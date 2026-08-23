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

/**
 * ★ 只允许命令行执行。
 *
 *   本脚本不该被放进 wwwroot，但守卫不能依赖「放对了位置」——
 *   文档根一旦配错（比如把项目根整个指过去），这个文件就暴露在网上了。
 *   那时一次未经认证的 GET 就会连上数据库、跑完整个流程（写入再删除
 *   SMOKE 数据），并把库名、主机、数据库版本原样打回页面。
 *
 *   实测（PHP 8.4）：register_argc_argv=On 时，Web 下被查询串填充的是
 *   $_SERVER['argv']（GET /x.php?--fresh → ["--fresh"]），
 *   而全局 $argv 不会被填充 —— 也就是下面那行读到的仍是空数组，
 *   `?--fresh` 触发不了 DROP TABLE。
 *
 *   但【不要】因此觉得可以省掉守卫：
 *     · 上面说的未认证 DB 写入与信息泄漏本身就不可接受；
 *     · 哪天有人把 $argv 改成 $_SERVER['argv']（看着像是「让它到处都能跑」
 *       的合理重构），DROP TABLE 那条路立刻就通了。
 *   守卫放在读参数之前，这两种情况一起挡掉。
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

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
use Vip\CardNumber;
use Vip\Repo\CardRepo;
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
           'meal_period','sys_config','sync_cursor','audit_log','alert','card'];

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
    ok(true, count($tables) . ' 张表均已存在');
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

/**
 * 建会员的测试辅助。
 *
 * 改发实体卡之后，会员不能凭空创建 —— 必须绑一张 card 库存表里真实存在
 * 的卡。这里预生成一批库存卡按需取用，让不关心卡片的那些测试保持原样，
 * 只是把 $newMember(...) 换成 $newMember(...)。
 */
$cardPool  = [];
$newMember = static function (?string $phone = null, ?string $email = null, ?string $bday = null)
        use ($app, &$cardPool): array {
    if (!$cardPool) {
        $cardPool = $app->cards()->generateBatch('SMOKEPOOL', 40);
    }
    $c = array_shift($cardPool);
    $r = $app->cardService()->bindNewMember(
        $c['card_no'], $phone, $email, $bday,
        ['id' => null, 'name' => 'smoke', 'device' => null]
    );
    if (!$r['ok']) {
        die_('测试辅助建会员失败：' . ($r['error'] ?? '?'));
    }
    return $r['member'];
};

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

$mA = $newMember('+34600000001', null, null);
$mB = $newMember(null, 'b@example.com', '1990-05-20');
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

$mC = $newMember('+34600000003', null, null);   // 客人没卡，现场建
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

$rwMember = $newMember('600990001', null, null);
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

// ── 12ter. 整单记给一人时按份数计次 ──────────────────────────
step('⑫ter 整单记给一位会员 —— 几份套餐就算几次');

/**
 * ★ 店家口径：一桌 10 个人吃，账单整单记给其中一位会员，
 *   那一次要算 10 次，不是 1 次 —— 十送一就该当场满。
 *
 * 这条链路上有三个地方可能把它做错，所以三处都要验：
 *   1) Pad 的「整单记给一位会员」必须把【整单份数】传给那一个人
 *      （pad.js 里是 portions: o.remaining_portions，不是 1）；
 *   2) 服务端 visit_count_mode = by_portion 时 counted_visit 取份数；
 *   3) 奖励进度读的是 visit_count，于是一单直接满 10 次发券。
 * 任何一处退化成「每单最多 1 次」，客人就会觉得次数少了却查不出原因。
 */
$pos->addHead([
    'serial_id' => '2608130090', 'order_head_id' => 92390, 'check_id' => 1,
    'table_name' => '50', 'eat_type' => 0, 'customer_num' => 10,
    'original_amount' => '239.00', 'should_amount' => '239.00', 'actual_amount' => '239.00',
    'order_end_time' => '2026-08-13 23:20:00',
]);
$pos->addDetail(92390, 1, [
    // ★ actual_price 是【行合计】不是单价：23.90 × 10 = 239.00（实测真库如此）
    FakePosSource::line(2390, 'MENÚ INFINITY NOCHE', '23.90', '239.00', 10),
]);

