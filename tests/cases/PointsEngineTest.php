<?php
declare(strict_types=1);

use Vip\MealRules;
use Vip\Money;
use Vip\PointsEngine as PE;

/** 与 db/seeds/003_meal_item_rule.sql 一致的规则子集 */
$rules = new MealRules([
    // 堂食套餐：算餐费、计次、积分
    ['menu_item_id' => 2390, 'is_meal_fee' => 1, 'counts_visit' => 1, 'earns_points' => 1, 'item_name' => 'MENÚ INFINITY NOCHE'],
    ['menu_item_id' => 1890, 'is_meal_fee' => 1, 'counts_visit' => 1, 'earns_points' => 1, 'item_name' => 'MENÚ INFINITY MEDIODIA'],
    // MENÚ DEL DIA：算餐费，但不计次
    ['menu_item_id' => 1590, 'is_meal_fee' => 1, 'counts_visit' => 0, 'earns_points' => 1, 'item_name' => 'MENÚ DEL DIA'],
    // BOX：外卖产品线，三个开关全 0
    ['menu_item_id' => 1017, 'is_meal_fee' => 0, 'counts_visit' => 0, 'earns_points' => 0, 'item_name' => 'BOX 17'],
    ['menu_item_id' => 6047, 'is_meal_fee' => 0, 'counts_visit' => 0, 'earns_points' => 0, 'item_name' => 'COMBO L'],
]);

// ════════════════════════════════════════════════════════════
T::group('MealRules —— 未配置菜品的安全默认值');

T::false($rules->isMealFee(431),   '未配置的 Agua：不是餐费项');
T::false($rules->countsVisit(431), '未配置的 Agua：不计次');
T::true($rules->earnsPoints(431),  '未配置的 Agua：金额【正常积分】（漏配不能少给客人钱）');
T::false($rules->isKnown(431),     'Agua 未在规则表中');
T::true($rules->isKnown(2390),     'MENÚ INFINITY NOCHE 已配置');

// ════════════════════════════════════════════════════════════
T::group('明细行过滤 —— 伪行 / 配料 / 退菜');

T::false(PE::isValidItemRow(['menu_item_id' => -3, 'condiment_belong_item' => 0, 'is_return_item' => 0]),
    'menu_item_id=-3 备注行被排除');
T::false(PE::isValidItemRow(['menu_item_id' => -4, 'condiment_belong_item' => 0, 'is_return_item' => 0]),
    'menu_item_id=-4 支付行被排除（其 actual_price 是收款额含找零，混入会虚高）');
T::false(PE::isValidItemRow(['menu_item_id' => 95, 'condiment_belong_item' => 12, 'is_return_item' => 0]),
    'condiment_belong_item≠0 的配料行被排除');
T::false(PE::isValidItemRow(['menu_item_id' => 95, 'condiment_belong_item' => 0, 'is_return_item' => 1]),
    'is_return_item=1 的退菜行被排除（退菜后 actual_price 不清零）');
T::true(PE::isValidItemRow(['menu_item_id' => 2390, 'condiment_belong_item' => 0, 'is_return_item' => 0]),
    '正常菜品行保留');

// ════════════════════════════════════════════════════════════
T::group('展示过滤 —— 必须区分两种「0 元」');

T::false(PE::isDisplayableRow(['product_price' => '0.00', 'original_price' => null, 'actual_price' => '0.00']),
    '套餐内本来免费的菜（三字段全 0/NULL）→ 隐藏');
T::true(PE::isDisplayableRow(['product_price' => '23.90', 'original_price' => null, 'actual_price' => '23.90']),
    '正常收费项 → 显示');
T::true(PE::isDisplayableRow(['product_price' => '2.80', 'original_price' => null, 'actual_price' => '0.00']),
    '被免的收费项（product_price 留痕）→ 【显示】，否则享用免费餐的客人无项可认领');
T::true(PE::isDisplayableRow(['product_price' => '0.00', 'original_price' => '2.80', 'actual_price' => '0.00']),
    '被免的收费项（original_price 留痕，另一种记法）→ 显示');

// ════════════════════════════════════════════════════════════
T::group('analyzeDetail —— 行金额不乘 quantity，份数才用 SUM(quantity)');

