<?php
declare(strict_types=1);

namespace Vip\Service;

use Vip\BusinessDay;
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
        private PosSource  $pos,
        private ConfigRepo $cfg,
        private OrderRepo  $orders,
        private MemberRepo $members,
        private LedgerRepo $ledger,
        private AlertRepo  $alerts,
        private AuditRepo  $audit,
        private MealRules  $rules,
        private BusinessDay $bizDay,
        private \Vip\Repo\CardTierRepo $tiers,
        private \Vip\MealPeriod $periods,
        private \Vip\CardNumber $cardNo,
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
     * 按小票上的「Factura Simplificada」号定位订单。
     *
     * 小票实测（docs/01 §2.9）：Factura Simplificada = order_head_id，全局唯一。
     * 因此这条路不需要时间窗、不受翻台影响、分单的多张 check 一次取全。
     *
     * ★ 为什么不用「Número Ticket」：那是 check_number，实测当日会重号
     *   （2026-08-03 票号 323 在 13:05 与 21:36 各发过一次），做不了唯一键。
     *
     * 唯一的时间约束是【最大回溯天数】：小票可以隔天补记，但不该让人拿着
     * 半年前的小票来领分。超期返回 too_old，由店家在后台调 invoice_lookup_max_days。
     *
     * @return array{ok:bool,reason?:string,candidates:array}
     */
    public function locateByInvoice(int $orderHeadId): array
    {
        if ($orderHeadId <= 0) {
            return ['ok' => false, 'reason' => 'bad_invoice', 'candidates' => []];
        }

        try {
            $rows = $this->pos->findByInvoice($orderHeadId);
        } catch (PosUnavailable $e) {
            return ['ok' => false, 'reason' => 'pos_unavailable', 'candidates' => []];
        }
        if (!$rows) {
            return ['ok' => true, 'reason' => 'not_found', 'candidates' => []];
        }

        $agg = PE::aggregateCandidates($rows);
        $out = [];
        foreach ($agg as $o) {
            $out[] = $this->buildContext($o);
        }

        // 回溯天数上限：0 表示不限
        $maxDays = $this->cfg->int('invoice_lookup_max_days', 7);
        if ($maxDays > 0 && $out) {
            $cut = date('Y-m-d H:i:s', strtotime($this->pos->now()) - $maxDays * 86400);
            $fresh = array_values(array_filter($out, static fn($c) => $c['order_end_time'] >= $cut));
            if (!$fresh) {
                /**
                 * ★ 【不回结账日期】。
                 *
                 *   这里曾经把 order_end_time 一并带出去，界面上就成了
                 *   「这张小票是 2026-08-16 的，超过 7 天」——
                 *   而这句话本身就等于确认「这个号是真的，而且那天有生意」。
                 *   小票号是连号整数，一个个试下去，号段和营业日都能画出来。
                 *
                 *   经理需要的只是【在期内还是在期外】这一个二值判断
                 *   （是真单太旧了，还是收银员输错了号），不需要具体是哪天。
                 *   所以连经理都不给日期 —— 经理账号一旦外泄，
                 *   泄露的东西不该比收银员账号多。
                 *
                 *   真要查某张单是哪天的，走后台带审计的查询，
                 *   而不是这个柜台上随手就能打的接口。
                 */
                return ['ok' => true, 'reason' => 'too_old', 'max_days' => $maxDays,
                        'candidates' => []];
            }
            $out = $fresh;
        }

        return ['ok' => true, 'candidates' => $out];
    }

    /**
     * 十送一核销行的名称模式。
     *
     * 店家在 POS 里改了名称时，改后台 sys_config.redeem_line_patterns 即可
     * （逗号分隔），不必改代码。留空则回落到内置默认。
     *
     * @return array<int,string>
     */
    private function redeemPatterns(): array
    {
        // ★ 解析逻辑放在 PointsEngine 里 —— 值比对路径要用同一份，
        //   见 PointsEngine::redeemPatternsFrom() 的说明
        return PE::redeemPatternsFrom($this->cfg->get('redeem_line_patterns', ''));
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
        $analysis = PE::analyzeDetail($detail, $this->rules, $this->redeemPatterns());

        // 「按含税价积分」开关 points_include_tax
        //   1（默认）按小票 TOTAL 那个数积分
        //   0        先扣掉 POS 给的真实税额（实测与小票 SubTotal 完全吻合）
        $taxCents = $this->cfg->get('points_include_tax', '1') === '1'
            ? 0
            : (int)($o['tax_cents'] ?? 0);

        // 先算一次常规基数；免费餐+额外消费计分时，餐费项也要排除
        $excluded  = $analysis['excluded_cents'];
        $baseCents = PE::pointsBaseCents(
            $o['should_cents'], $o['actual_cents'], $o['original_cents'], $excluded, $taxCents
        );

        $existing  = $this->orders->findBySerial($o['serial_id']);
        $isFree    = (bool)(int)($existing['is_free_meal'] ?? 0);

        // ★ 十送一核销：POS 侧的动作是在明细里加一条 `-2 / TARJETA 10+1 / 负金额`
        //   折扣行（实测订单 92293）。这一餐是客人在【兑换奖励】，
        //   按业务规则不计次也不计分，否则等于「拿奖励的同时又攒一次」。
        //   与服务员手工标记的 is_free_meal 分开存，审计时能区分判定来源。
        $isRedeemed = (bool)$analysis['is_redeemed'];

        // 「免费餐的额外消费是否计入」—— 后台开关 free_meal_extra_earns
        //   0（默认）核销/免费餐那一单整单不计分不计次
        //   1        套餐部分不计分，但酒水甜点等【额外消费】照常计分
        //            （计次仍然不给 —— 那一餐是兑换来的，不该再攒一次）
        $extraEarns = $this->cfg->get('free_meal_extra_earns', '0') === '1';

        /**
         * ★ 混合单：一桌里只有部分人用券免单，其余人正常付费。
         *
         * 店家口径（已确认）：4 人同桌、1 人用十送一券、其余 3 人照付
         * （哪怕他们用了满50减5 的纸质券），这一单就算【3 份】——
         * 可以 AA 给 3 个人，也可以整单记给一人算 3 次。
         *
         * 早先的写法是「只要有核销行就整单不计次不积分」，
         * 实测 5 张核销单里有 2 张是混合单（92089 2 份抵 1、92147 4 份抵 1），
         * 那 3 位付了钱的客人一次都没计上 —— 静默少给，客人也发现不了。
         *
         * 抵掉几份由 analyzeDetail 反推（核销额 ÷ 套餐单价，实测恒为整数）。
         * 反推不出来时（单价不唯一 / 不能整除）退回保守口径：整单不计次。
         * 宁可少给也不能多给 —— 多给等于白送一顿饭。
         */
        $portionsRedeemed = $analysis['portions_redeemed'];   // int|null
        $fullyRedeemed    = $isRedeemed
            && ($portionsRedeemed === null || $portionsRedeemed >= $analysis['portions_counted']);
        $isFreeish        = $isFree || $fullyRedeemed;

        // 本单实际可计次的份数：整单免费 → 0；部分核销 → 总份数 − 抵掉份数
        $netPortions = $isFree ? 0
            : ($isRedeemed
                ? ($portionsRedeemed === null
                    ? 0
                    : max(0, $analysis['portions_counted'] - $portionsRedeemed))
                : $analysis['portions_counted']);

        if ($isFreeish && $extraEarns) {
            // 只把餐费项从基数里剔掉，剩下的额外消费仍可积分
            $eligible = PE::checkEligible($o['eat_type'], $o['should_cents'], $o['actual_cents'], false);
        } else {
            $eligible = PE::checkEligible($o['eat_type'], $o['should_cents'], $o['actual_cents'], $isFreeish);
            if ($isRedeemed && $eligible['reason'] === 'free_meal') {
                $eligible['reason'] = 'redeemed';   // 与人工标记的免费餐区分开
            }
        }

        if ($isFreeish && $extraEarns) {
            // 餐费项（套餐）不计分，其余照常
            $excluded  = min($o['original_cents'], $excluded + $analysis['meal_fee_cents']);
            $baseCents = PE::pointsBaseCents(
                $o['should_cents'], $o['actual_cents'], $o['original_cents'], $excluded, $taxCents
            );
        }

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
            'tax_cents'          => $o['tax_cents'] ?? 0,
            'total_cents'        => $baseCents,
            'excluded_cents'     => $excluded,
            // ★ 存【净】份数：被券抵掉的那几份本来就不该计次，
            //   存净值后 validateAllocations 天然把分配上限卡对。
            'portions_counted'   => $netPortions,
            'portions_uncounted' => $analysis['portions_uncounted'],
            'is_redeemed'        => $isRedeemed,
            'redeem_cents'       => $analysis['redeem_cents'],
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
            'is_redeemed'        => $isRedeemed,
            'redeem_amount'      => Money::toStr($analysis['redeem_cents']),
            'redeem_lines'       => $analysis['redeem_lines'],
            'total_cents'        => $baseCents,
            'total'              => Money::toStr($baseCents),
            'allocated_cents'    => $allocated,
            'allocated'          => Money::toStr($allocated),
            'remaining_cents'    => max(0, $baseCents - $allocated),
            'remaining'          => Money::toStr(max(0, $baseCents - $allocated)),
            'portions_counted'   => $netPortions,
            /**
             * ★ 一位客人固定算几份 —— null = 不固定，可手填。
             *
             *   once_per_period 口径下，「份数」已经不是「吃了几份」，
             *   而是「这个人有没有吃计次套餐」这个是非题：
             *   给他 3 份和给他 1 份，最后都只记 1 次。
             *   既然多填没有任何用处，那个输入框就不该是可填的 ——
             *   它只剩下填错的可能（现场截图：只剩 1 份的单里填进了 4）。
             *
             *   by_portion / by_order 是旧口径，份数在那里是真的有意义，
             *   所以留 null，前端照常可填。
             */
            'portions_per_person' => $this->cfg->get('visit_count_mode', 'once_per_period')
                                     === 'once_per_period' ? 1 : null,
            'allocated_portions' => $allocPort,
            'remaining_portions' => max(0, $netPortions - $allocPort),
            // 排查用：明细里的原始份数，以及券抵掉了几份
            'portions_total'     => $analysis['portions_counted'],
            'portions_redeemed'  => $portionsRedeemed,
            // 份数拆档，Pad 直接显示，收银员不用自己数：
            //   付费套餐几份、免费套餐几份、买单人数（POS 的 customer_num）
            'portions_paid'      => $analysis['portions_paid'],
            'portions_free'      => $analysis['portions_free'],
            /**
             * ★ 「订单有金额，但一条明细都读不到」必须单独报出来。
             *
             * 实测这家 POS 的 history_order_detail 会明显落后于
             * history_order_head：订单头已经有 8-17 的单，明细却只到 8-13。
             * 于是刚结的账在 Pad 上表现为「查得到、套餐 0 份」——
             * 而这和「客人真的没点套餐」长得一模一样，收银员没法分辨，
             * 很容易当成 0 份就发分，把该计的次数漏掉。
             *
             * 这不是配置问题也不是漏配菜品，等明细归档过来就好了，
             * 所以要说清楚「等一会儿再查」而不是让人去后台翻规则。
             */
            'detail_missing'     => $detail === [] && $baseCents > 0,
            // 规则表没收录的菜品：份数会被安全默认吞成 0，必须让前台看得见，
            // 否则界面上「本来就 0 份」和「漏配所以算不出来」长得一模一样。
            'unknown_items'      => $analysis['unknown_items'],
            'excluded'           => Money::toStr($excluded),
            'items'              => $analysis['display'],
            /**
             * ★ 卡号要按【显示形态】发出去（TK-00000275-1A2），不能发库里那个normalized 串。
             *
             *   库里存的是去连字符的 TK000002751A2，而其他每一个接口
             *   都在出口处 format 过（见 api/routes.php 里那一排）。
             *   这里漏了 format 的后果是：同一张卡在「已经记给」那一栏
             *   长成 TK00000275•••，在会员弹层里长成 TK-00000275-•••，
             *   收银员对着两个不一样的串，得自己判断是不是同一张。
             */
            'existing_ledger'    => array_map(function (array $l): array {
                if (isset($l['card_no']) && $l['card_no'] !== null) {
                    $l['card_no'] = $this->cardNo->format((string)$l['card_no']);
                }
                return $l;
            }, $this->ledger->activeBySerial($o['serial_id'])),
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
    public function grant(string $serialId, array $allocations, int $allocMode, array $operator,
                          ?array $override = null): array
    {
        if (!$allocations) {
            return ['ok' => false, 'error' => 'empty_allocation'];
        }

        // ★★★ 主库时刻在【进事务之前】取，而且一次请求只取一次。
        //     理由见 posNow() 的说明 —— 一句话：事务里不许碰 POS。
        $nowAtPos = $this->posNow();

        return $this->db->transaction(function () use ($serialId, $allocations, $allocMode, $operator, $override, $nowAtPos) {
            $memberIds = array_map(static fn(array $a): int => (int)($a['member_id'] ?? 0), $allocations);
            $gate = $this->checkGates([$serialId], $memberIds, $operator, $override, $nowAtPos);
            if (!$gate['ok']) {
                return $gate;
            }
            $r = $this->grantOne($serialId, $allocations, $allocMode, $operator, null);
            if (!($r['ok'] ?? false)) {
                return $r;
            }
            $this->auditForced($gate, [$serialId], $operator, $override);
            $this->riskWatch($memberIds, $operator);
            return $r + ['forced' => $gate['forced'], 'gates' => $gate['hit']];
        });
    }

    /**
     * 多桌合并：几张订单的积分【整单】记进同一张卡。
     *
     * 场景是「同行分桌」—— 一大帮人坐了三桌，分桌计费、一起结账，
     * 然后自愿把三桌的分都记到其中一位的卡上。docs/03 §12.2
     *
     * ★ 只支持整单模式。合并之后再 AA 或点选菜品是没有意义的：
     *   会走到这条路上，本身就意味着「不用再分了，都算一个人的」。
     *   支持它要把整个步骤机改成多单版，代价大得多，而现场没有这个需求。
     *
     * ★ 几张单必须是同一顿饭：同一营业日、同一餐期、结账时间跨度受限。
     *   这三条就是把「同行分桌」和「捡了三张别人的小票」分开的全部依据 ——
     *   前者永远挨在一起，后者来源必然分散。
     *
     * ★ 加锁顺序按 serial_id 排序。两台 Pad 同时合并有重叠的两组单时，
     *   顺序不固定就会死锁 —— 这是合并相对单桌【新增】的唯一并发风险。
     *
     * @param string[] $serialIds
     * @return array{ok:bool,error?:string,entries?:array,group?:string}
     */
    public function grantMerged(array $serialIds, int $memberId, array $operator,
                                ?array $override = null): array
    {
        $serials = array_values(array_unique(array_filter(array_map(
            static fn($v): string => trim((string)$v), $serialIds))));
        if (count($serials) < 2) {
            return ['ok' => false, 'error' => 'merge_needs_two'];
        }
        $maxOrders = max(1, $this->cfg->int('merge_max_orders', 8));
        if (count($serials) > $maxOrders) {
            return ['ok' => false, 'error' => 'merge_too_many',
                    'detail' => ['max' => $maxOrders, 'given' => count($serials)]];
        }
        // ★ 固定加锁顺序，防止并发合并时死锁
        sort($serials);

        $nowAtPos = $this->posNow();      // 同上：进事务之前，一次

        return $this->db->transaction(function () use ($serials, $memberId, $operator, $override, $nowAtPos) {
            $span = $this->checkMergeSpan($serials);
            if (!$span['ok']) {
                return $span;
            }
            $gate = $this->checkGates($serials, [$memberId], $operator, $override, $nowAtPos);
            if (!$gate['ok']) {
                return $gate;
            }

            $group   = $this->newGroupId();
            $entries = [];
            foreach ($serials as $sid) {
                $order = $this->orders->lockBySerial($sid);
                if ($order === null) {
                    return ['ok' => false, 'error' => 'order_not_found', 'detail' => ['serial_id' => $sid]];
                }
                // 整单 = 把这一单【剩下的】全给他。已经分掉一部分的单也能合进来，
                // 剩多少给多少 —— 金额守恒照常在 grantOne 里逐单校验。
                $remain     = Money::toCents($order['total_amount']) - Money::toCents($order['allocated_amount']);
                $remainPort = (int)$order['portions_counted'] - (int)$order['allocated_portions'];
                if ($remain <= 0) {
                    return ['ok' => false, 'error' => 'order_fully_allocated', 'detail' => ['serial_id' => $sid]];
                }
                $r = $this->grantOne($sid, [[
                    'member_id'    => $memberId,
                    'amount_cents' => $remain,
                    'portions'     => max(0, $remainPort),
                ]], PE::MODE_WHOLE, $operator, $group);
                if (!($r['ok'] ?? false)) {
                    // 事务里直接返回 = 整组回滚。半成品比失败更难收拾：
                    // 收银员看到「成功了 2 桌、第 3 桌失败」根本不知道该怎么办
                    return $r + ['detail' => ($r['detail'] ?? []) + ['serial_id' => $sid]];
                }
                $entries = array_merge($entries, $r['entries']);
            }

            $this->audit->log('point_grant_merged', [
                'target_type'   => 'grant_group', 'target_id' => $group,
                'operator_id'   => $operator['id']   ?? null,
                'operator_name' => $operator['name'] ?? null,
                'device'        => $operator['device'] ?? null,
                'detail'        => ['serials' => $serials, 'member_id' => $memberId,
                                    'forced' => $gate['forced'], 'gates' => $gate['hit']],
            ]);
            $this->auditForced($gate, $serials, $operator, $override);
            $this->riskWatch([$memberId], $operator);

            return ['ok' => true, 'group' => $group, 'entries' => $entries,
                    'member_ids' => [$memberId],
                    'forced' => $gate['forced'], 'gates' => $gate['hit']];
        });
    }

    /**
     * 一张订单的分配 —— 事务由调用方开。
     *
     * 这一整段原本就是 grant() 事务闭包的全部内容，抽出来是为了让
     * grantMerged() 能在【同一个事务】里把它跑 M 遍。逻辑一行没改：
     * 金额守恒仍然是逐单校验的，本来就是 per-order 的，合并不动它。
     */
    private function grantOne(string $serialId, array $allocations, int $allocMode,
                              array $operator, ?string $group): array
    {
            $order = $this->orders->lockBySerial($serialId);
            if ($order === null) {
                return ['ok' => false, 'error' => 'order_not_found'];
            }
            if ((int)$order['eat_type'] !== 0) {
                return ['ok' => false, 'error' => 'not_dine_in'];
            }
            // 免费餐 / 核销单是否放行，取决于「免费餐的额外消费是否计入」开关：
            //   关（默认）整单拒绝
            //   开        放行，但 total_amount 里餐费项已被剔除，
            //             且下面会把计次强制归零 —— 兑换来的那餐不该再攒一次
            $extraEarns = $this->cfg->get('free_meal_extra_earns', '0') === '1';
            /**
             * ★ 核销单要区分「整单免」与「混合单」。
             *
             * pos_order.portions_counted 存的是【净】份数（buildContext 已扣掉
             * 券抵的份数）。所以：
             *   净份数 = 0 → 整桌都是兑换来的，整单不计次不积分（原有口径）
             *   净份数 > 0 → 混合单，那几位付了钱的客人照常计次积分
             *
             * 早先不分这两种，只要有核销行就整单拒绝发分 ——
             * 实测 5 张核销单里 2 张是混合单，那些付费客人一次都没计上。
             */
            $fullyRedeemed = (int)($order['is_redeemed'] ?? 0) === 1
                          && (int)$order['portions_counted'] === 0;
            $freeish       = (int)$order['is_free_meal'] === 1 || $fullyRedeemed;

            if ((int)$order['is_free_meal'] === 1 && !$extraEarns) {
                return ['ok' => false, 'error' => 'free_meal'];
            }
            if ($fullyRedeemed && !$extraEarns) {
                return ['ok' => false, 'error' => 'redeemed'];
            }

            $total     = Money::toCents($order['total_amount']);
            $allocated = Money::toCents($order['allocated_amount']);
            $totalPort = (int)$order['portions_counted'];
            $allocPort = (int)$order['allocated_portions'];

            if ($total <= 0) {
                return ['ok' => false, 'error' => 'zero_amount'];
            }

            /**
             * ★ 同一张卡不能在同一张订单上记两次。
             *
             *   金额守恒本来就挡住了「多拿」—— 第二次超额会被 exceeds_total 拒掉，
             *   所以【不存在重复计分】。但它挡不住「把一张单分两次都记给同一个人」：
             *   AA 拆成两半，两半都选同一张卡，金额照样守恒，只是分了两笔。
             *
             *   现场看到的就是这个：屏幕上「+27 分」，同时又提示
             *   「本餐期已记过 1 次，这一单只记积分不计次」，
             *   收银员完全没法判断这是正常的下半单，还是自己点重了。
             *
             *   而把 AA 的两半都给同一个人，现实中基本只可能是误操作 ——
             *   真要整单给一个人，人数填 1 就行，不必拆。
             *
             *   PointsEngine 里那条 duplicate_member 只管【一次提交内】重复；
             *   这里管的是【跨提交】重复，两者都需要。
             */
            $already = [];
            foreach ($this->ledger->activeBySerial($serialId) as $row) {
                if ((int)$row['entry_type'] === LedgerRepo::T_EARN) {
                    $already[(int)$row['member_id']] = (string)($row['card_no'] ?? '');
                }
            }
            foreach ($allocations as $a) {
                $mid = (int)($a['member_id'] ?? 0);
                if (isset($already[$mid])) {
                    return ['ok' => false, 'error' => 'member_already_on_order',
                            'detail' => ['member_id' => $mid, 'card_no' => $already[$mid]]];
                }
            }

            /**
             * ★ 一张单最多能记几位会员 = 计次套餐的份数（0 份的单只准 1 位）。
             *
             *   份数是这张单上「有几个人在这儿吃了饭」唯一可信的凭据。
             *   不封这个数的话，一张 € 200 的单可以拆给十张卡，
             *   每张都拿一份积分 —— 而其中大部分人根本没来过。
             *
             *   ★ 这一条【挡得住 exceeds_portions 挡不住的形状】：
             *     3 份的单拆给 5 个人、份数填成 [1,1,1,0,0]，
             *     份数合计 3 没超、金额也没超，守恒那两层全都放行。
             *
             *   0 份的单还留 1 位：纯酒水单是正常生意，钱是真花的，
             *   该给积分 —— 只是它证明不了几个人吃了饭，所以不给拆。
             *
             *   Pad 上「+ 添加会员」按钮到数就变灰、AA 人数框也封了顶，
             *   但那是给正常操作省事的；这一层才是真的锁。
             */
            $seatCap = max(1, $totalPort);
            $seats   = $already;
            foreach ($allocations as $a) {
                $seats[(int)($a['member_id'] ?? 0)] = true;
            }
            unset($seats[0]);
            if (count($seats) > $seatCap) {
                return ['ok' => false, 'error' => 'too_many_members',
                        'detail' => ['cap' => $seatCap, 'requested' => count($seats)]];
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
            $countMode  = $this->cfg->get('visit_count_mode', 'once_per_period');

            $entries = [];
            foreach ($allocations as $a) {
                $memberId = (int)$a['member_id'];
                $amt      = (int)($a['amount_cents'] ?? 0);
                $prt      = (int)($a['portions'] ?? 0);

                $member = $this->members->lockById($memberId);
                if ($member === null) {
                    return ['ok' => false, 'error' => 'member_not_found', 'detail' => ['member_id' => $memberId]];
                }

                /**
                 * 卡片等级的积分倍率，叠在全局倍率之上：
                 *   积分 = 金额 × 每欧元分数 × 全局倍率 × 本等级倍率
                 *
                 * 逐人查而不是提到循环外：同一单里不同的人可能拿着不同等级的卡
                 * （一桌四个人，两个金卡两个普卡是很常见的）。
                 */
                $tier   = $this->tiers->forMember($memberId);
                $points = PE::pointsFor($amt, $perEuro, $multiplier * $tier['multiplier']);
                // by_portion：按 counts_visit=1 菜品的份数计次
                // by_ledger ：每笔流水最多 1 次
                // ★ 免费餐 / 核销单不计次：那一餐是兑换来的，
                //   再计一次就等于「拿奖励的同时又攒一次」。
                //   金额可以照常积分（取决于 free_meal_extra_earns），但次数不给。
                $visits = $freeish ? 0
                        : $this->visitsFor($countMode, $memberId, $prt, $amt, (string)$order['order_end_time']);

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
                    'grant_group'        => $group,
                    // ★ 记下当时用的等级与倍率。倍率是活查的，改了立刻对以后生效 ——
                    //   流水里不记的话，事后回答不了「这单为什么给了 150 分」，
                    //   而这正是客人申诉、会计对账、撤销重算时第一个要问的。
                    'tier_code'          => $tier['code'],
                    'tier_multiplier'    => $tier['multiplier'],
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
                    'tier'      => $tier['code'],
                    'tier_x'    => $tier['multiplier'],
                ];
            }

            $this->orders->applyAllocation($serialId, $sumAmount, $sumPortions);

            $this->audit->log('point_grant', [
                'target_type'   => 'order',
                'target_id'     => $serialId,
                'operator_id'   => $operator['id']   ?? null,
                'operator_name' => $operator['name'] ?? null,
                'device'        => $operator['device'] ?? null,
                'detail'        => ['mode' => $allocMode, 'entries' => $entries,
                                    'grant_group' => $group],
            ]);

            return ['ok' => true, 'entries' => $entries, 'member_ids' => array_column($entries, 'member_id')];
    }

    /**
     * 这一笔该记几次。
     *
     * ── once_per_period（默认）───────────────────────────
     *
     * 🔴 **一张卡，一个餐期，最多 1 次。** 不管这一单给他分了几份套餐。
     *
     * 这是「十送一」口径的一次根本改变：从「买 10 份套餐」变成
     * 「来 10 趟」。理由是前者没法防：
     *
     *   一桌 10 个人 10 份套餐，整单记给一个人 = 一次 10 次计次，
     *   当场就够十送一。也就是说【一张小票 = 一顿免费的饭】——
     *   捡到一张就直接换一顿，连攒都不用攒。
     *
     * 改成按人按餐期计次之后：
     *   · 一桌 4 个人有 4 张卡 → 4 张各记 1 次
     *   · 一桌 4 个人只有 2 张卡 → 只有那 2 张各记 1 次，
     *     另外 2 份的次数【就是没有了】，不会挪给在场的卡
     *   · 捡一张 10 人的小票 → 1 次，收益掉一个数量级
     *
     * ★ 判定要查库，不能只看本次分配。
     *   同一个餐期里客人可能分两次结账（先点后加菜、分单），
     *   也可能走多桌合并 —— 合并是在【同一个事务】里连着调本方法几遍，
     *   第一遍插进去的流水必须被第二遍看见，否则三桌各记 1 次，
     *   等于什么都没防住。查库（而不是缓存）正是为了这个。
     *
     * ── by_portion / by_order（保留）─────────────────────
     * 老口径，店家要回去也回得去。by_portion 是「买 N 份送 1 份」，
     * by_order 是「每笔账算 1 次」（同一餐期分两次结账会算 2 次）。
     */
    private function visitsFor(
        string $mode,
        int $memberId,
        int $portions,
        int $amountCents,
        string $orderEndTime
    ): int {
        if ($portions <= 0) {
            return 0;
        }
        /**
         * ★ 没有金额就没有次数 —— 与 PointsEngine 的 portions_without_amount 同一条规则。
         *
         *   那边是【校验】，在事务最前面把整笔拒掉；这里是【兜底】，
         *   保证就算哪天有人绕开校验直接构造分配，写进流水的也不会是
         *   「0 元 1 次」这种账。规则写在两处不是重复：
         *   校验决定「能不能提交」，这里决定「流水长什么样」。
         */
        if ($amountCents <= 0) {
            return 0;
        }
        if ($mode === 'by_portion') {
            return $portions;
        }
        if ($mode === 'by_order') {
            return 1;
        }
        // once_per_period：这一顿已经记过就不再记
        return $this->countedThisSitting($memberId, $orderEndTime) ? 0 : 1;
    }

    /** 这张卡在这一顿（同一营业日 + 同一餐期）里是不是已经记过次数了 */
    private function countedThisSitting(int $memberId, string $refEndTime): bool
    {
        [$from, $to] = $this->bizDay->range($this->bizDay->of($refEndTime));
        foreach ($this->ledger->earnedInRange($memberId, $from, $to) as $r) {
            if ((int)$r['counted_visit'] <= 0) {
                continue;
            }
            if ($this->periods->sameSitting((string)$r['order_end_time'], $refEndTime, $this->bizDay)) {
                return true;
            }
        }
        return false;
    }

    // ════════════════════════════════════════════════════════
    // 风控闸门（docs/03 §12）
    // ════════════════════════════════════════════════════════

    /**
     * 记账前的两道闸门：补记时限、同一餐期的次数上限。
     *
     * ★ 两道都【不是硬拒绝】，而是「普通收银员做不了，经理填原因可以做」。
     *
     *   一刀拒绝的代价是柜台当面回绝客人 —— 那正是投诉的来源，而且
     *   两种被拦下的情形里都有大量正当的：客人忘带卡隔天来补、
     *   一家人中午吃完晚上又来。给经理留一个带原因、带留痕的口子，
     *   既守住了规则（普通收银员破不了例），事后又查得到是谁放的行。
     *   与「超宽限期换卡」「经理强制核销」是同一套做法。
     *
     * @param string[] $serialIds
     * @param int[]    $memberIds
     * @return array{ok:bool,error?:string,detail?:array,forced:bool,hit:array}
     */
    /**
     * @param string $nowAtPos 主库当下时刻，由调用方在【进事务之前】取好。
     *
     * ★ 不在这里调 posNow()。原因见 grant() 上方那段说明 ——
     *   一句话：这个方法跑在本地库事务里，而 POS 是一台会抖的老机器。
     */
    private function checkGates(array $serialIds, array $memberIds, array $operator,
                                ?array $override, string $nowAtPos): array
    {
        $hit = [];

        // ── ① 补记时限 ────────────────────────────────────
        $lateMin = $this->cfg->int('late_grant_minutes', 60);
        $oldest  = null;
        if ($lateMin > 0) {
            foreach ($serialIds as $sid) {
                $o = $this->orders->findBySerial($sid);
                if ($o === null) { continue; }
                /**
                 * ★ 时间基准取【主库】的 now()，不是应用服务器的 time()。
                 *
                 *   order_end_time 来自 POS 主库，拿本地时钟去减它，
                 *   两台机器的时钟差多少，这个 age 就错多少。
                 *   POS 是一台老 Windows 机器，漂移是常态：
                 *     POS 快 2 小时 → age 为负 → 补记时限【永不触发】
                 *     POS 慢 2 小时 → 每一单都超时限，每次记账都要叫经理
                 *   两个方向都难查，界面上只会说「这一单超出普通记账范围」。
                 *
                 *   locateByInvoice() 的回溯天数判断本来就用的是 pos->now()，
                 *   全仓库原本只有这一处用本地时间，是漏改。
                 *
                 * ★ 但这个时刻是【调用方在进事务之前取好一次】传进来的，
                 *   不在这个循环里现取 —— 见 posNow() 的说明。
                 */
                $age = (strtotime($nowAtPos) - strtotime((string)$o['order_end_time'])) / 60;
                if ($oldest === null || $age > $oldest) { $oldest = $age; }
            }
            if ($oldest !== null && $oldest > $lateMin) {
                $hit[] = ['gate' => 'late_grant', 'minutes' => (int)round($oldest), 'limit' => $lateMin];
            }
        }

        // ── ② 同一餐期的记账次数 ──────────────────────────
        $cap = $this->cfg->int('max_grants_per_period', 0);
        if ($cap > 0 && $memberIds && $serialIds) {
            $ref = $this->orders->findBySerial($serialIds[0]);
            if ($ref !== null) {
                foreach (array_unique(array_filter($memberIds)) as $mid) {
                    $n = $this->countGrantsInSitting((int)$mid, (string)$ref['order_end_time'], $serialIds);
                    if ($n >= $cap) {
                        $hit[] = ['gate' => 'period_cap', 'member_id' => (int)$mid,
                                  'used' => $n, 'limit' => $cap];
                    }
                }
            }
        }

        if (!$hit) {
            return ['ok' => true, 'forced' => false, 'hit' => []];
        }

        // 撞了闸门 —— 要经理 + 原因才放行
        if ($override === null) {
            return ['ok' => false, 'error' => 'manager_required', 'detail' => ['gates' => $hit],
                    'forced' => false, 'hit' => $hit];
        }
        if ((int)($operator['role'] ?? 0) < 2) {
            return ['ok' => false, 'error' => 'forbidden', 'detail' => ['gates' => $hit],
                    'forced' => false, 'hit' => $hit];
        }
        if (trim((string)($override['reason'] ?? '')) === '') {
            return ['ok' => false, 'error' => 'reason_required', 'detail' => ['gates' => $hit],
                    'forced' => false, 'hit' => $hit];
        }
        return ['ok' => true, 'forced' => true, 'hit' => $hit];
    }

    /**
     * 这张卡在【这一顿】里已经记过几次账。
     *
     * ★ 数的是「几次记账操作」，不是「几张订单」——
     *   一次三桌合并算 1 次。所以大团不会被误伤，
     *   而陆续拿三张小票来的会被数成 3 次。这正是要区分的东西。
     *   实现上就是按 COALESCE(grant_group, serial_id) 去重。
     *
     * @param string[] $exclude 本次正在记的这几单不算进来
     */
    private function countGrantsInSitting(int $memberId, string $refEndTime, array $exclude): int
    {
        $bizDate = $this->bizDay->of($refEndTime);
        [$from, $to] = $this->bizDay->range($bizDate);

        $rows = $this->ledger->earnedInRange($memberId, $from, $to);

        $seen = [];
        foreach ($rows as $r) {
            if (in_array((string)$r['serial_id'], $exclude, true)) { continue; }
            // 同一顿 = 同一营业日 + 同一餐期
            if (!$this->periods->sameSitting((string)$r['order_end_time'], $refEndTime, $this->bizDay)) {
                continue;
            }
            $seen[(string)($r['grant_group'] ?? '') !== '' ? 'g:' . $r['grant_group'] : 's:' . $r['serial_id']] = true;
        }
        return count($seen);
    }

    /**
     * 合并的几单必须是同一顿饭。
     *
     * 三条判据，全部来自「同行分桌一定挨在一起、捡小票一定分散」：
     *   · 同一营业日
     *   · 同一餐期（中午那桌和晚上那桌不是同一顿）
     *   · 最早与最晚结账时间的跨度不超过配置值
     */
    private function checkMergeSpan(array $serials): array
    {
        $times = [];
        foreach ($serials as $sid) {
            $o = $this->orders->findBySerial($sid);
            if ($o === null) {
                return ['ok' => false, 'error' => 'order_not_found', 'detail' => ['serial_id' => $sid]];
            }
            $times[$sid] = (string)$o['order_end_time'];
        }
        $ref = reset($times);
        foreach ($times as $sid => $t) {
            if (!$this->periods->sameSitting($t, $ref, $this->bizDay)) {
                return ['ok' => false, 'error' => 'merge_not_same_sitting',
                        'detail' => ['serial_id' => $sid]];
            }
        }
        $spanMin = max(1, $this->cfg->int('merge_span_minutes', 60));
        $stamps  = array_map('strtotime', array_values($times));
        $span    = (max($stamps) - min($stamps)) / 60;
        if ($span > $spanMin) {
            return ['ok' => false, 'error' => 'merge_span_too_wide',
                    'detail' => ['span_minutes' => (int)round($span), 'limit' => $spanMin]];
        }
        return ['ok' => true];
    }

    /**
     * 破例放行的记账，【单独记一条审计】。
     *
     * ★ 为什么不塞进 point_grant 的 detail 里就算了：
     *   后台「审计」页是按 action 筛的。混在普通记账里的话，
     *   想回答「这个月一共破了几次例、都是谁放的行」就得把当月
     *   几千条 point_grant 全捞出来一条条看 detail —— 等于查不了。
     *   单独一个 action 名，筛一下就是全部破例。
     *   与 card_replace_forced 是同一套做法。
     */
    private function auditForced(array $gate, array $serials, array $operator, ?array $override): void
    {
        if (!($gate['forced'] ?? false)) {
            return;
        }
        $this->audit->log('point_grant_forced', [
            'target_type'   => 'order',
            'target_id'     => implode(',', $serials),
            'operator_id'   => $operator['id']   ?? null,
            'operator_name' => $operator['name'] ?? null,
            'device'        => $operator['device'] ?? null,
            'detail'        => ['gates' => $gate['hit'], 'reason' => $override['reason'] ?? null],
        ]);
    }

    /** 组号：短、可读、够用即可 —— 它只在本店本库里区分不同的合并操作 */
    private function newGroupId(): string
    {
        return 'G' . date('ymdHis') . strtoupper(substr(bin2hex(random_bytes(2)), 0, 3));
    }

    /**
     * 记账之后看一眼有没有值得留痕的形状 —— 【只告警，不拦】。
     *
     * ★ 这是唯一能管住内部人的东西。
     *
     *   上面那两道闸门都建立在「收银员是诚实的」之上，可员工本人就是
     *   收银员，他要么有经理 PIN，要么干脆就是经理。对内部作案，
     *   事前拦截在结构上就是无效的 —— 能做的只有让它留下痕迹，
     *   并且让这个痕迹每周有人看一眼。
     *
     *   所以这里绝不返回错误、绝不影响记账结果，异常也吞掉：
     *   风控的副作用不该把已经算好的积分弄回滚。
     */
    /**
     * 主库时间 —— 拿不到就回落到本地时间。
     *
     * ★ 所有「这一单过了多久」的判断都必须用它，不能用 time()：
     *   order_end_time 是 POS 给的，两边时钟不一致时差多少就错多少。
     *
     * ── 🔴 这里【一次 POS 都不打】────────────────────────
     *
     * grant() / grantMerged() 的类注释立着一条：
     *
     *     grant() 【不碰 POS】，只在本地库事务内完成分配，
     *     因此主库抖动不会阻塞收银流程。
     *
     * 曾经为了拿主库时间，在 checkGates() 的逐单循环里现打 POS，
     * 那句话就不再成立了。实测：
     *   · POS 正常时：单桌 1 次往返、两桌合并 2 次 ——
     *     一个在单次请求内恒定的值被查了 N 次；
     *   · POS「连得上但不应答」时（现场最常见的抖动形态，read_timeout=5）：
     *     单桌 grant() 卡 5.0 秒、两桌合并 10.0 秒，
     *     merge_max_orders 默认 8 → 最坏约 40 秒，
     *     而且全程占着一个已经开着的本地库事务。
     *
     * 现在换成【本机时间 + 时钟偏差】：偏差由 Cron 每 20 分钟顺手记一次
     * （SyncService::incremental —— 它本来就要问一次 POS 的时间，白问不如记下来）。
     * 偏差变化极慢（两台机器的晶振差），20 分钟的新鲜度绰绰有余。
     *
     * ★ 没记过偏差时（新装、Cron 还没跑过）回落到 0 —— 也就是本机时间。
     *   那与修复之前的行为一致，不会更糟；而 Cron 一跑就自动对上。
     *
     * ★★ 偏差超过一天就【当它不存在】。
     *
     *   两台机器的时钟差、甚至时区填错，最多也就十几个小时；
     *   差到一天以上只有两种可能：存进去的值早就过期了，或者 POS 的
     *   时钟本身是坏的。这两种情况下用它，后果是【闸门被静默关掉】——
     *   age 算成负数，补记时限永远不触发，而界面上什么都看不出来。
     *
     *   宁可退回本机时间：那只是「基准可能差几分钟」，
     *   而不是「这道闸门实际上没在工作」。
     */
    private const CLOCK_OFFSET_MAX_SEC = 86400;

    private function posNow(): string
    {
        $off = $this->cfg->int('pos_clock_offset_sec', 0);
        if (abs($off) > self::CLOCK_OFFSET_MAX_SEC) {
            $off = 0;
        }
        return date('Y-m-d H:i:s', time() + $off);
    }

    /**
     * 「有人在枚举小票号」的观察者。
     *
     * ── 为什么要有 ────────────────────────────────────
     * 小票号就是 order_head_id，是一个【连号的整数】。
     * 手里有一张自己的小票，就知道当前号段在哪儿，往前减一个个试
     * 就能把别人的单翻出来。
     *
     * 前面那两道（错误信息不区分、经理才看得到真原因）挡住的是
     * 「试出来能知道什么」；这一道记的是「有人在试」。
     *
     * ★ 与 §12.4 同一个道理：能拿到收银机的就是店里的人，
     *   事前拦截在结构上无效 —— 能做的是留下痕迹，并让它每周有人看一眼。
     *
     * ★ 不拦人。收银员照着小票输错几个数字是天天发生的事，
     *   拦下来的代价是当着客人的面卡住，而这正是投诉的来源。
     *
     * 整段包在 try/catch 里：观察者坏了不该让找单也跟着失败。
     */
    public function watchInvoiceProbe(int $invoiceNo, array $operator): void
    {
        try {
            $this->audit->log('invoice_lookup_miss', [
                'target_type'   => 'invoice',
                'target_id'     => (string)$invoiceNo,
                'operator_id'   => $operator['id']   ?? null,
                'operator_name' => $operator['name'] ?? null,
                'device'        => $operator['device'] ?? null,
            ]);

            $max = $this->cfg->int('alert_invoice_miss', 0);
            $win = $this->cfg->int('alert_invoice_window_min', 30);
            if ($max <= 0) {
                return;
            }
            $opId = isset($operator['id']) ? (int)$operator['id'] : null;
            $n    = $this->audit->countRecent('invoice_lookup_miss', $opId, $win);
            if ($n <= $max) {
                return;
            }
            $this->alerts->raiseOnce(
                'invoice_probe', 'operator', (string)($opId ?? 0),
                sprintf('%s 在 %d 分钟内查了 %d 个查不到的小票号（阈值 %d）——'
                      . '照小票输错几个数字是常事，但连着这么多次更像是在一个个试号',
                        $operator['name'] ?? ('#' . (string)$opId), $win, $n, $max),
                ['severity' => 2, 'detail' => [
                    'operator_id' => $opId, 'count' => $n, 'window_min' => $win,
                    'last_invoice' => $invoiceNo, 'device' => $operator['device'] ?? null,
                ]]
            );
        } catch (\Throwable $e) {
            error_log('[watchInvoiceProbe] ' . $e->getMessage());
        }
    }

    private function riskWatch(array $memberIds, array $operator): void
    {
        try {
            $maxDay = $this->cfg->int('alert_grants_per_day', 0);
            $maxSpan = $this->cfg->int('alert_span_hours', 0);
            if ($maxDay <= 0 && $maxSpan <= 0) {
                return;
            }
            [$from, $to] = $this->bizDay->range($this->bizDay->of(date('Y-m-d H:i:s')));

            foreach (array_unique(array_filter($memberIds)) as $mid) {
                $rows = $this->ledger->earnedInRange((int)$mid, $from, $to);
                if (!$rows) { continue; }

                $ops = [];
                foreach ($rows as $r) {
                    $ops[(string)($r['grant_group'] ?? '') !== '' ? 'g:' . $r['grant_group'] : 's:' . $r['serial_id']] = true;
                }
                $n = count($ops);
                $card = $this->members->findById((int)$mid)['card_no'] ?? ('#' . $mid);

                if ($maxDay > 0 && $n > $maxDay) {
                    $this->alerts->raiseOnce('grant_many_per_day', 'member', (string)$mid,
                        sprintf('卡 %s 今天已记账 %d 次（阈值 %d）——「同行分桌」算 1 次，'
                              . '所以这是 %d 次分开的操作，值得核一下是不是同一位客人的消费',
                                $card, $n, $maxDay, $n),
                        ['severity' => 2, 'detail' => ['member_id' => (int)$mid, 'count' => $n,
                            'operator' => $operator['name'] ?? null]]);
                }

                if ($maxSpan > 0 && count($rows) > 1) {
                    $stamps = array_map(static fn(array $r): int => (int)strtotime((string)$r['order_end_time']), $rows);
                    $spanH  = (max($stamps) - min($stamps)) / 3600;
                    if ($spanH > $maxSpan) {
                        $this->alerts->raiseOnce('grant_span_wide', 'member', (string)$mid,
                            sprintf('卡 %s 今天记的几单，结账时间跨了 %.1f 小时（阈值 %d）——'
                                  . '同一顿饭不会跨这么久，像是攒了一把小票一起来兑',
                                    $card, $spanH, $maxSpan),
                            ['severity' => 2, 'detail' => ['member_id' => (int)$mid,
                                'span_hours' => round($spanH, 1), 'operator' => $operator['name'] ?? null]]);
                    }
                }
            }
        } catch (\Throwable $e) {
            // 风控只是观察者，坏了也不该影响记账
            error_log('[riskWatch] ' . $e->getMessage());
        }
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
        return $this->db->transaction(fn(): array => $this->reverseInTx($ledgerId, $reason, $operator));
    }

    /**
     * 撤销一条 —— 事务由调用方开。
     *
     * 抽出来是为了让 reverseGroup() 能在【同一个事务】里撤掉整组，
     * 做到要么全撤要么全不撤。逻辑一行没改。
     */
    private function reverseInTx(int $ledgerId, string $reason, array $operator): array
    {
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
    }

    /**
     * 整组撤销 —— 一次多桌合并产出的几笔，一起撤掉。
     *
     * 为什么要单独有这个：合并是一次操作，撤销也该是一次操作。
     * 让收银员逐条撤三笔，中间任何一步分神就留下一个撤了两桌、
     * 剩一桌还挂着的会员 —— 而这种半成品账，事后没人看得懂。
     *
     * 逐条复用 reverse()：那里已经把「写反向流水、回退会员余额、
     * 回退订单已分配额、标记原流水」四件事做全了，这里只是把它们
     * 圈进同一个事务，要么全撤要么全不撤。
     */
    public function reverseGroup(string $group, string $reason, array $operator): array
    {
        $group = trim($group);
        if ($group === '') {
            return ['ok' => false, 'error' => 'bad_request'];
        }
        return $this->db->transaction(function () use ($group, $reason, $operator) {
            $rows = $this->ledger->lockActiveByGroup($group);
            if (!$rows) {
                return ['ok' => false, 'error' => 'group_not_found'];
            }
            $ids = [];
            foreach ($rows as $r) {
                $one = $this->reverseInTx((int)$r['id'], $reason, $operator);
                if (!($one['ok'] ?? false)) {
                    return $one + ['detail' => ($one['detail'] ?? []) + ['ledger_id' => (int)$r['id']]];
                }
                $ids[] = $one['reversal_id'];
            }
            $this->audit->log('point_reverse_group', [
                'target_type'   => 'grant_group', 'target_id' => $group,
                'operator_id'   => $operator['id']   ?? null,
                'operator_name' => $operator['name'] ?? null,
                'device'        => $operator['device'] ?? null,
                'detail'        => ['reason' => $reason, 'count' => count($ids), 'reversal_ids' => $ids],
            ]);
            return ['ok' => true, 'count' => count($ids), 'reversal_ids' => $ids];
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
        /**
         * 两道上限，管的事情不一样：
         *
         * ① manual_entry_limit（软）—— 超过要经理放行。经理身份即视为已批
         *    （路由里 approved_by = 经理 id），这是 docs/03 §10 的设计。
         *
         * ② manual_entry_hard_limit（硬）—— ★ 谁都过不去，经理也不行。
         *
         * ── 为什么要有第二道 ────────────────────────────
         * 「经理可以破例」和「经理可以一次记 100 万欧」是两件事。
         * 实测：后台 manual_entry_limit = 200.00，经理提交 999999.99
         * 直接成功，会员余额当场变成 999999 分 —— 软上限对经理
         * 【完全不生效，且没有任何替代上限】。
         *
         * docs/03 §12.4 的取舍（对内部人只能留痕、不能事前拦截）仍然成立，
         * 这一道不是用来防内部作案的，是防【手滑多打几个零】——
         * 而那个错误一旦发生，积分已经进了卡，撤销要人工翻账。
         *
         * 默认 5000.00：远高于任何一张真实小票，又拦得住多打两个零。
         * 填 0 = 不设硬上限（不建议）。
         */
        $limit = Money::toCents($this->cfg->get('manual_entry_limit', '200.00'));
        $hard  = Money::toCents($this->cfg->get('manual_entry_hard_limit', '5000.00'));
        if ($hard > 0 && $amountCents > $hard) {
            return ['ok' => false, 'error' => 'exceeds_manual_hard_limit',
                    'detail' => ['limit' => Money::toStr($hard)]];
        }
        if ($amountCents > $limit && empty($operator['approved_by'])) {
            return ['ok' => false, 'error' => 'exceeds_manual_limit',
                    'detail' => ['limit' => Money::toStr($limit)]];
        }

        return $this->db->transaction(function () use ($memberId, $amountCents, $reasonCode, $operator) {
            $member = $this->members->lockById($memberId);
            if ($member === null) {
                return ['ok' => false, 'error' => 'member_not_found'];
            }

            // 手工录入也套等级倍率 —— 否则同一位客人「系统查不到订单」时
            // 反而少拿分，这种不一致最难跟客人解释
            $tier   = $this->tiers->forMember($memberId);
            $points = PE::pointsFor(
                $amountCents,
                $this->cfg->float('points_per_euro', 1.0),
                $this->cfg->float('points_multiplier', 1.0) * $tier['multiplier']
            );

            $lid = $this->ledger->insert([
                'member_id'     => $memberId,
                'serial_id'     => null,
                'entry_type'    => LedgerRepo::T_EARN,
                'amount_cents'  => $amountCents,
                'points'        => $points,
                'counted_visit' => 0,      // 手工录入无明细，无法判断套餐份数 → 不计次
                'tier_code'      => $tier['code'],
                'tier_multiplier'=> $tier['multiplier'],
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