$loc10 = $svc->locate('50');
$ctx10 = $loc10['candidates'][0];
eq(10, $ctx10['portions_counted'], '10 人 10 份 → 计次份数 10');

// 全新会员，从 0 次起算，好判断到底加了几次
$mid10 = (int)$newMember('+34600000010', null, null)['id'];
$g10 = $svc->grant('2608130090',
    [['member_id' => $mid10, 'amount_cents' => 23900, 'portions' => 10]],
    PE::MODE_WHOLE, ['id' => 1, 'name' => '收银员甲']);
ok($g10['ok'], '整单记给一位会员成功');
eq(10, (int)$g10['entries'][0]['visits'], '★ 整单记给一人 → 本次计 10 次（不是 1 次）');

eq(10, (int)$db->value('SELECT counted_visit FROM point_ledger WHERE store_code=? AND serial_id=?',
    [SMOKE_STORE, '2608130090']), '账本里 counted_visit = 10');
eq(10, (int)$db->value('SELECT visit_count FROM member WHERE id=?', [$mid10]),
    '★ 会员累计次数 = 10（一单吃满）');

// 十送一：这一单结束就该满一张券
$db->exec('UPDATE sys_config SET config_value=? WHERE store_code=? AND config_key=?',
    ['10', SMOKE_STORE, 'reward_threshold_visits']);
$rw = (new App(['store_code' => SMOKE_STORE, 'local_db' => $dbCfg, 'pos_db' => []]))->rewards();
$gr = $rw->checkAndGrant($mid10, ['id' => 1, 'name' => '收银员甲']);
eq(1, (int)$gr['granted'], '★ 十送一：10 人同桌整单记一人，当场发 1 张券');

// 反向确认：按笔计次时同一单只算 1 次 —— 证明上面的 10 确实来自份数
$db->exec('UPDATE sys_config SET config_value=? WHERE store_code=? AND config_key=?',
    ['by_ledger', SMOKE_STORE, 'visit_count_mode']);
$pos->addHead([
    'serial_id' => '2608130091', 'order_head_id' => 92391, 'check_id' => 1,
    'table_name' => '51', 'eat_type' => 0, 'customer_num' => 10,
    'original_amount' => '239.00', 'should_amount' => '239.00', 'actual_amount' => '239.00',
    'order_end_time' => '2026-08-13 23:25:00',
]);
$pos->addDetail(92391, 1, [
    FakePosSource::line(2390, 'MENÚ INFINITY NOCHE', '23.90', '239.00', 10),
]);
// 配置是按 App 实例缓存的，改完必须新建实例才生效
$svcL = new App(['store_code' => SMOKE_STORE, 'local_db' => $dbCfg, 'pos_db' => []]);
$svcL->setLocalDb($db);
$svcL->setPosSource($pos);
$svcL->points()->locate('51');
$midL = (int)$newMember('+34600000011', null, null)['id'];
$gL = $svcL->points()->grant('2608130091',
    [['member_id' => $midL, 'amount_cents' => 23900, 'portions' => 10]],
    PE::MODE_WHOLE, ['id' => 1, 'name' => '收银员甲']);
eq(1, (int)$gL['entries'][0]['visits'],
    '对照：visit_count_mode=by_ledger 时同样一单只算 1 次');
$db->exec('UPDATE sys_config SET config_value=? WHERE store_code=? AND config_key=?',
    ['by_portion', SMOKE_STORE, 'visit_count_mode']);

// ── 12quater. 份数明细自动读出，不需要人工填 ──────────────────
step('⑫quater 份数明细 —— 付费/免费/漏配都从明细读出来');

