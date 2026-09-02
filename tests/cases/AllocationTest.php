<?php
declare(strict_types=1);

use Vip\PointsEngine as PE;

T::group('validateAllocations —— 金额守恒');

// 订单可积分总额 71.70，计次份数 3，尚未分配
$T = 7170; $P = 3;

$r = PE::validateAllocations([['member_id' => 1, 'amount_cents' => 7170, 'portions' => 3]], $T, 0, $P, 0);
T::true($r['ok'], '整单记给一人，正好用完');
T::eq(7170, $r['sum_amount'], '合计金额回传');
T::eq(3, $r['sum_portions'], '合计份数回传');

$r = PE::validateAllocations([
    ['member_id' => 1, 'amount_cents' => 2390, 'portions' => 1],
    ['member_id' => 2, 'amount_cents' => 2390, 'portions' => 1],
    ['member_id' => 3, 'amount_cents' => 2390, 'portions' => 1],
], $T, 0, $P, 0);
T::true($r['ok'], 'AA 三人各 23.90 / 各 1 份');

$r = PE::validateAllocations([['member_id' => 1, 'amount_cents' => 2390, 'portions' => 1]], $T, 4780, $P, 2);
T::true($r['ok'], '已分配 47.80 / 2 份后，再分 23.90 / 1 份正好用完');

T::group('validateAllocations —— 超额一律拒绝');

$r = PE::validateAllocations([['member_id' => 1, 'amount_cents' => 7171, 'portions' => 3]], $T, 0, $P, 0);
T::false($r['ok'], '多 1 分也拒绝');
T::eq('exceeds_total', $r['error'], '错误码 exceeds_total');

$r = PE::validateAllocations([['member_id' => 9, 'amount_cents' => 2390, 'portions' => 1]], $T, 7170, $P, 3);
T::eq('exceeds_total', $r['error'], '已全额分配后再提交 → 拒绝（防止同一订单被记两次）');

$r = PE::validateAllocations([['member_id' => 1, 'amount_cents' => 1000, 'portions' => 4]], $T, 0, $P, 0);
T::eq('exceeds_portions', $r['error'], '份数超出 → 拒绝（金额没超也不行）');

$r = PE::validateAllocations([['member_id' => 1, 'amount_cents' => -100, 'portions' => 1]], $T, 0, $P, 0);
T::eq('negative_allocation', $r['error'], '负金额 → 拒绝（不能用分配接口刷分）');

$r = PE::validateAllocations([['member_id' => 1, 'amount_cents' => 100, 'portions' => -1]], $T, 0, $P, 0);
T::eq('negative_allocation', $r['error'], '负份数 → 拒绝');

T::group('validateAllocations —— 输入合法性');

T::eq('empty_allocation', PE::validateAllocations([], $T, 0, $P, 0)['error'],
    '空分配 → 拒绝');
T::eq('zero_amount', PE::validateAllocations(
    [['member_id' => 1, 'amount_cents' => 0, 'portions' => 0]], 0, 0, 0, 0)['error'],
    '订单总额为 0（员工餐/免费餐）→ 拒绝');
T::eq('invalid_member', PE::validateAllocations(
    [['member_id' => 0, 'amount_cents' => 100, 'portions' => 0]], $T, 0, $P, 0)['error'],
    'member_id 缺失 → 拒绝');
T::eq('duplicate_member', PE::validateAllocations([
    ['member_id' => 1, 'amount_cents' => 1000, 'portions' => 1],
    ['member_id' => 1, 'amount_cents' => 1000, 'portions' => 1],
], $T, 0, $P, 0)['error'],
    '同一会员在一次提交里出现两次 → 拒绝（多半是前端重复提交）');
T::eq('empty_allocation', PE::validateAllocations(
    [['member_id' => 1, 'amount_cents' => 0, 'portions' => 0]], $T, 0, $P, 0)['error'],
    '金额与份数都是 0 → 视为空分配');

T::group('validateAllocations —— 份数必须带金额（次数与积分绑定）');

// ★ 这是现场发现的洞，见 PointsEngine::validateAllocations 里的说明。
//   一单 71.70 / 3 份：A 先把金额全拿走，B 再来「0 元 1 份」。
$r = PE::validateAllocations([['member_id' => 2, 'amount_cents' => 0, 'portions' => 1]], $T, 7170, $P, 1);
T::eq('portions_without_amount', $r['error'],
    '★★★ 金额已被分完后再提交「0 元 1 份」→ 拒绝（防止白拿一次计次）');

$r = PE::validateAllocations([
    ['member_id' => 1, 'amount_cents' => 7170, 'portions' => 1],
    ['member_id' => 2, 'amount_cents' => 0,    'portions' => 1],
], $T, 0, $P, 0);
T::eq('portions_without_amount', $r['error'],
    '同一次提交里夹带一个 0 元的人 → 整笔拒绝（要么一起计，要么一起拒）');

