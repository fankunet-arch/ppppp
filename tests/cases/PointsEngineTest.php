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

// ── 回归：聚合出的 serial_id 必须与行序无关（模拟环境实测缺陷）──────
// 实测 order_head_id=25379 的两张 check 各带一个 serial
// （2409020040 / 2409020041）。Pad 走 order_end_time DESC、
// Cron 走 ASC，若取「第一行」的 serial，两条路径会各存一个幂等键，
// pos_order 里互相查不到，同一单被发两次分。
$dualSerial = [
    ['order_head_id' => 25379, 'check_id' => 1, 'serial_id' => '2409020040', 'table_name' => '80',
     'eat_type' => 0, 'customer_num' => 10, 'original_amount' => '238.75', 'should_amount' => '218.75',
     'actual_amount' => '218.75', 'order_end_time' => '2024-09-02 16:35:23'],
    ['order_head_id' => 25379, 'check_id' => 2, 'serial_id' => '2409020041', 'table_name' => '80',
     'eat_type' => 0, 'customer_num' => 10, 'original_amount' => '0.00', 'should_amount' => '0.00',
     'actual_amount' => '0.00', 'order_end_time' => '2024-09-02 16:35:46'],
];
$aggAsc  = PE::aggregateCandidates($dualSerial);
$aggDesc = PE::aggregateCandidates(array_reverse($dualSerial));
T::eq('2409020040', $aggAsc['25379']['serial_id'],  '一单双 serial：正序取到最小的');
T::eq('2409020040', $aggDesc['25379']['serial_id'], '一单双 serial：逆序仍取到最小的（两条读取路径必须一致）');
T::eq(21875, $aggAsc['25379']['should_cents'], '两张 check 的金额仍完整累加');

// ── 回归：serial_id = 0 的退化单不得共用幂等键 ────────────────────
// 实测 order_head_id=70762 两张 check 的 serial 都是 0。
// 若直接拿 0 当幂等键，另一天再出一单 serial=0 就会静默并单：
// 订单消失、积分不发、且不报错。
T::eq('H70762', PE::idempotencyKey('0', 70762), 'serial=0 → 退化为 order_head_id 造键');
T::eq('H70762', PE::idempotencyKey('',  70762), 'serial 为空 → 同样退化');
T::eq('2608130080', PE::idempotencyKey('2608130080', 92319), '正常 serial 原样返回');
T::true(PE::idempotencyKey('0', 70762) !== PE::idempotencyKey('0', 70999),
    '两个不同的 serial=0 订单必须得到不同的幂等键');

$zero = PE::aggregateCandidates([
    ['order_head_id' => 70762, 'check_id' => 1, 'serial_id' => '0', 'table_name' => '15',
     'eat_type' => 0, 'customer_num' => 8, 'original_amount' => '71.70', 'should_amount' => '71.70',
     'actual_amount' => '71.70', 'order_end_time' => '2025-12-29 23:27:40'],
    ['order_head_id' => 70762, 'check_id' => 2, 'serial_id' => '0', 'table_name' => '15',
     'eat_type' => 0, 'customer_num' => 8, 'original_amount' => '101.50', 'should_amount' => '101.50',
     'actual_amount' => '101.50', 'order_end_time' => '2025-12-29 23:26:10'],
]);
T::eq('H70762', $zero['70762']['serial_id'], 'serial=0 的单经聚合后拿到造出来的键');
T::eq(17320, $zero['70762']['should_cents'], '金额照常累加 71.70 + 101.50');

// ════════════════════════════════════════════════════════════
T::group('展示行合并 —— 必须与小票印出的行一致');

// 实测订单 92518（2026-08-16 Mesa 49，小票在手）：
// POS 把两瓶水存成两行各 2.95，小票印的是「2 Agua 5.90」。
// 服务员照着小票核对，不合并就会小票 2 行、Pad 3 行，像是多收了一笔。
$rulesR = new MealRules([
    ['menu_item_id' => 2590, 'is_meal_fee' => 1, 'counts_visit' => 1, 'earns_points' => 1],
]);
$twoWaters = [
    ['menu_item_id'=>2590,'menu_item_name'=>'MENÚ INFINITY VIERNES','quantity'=>2,
     'product_price'=>'25.90','original_price'=>null,'actual_price'=>'51.80',
     'is_return_item'=>0,'condiment_belong_item'=>0],
    ['menu_item_id'=>431,'menu_item_name'=>'Agua','quantity'=>1,
     'product_price'=>'2.95','original_price'=>null,'actual_price'=>'2.95',
     'is_return_item'=>0,'condiment_belong_item'=>0],
    ['menu_item_id'=>431,'menu_item_name'=>'Agua','quantity'=>1,
     'product_price'=>'2.95','original_price'=>null,'actual_price'=>'2.95',
     'is_return_item'=>0,'condiment_belong_item'=>0],
];
$da = PE::analyzeDetail($twoWaters, $rulesR);
T::eq(2, count($da['display']), '★ 两行 Agua 合并成一行（与小票一致）');
$agua = null;
foreach ($da['display'] as $d) { if ($d['menu_item_id'] === 431) { $agua = $d; } }
T::eq(2,   $agua['quantity'],   'Agua 数量合并为 2');
T::eq(590, $agua['line_cents'], 'Agua 金额合并为 5.90');
T::eq(5770, $da['line_total_cents'], '★ 合计仍是 57.70，与小票 TOTAL 一致');
T::eq(2, $da['portions_counted'], '2 份套餐 = 2 次');