/**
 * 收银员不该在 Pad 上手填份数：这些数 POS 明细里全都有。
 *   付费套餐 = counts_visit 行里【行合计 > 0】的份数
 *   免费套餐 = 同上但【行合计 = 0】（整行免单，实测真库确有此形态）
 *
 * ★ actual_price 是行合计不是单价 —— 真库里 17.90 的套餐点 2 份，
 *   该字段存 35.80。所以判「免费」只能看这一行是不是 0，与份数无关。
 */
$pos->addHead([
    'serial_id' => '2608130092', 'order_head_id' => 92392, 'check_id' => 1,
    'table_name' => '52', 'eat_type' => 0, 'customer_num' => 6,
    'original_amount' => '95.60', 'should_amount' => '95.60', 'actual_amount' => '95.60',
    'order_end_time' => '2026-08-13 23:26:00',
]);
$pos->addDetail(92392, 1, [
    // 同一套餐，4 份照付、2 份免单（整行 0）—— 真库里就是这个形态
    FakePosSource::line(2390, 'MENÚ INFINITY NOCHE', '23.90', '95.60', 4),
    FakePosSource::line(2390, 'MENÚ INFINITY NOCHE', '23.90', '0.00',  2),
    FakePosSource::line(99999,'新品套餐（未配规则）',  '20.00', '40.00', 2),
]);
$loc6  = $svcL->points()->locate('52');
$ctx6  = $loc6['candidates'][0];
eq(4, $ctx6['portions_paid'], '★ 付费套餐 4 份（行合计 > 0）');
eq(2, $ctx6['portions_free'], '★ 免费套餐 2 份（行合计 = 0，整行免单）');
eq(6, $ctx6['portions_counted'], '计次份数合计 6 = 付费 4 + 免费 2');
eq(6, (int)$ctx6['customer_num'], '买单人数 6 直接取自 POS');
eq(1, count($ctx6['unknown_items']), '★ 未配规则的菜品被单独列出（否则份数被吞成 0 却看不出原因）');
eq('新品套餐（未配规则）', $ctx6['unknown_items'][0], '列出的是菜品名，前台能直接看懂');

// ── 12quinquies. 有头无明细必须说破 ──────────────────────────
step('⑫quinquies 明细没同步过来 —— 不能只显示「0 份」了事');

/**
 * 实测该店 history_order_detail 明显落后于 history_order_head
 * （订单头到 8-17，明细只到 8-13）。刚结的账因此是「有头无明细」，
 * Pad 上表现为「查得到、套餐 0 份」—— 与「客人没点套餐」无法区分。
 * 收银员照 0 份发分就会把该计的次数永久漏掉，所以必须单独标出来。
 */
$pos->addHead([
    'serial_id' => '2608130093', 'order_head_id' => 92393, 'check_id' => 1,
    'table_name' => '53', 'eat_type' => 0, 'customer_num' => 2,
    'original_amount' => '53.70', 'should_amount' => '53.70', 'actual_amount' => '53.70',
    'order_end_time' => '2026-08-13 23:27:00',
]);
// 故意不 addDetail —— 复现「明细尚未归档」

$locNd = $svcL->points()->locate('53');
$ctxNd = $locNd['candidates'][0];
ok($ctxNd !== null, '有头无明细的订单仍能定位（不该因为没明细就查不到）');
eq(0, $ctxNd['portions_counted'], '份数为 0（没有明细可数）');
ok($ctxNd['detail_missing'] === true, '★ detail_missing 标记为真 —— 前台才能说清「不是没点套餐，是明细没到」');
eq('53.70', $ctxNd['total'], '金额仍按订单头算，不受明细缺失影响');
ok($ctxNd['eligible'], '仍可发分（金额是准的，只是份数要人工确认）');

// 对照：有明细时不得误报
ok($ctx6['detail_missing'] === false, '对照：有明细的订单不会被误标为「明细缺失」');

// ── 12sexies. 纸质券（满50抵5）不得被当成十送一核销 ──────────
step('⑫sexies 满50抵5 纸质券 —— 照常计次积分，不是十送一');

