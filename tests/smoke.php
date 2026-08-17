<?php
declare(strict_types=1);

/**
 * ════════════════════════════════════════════════════════════════
 * 真库冒烟测试 —— 连接真实的 MySQL / MariaDB，跑一遍完整业务流程。
 *
 * 用途：验证 DDL 与事务在真实环境下无误。请在【两种数据库上都跑一遍】。
 *
 * 用法：
 *   php tests/smoke.php --fresh          建表 + 灌种子 + 跑流程（首次）
 *   php tests/smoke.php                  复用已有表，只跑流程
 *   php tests/smoke.php --fresh --keep   跑完不清理，便于人工查看数据
 *
 * 数据库连接（按优先级）：
 *   1) 环境变量 SMOKE_DB_HOST / SMOKE_DB_PORT / SMOKE_DB_NAME /
 *      SMOKE_DB_USER / SMOKE_DB_PASS
 *   2) app/config/config.php 的 local_db
 *
 * ★ 安全设计
 *   · 全程使用独立门店码 SMOKE，绝不触碰生产数据；
 *   · --fresh 会执行 DROP TABLE，因此若库中存在 store_code != 'SMOKE'
 *     的任何数据，脚本会拒绝执行并退出；
 *   · 不需要 POS 主库可达 —— 注入 FakePosSource。
 * ════════════════════════════════════════════════════════════════
 */

const SMOKE_STORE = 'SMOKE';

spl_autoload_register(static function (string $class): void {
    foreach ([['Vip\\Test\\', __DIR__ . '/'], ['Vip\\', __DIR__ . '/../app/lib/']] as [$p, $base]) {
        if (str_starts_with($class, $p)) {
            $f = $base . str_replace('\\', '/', substr($class, strlen($p))) . '.php';
            if (is_file($f)) {
                require $f;
                return;
            }
        }
    }
});

use Vip\App;
use Vip\LocalDb;
use Vip\PointsEngine as PE;
use Vip\Test\FakePosSource;

$opts   = $argv ?? [];
$fresh  = in_array('--fresh', $opts, true);
$keep   = in_array('--keep', $opts, true);

$pass = 0; $fail = 0;
function ok(bool $c, string $msg, string $extra = ''): void {
    global $pass, $fail;
    if ($c) { $pass++; echo "  \033[32m✓\033[0m $msg\n"; }
    else    { $fail++; echo "  \033[31m✗\033[0m $msg" . ($extra ? "\n      $extra" : '') . "\n"; }
}
function eq(mixed $exp, mixed $act, string $msg): void {
    ok($exp === $act, $msg, '期望 ' . var_export($exp, true) . '，实际 ' . var_export($act, true));
}
function step(string $s): void { echo "\n\033[1m$s\033[0m\n"; }
function die_(string $m): never { echo "\n\033[31m$m\033[0m\n"; exit(1); }

// ── 1. 连接 ──────────────────────────────────────────────────
$dbCfg = [
    'host'     => getenv('SMOKE_DB_HOST') ?: null,
    'port'     => (int)(getenv('SMOKE_DB_PORT') ?: 3306),
    'database' => getenv('SMOKE_DB_NAME') ?: null,
    'user'     => getenv('SMOKE_DB_USER') ?: null,
    'password' => getenv('SMOKE_DB_PASS') ?: '',
    'charset'  => 'utf8mb4',
];
if ($dbCfg['host'] === null || $dbCfg['database'] === null) {
    $cfgFile = __DIR__ . '/../app/config/config.php';
    if (!is_file($cfgFile)) {
        die_("未设置 SMOKE_DB_* 环境变量，且 app/config/config.php 不存在。\n"
           . "示例：SMOKE_DB_HOST=127.0.0.1 SMOKE_DB_NAME=vip_smoke SMOKE_DB_USER=root SMOKE_DB_PASS=xxx php tests/smoke.php --fresh");
    }
    $dbCfg = (require $cfgFile)['local_db'];
}