// 同名但一个被免、一个照价收，不能合并 —— 否则看不出哪份是免的
$mixed = [
    ['menu_item_id'=>431,'menu_item_name'=>'Agua','quantity'=>1,
     'product_price'=>'2.95','original_price'=>null,'actual_price'=>'2.95',
     'is_return_item'=>0,'condiment_belong_item'=>0],
    ['menu_item_id'=>431,'menu_item_name'=>'Agua','quantity'=>1,
     'product_price'=>'2.95','original_price'=>'2.95','actual_price'=>'0.00',
     'is_return_item'=>0,'condiment_belong_item'=>0],
];
$dm = PE::analyzeDetail($mixed, $rulesR);
T::eq(2, count($dm['display']), '★ 被免的与照价收的同名菜不合并');

// ════════════════════════════════════════════════════════════
T::group('十送一核销识别 —— menu_item_id = -2 折扣行');

// 实测 -2 折扣行共 4 种名称，只有 TARJETA 10+1 是核销
T::true(PE::isRedeemLine('TARJETA 10+1'),      'TARJETA 10+1 → 核销');
T::true(PE::isRedeemLine('tarjeta 10+1'),      '大小写不敏感');
T::true(PE::isRedeemLine('  TARJETA 10+1  '),  '两侧空白不敏感');
T::false(PE::isRedeemLine('Dto. -20%'),        'Dto. -20% 是普通折扣，不得误判');
T::false(PE::isRedeemLine('CUPON DE 5 EUROS'), 'CUPON 是普通折扣，不得误判');
T::false(PE::isRedeemLine('Dto%'),             'Dto% 是普通折扣，不得误判');
T::true(PE::isRedeemLine('PROMO CANJE', ['CANJE']), '模式可配置');

// 含核销行的订单：金额不受污染，但标记为已核销
$rulesRedeem = new MealRules([
    ['menu_item_id' => 2390, 'is_meal_fee' => 1, 'counts_visit' => 1, 'earns_points' => 1],
]);
$redeemDetail = [
    ['menu_item_id' => 2390, 'menu_item_name' => 'MENÚ INFINITY', 'quantity' => 5,
     'product_price' => '23.90', 'original_price' => null, 'actual_price' => '119.50',
     'is_return_item' => 0, 'condiment_belong_item' => 0],
    // 核销折扣行：负金额，绝不能混进任何合计
    ['menu_item_id' => -2, 'menu_item_name' => 'TARJETA 10+1', 'quantity' => 0,
     'product_price' => '0.00', 'original_price' => null, 'actual_price' => '-95.60',
     'is_return_item' => 0, 'condiment_belong_item' => 0],
];
$ra = PE::analyzeDetail($redeemDetail, $rulesRedeem);
T::true($ra['is_redeemed'],           '识别出核销行');
T::eq(9560, $ra['redeem_cents'],      '核销额取绝对值 95.60');
T::eq(11950, $ra['line_total_cents'], '★ 负金额的折扣行没有混进行合计');
T::eq(5, $ra['portions_counted'],     '份数只数菜品行');
T::eq(0, count(array_filter($ra['display'], fn($d) => $d['menu_item_id'] < 0)),
    '折扣伪行不出现在 Pad 展示列表里');

// 普通折扣不得被当成核销
$plainDiscount = $redeemDetail;
$plainDiscount[1]['menu_item_name'] = 'Dto. -20%';
$pd = PE::analyzeDetail($plainDiscount, $rulesRedeem);
T::false($pd['is_redeemed'],      '普通折扣行不算核销');
T::eq(0, $pd['redeem_cents'],     '普通折扣不计入核销额');
T::eq(11950, $pd['line_total_cents'], '普通折扣行同样不污染行合计');