// 真实数据形态：3 份 MENÚ INFINITY NOCHE，product_price=23.90，actual_price=71.70
$detail = [
    ['menu_item_id' => 2390, 'menu_item_name' => 'MENÚ INFINITY NOCHE', 'quantity' => 3,
     'product_price' => '23.90', 'original_price' => null, 'actual_price' => '71.70',
     'condiment_belong_item' => 0, 'is_return_item' => 0],
    ['menu_item_id' => 431, 'menu_item_name' => 'Agua', 'quantity' => 2,
     'product_price' => '2.95', 'original_price' => null, 'actual_price' => '5.90',
     'condiment_belong_item' => 0, 'is_return_item' => 0],
    // 套餐内 0 元菜品
    ['menu_item_id' => 95, 'menu_item_name' => '95-Gunkan', 'quantity' => 1,
     'product_price' => '0.00', 'original_price' => null, 'actual_price' => '0.00',
     'condiment_belong_item' => 0, 'is_return_item' => 0],
    // 支付伪行，必须被排除
    ['menu_item_id' => -4, 'menu_item_name' => 'EFECTIVO', 'quantity' => 0,
     'product_price' => '0.00', 'original_price' => null, 'actual_price' => '80.00',
     'condiment_belong_item' => 0, 'is_return_item' => 0],
];
$a = PE::analyzeDetail($detail, $rules);

T::eq(7760, $a['line_total_cents'],
    '行金额合计 = 71.70 + 5.90 = 77.60（不是 71.70×3；支付行 80.00 已排除）');
T::eq(3, $a['portions_counted'],
    '计次份数 = SUM(quantity) = 3（不是 COUNT(*)=1）');
T::eq(7170, $a['meal_fee_cents'], '餐费项金额 = 71.70');
T::true($a['has_meal_fee_item'],  '订单内存在餐费项');
T::eq(0, $a['excluded_cents'],    '无 earns_points=0 的项');
T::eq(2, count($a['display']),    '展示列表 2 项（0 元套餐内菜品与支付伪行都不显示）');

// ════════════════════════════════════════════════════════════
T::group('analyzeDetail —— BOX 在堂食单中（三开关全 0）');

$detailBox = [
    ['menu_item_id' => 1017, 'menu_item_name' => 'BOX 17', 'quantity' => 2,
     'product_price' => '26.50', 'original_price' => null, 'actual_price' => '53.00',
     'condiment_belong_item' => 0, 'is_return_item' => 0],
    ['menu_item_id' => 431, 'menu_item_name' => 'Agua', 'quantity' => 1,
     'product_price' => '2.95', 'original_price' => null, 'actual_price' => '2.95',
     'condiment_belong_item' => 0, 'is_return_item' => 0],
];
$ab = PE::analyzeDetail($detailBox, $rules);
T::eq(5595, $ab['line_total_cents'], '行金额合计 55.95');
T::eq(5300, $ab['excluded_cents'],   'BOX 的 53.00 计入排除金额（earns_points=0）');
T::eq(0, $ab['portions_counted'],    'BOX 不计次');
T::false($ab['has_meal_fee_item'],   'BOX 不是餐费项');

// ════════════════════════════════════════════════════════════
T::group('analyzeDetail —— MENÚ DEL DIA 算餐费但不计次');

$detailDD = [
    ['menu_item_id' => 1590, 'menu_item_name' => 'MENÚ DEL DIA', 'quantity' => 3,
     'product_price' => '15.90', 'original_price' => null, 'actual_price' => '47.70',
     'condiment_belong_item' => 0, 'is_return_item' => 0],
    ['menu_item_id' => 1890, 'menu_item_name' => 'MENÚ INFINITY MEDIODIA', 'quantity' => 2,
     'product_price' => '18.90', 'original_price' => null, 'actual_price' => '37.80',
     'condiment_belong_item' => 0, 'is_return_item' => 0],
];
$ad = PE::analyzeDetail($detailDD, $rules);
T::eq(2, $ad['portions_counted'],   '只有 INFINITY 的 2 份计次');
T::eq(3, $ad['portions_uncounted'], 'DEL DIA 的 3 份记为不计次份数');
T::eq(8550, $ad['meal_fee_cents'],  '两者都算餐费：47.70 + 37.80 = 85.50');

