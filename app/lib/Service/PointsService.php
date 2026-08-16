<?php
declare(strict_types=1);

namespace Vip\Service;

use Vip\BusinessDay;
use Vip\LocalDb;
use Vip\MealRules;
use Vip\Money;
use Vip\PointsEngine as PE;
use Vip\PosReader;
use Vip\PosUnavailable;
use Vip\Repo\AlertRepo;
use Vip\Repo\AuditRepo;
use Vip\Repo\ConfigRepo;
use Vip\Repo\LedgerRepo;
use Vip\Repo\MemberRepo;
use Vip\Repo\OrderRepo;

/**
 * 积分业务编排。
 *
 * 分工：
 *   locate()  读 POS（2 次查询：订单头范围查 + 明细主键单点查），
 *             算出可积分总额与计次份数，落本地镜像，返回候选给 Pad。
 *   grant()   【不碰 POS】，只在本地库事务内完成分配，
 *             因此主库抖动不会阻塞收银流程。
 *   reverse() 追加反向冲正流水，回退订单与会员。
 */
final class PointsService
{
    public function __construct(
        private LocalDb    $db,
        private PosReader  $pos,
        private ConfigRepo $cfg,
        private OrderRepo  $orders,
        private MemberRepo $members,
        private LedgerRepo $ledger,
        private AlertRepo  $alerts,
        private AuditRepo  $audit,
        private MealRules  $rules,
        private BusinessDay $bizDay,
    ) {
    }

    // ════════════════════════════════════════════════════════
    // 订单定位
    // ════════════════════════════════════════════════════════

    /**
     * 按桌号定位近 N 分钟内的已结账订单。
     *
     * 实测：按 check 行直接返回歧义率 10.95%，按 order_head_id 聚合后
     * 降至 0.02%（53 万次模拟仅 127 次返回 2 条）。docs/03 §1.2
     *
     * @return array{ok:bool,reason?:string,window:int,candidates:array}
     */
    public function locate(string $tableName, ?int $windowMinutes = null): array
    {
        $win = $windowMinutes ?? $this->cfg->int('order_lookup_window_min', 30);

        try {
            $rows = $this->pos->findRecentByTable($tableName, $win);
        } catch (PosUnavailable $e) {
            // 主库不可达 → 不阻塞收银，交给上层走降级（手工录入）
            return ['ok' => false, 'reason' => 'pos_unavailable', 'window' => $win, 'candidates' => []];
        }

        $agg = PE::aggregateCandidates($rows);
        if (!$agg) {
            return ['ok' => true, 'reason' => 'not_found', 'window' => $win, 'candidates' => []];
        }

        $out = [];
        foreach ($agg as $o) {
            $out[] = $this->buildContext($o);
        }
        // 结账时间倒序，最近的排前面
        usort($out, static fn($a, $b) => strcmp($b['order_end_time'], $a['order_end_time']));

        return ['ok' => true, 'window' => $win, 'candidates' => $out];
    }

