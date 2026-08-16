<?php
declare(strict_types=1);

/**
 * 模拟环境专用：把历史真实订单「克隆到当下」，制造 Pad 可定位的活单。
 *
 * 为什么要克隆而不是造假数据：
 *   真实订单里带着全部脏东西 —— 伪行（-3 备注 / -4 支付）、配料行、
 *   退菜行、套餐内 0 元菜、外带 eat_type=3、订单级折扣。
 *   手写夹具永远想不全，克隆真单能把这些一次性全带上。
 *
 * ★ 本脚本【模拟 POS 自己在写单】，因此使用管理员账号直连模拟主库。
 *   它不属于旁路系统的一部分，生产环境永远不会执行。
 *   旁路系统自身仍然只有 pos_ro 只读账号，写权限在数据库层被拒绝。
 *
 * 用法：
 *   SIM_DSN='mysql:host=127.0.0.1;port=3306;dbname=sim_coolroid;charset=utf8' \
 *   SIM_USER=root SIM_PASS='' php tests/sim/inject_live.php [--clean]
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

const OHID_BASE = 900000;   // 克隆单的 order_head_id 起点，与真实数据（最大 ~92xxx）不重叠

$dsn  = getenv('SIM_DSN')  ?: 'mysql:host=127.0.0.1;port=3306;dbname=sim_coolroid;charset=utf8';
$user = getenv('SIM_USER') ?: 'root';
$pass = getenv('SIM_PASS') ?: '';

$pdo = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
    // ★ 必须取字符串，不能让 double 落进 PHP float。
    // 真实数据里 sales_amount 存了哨兵值 -9999999999999.99（double(15,2) 的极限），
    // 一旦变成 PHP float 再转回字符串就成了 -1.0E+13 = 14 位整数位 → 溢出报 1264。
    // 金额类列同理，字符串直进直出才不丢精度。
    PDO::ATTR_STRINGIFY_FETCHES  => true,
]);

// ── 清理 ──────────────────────────────────────────────────
$pdo->exec('DELETE FROM history_order_head   WHERE order_head_id >= ' . OHID_BASE);
$pdo->exec('DELETE FROM history_order_detail WHERE order_head_id >= ' . OHID_BASE);
if (in_array('--clean', $argv, true)) {
    echo "已清除全部克隆活单\n";
    exit(0);
}

/**
 * 要克隆的真实订单，以及各自考察的场景。
 * [源 order_head_id, 源 check_id 或 null=全部 check, 新桌号, 距今多少分钟结账, 场景说明]
 */
$plan = [
    [92268, 1,    '30',     3,  '常规堂食：伪行 + 配料行（该单明细缺套餐行，见下方说明）'],
    [92271, 1,    '52',     6,  '含退菜行（3 行 return_time 非空）'],
    [92285, 1,    '15',     9,  '订单级折扣 -10.00，须按比例扣除'],
    [92283, 1,    'Llevar', 12, '外带 eat_type=3 —— 不应被 Pad 定位到'],
    [9974,  null, '15',     15, '分单 AA：2 张 check，其中一张 actual=0'],
    [9978,  null, '23',     18, '分单 AA：4 张 check'],
    [92262, 1,    '10',     21, '大单 46 行明细、12 个伪行'],
    // ↓ 真正带餐费项（套餐行）的单，用来验证计次与十送一
    [10069, 1,    '40',     24, '10 人 10 份 MENÚ 2390，且订单有折扣 → 计次应为 10'],
    [10094, 1,    '9',      27, '成人 2390 + 儿童 1490 混点 → 验证儿童套餐开关'],
    [92293, 1,    '21',     30, '9 人 5 份套餐，should 与 actual 差额巨大'],
];

/*
 * ★ 关于「明细缺行」：用户提供的 history_order_detail 导出是【抽样】而非全量 ——
 *   2026 批次 1,694 行只覆盖 order_detail_id 区间的 76%。
 *   因此少数订单（如 92268）在库里没有套餐行，其
 *   SUM(actual_price) 对不上 original_amount。这是导出件的性质，不是 POS 的行为：
 *   剔除退菜行后恒等式在 237/240 单上成立，3 个例外全部落在缺行的单上。
 *   计次依赖套餐行，所以上面额外挑了 10069 / 10094 / 92293 这三张完整的单。
 */

/**
 * 列清单 + 取数表达式。
 * ★ bit(1) 列必须用 `col+0` 取，否则 PDO 拿到的是原始二进制字节，
 *   再写回去会报 1406 Data too long。这与 PosReader 对
 *   is_discount / is_return_item 的处理是同一个坑。
 */
