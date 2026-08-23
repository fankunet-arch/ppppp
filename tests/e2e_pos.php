<?php
declare(strict_types=1);

/**
 * 端到端测试 —— 同时接【真实 POS 主库】与【真实本地库】。
 *
 * 与 tests/smoke.php 的分工：
 *   smoke.php  注入 FakePosSource，不需要 POS 可达，验证记账语义与不变量。
 *   本文件     走真正的 mysqli → PosDb → PosReader 链路，验证
 *              smoke 永远测不到的东西：
 *                · 只读边界在【数据库层】确实拒绝写
 *                · 每条 POS 查询确实命中索引（POS 主机性能极度受限，
 *                  一次全表扫就可能在营业高峰拖垮收银）
 *                · 真实脏数据（伪行 / 配料行 / 退菜行 / 外带 / 分单）
 *                  在真链路上被正确处理
 *
 * 前置：先执行 tests/sim/inject_live.php 注入活单。
 *
 * 用法：
 *   php tests/e2e_pos.php
 * 环境变量（可选，默认读 app/config/config.php）：
 *   E2E_STORE  测试用门店码，默认 E2E（与生产数据隔离，跑完自动清理）
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../app/lib/App.php';
spl_autoload_register(function (string $c): void {
    if (!str_starts_with($c, 'Vip\\')) {
        return;
    }
    $f = __DIR__ . '/../app/lib/' . str_replace('\\', '/', substr($c, 4)) . '.php';
    if (is_file($f)) {
        require $f;
    }
});

use Vip\App;
use Vip\Money;
use Vip\PointsEngine;

$E2E_STORE = getenv('E2E_STORE') ?: 'E2E';

// ── 断言框架 ─────────────────────────────────────────────
$pass = 0;
$fails = [];
$C = fn(string $s, string $c) => "\033[{$c}m{$s}\033[0m";

function head(string $s): void { echo "\n\033[1m{$s}\033[0m\n"; }
function ok_(string $m): void  { global $pass, $C; $pass++; echo '  ' . $C('✓', '32') . " {$m}\n"; }
function bad_(string $m, string $detail = ''): void {
    global $fails, $C;
    $fails[] = $m . ($detail !== '' ? "  —— {$detail}" : '');
    echo '  ' . $C('✗', '31') . " {$m}" . ($detail !== '' ? "  \033[90m{$detail}\033[0m" : '') . "\n";
}
function is_(bool $cond, string $m, string $detail = ''): void { $cond ? ok_($m) : bad_($m, $detail); }
function eq_(mixed $exp, mixed $got, string $m): void {
    $exp === $got ? ok_($m) : bad_($m, ' 期望 ' . var_export($exp, true) . '，实得 ' . var_export($got, true));
}

// ── 启动 ─────────────────────────────────────────────────
$cfgPath = __DIR__ . '/../app/config/config.php';
if (!is_file($cfgPath)) {
    fwrite(STDERR, "缺少 app/config/config.php\n");
    exit(1);
}
$cfg = require $cfgPath;
$app = new App($cfg);
$app->setStoreCode($E2E_STORE);
$db     = $app->localDb();
$pos    = $app->posReader();
echo "\033[1mE2E · 真实 POS 主库 + 真实本地库\033[0m\n";
echo "  门店码 {$E2E_STORE}   POS {$cfg['pos_db']['host']}:{$cfg['pos_db']['port']}/{$cfg['pos_db']['database']}"
   . "   本地 {$cfg['local_db']['database']}\n";

// 清理上一轮
$cleanup = function () use ($db, $E2E_STORE): void {
    foreach (['point_ledger', 'pos_order', 'audit_log', 'alert', 'sync_cursor', 'coupon',
              'card', 'member', 'meal_item_rule', 'sys_config'] as $t) {
        try { $db->exec("DELETE FROM `{$t}` WHERE store_code = ?", [$E2E_STORE]); } catch (\Throwable) {}
    }
};
$cleanup();

/**
 * 建会员的测试辅助 —— 改发实体卡后，会员必须绑一张 card 库存表里真实
 * 存在的卡。预生成一批按需取用，让不关心卡片的用例保持原样。
 */
$e2eCardPool = [];
$newMember = static function (?string $phone = null, ?string $email = null, ?string $bday = null)
        use ($app, &$e2eCardPool): array {
    if (!$e2eCardPool) {
        $e2eCardPool = $app->cards()->generateBatch('E2EPOOL', 20);
    }
    $c = array_shift($e2eCardPool);
    $r = $app->cardService()->bindNewMember(
        $c['card_no'], $phone, $email, $bday,
        ['id' => null, 'name' => 'e2e', 'device' => null]
    );
    if (!$r['ok']) {
        throw new \RuntimeException('测试辅助建会员失败：' . ($r['error'] ?? '?'));
    }
    return $r['member'];
};

