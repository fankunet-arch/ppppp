<?php
declare(strict_types=1);

namespace Vip\Service;

use Vip\LocalDb;
use Vip\MealRules;
use Vip\Money;
use Vip\PointsEngine as PE;
use Vip\PosSource;
use Vip\PosUnavailable;
use Vip\Repo\AlertRepo;
use Vip\Repo\AuditRepo;
use Vip\Repo\ConfigRepo;
use Vip\Repo\LedgerRepo;
use Vip\Repo\MemberRepo;
use Vip\Repo\OrderRepo;

/**
 * 值比对冲正 —— 防刷分的正确做法。
 *
 * ★ 绝对不要按 return_time 增量扫描来触发扣分。
 *   实测三条证据（docs/01 §3.4）：
 *     1. return_amount 全表 88,616 行恒为 0，依赖它永不触发；
 *     2. 明细样本的退菜行 return_time 100% 早于 order_end_time；
 *     3. 触发器「明细被 UPDATE → head.status 置 1」在 927 天里从未触发
 *        （status 恒为 2），说明历史明细归档后从不被修改。
 *   结论：历史表中的 return_time 全是「结账前已发生」的退菜，其金额
 *   已经从 should_amount 中扣除。按 return_time 扫到就扣分，等于对早已
 *   扣过的退菜【再扣一次】。
 *
 * ★ 正确做法：把发分时的金额快照存本地，之后按 serial_id 回读主库当前
 *   金额做值比对。这不依赖任何数据库自增 ID，且覆盖面更广 —— 不仅覆盖
 *   退菜，也覆盖 POS 的「修改历史账单」功能（实测 edit_time 显示 2.9%
 *   的订单在结账后被修改过，其中 1,144 单晚于结账 30 分钟以上）。
 */
final class ReconcileService
{
    public function __construct(
        private LocalDb    $db,
        private PosSource  $pos,
        private ConfigRepo $cfg,
        private OrderRepo  $orders,
        private MemberRepo $members,
        private LedgerRepo $ledger,
        private AlertRepo  $alerts,
        private AuditRepo  $audit,
        private MealRules  $rules,
    ) {
    }

    /**
     * 回读保护期内已发分订单的金额，不一致则冲正。
     *
     * 开销：主键单点查（idx_headcheck），比扫 138 万行的 idx_return_time
     * 轻得多。保护期 30 天 × 日均 95.6 单 ≈ 2,870 单，分 29 批约 1 分钟跑完。
     */
    public function verifyAmounts(?callable $log = null): array
    {
        $log ??= static fn(string $m) => null;

        $protectDays = $this->cfg->int('verify_protect_days', 30);
        $batchSize   = min($this->cfg->int('sync_batch_size', 100), 100);
        $sleepMs     = $this->cfg->int('sync_batch_sleep_ms', 2000);
        $maxBatches  = $this->cfg->int('sync_max_batches', 200);

        $checked = 0; $changed = 0; $batches = 0;

        while ($batches < $maxBatches) {
            $page = $this->orders->pendingVerify($protectDays, $batchSize);
            if (!$page) {
                break;
            }
            $batches++;

            foreach ($page as $o) {
                try {
                    $r = $this->verifyOne($o);
                } catch (PosUnavailable $e) {
                    $log('POS 超时，值比对中止：' . $e->getMessage());
                    return ['ok' => false, 'reason' => 'pos_timeout',
                            'checked' => $checked, 'changed' => $changed];
                }
                $checked++;
                if ($r) {
                    $changed++;
                }
            }
            if ($sleepMs > 0) {
                usleep($sleepMs * 1000);
            }
        }

        return ['ok' => true, 'checked' => $checked, 'changed' => $changed, 'batches' => $batches];
    }