/**
 * 店家另有「满 50 抵 5」活动：发纸质券，【在 POS 上直接核销】，
 * Pad 端不参与。但那张券在 POS 明细里同样是一条 menu_item_id = -2
 * 的折扣伪行，名称 CUPON DE 5 EUROS —— 与十送一的 TARJETA 10+1 同型。
 *
 * ★ 绝不能把它当成十送一核销：那样客人正常付费吃饭却不计次不积分。
 *   实测该活动远比十送一常见（一份样本里 16 张 vs 5 张），
 *   误判的代价是天天在发生。
 *
 * 实测口径（真实订单 92087 / 92157 验证）：
 *   · 券额进订单头的 discount_amount，original + discount = should；
 *   · 积分基数取 min(should, actual) = 券后金额，客人按实付得分；
 *   · 券按 50 叠加，实测有 -5 / -10 / -15 / -30 四种额度；
 *   · 份数只看套餐行，与折扣行无关。
 */
$pos->addHead([
    'serial_id' => '2608130094', 'order_head_id' => 92394, 'check_id' => 1,
    'table_name' => '54', 'eat_type' => 0, 'customer_num' => 2,
    'original_amount' => '53.70', 'should_amount' => '48.70',
    'actual_amount' => '48.70', 'order_end_time' => '2026-08-13 23:28:00',
]);
$pos->addDetail(92394, 1, [
    FakePosSource::line(2390, 'MENÚ INFINITY NOCHE', '23.90', '47.80', 2),
    FakePosSource::line(431,  'Agua',                '2.95',  '5.90',  2),
    // 纸质券的折扣伪行 —— 与十送一同型，只有名称不同
    FakePosSource::line(PE::PSEUDO_DISCOUNT, 'CUPON DE 5 EUROS', '0.00', '-5.00', 0),
]);

$locCp = $svcL->points()->locate('54');
$ctxCp = $locCp['candidates'][0];
ok(!$ctxCp['is_redeemed'], '★ 纸质券【不】被判定为十送一核销（否则客人白吃两次）');
eq([], $ctxCp['redeem_lines'], '核销行清单为空');
eq(2, $ctxCp['portions_counted'], '份数照常算 2（折扣行不影响份数）');
eq('48.70', $ctxCp['total'], '★ 积分基数是券后金额 48.70，客人按实付得分');
ok($ctxCp['eligible'], '订单可正常发分');

// 对照：真正的十送一核销必须仍能认出来
$pos->addHead([
    'serial_id' => '2608130095', 'order_head_id' => 92395, 'check_id' => 1,
    'table_name' => '55', 'eat_type' => 0, 'customer_num' => 2,
    'original_amount' => '53.70', 'should_amount' => '29.80',
    'actual_amount' => '29.80', 'order_end_time' => '2026-08-13 23:29:00',
]);
$pos->addDetail(92395, 1, [
    FakePosSource::line(2390, 'MENÚ INFINITY NOCHE', '23.90', '47.80', 2),
    FakePosSource::line(431,  'Agua',                '2.95',  '5.90',  2),
    FakePosSource::line(PE::PSEUDO_DISCOUNT, 'TARJETA 10+1', '0.00', '-23.90', 0),
]);
$locRd = $svcL->points()->locate('55');
$ctxRd = $locRd['candidates'][0];
ok($ctxRd['is_redeemed'], '对照：TARJETA 10+1 仍被认成十送一核销');
eq('23.90', $ctxRd['redeem_amount'], '核销额 23.90 = 一份套餐');

// ── 12septies. 混合核销单：免的不算，付费的照算 ───────────────
step('⑫septies 混合单 —— 4 人 1 人用券，另外 3 人照常计次');

/**
 * 店家口径（已确认）：4 人同桌，1 人用十送一券免单，其余 3 人正常付费
 * （哪怕他们用了满50减5 的纸质券），这一单就算【3 份】——
 * 可以 AA 给 3 个人，也可以整单记给一人算 3 次。
 *
 * 真实订单 92147 就是这个形态：4 份 18.90 的午市套餐，
 * 券抵 18.90（1 份），另有纸质券 -5.00，实付 63.50。
 */