step('连接本地库');
try {
    $db = new LocalDb($dbCfg);
} catch (\Throwable $e) {
    die_('连接失败：' . $e->getMessage());
}
$version = (string)$db->pdo()->query('SELECT VERSION()')->fetchColumn();
$flavor  = $db->serverFlavor();
echo "  服务器：{$version}  （识别为 \033[1m{$flavor}\033[0m）\n";
echo "  数据库：{$dbCfg['database']} @ {$dbCfg['host']}:{$dbCfg['port']}\n";
ok(in_array($flavor, ['mysql', 'mariadb'], true), "识别数据库类型：{$flavor}");

// ── 2. 安全闸门 + 建表 ───────────────────────────────────────
$tables = ['pos_order','member','point_ledger','coupon','meal_item_rule',
           'meal_period','sys_config','sync_cursor','audit_log','alert'];

$existing = [];
foreach ($db->all('SHOW TABLES') as $row) {
    $existing[] = (string)array_values($row)[0];
}

if ($fresh) {
    step('安全检查 —— --fresh 会 DROP TABLE');
    $foreign = 0;
    foreach ($tables as $t) {
        if (!in_array($t, $existing, true)) {
            continue;
        }
        $cols = $db->all("SHOW COLUMNS FROM `{$t}` LIKE 'store_code'");
        if (!$cols) {
            continue;
        }
        $n = (int)$db->value("SELECT COUNT(*) FROM `{$t}` WHERE store_code <> ?", [SMOKE_STORE]);
        if ($n > 0) {
            echo "  \033[31m表 {$t} 含 {$n} 行非 SMOKE 数据\033[0m\n";
            $foreign += $n;
        }
    }
    if ($foreign > 0) {
        die_("拒绝执行 --fresh：库中存在 {$foreign} 行非 SMOKE 门店的数据，DROP TABLE 会破坏它们。\n"
           . "请改用一个空库，或去掉 --fresh 复用已有表结构。");
    }
    ok(true, '库中无非 SMOKE 数据，可以安全建表');

    step('执行 migrations 与 seeds');
    // ★ 必须扫目录而不是写死文件名 —— 早先这里硬编码了 001_init.sql，
    //   于是每加一个迁移（002、003…）冒烟测试都会因为缺列而崩，
    //   且报错指向业务代码，看不出真正原因是建表少跑了迁移。
    $migrations = glob(__DIR__ . '/../db/migrations/*.sql') ?: [];
    sort($migrations);
    if (!$migrations) {
        die_('db/migrations/ 下没有找到任何迁移文件');
    }
    foreach ($migrations as $path) {
        $name = 'migrations/' . basename($path);
        try {
            $db->pdo()->exec((string)file_get_contents($path));
            ok(true, "执行 db/{$name}");
        } catch (\Throwable $e) {
            die_("db/{$name} 执行失败（这正是本脚本要发现的问题）：\n      " . $e->getMessage());
        }
    }
    // 种子里的 store_code 是 S001，冒烟用 SMOKE，因此下面自行灌
    $existing = $tables;
} else {
    $missing = array_diff($tables, $existing);
    if ($missing) {
        die_('缺少表：' . implode(', ', $missing) . "\n请先用 --fresh 建表。");
    }
    ok(true, '10 张表均已存在');
}

// ── 3. 清理上次残留 ──────────────────────────────────────────
$cleanup = static function (LocalDb $db) use ($tables): void {
    foreach (array_reverse($tables) as $t) {
        $db->exec("DELETE FROM `{$t}` WHERE store_code = ?", [SMOKE_STORE]);
    }
};
$cleanup($db);

// ── 4. 灌 SMOKE 门店的配置与规则 ─────────────────────────────
step('灌入 SMOKE 门店的配置与套餐规则');

$app = new App(['store_code' => SMOKE_STORE, 'local_db' => $dbCfg, 'pos_db' => []]);
$app->setLocalDb($db);   // 与断言共用同一条连接

$cfgSeed = [
    'order_lookup_window_min' => '30',
    'points_per_euro'         => '1',
    'points_multiplier'       => '1.0',
    'visit_count_mode'        => 'by_portion',
    'business_day_cutoff'     => '02:00',
    'reversal_window_hours'   => '24',
    'manual_entry_enabled'    => '1',
    'manual_entry_limit'      => '200.00',
    'manual_entry_daily_alert'=> '5',
];
foreach ($cfgSeed as $k => $v) {
    $app->cfg()->set($k, $v);
}
ok(count($app->cfg()->all()) >= count($cfgSeed), '写入 ' . count($cfgSeed) . ' 项配置');