// ════════════════════════════════════════════════════════════
T::group('pointsBaseCents —— LEAST(should, actual) 排除找零');

T::eq(5830, PE::pointsBaseCents(5830, 5830, 5830, 0),
    '正常单：应收=实收=58.30');
T::eq(10535, PE::pointsBaseCents(10535, 11535, 10535, 0),
    '现金找零：应收 105.35 / 收款 115.35 → 取 105.35（多的 10.00 是找零不是消费）');
T::eq(12785, PE::pointsBaseCents(12785, 13500, 12785, 0),
    '递整钞：应收 127.85 / 收款 135.00 → 取 127.85');
T::eq(2740, PE::pointsBaseCents(2740, 5480, 2740, 0),
    '疑似重复收款：应收 27.40 / 收款 54.80 → 取 27.40（用 actual 会多给一倍）');
T::eq(4170, PE::pointsBaseCents(4170, 4180, 4170, 0),
    '分币舍入：41.70 / 41.80 → 取 41.70');
T::eq(0, PE::pointsBaseCents(0, 0, 0, 0),
    '免费餐 / 员工餐：应收实收皆 0 → 不积分');

T::group('pointsBaseCents —— 不计分项按比例扣除');

// 真实形态：original=114.10、discount=-14.34、should=99.76（docs/01 §3.3.1 实例）
// 其中 14.10 的项 earns_points=0。
// 不计分项占原价 14.10/114.10 = 12.357%
// 基数 = 99.76 × (1 - 0.12357) = 99.76 × 0.87643 = 87.43
T::eq(8743, PE::pointsBaseCents(9976, 9976, 11410, 1410),
    '应收 99.76（原价 114.10、折扣 -14.34），排除 14.10 → 按比例扣得 87.43');
// 同一订单若不带折扣（should = original = 114.10），扣 14.10 后应恰为 100.00
T::eq(10000, PE::pointsBaseCents(11410, 11410, 11410, 1410),
    '无折扣时按比例扣退化为直接相减：114.10 - 14.10 = 100.00');
T::eq(0, PE::pointsBaseCents(5595, 5595, 5595, 5595),
    '堂食单全是 BOX：排除金额=全额 → 基数归零，不积分');
T::eq(5830, PE::pointsBaseCents(5830, 5830, 5830, 0),
    '无排除项时按比例扣不生效');
T::eq(2915, PE::pointsBaseCents(5830, 5830, 5830, 2915),
    '排除一半 → 基数减半');

T::group('pointsFor —— 向下取整，宁可少给');

T::eq(58, PE::pointsFor(5830, 1.0, 1.0),  '58.30 欧 × 1 分/欧 = 58 分（向下取整）');
T::eq(87, PE::pointsFor(8750, 1.0, 1.0),  '87.50 欧 → 87 分');
T::eq(131, PE::pointsFor(8750, 1.0, 1.5), '87.50 欧 × 1.5 倍 = 131 分');
T::eq(0, PE::pointsFor(0, 1.0, 1.0),      '0 元 → 0 分');
T::eq(0, PE::pointsFor(50, 1.0, 1.0),     '0.50 欧 → 0 分');

// ════════════════════════════════════════════════════════════
T::group('checkEligible —— eat_type 用白名单而非黑名单');

T::true(PE::checkEligible(0, 5830, 5830, false)['ok'],  '堂食 eat_type=0 可积分');
T::false(PE::checkEligible(3, 5830, 5830, false)['ok'], '外带 eat_type=3 不积分');
T::false(PE::checkEligible(1, 5830, 5830, false)['ok'], 'eat_type=1（来路不明，3 单）被白名单自动排除');
T::false(PE::checkEligible(2, 5830, 5830, false)['ok'], 'eat_type=2（来路不明，4 单）被白名单自动排除');
T::eq('zero_amount', PE::checkEligible(0, 0, 0, false)['reason'],
    'actual=0 的 1,666 单（员工餐/招待/作废）不积分');
