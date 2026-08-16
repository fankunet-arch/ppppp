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
     * 实测 63 行 `-2` 折扣行共 4 种名称：
     *   Dto. -20%（54）、CUPON DE 5 EUROS（4）、Dto%（4）、TARJETA 10+1（1）
     * 只有最后一种是十送一核销，前三种是普通折扣，【绝不能误判】——
     * 误判会让正常打折的客人拿不到积分。
     * 因此按名称模式匹配，而不是「只要有折扣就算核销」。
     *
     * 店家若改了 POS 里的名称，在后台 sys_config 的 redeem_line_patterns
     * 改这份清单即可，无需改代码。
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
                    'order_end_time'  => (string)$r['order_end_time'],
                ];
            }
            $a = &$out[$ohid];
            $a['check_ids'][]     = (int)$r['check_id'];
            $a['original_cents'] += Money::toCents($r['original_amount'] ?? '0');
            $a['should_cents']   += Money::toCents($r['should_amount']   ?? '0');
            $a['actual_cents']   += Money::toCents($r['actual_amount']   ?? '0');
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
        $waivedCents       = 0;   // 「被免金额」：原价 - 实收
        $display           = [];
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
            }

            // 被免金额：原价（优先 original_price，回落 product_price×qty）与实收之差
            $refC = $origC > 0 ? $origC : $prodC * max($qty, 1);
            if ($refC > $lineC) {
                $waivedCents += ($refC - $lineC);
            }

            if (self::isDisplayableRow($row)) {
                $display[] = [
                    'menu_item_id'   => $itemId,
                    'name'           => (string)($row['menu_item_name'] ?? ''),
                    'quantity'       => $qty,
                    'line_cents'     => $lineC,
                    'unit_cents'     => $prodC,
                    'is_waived'      => $lineC === 0 && $refC > 0,
                    'counts_visit'   => $rules->countsVisit($itemId),
                    'earns_points'   => $rules->earnsPoints($itemId),
                    'is_meal_fee'    => $rules->isMealFee($itemId),
                ];
            }
        }

        return [
            'line_total_cents'    => $lineTotalCents,
            'excluded_cents'      => $excludedCents,
            'meal_fee_cents'      => $mealFeeCents,
            'has_meal_fee_item'   => $hasMealFeeItem,
            'portions_counted'    => $portionsCounted,
            'portions_uncounted'  => $portionsUncounted,
            'waived_cents'        => $waivedCents,
            'display'             => $display,
            'redeem_cents'        => $redeemCents,
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
    public static function isRedeemLine(string $name, array $patterns = self::REDEEM_PATTERNS): bool
    {
        $n = mb_strtoupper(trim($name));
        foreach ($patterns as $p) {
            $p = mb_strtoupper(trim((string)$p));
            if ($p !== '' && str_contains($n, $p)) {
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
        int $excludedCents
    ): int {
        $base = min($shouldCents, $actualCents);
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
     * 积分数 = 基数(欧元) × 每欧元积分 × 倍率，向下取整。
     * 向下取整而非四舍五入：宁可少给，避免累积超发。
     */
    public static function pointsFor(int $baseCents, float $perEuro, float $multiplier): int
    {
        if ($baseCents <= 0) {
            return 0;
        }
        return (int)floor(($baseCents / 100.0) * $perEuro * $multiplier);
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
     * 分配校验 —— 金额守恒的判定逻辑，抽成纯函数便于测试。
     *
     * 恒定成立：SUM(已分配 + 本次) ≤ total_amount
     * 不信任客户端传来的金额，一律以本地镜像的 total_amount 为准。
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
     * 均摊 AA：把金额与份数分给 n 人，余数都给第一位。
     * 保证分毫不差、份数不丢。
     *
     * @return array<int,array{amount_cents:int,portions:int}>
     */
    public static function splitEvenly(int $amountCents, int $portions, int $n): array
    {
        $amts = Money::splitEvenly($amountCents, $n);
        $base = intdiv($portions, $n);
        $rem  = $portions - $base * $n;
        $out  = [];
        for ($i = 0; $i < $n; $i++) {
            $out[] = [
                'amount_cents' => $amts[$i],
                'portions'     => $base + ($i === 0 ? $rem : 0),
            ];
        }
        return $out;
    }
}