$r = PE::validateAllocations([['member_id' => 1, 'amount_cents' => 2390, 'portions' => 0]], $T, 0, $P, 0);
T::true($r['ok'], '有金额没份数 → 允许（只点酒水：该积分、不该计次）');

$r = PE::validateAllocations([['member_id' => 1, 'amount_cents' => 1, 'portions' => 3]], $T, 0, $P, 0);
T::true($r['ok'], '绑定只看「有没有钱」，不看多少 —— 1 分钱也算有');

// splitEvenly 不会造出「0 元有份」的分配，正常 AA 不受影响
foreach ([[7170, 3, 3], [10000, 4, 4], [999, 2, 2], [7170, 0, 3], [500, 10, 10], [7170, 3, 4]] as [$amt, $prt, $n]) {
    $shares = PE::splitEvenly($amt, $prt, $n);
    $allocs = [];
    foreach ($shares as $i => $sh) {
        $allocs[] = ['member_id' => $i + 1] + $sh;
    }
    $ok = PE::validateAllocations($allocs, $amt, 0, $prt, 0);
    T::true($ok['ok'], "splitEvenly({$amt}, {$prt}, {$n}) 的结果照样通过校验");
}

T::group('splitEvenly —— 份数余数要摊开，不能堆给第一位');

/**
 * ★ 这是 portions_without_amount 的【反面】：有钱却没份数。
 *
 *   once_per_period 口径下，份数已经不是「几份」而是
 *   「这个人有没有吃计次套餐」这个是非题。
 *   3 份 4 人如果堆成 [3,0,0,0]，后三位付了钱一次都拿不到 ——
 *   而且不报错、不告警，客人要等到攒够十次那天才发现少了。
 *
 *   旧口径 by_portion 下堆给第一位是没问题的（第一位记 3 次，总数守恒），
 *   所以这不是「一直写错了」，是计次口径改掉之后【原来对的写法变错了】。
 */
$col = static fn(array $sh): array => array_column($sh, 'portions');

T::eq([1, 1, 1, 0], $col(PE::splitEvenly(7170, 3, 4)),
    '★★★ 3 份 4 人 → [1,1,1,0]，不是 [3,0,0,0]（三位各记 1 次，第四位确实没点套餐）');
T::eq([1, 1, 1, 1], $col(PE::splitEvenly(7170, 4, 4)), '4 份 4 人 → 每人 1 份');
T::eq([3, 3, 2, 2], $col(PE::splitEvenly(7170, 10, 4)), '10 份 4 人 → 余数一人一份地摊，不是 [4,2,2,2]');
T::eq([1, 1, 0, 0, 0], $col(PE::splitEvenly(7170, 2, 5)), '2 份 5 人 → 只有两位有份（单确实只有 2 份计次套餐）');
T::eq([0, 0, 0], $col(PE::splitEvenly(7170, 0, 3)), '整单 0 份（纯酒水单）→ 全是 0，谁都不计次');
T::eq([1], $col(PE::splitEvenly(7170, 1, 1)), '1 人整桌 → 1 份');

// 无论怎么摊，份数与金额都不能丢
foreach ([[7170, 3, 4], [7170, 10, 4], [999, 7, 3], [1, 1, 2], [12345, 13, 7]] as [$amt, $prt, $n]) {
    $sh = PE::splitEvenly($amt, $prt, $n);
    T::eq($prt, array_sum(array_column($sh, 'portions')), "份数守恒：{$prt} 份分给 {$n} 人");
    T::eq($amt, array_sum(array_column($sh, 'amount_cents')), "金额守恒：{$amt} 分给 {$n} 人");
}

// 拿到份数的人数 = min(份数, 人数) —— 这正是「尽可能多的人记上次」
foreach ([[3, 4, 3], [10, 4, 4], [2, 5, 2], [0, 3, 0], [1, 1, 1]] as [$prt, $n, $expect]) {
    $withPort = count(array_filter($col(PE::splitEvenly(9999, $prt, $n)), static fn(int $p): bool => $p > 0));
    T::eq($expect, $withPort, "★★ {$prt} 份 {$n} 人 → {$expect} 位拿到份数（= min(份数, 人数)）");
}

T::group('splitEvenly —— 没分到钱的人不给份数');

/**
 * ★ 金额余数给第一位、份数余数摊开 —— 两条规则不一样，就带出一个组合：
 *   当 intdiv(金额, 人数) == 0 时，排在后面的人会拿到「0 元 + 1 份」。
 *
 *       splitEvenly(2 分, 3 份, 5 人) → 2/1  0/1  0/1  0/0  0/0
 *
 *   这正是 validateAllocations 硬拒的 portions_without_amount ——
 *   这个纯函数会造出一组【交回去会被自己拒掉】的分配。
 *   （而它自己的注释当时还写着「不会造出这种分配」。）
 *
 *   现实里要求「剩余金额的分数 < 人数」，几乎不可能发生，
 *   所以不是金额风险；但自相矛盾的纯函数迟早会被别处拿去用。
 */