    /** @return bool 是否发生了冲正 */
    private function verifyOne(array $o): bool
    {
        $serial   = (string)$o['serial_id'];
        $headId   = (int)$o['order_head_id'];
        $checkIds = array_filter(array_map('intval', explode(',', (string)$o['check_ids'])));

        // 逐张 check 回读并汇总
        $nowOriginal = 0; $nowShould = 0; $nowActual = 0; $nowTax = 0; $found = false;
        foreach ($checkIds as $cid) {
            $r = $this->pos->reloadAmounts($headId, $cid);
            if ($r === null) {
                continue;
            }
            $found = true;
            $nowOriginal += Money::toCents((string)$r['original_amount']);
            $nowShould   += Money::toCents((string)$r['should_amount']);
            $nowActual   += Money::toCents((string)$r['actual_amount']);
            // ★ 税额也要回读。拿本地镜像里那个【旧】税额去算新总额是错的：
            //   金额都改了，税额不可能没改（实测 100.00/税 9.09 退成 75.00/税 6.82，
            //   用旧税算出来是 65.91，正确是 68.18 —— 反过来多退了 3 分）
            $nowTax      += Money::toCents((string)($r['tax_amount'] ?? '0'));
        }

        if (!$found) {
            // 订单在主库中消失了 —— 极罕见，需人工核实，不自动扣分
            $this->alerts->raiseOnce('order_vanished', 'order', $serial,
                sprintf('订单 %s 已发分，但主库中查不到了，需人工核实', $serial),
                ['severity' => 3]);
            $this->orders->markVerified($serial, 3);
            return false;
        }

        $oldShould = Money::toCents((string)$o['should_amount']);
        $oldActual = Money::toCents((string)$o['actual_amount']);

        if ($nowShould === $oldShould && $nowActual === $oldActual) {
            $this->orders->markVerified($serial, 1);
            return false;
        }

        /**
         * 金额变了 —— 重算可积分总额。此时才读明细（罕见路径，开销可接受）。
         *
         * ── 🔴 必须和记账路径用【同一套】算法 ─────────────
         *
         * 原来这里少传了两个东西，与 PointsService::buildContext() 分叉：
         *
         * ① redeemPatterns 没传 → 退回硬编码的 ['TARJETA 10+1','10+1']。
         *    而 PointsEngine::REDEEM_PATTERNS 的注释承诺「名称会变……
         *    改后台 sys_config 即可，无需改代码」——
         *    店家真改了 POS 里的名称：Pad 认得出，夜间校准认不出。
         *
         * ② taxCents 没传 → 恒按含税算。points_include_tax = 0 时
         *    落库的 total_amount 是【不含税】的，这里重算出来是【含税】的，
         *    两数天然不等且 newTotal 恒 ≥ oldTotal → 每一单都走
         *    「金额变大，不自动补分」分支，推一条 amount_changed 告警。
         *    本该自动冲正的单被挂成人工待办，同时污染告警队列。
         *
         * 两项当前恰好都没发作（含税开关是 1、核销名称等于硬编码默认值），
         * 但那是配置碰巧对上了，不是代码对。
         */
        $detail   = $this->pos->fetchDetailForChecks($headId, $checkIds);
        $analysis = PE::analyzeDetail($detail, $this->rules,
            PE::redeemPatternsFrom($this->cfg->get('redeem_line_patterns', '')));
        /**
         * ★ 用【回读到的】税额，不是本地镜像里那一份。
         *
         *   这里连着栽过两次：
         *   ① 一开始压根没传 taxCents —— points_include_tax=0 时
         *      newTotal 恒 ≥ oldTotal，每张改过金额的单都挂成人工待办。
         *   ② 补上之后读的是 $o['tax_amount']，而 pendingVerify() 的 SELECT
         *      根本没取那一列 —— PHP 静默求值成 null → 0，修了等于没修，
         *      冲正比应退的少一个税额（实测多留 6 分）。
         *   ③ 把列补进 SELECT 之后，读到的是【下单时】的旧税额 ——
         *      金额都改了税额不可能没改，于是又反过来多退了 3 分。
         *
         *   三次都是同一个毛病：算钱时拿了一个「看起来对」的近似值。
         *   现在只认 reloadAmounts() 当下回读的那一份。
         */
        $taxCents = $this->cfg->get('points_include_tax', '1') === '1' ? 0 : $nowTax;
        $newTotal = PE::pointsBaseCents($nowShould, $nowActual, $nowOriginal,
                                        $analysis['excluded_cents'], $taxCents);
        $oldTotal = Money::toCents((string)$o['total_amount']);

        if ($newTotal >= $oldTotal) {
            // 金额变大：不自动补分，避免被利用；记告警等人工判断
            $this->alerts->raiseOnce('amount_changed', 'order', $serial,
                sprintf('订单 %s 金额由 € %s 变为 € %s（变大），不自动补分，请人工复核',
                    $serial, Money::toStr($oldTotal), Money::toStr($newTotal)),
                ['severity' => 2, 'detail' => ['old' => Money::toStr($oldTotal), 'new' => Money::toStr($newTotal)]]);
            $this->orders->markVerified($serial, 3);
            return true;
        }

        $this->applyShrink($serial, $oldTotal, $newTotal);
        return true;
    }