$rules = [
    // 堂食套餐：算餐费、计次、积分
    ['menu_item_id' => 2390, 'item_name' => 'MENÚ INFINITY NOCHE', 'ref_price' => '23.90',
     'is_meal_fee' => 1, 'counts_visit' => 1, 'earns_points' => 1],
    // MENÚ DEL DIA：算餐费但不计次
    ['menu_item_id' => 1590, 'item_name' => 'MENÚ DEL DIA', 'ref_price' => '15.90',
     'is_meal_fee' => 1, 'counts_visit' => 0, 'earns_points' => 1],
    // BOX：外卖产品线，三开关全 0
    ['menu_item_id' => 1017, 'item_name' => 'BOX 17', 'ref_price' => '26.50',
     'is_meal_fee' => 0, 'counts_visit' => 0, 'earns_points' => 0],
];
foreach ($rules as $r) {
    $app->mealRuleRepo()->upsert($r);
}
eq(3, $app->mealRuleRepo()->load()->count(), '写入 3 条套餐规则');

// ── 5. 构造假 POS 数据 ───────────────────────────────────────
step('构造 POS 夹具（形态照搬真实导出）');

$pos = new FakePosSource();
$pos->now = '2026-08-13 23:30:00';

// 订单 A：3 份 MENÚ INFINITY NOCHE，无折扣无找零
$pos->addHead([
    'serial_id' => '2608130080', 'order_head_id' => 92319, 'check_id' => 1,
    'table_name' => '42', 'eat_type' => 0, 'customer_num' => 3,
    'original_amount' => '71.70', 'should_amount' => '71.70', 'actual_amount' => '71.70',
    'order_end_time' => '2026-08-13 23:11:47',
]);
$pos->addDetail(92319, 1, [
    // actual_price 是行小计：23.90 × 3 = 71.70
    FakePosSource::line(2390, 'MENÚ INFINITY NOCHE', '23.90', '71.70', 3),
    // 套餐内 0 元菜品
    FakePosSource::line(95, '95-Gunkan de queso crema', '0.00', '0.00', 1),
    // 伪行：备注 / 支付（支付行 actual_price 是收款额，混入会虚高）
    FakePosSource::line(-3, '**999 Enviado 23:05**', '0.00', '0.00', 0),
    FakePosSource::line(-4, 'EFECTIVO', '0.00', '71.70', 0),
    // 配料行
    FakePosSource::line(500, 'S/Pepino', '0.00', '0.00', 1, null, 0, 1),
    // 退菜行（actual_price 退菜后不清零）
    FakePosSource::line(431, 'Agua', '2.95', '2.95', 1, null, 1),
]);

// 订单 B：堂食单里混了 BOX（外卖产品线），且客人给整钞有找零
//   明细：MENÚ 23.90 + BOX17 53.00(qty2) + Agua 2.95 = 79.85 = original
//   收款 80.00 → 找零 0.15
$pos->addHead([
    'serial_id' => '2608130081', 'order_head_id' => 92322, 'check_id' => 1,
    'table_name' => '32', 'eat_type' => 0, 'customer_num' => 2,
    'original_amount' => '79.85', 'should_amount' => '79.85', 'actual_amount' => '80.00',
    'order_end_time' => '2026-08-13 23:16:12',
]);
$pos->addDetail(92322, 1, [
    FakePosSource::line(2390, 'MENÚ INFINITY NOCHE', '23.90', '23.90', 1),
    FakePosSource::line(1017, 'BOX 17', '26.50', '53.00', 2),
    FakePosSource::line(431,  'Agua',   '2.95',  '2.95',  1),
]);

$app->setPosSource($pos);
$svc = $app->points();
ok(true, '注入 FakePosSource（无需 POS 主库可达）');

// ── 6. 订单定位 ──────────────────────────────────────────────
step('① 订单定位 —— 桌号 42');