T::eq('free_meal', PE::checkEligible(0, 5830, 5830, true)['reason'],
    '已标记免费餐不积分');

// ════════════════════════════════════════════════════════════
T::group('suspectFreeMeal —— 前置条件「存在餐费项」防误报');

$withMeal    = ['has_meal_fee_item' => true,  'meal_fee_cents' => 0];
$mealCharged = ['has_meal_fee_item' => true,  'meal_fee_cents' => 7170];
$noMeal      = ['has_meal_fee_item' => false, 'meal_fee_cents' => 0];

T::true(PE::suspectFreeMeal($withMeal, 500, false),
    '点了套餐但餐费为 0、订单仍有金额 → 疑似漏点核销，报警');
T::false(PE::suspectFreeMeal($mealCharged, 7670, false),
    '套餐正常收费 → 不报警');
T::false(PE::suspectFreeMeal($noMeal, 4500, false),
    '★ 堂食点 COMBO L 配酒水（无餐费项）→ 【不报警】。若缺前置条件会全部误报');
T::false(PE::suspectFreeMeal($withMeal, 500, true),
    '已在 Pad 标记核销 → 不报警');
T::false(PE::suspectFreeMeal($withMeal, 0, false),
    '订单本身 0 元 → 不报警');

// ════════════════════════════════════════════════════════════
T::group('aggregateCandidates —— 按 order_head_id 聚合消除分单歧义');

$heads = [
    ['order_head_id' => 92319, 'check_id' => 1, 'serial_id' => '2608130080', 'table_name' => '42',
     'eat_type' => 0, 'customer_num' => 2, 'original_amount' => '53.70', 'should_amount' => '53.70',
     'actual_amount' => '53.70', 'order_end_time' => '2026-08-13 23:11:47'],
    // 同一订单的第二张 check（实测多数为 0 元空壳）
    ['order_head_id' => 92319, 'check_id' => 2, 'serial_id' => '2608130080', 'table_name' => '42',
     'eat_type' => 0, 'customer_num' => 2, 'original_amount' => '0.00', 'should_amount' => '0.00',
     'actual_amount' => '0.00', 'order_end_time' => '2026-08-13 23:11:50'],
    // 另一桌
    ['order_head_id' => 92322, 'check_id' => 1, 'serial_id' => '2608130081', 'table_name' => '32',
     'eat_type' => 0, 'customer_num' => 4, 'original_amount' => '86.65', 'should_amount' => '86.65',
     'actual_amount' => '86.65', 'order_end_time' => '2026-08-13 23:16:12'],
];
$agg = PE::aggregateCandidates($heads);

T::eq(2, count($agg), '3 行 check 聚合成 2 张订单（这正是歧义率从 10.95% 降到 0.02% 的原因）');
T::eq(5370, $agg['92319']['should_cents'], '92319 两张 check 合计 53.70');
T::eq([1, 2], $agg['92319']['check_ids'],  'check_ids 完整保留，供回读明细');
T::eq('2026-08-13 23:11:50', $agg['92319']['order_end_time'], '取最晚的结账时间');
T::eq('2608130080', $agg['92319']['serial_id'], 'serial_id 作为业务主键');
T::eq(8665, $agg['92322']['should_cents'], '92322 独立一单');

// ════════════════════════════════════════════════════════════
T::group('splitEvenly —— AA 金额与份数同时分摊');

$sp = PE::splitEvenly(7170, 3, 3);
T::eq(3, count($sp), '3 人');
T::eq(7170, array_sum(array_column($sp, 'amount_cents')), '金额合计不丢分');
T::eq(3, array_sum(array_column($sp, 'portions')), '份数合计不丢');
T::eq(1, $sp[0]['portions'], '3 份分 3 人 → 每人 1 份');

$sp2 = PE::splitEvenly(10000, 4, 3);
T::eq(10000, array_sum(array_column($sp2, 'amount_cents')), '金额合计不丢分');
T::eq(4, array_sum(array_column($sp2, 'portions')), '4 份分 3 人 → 合计仍是 4');
T::eq(2, $sp2[0]['portions'], '余数份给第一位：2 / 1 / 1');
T::eq(1, $sp2[1]['portions'], '第二位 1 份');