$describe = function (string $table) use ($pdo): array {
    $cols = $pdo->query('SHOW COLUMNS FROM `' . $table . '`')->fetchAll();
    $names = $exprs = $binds = [];
    foreach ($cols as $c) {
        $type    = strtolower((string)$c['Type']);
        $isBit   = str_starts_with($type, 'bit(');
        // ★ double / float 必须在【服务端】转成字符串。
        // STRINGIFY_FETCHES 是在驱动把值转成 PHP float【之后】才生效的，
        // 救不回精度：真实数据里的哨兵 -9999999999999.99 会先被 float 吃成
        // -1.0E+13，写回去就溢出。CAST(... AS CHAR) 才拿得到原样的十进制串。
        // （DECIMAL 不受影响 —— MySQL 本来就按文本传，这也正是金额列一律用
        //   DECIMAL、PHP 侧一律走整数分的原因。）
        $isFloat = str_starts_with($type, 'double') || str_starts_with($type, 'float');
        $names[] = $c['Field'];
        $exprs[] = match (true) {
            $isBit   => '`' . $c['Field'] . '`+0 AS `' . $c['Field'] . '`',
            $isFloat => 'CAST(`' . $c['Field'] . '` AS CHAR) AS `' . $c['Field'] . '`',
            default  => '`' . $c['Field'] . '`',
        };
        // 写回也要 +0：PDO 关掉模拟预处理后一律按【字符串】绑定，
        // 而 MariaDB 会把字符串 "0"（0x30）当成 8 位的位串 → bit(1) 装不下，
        // 报 1406 Data too long。`?+0` 让服务端先当数值算，绕开二进制协议这一层。
        $binds[] = $isBit ? '?+0' : '?';
    }
    return [$names, implode(',', $exprs), implode(',', $binds)];
};
[$headCols, $headSel, $headBind] = $describe('history_order_head');
[$detCols,  $detSel,  $detBind]  = $describe('history_order_detail');

$mkInsert = fn(string $table, array $cols, string $binds): string =>
    'INSERT INTO `' . $table . '` (`' . implode('`,`', $cols) . '`) VALUES (' . $binds . ')';
$insHead = $pdo->prepare($mkInsert('history_order_head',   $headCols, $headBind));
$insDet  = $pdo->prepare($mkInsert('history_order_detail', $detCols,  $detBind));

$now      = new DateTimeImmutable((string)$pdo->query('SELECT NOW()')->fetchColumn());
$newOhid  = OHID_BASE;
$injected = [];
// order_detail_id 由 POS 自己分配（NOT NULL、非自增），克隆时必须另起号段
$nextDetailId = ((int)$pdo->query('SELECT MAX(order_detail_id) FROM history_order_detail')->fetchColumn()) + 1000000;

foreach ($plan as [$srcOhid, $srcCheck, $newTable, $minsAgo, $desc]) {
    $newOhid++;
    $end   = $now->modify("-{$minsAgo} minutes");
    // 开台时间按原单的用餐时长回推，保持真实的「开台→结账」间隔
    $sql   = 'SELECT ' . $headSel . ' FROM history_order_head WHERE order_head_id = ?'
           . ($srcCheck !== null ? ' AND check_id = ?' : '') . ' ORDER BY check_id';
    $st    = $pdo->prepare($sql);
    $st->execute($srcCheck !== null ? [$srcOhid, $srcCheck] : [$srcOhid]);
    $heads = $st->fetchAll();
    if (!$heads) {
        fwrite(STDERR, "源订单 {$srcOhid} 不存在，跳过\n");
        continue;
    }

    // 该单最晚的结账时间，作为整体平移的基准
    $srcEndMax = max(array_map(fn($h) => strtotime((string)$h['order_end_time']), $heads));
    $shift     = $end->getTimestamp() - $srcEndMax;

    $serials = [];
    foreach ($heads as $h) {
        $row = $h;
        $row['order_head_id']    = $newOhid;
        $row['table_name']       = $newTable;
        $row['table_id']         = is_numeric($newTable) ? (int)$newTable : 0;
        // serial_id 重新编号：YYMMDD + 4 位，保证与真实数据不撞
        $row['serial_id']        = (int)($end->format('ymd') . str_pad((string)(($newOhid % 1000) + (int)$h['check_id']), 4, '0', STR_PAD_LEFT));
        $row['order_start_time'] = date('Y-m-d H:i:s', strtotime((string)$h['order_start_time']) + $shift);
        $row['order_end_time']   = date('Y-m-d H:i:s', strtotime((string)$h['order_end_time'])   + $shift);
        if (!empty($h['edit_time'])) {
            $row['edit_time'] = date('Y-m-d H:i:s', strtotime((string)$h['edit_time']) + $shift);
        }
        $serials[] = $row['serial_id'];
        $insHead->execute(array_map(fn($c) => $row[$c], $headCols));

        // 明细同步克隆
        $ds = $pdo->prepare('SELECT ' . $detSel . ' FROM history_order_detail WHERE order_head_id=? AND check_id=?');
        $ds->execute([$srcOhid, (int)$h['check_id']]);
        $nDet = 0;
        foreach ($ds->fetchAll() as $d) {
            $d['order_head_id'] = $newOhid;
            $d['order_detail_id'] = $nextDetailId++;
            if (!empty($d['order_time'])) {
                $d['order_time'] = date('Y-m-d H:i:s', strtotime((string)$d['order_time']) + $shift);
            }
            if (!empty($d['return_time'])) {
                $d['return_time'] = date('Y-m-d H:i:s', strtotime((string)$d['return_time']) + $shift);
            }
            $insDet->execute(array_map(fn($c) => $d[$c], $detCols));
            $nDet++;
        }
    }

    $injected[] = [
        'ohid'    => $newOhid,
        'table'   => $newTable,
        'checks'  => count($heads),
        'serials' => $serials,
        'minsAgo' => $minsAgo,
        'desc'    => $desc,
        'src'     => $srcOhid,
    ];
    printf("  ✓ ohid=%d  桌号 %-7s %d 张 check  %2d 分钟前结账  ← 源 %d  %s\n",
        $newOhid, $newTable, count($heads), $minsAgo, $srcOhid, $desc);
}

echo "\n共注入 " . count($injected) . " 单活单\n";
file_put_contents(
    __DIR__ . '/live_orders.json',
    json_encode($injected, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
);
echo "清单已写入 tests/sim/live_orders.json\n";