$loc = $svc->locate('42');
ok($loc['ok'], 'locate 成功');
eq(1, count($loc['candidates']), '返回 1 张候选订单');
$ctxA = $loc['candidates'][0];
eq('2608130080', $ctxA['serial_id'], 'serial_id 正确');
eq('71.70', $ctxA['total'], '可积分总额 71.70');
eq(3, $ctxA['portions_counted'], '计次份数 3（SUM(quantity)，不是行数 1）');
eq('2026-08-13', $ctxA['business_date'], '营业日 2026-08-13');
ok($ctxA['eligible'], '订单可积分');
eq(1, count($ctxA['items']), '展示列表仅 1 项（0 元菜品/伪行/配料/退菜均已过滤）');
eq('MENÚ INFINITY NOCHE', $ctxA['items'][0]['name'], '展示项是套餐');

$row = $db->one('SELECT * FROM pos_order WHERE store_code=? AND serial_id=?', [SMOKE_STORE, '2608130080']);
ok($row !== null, '订单已落本地镜像');
eq('71.70', $row['total_amount'], '镜像 total_amount = 71.70');
eq(3, (int)$row['portions_counted'], '镜像份数 = 3');

// 幂等：再定位一次不应产生第二条
$svc->locate('42');
eq(1, (int)$db->value('SELECT COUNT(*) FROM pos_order WHERE store_code=? AND serial_id=?',
        [SMOKE_STORE, '2608130080']), '重复定位不产生重复订单（(store_code,serial_id) 唯一约束）');

step('② 订单定位 —— 桌号 32（含 BOX 与找零）');

$locB = $svc->locate('32');
$ctxB = $locB['candidates'][0];
eq('26.85', $ctxB['total'],
    '可积分总额 26.85 ＝ LEAST(79.85,80.00) 排除找零，再按比例扣掉 BOX 的 53.00');
eq('53.00', $ctxB['excluded'], '排除金额 53.00（BOX earns_points=0）');
eq(1, $ctxB['portions_counted'], '计次份数 1（BOX 不计次）');

// ── 7. 会员 ──────────────────────────────────────────────────
step('③ 建会员');

$mA = $app->members()->create('+34600000001', null, null);
$mB = $app->members()->create(null, 'b@example.com', '1990-05-20');
ok((int)$mA['id'] > 0 && (int)$mB['id'] > 0, '建立 2 名会员');
eq(0, (int)$mA['consent_status'], '新会员 consent_status=0（pending，积分入账但冻结）');
ok(!empty($mA['consent_token']), '生成 double opt-in 令牌');
ok($app->members()->findBy('phone', '+34600000001') !== null, '按手机号检索命中');
ok($app->members()->findBy('email', 'b@example.com') !== null, '按邮箱检索命中');
ok($app->members()->findBy('card', (string)$mA['card_no']) !== null, '按卡号检索命中');

// ── 8. 整单记账 ──────────────────────────────────────────────
step('④ 整单记给会员 A');

$g = $svc->grant('2608130080',
    [['member_id' => (int)$mA['id'], 'amount_cents' => 7170, 'portions' => 3]],
    PE::MODE_WHOLE, ['id' => 1, 'name' => '收银员甲', 'device' => 'PAD-1']);
ok($g['ok'], 'grant 成功');
eq(71, $g['entries'][0]['points'], '积分 71（71.70 欧向下取整）');
eq(3, $g['entries'][0]['visits'], '计次 3（by_portion）');

$mA1 = $app->members()->findById((int)$mA['id']);
eq(71, (int)$mA1['points_balance'], '会员积分余额 71');
eq(3, (int)$mA1['visit_count'], '会员累计次数 3');
eq('71.70', $mA1['total_spent'], '会员累计消费 71.70');

$oA1 = $app->orders()->findBySerial('2608130080');
eq('71.70', $oA1['allocated_amount'], '订单已分配 71.70');
eq(2, (int)$oA1['alloc_status'], '订单状态 = 已全额分配');

step('⑤ 超额分配必须被拒绝');

$g2 = $svc->grant('2608130080',
    [['member_id' => (int)$mB['id'], 'amount_cents' => 100, 'portions' => 0]],
    PE::MODE_WHOLE, ['id' => 1, 'name' => '收银员甲']);
