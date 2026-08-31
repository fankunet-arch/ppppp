<?php
declare(strict_types=1);

namespace Vip;

/**
 * 积分计算核心 —— 全部为纯函数，不碰数据库，便于测试。
 *
 * 这里集中了对 POS 数据的全部实测结论，每条都在 docs/ 中有数据支撑。
 * 修改任何一条前，请先读对应的文档小节。
 */
final class PointsEngine
{
    // ── POS 明细中的伪行标记（docs/01 §3.1）──────────────
    public const PSEUDO_DISCOUNT = -2;  // 折扣行，actual_price 恒为负；十送一核销也走这里
    public const PSEUDO_REMARK  = -3;   // 操作留痕行 '**999 Enviado 19:16**'
    public const PSEUDO_PAYMENT = -4;   // 支付行 'EFECTIVO'，actual_price 是收款额含找零

    /**
     * 「十送一核销」折扣行的名称模式（子串匹配，忽略大小写）。
     *
     * 实测两批样本里 `-2` 折扣行共出现 5 种名称：
     *
     *   2026-07-10 ~ 08-17（209 行）  CUPON DE 5 EUROS 164、TARJETA 10+1 34、
     *                                 Dto% 10、Dto. -15% 1
     *   更早的 63 行样本              Dto. -20% 54、CUPON DE 5 EUROS 4、
     *                                 Dto% 4、TARJETA 10+1 1
     *
     * 只有 TARJETA 10+1 是十送一核销，其余全是普通折扣，【绝不能误判】——
     * 误判会让正常付费的客人不计次不积分。其中 CUPON DE 5 EUROS 是
     * 「满 50 减 5」纸质券（在 POS 上直接核销，Pad 不参与），
     * 出现频率是十送一的 5 倍，误判的代价天天在发生。
     *
     * ★ 注意名称【会变】：Dto. -20% 在新样本里已经消失，换成了 Dto. -15%。
     *   所以既不能按「有折扣就算核销」判，也不能把名单写死在代码里 ——
     *   店家改了 POS 里的名称时，在后台 sys_config 的 redeem_line_patterns
     *   改这份清单即可，无需改代码。
     */
    public const REDEEM_PATTERNS = ['TARJETA 10+1', '10+1'];

    // ── 记账方式 ──────────────────────────────────────────
    public const MODE_WHOLE  = 1;   // 整单记给一人
    public const MODE_SPLIT  = 2;   // 均摊 AA
    public const MODE_PICK   = 3;   // 点选菜品