    /**
     * 金额变小 → 冲正。
     *
     * 两个容易算错的地方：
     *
     * ① 冲正的基数是【已分配额超出新总额的部分】，不是缩水额本身。
     *    订单可能只被部分分配（AA 时只有部分客人有卡）。
     *    例：总额 100 只记了 50，缩水到 60 —— 新总额 60 仍 ≥ 已分配 50，
     *    一分都不该退。若按缩水额 40 去退就退多了。
     *
     * ② 按比例分摊会有舍入残差。三条等额流水各退 1/3 时
     *    scale() 三次的和可能比应退多 1 分。最后一条用「应退总额 − 已退」
     *    兜底，保证分毫不差。
     *
     * 冲正积分向上取整（对商家有利）。若会员积分不足，允许负余额并标记，
     * 不阻断、也不静默丢弃，下次消费优先抵扣。docs/03 §6.4
     */
    private function applyShrink(string $serial, int $oldTotal, int $newTotal): void
    {
        $this->db->transaction(function () use ($serial, $oldTotal, $newTotal): void {
            $order = $this->orders->lockBySerial($serial);
            if ($order === null) {
                return;
            }
            $allocated = Money::toCents((string)$order['allocated_amount']);

            // ① 只退「已分配额超出新总额」的部分
            $excess = $allocated - $newTotal;
            if ($excess <= 0) {
                $this->db->exec(
                    'UPDATE pos_order SET total_amount = ?, updated_at = ? WHERE id = ?',
                    [Money::toStr($newTotal), $this->db->now(), $order['id']]
                );
                $this->orders->markVerified($serial, 1);
                $this->alerts->raiseOnce('amount_changed', 'order', $serial,
                    sprintf('订单 %s 金额由 € %s 变为 € %s，但已分配 € %s 未超出新总额，无需冲正',
                        $serial, Money::toStr($oldTotal), Money::toStr($newTotal), Money::toStr($allocated)),
                    ['severity' => 1]);
                return;
            }

            $earns = [];
            foreach ($this->ledger->activeBySerial($serial) as $e) {
                if ((int)$e['entry_type'] === LedgerRepo::T_EARN && Money::toCents((string)$e['amount']) > 0) {
                    $earns[] = $e;
                }
            }
            if (!$earns) {
                $this->orders->markVerified($serial, 3);
                return;
            }

            $n = count($earns);
            $acc = 0;
            $totalBack = 0;

            foreach ($earns as $i => $e) {
                $amt = Money::toCents((string)$e['amount']);
                // ② 最后一条吃掉舍入残差，保证 SUM(退款) 恰好等于 excess
                $backAmt = ($i === $n - 1)
                    ? $excess - $acc
                    : Money::scale($excess, $amt, max($allocated, 1));
                $backAmt = max(0, min($backAmt, $amt));   // 不能退超过该条本身
                $acc += $backAmt;
                if ($backAmt === 0) {
                    continue;
                }

                $pts = (int)$e['points'];
                /**
                 * ★ 退多少分，看积分口径。
                 *
                 *   by_amount：分是从金额来的，按退掉的金额比例退，向上取整（对商家有利）。
                 *
                 *   by_visit ：分是从【来过一次】来的，跟金额没有比例关系。
                 *     照搬比例公式的话，`ceil(1 × 任意正比例)` 恒等于 1 ——
                 *     哪怕只退了 5 分钱，那一次的分也整个没了，而客人确实来过。
                 *     所以只在【整条被退干净】时才收回；部分缩水不动分。
                 *
                 *   两种口径下「计次」都不动（金额缩水不改变吃了几份套餐），
                 *   见下面 counted_visit => 0。
                 */
                $backPts = $this->cfg->get('points_mode', 'by_amount') === 'by_visit'
                    ? ($backAmt >= $amt ? $pts : 0)
                    : (int)ceil($pts * ($backAmt / max($amt, 1)));   // 向上取整

                $this->members->lockById((int)$e['member_id']);
                $this->ledger->insert([
                    'member_id'     => (int)$e['member_id'],
                    'serial_id'     => $serial,
                    'entry_type'    => LedgerRepo::T_REFUND,
                    'amount_cents'  => -$backAmt,
                    'points'        => -$backPts,
                    'counted_visit' => 0,      // 金额缩水不改变「吃了几份套餐」
                    'reverses_id'   => (int)$e['id'],
                    'reason'        => sprintf('值比对发现订单金额由 %s 变为 %s',
                        Money::toStr($oldTotal), Money::toStr($newTotal)),
                ]);
                $this->members->applyDelta((int)$e['member_id'], -$backPts, 0, -$backAmt);
                $totalBack += $backAmt;
            }

            $this->orders->applyAllocation($serial, -$totalBack, 0);
            $this->db->exec(
                'UPDATE pos_order SET total_amount = ?, updated_at = ? WHERE id = ?',
                [Money::toStr($newTotal), $this->db->now(), $order['id']]
            );
            $this->orders->markVerified($serial, 2);

            $this->audit->log('point_reverse', [
                'target_type' => 'order', 'target_id' => $serial,
                'detail' => ['auto' => true, 'kind' => 'amount_shrink',
                             'old' => Money::toStr($oldTotal), 'new' => Money::toStr($newTotal),
                             'reversed' => Money::toStr($totalBack)],
            ]);

            $this->alerts->raise('amount_changed',
                sprintf('订单 %s 金额由 € %s 缩至 € %s，已自动冲正 € %s',
                    $serial, Money::toStr($oldTotal), Money::toStr($newTotal), Money::toStr($totalBack)),
                ['severity' => 2, 'ref_type' => 'order', 'ref_id' => $serial]);
        });
    }
}