ok(!$g2['ok'], '已全额分配后再提交被拒绝');
eq('exceeds_total', $g2['error'], '错误码 exceeds_total');
eq(0, (int)$app->members()->findById((int)$mB['id'])['points_balance'],
    '会员 B 未被误加分，余额仍为 0');

// ── 9. 撤销 ──────────────────────────────────────────────────
step('⑥ 撤销 —— 追加反向冲正，不物理删除');

$ledgerId = (int)$g['entries'][0]['ledger_id'];
$rev = $svc->reverse($ledgerId, '客人要求改为 AA 分记',
    ['id' => 1, 'name' => '收银员甲', 'device' => 'PAD-1']);
ok($rev['ok'], 'reverse 成功');

$orig = $app->ledger()->findById($ledgerId);
eq(2, (int)$orig['status'], '原流水标记为已撤销（status=2），行仍在');
eq((int)$rev['reversal_id'], (int)$orig['reversed_by_id'], '原流水指向冲正流水');
$revRow = $app->ledger()->findById((int)$rev['reversal_id']);
eq('-71.70', $revRow['amount'], '冲正流水金额为负');
eq(-71, (int)$revRow['points'], '冲正流水积分为负');
eq(-3, (int)$revRow['counted_visit'], '冲正流水计次为负');
eq(2, (int)$revRow['entry_type'], 'entry_type=2（撤销冲正）');

$mA2 = $app->members()->findById((int)$mA['id']);
eq(0, (int)$mA2['points_balance'], '会员余额回退为 0');
eq(0, (int)$mA2['visit_count'], '会员次数回退为 0');
eq('0.00', $mA2['total_spent'], '会员累计消费回退为 0');

$oA2 = $app->orders()->findBySerial('2608130080');
eq('0.00', $oA2['allocated_amount'], '订单已分配额回退为 0');
eq(0, (int)$oA2['alloc_status'], '订单状态回到未分配');

eq(2, (int)$db->value('SELECT COUNT(*) FROM point_ledger WHERE store_code=? AND serial_id=?',
        [SMOKE_STORE, '2608130080']), '账本共 2 行（原始 + 冲正），只增不删');

// ── 10. AA 分摊 ──────────────────────────────────────────────
step('⑦ 撤销后改记 AA —— 3 人均摊（其中一人现场新建会员）');

$mC = $app->members()->create('+34600000003', null, null);   // 客人没卡，现场建
$split = PE::splitEvenly(7170, 3, 3);
eq(7170, array_sum(array_column($split, 'amount_cents')), 'AA 金额合计不丢分');
eq(3, array_sum(array_column($split, 'portions')), 'AA 份数合计不丢');

$g3 = $svc->grant('2608130080', [
    ['member_id' => (int)$mA['id'], 'amount_cents' => $split[0]['amount_cents'], 'portions' => $split[0]['portions']],
    ['member_id' => (int)$mB['id'], 'amount_cents' => $split[1]['amount_cents'], 'portions' => $split[1]['portions']],
    ['member_id' => (int)$mC['id'], 'amount_cents' => $split[2]['amount_cents'], 'portions' => $split[2]['portions']],
], PE::MODE_SPLIT, ['id' => 1, 'name' => '收银员甲', 'device' => 'PAD-1']);
ok($g3['ok'], 'AA 分配成功');
eq(3, count($g3['entries']), '产生 3 条流水');

$oA3 = $app->orders()->findBySerial('2608130080');
eq('71.70', $oA3['allocated_amount'], '★ 金额守恒：已分配回到 71.70，分毫不差');
eq(3, (int)$oA3['allocated_portions'], '份数守恒：3');
foreach ([$mA, $mB, $mC] as $m) {
    $x = $app->members()->findById((int)$m['id']);
    eq(1, (int)$x['visit_count'], "会员 {$x['card_no']} 计次 1（AA 每人 1 份）");
}

// ── 11. 手工录入降级 ─────────────────────────────────────────
step('⑧ 降级路径 —— 手工录入');