foreach ([[2, 3, 5], [3, 3, 5], [1, 4, 4], [0, 3, 3], [7, 9, 10]] as [$amt, $prt, $n]) {
    $sh  = PE::splitEvenly($amt, $prt, $n);
    $bad = array_filter($sh, static fn(array $x): bool => $x['amount_cents'] === 0 && $x['portions'] > 0);
    T::true($bad === [],
        "★★★ splitEvenly({$amt}分, {$prt}份, {$n}人) 里没有「0 元却带份数」的人");
    // 并且这一组交回校验必须能过（空分配除外）
    $allocs = [];
    foreach ($sh as $i => $x) {
        if ($x['amount_cents'] > 0 || $x['portions'] > 0) {
            $allocs[] = ['member_id' => $i + 1] + $x;
        }
    }
    if ($allocs !== []) {
        $chk = PE::validateAllocations($allocs, max(1, $amt), 0, $prt, 0);
        T::true($chk['ok'], "  └ 交回 validateAllocations 能过（{$amt}分/{$prt}份/{$n}人）");
    }
}

// 金额守恒不受影响
foreach ([[2, 3, 5], [999, 7, 3], [12345, 13, 7]] as [$amt, $prt, $n]) {
    $sh = PE::splitEvenly($amt, $prt, $n);
    T::eq($amt, array_sum(array_column($sh, 'amount_cents')), "金额守恒：{$amt} 分给 {$n} 人");
}

T::group('validateAllocations —— 部分登记（AA 中只有部分人有卡）');

// 4 人 AA 但只有 3 人有卡：允许只分配 3 份，剩余留空
$r = PE::validateAllocations([
    ['member_id' => 1, 'amount_cents' => 2500, 'portions' => 1],
    ['member_id' => 2, 'amount_cents' => 2500, 'portions' => 1],
    ['member_id' => 3, 'amount_cents' => 2500, 'portions' => 1],
], 10000, 0, 4, 0);
T::true($r['ok'], '4 人 AA 只登记 3 人 → 允许，剩余份额保持未分配');
T::eq(7500, $r['sum_amount'], '只分配 75.00，余 25.00 留空');

T::group('validateAllocations —— 撤销后可重新分配');

// 撤销会把 allocated 回退到 0，此时应可重新整单分配
$r = PE::validateAllocations([
    ['member_id' => 5, 'amount_cents' => 2390, 'portions' => 1],
    ['member_id' => 6, 'amount_cents' => 2390, 'portions' => 1],
    ['member_id' => 7, 'amount_cents' => 2390, 'portions' => 1],
], $T, 0, $P, 0);
T::true($r['ok'], '撤销回退后改记 AA 三人 → 通过');

T::group('计次的最低金额门槛 —— 一分钱不能换一次「十送一」');

/**
 * ★ portions_without_amount 只堵住了 0 元，0.01 元照样过。
 *
 *   一桌四个人 71.70、三份套餐，实际只有一个人点了计次套餐。
 *   点选菜品模式下让他认领 71.69 计 1 次，
 *   再把剩下的 0.01 连着 1 份丢给同行没点套餐的人 ——
 *   那个人就白得一次「十送一」的进度。实测确实通得过。
 *
 *   次数才是奖励的真正来源（十送一 = 一顿免费的饭），金额是死的、分完就没了，
 *   所以真正要守的门槛在次数这一侧。
 */
$MIN = 500;    // 5.00 €，与 db/seeds 的出厂值一致

$r = PE::validateAllocations([
    ['member_id' => 1, 'amount_cents' => 7169, 'portions' => 1],
    ['member_id' => 2, 'amount_cents' => 1,    'portions' => 1],   // 1 分钱换 1 次
], $T, 0, $P, 0, $MIN);
T::false($r['ok'], '★★★ 0.01 € 换 1 次 → 拒绝');
T::eq('amount_too_small_for_visit', $r['error'], '  └ 错误码说清是「金额不够计一次」，不是含糊的守恒失败');

$r = PE::validateAllocations([
    ['member_id' => 1, 'amount_cents' => 7169, 'portions' => 1],
    ['member_id' => 2, 'amount_cents' => 499,  'portions' => 1],
], $T, 0, $P, 0, $MIN);
T::false($r['ok'], '  └ 差一分钱也不行（4.99 < 5.00）—— 门槛不能是"大概"');