$pos->addHead([
    'serial_id' => '2608130096', 'order_head_id' => 92396, 'check_id' => 1,
    'table_name' => '56', 'eat_type' => 0, 'customer_num' => 4,
    'original_amount' => '95.60', 'should_amount' => '66.70',
    'actual_amount' => '66.70', 'order_end_time' => '2026-08-13 23:30:00',
]);
$pos->addDetail(92396, 1, [
    FakePosSource::line(2390, 'MENÚ INFINITY NOCHE', '23.90', '95.60', 4),
    FakePosSource::line(PE::PSEUDO_DISCOUNT, 'TARJETA 10+1',     '0.00', '-23.90', 0),
    FakePosSource::line(PE::PSEUDO_DISCOUNT, 'CUPON DE 5 EUROS', '0.00', '-5.00',  0),
]);

// ★ 必须新建 App：ConfigRepo 按实例缓存，$svcL 是在 ⑫ter 改过
//   visit_count_mode 之后构造的，仍持有当时的旧值。
$appMx = new App(['store_code' => SMOKE_STORE, 'local_db' => $dbCfg, 'pos_db' => []]);
$appMx->setLocalDb($db);
$appMx->setPosSource($pos);

$locMx = $appMx->points()->locate('56');
$ctxMx = $locMx['candidates'][0];
eq(4, $ctxMx['portions_total'],    '明细里共 4 份套餐');
eq(1, $ctxMx['portions_redeemed'], '★ 券抵掉 1 份（23.90 ÷ 23.90，从核销额反推）');
eq(3, $ctxMx['portions_counted'],  '★ 可计次 3 份 —— 付了钱的那 3 位不该被吞掉');
eq(3, $ctxMx['remaining_portions'],'可分配份数 3');
ok($ctxMx['eligible'], '★ 混合单可以发分（早先是整单拒绝，3 位客人白吃）');
ok($ctxMx['is_redeemed'], '仍标记为含核销，审计能看出来');

// 整单记给一人 → 应计 3 次
$midMx = (int)$newMember('+34600000012', null, null)['id'];
$gMx = $appMx->points()->grant('2608130096',
    [['member_id' => $midMx, 'amount_cents' => 6670, 'portions' => 3]],
    PE::MODE_WHOLE, ['id' => 1, 'name' => '收银员甲']);
ok($gMx['ok'], '发分成功');
eq(3, (int)$gMx['entries'][0]['visits'], '★ 整单记给一人 → 计 3 次（不是 0，也不是 4）');
eq(3, (int)$db->value('SELECT visit_count FROM member WHERE id=?', [$midMx]), '会员累计 3 次');

// 对照：整桌都用券免单时，仍然一次都不给
$pos->addHead([
    'serial_id' => '2608130097', 'order_head_id' => 92397, 'check_id' => 1,
    'table_name' => '57', 'eat_type' => 0, 'customer_num' => 2,
    'original_amount' => '47.80', 'should_amount' => '0.00',
    'actual_amount' => '0.00', 'order_end_time' => '2026-08-13 23:31:00',
]);
$pos->addDetail(92397, 1, [
    FakePosSource::line(2390, 'MENÚ INFINITY NOCHE', '23.90', '47.80', 2),
    FakePosSource::line(PE::PSEUDO_DISCOUNT, 'TARJETA 10+1', '0.00', '-47.80', 0),
]);
$locAll = $appMx->points()->locate('57');
$ctxAll = $locAll['candidates'][0];
eq(2, $ctxAll['portions_redeemed'], '对照：券抵掉 2 份 = 全部');
eq(0, $ctxAll['portions_counted'],  '★ 整桌兑换 → 可计次 0 份');
ok(!$ctxAll['eligible'], '★ 整单兑换仍不可发分（原有口径不变）');

// ── 13. 不变量总校验 ─────────────────────────────────────────
step('⑭ 实体卡库存 —— 真伪只由 card 表说了算');

$cards  = $app->cards();
$cardNo = $app->cardNumber();