    /**
     * 读明细并算出该订单的完整上下文，同时落本地镜像。
     *
     * ★ 每次积分都必须读明细 —— 计次份数与不计分项扣除都靠它，
     *   不再只有「点选菜品」模式才读。docs/03 §1.1
     */
    private function buildContext(array $o): array
    {
        $detail   = $this->pos->fetchDetailForChecks($o['order_head_id'], $o['check_ids']);
        $analysis = PE::analyzeDetail($detail, $this->rules);

        $baseCents = PE::pointsBaseCents(
            $o['should_cents'],
            $o['actual_cents'],
            $o['original_cents'],
            $analysis['excluded_cents']
        );

        $existing  = $this->orders->findBySerial($o['serial_id']);
        $isFree    = (bool)(int)($existing['is_free_meal'] ?? 0);
        $eligible  = PE::checkEligible($o['eat_type'], $o['should_cents'], $o['actual_cents'], $isFree);

        $bizDate = $this->bizDay->of($o['order_end_time']);

        // 落本地镜像（幂等：ON DUPLICATE KEY UPDATE）
        $this->orders->upsert([
            'serial_id'          => $o['serial_id'],
            'order_head_id'      => $o['order_head_id'],
            'check_ids'          => $o['check_ids'],
            'table_name'         => $o['table_name'],
            'eat_type'           => $o['eat_type'],
            'customer_num'       => $o['customer_num'],
            'order_end_time'     => $o['order_end_time'],
            'business_date'      => $bizDate,
            'original_cents'     => $o['original_cents'],
            'should_cents'       => $o['should_cents'],
            'actual_cents'       => $o['actual_cents'],
            'total_cents'        => $baseCents,
            'excluded_cents'     => $analysis['excluded_cents'],
            'portions_counted'   => $analysis['portions_counted'],
            'portions_uncounted' => $analysis['portions_uncounted'],
        ]);

        // 免费餐兜底：点了套餐但餐费为 0 且订单仍有金额 → 疑似漏点核销
        if (PE::suspectFreeMeal($analysis, $baseCents, $isFree)) {
            $this->alerts->raiseOnce(
                'free_meal_suspect', 'order', $o['serial_id'],
                sprintf('订单 %s 含套餐但餐费项合计为 0，金额 %s，Pad 端未标记核销',
                    $o['serial_id'], Money::toStr($baseCents)),
                ['severity' => 2, 'detail' => ['meal_fee_cents' => $analysis['meal_fee_cents']]]
            );
        }

        $allocated  = Money::toCents($existing['allocated_amount'] ?? '0');
        $allocPort  = (int)($existing['allocated_portions'] ?? 0);

        return [
            'serial_id'          => $o['serial_id'],
            'order_head_id'      => $o['order_head_id'],
            'check_ids'          => $o['check_ids'],
            'table_name'         => $o['table_name'],
            'customer_num'       => $o['customer_num'],
            'order_end_time'     => $o['order_end_time'],
            'business_date'      => $bizDate,
            'eligible'           => $eligible['ok'],
            'ineligible_reason'  => $eligible['reason'],
            'is_free_meal'       => $isFree,
            'total_cents'        => $baseCents,
            'total'              => Money::toStr($baseCents),
            'allocated_cents'    => $allocated,
            'allocated'          => Money::toStr($allocated),
            'remaining_cents'    => max(0, $baseCents - $allocated),
            'remaining'          => Money::toStr(max(0, $baseCents - $allocated)),
            'portions_counted'   => $analysis['portions_counted'],
            'allocated_portions' => $allocPort,
            'remaining_portions' => max(0, $analysis['portions_counted'] - $allocPort),
            'excluded'           => Money::toStr($analysis['excluded_cents']),
            'items'              => $analysis['display'],
            'existing_ledger'    => $this->ledger->activeBySerial($o['serial_id']),
        ];
    }

    // ════════════════════════════════════════════════════════
    // 积分发放
    // ════════════════════════════════════════════════════════