// 核销单必须失去积分资格
T::false(PE::checkEligible(0, 2980, 2980, true)['ok'], '核销单不可积分');
T::true(PE::checkEligible(0, 2980, 2980, false)['ok'], '非核销单正常可积分');

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

T::group('核销抵掉几份 —— redeemedPortions');

/**
 * 39 天真实样本（25 张核销单）验证过的口径。
 * 关键结论：核销额【不是】恒为本单套餐单价的整数倍 ——
 * 券按当初攒够时的价位计，与本次用餐价位可以不同
 * （平日 23.90 / 周末 25.90 / 午市 18.90）。
 */
T::eq(0, PE::redeemedPortions(0, [2390]), '没有核销 → 抵 0 份');
T::eq(null, PE::redeemedPortions(2390, []), '本单没有带价的计次套餐 → 无从判断');

// ① 精确整除
T::eq(1, PE::redeemedPortions(2390, [2390]), '23.90 ÷ 23.90 = 1 份');
T::eq(4, PE::redeemedPortions(9560, [2390]), '实测 92600：95.60 ÷ 23.90 = 4 份');
T::eq(3, PE::redeemedPortions(7170, [2390, 1290]),
    '实测 91756：71.70 能被 23.90 整除（12.90 不能）→ 3 份');

// ② 除不尽 → 按最低单价向上取整（免费份数只多不少）
T::eq(2, PE::redeemedPortions(4780, [2590]),
    '★ 实测 90216：9 份全是 25.90，核销 47.80（= 23.90×2）除不尽 → 向上取整 2 份');
T::eq(2, PE::redeemedPortions(2590, [2390]),
    '★ 实测 91095：5 份全是 23.90，核销 25.90 除不尽 → 向上取整 2 份');
T::eq(1, PE::redeemedPortions(1100, [2590]),
    '★ 实测 91863：核销 11.00 任何套餐价都除不尽 → 向上取整 1 份');

// ③ 多个单价都能整除但结果不一致 → 有歧义，走保守回落而不是随便挑一个
T::eq(2, PE::redeemedPortions(2380, [1190, 2380]),
    '★ 23.80 被 11.90 整除得 2、被 23.80 整除得 1 —— 有歧义，按最低价取 2（少给不多给）');

// ④ 向上取整的方向必须是「免费份数变多」
T::true(PE::redeemedPortions(2400, [2390]) >= 1,
    '★ 略高于一份的核销额至少算 1 份免费，不能算 0 份');

T::group('核销识别不依赖 mbstring');

/**
 * 现场事故：Windows 上没开 mbstring，于是每张带折扣行的单
 * 一查就抛 "Call to undefined function Vip\mb_strtoupper()"，
 * 界面只显示「系统内部错误」，查了好几轮才定位到。
 *
 * 核销模式全是 ASCII，本来就不需要多字节转换。
 * 积分算得对不对，不该取决于某个可选扩展装没装。
 */
// 按 token 扫，不能按字符串找 —— 上面那段说明里就写着 mb_strtoupper，
// 用 preg_match 会把注释当成调用（这个坑本项目已经踩过一次）。
$peCalls = [];
foreach (token_get_all((string)file_get_contents(__DIR__ . '/../../app/lib/PointsEngine.php')) as $tk) {
    if (is_array($tk) && $tk[0] === T_STRING && str_starts_with($tk[1], 'mb_')) {
        $peCalls[] = $tk[1];
    }
}
T::eq([], $peCalls,
    '★ PointsEngine 里不出现任何 mb_* 调用（缺扩展时会直接抛 Error）'
    . ($peCalls ? '，实际有：' . implode('、', $peCalls) : ''));
T::true(PE::isRedeemLine('TARJETA 10+1'), 'TARJETA 10+1 是核销');
T::true(PE::isRedeemLine('tarjeta 10+1'), '大小写不敏感');
T::false(PE::isRedeemLine('CUPON DE 5 EUROS'), '★ 满50减5 纸质券不是核销');
T::false(PE::isRedeemLine('Dto. -15%'), '普通折扣不是核销');
T::false(PE::isRedeemLine('MENÚ INFINITY NOCHE'), '带多字节字符不误判也不报错');

// 业务路径上的其它 mb_* 也要有回落
T::true(str_contains((string)file_get_contents(__DIR__ . '/../../app/lib/Repo/AlertRepo.php'), "function_exists('mb_substr')"),
    'AlertRepo 的截断有 mb_substr 缺失回落（告警写入在业务路径上）');

T::group('积分口径 · 按次数（points_mode = by_visit）');

/**
 * 店家可选的第二种玩法：客人看到的不是「87 分」这种要换算的数字，
 * 而是「我来了 3 次」—— 与十送一那张卡上的格子是同一件事，不用解释。
 */