// ── 批次生成 ──
$batch = $cards->generateBatch('SMOKEB1', 5);
eq(5, count($batch), '生成 5 张卡');
eq(5, count(array_unique(array_column($batch, 'card_no'))), '卡号互不重复');
eq(5, count(array_unique(array_column($batch, 'serial'))), '顺序号互不重复');

$first = $batch[0];
ok($cardNo->isWellFormed($first['card_no']), "卡号结构合法（{$first['display']}）");
eq(6, strlen($first['pin']), 'PIN 是 6 位');
ok(ctype_digit($first['pin']), 'PIN 是纯数字（好印、好念、报的时候没字母歧义）');

// ── 明文 PIN 绝不入库 ──
$row = $cards->findByCardNo($first['card_no']);
ok($row !== null, '卡能按卡号查到');
ok(!str_contains((string)$row['pin_hash'], $first['pin']),
   '★ 库里存的是 hash，不含明文 PIN');
ok(str_starts_with((string)$row['pin_hash'], '$2y$'),
   '★ 用 bcrypt（与收银员 PIN 同一套做法）');
eq(0, (int)$row['status'], '新卡状态是「库存中」');
eq(null, $row['member_id'], '新卡未绑定任何会员');

// 同一个 PIN 在不同卡上存出来的 hash 不同（每条独立加盐，防彩虹表）
$sameP = password_hash($first['pin'], PASSWORD_BCRYPT);
ok($sameP !== $row['pin_hash'], '★ 相同 PIN 的两次 hash 不相等（每条独立加盐）');

// ── 这一层才是防伪造的真正防线 ──
$forged = $cardNo->make(99999999);
ok($cardNo->isWellFormed($forged), '伪造的卡号结构上完全合法');
eq(null, $cards->findByCardNo($forged),
   '★ 但库里没有 → 查不到。结构合法 ≠ 卡存在，真伪只由 card 表说了算');

// ── 手输容错 ──
$typed = str_replace('0', 'O', $first['display']);          // 照卡面把 0 读成 O
ok($cards->findByCardNo($typed) !== null, "★ 手输把 0 打成 O 也能找到（{$typed}）");
ok($cards->findByCardNo(strtolower($first['display'])) !== null, '小写也能找到');

// ── PIN 校验与锁定 ──
$row = $cards->findByCardNo($first['card_no']);
ok($cards->verifyPin($row, $first['pin'])['ok'], '正确 PIN 通过');

$row = $cards->findByCardNo($first['card_no']);
$bad = $cards->verifyPin($row, '000000');
ok(!$bad['ok'] && $bad['error'] === 'pin_wrong', '错误 PIN 被拒');

// 连错到阈值要锁定 —— 卡背 PIN 是静态的，不锁就能慢慢穷举 100 万种
// 注意上面那次 '000000' 已经算一次失败，所以这里从第 2 次开始数
$row      = $cards->findByCardNo($first['card_no']);
$failSoFar = (int)$row['pin_fail'];
$lockAt   = null;
for ($i = 0; $i < 8; $i++) {
    $row = $cards->findByCardNo($first['card_no']);
    $r   = $cards->verifyPin($row, '999999');
    if (($r['error'] ?? '') === 'pin_locked') { $lockAt = $failSoFar + $i + 1; break; }
}
eq(CardRepo::PIN_MAX_FAIL, $lockAt,
   '★ 累计错到第 ' . CardRepo::PIN_MAX_FAIL . ' 次时锁定');

$row = $cards->findByCardNo($first['card_no']);
$still = $cards->verifyPin($row, $first['pin']);
ok(!$still['ok'] && $still['error'] === 'pin_locked',
   '★ 锁定期内即使 PIN 正确也拒绝（否则锁了等于没锁）');

$cards->resetPinFail((int)$row['id']);
$row = $cards->findByCardNo($first['card_no']);
ok($cards->verifyPin($row, $first['pin'])['ok'], '解锁后正确 PIN 恢复通过');

// ── 扫卡建会员：走真实流程 ──
$svc   = $app->cardService();
$opStub = ['id' => null, 'name' => 'smoke', 'device' => null];