    /**
     * 把订单金额与份数分配给一到多个会员（AA 场景）。
     *
     * ★ 金额守恒在事务内校验：SUM(已分配 + 本次) ≤ total_amount。
     * ★ 不信任客户端传来的金额上限 —— 一律以本地镜像的 total_amount 为准。
     *
     * @param array $allocations [['member_id'=>int,'amount_cents'=>int,'portions'=>int], ...]
     * @return array{ok:bool,error?:string,entries?:array}
     */
    public function grant(string $serialId, array $allocations, int $allocMode, array $operator): array
    {
        if (!$allocations) {
            return ['ok' => false, 'error' => 'empty_allocation'];
        }

        return $this->db->transaction(function () use ($serialId, $allocations, $allocMode, $operator) {
            $order = $this->orders->lockBySerial($serialId);
            if ($order === null) {
                return ['ok' => false, 'error' => 'order_not_found'];
            }
            if ((int)$order['eat_type'] !== 0) {
                return ['ok' => false, 'error' => 'not_dine_in'];
            }
            if ((int)$order['is_free_meal'] === 1) {
                return ['ok' => false, 'error' => 'free_meal'];
            }

            $total     = Money::toCents($order['total_amount']);
            $allocated = Money::toCents($order['allocated_amount']);
            $totalPort = (int)$order['portions_counted'];
            $allocPort = (int)$order['allocated_portions'];

            if ($total <= 0) {
                return ['ok' => false, 'error' => 'zero_amount'];
            }

            // ★ 金额守恒校验（纯函数，见 PointsEngine::validateAllocations 与其测试）
            $v = PE::validateAllocations($allocations, $total, $allocated, $totalPort, $allocPort);
            if (!$v['ok']) {
                return [
                    'ok'     => false,
                    'error'  => $v['error'],
                    'detail' => [
                        'total'     => Money::toStr($total),
                        'allocated' => Money::toStr($allocated),
                        'requested' => Money::toStr($v['sum_amount']),
                    ],
                ];
            }
            $sumAmount   = $v['sum_amount'];
            $sumPortions = $v['sum_portions'];

            $perEuro    = $this->cfg->float('points_per_euro', 1.0);
            $multiplier = $this->cfg->float('points_multiplier', 1.0);
            $byPortion  = $this->cfg->get('visit_count_mode', 'by_portion') === 'by_portion';

            $entries = [];
            foreach ($allocations as $a) {
                $memberId = (int)$a['member_id'];
                $amt      = (int)($a['amount_cents'] ?? 0);
                $prt      = (int)($a['portions'] ?? 0);

                $member = $this->members->lockById($memberId);
                if ($member === null) {
                    return ['ok' => false, 'error' => 'member_not_found', 'detail' => ['member_id' => $memberId]];
                }

                $points = PE::pointsFor($amt, $perEuro, $multiplier);
                // by_portion：按 counts_visit=1 菜品的份数计次
                // by_ledger ：每笔流水最多 1 次
                $visits = $byPortion ? $prt : ($prt > 0 ? 1 : 0);

                $lid = $this->ledger->insert([
                    'member_id'          => $memberId,
                    'serial_id'          => $serialId,
                    'entry_type'         => LedgerRepo::T_EARN,
                    'amount_cents'       => $amt,
                    'points'             => $points,
                    'counted_visit'      => $visits,
                    'portions_counted'   => $prt,
                    'portions_uncounted' => (int)$order['portions_uncounted'],
                    'excluded_cents'     => Money::toCents($order['excluded_amount']),
                    'alloc_mode'         => $allocMode,
                    'alloc_detail'       => $a['detail'] ?? null,
                    'source'             => LedgerRepo::SRC_POS,
                    'operator_id'        => $operator['id']   ?? null,
                    'operator_name'      => $operator['name'] ?? null,
                    'device'             => $operator['device'] ?? null,
                ]);

                $this->members->applyDelta($memberId, $points, $visits, $amt);

                $entries[] = [
                    'ledger_id' => $lid,
                    'member_id' => $memberId,
                    'card_no'   => $member['card_no'],
                    'amount'    => Money::toStr($amt),
                    'points'    => $points,
                    'visits'    => $visits,
                ];
            }

            $this->orders->applyAllocation($serialId, $sumAmount, $sumPortions);

            $this->audit->log('point_grant', [
                'target_type'   => 'order',
                'target_id'     => $serialId,
                'operator_id'   => $operator['id']   ?? null,
                'operator_name' => $operator['name'] ?? null,
                'device'        => $operator['device'] ?? null,
                'detail'        => ['mode' => $allocMode, 'entries' => $entries],
            ]);

            return ['ok' => true, 'entries' => $entries];
        });
    }

    // ════════════════════════════════════════════════════════
    // 撤销
    // ════════════════════════════════════════════════════════

    /**
     * 撤销一条积分流水 —— 写反向冲正记录，绝不物理删除。
     *
     * 典型场景：服务员先把整单记给一人，客人要求改为分别记给每个人，
     * 其中一位还没有卡（撤销后改选 AA + 内联新建会员）。docs/03 §4.2
     */
    public function reverse(int $ledgerId, string $reason, array $operator): array
    {
        return $this->db->transaction(function () use ($ledgerId, $reason, $operator) {
            $orig = $this->ledger->lockById($ledgerId);
            if ($orig === null) {
                return ['ok' => false, 'error' => 'ledger_not_found'];
            }
            if ((int)$orig['status'] !== LedgerRepo::S_ACTIVE) {
                return ['ok' => false, 'error' => 'already_reversed'];
            }
            if ((int)$orig['entry_type'] !== LedgerRepo::T_EARN) {
                return ['ok' => false, 'error' => 'not_reversible'];
            }

            // 撤销时间窗：超出需经理权限
            $windowH = $this->cfg->int('reversal_window_hours', 24);
            $ageH    = (time() - strtotime((string)$orig['created_at'])) / 3600;
            if ($ageH > $windowH && empty($operator['is_manager'])) {
                return ['ok' => false, 'error' => 'reversal_window_expired',
                        'detail' => ['window_hours' => $windowH]];
            }

            $amt    = Money::toCents($orig['amount']);
            $points = (int)$orig['points'];
            $visits = (int)$orig['counted_visit'];

            $this->members->lockById((int)$orig['member_id']);

            $revId = $this->ledger->insert([
                'member_id'        => (int)$orig['member_id'],
                'serial_id'        => $orig['serial_id'],
                'entry_type'       => LedgerRepo::T_REVERSE,
                'amount_cents'     => -$amt,
                'points'           => -$points,
                'counted_visit'    => -$visits,
                'portions_counted' => -(int)$orig['portions_counted'],
                'reverses_id'      => $ledgerId,
                'source'           => (int)$orig['source'],
                'operator_id'      => $operator['id']   ?? null,
                'operator_name'    => $operator['name'] ?? null,
                'device'           => $operator['device'] ?? null,
                'reason'           => $reason,
            ]);

            $this->ledger->markReversed($ledgerId, $revId);

            // 允许负余额并标记，不阻断撤销；下次消费优先抵扣
            $this->members->applyDelta((int)$orig['member_id'], -$points, -$visits, -$amt);

            if ($orig['serial_id'] !== null) {
                $this->orders->applyAllocation((string)$orig['serial_id'], -$amt, -(int)$orig['portions_counted']);
            }

            $this->audit->log('point_reverse', [
                'target_type'   => 'ledger',
                'target_id'     => (string)$ledgerId,
                'operator_id'   => $operator['id']   ?? null,
                'operator_name' => $operator['name'] ?? null,
                'device'        => $operator['device'] ?? null,
                'detail'        => ['reversal_id' => $revId, 'reason' => $reason,
                                    'amount' => $orig['amount'], 'points' => $points],
            ]);

            return ['ok' => true, 'reversal_id' => $revId];
        });
    }