    /**
     * 把订单头候选行按 order_head_id 聚合。
     *
     * 实测：按 check 行直接返回，30 分钟窗口歧义率 10.95%；
     *       按 order_head_id 聚合后降至 0.02%。
     *       歧义几乎全部来自同一订单的多张 check（8,873 次 vs 翻台 14 次）。
     * docs/03 §1.2
     *
     * @param array<int,array> $rows history_order_head 的行
     * @return array<string,array> 以 order_head_id 为键的聚合结果
     */
    public static function aggregateCandidates(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $ohid = (string)$r['order_head_id'];
            if (!isset($out[$ohid])) {
                $out[$ohid] = [
                    'order_head_id'   => (int)$r['order_head_id'],
                    'serial_id'       => (string)$r['serial_id'],
                    'table_name'      => $r['table_name'] ?? null,
                    'eat_type'        => (int)$r['eat_type'],
                    'customer_num'    => isset($r['customer_num']) ? (int)$r['customer_num'] : null,
                    'check_ids'       => [],
                    'original_cents'  => 0,
                    'should_cents'    => 0,
                    'actual_cents'    => 0,
                    'tax_cents'       => 0,
                    'order_end_time'  => (string)$r['order_end_time'],
                ];
            }
            $a = &$out[$ohid];
            $a['check_ids'][]     = (int)$r['check_id'];
            $a['original_cents'] += Money::toCents($r['original_amount'] ?? '0');
            $a['should_cents']   += Money::toCents($r['should_amount']   ?? '0');
            $a['actual_cents']   += Money::toCents($r['actual_amount']   ?? '0');
            $a['tax_cents']      += Money::toCents($r['tax_amount']      ?? '0');
            // 多张 check 取最晚的结账时间
            if ((string)$r['order_end_time'] > $a['order_end_time']) {
                $a['order_end_time'] = (string)$r['order_end_time'];
            }
            // ★ serial_id 恒取【最小值】，不能用「第一行」。
            // 实测 88,616 行中有 1 单（order_head_id=25379）的两张 check
            // 各自带了一个 serial（2409020040 / 2409020041）。
            // 而两条读取路径的排序方向相反 ——
            //   Pad   findRecentByTable  ORDER BY order_end_time DESC
            //   Cron  fetchSince         ORDER BY order_end_time ASC
            // 取「第一行」会让两边各存一个幂等键，pos_order 里查不到对方，
            // 同一单就会被发两次分。取最小值则两条路径必然一致。
            // serial_id 是 int 列，按数值比而非字符串比（宽度并不恒定，实测有 0）。
            if (self::serialLess((string)$r['serial_id'], $a['serial_id'])) {
                $a['serial_id'] = (string)$r['serial_id'];
            }
            unset($a);
        }
        foreach ($out as &$a) {
            sort($a['check_ids']);
            $a['serial_id'] = self::idempotencyKey($a['serial_id'], $a['order_head_id']);
        }
        unset($a);
        return $out;
    }

    /** serial_id 数值比较。两边都是纯数字时按数值比，否则退回字符串比。 */
    private static function serialLess(string $candidate, string $current): bool
    {
        if (ctype_digit($candidate) && ctype_digit($current)) {
            // 用字符串补齐再比，避免超出 PHP int 范围时的精度问题
            $len = max(strlen($candidate), strlen($current));
            return strcmp(str_pad($candidate, $len, '0', STR_PAD_LEFT),
                          str_pad($current,   $len, '0', STR_PAD_LEFT)) < 0;
        }
        return strcmp($candidate, $current) < 0;
    }

    /**
     * 幂等键：正常情况就是 serial_id 本身。
     *
     * ★ 但 POS 会吐出 serial_id = 0 的单 —— 实测 order_head_id=70762
     *   （2025-12-29，桌 15，两张 check）两行 serial 都是 0。
     *   此处只有一单尚不冲突，可一旦另一天再出一单 serial=0，
     *   幂等键 (store_code, 0) 就会撞上，后一单会被静默并进前一单 ——
     *   订单不见了、积分也没发，且不报错。
     *   故退化时改用 order_head_id 造键：它在 88,616 行里始终有值，
     *   且 (order_head_id, check_id) 唯一。加 H 前缀，与纯数字的真实
     *   serial 天然不会相撞。
     */
    public static function idempotencyKey(string $serialId, int $orderHeadId): string
    {
        $s = trim($serialId);
        return ($s === '' || $s === '0' || (ctype_digit($s) && (int)$s === 0))
            ? 'H' . $orderHeadId
            : $s;
    }

    /**
     * 一行明细是否为「有效菜品行」。
     *
     * 必须排除（docs/01 §3.1）：
     *   menu_item_id <= 0        伪行：-3 备注、-4 支付（后者 actual_price 是收款额含找零，混入会虚高）
     *   condiment_belong_item≠0  配料/做法行
     *   is_return_item = 1       已退菜行（actual_price 退菜后不清零，仍保留原价）
     */
    public static function isValidItemRow(array $row): bool
    {
        if ((int)$row['menu_item_id'] <= 0) {
            return false;
        }
        if ((int)($row['condiment_belong_item'] ?? 0) !== 0) {
            return false;
        }
        if ((int)($row['is_return_item'] ?? 0) === 1) {
            return false;
        }
        return true;
    }

    /**
     * 该行是否应在 Pad 的「点选菜品」界面显示。
     *
     * 两种 0 元必须区分（docs/01 §3.2）：
     *   套餐内本来免费的菜  → 三个价格字段全为 0/NULL → 隐藏
     *   被免掉的收费项      → product_price 或 original_price > 0 → 【显示】
     *
     * 后者必须显示，否则 10送1 那次享用免费餐的客人在列表里无项可认领。
     */
    public static function isDisplayableRow(array $row): bool
    {
        return Money::toCents($row['actual_price']   ?? '0') > 0
            || Money::toCents($row['product_price']  ?? '0') > 0
            || Money::toCents($row['original_price'] ?? '0') > 0;
    }

    /**
     * 分析订单明细，产出计次份数、扣除金额、餐费项统计与展示列表。
     *
     * ★ 行金额 = actual_price，【不乘 quantity】。
     *   实测 339 行 quantity>1 的明细（2024-01 的 242 行 + 2026-08 的 97 行），
     *   100% 满足 actual_price = product_price × quantity —— actual_price
     *   已经是行小计。再乘一次会让金额平方级放大。docs/01 §3.3
     *
     * ★ 计次份数 = SUM(quantity)，【不是 COUNT(*)】。
     *   收银员会把多份相同套餐录成一行（实测有 quantity 为 2/3/4/5 的行）。
     *
     * @param array<int,array> $detailRows history_order_detail 的行
     */
    public static function analyzeDetail(array $detailRows, MealRules $rules, array $redeemPatterns = self::REDEEM_PATTERNS): array
    {
        $lineTotalCents    = 0;   // 全部有效行金额合计（应等于 original_amount）
        $excludedCents     = 0;   // earns_points=0 的项
        $mealFeeCents      = 0;   // is_meal_fee=1 的项
        $hasMealFeeItem    = false;
        $portionsCounted   = 0;   // counts_visit=1 的 SUM(quantity)
        $portionsUncounted = 0;   // counts_visit=0 但属餐费项的份数
        // ★ 计次份数再拆成「付费」与「免费」两档，供 Pad 直接显示，
        //   免得收银员对着一个总数猜里面几个是免单的。
        //   判据是【行合计】actual_price 是否为 0 —— 实测真库里
        //   actual_price 存的是行合计而非单价（如 17.90 × 2 份 = 35.80），
        //   所以整行免单就是 0，与份数多少无关。
        $portionsPaid      = 0;
        $portionsFree      = 0;
        // 出现在本单、但套餐规则表里没有的菜品：份数无法判定，要如实告诉前台
        $unknownItems      = [];
        $waivedCents       = 0;   // 「被免金额」：原价 - 实收
        $display           = [];
        $countedUnitPrices = [];  // 计次套餐出现过的单价（分），用于反推核销份数
        $redeemCents       = 0;   // 十送一核销折扣额（正数）
        $redeemLines       = [];  // 命中的核销行名称

        foreach ($detailRows as $row) {
            // ── 折扣伪行（menu_item_id = -2）────────────────────
            // 不参与任何金额累加（isValidItemRow 已排除），但要在这里
            // 认出「十送一核销」。见 self::REDEEM_PATTERNS 的说明。
            if ((int)$row['menu_item_id'] === self::PSEUDO_DISCOUNT) {
                $name = (string)($row['menu_item_name'] ?? '');
                if (self::isRedeemLine($name, $redeemPatterns)) {
                    $redeemCents  += abs(Money::toCents($row['actual_price'] ?? '0'));
                    $redeemLines[] = $name;
                }
                continue;
            }
            if (!self::isValidItemRow($row)) {
                continue;
            }
            $itemId  = (int)$row['menu_item_id'];
            $qty     = (int)round((float)($row['quantity'] ?? 0));
            $lineC   = Money::toCents($row['actual_price'] ?? '0');
            $prodC   = Money::toCents($row['product_price'] ?? '0');
            $origC   = Money::toCents($row['original_price'] ?? '0');

            $lineTotalCents += $lineC;

            if (!$rules->earnsPoints($itemId)) {
                $excludedCents += $lineC;
            }
            if ($rules->isMealFee($itemId)) {
                $hasMealFeeItem = true;
                $mealFeeCents  += $lineC;
                if (!$rules->countsVisit($itemId)) {
                    $portionsUncounted += $qty;
                }
            }
            if ($rules->countsVisit($itemId)) {
                $portionsCounted += $qty;
                if ($lineC > 0) {
                    $portionsPaid += $qty;
                } else {
                    $portionsFree += $qty;
                }
                // 记下计次套餐的单价，用来反推核销抵掉了几份（见下方 portions_redeemed）
                if ($prodC > 0) {
                    $countedUnitPrices[$prodC] = true;
                }
            } elseif (!$rules->isKnown($itemId) && $lineC > 0) {
                /**
                 * 规则表没收录 → countsVisit 回落成 false（安全默认，宁可少算）。
                 * 「少算」和「本来就不该算」在界面上都是 0，收银员没法判断
                 * 该不该手工补，所以要把菜品名带出去让前台明说。
                 *
                 * ★ 但只报【行合计 > 0】的。实测一张自助单里有 17~25 个 0 元
                 *   单品（自助内含的寿司、天妇罗……），它们本来就不该计次，
                 *   全列出来会让提示变成一屏噪音，真正该处理的漏配反而被淹掉。
                 *   0 元行也不可能是漏配的付费套餐，漏报没有代价。
                 */
                $unknownItems[$itemId] = (string)($row['menu_item_name'] ?? ('#' . $itemId));
            }

            // 被免金额：原价（优先 original_price，回落 product_price×qty）与实收之差
            $refC = $origC > 0 ? $origC : $prodC * max($qty, 1);
            if ($refC > $lineC) {
                $waivedCents += ($refC - $lineC);
            }

            if (self::isDisplayableRow($row)) {
                $waived = $lineC === 0 && $refC > 0;
                // ★ 同一菜品的多行要合并显示。
                // POS 把「2 瓶水」存成两行各 2.95，而小票印的是「2 Agua 5.90」
                // （实测订单 92518）。服务员是照着手里的小票核对的，
                // 不合并就会出现小票 3 行、Pad 4 行，看起来像少收/多收。
                // 只在单价与三个开关都相同时合并，避免把「被免的」和
                // 「照价收的」同名菜混成一行。
                $key = $itemId . '|' . $prodC . '|' . ($waived ? 1 : 0);
                if (isset($display[$key])) {
                    $display[$key]['quantity']   += $qty;
                    $display[$key]['line_cents'] += $lineC;
                } else {
                    $display[$key] = [
                        'menu_item_id'   => $itemId,
                        'name'           => (string)($row['menu_item_name'] ?? ''),
                        'quantity'       => $qty,
                        'line_cents'     => $lineC,
                        'unit_cents'     => $prodC,
                        'is_waived'      => $waived,
                        'counts_visit'   => $rules->countsVisit($itemId),
                        'earns_points'   => $rules->earnsPoints($itemId),
                        'is_meal_fee'    => $rules->isMealFee($itemId),
                    ];
                }
            }
        }

        return [
            'line_total_cents'    => $lineTotalCents,
            'excluded_cents'      => $excludedCents,
            'meal_fee_cents'      => $mealFeeCents,
            'has_meal_fee_item'   => $hasMealFeeItem,
            'portions_counted'    => $portionsCounted,
            'portions_uncounted'  => $portionsUncounted,
            'portions_paid'       => $portionsPaid,
            'portions_free'       => $portionsFree,
            'unknown_items'       => array_values($unknownItems),
            'waived_cents'        => $waivedCents,
            // 合并用的键只是内部产物，对外仍是顺序数组
            'display'             => array_values($display),
            'redeem_cents'        => $redeemCents,
            /**
             * ★ 核销抵掉了几份套餐 —— 用来把「混合单」算对。
             *
             * 店家口径（已确认）：4 人同桌、1 人用十送一券免单、其余 3 人正常付费
             * （哪怕他们用了满50减5 的纸质券），这一单就算【3 份】：
             * 可以 AA 给 3 个人，也可以整单记给一人算 3 次。
             *
             * 实测 5 张核销单，核销额 ÷ 套餐单价【全部是精确整数】：
             *   92089 23.9÷23.9=1（共2份）  92147 18.9÷18.9=1（共4份）
             *   92101 37.8÷18.9=2（共2份）  92223 47.8÷23.9=2（共2份）
             *   92600 95.6÷23.9=4（共4份）
             *
             * 只在【计次套餐单价唯一且能整除】时才反推 —— 一单混点多种价位的
             * 套餐时无法判断券抵的是哪一种，那种情况回落成 null，
             * 由上层按保守口径处理（整单不计次），宁可少给也不能多给。
             */
            'portions_redeemed'   => self::redeemedPortions($redeemCents, array_keys($countedUnitPrices)),
            'redeem_lines'        => $redeemLines,
            'is_redeemed'         => $redeemCents > 0,
        ];
    }

    /**
     * 该折扣行是否为「十送一核销」。
     *
     * 名称由店家在 POS 里自定义，故用【可配置的模式】而非硬编码全名。
     * 大小写与两侧空白都不敏感。
     */
    /**
     * 核销额抵掉了几份计次套餐。
     *
     * ★ 不能假设「核销额恒为本单套餐单价的整数倍」。
     *   39 天样本里 25 张核销单，只有 21 张能整除：
     *     · 90216  9 份全是 25.90，核销 47.80 = 23.90 × 2
     *     · 91095  5 份全是 23.90，核销 25.90
     *   —— 券是按【当初攒够时的价位】计的，与本次用餐价位可以不同
     *   （平日 23.90 / 周末 25.90 / 午市 18.90 …）。
     *     · 91863  核销 11.00，任何套餐价都除不尽。
     *
     * 所以分两级：
     *   ① 能被本单某一个单价整除，且只有一个单价能整除 → 就用它（高置信）
     *   ② 否则按【本单最低单价】向上取整 —— 把免费份数往多了算，
     *      也就是把计次往少了给。宁可少给也不能多给：多给等于白送一顿饭。
     *
     * 向上取整为什么安全：真实免费份数 = 核销额 ÷ 券的价位。
     * 券价位低于本单价位时，ceil 得到的份数 ≥ 真实份数；
     * 券价位高于本单价位时更是如此。两个方向都不会少算免费份数。
     *
     * @param int   $redeemCents 核销折扣额（正数，分）
     * @param int[] $unitPrices  本单计次套餐出现过的单价（分）
     * @return int|null 份数；完全无从判断时返回 null（上层按整单不计次处理）
     */
    public static function redeemedPortions(int $redeemCents, array $unitPrices): ?int
    {
        if ($redeemCents <= 0) {
            return 0;
        }
        $units = array_values(array_filter(array_map('intval', $unitPrices), fn(int $u): bool => $u > 0));
        if (!$units) {
            return null;   // 本单没有任何带价的计次套餐，无从判断
        }

        // ① 精确整除：只有一个单价能整除时才采信，多个都能整除说明有歧义
        $exact = [];
        foreach ($units as $u) {
            if ($redeemCents % $u === 0) {
                $exact[] = intdiv($redeemCents, $u);
            }
        }
        if (count(array_unique($exact)) === 1) {
            return $exact[0];
        }

        // ② 保守回落：按最低单价向上取整，免费份数只多不少
        return (int)ceil($redeemCents / min($units));
    }

    public static function isRedeemLine(string $name, array $patterns = self::REDEEM_PATTERNS): bool
    {
        /**
         * ★ 用 stripos 而不是 mb_strtoupper + str_contains。
         *
         *   核销模式全是 ASCII（TARJETA 10+1），不需要多字节大小写转换；
         *   UTF-8 的多字节序列里也绝不会出现 ASCII 字节，所以按字节找子串
         *   不会误命中。
         *
         *   更要紧的是【不能依赖 mbstring】：现场 Windows 上没开这个扩展，
         *   于是每张带折扣行的单一查就抛
         *   「Call to undefined function Vip\mb_strtoupper()」——
         *   界面只显示「系统内部错误」。积分算得对不对，不该取决于
         *   某个可选扩展装没装。
         */
        $n = trim($name);
        foreach ($patterns as $p) {
            $p = trim((string)$p);
            if ($p !== '' && stripos($n, $p) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * 计算订单的可积分基数（整数分）。
     *
     *   基数 = LEAST(should_amount, actual_amount) × (1 - 排除金额 / original_amount)
     *
     * ★ 为什么取 LEAST（docs/01 §2.2）：
     *   actual_amount 是【收款额，含待找零】，不是净实付。
     *   实测 172 行 actual > should，差额呈 +5.00/+10.00/+15.00 及整钞
     *   40/130/135 的模式；actual < should 全表仅 1 行。
     *   直接用 actual_amount 会把找零算成消费额（最坏一笔多给一倍）。
     *
     * ★ 为什么按比例扣而不是直接相减（docs/01 §3.3.1）：
     *   明细金额合计 == original_amount（折扣前），实测 210/211 = 99.5%。
     *   订单级折扣只记在 discount_amount，不体现在明细行上。
     *   直接相减会把折扣算错，按比例扣可自动吸收。
     *
     * ★ 分母用 original_amount 而不是明细合计：
     *   两者恒等，取订单头字段更稳健（明细偶发不全时不失真）。
     */
    public static function pointsBaseCents(
        int $shouldCents,
        int $actualCents,
        int $originalCents,
        int $excludedCents,
        int $taxCents = 0
    ): int {
        $base = min($shouldCents, $actualCents);
        /**
         * 按不含税价积分时先把税额扣掉（points_include_tax=0）。
         * 用 POS 给的真实 tax_amount，不硬编码税率 ——
         * 实测该字段与实物小票的 SubTotal 完全吻合（docs/01 §2.11）。
         *
         * ★ 这里原来写的是
         *     $base - Money::scale($taxCents, $base, min($should, $actual))
         *   而此刻 $base 恰好【就等于】那个分母，所以 scale() 是恒等的 ——
         *   读起来像「按比例折算税额」，实际等价于直接减。
         *   一个永远不生效的比例换算比没有更糟：它会让人以为这里
         *   已经处理过某种比例问题了。
         *
         * ★ 顺带说明它没处理的那个边界：actual < should 时（docs/01 §2.2
         *   实测全表仅 1 行），基数取较小的 actual，而 tax 是整单的，
         *   于是「整单的税从半单里扣」。金额小、出现率近乎为零，
         *   真要处理得先弄清 POS 在这种单上把税算给了谁 —— 不猜。
         */
        if ($taxCents > 0 && $base > 0) {
            $base = max(0, $base - $taxCents);
        }
        if ($base <= 0) {
            return 0;
        }
        if ($excludedCents <= 0) {
            return $base;
        }
        if ($originalCents <= 0) {
            // 没有可靠分母时保守处理：直接扣，且不小于 0
            return max(0, $base - $excludedCents);
        }
        if ($excludedCents >= $originalCents) {
            return 0;   // 整单都是不计分项（如堂食单全是 BOX）
        }
        $keep = $originalCents - $excludedCents;
        return Money::scale($base, $keep, $originalCents);
    }

    /**
     * 「分/€」「分/次」的小数位数 —— sys_config 校验为 `^\d+(\.\d{1,2})?$`，最多两位。
     */
    private const RATE_SCALE = 100;

    /**
     * 倍率的小数位数。倍率 = 全局 × 卡片等级，两者各自最多两位
     * （sys_config 同上；`card_tier.points_multiplier` 是 DECIMAL(4,2)），
     * 相乘最多四位。取六位是留余量：以后再叠一层两位小数的倍率也还是精确的。
     */
    private const MULT_SCALE = 1000000;

    /**
     * 定点乘除：floor($units × $rate × $mult ÷ $unitDiv)，全程走整数。
     *
     * ── 🔴 为什么不能直接 floor(a × b × c) ──────────────
     *
     * 三个 IEEE754 双精度相乘，当精确结果本该是整数时，浮点表示会给出
     * 比它略小的值，floor 就少一分。**丢的不是小数部分，是整数本身**：
     *
     *     100.00 € × 0.29 × 1.00   精确值 29，(int)floor(...) 给 28
     *     12 次   × 0.70 × 2.50    精确值 21，(int)floor(...) 给 20
     *
     * 「向下取整而非四舍五入，宁可少给」是一条关于**小数部分**的策略，
     * 那条策略是对的；这里修的是表示误差，两回事。
     * `points_per_euro = 0.29`（想设「3.45 € 换 1 分」时算出来就是它）
     * 配上出厂倍率 1.00 就会命中 —— 不需要任何刁钻配置。
     *
     * 方向始终对商家有利、每次只差一分，客人发现不了 —— 所以只能靠代码堵。
     * `Money` 开头那句「金额一律以整数分在内部流转，避免浮点误差」，
     * 这两个函数曾经是仓库里仅剩的两处例外。
     */
    private static function fixedFloor(int $units, int $unitDiv, float $rate, float $mult): int
    {
        if ($units <= 0) {
            return 0;
        }
        // round 而非 (int) 强转：0.29 * 100 在双精度里是 28.999999999999996
        $r = (int)round($rate * self::RATE_SCALE);
        $m = (int)round($mult * self::MULT_SCALE);
        if ($r <= 0 || $m <= 0) {
            return 0;
        }

        /**
         * 溢出兜底。正常配置下永远走不到 ——
         * 一单几百欧（$units ≤ 1e5 分）配上个位数的倍率，乘积在 1e13 量级，
         * 距 PHP_INT_MAX（约 9.2e18）还有五个数量级。
         * 真要有人把「每欧元积几分」填到几百万，那时少一分也已经无所谓了，
         * 回落到浮点，好过静默溢出成负数。
         */
        if ((float)$units * $r * $m > (float)PHP_INT_MAX) {
            return (int)floor($units / $unitDiv * $rate * $mult);
        }

        return intdiv($units * $r * $m, $unitDiv * self::RATE_SCALE * self::MULT_SCALE);
    }

    /**
     * 积分数 = 基数(欧元) × 每欧元积分 × 倍率，向下取整。
     * 向下取整而非四舍五入：宁可少给，避免累积超发。
     */
    public static function pointsFor(int $baseCents, float $perEuro, float $multiplier): int
    {
        return self::fixedFloor($baseCents, 100, $perEuro, $multiplier);
    }

    /**
     * 按「来一次积几分」算积分（points_mode = by_visit）。
     *
     * ── 两种积分口径 ────────────────────────────────────
     *
     * | points_mode | 积分 = |
     * |---|---|
     * | `by_amount`（默认） | 金额 × 每欧元分数 × 倍率 —— 花得多攒得快 |
     * | `by_visit`          | 计次数 × 每次分数 × 倍率 —— 来得勤攒得快 |
     *
     * 后者是店家可选的另一种玩法：客人看到的不再是「87 分」这种
     * 跟消费额挂钩、需要换算的数字，而是「我来了 3 次」——
     * 与十送一那张卡上的格子是同一件事，不用解释。
     *
     * ★ 没计上次就没有分。这是 by_visit 的定义（「一次积一分」），
     *   不是漏算：同一餐期第二单不计次，那一单在这个口径下也不积分。
     *   Pad 上必须把这句话说出来 —— 客人会问「我这单怎么一分没有」。
     *
     * ★ 倍率照旧叠加（全局 × 等级）。金卡 2 倍在这个口径下就是
     *   「来一次积 2 分」，与 by_amount 下「同样金额拿双倍」是同一个心智模型。
     */
    public static function pointsForVisit(int $visits, float $perVisit, float $multiplier): int
    {
        // $unitDiv = 1：次数本身就是整数，不像金额那样要先从「分」还原成「欧元」
        return self::fixedFloor($visits, 1, $perVisit, $multiplier);
    }

    /**
     * 订单是否可积分（硬性过滤，docs/03 §2.2）。
     *
     * @return array{ok:bool,reason:string}
     */
    public static function checkEligible(int $eatType, int $shouldCents, int $actualCents, bool $isFreeMeal): array
    {
        // 白名单：只认 eat_type=0。库中还有 eat_type=1(3单)、2(4单) 来路不明的记录，
        // 写成 != 3 会把它们放进来。
        if ($eatType !== 0) {
            return ['ok' => false, 'reason' => 'not_dine_in'];
        }
        if (min($shouldCents, $actualCents) <= 0) {
            // actual=0 的订单实测 1,666 单（1.88%，按月均匀分布），
            // 是员工餐/招待/作废单 —— 既不积分也不计次。
            return ['ok' => false, 'reason' => 'zero_amount'];
        }
        if ($isFreeMeal) {
            return ['ok' => false, 'reason' => 'free_meal'];
        }
        return ['ok' => true, 'reason' => ''];
    }

    /**
     * 免费餐兜底判据（docs/03 §5.2 第二层）。
     *
     * ★ 前置条件「订单内存在餐费项」不可省略。
     *   若只判断「餐费项合计 == 0」，则根本没点套餐的订单会被全部误报
     *   —— 例如堂食客人点一份 COMBO L 配酒水，餐费项合计天然为 0。
     *   免费餐的真实特征是【点了套餐但被免】，不是【没点套餐】。
     */
    public static function suspectFreeMeal(array $detailAnalysis, int $pointsBaseCents, bool $markedFreeMeal): bool
    {
        if ($markedFreeMeal) {
            return false;
        }
        return $detailAnalysis['has_meal_fee_item']
            && $detailAnalysis['meal_fee_cents'] === 0
            && $pointsBaseCents > 0;
    }

    /**
     * 后台配的核销行名称 → 模式数组。留空回落到内置默认。
     *
     * ★ 放在这里而不是某个 Service 里，是因为【两条路径都要用】：
     *   记账（PointsService::buildContext）与值比对（ReconcileService::verifyOne）。
     *   原来只有记账那边有，值比对那边直接用了硬编码默认值 ——
     *   于是店家改了 POS 里的核销行名称之后，Pad 认得出、夜间校准认不出。
     *   抽成一个共用的纯函数，就没有「改了一处忘了另一处」这回事。
     *
     * @return array<int,string>
     */
    public static function redeemPatternsFrom(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return self::REDEEM_PATTERNS;
        }
        $out = array_values(array_filter(
            array_map('trim', explode(',', $raw)),
            static fn(string $s): bool => $s !== ''
        ));
        return $out ?: self::REDEEM_PATTERNS;
    }

    /**
     * 分配校验 —— 金额守恒的判定逻辑，抽成纯函数便于测试。
     *
     * 恒定成立：SUM(已分配 + 本次) ≤ total_amount
     * 不信任客户端传来的金额，一律以本地镜像的 total_amount 为准。
     *
     * 另一条恒定成立的：任何一笔分配，份数 > 0 ⇒ 金额 > 0。
     * 见下方 portions_without_amount 处的说明。
     *
     * @param array $allocations [['member_id'=>int,'amount_cents'=>int,'portions'=>int], ...]
     * @return array{ok:bool,error:string,sum_amount:int,sum_portions:int}
     */
    public static function validateAllocations(
        array $allocations,
        int $totalCents,
        int $allocatedCents,
        int $totalPortions,
        int $allocatedPortions
    ): array {
        $fail = static fn(string $e, int $a = 0, int $p = 0) =>
            ['ok' => false, 'error' => $e, 'sum_amount' => $a, 'sum_portions' => $p];

        if (!$allocations) {
            return $fail('empty_allocation');
        }
        if ($totalCents <= 0) {
            return $fail('zero_amount');
        }

        $sumAmount = 0;
        $sumPort   = 0;
        $seen      = [];
        foreach ($allocations as $a) {
            $mid = (int)($a['member_id'] ?? 0);
            $amt = (int)($a['amount_cents'] ?? 0);
            $prt = (int)($a['portions'] ?? 0);

            if ($mid <= 0) {
                return $fail('invalid_member');
            }
            if (isset($seen[$mid])) {
                // 同一会员在一次提交里出现两次 → 多半是前端重复提交
                return $fail('duplicate_member');
            }
            $seen[$mid] = true;

            if ($amt < 0 || $prt < 0) {
                return $fail('negative_allocation');
            }

            /**
             * ★ 份数与金额【绑在一起】：要计次就得有钱。
             *
             *   守恒校验只管金额上限，管不住「0 元也要一份」。
             *   于是有这么一个洞：一单 71.70 三份，A 先把 71.70 全拿走
             *   （积分 71、计次 1），B 再提交「金额 0、份数 1」——
             *   金额没超，份数没超，通过；B 白拿一次。
             *   第三个人照样还能再来一次，直到份数用完。
             *
             *   现实里这是最容易被利用的一步：金额是死的、分完就没了，
             *   而次数才是奖励的真正来源（十送一）。
             *   所以规则改成：一笔分配要么【钱和次一起计】，要么整笔拒绝。
             *
             *   反过来【有钱没份】是允许的，那是正常生意 ——
             *   只点酒水没点套餐，该积分不该计次。绑定只有这一个方向。
             *
             *   splitEvenly 也守着同一条规则（份数只摊给分到钱的人），
             *   所以 AA 正常拆分不会撞上这一条 —— 见该方法的说明。
             */
            if ($prt > 0 && $amt === 0) {
                return $fail('portions_without_amount');
            }

            $sumAmount += $amt;
            $sumPort   += $prt;
        }

        if ($sumAmount === 0 && $sumPort === 0) {
            return $fail('empty_allocation');
        }
        if ($allocatedCents + $sumAmount > $totalCents) {
            return $fail('exceeds_total', $sumAmount, $sumPort);
        }
        if ($allocatedPortions + $sumPort > $totalPortions) {
            return $fail('exceeds_portions', $sumAmount, $sumPort);
        }

        return ['ok' => true, 'error' => '', 'sum_amount' => $sumAmount, 'sum_portions' => $sumPort];
    }

    /**
     * 均摊 AA：把金额与份数分给 n 人。分毫不差、份数不丢。
     *
     * ── 金额与份数的余数处理【不一样】，这是有意的 ───────
     *
     * 金额余数（最多 n−1 分）全给第一位 —— 差几分钱没有意义。
     *
     * 份数余数【一人一份地摊开】，不能堆给第一位。
     * 因为 once_per_period 口径下，份数已经不是「几份」而是
     * 「这个人有没有吃计次套餐」这个是非题：
     *
     *   3 份 4 人，堆给第一位 → [3, 0, 0, 0]
     *     第一位记 1 次，后三位【付了 € 17.92 却一次都没有】
     *     —— 旧口径 by_portion 下第一位记 3 次，总数还守得住；
     *        换成 once_per_period 之后凭空少掉 2 次，而且不报错。
     *
     *   摊开 → [1, 1, 1, 0]
     *     三位各记 1 次，第四位确实没点计次套餐 —— 这才是真实形状。
     *
     * ★ 所以这里【不能】跟金额那条「余数给第一位」的规则保持一致。
     *   改这一段前先读 docs/03 §3.2 与 §13。
     *
     * ── 🔴 份数只摊给【分到钱的人】 ─────────────────────
     *
     * 两条余数规则不一样，就带出了一个组合：当
     * `intdiv(金额, 人数) == 0` 时，排在后面的人会拿到「0 元 + 1 份」。
     *
     *     splitEvenly(2 分, 3 份, 5 人) → 2/1  0/1  0/1  0/0  0/0
     *
     * 这正是 validateAllocations 硬拒的 portions_without_amount ——
     * 也就是说这个函数会造出一组【交回去会被自己拒掉】的分配。
     * （早先这里的注释还写着「splitEvenly 不会造出这种分配」，是错的。）
     *
     * 现实中要求「剩余金额的分数 < 人数」（如剩 0.03 € 分给 5 人），
     * 几乎不可能发生，所以这不是金额风险；但一个自相矛盾的纯函数
     * 迟早会被别处拿去用。所以在这里就把它掐掉：
     * 没分到钱的人不给份数，份数只在【有钱的人】之间摊。
     *
     * @return array<int,array{amount_cents:int,portions:int}>
     */
    public static function splitEvenly(int $amountCents, int $portions, int $n): array
    {
        $amts = Money::splitEvenly($amountCents, $n);

        // 只有分到钱的人才参与份数分摊 —— 没钱就没次，与 validateAllocations 同一条规则
        $paying = [];
        for ($i = 0; $i < $n; $i++) {
            if ($amts[$i] > 0) {
                $paying[] = $i;
            }
        }
        $give = array_fill(0, $n, 0);
        $k = count($paying);
        if ($k > 0 && $portions > 0) {
            $base = intdiv($portions, $k);
            $rem  = $portions - $base * $k;
            foreach ($paying as $j => $idx) {
                $give[$idx] = $base + ($j < $rem ? 1 : 0);
            }
        }

        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[] = ['amount_cents' => $amts[$i], 'portions' => $give[$i]];
        }
        return $out;
    }
}
