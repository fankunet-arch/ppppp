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
