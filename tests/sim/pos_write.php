<?php
declare(strict_types=1);

/**
 * 模拟 POS 结账写单 —— 不依赖那份没随仓库分发的真实导出。
 *
 * ── 为什么需要它 ────────────────────────────────────────
 *
 * 同目录的 inject_live.php 是把【真实历史订单】克隆到当下，效果最好
 * （真单自带伪行、配料行、退菜、折扣、分单），但它要求库里先有那份
 * history_order_head/detail 的导出 —— 而那份数据【不在仓库里】
 * （pdb/ 只有 100 行明细样本）。没有它时 inject_live.php 一张也造不出来。
 *
 * 这个脚本是最小可用替代：够跑通「按桌号查已买单的桌 → 记账 → 发券 →
 * 核销」这条主链路，也够验证 mysqli → PosDb → PosReader 的真实链路。
 * 它【造不出】多 check 分单、订单级折扣、-2 核销伪行那几种脏数据，
 * 所以 e2e_pos.php 里依赖那些形状的断言仍然需要真实导出。
 *
 * ★ 本脚本模拟的是【POS 自己在写单】，因此用管理员账号直连模拟主库。
 *   它不属于旁路系统的一部分，生产环境永远不会执行。
 *   旁路系统自身仍然只有 pos_ro 只读账号。
 *
 * 用法：
 *   SIM_USER=sim_admin SIM_PASS=... \
 *   php tests/sim/pos_write.php <桌号> <几分钟前结账> [eat_type] [几份套餐] [order_head_id]
 *
 * 例：
 *   php tests/sim/pos_write.php 30 3          桌 30，3 分钟前买单，堂食 2 份
 *   php tests/sim/pos_write.php Llevar 5 3    外带（eat_type=3，Pad 不该定位到）
 *   php tests/sim/pos_write.php 31 45         45 分钟前 —— 超出默认 30 分钟窗口
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$dsn  = getenv('SIM_DSN')  ?: 'mysql:host=127.0.0.1;port=3306;dbname=sim_coolroid;charset=utf8';
$user = getenv('SIM_USER') ?: 'sim_admin';
$pass = getenv('SIM_PASS') ?: '';

$pdo = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    // 金额列字符串直进直出，别让 double 落进 PHP float（见 inject_live.php 的说明）
    PDO::ATTR_STRINGIFY_FETCHES  => true,
]);

$table = (string)($argv[1] ?? '30');
$mins  = (int)($argv[2] ?? 5);
$eat   = (int)($argv[3] ?? 0);      // 0=堂食（唯一可积分） 3=外带
$qty   = (int)($argv[4] ?? 2);      // 几份计次套餐
$ohid  = (int)($argv[5] ?? 0);

if ($ohid === 0) {
    // 900000 起，与真实数据（最大 ~92xxx）不重叠，方便整批清理
    $ohid = max(900000, (int)$pdo->query(
        'SELECT COALESCE(MAX(order_head_id),900000)+1 FROM history_order_head')->fetchColumn());
}
$serial = (int)$pdo->query(
    'SELECT COALESCE(MAX(serial_id),9000000)+1 FROM history_order_head')->fetchColumn();

// 2390 = MENÚ INFINITY NOCHE，出厂套餐规则里计次的那一项
const MENU_ITEM = 2390;
const PRICE     = 23.90;

$amt   = number_format(PRICE * $qty, 2, '.', '');
$end   = date('Y-m-d H:i:s', time() - $mins * 60);
$start = date('Y-m-d H:i:s', time() - ($mins + 45) * 60);
$tax   = number_format((float)$amt * 0.1 / 1.1, 2, '.', '');

$pdo->prepare(
    'INSERT INTO history_order_head
       (serial_id, order_head_id, check_number, rvc_center_id, rvc_center_name,
        table_id, table_name, check_id, open_employee_id, open_employee_name,
        customer_num, customer_id, pos_device_id, pos_name,
        order_start_time, order_end_time, should_amount, return_amount, discount_amount,
        actual_amount, print_count, status, eat_type, original_amount, service_amount,
        edit_time, tax_amount, second_tax_amount)
     VALUES (?,?,1,1,\'SALA\',1,?,1, 5,\'CAMARERO\', ?,0,1,\'POS1\',
             ?,?, ?,0.00,0.00, ?, 1,1, ?, ?,0.00, ?, ?,0.00)'
)->execute([$serial, $ohid, $table, $qty, $start, $end, $amt, $amt, $eat, $amt, $end, $tax]);

$did = (int)$pdo->query(
    'SELECT COALESCE(MAX(order_detail_id),9000000)+1 FROM history_order_detail')->fetchColumn();
$pdo->prepare(
    'INSERT INTO history_order_detail
       (order_detail_id, order_head_id, check_id, menu_item_id, menu_item_name,
        product_price, is_discount, original_price, discount_id, actual_price,
        is_return_item, order_employee_id, order_employee_name, pos_device_id, pos_name,
        order_time, quantity, eat_type, condiment_belong_item)
     VALUES (?,?,1,?,\'MENU INFINITY NOCHE\', ?,0,?,0,?, 0,5,\'CAMARERO\',1,\'POS1\', ?,?,?,0)'
)->execute([$did, $ohid, MENU_ITEM, PRICE, PRICE, $amt, $start, $qty, $eat === 0 ? 1 : $eat]);

printf("已写入：桌「%s」 serial=%d order_head_id=%d eat_type=%d 结账=%s（%d 分钟前）€%s %d 份\n",
    $table, $serial, $ohid, $eat, $end, $mins, $amt, $qty);