$r = PE::validateAllocations([
    ['member_id' => 1, 'amount_cents' => 6670, 'portions' => 1],
    ['member_id' => 2, 'amount_cents' => 500,  'portions' => 1],
], $T, 0, $P, 0, $MIN);
T::true($r['ok'], '  └ 正好够门槛就放行');

/**
 * ★ 只管【要计次的那几笔】。
 *   只点一杯酒水的客人该积分不该计次，那是正常生意，不能拦。
 */
$r = PE::validateAllocations([
    ['member_id' => 1, 'amount_cents' => 7169, 'portions' => 3],
    ['member_id' => 2, 'amount_cents' => 1,    'portions' => 0],   // 有钱没份
], $T, 0, $P, 0, $MIN);
T::true($r['ok'], '★★ 有钱【没份】的 0.01 € 照常通过 —— 绑定只有「份数 ⇒ 够金额」这一个方向');

// 正常的四人 AA（71.70 / 4 ≈ 17.92）不受影响 —— 这一条是防止门槛误伤日常生意
$r = PE::validateAllocations([
    ['member_id' => 1, 'amount_cents' => 1793, 'portions' => 1],
    ['member_id' => 2, 'amount_cents' => 1792, 'portions' => 1],
    ['member_id' => 3, 'amount_cents' => 1792, 'portions' => 1],
    ['member_id' => 4, 'amount_cents' => 1793, 'portions' => 0],
], $T, 0, $P, 0, $MIN);
T::true($r['ok'], '★★ 正常四人 AA 照常通过 —— 门槛不能挡住日常生意');

// 填 0 = 不设门槛（旧行为，给不想用这条规则的门店留后路）
$r = PE::validateAllocations([
    ['member_id' => 1, 'amount_cents' => 7169, 'portions' => 1],
    ['member_id' => 2, 'amount_cents' => 1,    'portions' => 1],
], $T, 0, $P, 0, 0);
T::true($r['ok'], '门槛填 0 时退回旧行为（只要求「有钱」）');

T::group('计次成本的【性质】—— 穷举，而不是逐个场景');

/**
 * ★ 这一组和上面那些逐条断言不一样：它不枚举玩法，而是钉住一条【性质】。
 *
 *   起因是一个真实的漏洞：门槛判据写成 `$amt < $min` 而不是 `$amt < $min * $prt`，
 *   于是「0.01 换 1 次」拦住了，「5.00 换 3 次」放行 ——
 *   同一条规则，换个数字就绕过去了。逐个场景写断言永远追不上，
 *   因为漏的正是没想到的那个组合。
 *
 *   改成穷举一条性质：**任何一笔能通过校验的分配，
 *   它每换到一次计次所付的钱，都不能低于门槛。**
 *   只要这条恒成立，无论有没有人想到某种新玩法，都绕不过去。
 */
$MIN = 500;
$bad = null; $checked = 0;
for ($amt = 0; $amt <= 8000; $amt += 37) {
    for ($prt = 0; $prt <= 6; $prt++) {
        $checked++;
        $r = PE::validateAllocations(
            [['member_id' => 1, 'amount_cents' => $amt, 'portions' => $prt]],
            100000, 0, 20, 0, $MIN);
        if (!($r['ok'] ?? false) || $prt === 0) {
            continue;                       // 被拒的、以及不计次的，都不在这条性质的范围内
        }
        // 通过了校验 且 真的换到了次数 → 每次的成本必须够门槛
        if (intdiv($amt, $prt) < $MIN) {
            $bad = "金额 {$amt} 分 / {$prt} 份 → 每次只花了 " . intdiv($amt, $prt) . " 分";
            break 2;
        }
    }
}
T::eq(null, $bad,
    "★★★ 穷举 {$checked} 种（金额 × 份数）组合：凡是通过校验并换到计次的，"
    . '每一次的成本都 ≥ 门槛' . ($bad === null ? '' : "（反例：{$bad}）"));

/**
 * ★ 反向也要钉：门槛不能把【正常生意】挡在外面。
 *   只要每次的钱够门槛，就必须放行 —— 否则这道闸门会变成柜台上的路障。
 */
$blocked = null; $checked2 = 0;
for ($prt = 1; $prt <= 6; $prt++) {
    for ($per = $MIN; $per <= $MIN + 2000; $per += 113) {
        $checked2++;
        $r = PE::validateAllocations(
            [['member_id' => 1, 'amount_cents' => $per * $prt, 'portions' => $prt]],
            1000000, 0, 20, 0, $MIN);
        if (!($r['ok'] ?? false)) {
            $blocked = "每次 {$per} 分 × {$prt} 份被拒了";
            break 2;
        }
    }
}
T::eq(null, $blocked,
    "★★ 反向穷举 {$checked2} 种：每次都够门槛的分配【一律放行】"
    . ($blocked === null ? '' : "（反例：{$blocked}）"));