T::eq(1, PE::pointsForVisit(1, 1.0, 1.0), '一次积一分');
T::eq(3, PE::pointsForVisit(3, 1.0, 1.0), 'by_portion 口径下一单 3 次 → 3 分');
T::eq(2, PE::pointsForVisit(1, 1.0, 2.0), '★ 金卡 2 倍 → 来一次积 2 分（倍率照旧叠加）');
T::eq(5, PE::pointsForVisit(1, 5.0, 1.0), '每次 5 分也行（points_per_visit 可配）');
T::eq(7, PE::pointsForVisit(1, 2.5, 3.0), '向下取整：1 × 2.5 × 3 = 7.5 → 7');

/**
 * ★ 没计上次就没有分 —— 这是 by_visit 的定义，不是漏算。
 *   同一餐期第二单不计次，那一单在这个口径下也不积分。
 */
T::eq(0, PE::pointsForVisit(0, 1.0, 1.0), '★★★ 0 次 → 0 分（「一次积一分」的另一半）');
T::eq(0, PE::pointsForVisit(-1, 1.0, 1.0), '负数（撤销流水的形状）也返回 0，不倒扣');

// 两种口径互不影响
T::eq(71, PE::pointsFor(7170, 1.0, 1.0), '按金额那条路没被动过：€71.70 → 71 分');

/**
 * ── 🔴 浮点表示误差：精确值本是整数时少给一分 ──────────
 *
 * 两个函数原来都是 `(int)floor($a * $b * $c)`，三个双精度相乘。
 * 精确结果本该是整数时，浮点会给出比它略小的值，floor 就少一分 ——
 * 丢的不是小数部分，是整数本身。
 *
 * 「向下取整、宁可少给」那条策略说的是小数部分，与这里无关。
 * points_per_euro = 0.29（想设「3.45 € 换 1 分」时算出来就是它）
 * 配上出厂倍率 1.00 就会命中，不需要任何刁钻配置；
 * 方向始终对商家有利、每次只差一分，客人发现不了。
 */
T::eq(29, PE::pointsFor(10000, 0.29, 1.00), '★★★ €100 × 0.29 分/€ × 1.0 = 29 分，不是 28');
T::eq(58, PE::pointsFor(10000, 0.29, 2.00), '  └ 双倍活动：58 分，不是 57');
T::eq(29, PE::pointsFor(10000, 0.25, 1.16), '  └ 等级倍率叠出来的小数同理：29 分，不是 28');
T::eq(21, PE::pointsForVisit(12, 0.70, 2.50), '★★★ 12 次 × 0.70 × 2.5 = 21 分，不是 20');
T::eq(27, PE::pointsForVisit(9, 1.20, 2.50),  '  └ 9 次 × 1.20 × 2.5 = 27 分，不是 26');
T::eq(42, PE::pointsForVisit(12, 1.40, 2.50), '  └ 12 次 × 1.40 × 2.5 = 42 分，不是 41');

/**
 * 穷举一遍，确认不是只补了这几个点。
 * 精确值用整数算（分 × 分率×100 × 倍率×100 ÷ 1e6），与被测函数没有共用代码。
 */
$bad = 0;
for ($c = 1; $c <= 12000; $c += 271) {          // 金额（分）
    for ($r = 1; $r <= 200; $r += 3) {          // 每欧元分数 ×100
        for ($m = 25; $m <= 300; $m += 25) {    // 倍率 ×100
            if (PE::pointsFor($c, $r / 100, $m / 100) !== intdiv($c * $r * $m, 1000000)) {
                $bad++;
            }
        }
    }
}
T::eq(0, $bad, '★★ pointsFor 穷举 ' . (45 * 67 * 12) . ' 组配置，与整数精确值零偏差');

$bad = 0;
for ($v = 1; $v <= 40; $v++) {
    for ($r = 1; $r <= 200; $r += 3) {
        for ($m = 25; $m <= 300; $m += 25) {
            if (PE::pointsForVisit($v, $r / 100, $m / 100) !== intdiv($v * $r * $m, 10000)) {
                $bad++;
            }
        }
    }
}
T::eq(0, $bad, '★★ pointsForVisit 穷举 ' . (40 * 67 * 12) . ' 组配置，零偏差');

// 真·向下取整的那一半没被改掉：小数部分该丢还是丢
T::eq(7, PE::pointsForVisit(1, 2.50, 3.00), '  └ 1 × 2.5 × 3 = 7.5，仍然给 7（策略没变）');
T::eq(0, PE::pointsFor(50, 1.0, 1.0),       '  └ 0.50 € 仍然给 0 分');