$look = $svc->lookup($first['card_no']);
ok($look['ok'] && $look['state'] === 'stock', '库存卡 lookup → state=stock（该弹建卡表单）');

$bind = $svc->bindNewMember($first['card_no'], '600100200', null, null, $opStub);
ok($bind['ok'], '扫库存卡 + 填手机号 → 建会员并绑卡');
$m = $bind['member'];
eq(CardNumber::normalize($first['card_no']), $m['card_no'], '会员行上的卡号与实体卡一致');

$look = $svc->lookup($first['card_no']);
ok($look['ok'] && $look['state'] === 'active', '再扫同一张 → state=active（直接进该会员）');
eq((int)$m['id'], (int)$look['member']['id'], '认出的是同一位会员');

// 已绑定的卡不能再拿去建新会员 —— 该走「直接进入该会员」
$again = $svc->bindNewMember($first['card_no'], '600100201', null, null, $opStub);
ok(!$again['ok'] && $again['error'] === 'card_taken', '★ 已绑定的卡不能再建新会员');

// 同一个会员不能再绑第二张卡 —— 数据库唯一键挡住，不靠应用层自觉
$second = $batch[1];
$row2   = $cards->findByCardNo($second['card_no']);
$twoCards = false;
try { $cards->activate((int)$row2['id'], (int)$m['id'], null); }
catch (\Throwable $e) { $twoCards = true; }
ok($twoCards, '★ 一人一卡由 uk_member 唯一键在数据库层保证');

// ── 不在库存里的卡 ──
$forgedLook = $svc->lookup($forged);
ok(!$forgedLook['ok'] && $forgedLook['error'] === 'card_unknown',
   '★ 结构合法但不在库存 → card_unknown（防伪造的真正防线）');
$junk = $svc->lookup('随便扫到的别的二维码');
ok(!$junk['ok'] && $junk['error'] === 'card_malformed',
   '扫错二维码 → card_malformed（提示「卡号不完整」比「查无此卡」有用）');

// ── 挂失换卡：走真实流程 ──
$rep = $svc->replaceCard((int)$m['id'], $second['card_no'], '客人报失', $opStub);
ok($rep['ok'], '挂失换卡成功');

$row = $cards->findByCardNo($first['card_no']);
eq(2, (int)$row['status'], '旧卡状态变为「已作废」');
eq(null, $row['member_id'],
   '★ 作废时必须清空 member_id，否则唯一键会挡住这位会员绑新卡');

$row2 = $cards->findByCardNo($second['card_no']);
eq((int)$m['id'], (int)$row2['member_id'], '★ 新卡已绑到同一位会员');
eq(CardNumber::normalize($second['card_no']),
   $app->members()->findById((int)$m['id'])['card_no'],
   '★ 会员行上的 card_no 也同步到新卡（冗余字段必须有人负责同步）');

$voidLook = $svc->lookup($first['card_no']);
ok(!$voidLook['ok'] && $voidLook['error'] === 'card_void', '扫已作废的旧卡 → card_void');

// ── 批次统计 ──
$b = null;
foreach ($cards->batches() as $x) { if ($x['batch_no'] === 'SMOKEB1') { $b = $x; } }
ok($b !== null, '批次能查到');
eq(5, (int)$b['total'], '批次共 5 张');
eq(1, (int)$b['active'], '其中 1 张已激活（换发的新卡）');
eq(1, (int)$b['void_cnt'], '其中 1 张已作废（挂失的旧卡）');

// ── 拒绝不合理的批次参数 ──
foreach ([[0, '数量为 0'], [5001, '数量超上限']] as [$n, $why]) {
    $threw = false;
    try { $cards->generateBatch('SMOKEB2', $n); }
    catch (\InvalidArgumentException $e) { $threw = true; }
    ok($threw, "{$why}时拒绝生成");
}
$threw = false;
try { $cards->generateBatch('SMOKEB1', 1); }
catch (\InvalidArgumentException $e) { $threw = true; }
ok($threw, '★ 批次号重复时拒绝（否则盘点时对不上账）');

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