$man = $svc->manualGrant((int)$mB['id'], 4500, 'system_not_found',
    ['id' => 2, 'name' => '经理', 'device' => 'PAD-2']);
ok($man['ok'], 'manualGrant 成功');
ok($man['review_required'], '标记为需要复核');
$manRow = $app->ledger()->findById((int)$man['ledger_id']);
eq(2, (int)$manRow['source'], 'source=2（手工录入）');
eq(null, $manRow['serial_id'], 'serial_id 为 NULL（无对应 POS 订单）');
eq(1, (int)$manRow['review_status'], '进入待复核队列');
eq(0, (int)$manRow['counted_visit'], '手工录入不计次（无明细无法判断套餐份数）');
eq(1, count($app->ledger()->pendingReview()), '待复核队列 1 条');

$over = $svc->manualGrant((int)$mB['id'], 50000, 'other', ['id' => 2, 'name' => '经理']);
ok(!$over['ok'], '超过单笔限额被拒绝');
eq('exceeds_manual_limit', $over['error'], '错误码 exceeds_manual_limit');

// ── 12. Cron 流程 ────────────────────────────────────────────
step('⑨ 增量补抓（Cron）');

$app->cfg()->set('sync_batch_sleep_ms', '0');   // 冒烟测试不停顿
$app->cfg()->set('sync_window_hours', '48');
$sync = $app->sync();
$rs = $sync->incremental();
ok($rs['ok'], '增量补抓执行成功');
ok($rs['rows'] >= 2, "补抓 {$rs['rows']} 单（含桌号 42 与 32 两张）");
$cur = $db->one('SELECT * FROM sync_cursor WHERE store_code=? AND cursor_name=?',
                [SMOKE_STORE, 'incremental']);
ok($cur !== null, '水位线已建立');
eq(1, (int)$cur['last_status'], '水位线状态=成功');
ok($cur['watermark'] >= '2026-08-13', '水位线已推进到夹具时间之后');

// 幂等：再跑一次不应产生重复订单
$before = (int)$db->value('SELECT COUNT(*) FROM pos_order WHERE store_code=?', [SMOKE_STORE]);
$sync->incremental();
eq($before, (int)$db->value('SELECT COUNT(*) FROM pos_order WHERE store_code=?', [SMOKE_STORE]),
    '重复执行不产生重复订单（幂等）');

step('⑩ 值比对冲正 —— 金额缩水');

// 当前订单 A 已 AA 分给 3 人各 23.90，合计 71.70
$oBefore = $app->orders()->findBySerial('2608130080');
eq('71.70', $oBefore['allocated_amount'], '前提：订单 A 已全额分配 71.70');

// 模拟 POS 侧退掉一份套餐：71.70 → 47.80
foreach ($pos->heads as $i => $h) {
    if ($h['serial_id'] === '2608130080') {
        $pos->heads[$i]['original_amount'] = '47.80';
        $pos->heads[$i]['should_amount']   = '47.80';
        $pos->heads[$i]['actual_amount']   = '47.80';
    }
}
$pos->addDetail(92319, 1, [
    FakePosSource::line(2390, 'MENÚ INFINITY NOCHE', '23.90', '47.80', 2),
]);

$rv = $app->reconcile()->verifyAmounts();
ok($rv['ok'], '值比对执行成功');
ok($rv['checked'] >= 1, "回读了 {$rv['checked']} 张订单");
eq(1, $rv['changed'], '发现 1 张订单金额变化');

$oAfter = $app->orders()->findBySerial('2608130080');
eq('47.80', $oAfter['total_amount'], '订单可积分总额更新为 47.80');
eq('47.80', $oAfter['allocated_amount'], '★ 已分配额同步降到 47.80，分毫不差');
eq(2, (int)$oAfter['verify_status'], '订单标记为已冲正');

$refunds = $db->all(
    'SELECT * FROM point_ledger WHERE store_code=? AND serial_id=? AND entry_type=3 ORDER BY id',
    [SMOKE_STORE, '2608130080']);
