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
