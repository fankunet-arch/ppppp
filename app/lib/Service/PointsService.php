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
        /**
         * ★ 撤销时要把「已经不再算挣到」的券一并收回（见 reverseInTx）。
         *   RewardService 不依赖 PointsService，不构成环。
         */
        private RewardService $rewards,
        /**
         * ★ 可空。这里只拿它把 existing_ledger 的卡号格式化成显示形态
         *   （docs/03 §3.1ter：同一张卡不能在两个屏幕上长得不一样）。
         *
         *   ★★ 记账路径【不能】因为卡号格式化而整条挂掉。
         *   card_prefix 配错时 CardNumber 构造即抛，如果这里是必填的，
         *   App::points() 就构造不出来 —— 实测那会让
         *   /order/locate、/order/locate-invoice、/points/grant、/points/manual
         *   全部 500：收银员登得进去，一单也记不了。
         *   而 docs/03 §10 立的规矩是「不阻塞收银流程」。
         *
         *   为 null 时卡号原样输出（未格式化的串仍然是可读的，
         *   只是少了连字符）—— 少一个分组符，远好过整个收银台停摆。
         */
        private ?\Vip\CardNumber $cardNo = null,
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
            /**
             * ── 🔴 一张单出问题，不能拖垮整桌 ──────────────────
             *
             * locate 是【按桌批量】的：一张桌上最近的几单一起取出、逐单
             * buildContext。而 buildContext 会写镜像（upsert），万一某一单的
             * 金额落在 DECIMAL(11,2) 之外（>10 亿欧），upsert 抛
             * 22003，整桌的 locate 一起挂 —— 收银员对这张桌一个客人都记不了账。
             *
             * 这需要 POS 主库里真出现一个 10 亿欧的订单，正常绝不会发生
             * （POS 自己的字段宽度也就那么大），但「一张坏单阻塞整桌」这件事
             * 本身不该成立。坏的那一单跳过并告警，其余照常返回。
             *
             * ★ PosUnavailable 不在这里吞 —— 那是「主库连不上」，要整体走
             *   降级（手工录入），已由外层 try 处理。这里只接【单单级】的意外。
             */
            try {
                $out[] = $this->buildContext($o);
            } catch (PosUnavailable $e) {
                throw $e;
            } catch (\Throwable $e) {
                $serial = (string)($o['serial_id'] ?? ('oh:' . ($o['order_head_id'] ?? '?')));
                $this->alerts->raiseOnce('order_build_failed', 'order', $serial,
                    sprintf('订单 %s 无法处理（%s），已跳过，不影响同桌其他单',
                        $serial, $e->getMessage()),
                    ['severity' => 2]);
            }
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
            /**
             * ★ 按【日历天】回溯，不是 N × 86400 秒。
             *
             *   Europe/Madrid 每年有一天 23 小时、一天 25 小时。
             *   减秒数会让回溯窗在那两天各差一小时 ——
             *   春季那天窗口短一小时，恰好卡在窗边的那张小票就查不到了，
             *   收银员看到的是「查无此单」，而单子就在客人手里。
             *   与 BusinessDay::range() 同一个理由、同一种解法。
             */
            $cut = (new \DateTimeImmutable($this->pos->now()))
                ->modify('-' . $maxDays . ' days')->format('Y-m-d H:i:s');
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

        /**
         * ★ App 自己核销掉的券，是比匹配串【更硬】的证据。
         *
         *   上面那一行只是拿 redeem_line_patterns 去猜 POS 折扣行的名字，
         *   而名字是会变的。但「哪一张券核销在哪一单」App 是确知的
         *   （redeem() 存了 redeemed_serial_id）—— 那才是地面真值。
         *
         *   ★ 只【加】不【减】：匹配串认出来的照旧算数，
         *     这里补的是它没认出来的那一半。
         *     漏认的后果是免费餐又攒一次（每 9 顿付费就送一顿）；
         *     多认的后果是误伤正常客人 —— 两个方向都要守，
         *     但这一处只补漏认，不碰多认那一边（那一边由匹配串防呆 + 比例告警管）。
         */
        $appRedeemed = $this->orders->appRedeemedCount((string)$o['serial_id']);
        if ($appRedeemed > 0) {
            $isRedeemed = true;
        }

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
        /**
         * ★ 抵掉几份：取「匹配串反推出来的」与「App 自己核销的张数」中较大的一个。
         *   一张券 = 一份免费套餐，所以张数就是份数的下限。
         *   反推不出来（null）时保持 null —— 那是「保守口径：整单不计次」，
         *   比这里的下限更严，不能被削弱。
         */
        if ($appRedeemed > 0 && $portionsRedeemed !== null) {
            $portionsRedeemed = max($portionsRedeemed, $appRedeemed);
        }
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
            // ★ 同时存【总】份数（券抵之前）。核销时要按它重算净份数 ——
            //   否则 markRedeemedByApp 只能靠 is_redeemed 这个布尔去减，
            //   一桌两张券就少扣一份（迁移 019）。
            'portions_gross'     => $analysis['portions_counted'],
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
                    $l['card_no'] = $this->fmtCard((string)$l['card_no']);
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
                if ((int)$row['entry_type'] !== LedgerRepo::T_EARN) {
                    continue;
                }
                /**
                 * ★ 「0 元 0 分 0 次」的流水不算占位（审计 F10）。
                 *
                 *   这种空行落库之后，这位客人就被永久锁死在这张单上：
                 *   想给他补记 → member_already_on_order，
                 *   而那条流水里一分钱一次数都没有，撤销也无从撤起
                 *   （reverseInTx 撤的是钱和次，撤一条全零的等于没撤）。
                 *
                 *   现在提交侧已经把 0/0 直接拒掉（zero_allocation），
                 *   这里是给【已经落库的历史空行】留的出口 ——
                 *   否则那几位客人得等到订单过了保护期才解得开。
                 */
                if (Money::toCents((string)$row['amount']) === 0
                    && (int)$row['points'] === 0
                    && (int)$row['counted_visit'] === 0) {
                    continue;
                }
                $already[(int)$row['member_id']] = (string)($row['card_no'] ?? '');
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
            /**
             * ── 🔴 用券吃的那几位也要留座（审计 F9） ────────────────
             *
             * portions_counted 是【净】份数：券抵掉的那几份已经被扣掉了。
             * 拿它直接当座位数，会把「用券的那位客人」挤出这张单：
             *
             *   4 人桌，服务员按 AA 排好 4 位 → 其中一位当场核销了一张券
             *   → markRedeemedByApp 把净份数减到 3
             *   → 提交时 4 > 3 → too_many_members，整笔被拒
             *   而客人们就站在柜台前，收银员看到的提示是
             *   「这张单的付费套餐份数不够记这么多位客人」—— 与实情无关，
             *   也不告诉他该怎么办。
             *
             * 券本身就是「这个人在这儿吃了饭」最硬的凭据，比份数还硬。
             * 所以座位数要把券抵掉的份数加回来。
             *
             * ★ 只加回座位，不加回可分金额与可计次份数 —— 那两样仍然按净额走，
             *   用券那位照样是 0 元 0 次（免费餐不攒进度，见 §6）。
             *   这里放开的只是「他有没有资格出现在这张单上」。
             */
            $seatCap = max(1, $totalPort + $this->orders->appRedeemedCount($serialId));
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
            /**
             * ★ 计次的最低金额门槛 —— 一分钱不能换一次「十送一」的进度。
             *   门槛由后台配（不同门店套餐价差很大），填 0 就是不设。
             */
            $v = PE::validateAllocations($allocations, $total, $allocated, $totalPort, $allocPort,
                Money::toCents($this->cfg->get('min_amount_per_visit', '0')));
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
            $perVisit   = $this->cfg->float('points_per_visit', 1.0);
            $pointsMode = $this->cfg->get('points_mode', 'by_amount');
            $multiplier = $this->cfg->float('points_multiplier', 1.0);
            $countMode  = $this->cfg->get('visit_count_mode', 'once_per_period');

            /**
             * ── 🔴 固定加锁顺序，否则两台 Pad 会撞死锁 ────────────
             *
             * 下面的循环按【服务员在 Pad 上点人的顺序】逐个 SELECT ... FOR UPDATE
             * 锁会员行。两台 Pad 同时记两张 AA 单、而两张单上是同两位客人
             * （一对夫妻、常一起来的两个朋友，在小店里天天有），
             * 只要点人的顺序不同就构成经典的加锁顺序死锁：
             *
             *   1 号 Pad：锁张三 → 等李四
             *   2 号 Pad：锁李四 → 等张三
             *
             * MySQL 会挑一个牺牲者整笔回滚，收银员看到的是一句「数据库不可用，
             * 请联系管理员」—— 而库好得很，谁也没做错事。
             * 实测两个进程各 60 单、只把顺序反过来：120 单里死锁 27 次（22.5%），
             * Innodb_deadlocks 计数一次不差。
             *
             * grantMerged() 早就为同一件事写了 `sort($serials)`，
             * 说明这个风险被想到过 —— 只是没推广到【每天跑几百次】的这条路。
             *
             * ★ 先按 member_id 升序把锁全部拿到手，再走原来的循环。
             *   不直接给 $allocations 排序，是因为返回的 entries 顺序
             *   就是结果页的显示顺序 —— 那应当跟着服务员点人的顺序，
             *   而不是跟着数据库主键。锁的顺序和显示的顺序是两件事。
             *   事务内重复 lockById 不会再等锁，所以下面那句照原样留着。
             */
            $lockIds = array_map(static fn(array $a): int => (int)$a['member_id'], $allocations);
            $lockIds = array_values(array_unique($lockIds));
            sort($lockIds, SORT_NUMERIC);
            foreach ($lockIds as $lid) {
                $this->members->lockById($lid);
            }

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
                 * 卡片等级的积分倍率，叠在全局倍率之上。
                 *
                 * 逐人查而不是提到循环外：同一单里不同的人可能拿着不同等级的卡
                 * （一桌四个人，两个金卡两个普卡是很常见的）。
                 */
                $tier = $this->tiers->forMember($memberId);

                // by_portion：按 counts_visit=1 菜品的份数计次
                // by_ledger ：每笔流水最多 1 次
                // ★ 免费餐 / 核销单不计次：那一餐是兑换来的，
                //   再计一次就等于「拿奖励的同时又攒一次」。
                //   金额可以照常积分（取决于 free_meal_extra_earns），但次数不给。
                $visits = $freeish ? 0
                        : $this->visitsFor($countMode, $memberId, $prt, $amt, (string)$order['order_end_time']);

                /**
                 * ★ 积分口径（points_mode）—— 必须放在算完 $visits 之后。
                 *
                 *   by_amount（默认）：金额 × 每欧元分数 × 全局倍率 × 等级倍率
                 *   by_visit        ：计次数 × 每次分数   × 全局倍率 × 等级倍率
                 *
                 *   后者「没计上次就没有分」是定义而不是漏算 ——
                 *   同一餐期第二单不计次，那一单在这个口径下也不积分。
                 *   Pad 上会把这句话说出来（done.noVisit 按口径换措辞），
                 *   否则客人问「我这单怎么一分没有」时收银员答不上来。
                 */
                $points = $pointsMode === 'by_visit'
                    ? PE::pointsForVisit($visits, $perVisit, $multiplier * $tier['multiplier'])
                    : PE::pointsFor($amt, $perEuro, $multiplier * $tier['multiplier']);

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
                    // ★ 走 format：库里存的是去连字符的串，而 Pad 上别处显示的都是
                    //   分组形态。同一张卡在两个屏幕上长得不一样，收银员得自己判断
                    //   是不是同一张（docs/03 §3.1ter）。cardNo 为 null 时原样输出。
                    'card_no'   => $this->fmtCard((string)$member['card_no']),
                    'amount'    => Money::toStr($amt),
                    'points'    => $points,
                    'visits'    => $visits,
                    'tier'      => $tier['code'],
                    'tier_x'    => $tier['multiplier'],
                ];
            }

            $this->orders->applyAllocation($serialId, $sumAmount, $sumPortions);
            /**
             * ★ 定格值比对的基准 —— 就是【此刻】POS 说这一单值多少钱。
             *
             *   必须单独存一份：should_amount / actual_amount 那两列
             *   是主库当前值的镜像，收银员再 locate 一次就被刷掉，
             *   夜间值比对于是拿新值跟新值比，永远判「一致」
             *   （审计 F2，见 OrderRepo::initVerifyBase 的实测数据）。
             *
             *   只写第一次（方法内自带 verify_base_at IS NULL 条件）：
             *   AA 分几次记，基准要停在第一笔那一刻。
             */
            $this->orders->initVerifyBase($serialId);

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

    /**
     * 【记账之前】先问一句：这一单给这位客人，到底会不会计次？
     *
     * ── 为什么必须有这个 ──────────────────────────────
     *
     * `once_per_period` 下同一张卡一个餐期最多 1 次。于是同餐期的第二单
     * 照样记得上（金额、积分都进账），但【计次是 0】。
     * 原来这件事只有在提交完、结果页那行橙字上才说出来 ——
     * 那时账已经记了，服务员没法再回头问客人一句。
     *
     * 现实场景：一桌客人吃完结了账，又加点了甜点酒水另开一单。
     * 服务员照常拿卡去记，客人以为又攒了一次，回头发现没有 —— 投诉就是这么来的。
     *
     * 所以在【选完会员】和【提交】两处提前告知：
     *   · 选完会员 → 那一行上挂一条常驻提示，服务员当场就能告诉客人
     *   · 提交     → 弹一次页内确认（不是系统弹框），让服务员明确「知道了，继续」
     *
     * ── 一定要走同一条代码路径 ────────────────────────
     *
     * 这里复用 visitsFor() / countedThisSitting()，不另写一份判断。
     * 预览和实际入账用两套规则的话，迟早会出现「预览说会计次、结果没计」——
     * 那比不提示还糟：服务员照着预览跟客人打了包票。
     *
     * @return array{counts_visit:bool, reason:?string}|null
     *         null = 订单不存在。reason: already_counted | free_meal | null
     */
    public function visitPreview(int $memberId, string $serialId): ?array
    {
        $order = $this->orders->findBySerial($serialId);
        if ($order === null) {
            return null;
        }

        /**
         * 免费餐 / 整单核销：兑换来的那一餐本来就不计次。
         *
         * ★ 但要和 grantOne 一样先看 free_meal_extra_earns —— 这个开关
         *   决定的不是「计不计次」，而是【这一单能不能记】：
         *     关（出厂默认）→ grantOne 直接整单拒绝（free_meal / redeemed）
         *     开             → 放行，但计次强制为 0
         *
         *   漏看它的后果不是少提示一句，是【说反了】：
         *   预览说「不计次」，言下之意别的照记；实际是一分钱都记不进去。
         *   服务员照着这句话跟客人说完「这一餐不计次，别的照常」，
         *   客人点头，然后提交撞一堵墙 ——
         *   比原来「记完才告诉你没计次」更糟。
         *
         *   关着的时候返回 null（＝没有可说的）：那种单在选单页就被
         *   buildContext 标成 eligible=false、灰掉点不动，本来也轮不到预览。
         */
        $fullyRedeemed = (int)($order['is_redeemed'] ?? 0) === 1
                      && (int)($order['portions_counted'] ?? 0) === 0;
        if ((int)($order['is_free_meal'] ?? 0) === 1 || $fullyRedeemed) {
            return $this->cfg->get('free_meal_extra_earns', '0') === '1'
                ? ['counts_visit' => false, 'reason' => 'free_meal']
                : null;
        }

        /**
         * ★ 只有 once_per_period 能在这个时点给出确定答案。
         *   by_portion 的次数 = 份数、by_order 恒为 1，都要等分配填完才知道，
         *   而预览发生在填之前。那两种口径下一律不提示 ——
         *   宁可不说，也不能说一句还没算数的话。
         */
        if ($this->cfg->get('visit_count_mode', 'once_per_period') !== 'once_per_period') {
            return ['counts_visit' => true, 'reason' => null];
        }

        return $this->countedThisSitting($memberId, (string)$order['order_end_time'])
            ? ['counts_visit' => false, 'reason' => 'already_counted']
            : ['counts_visit' => true, 'reason' => null];
    }

    /**
     * 当前营业日的起点（本地时间）。
     *
     * 手工录入的日限额/日频次都按它切 —— 见 manualAmountSince 的说明。
     * 复用 BusinessDay 而不是另写一遍：那里已经处理了夏令时那天
     * 02:00 不存在的情况，这套系统里也只该有一个「一天从几点开始」。
     */
    private function businessDayStart(): string
    {
        $now = date('Y-m-d H:i:s');
        return $this->bizDay->range($this->bizDay->of($now))[0];
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
            /**
             * ★ 合并用的是【更宽的】那个口径（couldBeSameSitting，见 F6 的说明）。
             *
             *   有一桌落在餐期空档里（比如 19:29 那桌）就放行 ——
             *   按风控那个严格口径拦下来，等于把同行分桌的两桌硬拆开，
             *   而客人正站在柜台前。捡小票那一类由下面的
             *   merge_span_minutes（出厂 60 分钟）挡，那才是承重墙。
             */
            if (!$this->periods->couldBeSameSitting($t, $ref, $this->bizDay)) {
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
     * 卡号 → 显示形态。cardNo 为 null（card_prefix 配错）时原样输出。
     *
     * ★ 存在的理由：库里存的是归一化后的串（TK000002751A2），
     *   而每一个出口都该发分组形态（TK-00000275-1A2）。
     *   漏了一处的后果不是报错，是同一张卡在两个屏幕上长得不一样。
     */
    private function fmtCard(string $cardNo): string
    {
        return $this->cardNo !== null ? $this->cardNo->format($cardNo) : $cardNo;
    }

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
    private function riskWatch(array $memberIds, array $operator): void
    {
        try {
            $maxDay = $this->cfg->int('alert_grants_per_day', 0);
            $maxSpan = $this->cfg->int('alert_span_hours', 0);
            if ($maxDay <= 0 && $maxSpan <= 0) {
                return;
            }
            $bizDate = $this->bizDay->of(date('Y-m-d H:i:s'));
            [$from, $to] = $this->bizDay->range($bizDate);

            foreach (array_unique(array_filter($memberIds)) as $mid) {
                $rows = $this->ledger->earnedInRange((int)$mid, $from, $to);
                if (!$rows) { continue; }

                $ops = [];
                foreach ($rows as $r) {
                    $ops[(string)($r['grant_group'] ?? '') !== '' ? 'g:' . $r['grant_group'] : 's:' . $r['serial_id']] = true;
                }
                $n = count($ops);
                $card = $this->members->findById((int)$mid)['card_no'] ?? ('#' . $mid);

                /**
                 * ★ 去重键要带上【营业日】（与审计 F13 同一类）。
                 *
                 *   这两条说的是「这张卡【今天】怎么了」—— 一天一件事。
                 *   而 raiseOnce 只按 (类型, member) 去重的话：今天这条
                 *   经理没来得及处理，明天同一张卡再犯就【一条都不推】。
                 *   越是天天出问题的那张卡，越是从第二天起彻底静音。
                 */
                if ($maxDay > 0 && $n > $maxDay) {
                    $this->alerts->raiseOnce('grant_many_per_day', 'member', $mid . '@' . $bizDate,
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
                        $this->alerts->raiseOnce('grant_span_wide', 'member', $mid . '@' . $bizDate,
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

            /**
             * ── 🔴 撤销必须写原因（审计 F11） ────────────────────
             *
             * 撤销是【不可逆的减钱动作】：客人的分、次、消费额一起退掉，
             * 靠它挣来的券还会被连带作废（clawBackOverIssued）。
             * 而这条路原来允许空原因 —— 审计日志里那条 reason 就是空串。
             *
             * 后果不是「记录不好看」：客人事后来问「我上周那顿怎么没了」，
             * 店里翻出审计日志，只看得到「某年某月某人撤了」，
             * 说不出为什么。而系统里每一个同量级的动作都是要理由的 ——
             * 作废券要（void）、强制核销要（override）、强制换卡要、
             * 后台手工发券要。唯独撤销不要，这本身就说不通。
             *
             * ★ 放在时间窗判定【之前】：先说「你得填原因」，
             *   再说「超时了要经理」——两条都不满足时，先说得清的那条。
             */
            if (trim($reason) === '') {
                return ['ok' => false, 'error' => 'reason_required'];
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

            /**
             * ── 🔴 先扣掉【已经补偿过】的部分，否则同一次退两遍 ──────
             *
             * 「先记账、后核销」时 clawBackVisitOnRedeem() 已经另插了一条
             * 「免费那一餐不计次」的负数流水（reverses_id 指向这一笔），
             * 而原流水本身不动（账本是追加式的）。
             * 于是 $orig['counted_visit'] 已经不是「现在还欠客人几次」。
             *
             * 实测三步全是柜台日常动作：
             *     ① 服务员先按 AA 记账          会员 0 → 1
             *     ② 客人事后拿出券，核销到这一单 会员 1 → 0（对的）
             *     ③ 经理发现记错卡，撤销那一笔  会员 0 → -1  🔴
             * 客人凭空少一次，下一顿免费餐要多来一趟；
             * 而进度条会写「还差 4 次」——比门槛本身还大。
             *
             * ★ 判据必须与 clawBackVisitOnRedeem 一致：看【还剩多少没退】，
             *   不是【当初记了多少】。那边守住了「一单两张券」，
             *   这边没守住「核销之后再撤销」—— 同一个形状隔了一个函数
             *   （docs/13 §3.1）。
             *
             * ★ 补偿流水也要一并标记成已冲正：留着有效的话，
             *   「有效流水的计次合计」会和会员表对不上。
             */
            $comps = $this->ledger->activeCompensationsOf($ledgerId);
            foreach ($comps as $c) {
                $amt    += Money::toCents((string)$c['amount']);      // 补偿是负数
                $points += (int)$c['points'];
                $visits += (int)$c['counted_visit'];
            }

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
            // 补偿流水随原流水一起退出有效集 —— 它的使命（把那一次退掉）
            // 已经被并进上面这条冲正里了，留着就会被重复计入
            foreach ($comps as $c) {
                $this->ledger->markReversed((int)$c['id'], $revId);
            }

            // 允许负余额并标记，不阻断撤销；下次消费优先抵扣
            $this->members->applyDelta((int)$orig['member_id'], -$points, -$visits, -$amt);

            if ($orig['serial_id'] !== null) {
                $this->orders->applyAllocation((string)$orig['serial_id'], -$amt, -(int)$orig['portions_counted']);
            }

            /**
             * ── 🔴 券也要跟着退，否则两头都错 ──────────────────
             *
             * 服务员拿错卡，把 B 桌的账记到张三名下，张三因此正好满十次、
             * 系统当场发了一张免费餐券。经理十分钟后撤销那笔记账。
             *
             * 原来撤销只退计次/积分/消费额，不碰券，也不碰 rewards_issued：
             *   ① 那张券还在客人手上，而且【能正常核销】—— 一顿饭送出去了；
             *   ② 客人后来自己吃到第十次，一张券都拿不到 ——
             *      pending = earned − issued，issued 虚高 1，永远算出 0，
             *      而进度条上还写着「还差 N 次」，看上去完全正常，
             *      店里也没有任何界面能看见 rewards_issued。
             *
             * ★ 必须放在 applyDelta 之后 —— 收几张是按【退完之后的进度】重算的。
             * ★ 在同一笔事务里：收券和退计次分开就又回到「计次退了、券没退」。
             */
            $claw = $this->rewards->clawBackOverIssued(
                (int)$orig['member_id'], $operator, '记账被撤销（' . $reason . '）');

            /**
             * ★ 已经吃掉的券收不回来 —— 那时 rewards_issued 也不减
             *   （客人确实拿到了那份奖励）。但这是一份【发错的账换来的】免费餐，
             *   经理必须知道，否则这笔损失连账都没地方对。
             */
            if (($claw['unrecoverable'] ?? 0) > 0) {
                $this->alerts->raiseOnce(
                    // ★ 去重键带上流水号：同一位客人被撤第二笔时，
                    //   那顿同样白送的饭不能被当成重复告警吞掉（F13）
                    'reward_on_reversed_grant', 'member', $orig['member_id'] . '#' . $ledgerId,
                    sprintf('撤销了一笔记账，但由它带出的 %d 张免费餐券【已经被核销】，收不回来了。'
                          . '请人工核对这位客人的奖励进度（流水 #%d，原因：%s）',
                        (int)$claw['unrecoverable'], $ledgerId, $reason),
                    ['severity' => 2, 'detail' => ['ledger_id' => $ledgerId,
                                                   'voided' => $claw['codes'] ?? []]]);
            }

            $this->audit->log('point_reverse', [
                'target_type'   => 'ledger',
                'target_id'     => (string)$ledgerId,
                'operator_id'   => $operator['id']   ?? null,
                'operator_name' => $operator['name'] ?? null,
                'device'        => $operator['device'] ?? null,
                'detail'        => ['reversal_id' => $revId, 'reason' => $reason,
                                    'amount' => $orig['amount'], 'points' => $points,
                                    'coupons_voided' => $claw['codes'] ?? [],
                                    'coupons_unrecoverable' => $claw['unrecoverable'] ?? 0],
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
         * ── 🔴 手工录入的【下限】—— 防的是员工这一侧 ──────────
         *
         * 手工录入不需要真实订单，金额是收银员自己填的。原来只校验了
         * 「> 0」，于是连着录很多笔 0.01 欧元就是一条零成本的刷分后门：
         * 日频告警只要控制在阈值以内，账面上完全看不出来。
         *
         * ★ 按次数积分（by_visit）口径下更严重：那时不论金额多少
         *   都按【一次】给分（否则 POS 挂掉时降级路径永远 0 分，见下方说明），
         *   也就是 0.01 欧元一笔和一顿正餐拿到的分一模一样。
         *   实测：三笔 0.01 欧元 → 3 分。
         *   所以这个口径下门槛取「手工录入下限」与「计一次至少多少钱」
         *   两者的较大值 —— 它买到的东西和一次计次等价，门槛就该看齐。
         */
        $minManual = Money::toCents($this->cfg->get('manual_entry_min', '0'));
        if ($this->cfg->get('points_mode', 'by_amount') === 'by_visit') {
            $minManual = max($minManual, Money::toCents($this->cfg->get('min_amount_per_visit', '0')));
        }
        if ($minManual > 0 && $amountCents < $minManual) {
            return ['ok' => false, 'error' => 'below_manual_min',
                    'detail' => ['min' => Money::toStr($minManual),
                                 'given' => Money::toStr($amountCents)]];
        }

        /**
         * ── 🔴 日【累计金额】上限 —— 单笔限额挡不住的那一半 ──────
         *
         * 原来的风控是：单笔上限（超了要经理放行）、单笔硬上限、
         * 以及同一员工单日【笔数】超过 N 笔就告警。三条都管不住这个：
         *
         *   reward_mode = amount、门槛 100.00 时，
         *   经理连录 3 笔 200.00 → 消费额 600.00 → 当场发出【6 张免费餐券】。
         *   单笔没超限、笔数 3 < 告警阈值 5 —— 一声不响，六顿饭。
         *
         * 笔数管不住钱，只有钱能管钱。这一条把最坏情况框住：
         * 一天最多能凭空造出多少额度，是个可以写进规章的数。
         *
         * ★ 它拦的是【累计】，不是单笔 —— 与 manual_entry_limit 是两个维度，
         *   谁也替代不了谁。填 0 = 不设。
         * ★ 超了就是超了，经理也不能放行：能放行的话它就不是上限，
         *   而放行权本身就在最可能滥用的那个人手里。
         */
        $dayCap = Money::toCents($this->cfg->get('manual_entry_daily_cap', '0'));
        /**
         * ★ 互斥行【在事务外】先补齐（见 LedgerRepo::ensureQuotaRow）。
         *   放进事务里的话，两个事务同时 INSERT 同一个主键会各拿一把 S 锁，
         *   随后都要升级成 X —— 又是一个死锁环。
         */
        if ($dayCap > 0) {
            $this->ledger->ensureQuotaRow((int)($operator['id'] ?? 0));
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

        return $this->db->transaction(function () use ($memberId, $amountCents, $reasonCode,
                                                       $operator, $dayCap) {
            /**
             * ── 🔴 日累计上限要在【事务里、拿到锁之后】判（审计 F14） ────
             *
             * 这一段原来在事务外面：读一下 manualAmountSince 判「没超」，
             * 然后才进事务写流水。两台 Pad 同时提交时各自读到「今天用了 0」，
             * 各自放行，各自写进去 —— 上限 € 300 被撑成 € 600。
             * 与 checkAndGrant 那次「四个进程发出四张券」是同一个形状：
             * 「读一下再写」而中间没有锁，等于没上限。
             *
             * 锁的是 manual_entry_lock 里【这个操作员那一行】（额度按操作员算）。
             * 锁会员行不够 —— 同一个人给两位不同客人各录一笔照样并发；
             * 锁 operator 表那一行也不行 —— 那要求那一行确实存在，
             * 而「碰巧存在」不是可以拿来守钱的性质；
             * 锁流水的区间更不行 —— gap 锁彼此不冲突，反而制造死锁。
             *
             * ★ 起点按【营业日】，不是日历零点。
             *   切点 02:00，晚市 19:30 做到凌晨 02:00 —— 一个班次跨零点。
             *   按日历切等于在班次中间把额度清零：实测上限写 € 300，
             *   同一个人同一个班次录进了 € 600。
             *
             * ★ transaction() 会在死锁/锁等待时重放这个闭包，所以这里
             *   不能依赖闭包外算好的中间量 —— 用到的都是入参与现查的值。
             */
            $opIdPre = (int)($operator['id'] ?? 0);
            if ($dayCap > 0 && $opIdPre > 0) {
                // 单行 X 锁：同一个操作员的手工录入在这里排队。
                // 不能改成锁流水的区间 —— gap 锁彼此不冲突，两笔都拿得到，
                // 然后各自 INSERT 进去互等，实测每 160 笔死锁 5–7 次
                // （见 LedgerRepo::lockManualQuota）。
                $this->ledger->lockManualQuota($opIdPre);
                $usedToday = $this->ledger->manualAmountSince(
                    $opIdPre, $this->businessDayStart());
                if ($usedToday + $amountCents > $dayCap) {
                    return ['ok' => false, 'error' => 'exceeds_manual_daily_cap',
                            'detail' => ['cap' => Money::toStr($dayCap),
                                         'used' => Money::toStr($usedToday),
                                         'given' => Money::toStr($amountCents)]];
                }
            }

            $member = $this->members->lockById($memberId);
            if ($member === null) {
                return ['ok' => false, 'error' => 'member_not_found'];
            }

            // 手工录入也套等级倍率 —— 否则同一位客人「系统查不到订单」时
            // 反而少拿分，这种不一致最难跟客人解释
            $tier = $this->tiers->forMember($memberId);
            $mult = $this->cfg->float('points_multiplier', 1.0) * $tier['multiplier'];

            /**
             * ★ by_visit 口径下，手工录入按【一次】算分。
             *
             *   手工录入没有明细，判断不出套餐份数，所以 counted_visit 一直是 0
             *   （见下面那一行的说明）。若照搬「没计次就没分」，
             *   手工录入在这个口径下会永远得 0 分 ——
             *   而它正是 POS 不可达时的降级路径（docs/03 §10），
             *   给 0 分等于那条路废掉。
             *
             *   所以：算分按 1 次算，计次仍然是 0。
             *   两者不一致是有意的 —— 分是「这顿饭该给的」，
             *   次是「我们能证明的」，手工录入证明不了。
             */
            $points = $this->cfg->get('points_mode', 'by_amount') === 'by_visit'
                ? PE::pointsForVisit(1, $this->cfg->float('points_per_visit', 1.0), $mult)
                : PE::pointsFor($amountCents, $this->cfg->float('points_per_euro', 1.0), $mult);

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
            $cnt  = $this->ledger->manualCountSince($opId, $this->businessDayStart());
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