eq(3, count($refunds), '产生 3 条退单冲正流水（对应 3 位 AA 会员）');
$sumBack = 0;
foreach ($refunds as $r) { $sumBack += (int)round((float)$r['amount'] * -100); }
eq(2390, $sumBack, '★ 冲正合计恰好 23.90 —— 舍入残差由最后一条吸收，不多退也不少退');
foreach ($refunds as $r) {
    eq(0, (int)$r['counted_visit'], '金额缩水不改变计次（客人确实吃了那一份）');
}

step('⑪ 部分分配时不应过度冲正');

// 订单 B：总额 26.85，只记 10.00 给一位会员，然后缩水到 20.00
$svc->locate('32');
$g4 = $svc->grant('2608130081',
    [['member_id' => (int)$mA['id'], 'amount_cents' => 1000, 'portions' => 0]],
    PE::MODE_WHOLE, ['id' => 1, 'name' => '收银员甲']);
ok($g4['ok'], '订单 B 部分分配 10.00');

foreach ($pos->heads as $i => $h) {
    if ($h['serial_id'] === '2608130081') {
        $pos->heads[$i]['original_amount'] = '46.85';
        $pos->heads[$i]['should_amount']   = '46.85';
        $pos->heads[$i]['actual_amount']   = '46.85';
    }
}
$pos->addDetail(92322, 1, [
    FakePosSource::line(2390, 'MENÚ INFINITY NOCHE', '23.90', '23.90', 1),
    FakePosSource::line(1017, 'BOX 17', '26.50', '20.00', 1),
    FakePosSource::line(431,  'Agua',   '2.95',  '2.95',  1),
]);
$app->orders()->markVerified('2608130081', 0);   // 重置以便再次比对
$app->reconcile()->verifyAmounts();

$oB = $app->orders()->findBySerial('2608130081');
eq('10.00', $oB['allocated_amount'],
    '★ 新总额仍高于已分配额 → 一分都不退（若按缩水额去退就退多了）');
eq(0, count($db->all(
    'SELECT 1 FROM point_ledger WHERE store_code=? AND serial_id=? AND entry_type=3',
    [SMOKE_STORE, '2608130081'])), '未产生任何冲正流水');

step('⑫ 数据完整性监控');

$ci = $app->sync()->checkIntegrity(3);
ok($ci['ok'], '完整性监控执行成功');
ok(is_array($ci['findings']), '返回检查结果列表');

// ── 12b. 奖励券：有效期写在券上，不随规则变动 ────────────────
step('⑫bis 奖励券 —— 改规则不影响已发的券');

$setCfg = static function (LocalDb $db, string $k, string $v): void {
    $db->exec('UPDATE sys_config SET config_value = ? WHERE store_code = ? AND config_key = ?',
        [$v, SMOKE_STORE, $k]);
};
// 规则里没有这几项就补上（smoke 库的种子是自己灌的）
foreach (['reward_enabled' => '1', 'reward_mode' => 'visits',
          'reward_threshold_visits' => '10', 'reward_threshold_amount' => '300.00',
          'reward_auto_grant' => '1', 'coupon_valid_days' => '180'] as $k => $v) {
    $db->exec('INSERT INTO sys_config (store_code, config_key, config_value, updated_at)
               VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)',
        [SMOKE_STORE, $k, $v, $db->now()]);
}

$rwMember = $app->members()->create('600990001', null, null);
$rwId     = (int)$rwMember['id'];
$db->exec('UPDATE member SET visit_count = 10 WHERE id = ?', [$rwId]);

$g180 = (new App(['store_code' => SMOKE_STORE, 'local_db' => $dbCfg, 'pos_db' => []]))
    ->rewards()->checkAndGrant($rwId, ['id' => 1, 'name' => 'smoke']);
eq(1, $g180['granted'], '满 10 次发 1 张券');
$cid180 = (int)$g180['coupons'][0]['id'];
$exp180 = date('Y-m-d', strtotime($db->now()) + 180 * 86400);
eq($exp180, (string)$db->value('SELECT valid_to FROM coupon WHERE id = ?', [$cid180]),
    '按当时规则 180 天定下到期日');