// 套餐规则与配置按门店隔离，E2E 门店要自带一份，否则 MealRules 全部
// 回落到安全默认（counts_visit=false），计次恒为 0，测不出真实行为。
$srcStore = (string)($cfg['store_code'] ?? 'S001');
$db->exec('DELETE FROM meal_item_rule WHERE store_code = ?', [$E2E_STORE]);
$db->exec('INSERT INTO meal_item_rule
             (store_code, menu_item_id, item_name, ref_price, is_meal_fee, counts_visit,
              earns_points, enabled, updated_at)
           SELECT ?, menu_item_id, item_name, ref_price, is_meal_fee, counts_visit,
                  earns_points, enabled, updated_at
             FROM meal_item_rule WHERE store_code = ?', [$E2E_STORE, $srcStore]);
$db->exec('DELETE FROM sys_config WHERE store_code = ?', [$E2E_STORE]);
$db->exec('INSERT INTO sys_config (store_code, config_key, config_value, updated_at)
           SELECT ?, config_key, config_value, updated_at
             FROM sys_config WHERE store_code = ?', [$E2E_STORE, $srcStore]);
$nRules = (int)$db->value('SELECT COUNT(*) FROM meal_item_rule WHERE store_code=?', [$E2E_STORE]);
echo "  已从门店 {$srcStore} 复制 {$nRules} 条套餐规则到 {$E2E_STORE}\n";

// ★ 必须在规则复制【之后】才构造 —— MealRules 是构造时一次性读进内存的，
//   提前构造会捕获到空规则集，counts_visit 全部回落成 false，计次恒为 0。
$points = $app->points();
$member = $app->members();

// ══════════════════════════════════════════════════════════
head('① 只读边界 —— 数据库层必须拒绝写');

$posDb = $app->posDb();
foreach ([
    'UPDATE history_order_head SET status=9 WHERE order_head_id=1' => 'UPDATE',
    'DELETE FROM history_order_head WHERE order_head_id=1'         => 'DELETE',
    'INSERT INTO history_order_head (serial_id) VALUES (1)'        => 'INSERT',
    'DROP TABLE history_order_head'                                => 'DROP',
    'SELECT 1; DROP TABLE history_order_head'                      => '多语句拼接',
    'SELECT * FROM history_order_head'                             => '无 LIMIT 的 SELECT',
] as $sql => $label) {
    try {
        $posDb->select($sql, []);
        bad_("PosDb 应拒绝：{$label}", '竟然执行成功');
    } catch (\Throwable $e) {
        ok_("PosDb 拒绝 {$label}");
    }
}

// 绕过 PosDb 的应用层护栏，直接用只读账号打到服务端，验证权限本身
// PHP 8.1+ 起 mysqli 默认抛异常而非返回 false，两种形态都要当成「被拒绝」
$rawWriteDenied = function (string $sql) use ($cfg): bool {
    try {
        $m = new \mysqli(
            $cfg['pos_db']['host'], $cfg['pos_db']['user'], $cfg['pos_db']['password'],
            $cfg['pos_db']['database'], (int)$cfg['pos_db']['port']
        );
    } catch (\Throwable) {
        return false;   // 连不上不算「被拒绝」，避免假阳性
    }
    try {
        $denied = ($m->query($sql) === false);
    } catch (\mysqli_sql_exception) {
        $denied = true;
    } finally {
        $m->close();
    }
    return $denied;
};
is_($rawWriteDenied('UPDATE history_order_head SET status=9 WHERE order_head_id=1'),
    '★ 只读账号在【数据库层】被拒绝写入（不依赖应用层自觉）');
is_($rawWriteDenied('CREATE TABLE e2e_probe(a int)'), '★ 只读账号无法建表');

// ══════════════════════════════════════════════════════════
head('② 索引命中 —— POS 主机性能极度受限，任何全表扫都不可接受');

$explain = function (string $sql) use ($cfg): array {
    $m = new \mysqli(
        $cfg['pos_db']['host'], $cfg['pos_db']['user'], $cfg['pos_db']['password'],
        $cfg['pos_db']['database'], (int)$cfg['pos_db']['port']
    );
    $res = $m->query('EXPLAIN ' . $sql);
    $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    $m->close();
    return $rows;
};
$noFullScan = function (string $label, string $sql) use ($explain): void {
    $rows = $explain($sql);
    if (!$rows) {
        bad_("EXPLAIN 失败：{$label}");
        return;
    }
    foreach ($rows as $r) {
        $type = strtoupper((string)($r['type'] ?? ''));
        $key  = (string)($r['key'] ?? '');
        if ($type === 'ALL' || $key === '') {
            bad_("{$label} 未命中索引", "type={$type} key=" . ($key ?: '(无)') . " rows={$r['rows']}");
            return;
        }
    }
    ok_("{$label} 命中索引 " . $rows[0]['key'] . "（type=" . $rows[0]['type'] . '）');
};
$noFullScan('findRecentByTable（Pad 定位）',
    "SELECT serial_id, order_head_id, check_id, table_name, eat_type, customer_num,
            original_amount, should_amount, actual_amount, order_end_time
     FROM history_order_head
     WHERE order_end_time >= NOW() - INTERVAL 30 MINUTE AND table_name='30' AND eat_type=0
     ORDER BY order_end_time DESC LIMIT 20");
$noFullScan('fetchSince（增量补抓）',
    "SELECT serial_id, order_head_id, check_id FROM history_order_head
     WHERE order_end_time >= '2026-08-13 00:00:00' AND order_end_time < '2026-08-15 00:00:00'
     ORDER BY order_end_time ASC, order_head_id ASC, check_id ASC LIMIT 100 OFFSET 0");
$noFullScan('reloadAmounts（值比对回读）',
    "SELECT serial_id, original_amount, should_amount, actual_amount, edit_time
     FROM history_order_head WHERE order_head_id=900001 AND check_id=1 LIMIT 1");
$noFullScan('fetchDetail（明细读取）',
    "SELECT menu_item_id, quantity, actual_price FROM history_order_detail
     WHERE order_head_id=900001 AND check_id=1 AND menu_item_id>0 AND condiment_belong_item=0 LIMIT 100");
$noFullScan('countInRange（完整性监控）',
    "SELECT COUNT(*) AS c FROM history_order_head
     WHERE order_end_time >= '2026-08-13 00:00:00' AND order_end_time < '2026-08-14 00:00:00' LIMIT 1");

// ══════════════════════════════════════════════════════════
head('③ 订单定位 —— 真实脏数据');

$liveFile = __DIR__ . '/sim/live_orders.json';
if (!is_file($liveFile)) {
    bad_('缺少 tests/sim/live_orders.json', '请先执行 tests/sim/inject_live.php');
    goto finish;
}
$live = json_decode((string)file_get_contents($liveFile), true);

$byTable = [];
foreach ($live as $l) {
    $byTable[$l['table']][] = $l;
}

/**
 * ★ 夹具保鲜检查 —— 必须在跑断言之前。
 *
 * 下面的定位断言全都走 findRecentByTable(..., 30, ...)，即
 * `order_end_time >= NOW() - INTERVAL 30 MINUTE`。
 * inject_live.php 把订单克隆到「注入那一刻的前几分钟」，所以夹具只有
 * 大约半小时的保质期 —— 注入完隔一小时再跑，订单就整批掉出窗口。
 *
 * 不检查的话，表现是「桌号 23 期望 4 实得 0」这种红字，看上去像聚合逻辑
 * 出了 bug，实际只是夹具过期。为这个白查一遍代码非常费时间，所以宁可
 * 在这里花一条查询把话说清楚。
 */
/**
 * 只看定位断言真正依赖的这几张桌 —— 不能拿「所有注入单里最老的一张」来判。
 * 注入器故意把最老的一单放在正好 30 分钟前（桌 21，用于更宽窗口的补抓测试），
 * 一刀切会让本检查在刚注入完时就误报过期。
 *
 * 桌 Llevar 也要在窗口内：它的断言是「取回 0 行」，一旦老出窗口，
 * 这条断言会因为夹具没了而通过 —— 通过得毫无意义，比失败更糟。
 */
$needFresh = ['30', '23', '15', 'Llevar'];
$staleMsg  = [];
foreach ($needFresh as $t) {
    $a = $posDb->select(
        'SELECT TIMESTAMPDIFF(MINUTE, MIN(order_end_time), NOW()) AS age
           FROM history_order_head
          WHERE order_head_id >= 900000 AND table_name = ? LIMIT 1', [$t], 's');
    $age = $a[0]['age'] ?? null;
    if ($age === null || (int)$age >= 30) {
        $staleMsg[] = $age === null ? "桌 {$t} 未注入" : "桌 {$t} 已 {$age} 分钟";
    }
}
if ($staleMsg) {
    bad_('模拟活单已过期：' . implode('，', $staleMsg) . '，超出 30 分钟定位窗口',
        "这不是代码问题，是夹具过期 —— 注入后约 12 分钟内有效。请重新注入再跑：\n"
      . "        SIM_USER=sim_admin SIM_PASS=... php tests/sim/inject_live.php");
    goto finish;
}

$rows30 = $pos->findRecentByTable('30', 30, 20);
is_(count($rows30) >= 1, '桌号 30 能定位到活单');
$agg30 = PointsEngine::aggregateCandidates($rows30);
eq_(1, count($agg30), '桌号 30 聚合为 1 张订单');

// 外带必须定位不到
$rowsLlevar = $pos->findRecentByTable('Llevar', 30, 20);
eq_(0, count($rowsLlevar), '★ 外带订单（eat_type=3）不会被 Pad 定位到');

// 分单：4 张 check 必须聚合成 1 张订单
$rows23 = $pos->findRecentByTable('23', 30, 20);
$agg23  = PointsEngine::aggregateCandidates($rows23);
eq_(4, count($rows23), '桌号 23 取回 4 行 head（4 张 check）');
eq_(1, count($agg23),  '★ 4 张 check 聚合为 1 张订单（AA 分单不会被当成 4 单）');
// 上一条断言失败时 reset() 会返回 false —— 直接下标取值会抛 TypeError，
// 把后面几十条断言全打断，反而看不出问题范围。取不到就跳过这两条。
$o23 = $agg23 ? reset($agg23) : null;
if ($o23 === null) {
    bad_('桌号 23 聚合结果为空，跳过其明细断言');
} else {
    eq_(4, count($o23['check_ids']), '聚合结果保留了全部 4 个 check_id');
    eq_('75.86', Money::toStr($o23['actual_cents']), '★ 分单金额按 check 累加 = 75.86');
}

// 同桌翻台：两张不同订单必须分开
$rows15 = $pos->findRecentByTable('15', 30, 20);
$agg15  = PointsEngine::aggregateCandidates($rows15);
eq_(2, count($agg15), '★ 同桌两张不同订单不会被误合并（翻台场景）');

// 聚合后的 serial_id 必须稳定 —— 与排序无关
$shuffled = $rows23;
usort($shuffled, fn($a, $b) => strcmp((string)$b['order_end_time'], (string)$a['order_end_time']));
$aggAsc  = PointsEngine::aggregateCandidates($rows23);
$aggDesc = PointsEngine::aggregateCandidates($shuffled);
eq_(reset($aggAsc)['serial_id'], reset($aggDesc)['serial_id'],
    '★ 聚合出的 serial_id 与行序无关（否则 Pad 与 Cron 两条路径会各存一个键，同单发两次分）');

// ══════════════════════════════════════════════════════════
head('③bis 按小票号查单 —— Factura Simplificada = order_head_id');

// 用注入的活单反查：先按桌号拿到 ohid，再用它当小票号查
$rowsInv = $pos->findRecentByTable('30', 30, 20);
$ohidInv = (int)($rowsInv[0]['order_head_id'] ?? 0);
if ($ohidInv <= 0) {
    bad_('桌 30 未定位到订单，无法测小票号查单');
} else {
    $byInv = $pos->findByInvoice($ohidInv);
    is_(count($byInv) >= 1, "小票号 {$ohidInv} 能查到订单");
    eq_($ohidInv, (int)$byInv[0]['order_head_id'], '★ 小票号即 order_head_id，精确命中');

    // 分单单：4 张 check 必须一次全取回
    $rows23i = $pos->findRecentByTable('23', 30, 20);
    $ohid23  = (int)($rows23i[0]['order_head_id'] ?? 0);
    eq_(4, count($pos->findByInvoice($ohid23)),
        '★ 分单的 4 张 check 一次全取回（不像按桌号还要拼窗口）');

    // 外带：按桌号查不到，按小票号必须查得到
    $rowsLl = $pos->findRecentByTable('Llevar', 30, 20);
    eq_(0, count($rowsLl), '外带按桌号查不到（eat_type 过滤）');
    // 直接问 POS 要一张外带单，不依赖本地 pos_order（此刻尚未补抓）
    $llRows = $pos->fetchSince(
        date('Y-m-d H:i:s', strtotime($pos->now()) - 86400),
        date('Y-m-d H:i:s', strtotime($pos->now()) + 60), 100, 0);
    $ohidLl = 0;
    foreach ($llRows as $r) {
        if ((int)$r['eat_type'] !== 0) { $ohidLl = (int)$r['order_head_id']; break; }
    }
    if ($ohidLl > 0) {
        is_(count($pos->findByInvoice($ohidLl)) >= 1,
            '★ 外带单按小票号【查得到】—— 好让收银员看到「外带不积分」而不是「查无此单」');
        $liLl = $points->locateByInvoice($ohidLl);
        $cLl  = $liLl['candidates'][0] ?? null;
        is_($cLl !== null && $cLl['eligible'] === false, '外带单可查但不可积分');
        eq_('not_dine_in', $cLl['ineligible_reason'] ?? '', '★ 给出「外带不积分」而不是「查无此单」');
    } else {
        bad_('未找到外带单用于验证', '注入活单里应有一张 Llevar');
    }

    // 服务层：定位 → 可发分判定
    $li = $points->locateByInvoice($ohidInv);
    is_(($li['ok'] ?? false) === true, 'locateByInvoice 成功', json_encode($li, JSON_UNESCAPED_UNICODE));
    eq_(1, count($li['candidates']), '返回 1 张订单');
    $ci = $li['candidates'][0];
    eq_($ohidInv, (int)$ci['order_head_id'], '候选订单就是该小票号对应的单');

    // 与按桌号查到的必须是同一张单、同样的金额与份数
    $lt = $points->locate('30', 30);
    $ct = $lt['candidates'][0] ?? null;
    if ($ct !== null) {
        eq_($ct['serial_id'],        $ci['serial_id'],        '★ 两条查法定位到同一个幂等键');
        eq_($ct['total_cents'],      $ci['total_cents'],      '★ 两条查法算出同样的可积分金额');
        eq_($ct['portions_counted'], $ci['portions_counted'], '★ 两条查法算出同样的计次份数');
    }

    // 不存在的号
    $none = $points->locateByInvoice(99999999);
    eq_('not_found', $none['reason'] ?? '', '不存在的小票号返回 not_found');
    // 非法号
    $bad = $points->locateByInvoice(0);
    eq_('bad_invoice', $bad['reason'] ?? '', '小票号为 0 返回 bad_invoice');

    // 回溯天数上限：直接从主库拿一张远早于上限的历史单
    $maxDays = $app->cfg()->int('invoice_lookup_max_days', 7);
    $oldFrom = date('Y-m-d H:i:s', strtotime($pos->now()) - ($maxDays + 60) * 86400);
    $oldRows = $pos->fetchSince($oldFrom, date('Y-m-d H:i:s', strtotime($oldFrom) + 86400), 10, 0);
    if ($oldRows) {
        $tooOld = $points->locateByInvoice((int)$oldRows[0]['order_head_id']);
        eq_('too_old', $tooOld['reason'] ?? '',
            "★ 超过 {$maxDays} 天的小票返回 too_old（防止拿半年前的小票来领分）");
        eq_(0, count($tooOld['candidates']), '超期时不返回任何候选');
    } else {
        bad_('未找到超期历史单用于验证');
    }
}

// ══════════════════════════════════════════════════════════
head('④ 明细分析 —— 伪行 / 配料行 / 退菜行');

$detail = $pos->fetchDetail(900001, 1);
is_(count($detail) > 0, '读到明细 ' . count($detail) . ' 行');
$hasPseudo = false;
$hasCond   = false;
foreach ($detail as $d) {
    if ((int)$d['menu_item_id'] <= 0)             { $hasPseudo = true; }
    if ((int)$d['condiment_belong_item'] !== 0)   { $hasCond = true; }
}
is_(!$hasPseudo, '★ SQL 层已滤掉伪行（-3 备注 / -4 支付）');
is_(!$hasCond,   '★ SQL 层已滤掉配料行');

// bit(1) 字段必须是 0/1 而不是二进制字节
$bitOk = true;
foreach ($detail as $d) {
    if (!in_array((string)$d['is_return_item'], ['0', '1'], true)) { $bitOk = false; }
}
is_($bitOk, '★ bit(1) 字段经 +0 转换后是 0/1（否则 PHP 读到的是二进制字节）');

// 退菜行：源单 92271 有 3 行 return_time 非空
$detRet = $pos->fetchDetail(900002, 1);
$nReturned = 0;
foreach ($detRet as $d) {
    if ((int)$d['is_return_item'] === 1) { $nReturned++; }
}
is_(true, "含退菜的订单读到 " . count($detRet) . " 行明细，其中 is_return_item=1 共 {$nReturned} 行");

// ══════════════════════════════════════════════════════════
head('⑤ 完整流程 —— 定位 → 建单 → 发分');


$loc = $points->locate('30', 30);
is_(($loc['ok'] ?? false) === true, 'locate 成功', json_encode($loc, JSON_UNESCAPED_UNICODE));
$cand = $loc['candidates'][0] ?? null;
is_($cand !== null, 'locate 返回候选订单');

if ($cand !== null) {
    $serial = (string)$cand['serial_id'];
    ok_("候选订单 serial={$serial} 可积分金额={$cand['total']} 份数={$cand['portions_counted']}"
        . ' 已扣除不计分项 ' . $cand['excluded']);

    $row = $db->one('SELECT * FROM pos_order WHERE store_code=? AND serial_id=?', [$E2E_STORE, $serial]);
    is_($row !== null, '★ locate 已把订单落到本地 pos_order（幂等来源）');

    // 幂等：重复 locate 不产生第二条
    $points->locate('30', 30);
    eq_(1, (int)$db->value('SELECT COUNT(*) FROM pos_order WHERE store_code=? AND serial_id=?', [$E2E_STORE, $serial]),
        '★ 重复 locate 不产生重复订单');

    $m1 = $newMember('600100001', null, null);
    $op = ['id' => 1, 'name' => 'e2e', 'role' => 'admin'];
    $totalCents = Money::toCents((string)$row['total_amount']);

    $g = $points->grant($serial, [['member_id' => (int)$m1['id'], 'amount_cents' => $totalCents]],
                        PointsEngine::MODE_WHOLE, $op);
    is_(($g['ok'] ?? false) === true, '整单记一人 发分成功', json_encode($g, JSON_UNESCAPED_UNICODE));

    $after = $db->one('SELECT * FROM pos_order WHERE store_code=? AND serial_id=?', [$E2E_STORE, $serial]);
    eq_($after['total_amount'], $after['allocated_amount'], '★ 已分配额 = 可积分总额（全额分配）');

    // 超额分配必须被拒
    $g2 = $points->grant($serial, [['member_id' => (int)$m1['id'], 'amount_cents' => 1]],
                         PointsEngine::MODE_WHOLE, $op);
    is_(($g2['ok'] ?? true) === false, '★ 超出可分配额度被拒绝', json_encode($g2, JSON_UNESCAPED_UNICODE));
}

// 外带订单不可发分
$locTakeaway = $points->locate('Llevar', 30);
is_(empty($locTakeaway['candidates']), '★ 外带订单不进入可发分候选');

// ══════════════════════════════════════════════════════════
head('⑥ 分单 AA —— 4 张 check 一次记账');

$loc23 = $points->locate('23', 30);
$c23   = $loc23['candidates'][0] ?? null;
if ($c23 !== null) {
    $serial23 = (string)$c23['serial_id'];
    $ord23    = $db->one('SELECT * FROM pos_order WHERE store_code=? AND serial_id=?', [$E2E_STORE, $serial23]);
    ok_("分单订单落地 serial={$serial23} checks={$ord23['check_ids']} 金额={$ord23['total_amount']}");
    is_(str_contains((string)$ord23['check_ids'], ','), '★ check_ids 保存了全部分单号');

    $tot23 = Money::toCents((string)$ord23['total_amount']);
    $ms = [];
    for ($i = 0; $i < 3; $i++) {
        $ms[] = (int)$newMember('60020000' . $i, null, null)['id'];
    }
    $port23 = (int)$ord23['portions_counted'];
    $parts  = PointsEngine::splitEvenly($tot23, $port23, 3);
    $allocs = [];
    foreach ($ms as $i => $mid) {
        $allocs[] = [
            'member_id'    => $mid,
            'amount_cents' => $parts[$i]['amount_cents'],
            'portions'     => $parts[$i]['portions'],
        ];
    }
    $g23 = $points->grant($serial23, $allocs, PointsEngine::MODE_SPLIT, ['id' => 1, 'name' => 'e2e', 'role' => 'admin']);
    is_(($g23['ok'] ?? false) === true, 'AA 均摊发分成功', json_encode($g23, JSON_UNESCAPED_UNICODE));
    eq_($tot23, array_sum(array_column($parts, 'amount_cents')), '★ 均摊金额分毫不差');
    eq_($port23, array_sum(array_column($parts, 'portions')), '★ 均摊份数不丢');

    $after23 = $db->one('SELECT * FROM pos_order WHERE store_code=? AND serial_id=?', [$E2E_STORE, $serial23]);
    eq_($after23['total_amount'], $after23['allocated_amount'], '★ AA 后已分配额 = 总额');
}

// ══════════════════════════════════════════════════════════
head('⑥bis 十送一核销 —— 真实的 TARJETA 10+1 折扣行');

// 桌 21 来自真实订单 92293：明细含 `-2 / TARJETA 10+1 / -95.60`
$locRedeem = $points->locate('21', 35);
$cRedeem   = $locRedeem['candidates'][0] ?? null;
if ($cRedeem === null) {
    bad_('桌 21 未定位到核销单', '请先执行 tests/sim/inject_live.php');
} else {
    is_($cRedeem['is_redeemed'] === true, '★ 识别出十送一核销行');
    eq_('95.60', $cRedeem['redeem_amount'], '核销额取自 -2 行的绝对值');
    is_(in_array('TARJETA 10+1', $cRedeem['redeem_lines'], true), '记录了命中的核销行名称');

    /**
     * ★ 这张真实单（92293）本身就是【混合单】：
     *   5 份 MENÚ @ 23.90 = 119.50，券抵 95.60 = 4 份，还剩 1 份是付费的。
     *
     * 本处断言原先写的是「核销单一律拒绝发分」——那是错的口径，
     * 等于让那位付了钱的客人白吃。店家口径已确认：
     * 免的那几份不算，付费的那几份照常计次。
     * 所以这里改成断言「抵掉 4 份、可计次 1 份、可以发分」。
     */
    eq_(5, $cRedeem['portions_total'],    '明细里共 5 份套餐');
    eq_(4, $cRedeem['portions_redeemed'], '★ 券抵掉 4 份（95.60 ÷ 23.90）');
    eq_(1, $cRedeem['portions_counted'],  '★ 可计次 1 份 —— 付费的那位不该被吞掉');
    is_($cRedeem['eligible'] === true,    '★ 混合单可以发分');

    $mR = $newMember('600300001', null, null);
    $gR = $points->grant((string)$cRedeem['serial_id'],
        [['member_id' => (int)$mR['id'], 'amount_cents' => 100, 'portions' => 1]],
        PointsEngine::MODE_WHOLE, ['id' => 1, 'name' => 'e2e', 'role' => 'admin']);
    is_(($gR['ok'] ?? false) === true, '★ 混合核销单可以正常发分');
    eq_(1, (int)($gR['entries'][0]['visits'] ?? -1), '★ 计 1 次（不是 0，也不是 5）');
    // 原先此处断言「返回 redeemed 错误码」—— 那是整单拒绝时代的产物。
    // 混合单现在会正常发分，不该再有错误码。
    eq_('', $gR['error'] ?? '', '混合单不返回错误码');

    $rowR = $db->one('SELECT is_redeemed, redeem_amount FROM pos_order WHERE store_code=? AND serial_id=?',
        [$E2E_STORE, (string)$cRedeem['serial_id']]);
    eq_(1, (int)$rowR['is_redeemed'], '核销标记已落库（与人工 is_free_meal 分开存，便于审计）');
}

// 对照组：普通折扣单不得被误判为核销
$locPlain = $points->locate('40', 35);
$cPlain   = $locPlain['candidates'][0] ?? null;
if ($cPlain !== null) {
    is_($cPlain['is_redeemed'] === false, '★ 普通折扣单未被误判为核销（Dto./CUPON 不能命中）');
    is_($cPlain['eligible'] === true, '普通折扣单正常可积分');
    eq_(10, $cPlain['portions_counted'], '10 份套餐计次为 10');
}

// ══════════════════════════════════════════════════════════
head('⑦ 增量补抓 —— 跑在 88,616 行真实数据上');

$db->exec('DELETE FROM sync_cursor WHERE store_code=?', [$E2E_STORE]);
$t0   = microtime(true);
$sync = $app->sync()->incremental();
$ms   = (int)((microtime(true) - $t0) * 1000);
is_(($sync['ok'] ?? false) === true, '增量补抓执行成功', json_encode($sync, JSON_UNESCAPED_UNICODE));
ok_(sprintf('补抓 %d 单 / %d 批 / %d 个窗口，耗时 %d ms',
    $sync['rows'] ?? 0, $sync['batches'] ?? 0, $sync['windows'] ?? 0, $ms));

$cur = $db->one('SELECT * FROM sync_cursor WHERE store_code=? AND cursor_name=?', [$E2E_STORE, 'incremental']);
is_($cur !== null, '水位线已建立');

$before = (int)$db->value('SELECT COUNT(*) FROM pos_order WHERE store_code=?', [$E2E_STORE]);
$app->sync()->incremental();
eq_($before, (int)$db->value('SELECT COUNT(*) FROM pos_order WHERE store_code=?', [$E2E_STORE]),
    '★ 重复补抓不产生重复订单（幂等）');

// 补抓进来的订单里不应有外带
$nTakeaway = (int)$db->value('SELECT COUNT(*) FROM pos_order WHERE store_code=? AND eat_type<>0', [$E2E_STORE]);
ok_("补抓结果中非堂食订单 {$nTakeaway} 单（会入库但不可发分）");

// ══════════════════════════════════════════════════════════
head('⑧ 值比对冲正 —— 真实金额回读');

$rec = $app->reconcile()->verifyAmounts();
is_(($rec['ok'] ?? false) === true, '值比对执行成功', json_encode($rec, JSON_UNESCAPED_UNICODE));
ok_(sprintf('回读 %d 张订单，发现 %d 张金额变化',
    $rec['checked'] ?? 0, $rec['changed'] ?? 0));

// ══════════════════════════════════════════════════════════
head('⑨ 完整性监控 —— 真实数据里确有整段缺失');

$integ = $app->sync()->checkIntegrity(30);
is_(is_array($integ), '完整性监控返回结果');
ok_('检查了 ' . count($integ['days'] ?? $integ) . ' 个营业日');

// ══════════════════════════════════════════════════════════
head('⑨bis 撤销 —— 账本只增不删');

$locRev = $points->locate('9', 35);
$cRev   = $locRev['candidates'][0] ?? null;
if ($cRev === null) {
    bad_('桌 9 未定位到订单');
} else {
    $serialRev = (string)$cRev['serial_id'];
    $mRev = $newMember('600400001', null, null);
    $opRev = ['id' => 1, 'name' => 'e2e', 'role' => 'admin'];
    $gRev = $points->grant($serialRev,
        [['member_id' => (int)$mRev['id'], 'amount_cents' => $cRev['total_cents'],
          'portions' => $cRev['portions_counted']]],
        PointsEngine::MODE_WHOLE, $opRev);
    is_(($gRev['ok'] ?? false) === true, '先正常发分', json_encode($gRev, JSON_UNESCAPED_UNICODE));

    $ledgerId = (int)($gRev['entries'][0]['ledger_id'] ?? 0);
    $before   = (int)$db->value('SELECT points_balance FROM member WHERE id=?', [(int)$mRev['id']]);
    is_($before > 0, "撤销前余额 {$before} 分");

    $rv = $points->reverse($ledgerId, 'E2E 测试撤销', $opRev);
    is_(($rv['ok'] ?? false) === true, '撤销成功', json_encode($rv, JSON_UNESCAPED_UNICODE));

    eq_(0, (int)$db->value('SELECT points_balance FROM member WHERE id=?', [(int)$mRev['id']]),
        '★ 撤销后余额归零');
    eq_(0, (int)$db->value('SELECT visit_count FROM member WHERE id=?', [(int)$mRev['id']]),
        '★ 撤销后计次归零');
    eq_(2, (int)$db->value('SELECT COUNT(*) FROM point_ledger WHERE store_code=? AND serial_id=?',
        [$E2E_STORE, $serialRev]), '★ 账本共 2 行（原始 + 冲正），原始行未被物理删除');
    eq_(2, (int)$db->value('SELECT status FROM point_ledger WHERE id=?', [$ledgerId]),
        '原始行标记为已撤销（status=2），但仍留在账本里');
    eq_(0, (int)$db->value('SELECT ROUND(allocated_amount*100) FROM pos_order WHERE store_code=? AND serial_id=?',
        [$E2E_STORE, $serialRev]), '★ 订单已分配额回退到 0');

    // 同一条流水不得重复撤销
    $rv2 = $points->reverse($ledgerId, '重复撤销', $opRev);
    is_(($rv2['ok'] ?? true) === false, '★ 同一条流水不可重复撤销');
}

// ══════════════════════════════════════════════════════════
head('⑩ 不变量总校验');

$over = (int)$db->value(
    'SELECT COUNT(*) FROM pos_order WHERE store_code=? AND allocated_amount > total_amount + 0.001', [$E2E_STORE]);
eq_(0, $over, '★ 没有任何订单的已分配额超过可积分总额');

// ★ 求和时【不能】按 status 过滤。
// status=2 的语义是「这条流水已被撤销过」，用于防止重复撤销、
// 以及在界面上只列未撤销的条目 —— 它不是「不计入余额」。
// 账本只增不删：原始 +222 与其冲正 -222 两条都是真实流水，
// 余额等于【全部流水之和】= 0。若只求和 status=1，
// 会漏掉被标记的原始条目、只剩下负的冲正条目，算出 -222 的假警报。
$mismatch = (int)$db->value(
    "SELECT COUNT(*) FROM (
        SELECT o.serial_id,
               o.allocated_amount AS a,
               COALESCE((SELECT SUM(l.amount) FROM point_ledger l
                         WHERE l.store_code=o.store_code AND l.serial_id=o.serial_id), 0) AS b
        FROM pos_order o WHERE o.store_code=?
     ) x WHERE ABS(x.a - x.b) > 0.001", [$E2E_STORE]);
eq_(0, $mismatch, '★ 每张订单的已分配额与其账本流水合计一致（含冲正）');

$balBad = (int)$db->value(
    "SELECT COUNT(*) FROM (
        SELECT m.id,
               m.points_balance AS a,
               COALESCE((SELECT SUM(l.points) FROM point_ledger l
                         WHERE l.member_id=m.id), 0) AS b
        FROM member m WHERE m.store_code=?
     ) x WHERE x.a <> x.b", [$E2E_STORE]);
eq_(0, $balBad, '★ 每名会员的积分余额与其流水合计一致（含冲正）');

$negative = (int)$db->value('SELECT COUNT(*) FROM member WHERE store_code=? AND points_balance < 0', [$E2E_STORE]);
eq_(0, $negative, '★ 没有负余额会员');

finish:
// ── 清理 ─────────────────────────────────────────────────
$cleanup();
head('已清理全部 E2E 数据');

$flavor = (string)$db->value('SELECT VERSION()');
echo "\n" . str_repeat('─', 62) . "\n";
if ($fails) {
    echo $C('E2E 失败', '31') . '  ' . count($fails) . " 项\n";
    foreach ($fails as $f) {
        echo "  · {$f}\n";
    }
    exit(1);
}
echo $C('E2E 通过', '32') . "  {$pass} 项断言   （本地库 {$flavor}）\n";