    // ════════════════════════════════════════════════════════
    // 降级：手工录入
    // ════════════════════════════════════════════════════════

    /**
     * 主库数据缺失或不可达时的手工录入。
     *
     * 实测 history_order_head 曾整段丢失约 6 天、478 个订单号、
     * 29,233.53 欧的记录，且校准任务无法自愈（数据本就不在主库里）。
     * docs/01 §5.3、docs/03 §10
     *
     * 特征：source=2、serial_id 为 NULL、不写 pos_order、自动进待复核队列。
     */
    public function manualGrant(int $memberId, int $amountCents, string $reasonCode, array $operator): array
    {
        if (!$this->cfg->bool('manual_entry_enabled', true)) {
            return ['ok' => false, 'error' => 'manual_entry_disabled'];
        }
        if ($amountCents <= 0) {
            return ['ok' => false, 'error' => 'invalid_amount'];
        }
        $limit = Money::toCents($this->cfg->get('manual_entry_limit', '200.00'));
        if ($amountCents > $limit && empty($operator['approved_by'])) {
            return ['ok' => false, 'error' => 'exceeds_manual_limit',
                    'detail' => ['limit' => Money::toStr($limit)]];
        }

        return $this->db->transaction(function () use ($memberId, $amountCents, $reasonCode, $operator) {
            $member = $this->members->lockById($memberId);
            if ($member === null) {
                return ['ok' => false, 'error' => 'member_not_found'];
            }

            $points = PE::pointsFor(
                $amountCents,
                $this->cfg->float('points_per_euro', 1.0),
                $this->cfg->float('points_multiplier', 1.0)
            );

            $lid = $this->ledger->insert([
                'member_id'     => $memberId,
                'serial_id'     => null,
                'entry_type'    => LedgerRepo::T_EARN,
                'amount_cents'  => $amountCents,
                'points'        => $points,
                'counted_visit' => 0,      // 手工录入无明细，无法判断套餐份数 → 不计次
                'source'        => LedgerRepo::SRC_MANUAL,
                'manual_reason' => $reasonCode,
                'review_status' => 1,      // 全部进待复核队列
                'approved_by'   => $operator['approved_by'] ?? null,
                'operator_id'   => $operator['id']   ?? null,
                'operator_name' => $operator['name'] ?? null,
                'device'        => $operator['device'] ?? null,
            ]);

            $this->members->applyDelta($memberId, $points, 0, $amountCents);

            // 频次风控
            $opId = (int)($operator['id'] ?? 0);
            $cnt  = $this->ledger->manualCountToday($opId);
            $thr  = $this->cfg->int('manual_entry_daily_alert', 5);
            if ($opId > 0 && $cnt > $thr) {
                $this->alerts->raise(
                    'manual_entry',
                    sprintf('员工 %s 今日手工录入已达 %d 笔（阈值 %d）',
                        $operator['name'] ?? $opId, $cnt, $thr),
                    ['severity' => 2, 'ref_type' => 'operator', 'ref_id' => (string)$opId]
                );
            }

            $this->audit->log('point_grant', [
                'target_type'   => 'member',
                'target_id'     => (string)$memberId,
                'operator_id'   => $operator['id']   ?? null,
                'operator_name' => $operator['name'] ?? null,
                'device'        => $operator['device'] ?? null,
                'detail'        => ['manual' => true, 'reason' => $reasonCode,
                                    'amount' => Money::toStr($amountCents), 'points' => $points],
            ]);

            return ['ok' => true, 'ledger_id' => $lid, 'points' => $points, 'review_required' => true];
        });
    }
}