// 规则改成 90 天后再发一张
$setCfg($db, 'coupon_valid_days', '90');
$db->exec('UPDATE member SET visit_count = 20 WHERE id = ?', [$rwId]);
$g90 = (new App(['store_code' => SMOKE_STORE, 'local_db' => $dbCfg, 'pos_db' => []]))
    ->rewards()->checkAndGrant($rwId, ['id' => 1, 'name' => 'smoke']);
$cid90 = (int)$g90['coupons'][0]['id'];
eq(date('Y-m-d', strtotime($db->now()) + 90 * 86400),
   (string)$db->value('SELECT valid_to FROM coupon WHERE id = ?', [$cid90]),
   '新券按新规则 90 天');
eq($exp180, (string)$db->value('SELECT valid_to FROM coupon WHERE id = ?', [$cid180]),
   '★ 改规则后，先发的那张仍是 180 天 —— 客人拿到手的券到期日不会变');

// 过期判定读券上的日期，不读当前规则
$db->exec('UPDATE coupon SET valid_to = ? WHERE id = ?',
    [date('Y-m-d', strtotime('-1 day')), $cid90]);
$setCfg($db, 'coupon_valid_days', '3650');
(new App(['store_code' => SMOKE_STORE, 'local_db' => $dbCfg, 'pos_db' => []]))
    ->rewards()->expireStale();
eq(3, (int)$db->value('SELECT status FROM coupon WHERE id = ?', [$cid90]),
   '★ 过期按券上的日期判定（规则改成 3650 天也救不回来）');
eq(1, (int)$db->value('SELECT status FROM coupon WHERE id = ?', [$cid180]),
   '未到期的券不受影响');

// ── 13. 不变量总校验 ─────────────────────────────────────────
step('⑬ 不变量总校验');

$bad = $db->all(
    'SELECT o.serial_id, o.total_amount, o.allocated_amount
       FROM pos_order o
      WHERE o.store_code = ? AND o.allocated_amount > o.total_amount',
    [SMOKE_STORE]);
eq([], $bad, '★ 没有任何订单的已分配额超过可积分总额');

$mismatch = $db->all(
    'SELECT o.serial_id, o.allocated_amount,
            COALESCE(SUM(l.amount), 0) AS ledger_sum
       FROM pos_order o
       LEFT JOIN point_ledger l
              ON l.store_code = o.store_code AND l.serial_id = o.serial_id
      WHERE o.store_code = ?
      GROUP BY o.serial_id, o.allocated_amount
     HAVING ABS(o.allocated_amount - COALESCE(SUM(l.amount), 0)) > 0.001',
    [SMOKE_STORE]);
eq([], $mismatch, '★ 每张订单的 allocated_amount 与其账本流水合计一致');

$memBad = $db->all(
    'SELECT m.card_no, m.points_balance, COALESCE(SUM(l.points),0) AS s
       FROM member m
       LEFT JOIN point_ledger l ON l.member_id = m.id AND l.store_code = m.store_code
      WHERE m.store_code = ?
      GROUP BY m.id, m.card_no, m.points_balance
     HAVING m.points_balance <> COALESCE(SUM(l.points),0)',
    [SMOKE_STORE]);
eq([], $memBad, '★ 每名会员的积分余额与其流水合计一致');

$auditN = (int)$db->value('SELECT COUNT(*) FROM audit_log WHERE store_code=?', [SMOKE_STORE]);
ok($auditN >= 5, "审计日志 {$auditN} 条（发分/撤销/手工录入均留痕）");

// ── 13. 收尾 ─────────────────────────────────────────────────
if ($keep) {
    step('保留数据（--keep），可用 store_code = SMOKE 查看');
} else {
    $cleanup($db);
    step('已清理全部 SMOKE 数据');
    eq(0, (int)$db->value('SELECT COUNT(*) FROM point_ledger WHERE store_code=?', [SMOKE_STORE]),
        '账本已清空');
}

echo "\n" . str_repeat('─', 62) . "\n";
$total = $pass + $fail;
if ($fail === 0) {
    echo "\033[32m冒烟测试通过\033[0m  {$total} 项断言   （{$flavor} {$version}）\n";
    exit(0);
}
echo "\033[31m失败 {$fail}\033[0m / 共 {$total} 项   （{$flavor} {$version}）\n";
exit(1);
