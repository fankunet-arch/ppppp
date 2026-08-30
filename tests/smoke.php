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
 * 🔴 --fresh 请给它一个【专用空库】，不要指着开发库跑：
 *
 *     CREATE DATABASE vip_smoke DEFAULT CHARSET utf8mb4
 *            COLLATE utf8mb4_unicode_ci;
 *
 *     SMOKE_DB_HOST=127.0.0.1 SMOKE_DB_NAME=vip_smoke \
 *     SMOKE_DB_USER=... SMOKE_DB_PASS=... php tests/smoke.php --fresh
 *
 *   开发库里有种子数据和浏览器测试留下的卡（store_code != 'SMOKE'），
 *   下面那道安全检查会直接拒绝执行 —— 这是对的，不要去绕过它，
 *   换一个空库就行。
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

    /**
     * ★ 先把表全删干净，别指望迁移自己清场。
     *
     * 早先这里直接跑迁移，靠 001/002 里的 DROP TABLE 来清空。但后来新增的
     * 表用的是 CREATE TABLE IF NOT EXISTS（为了不触发 init.php 的破坏性
     * 迁移闸门）—— 没有任何东西会删它们。于是重跑 --fresh 时，表还在、
     * 列也还在，后面某个 ALTER 就撞上「Duplicate column」，
     * 报错指向迁移文件，看着像迁移写错了，其实是没清干净。
     *
     * --fresh 就该是 fresh。
     */
    foreach (array_merge($tables, ['schema_migration']) as $t) {
        $db->pdo()->exec("DROP TABLE IF EXISTS `{$t}`");
    }

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
    /**
     * ★ 防刷闸门在【绝大多数段落里必须关掉】。
     *
     * 冒烟用的假订单结账时间是固定的 2026-08-13，跑测试的那天永远比它晚 ——
     * 于是每一单都算「补记」，每一次 grant 都会被拦成 manager_required。
     * 那样测到的全是闸门，而不是这些段落真正要测的分配算法。
     *
     * 闸门本身在 ㉒ 段单独测：那一段会把它打开，并且用【当天】的订单
     * 走完「拦下 → 经理带原因放行」的全过程。
     */
    'late_grant_minutes'      => '0',
    'max_grants_per_period'   => '0',
    'alert_grants_per_day'    => '0',
    'alert_span_hours'        => '0',
    'merge_span_minutes'      => '60',
    'merge_max_orders'        => '8',
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

/**
 * 餐期 —— 以前冒烟不灌这个，因为没有任何代码读 meal_period。
 *
 * 现在「一张卡一个餐期最多 1 次」要靠它分中午和晚上，不灌的话
 * MealPeriod 会退回「同一营业日」这个更粗的口径，于是
 * 「中午来过、晚上又来」被当成同一顿，测出来的是错的结论。
 * 这一条是写这段测试时踩到的：断言先红了，才发现库里一条餐期都没有。
 */
$db->exec('DELETE FROM meal_period WHERE store_code = ?', [SMOKE_STORE]);
foreach ([['白天', '11:00:00', '18:00:00', 0, 1],
          ['晚上', '19:30:00', '02:00:00', 1, 2]] as [$nm, $st, $en, $cross, $so]) {
    $db->exec('INSERT INTO meal_period (store_code, period_name, start_time, end_time, cross_midnight, sort_order)
               VALUES (?,?,?,?,?,?)', [SMOKE_STORE, $nm, $st, $en, $cross, $so]);
}
eq(2, (int)$db->value('SELECT COUNT(*) FROM meal_period WHERE store_code = ?', [SMOKE_STORE]),
   '写入 2 个餐期（白天 / 晚上）');

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
$batch = $cards->generateBatch('SMOKEB1', 8);
eq(8, count($batch), '生成 8 张卡');
eq(8, count(array_unique(array_column($batch, 'card_no'))), '卡号互不重复');
eq(8, count(array_unique(array_column($batch, 'serial'))), '顺序号互不重复');

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

// ── 卡片默认不实名 ──
/**
 * 凭卡号 + 卡背 PIN 即可积分与兑换，系统里不存任何可识别到人的数据。
 * 没有个人数据就没有可同意的对象 —— 积分当场生效，不冻结。
 *
 * 留联系方式那条路【保留】着，为的是以后要上实名时链路是通的：
 * 一旦填了手机号或邮箱，这条记录重新落入个人数据范畴，
 * 双重确认那套照旧（待确认 + 积分冻结）。
 */
$anonCard = $batch[2];
$anon = $svc->bindNewMember($anonCard['card_no'], null, null, null, $opStub);
ok($anon['ok'], '不填任何联系方式也能绑卡');
eq(1, (int)$anon['member']['consent_status'],
   '★ 匿名卡直接置为已生效（没有个人数据就没有可同意的对象）');
eq(null, $anon['member']['phone'], '库里没有手机号');
eq(null, $anon['member']['email'], '库里没有邮箱');
ok($anon['member']['consent_at'] !== null, '同意时间记为创建时间');

$piiCard = $batch[3];
$pii = $svc->bindNewMember($piiCard['card_no'], '600888777', null, null, $opStub);
ok($pii['ok'], '留手机号也能绑卡');
eq(0, (int)$pii['member']['consent_status'],
   '★ 留了联系方式则回到待确认（实名那条路仍然通着，供日后启用）');
eq('600888777', $pii['member']['phone'], '手机号已存');

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
eq(8, (int)$b['total'], '批次共 8 张');
eq(3, (int)$b['active'],
   '其中 3 张已激活（换发的新卡 + 匿名卡 + 留了手机号的卡）——\n    这条断言在确认码那一段【之前】执行，后面还会再激活两张');
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

step('⑮ 核销验卡背 PIN —— 唯一真正会造成损失的一步');

/**
 * 二维码印在卡正面可被拍照复制，PIN 藏在刮开层下只有真正拿到卡的人知道。
 * 所以核销要验 PIN，而积分入账那一侧不验 ——
 * 被人抄卡去攒分，店家没有损失，受害者反而多了分。
 */
$pinBatch = $app->cards()->generateBatch('SMOKEPIN', 2);
$pinCard  = $pinBatch[0];
$bindR    = $app->cardService()->bindNewMember(
    $pinCard['card_no'], '600777001', null, null, $opStub
);
ok($bindR['ok'], '备一位持卡会员');
$pinMid = (int)$bindR['member']['id'];

// 发一张券给他
$rw = $app->rewards();
$db->exec('INSERT INTO coupon (store_code, member_id, coupon_type, source, code, status, created_at)
           VALUES (?,?,?,?,?,?,?)',
    [SMOKE_STORE, $pinMid, 1, 3, 'SMOKEPINC1', 1, $db->now()]);
$couponId = (int)$db->lastInsertId();

$cashier = ['id' => 9, 'name' => '收银员', 'role' => 1];
$manager = ['id' => 8, 'name' => '经理',   'role' => 2];

// ── 不给 PIN ──
$r = $rw->redeem($couponId, null, $cashier);
ok(!$r['ok'] && $r['error'] === 'pin_required', '★ 不给 PIN 不给核销');
eq(1, (int)$db->value('SELECT status FROM coupon WHERE id=?', [$couponId]), '券仍未使用');

// ── PIN 不对 ──
$r = $rw->redeem($couponId, null, $cashier, '000000');
ok(!$r['ok'] && $r['error'] === 'pin_wrong', 'PIN 不对不给核销');
eq(1, (int)$db->value('SELECT status FROM coupon WHERE id=?', [$couponId]), '券仍未使用');

// ── 收银员不能强制核销 ──
$r = $rw->redeem($couponId, null, $cashier, null, ['reason' => '想跳过']);
ok(!$r['ok'] && $r['error'] === 'forbidden', '★ 收银员强制核销被拒（需经理及以上）');

// ── 经理强制核销必须填原因 ──
$r = $rw->redeem($couponId, null, $manager, null, ['reason' => '  ']);
ok(!$r['ok'] && $r['error'] === 'reason_required', '★ 强制核销必须填原因');

// ── 正确 PIN ──
$app->cards()->resetPinFail((int)$app->cards()->findByCardNo($pinCard['card_no'])['id']);
$r = $rw->redeem($couponId, 'SER001', $cashier, $pinCard['pin']);
ok($r['ok'], '★ 正确 PIN 核销成功');
ok(!$r['forced'], '走的是正常路径，不是强制');
eq(2, (int)$db->value('SELECT status FROM coupon WHERE id=?', [$couponId]), '券状态变为已核销');

$audit = $db->one('SELECT * FROM audit_log WHERE store_code=? AND action=? ORDER BY id DESC',
    [SMOKE_STORE, 'coupon_redeem']);
ok($audit !== null, '正常核销记 coupon_redeem 审计事件');

// ── 经理强制核销（另一张券）──
$db->exec('INSERT INTO coupon (store_code, member_id, coupon_type, source, code, status, created_at)
           VALUES (?,?,?,?,?,?,?)',
    [SMOKE_STORE, $pinMid, 1, 3, 'SMOKEPINC2', 1, $db->now()]);
$couponId2 = (int)$db->lastInsertId();

$r = $rw->redeem($couponId2, null, $manager, null, ['reason' => '客人忘记卡背 PIN']);
ok($r['ok'] && $r['forced'], '★ 经理带原因可强制核销');
eq(2, (int)$db->value('SELECT status FROM coupon WHERE id=?', [$couponId2]), '券已核销');

$forcedLog = $db->one('SELECT * FROM audit_log WHERE store_code=? AND action=? ORDER BY id DESC',
    [SMOKE_STORE, 'coupon_redeem_forced']);
ok($forcedLog !== null, '★ 强制核销记的是单独的 coupon_redeem_forced 事件');
ok(str_contains((string)$forcedLog['detail'], '客人忘记'), '原因写进了审计明细');

// ── 挂失后没有卡 ──
$db->exec('INSERT INTO coupon (store_code, member_id, coupon_type, source, code, status, created_at)
           VALUES (?,?,?,?,?,?,?)',
    [SMOKE_STORE, $pinMid, 1, 3, 'SMOKEPINC3', 1, $db->now()]);
$couponId3 = (int)$db->lastInsertId();
$app->cards()->void((int)$app->cards()->findByCardNo($pinCard['card_no'])['id'], '测试挂失');

$r = $rw->redeem($couponId3, null, $cashier, '123456');
ok(!$r['ok'] && $r['error'] === 'card_missing',
   '★ 会员当前没有卡时说清楚（别让人对着 PIN 框干瞪眼）');

// 但经理仍可强制核销 —— 补卡之前不该卡住客人
$r = $rw->redeem($couponId3, null, $manager, null, ['reason' => '卡已挂失待补发']);
ok($r['ok'] && $r['forced'], '★ 没有卡时经理仍可强制核销');

step('⑯ 现场确认码 —— 双重确认改成不依赖公网入口');

/**
 * 原方案是「客人点短信里的链接」，那需要一个公网可达的端点接收点击，
 * 而门店网络是单向的（能出去、进不来）—— 这条路根本走不通，
 * 而这个矛盾在原设计里没被发现。
 *
 * 改成现场输码：只发一个 6 位码（纯出站），客人当场报给收银员。
 * 举证靠审计日志：发送时间、发到哪个渠道、校验通过时间、经手的操作员。
 */

/** 发送器替身 —— 不真发，只把最后一条消息记下来供断言 */
$fakeMsg = new class([]) extends \Vip\Service\Messaging {
    public array $sent = [];
    public function ready(string $channel): bool { return true; }
    public function readyChannels(): array { return ['sms', 'email']; }
    public function send(string $channel, string $to, string $subject, string $text): array
    {
        $this->sent[] = compact('channel', 'to', 'subject', 'text');
        return ['ok' => true];
    }
};

$consent = new \Vip\Service\ConsentService(
    $db, $app->members(), $fakeMsg, $app->cfg(), $app->audit(), SMOKE_STORE, '冒烟店'
);
$opC = ['id' => 7, 'name' => '收银员', 'device' => 'PAD-S'];

// 备一位留了手机号的会员
$cCard = $batch[4];
$cBind = $svc->bindNewMember($cCard['card_no'], '600666555', null, null, $opStub);
ok($cBind['ok'], '备一位留了手机号的会员');
$cMid = (int)$cBind['member']['id'];
eq(0, (int)$cBind['member']['consent_status'], '留了联系方式 → 待确认');

// ── 发码 ──
$r = $consent->sendCode($cMid, $opC);
ok($r['ok'] && $r['channel'] === 'sms', '★ 有手机号 → 走短信');
eq(1, count($fakeMsg->sent), '发出了一条');

$msgText = $fakeMsg->sent[0]['text'];
ok(preg_match('/\b(\d{6})\b/', $msgText, $mm) === 1, '消息里有 6 位码');
$realCode = $mm[1];
ok(str_contains($msgText, '冒烟店'), '消息里带店名（客人要知道是谁发的）');
eq('600666555', $fakeMsg->sent[0]['to'], '发到客人留的手机号');

$row = $db->one('SELECT * FROM member WHERE id = ?', [$cMid]);
ok(!str_contains((string)$row['consent_code_hash'], $realCode),
   '★ 库里存的是 hash，不含明文码');
eq('sms', $row['consent_channel'], '记下走的哪个渠道（举证要说清发到哪里）');
ok($row['consent_code_sent_at'] !== null, '记下发送时间');

$log = $db->one('SELECT * FROM audit_log WHERE store_code=? AND action=? ORDER BY id DESC',
    [SMOKE_STORE, 'consent_code_sent']);
ok($log !== null, '发码留了审计');
ok(!str_contains((string)$log['detail'], '600666555'),
   '★ 审计里的收件人做了掩码 —— 日志本身不该成为一份联系方式清单');

// ── 校验 ──
$bad = $consent->verifyCode($cMid, '000000', $opC);
ok(!$bad['ok'] && $bad['error'] === 'code_wrong', '码错了不给过');
ok(($bad['left'] ?? null) !== null, '告诉还能试几次');

$good = $consent->verifyCode($cMid, $realCode, $opC, '192.168.2.9');
ok($good['ok'], '★ 正确的码通过');

$row = $db->one('SELECT * FROM member WHERE id = ?', [$cMid]);
eq(1, (int)$row['consent_status'], '★ 积分解冻（consent_status = 1）');
eq(null, $row['consent_code_hash'], '通过后码立即作废，不留在库里');
eq('192.168.2.9', $row['consent_ip'], '记下确认来源 IP，举证用');

$log = $db->one('SELECT * FROM audit_log WHERE store_code=? AND action=? ORDER BY id DESC',
    [SMOKE_STORE, 'consent_confirmed']);
ok($log !== null, '★ 确认通过留了审计（这就是举证链条）');

// 幂等：已确认的再确认一次不报错
ok($consent->verifyCode($cMid, 'whatever', $opC)['ok'], '已确认的会员重复确认不报错');

// ── 连错锁定 ──
$cCard2 = $batch[5];
$cBind2 = $svc->bindNewMember($cCard2['card_no'], '600666444', null, null, $opStub);
$cMid2  = (int)$cBind2['member']['id'];
$consent->sendCode($cMid2, $opC);

$lockAt = null;
for ($i = 1; $i <= 8; $i++) {
    $rr = $consent->verifyCode($cMid2, '111111', $opC);
    if (($rr['error'] ?? '') === 'code_locked') { $lockAt = $i; break; }
}
eq(\Vip\Service\ConsentService::MAX_FAIL + 1, $lockAt,
   '★ 连错 ' . \Vip\Service\ConsentService::MAX_FAIL . ' 次后锁定（防穷举 6 位码）');

// 重发换新码，并解掉锁
$before = count($fakeMsg->sent);
$r2 = $consent->sendCode($cMid2, $opC);
ok($r2['ok'], '★ 锁住之后可以重发');
eq($before + 1, count($fakeMsg->sent), '确实又发了一条');
preg_match('/\b(\d{6})\b/', $fakeMsg->sent[count($fakeMsg->sent) - 1]['text'], $m2);
ok($consent->verifyCode($cMid2, $m2[1], $opC)['ok'], '★ 新码可用（重发会重置失败计数）');

// ── 匿名会员没有可确认的对象 ──
$anonR = $consent->sendCode((int)$anon['member']['id'], $opC);
ok(!$anonR['ok'] && $anonR['error'] === 'consent_already_done',
   '★ 匿名卡本来就是已生效状态，不需要也不能再确认');

step('⑰ 卡片有效期 —— 卡面日期是唯一的告知证据');

/**
 * 为什么用「固定日期印在卡上」而不是「N 个月不活跃就清零」：
 * 客人查不到任何线上信息，手里只有一张卡。不活跃期这种规则他无从判断
 * 自己处在什么位置，一旦投诉，店家拿不出「已告知」的证据。
 * 而固定日期印在卡面 —— 卡片本身就是证据。
 *
 * 缺陷是常客也会被清零，补法是「有效期属于卡片，不属于积分」：
 * 到店换卡则积分全部结转。以下把这条链路钉住。
 */
$today    = date('Y-m-d');
$past     = date('Y-m-d', strtotime('-1 day'));
$soon     = date('Y-m-d', strtotime('+10 days'));
$far      = date('Y-m-d', strtotime('+3 years'));

// ── 生成时的有效期校验 ──
foreach ([[$past, '已经过去的日期'], [$today, '就是今天'], ['2026-13-45', '格式不对']] as [$bad, $why]) {
    $threw = false;
    try { $cards->generateBatch('SMOKEVT' . substr(md5($bad), 0, 4), 1, $bad); }
    catch (\InvalidArgumentException $e) { $threw = true; }
    ok($threw, "{$why}的有效期被拒绝");
}

$vb = $cards->generateBatch('SMOKEVAL', 3, $far);
eq($far, $vb[0]['valid_to'], '★ 有效期写进了卡（印刷清单里也要有这一列）');
eq($far, (string)$cards->findByCardNo($vb[0]['card_no'])['valid_to'], '库里存下来了');

// ── 判定 ──
$live = $cards->findByCardNo($vb[0]['card_no']);
ok(!CardRepo::isExpired($live), '未到期的卡不算过期');
ok(CardRepo::daysLeft($live) > 300, '剩余天数算得出来');

$fake = ['valid_to' => $past];
ok(CardRepo::isExpired($fake), '★ 过了 valid_to 就算过期');
ok(CardRepo::daysLeft($fake) < 0, '过期后剩余天数是负的');
ok(!CardRepo::isExpired(['valid_to' => null]), '不设有效期的卡永不过期');
$G = $svc->graceMonths();
eq(CardRepo::GRACE_MONTHS, $G, '宽限期默认取到 ' . CardRepo::GRACE_MONTHS . ' 个月');
ok(!CardRepo::graceOver(['valid_to' => $past], $G), '刚过期还在宽限期内');
ok(CardRepo::graceOver(['valid_to' => date('Y-m-d', strtotime('-8 months'))], $G),
   '★ 超过 ' . $G . ' 个月宽限才算彻底失效');

/**
 * 宽限期是后台可调的 —— 改了必须【当场】生效。
 *
 * 用同一个 $svc 验，不新建对象：真实现场就是后台改完、Pad 下一次请求就该
 * 按新值判。ConfigRepo 有缓存，set() 里清了 —— 这条断言守的正是那一句，
 * 漏了的话表现是「后台数字改了但行为不变」，最难查的一类。
 */
$app->cfg()->set('card_grace_months', '1');
eq(1, $svc->graceMonths(), '★ 后台把宽限期改成 1 个月，服务层当场读到 1');
ok(CardRepo::graceOver(['valid_to' => date('Y-m-d', strtotime('-3 months'))], $svc->graceMonths()),
   '★ 按新的 1 个月判定，3 个月前过期的卡已超宽限');
$app->cfg()->set('card_grace_months', '0');
eq(0, $svc->graceMonths(), '0 是有意义的取值（过期即不能换），不该被兜底吃掉');
$app->cfg()->set('card_expiring_soon_days', '45');
eq(45, $svc->expiringSoonDays(), '提醒天数同样可调');
$app->cfg()->set('card_grace_months', (string)CardRepo::GRACE_MONTHS);
$app->cfg()->set('card_expiring_soon_days', (string)CardRepo::EXPIRING_SOON_DAYS);
eq(CardRepo::GRACE_MONTHS, $svc->graceMonths(), '改回默认值');

// ── 过期卡不能发给客人 ──
$expBatch = $cards->generateBatch('SMOKEEXP', 2, $far);
// 直接把库里的日期改成过去，模拟「库存里躺过期了」
$db->exec('UPDATE card SET valid_to = ? WHERE store_code = ? AND batch_no = ?',
    [$past, SMOKE_STORE, 'SMOKEEXP']);

$r = $svc->bindNewMember($expBatch[0]['card_no'], null, null, null, $opStub);
ok(!$r['ok'] && $r['error'] === 'card_expired',
   '★ 库存里躺过期的卡不能发给客人（发了他拿回家就是废卡）');

$look = $svc->lookup($expBatch[0]['card_no']);
eq('expired', $look['state'], '扫过期卡 → state=expired');

// ── 换卡结转：这是整条规则成立的关键 ──
$oldCard = $vb[1];
$bindR   = $svc->bindNewMember($oldCard['card_no'], null, null, null, $opStub);
ok($bindR['ok'], '先发一张正常的卡并激活');
$vMid = (int)$bindR['member']['id'];

/**
 * 攒点积分与计次，再把这张卡改成已过期。
 *
 * ★ 必须同时写流水。applyDelta 只动余额，而 ⑬ 段有一条不变量
 *   「每名会员的积分余额与其流水合计一致」—— 只改余额不写流水，
 *   那条断言会红，而它是对的：造假数据的是测试，不是产品。
 */
$app->members()->applyDelta($vMid, 120, 7, 5000);
$db->exec('INSERT INTO point_ledger
             (store_code, member_id, entry_type, amount, points, counted_visit,
              status, source, manual_reason, created_at)
           VALUES (?,?,?,?,?,?,?,?,?,?)',
    [SMOKE_STORE, $vMid, 6, 50.00, 120, 7, 1, 2, '测试造数', $db->now()]);
$db->exec('UPDATE card SET valid_to = ? WHERE store_code = ? AND card_no = ?',
    [$past, SMOKE_STORE, CardNumber::normalize($oldCard['card_no'])]);

$before = $app->members()->findById($vMid);
eq(120, (int)$before['points_balance'], '换卡前有 120 分');
eq(7,   (int)$before['visit_count'],    '换卡前已消费 7 次');

$look = $svc->lookup($oldCard['card_no']);
eq('expired', $look['state'], '过期卡扫出来是 expired');
ok(($look['member']['id'] ?? 0) === $vMid,
   '★ 过期卡要把「绑的是谁」一并带回 —— Pad 才能直接进换卡，不用再查一遍');

$newCard = $vb[2];
$rep = $svc->replaceCard($vMid, $newCard['card_no'], '原卡到期', $opStub);
ok($rep['ok'], '★ 过期卡可以换发新卡');

$after = $app->members()->findById($vMid);
eq(120, (int)$after['points_balance'], '★ 积分完整结转');
eq(7,   (int)$after['visit_count'],    '★ 计次完整结转');
eq(CardNumber::normalize($newCard['card_no']), $after['card_no'], '会员行指向新卡');
eq(2, (int)$cards->findByCardNo($oldCard['card_no'])['status'], '旧卡已作废');

$log = $db->one('SELECT * FROM audit_log WHERE store_code=? AND action=? ORDER BY id DESC',
    [SMOKE_STORE, 'card_replace']);
ok($log !== null && str_contains((string)$log['detail'], 'old_valid_to'),
   '★ 换卡审计里记下新旧卡的有效期（客人申诉时要查的就是这个）');

// 新卡本身过期的话不能拿来换
$db->exec('UPDATE card SET valid_to = ? WHERE store_code = ? AND batch_no = ?',
    [$past, SMOKE_STORE, 'SMOKEVAL']);
$r2 = $svc->replaceCard($vMid, $expBatch[1]['card_no'], '再换', $opStub);
ok(!$r2['ok'] && $r2['error'] === 'card_expired', '★ 不能换成另一张过期卡（换了个寂寞）');

/**
 * ── 超过宽限期：前台换不了，经理带原因才行 ──────────────
 *
 * 这一段守的是「宽限期真的会拦」。之前 graceOver() 写了也测了，
 * 但没有任何一处调用它 —— 一张 2019 年过期的卡照样能换。
 * 光测工具函数不够，必须测到 replaceCard 这一层。
 */
$farNew  = date('Y-m-d', strtotime('+2 years'));
$gb      = $cards->generateBatch('SMOKEGRC', 3, $farNew);
$bindG   = $svc->bindNewMember($gb[0]['card_no'], null, null, null, $opStub);
ok($bindG['ok'], '再发一张卡用于宽限期测试');
$gMid = (int)$bindG['member']['id'];
$app->members()->applyDelta($gMid, 60, 3, 2000);
$db->exec('INSERT INTO point_ledger
             (store_code, member_id, entry_type, amount, points, counted_visit,
              status, source, manual_reason, created_at)
           VALUES (?,?,?,?,?,?,?,?,?,?)',
    [SMOKE_STORE, $gMid, 6, 20.00, 60, 3, 1, 2, '测试造数', $db->now()]);

// 把这张卡改成「过期很久」——超出宽限期
$longAgo = date('Y-m-d', strtotime('-' . (CardRepo::GRACE_MONTHS + 3) . ' months'));
$db->exec('UPDATE card SET valid_to = ? WHERE store_code = ? AND card_no = ?',
    [$longAgo, SMOKE_STORE, CardNumber::normalize($gb[0]['card_no'])]);

$lk = $svc->lookup($gb[0]['card_no']);
eq('expired', $lk['state'], '超期卡仍然认得出是 expired');
ok($lk['grace_over'] === true, '★ lookup 会告诉前端「已超宽限期」');

$clerk   = ['id' => 1, 'name' => '收银员', 'role' => 1, 'device' => 'SMOKE'];
$manager = ['id' => 2, 'name' => '经理',   'role' => 2, 'device' => 'SMOKE'];

$g1 = $svc->replaceCard($gMid, $gb[1]['card_no'], '超期换卡', $clerk);
ok(!$g1['ok'] && $g1['error'] === 'grace_over', '★ 超过宽限期：普通换卡被拒');
eq($longAgo, $g1['old_valid_to'], '拒绝时带回判定依据（哪天过期的）');
eq(CardRepo::GRACE_MONTHS, $g1['grace_months'], '也带回当前宽限期，前端才能把话说清楚');
eq(CardNumber::normalize($gb[0]['card_no']),
   $app->members()->findById($gMid)['card_no'], '被拒时旧卡没被动过');

$g2 = $svc->replaceCard($gMid, $gb[1]['card_no'], '超期换卡', $clerk, ['reason' => '客人坚持']);
ok(!$g2['ok'] && $g2['error'] === 'forbidden', '★ 收银员就算带了原因也破不了例');

$g3 = $svc->replaceCard($gMid, $gb[1]['card_no'], '超期换卡', $manager, ['reason' => '  ']);
ok(!$g3['ok'] && $g3['error'] === 'reason_required', '★ 经理也必须填原因，空白不算');

$g4 = $svc->replaceCard($gMid, $gb[1]['card_no'], '超期换卡', $manager,
    ['reason' => '老客户，经理同意保留']);
ok($g4['ok'] && ($g4['forced'] ?? false) === true, '★ 经理带原因可强制换发');

$afterG = $app->members()->findById($gMid);
eq(60, (int)$afterG['points_balance'], '★ 强制换发后积分照样保留');
eq(CardNumber::normalize($gb[1]['card_no']), $afterG['card_no'], '会员行指向新卡');

$flog = $db->one('SELECT * FROM audit_log WHERE store_code=? AND action=? ORDER BY id DESC',
    [SMOKE_STORE, 'card_replace_forced']);
ok($flog !== null, '★ 强制换发记的是单独的 card_replace_forced 事件（后台能筛出全部破例）');
ok(str_contains((string)$flog['detail'], '经理同意保留'), '原因写进了审计明细');
ok(str_contains((string)$flog['detail'], 'grace_months'), '当时的宽限期设置也一并留档');

// 宽限期【之内】不需要经理 —— 别把正常换卡也拦了
$bindH = $svc->bindNewMember($gb[2]['card_no'], null, null, null, $opStub);
$hMid  = (int)$bindH['member']['id'];
$db->exec('UPDATE card SET valid_to = ? WHERE store_code = ? AND card_no = ?',
    [$past, SMOKE_STORE, CardNumber::normalize($gb[2]['card_no'])]);
$hb = $cards->generateBatch('SMOKEGR2', 1, $farNew);
$g5 = $svc->replaceCard($hMid, $hb[0]['card_no'], '刚过期换卡', $clerk);
ok($g5['ok'] && ($g5['forced'] ?? false) === false,
   '★ 还在宽限期内的卡，普通收银员照样能换，不用惊动经理');

step('⑲ 卡片等级与积分倍率');

/**
 * 等级属于【卡】不属于会员 —— 它印在卡面上，换卡时跟着新卡走。
 * 整套是可选的：不定义等级，一切照旧。
 *
 * 倍率叠在全局倍率之上，且【实际用了多少必须记进流水】——
 * 倍率是活查的，不定格的话，改一次倍率历史就再也对不上账，
 * 而「这单为什么给了 150 分」正是客人申诉时第一个要问的。
 */
$tiers = $app->cardTiers();

ok($tiers->save('smokegold', '冒烟金卡', 'Oro de prueba', 2.0, 90, true), '建一个等级');
$t = $tiers->find('smokegold');
ok($t !== null, '查得到');
eq('2.00', (string)$t['points_multiplier'], '倍率存下来了');

ok(!$tiers->save('SmokeBad!', '名字', null, 1.0, 1, true), '★ 标识只允许小写字母数字下划线');
ok(!$tiers->save('smokebad', '', null, 1.0, 1, true), '★ 名称不能为空');
ok(!$tiers->save('smokebad', '名字', null, 0, 1, true), '★ 倍率不能是 0（消费了反而不给分）');
ok(!$tiers->save('smokebad', '名字', null, -1, 1, true), '★ 倍率不能是负数');
ok(!$tiers->save('smokebad', '名字', null, 99, 1, true), '★ 倍率有上限，防手滑多打个零');

$d = $tiers->describe('smokegold');
eq('冒烟金卡', $d['name'], '中文名');
eq('Oro de prueba', $d['names']['es'], '西语名');
eq(2.0, $d['multiplier'], '倍率也带出来');
ok($tiers->describe(null) === null, '不分级返回 null —— 前端据此不显示等级那一栏');
ok($tiers->describe('没这个等级') === null, '认不出的等级码也返回 null，不炸');

// ── 发一批带等级的卡 ──
$tb = $cards->generateBatch('SMOKETIER', 2, date('Y-m-d', strtotime('+2 years')), 'smokegold');
eq('smokegold', $tb[0]['tier_code'], '★ 等级写进了卡（印刷清单也要有这一列）');
$row = $cards->findByCardNo($tb[0]['card_no']);
eq('smokegold', $row['tier_code'], '库里存下来了');

// ── 倍率真的作用在积分上 ──
$tr = $svc->bindNewMember($tb[0]['card_no'], null, null, null, $opStub);
ok($tr['ok'], '这张金卡绑给一位会员');
$goldMid = (int)$tr['member']['id'];

$fm = $tiers->forMember($goldMid);
eq('smokegold', $fm['code'], '★ 按会员查等级 —— 查的是他手里那张卡');
eq(2.0, $fm['multiplier'], '倍率对');

// 普通卡的会员作对照
$pb = $cards->generateBatch('SMOKEPLAIN', 1, date('Y-m-d', strtotime('+2 years')));
$pr = $svc->bindNewMember($pb[0]['card_no'], null, null, null, $opStub);
$plainMid = (int)$pr['member']['id'];
eq(null, $tiers->forMember($plainMid)['code'], '不分级的卡查出来是 null');
eq(1.0, $tiers->forMember($plainMid)['multiplier'], '★ 不分级 = 1.00 倍，照常积分只是没有加成');

// 同样金额，金卡应当拿到两倍
$perEuro = $app->cfg()->float('points_per_euro', 1.0);
$base    = \Vip\PointsEngine::pointsFor(5000, $perEuro, 1.0);
$gold    = \Vip\PointsEngine::pointsFor(5000, $perEuro, 1.0 * 2.0);
eq($base * 2, $gold, '★★ 同样 € 50，2 倍等级拿到两倍积分（' . $base . ' → ' . $gold . '）');

// ── 换卡时等级跟着新卡走 ──
$nb = $cards->generateBatch('SMOKETIER2', 1, date('Y-m-d', strtotime('+2 years')));
$rp = $svc->replaceCard($goldMid, $nb[0]['card_no'], '换成不分级的卡', $opStub);
ok($rp['ok'], '把金卡换成一张不分级的卡');
eq(null, $tiers->forMember($goldMid)['code'],
   '★★ 等级跟着新卡走 —— 换了不分级的卡，这位会员就不再是金卡');

// ── 有卡在用的等级不给删 ──
$tb2 = $cards->generateBatch('SMOKETIER3', 1, date('Y-m-d', strtotime('+2 years')), 'smokegold');
$del = $tiers->delete('smokegold');
ok(!$del['ok'] && $del['error'] === 'tier_in_use',
   '★★ 已经有卡在用的等级不给删（删了那些卡就指向一个不存在的等级）');
ok($del['in_use'] >= 1, '  └ 告诉调用方有几张在用：' . $del['in_use']);

// 停用是允许的：只是不再出现在发卡下拉框里，老卡照常显示
ok($tiers->save('smokegold', '冒烟金卡', 'Oro de prueba', 2.0, 90, false), '改成停用');
ok(!$tiers->isUsable('smokegold'), '★ 停用后不能再用来发卡');
ok($tiers->describe('smokegold') !== null, '★★ 但已发出去的卡照常显示等级名');
ok(count($tiers->all(true)) < count($tiers->all(false)), '发卡下拉框里看不到停用的等级');

// ── 按等级设不同的送 1 门槛 ──
step('⑳ 按等级的奖励门槛（金卡 8 次送 1 次）');

$rw = $app->rewards();
$globalN = $app->cfg()->int('reward_threshold_visits', 10);
ok($globalN > 0, "全局门槛是 {$globalN} 次");

/**
 * ⑲ 段最后把这位会员的金卡换成了不分级的卡（那一段验的就是「等级跟着卡走」）。
 * 所以这里要先把他换回金卡，否则下面全在按不分级算 —— 一片红，
 * 而原因跟产品毫无关系。
 */
$backR = $svc->replaceCard($goldMid, $tb2[0]['card_no'], '换回金卡', $opStub);
ok($backR['ok'], '先把这位会员换回金卡（上一段把他换成不分级的了）');
eq('smokegold', $tiers->forMember($goldMid)['code'], '  └ 确认他现在是金卡');

// 门槛留空 = 跟随全局
$tiers->save('smokegold', '冒烟金卡', 'Oro', 2.0, 90, true, null, null);
$rNull = $rw->rule($tiers->forMember($goldMid));
eq($globalN, $rNull['threshold_visits'], '★ 等级不设门槛时跟随全局 —— 只想优待金卡的店只填金卡那一格');

// 给金卡设 8 次
$tiers->save('smokegold', '冒烟金卡', 'Oro', 2.0, 90, true, 8, null);
$rGold = $rw->rule($tiers->forMember($goldMid));
eq(8, $rGold['threshold_visits'], '★★ 金卡按 8 次算');
eq($globalN, $rw->rule($tiers->forMember($plainMid))['threshold_visits'],
   '★★ 不分级的会员仍按全局，互不影响');

/**
 * 达标判定一直是「floor(进度 / 阈值) − 已发张数」，不是「每次 +1」。
 * 所以门槛一改数量会自动对上 —— 这正是改门槛安全的原因。
 */
$db->exec('UPDATE member SET visit_count = 8, rewards_issued = 0 WHERE store_code = ? AND id = ?',
    [SMOKE_STORE, $goldMid]);
$mg = $app->members()->findById($goldMid);
$pg = $rw->progressOf($mg, $tiers->forMember($goldMid));
eq(8, $pg['threshold'], '进度按金卡的 8 次算');
eq(1, $pg['earned'],  '8 次 → 该发 1 张');
eq(1, $pg['pending'], '还没发过，所以欠 1 张');
eq('smokegold', $pg['tier_code'], '★ 进度里带着按的是哪个等级的门槛');

// 同样 8 次，不分级的会员还差 2 次
$db->exec('UPDATE member SET visit_count = 8, rewards_issued = 0 WHERE store_code = ? AND id = ?',
    [SMOKE_STORE, $plainMid]);
$pp = $rw->progressOf($app->members()->findById($plainMid), $tiers->forMember($plainMid));
eq(0, $pp['earned'], '★★ 同样 8 次，不分级的还没达标（全局 ' . $globalN . ' 次）');

// 真发一张，并确认券上定格了当时的等级与门槛
$gr = $rw->checkAndGrant($goldMid, $opStub);
eq(1, $gr['granted'], '★★ 金卡 8 次真的发出了 1 张券');
$cp = $db->one('SELECT tier_code, threshold_used FROM coupon
                 WHERE store_code = ? AND member_id = ? ORDER BY id DESC', [SMOKE_STORE, $goldMid]);
eq('smokegold', $cp['tier_code'], '★★ 券上定格了发它时的等级');
eq(8, (int)$cp['threshold_used'], '★★ 也定格了当时的门槛 —— 改门槛之后还答得出「凭什么发的」');

/**
 * 🔴 调高门槛【绝不能】把已经发出去的券收回来。
 *    收回已给出去的东西是投诉的直接来源。
 */
$tiers->save('smokegold', '冒烟金卡', 'Oro', 2.0, 90, true, 20, null);
$pg2 = $rw->progressOf($app->members()->findById($goldMid), $tiers->forMember($goldMid));
eq(0, $pg2['earned'],  '门槛调到 20 次后，按新门槛算 8 次还没达标');
eq(0, $pg2['pending'], '★★ pending 取 max(0,…) —— 不会变成负数去追回已发的券');
$still = (int)$db->value('SELECT COUNT(*) FROM coupon WHERE store_code = ? AND member_id = ? AND status = 1',
    [SMOKE_STORE, $goldMid]);
eq(1, $still, '★★ 已经发出去的那张券【原样还在】');

// 调低则当场补发差额
$tiers->save('smokegold', '冒烟金卡', 'Oro', 2.0, 90, true, 4, null);
$gr2 = $rw->checkAndGrant($goldMid, $opStub);
eq(1, $gr2['granted'], '★★ 门槛调到 4 次 → 8 次该发 2 张，当场补发差额 1 张');

// 门槛的合法性
ok(!$tiers->save('smokebad2', '名字', null, 1.0, 1, true, 0, null),
   '★ 门槛不能是 0 次（每记一次账就发一张券，那不是优待，是把店送掉）');
ok(!$tiers->save('smokebad2', '名字', null, 1.0, 1, true, -5, null), '★ 门槛不能是负数');
ok(!$tiers->save('smokebad2', '名字', null, 1.0, 1, true, null, '-10'), '★ 满额门槛也不能是负数');
ok($tiers->save('smokebad2', '名字', null, 1.0, 1, true, null, null), '两格都留空是合法的（跟随全局）');
$db->exec('DELETE FROM card_tier WHERE store_code = ? AND code = ?', [SMOKE_STORE, 'smokebad2']);

// ── 按等级设不同的券有效期 ──
step('㉑ 按等级的券有效期（金卡的券多给一段时间）');

/**
 * 「金卡的券有效期长一点」是很自然的诉求，但它有一个容易踩的坑：
 * 券的到期日是【发券当刻算好写死在券上】的，不是每次查询实时算的。
 * 所以后台把这个设置一改，客人手上已经拿到的券【不会跟着变】——
 * 券面上印的日子就是最终的日子。下面把这一点钉死。
 */

// 全局值从 rule() 自己拿 —— ConfigRepo 按实例缓存，直接读库或读另一个实例
// 都可能和 $rw 眼里的值对不上，那样测的就不是同一件事了
$globalDays = $rw->rule(null)['valid_days'];

// ① 等级不设 = 跟随全局
$tiers->save('smokegold', '冒烟金卡', 'Oro', 2.0, 90, true, 4, null, null);
eq($globalDays, $rw->rule($tiers->forMember($goldMid))['valid_days'],
   '★ 等级不设券有效期时跟随全局（' . $globalDays . ' 天）');

// ② 等级设了就按等级的
$tiers->save('smokegold', '冒烟金卡', 'Oro', 2.0, 90, true, 4, null, 7);
eq(7, $rw->rule($tiers->forMember($goldMid))['valid_days'], '★★ 金卡的券按等级给 7 天');
eq($globalDays, $rw->rule($tiers->forMember($plainMid))['valid_days'],
   '  └ 不分级的卡不受影响，还是全局的 ' . $globalDays . ' 天');

// ③ 真发一张，到期日按等级算
$mc1 = $rw->grantManual($goldMid, '冒烟测试 · 按等级的券有效期', $opStub);
ok($mc1['ok'], '发了一张手工券');
eq(date('Y-m-d', strtotime('+7 days')), $mc1['coupon']['valid_to'],
   '★★ 券上的到期日 = 发券当天 + 等级的 7 天');

// ④ 改设置不动老券 —— 这是整段的重点
$tiers->save('smokegold', '冒烟金卡', 'Oro', 2.0, 90, true, 4, null, 30);
$keep = $db->one('SELECT valid_to FROM coupon WHERE store_code = ? AND id = ?',
                 [SMOKE_STORE, $mc1['coupon']['id']]);
eq(date('Y-m-d', strtotime('+7 days')), (string)$keep['valid_to'],
   '★★★ 把有效期改成 30 天后，【已经发出去的那张券还是 7 天】—— 券面上写的日子就是最终的日子');
$mc2 = $rw->grantManual($goldMid, '冒烟测试 · 改完之后再发一张', $opStub);
eq(date('Y-m-d', strtotime('+30 days')), $mc2['coupon']['valid_to'], '  └ 改完之后【新发的】才是 30 天');

// ⑤ 0 = 永久有效（不是「没设置」，也不是「当天过期」）
$tiers->save('smokegold', '冒烟金卡', 'Oro', 2.0, 90, true, 4, null, 0);
eq(0, $rw->rule($tiers->forMember($goldMid))['valid_days'], '★ 0 存得住，没被当成「留空」');
$mc3 = $rw->grantManual($goldMid, '冒烟测试 · 永久有效', $opStub);
eq(null, $mc3['coupon']['valid_to'], '★★ 0 天 = 永久有效（valid_to 存 NULL），不是当天就过期');

// ⑥ 合法性
ok(!$tiers->save('smokebad3', '名字', null, 1.0, 1, true, null, null, -1), '★ 券有效期不能是负数');
ok($tiers->save('smokebad3', '名字', null, 1.0, 1, true, null, null, 0), '  └ 但 0 是合法的（永久）');
ok($tiers->save('smokebad3', '名字', null, 1.0, 1, true, null, null, null), '  └ 留空也是合法的（跟随全局）');
eq(null, $tiers->find('smokebad3')['coupon_valid_days'], '★ 留空存的是 NULL，不是 0 —— 两者含义完全不同');
$db->exec('DELETE FROM card_tier WHERE store_code = ? AND code = ?', [SMOKE_STORE, 'smokebad3']);

// 复位，免得影响后面
$tiers->save('smokegold', '冒烟金卡', 'Oro', 2.0, 90, true, 4, null, null);

// 规则文案也要按等级说
$txtGold  = $rw->ruleText($tiers->forMember($goldMid));
$txtPlain = $rw->ruleText($tiers->forMember($plainMid));
ok(str_contains($txtGold, '4'), "★★ 规则文案按等级说：「{$txtGold}」");
ok($txtGold !== $txtPlain, "  └ 与不分级的不同：「{$txtPlain}」");

$db->exec('DELETE FROM coupon WHERE store_code = ? AND member_id IN (?,?)', [SMOKE_STORE, $goldMid, $plainMid]);

// 清理
$db->exec('DELETE FROM card WHERE store_code = ? AND batch_no LIKE ?', [SMOKE_STORE, 'SMOKETIER%']);
$db->exec('DELETE FROM card WHERE store_code = ? AND batch_no = ?', [SMOKE_STORE, 'SMOKEPLAIN']);
$db->exec('DELETE FROM point_ledger WHERE store_code = ? AND member_id IN (?,?)', [SMOKE_STORE, $goldMid, $plainMid]);
$db->exec('DELETE FROM member WHERE store_code = ? AND id IN (?,?)', [SMOKE_STORE, $goldMid, $plainMid]);
$db->exec('DELETE FROM card_tier WHERE store_code = ? AND code = ?', [SMOKE_STORE, 'smokegold']);

step('⑱ 界面语言 —— 跟着账号走，不跟着平板走');

/**
 * 收银台的平板是共用的，中文和西语的员工换班轮着用同一台。
 * 语言存在 operator 行上，所以「换台平板还是我的语言」「换个人就换语言」
 * 这两件事才成立。存在平板本地的话就变成「谁后切的算谁的」。
 */
$auth   = $app->auth();
$langOp = $auth->createOperator('smokelang', '语言测试', '778899', 1);
ok($langOp > 0, "建一个测试账号");

$row = $db->one('SELECT lang FROM operator WHERE store_code = ? AND id = ?', [SMOKE_STORE, $langOp]);
ok($row['lang'] === null, '★ 新账号的 lang 是 NULL —— 「没选过」要能和「选了中文」区分开');

ok($auth->setLang($langOp, 'es'), '设成西语');
$row = $db->one('SELECT lang FROM operator WHERE store_code = ? AND id = ?', [SMOKE_STORE, $langOp]);
eq('es', $row['lang'], '★ 落到了 operator 行上');

ok(!$auth->setLang($langOp, 'fr'), '★ 不支持的语言码直接拒绝');
$row = $db->one('SELECT lang FROM operator WHERE store_code = ? AND id = ?', [SMOKE_STORE, $langOp]);
eq('es', $row['lang'], '★ 被拒时不改动原值 —— 宁可保持原样，也别把人的选择改坏');

// 登录与会话恢复两条路都要带上语言，漏一条就是「刷新之后语言变了」
$lg = $auth->login('smokelang', '778899', 'SMOKEPAD', '127.0.0.1');
ok($lg['ok'], '能登录');
eq('es', $lg['operator']['lang'] ?? null, '★ 登录响应里带着语言');
$me = $auth->resolve((string)$lg['token']);
eq('es', $me['lang'] ?? null, '★ 会话恢复那条路也带着语言（最容易漏的一条）');

/**
 * 显示名也要跟着语言走 —— 否则顶栏会是「系统管理员 (encargado)」
 * 这种中西混排（现场照片上就是这样）。
 *
 * 名字本身没法翻译（店家填的可能是「小王」也可能是「María」），
 * 所以一个账号存两个名字。西语留空则回落中文名。
 */
$auth->renameOperator($langOp, '语言测试', 'Prueba de idioma');
$row = $db->one('SELECT display_name, display_name_es FROM operator WHERE store_code=? AND id=?',
    [SMOKE_STORE, $langOp]);
eq('语言测试', $row['display_name'], '中文名存下了');
eq('Prueba de idioma', $row['display_name_es'], '西语名也存下了');

$names = \Vip\Service\AuthService::names($row);
eq('语言测试', $names['zh'], '按 zh 取到中文名');
eq('Prueba de idioma', $names['es'], '★ 按 es 取到西语名 —— 顶栏才不会中西混排');

// 西语留空：回落中文名，绝不能变成空白
$auth->renameOperator($langOp, '语言测试', '');
$row2 = $db->one('SELECT display_name, display_name_es FROM operator WHERE store_code=? AND id=?',
    [SMOKE_STORE, $langOp]);
ok($row2['display_name_es'] === null, '西语名留空存成 NULL，不是空字符串');
$names2 = \Vip\Service\AuthService::names($row2);
eq('语言测试', $names2['es'], '★★ 没填西语名的账号，西语界面回落到中文名而不是空白');

ok(!$auth->renameOperator($langOp, '   ', 'Algo'), '★ 中文名不能改成空白');

// 登录响应里要带上两个名字，前端切语言才不用再请求一次
$lg2 = $auth->login('smokelang', '778899', 'SMOKEPAD', '127.0.0.1');
$op2 = $lg2['operator'] ?? [];
ok(isset($op2['names']['zh'], $op2['names']['es']),
   '★ 登录响应带着两种语言的名字（切语言时前端就地换掉，不用再往返一次）');

$db->exec('DELETE FROM operator_session WHERE store_code = ? AND operator_id = ?', [SMOKE_STORE, $langOp]);
$db->exec('DELETE FROM operator WHERE store_code = ? AND id = ?', [SMOKE_STORE, $langOp]);

step('㉒ 防刷：同行分桌要放行，捡小票要挡住');

/**
 * 这一整段的前提写在 docs/03 §12：
 *
 * 「同行分桌」和「捡了几张别人的小票」在系统里长得【一模一样】——
 * 都是多张订单记进同一张卡。能把两者分开的只有时间：
 *   · 同行分桌永远是当场，几张单结账时间也挨在一起
 *   · 捡小票在物理上必须发生在结账之后，来源必然分散
 *
 * 所以下面每一条都在验时间维度的判据，以及「拦下之后还有没有活路」——
 * 一刀拒绝的代价是柜台当面回绝客人，那正是投诉的来源。
 */

// ★ 必须新建 App：ConfigRepo 按实例缓存，下面要反复改闸门的开关
$rk = new App(['store_code' => SMOKE_STORE, 'local_db' => $dbCfg, 'pos_db' => []]);
$rkDb = $rk->localDb();

// 造三张【刚刚结账】的同桌单，模拟一大帮人坐了三桌一起结账
$rkPos = new FakePosSource();
$rkPos->now = date('Y-m-d H:i:s');
$mkOrder = function (string $serial, int $ohid, string $table, string $amt, string $when) use ($rkPos) {
    $rkPos->addHead([
        'serial_id' => $serial, 'order_head_id' => $ohid, 'check_id' => 1,
        'table_name' => $table, 'eat_type' => 0, 'customer_num' => 3,
        'original_amount' => $amt, 'should_amount' => $amt, 'actual_amount' => $amt,
        'order_end_time' => $when,
    ]);
    $rkPos->addDetail($ohid, 1, [FakePosSource::line(2390, 'MENÚ INFINITY NOCHE', '23.90', $amt, 2)]);
};
$nowTs = time();
$mkOrder('9909990001', 990001, 'R1', '47.80', date('Y-m-d H:i:s', $nowTs - 120));
$mkOrder('9909990002', 990002, 'R2', '47.80', date('Y-m-d H:i:s', $nowTs - 300));
$mkOrder('9909990003', 990003, 'R3', '47.80', date('Y-m-d H:i:s', $nowTs - 420));
// 第四张：同一天但隔了 5 小时 —— 「捡小票」的形状
$mkOrder('9909990004', 990004, 'R9', '47.80', date('Y-m-d H:i:s', $nowTs - 5 * 3600));
/**
 * 第五张：和前三桌【同一顿】，专门留给 ⑤ 段测餐期上限。
 *
 * ★ 不能拿上面那张 5 小时前的单来测上限 —— 它和前三桌很可能不在同一个餐期
 *   （比如现在 21:46 属「晚上」，5 小时前 16:46 属「白天」），
 *   于是 countGrantsInSitting 一条都数不到，闸门根本不会触发。
 *   这条测试因此会【随一天中的时刻时绿时红】：跑在 18:00–19:30 那个
 *   餐期空档里就恰好是绿的，因为那时两张单都落在餐期之外、退回按天比。
 *   加餐期种子之前它一直是绿的，正是这个原因。
 */
$mkOrder('9909990005', 990005, 'R5', '47.80', date('Y-m-d H:i:s', $nowTs - 60));
$rk->setPosSource($rkPos);

foreach (['R1', 'R2', 'R3', 'R9', 'R5'] as $tb) {
    $rk->points()->locate($tb, 600);
}
eq(5, (int)$rkDb->value('SELECT COUNT(*) FROM pos_order WHERE store_code=? AND serial_id LIKE ?',
                        [SMOKE_STORE, '99099900%']), '五张测试订单已落镜像');

$rkMid = (int)$rk->members()->create('TK-00099901-RSK', null, null, null)['id'];
$rkOpS = ['id' => 1, 'name' => '收银员', 'device' => 'SMOKE', 'role' => 1, 'is_manager' => false];
$rkOpM = ['id' => 2, 'name' => '经理',   'device' => 'SMOKE', 'role' => 2, 'is_manager' => true];

// ── ① 多桌合并：三桌整单记进一张卡 ─────────────────────
$rk->cfg()->set('late_grant_minutes', '60');
$rk->cfg()->set('merge_span_minutes', '60');
$rk->cfg()->set('max_grants_per_period', '0');

$mg = $rk->points()->grantMerged(['9909990001', '9909990002', '9909990003'], $rkMid, $rkOpS);
ok($mg['ok'], '★★ 同行分桌：三桌一次记进一张卡' . ($mg['ok'] ? '' : '（' . ($mg['error'] ?? '?') . '）'));
eq(3, count($mg['entries'] ?? []), '  └ 产出三笔流水（每桌一笔，金额守恒仍是逐单校验的）');
$grp = $mg['group'] ?? '';
ok($grp !== '', "  └ 三笔盖同一个组号：{$grp}");
eq(3, (int)$rkDb->value('SELECT COUNT(*) FROM point_ledger WHERE store_code=? AND grant_group=?',
                        [SMOKE_STORE, $grp]), '★ 库里查得到这一组');
eq(1, (int)$rkDb->value('SELECT COUNT(DISTINCT member_id) FROM point_ledger WHERE store_code=? AND grant_group=?',
                        [SMOKE_STORE, $grp]), '★ 全部记给同一位会员');
$mSum = $rkDb->one('SELECT points_balance, visit_count FROM member WHERE store_code=? AND id=?', [SMOKE_STORE, $rkMid]);
eq(141, (int)$mSum['points_balance'], '★★ 三桌 47.80 × 3 = 143.40 → 141 分（逐单向下取整 47+47+47）');
eq(6, (int)$mSum['visit_count'], '  └ 计次 6（每桌 2 份套餐）');

// ── ② 合并算「一次」，不是「三次」──────────────────────
/**
 * 这一条是整套限次能不能用的关键。
 * 按订单数算的话，一个五桌的大团一次就顶满上限，
 * 而这恰恰是最应该放行的正当场景。
 */
$rk->cfg()->set('max_grants_per_period', '2');
$ref = $rkDb->one('SELECT order_end_time FROM pos_order WHERE store_code=? AND serial_id=?',
                  [SMOKE_STORE, '9909990001']);
$rm = new ReflectionMethod(Vip\Service\PointsService::class, 'countGrantsInSitting');
$rm->setAccessible(true);
eq(1, $rm->invoke($rk->points(), $rkMid, (string)$ref['order_end_time'], []),
   '★★★ 三桌合并只算【1 次】—— 按订单数算的话大团一次就顶满上限，而那正是最该放行的');

// ── ③ 时间跨度：隔了 5 小时的单不能混进同一组 ────────────
$mg2 = $rk->points()->grantMerged(['9909990001', '9909990004'], $rkMid, $rkOpS);
ok(!$mg2['ok'], '★★ 隔了 5 小时的两单不让合并');
ok(in_array($mg2['error'] ?? '', ['merge_span_too_wide', 'merge_not_same_sitting'], true),
   "  └ 理由说得清：{$mg2['error']}");

// ── ④ 补记时限：超时要经理带原因 ────────────────────────
$lateR = $rk->points()->grant('9909990004', [['member_id' => $rkMid, 'amount_cents' => 4780, 'portions' => 2]],
                              Vip\PointsEngine::MODE_WHOLE, $rkOpS);
ok(!$lateR['ok'] && $lateR['error'] === 'manager_required',
   '★★ 5 小时前的单，普通收银员记不了（补记要经理）');
ok(($lateR['detail']['gates'][0]['gate'] ?? '') === 'late_grant',
   '  └ 告诉前端撞的是哪道闸门，好让界面说人话');

// 经理但不写原因 —— 照样不行
$noReason = $rk->points()->grant('9909990004', [['member_id' => $rkMid, 'amount_cents' => 4780, 'portions' => 2]],
                                 Vip\PointsEngine::MODE_WHOLE, $rkOpM, ['reason' => '   ']);
eq('reason_required', $noReason['error'] ?? '', '★★ 经理也必须写原因 —— 破例不留痕等于没有规则');

// 普通收银员就算自己填了原因也不行
$notMgr = $rk->points()->grant('9909990004', [['member_id' => $rkMid, 'amount_cents' => 4780, 'portions' => 2]],
                               Vip\PointsEngine::MODE_WHOLE, $rkOpS, ['reason' => '客人忘带卡']);
eq('forbidden', $notMgr['error'] ?? '', '★★ 普通收银员自己填原因也破不了例');

// 经理 + 原因 → 放行
$forced = $rk->points()->grant('9909990004', [['member_id' => $rkMid, 'amount_cents' => 4780, 'portions' => 2]],
                               Vip\PointsEngine::MODE_WHOLE, $rkOpM, ['reason' => '客人忘带卡，隔天拿小票来补']);
ok($forced['ok'] ?? false, '★★ 经理写了原因就能补记 —— 一刀拒绝的代价是柜台当面回绝客人');
ok($forced['forced'] ?? false, '  └ 返回值标着这是破例');
/**
 * ★★ 破例要【单独一个 action】，不能混在普通记账里。
 *
 * 后台「审计」页是按 action 筛的。混在 point_grant 里的话，
 * 想回答「这个月破了几次例、谁放的行」就得把当月几千条记账
 * 一条条翻 detail —— 等于查不了。
 */
eq(1, (int)$rkDb->value(
    'SELECT COUNT(*) FROM audit_log WHERE store_code=? AND action=? AND detail LIKE ?',
    [SMOKE_STORE, 'point_grant_forced', '%忘带卡%']),
   '★★ 破例单独记一条 point_grant_forced，带着原因 —— 筛一下就是全部破例');
eq('经理', (string)$rkDb->value(
    'SELECT operator_name FROM audit_log WHERE store_code=? AND action=? ORDER BY id DESC LIMIT 1',
    [SMOKE_STORE, 'point_grant_forced']), '  └ 记着是谁放的行');

// ── ⑤ 餐期上限 ──────────────────────────────────────────
$rk->cfg()->set('late_grant_minutes', '0');     // 只测上限，把补记闸门让开
$rk->cfg()->set('max_grants_per_period', '1');
// ★ 用同一顿的那张（9909990005），不是 5 小时前那张 —— 理由见上面造单处
$capR = $rk->points()->grant('9909990005', [['member_id' => $rkMid, 'amount_cents' => 1, 'portions' => 0]],
                             Vip\PointsEngine::MODE_WHOLE, $rkOpS);
ok(!$capR['ok'] && $capR['error'] === 'manager_required', '★★ 同一餐期超过上限，普通收银员记不了');
ok(($capR['detail']['gates'][0]['gate'] ?? '') === 'period_cap', "  └ 撞的是次数上限");

// ── ⑥ 整组撤销 ──────────────────────────────────────────
$rk->cfg()->set('max_grants_per_period', '0');
$before = (int)$rkDb->value('SELECT points_balance FROM member WHERE store_code=? AND id=?', [SMOKE_STORE, $rkMid]);
$rg = $rk->points()->reverseGroup($grp, '客人反悔，改为各记各的', $rkOpM);
ok($rg['ok'] ?? false, '★★ 整组撤销成功');
eq(3, $rg['count'] ?? 0, '  └ 三笔一起撤（合并是一次操作，撤销也该是一次操作）');
$after = (int)$rkDb->value('SELECT points_balance FROM member WHERE store_code=? AND id=?', [SMOKE_STORE, $rkMid]);
eq($before - 141, $after, '★★ 三桌的分一次全退干净');
eq(0, (int)$rkDb->value(
    'SELECT COUNT(*) FROM point_ledger WHERE store_code=? AND grant_group=? AND entry_type=1 AND status=1',
    [SMOKE_STORE, $grp]), '  └ 组内已无有效的消费流水');
$allocBack = $rkDb->value('SELECT allocated_amount FROM pos_order WHERE store_code=? AND serial_id=?',
                          [SMOKE_STORE, '9909990001']);
eq('0.00', (string)$allocBack, '★★ 订单的已分配额也退回去了 —— 这几桌可以重新记账');
ok(!($rk->points()->reverseGroup($grp, '再撤一次', $rkOpM)['ok'] ?? false),
   '★ 撤过的组不能再撤（幂等）');

// ── ⑦ 告警：拦不住内部人，但要留下痕迹 ──────────────────
/**
 * 上面每一道闸门都建立在「收银员是诚实的」之上，
 * 可员工本人就是收银员 —— 他要么有经理 PIN，要么干脆就是经理。
 * 对内部作案事前拦截在结构上无效，能做的只有让它留痕。
 */
$rk->cfg()->set('alert_span_hours', '2');
$rk->cfg()->set('alert_grants_per_day', '2');
$rkDb->exec('DELETE FROM alert WHERE store_code=? AND alert_type LIKE ?', [SMOKE_STORE, 'grant_%']);
foreach (['9909990001', '9909990002', '9909990003', '9909990004'] as $sn) {
    $rk->points()->grant($sn, [['member_id' => $rkMid, 'amount_cents' => 4780, 'portions' => 2]],
                         Vip\PointsEngine::MODE_WHOLE, $rkOpS);
}
$alerts = $rkDb->all('SELECT alert_type, message FROM alert WHERE store_code=? AND alert_type LIKE ?',
                     [SMOKE_STORE, 'grant_%']);
$types = array_column($alerts, 'alert_type');
ok(in_array('grant_many_per_day', $types, true), '★★ 一天记太多次 → 告警');
ok(in_array('grant_span_wide', $types, true),
   '★★ 几单结账时间跨度太大 → 告警（「攒了一把小票一起来兑」的形状）');
ok(count($alerts) <= 2, '  └ 同一张卡同一类型不重复刷屏（raiseOnce）');

// 告警只观察不拦人
eq(4, (int)$rkDb->value(
    'SELECT COUNT(*) FROM point_ledger WHERE store_code=? AND member_id=? AND entry_type=1 AND status=1 AND grant_group IS NULL',
    [SMOKE_STORE, $rkMid]), '★★ 告警不影响记账 —— 四笔都记上了，只是后台多了两条待处理');

// 清理
$rkDb->exec('DELETE FROM alert WHERE store_code=? AND alert_type LIKE ?', [SMOKE_STORE, 'grant_%']);
$rkDb->exec('DELETE FROM point_ledger WHERE store_code=? AND member_id=?', [SMOKE_STORE, $rkMid]);
$rkDb->exec('DELETE FROM member WHERE store_code=? AND id=?', [SMOKE_STORE, $rkMid]);
$rkDb->exec('DELETE FROM pos_order WHERE store_code=? AND serial_id LIKE ?', [SMOKE_STORE, '99099900%']);

step('㉓ 计次口径：一张卡，一个餐期，最多 1 次');

/**
 * 这是「十送一」口径的一次根本改变：从【买 10 份套餐】变成【来 10 趟】。
 *
 * 旧口径（by_portion）没法防：一桌 10 个人 10 份套餐，整单记给一个人
 * = 一次 10 次计次，当场就够十送一。也就是说【一张小票 = 一顿免费的饭】——
 * 捡到一张就直接换，连攒都不用攒。
 *
 * 新口径下：
 *   · 一桌 4 人有 4 张卡 → 4 张各记 1 次
 *   · 一桌 4 人只有 2 张卡 → 只记那 2 张，另外 2 份的次数【就是没有了】，
 *     不会挪给在场的卡
 *   · 捡一张 10 人的小票 → 1 次
 *
 * ★ 积分不受影响。钱是真花了的，不给分才是错的。
 */

$vc   = new App(['store_code' => SMOKE_STORE, 'local_db' => $dbCfg, 'pos_db' => []]);
$vcDb = $vc->localDb();
$vc->cfg()->set('visit_count_mode', 'once_per_period');
$vc->cfg()->set('late_grant_minutes', '0');       // 只测计次，别撞补记闸门
$vc->cfg()->set('max_grants_per_period', '0');
$vc->cfg()->set('alert_grants_per_day', '0');
$vc->cfg()->set('alert_span_hours', '0');

$vcPos = new FakePosSource();
$vcPos->now = date('Y-m-d H:i:s');
$today = date('Y-m-d');
$mk = function (string $serial, int $ohid, string $table, int $portions, string $clock) use ($vcPos, $today) {
    $amt = number_format(23.90 * $portions, 2, '.', '');
    $vcPos->addHead([
        'serial_id' => $serial, 'order_head_id' => $ohid, 'check_id' => 1,
        'table_name' => $table, 'eat_type' => 0, 'customer_num' => $portions,
        'original_amount' => $amt, 'should_amount' => $amt, 'actual_amount' => $amt,
        'order_end_time' => $today . ' ' . $clock,
    ]);
    $vcPos->addDetail($ohid, 1, [FakePosSource::line(2390, 'MENÚ INFINITY NOCHE', '23.90', $amt, $portions)]);
};
// 白天餐期（11:00–18:00）两单，晚市（19:30–02:00）一单
$mk('9808880001', 980001, 'V1', 3, '13:10:00');
$mk('9808880002', 980002, 'V2', 2, '13:40:00');
$mk('9808880003', 980003, 'V3', 4, '21:15:00');
$mk('9808880004', 980004, 'V4', 4, '13:20:00');
$vc->setPosSource($vcPos);
foreach (['V1', 'V2', 'V3', 'V4'] as $tb) { $vc->points()->locate($tb, 1200); }

$mA = (int)$vc->members()->create('TK-00098801-AAA', null, null, null)['id'];
$mB = (int)$vc->members()->create('TK-00098802-BBB', null, null, null)['id'];
$mC = (int)$vc->members()->create('TK-00098803-CCC', null, null, null)['id'];
$mD = (int)$vc->members()->create('TK-00098804-DDD', null, null, null)['id'];
$vcOp = ['id' => 1, 'name' => '收银员', 'device' => 'SMOKE', 'role' => 1, 'is_manager' => false];
$visitsOf = fn(int $id): int => (int)$vcDb->value(
    'SELECT visit_count FROM member WHERE store_code=? AND id=?', [SMOKE_STORE, $id]);
$pointsOf = fn(int $id): int => (int)$vcDb->value(
    'SELECT points_balance FROM member WHERE store_code=? AND id=?', [SMOKE_STORE, $id]);

// ── ① 3 份套餐记给一个人 = 1 次，不是 3 次 ──────────────
$r1 = $vc->points()->grant('9808880001', [['member_id' => $mA, 'amount_cents' => 7170, 'portions' => 3]],
                           Vip\PointsEngine::MODE_WHOLE, $vcOp);
ok($r1['ok'], '记账成功');
eq(1, $visitsOf($mA), '★★★ 3 份套餐整单记给一个人 = 【1 次】—— 旧口径下这里是 3 次');
eq(71, $pointsOf($mA), '  └ 积分照常给 71 分（钱是真花了的，不给分才是错的）');

// ── ② 同一餐期再来一单 = 0 次，但积分照给 ────────────────
$r2 = $vc->points()->grant('9808880002', [['member_id' => $mA, 'amount_cents' => 4780, 'portions' => 2]],
                           Vip\PointsEngine::MODE_WHOLE, $vcOp);
ok($r2['ok'], '同一餐期第二单也记得上（不是拒绝）');
eq(1, $visitsOf($mA), '★★★ 同一餐期第二单【不再计次】—— 一张卡一个餐期最多 1 次');
eq(0, (int)$r2['entries'][0]['visits'], '  └ 返回值里明写着这一单计次为 0，前端好告诉收银员');
eq(71 + 47, $pointsOf($mA), '★★ 但积分照加 —— 不计次不等于不积分');

// ── ③ 换个餐期又能记一次 ────────────────────────────────
$r3 = $vc->points()->grant('9808880003', [['member_id' => $mA, 'amount_cents' => 9560, 'portions' => 4]],
                           Vip\PointsEngine::MODE_WHOLE, $vcOp);
ok($r3['ok'], '晚市这一单记账成功');
eq(2, $visitsOf($mA), '★★ 中午记过、晚上又来 → 再记 1 次（一天两个餐期各算各的）');

// ── ④ 一桌 4 人 4 张卡：各记 1 次 ───────────────────────
$vcDb->exec('DELETE FROM point_ledger WHERE store_code=? AND serial_id=?', [SMOKE_STORE, '9808880004']);
$vcDb->exec('UPDATE pos_order SET allocated_amount=0, allocated_portions=0 WHERE store_code=? AND serial_id=?',
            [SMOKE_STORE, '9808880004']);
$r4 = $vc->points()->grant('9808880004', [
    ['member_id' => $mB, 'amount_cents' => 2390, 'portions' => 1],
    ['member_id' => $mC, 'amount_cents' => 2390, 'portions' => 1],
    ['member_id' => $mD, 'amount_cents' => 2390, 'portions' => 1],
], Vip\PointsEngine::MODE_SPLIT, $vcOp);
ok($r4['ok'], '一桌 3 张卡同时记账');
eq([1, 1, 1], [$visitsOf($mB), $visitsOf($mC), $visitsOf($mD)],
   '★★★ 三张卡【各记 1 次】—— 这正是新规则要的形状');

// ── ⑤ 一桌 4 人只有 2 张卡：剩下的次数就是没有了 ─────────
/**
 * ★★★ 这一条是整条规则的重点。
 *
 * 那一桌确实吃了 4 份，但只有 2 位客人带了卡。
 * 剩下 2 份的次数【不会挪给在场的两张卡】—— 挪过去就等于
 * 「一桌 2 个人计入一个名下」，正是这次要禁掉的事。
 */
$mk('9808880005', 980005, 'V5', 4, '13:50:00');
$vc->setPosSource($vcPos);
$vc->points()->locate('V5', 1200);
$mE = (int)$vc->members()->create('TK-00098805-EEE', null, null, null)['id'];
$mF = (int)$vc->members()->create('TK-00098806-FFF', null, null, null)['id'];
$r5 = $vc->points()->grant('9808880005', [
    ['member_id' => $mE, 'amount_cents' => 4780, 'portions' => 2],
    ['member_id' => $mF, 'amount_cents' => 4780, 'portions' => 2],
], Vip\PointsEngine::MODE_SPLIT, $vcOp);
ok($r5['ok'], '4 人桌记给 2 张卡');
eq(2, $visitsOf($mE) + $visitsOf($mF),
   '★★★ 一桌 4 份、只有 2 张卡 → 全桌只记 2 次（各 1 次），另外 2 份的次数没有了');
eq(1, $visitsOf($mE), '  └ 每张各 1 次，不是一张 2 次');

// ── ⑥ 多桌合并：三桌并给一张卡也只有 1 次 ───────────────
/**
 * ★★ 这一条守的是【同一个事务内】的可见性。
 *
 * 合并是在一个事务里连着调 grantOne 三遍。第一遍插进去的流水
 * 必须被第二遍查得到，否则三桌各记 1 次 —— 等于什么都没防住。
 * 判定查库而不是缓存，正是为了这个。
 */
$mk('9808880011', 980011, 'W1', 2, '13:05:00');
$mk('9808880012', 980012, 'W2', 2, '13:12:00');
$mk('9808880013', 980013, 'W3', 2, '13:18:00');
$vc->setPosSource($vcPos);
foreach (['W1', 'W2', 'W3'] as $tb) { $vc->points()->locate($tb, 1200); }
$mG = (int)$vc->members()->create('TK-00098807-GGG', null, null, null)['id'];
$mgOp = ['id' => 2, 'name' => '经理', 'device' => 'SMOKE', 'role' => 2, 'is_manager' => true];
$r6 = $vc->points()->grantMerged(['9808880011', '9808880012', '9808880013'], $mG, $mgOp);
ok($r6['ok'], '三桌合并成功' . ($r6['ok'] ? '' : '（' . ($r6['error'] ?? '?') . '）'));
eq(1, $visitsOf($mG),
   '★★★ 三桌并给一张卡，计次仍然只有【1 次】—— 同一事务内第一笔要被后两笔看见');
eq(141, $pointsOf($mG), '  └ 但三桌的积分全都进来了（并的是积分，不是次数）');
eq([1, 0, 0], array_map(static fn(array $e): int => (int)$e['visits'], $r6['entries']),
   '  └ 逐笔看：第一桌记 1 次，后两桌 0 次');

// ── ⑦ 老口径仍然能用 ────────────────────────────────────
$vc->cfg()->set('visit_count_mode', 'by_portion');
$mk('9808880021', 980021, 'X1', 5, '14:10:00');
$vc->setPosSource($vcPos);
$vc->points()->locate('X1', 1200);
$mH = (int)$vc->members()->create('TK-00098808-HHH', null, null, null)['id'];
$vc->points()->grant('9808880021', [['member_id' => $mH, 'amount_cents' => 11950, 'portions' => 5]],
                     Vip\PointsEngine::MODE_WHOLE, $vcOp);
eq(5, $visitsOf($mH), '★ 切回 by_portion：5 份 = 5 次（店家想回去也回得去）');

$vc->cfg()->set('visit_count_mode', 'by_order');
$mk('9808880022', 980022, 'X2', 5, '14:20:00');
$vc->setPosSource($vcPos);
$vc->points()->locate('X2', 1200);
$mI = (int)$vc->members()->create('TK-00098809-III', null, null, null)['id'];
$vc->points()->grant('9808880022', [['member_id' => $mI, 'amount_cents' => 11950, 'portions' => 5]],
                     Vip\PointsEngine::MODE_WHOLE, $vcOp);
eq(1, $visitsOf($mI), '★ 切到 by_order：整笔算 1 次');

// 清理
$vc->cfg()->set('visit_count_mode', 'by_portion');
$ids = [$mA, $mB, $mC, $mD, $mE, $mF, $mG, $mH, $mI];
$in  = implode(',', array_fill(0, count($ids), '?'));
$vcDb->exec("DELETE FROM point_ledger WHERE store_code=? AND member_id IN ($in)", array_merge([SMOKE_STORE], $ids));
$vcDb->exec("DELETE FROM coupon WHERE store_code=? AND member_id IN ($in)", array_merge([SMOKE_STORE], $ids));
$vcDb->exec("DELETE FROM member WHERE store_code=? AND id IN ($in)", array_merge([SMOKE_STORE], $ids));
$vcDb->exec('DELETE FROM pos_order WHERE store_code=? AND serial_id LIKE ?', [SMOKE_STORE, '98088800%']);

step('㉔ 同一张卡不能在同一张单上记两次');

/**
 * ★ 这一段来自一次现场质疑：屏幕上「+27 分」，同时又提示
 *   「本餐期已记过 1 次，这一单只记积分不计次」，怀疑重复计分了。
 *
 *   查下来【没有重复计分】—— 金额守恒挡着，那是同一张单的另一半。
 *   但守恒挡不住「把一张单分两次都记给同一个人」：AA 拆成两半，
 *   两半都选同一张卡，金额照样守恒，只是分了两笔。
 *   收银员完全没法判断这是正常的下半单，还是自己点重了。
 *
 *   而把 AA 的两半都给同一个人，现实中基本只可能是误操作 ——
 *   真要整单给一个人，人数填 1 就行，不必拆。所以直接禁掉。
 *
 * 下面两件事都要钉住：① 确实没有重复计分 ② 第二次会被拒。
 */
$dupApp = new App(['store_code' => SMOKE_STORE, 'local_db' => $dbCfg, 'pos_db' => []]);
$dupDb  = $dupApp->localDb();
$dupApp->cfg()->set('late_grant_minutes', '0');
$dupApp->cfg()->set('max_grants_per_period', '0');
$dupApp->cfg()->set('visit_count_mode', 'once_per_period');

$dupPos = new FakePosSource();
$dupPos->now = date('Y-m-d H:i:s');
$dupPos->addHead([
    'serial_id' => '9707770001', 'order_head_id' => 970001, 'check_id' => 1,
    'table_name' => 'DUP', 'eat_type' => 0, 'customer_num' => 2,
    'original_amount' => '55.75', 'should_amount' => '55.75', 'actual_amount' => '55.75',
    'order_end_time' => date('Y-m-d H:i:s'),
]);
$dupPos->addDetail(970001, 1, [FakePosSource::line(2390, 'MENÚ INFINITY NOCHE', '23.90', '55.75', 2)]);
$dupApp->setPosSource($dupPos);
$dupApp->points()->locate('DUP', 600);

$dupMid = (int)$dupApp->members()->create('TK-00097701-DUP', null, null, null)['id'];
$dupOp  = ['id' => 1, 'name' => '收银员', 'device' => 'SMOKE', 'role' => 1, 'is_manager' => false];

// ── ① 第一次记一半 ──
$d1 = $dupApp->points()->grant('9707770001',
    [['member_id' => $dupMid, 'amount_cents' => 2788, 'portions' => 1]],
    Vip\PointsEngine::MODE_SPLIT, $dupOp);
ok($d1['ok'], '第一次记一半（€ 27.88）');
eq(1, (int)$d1['entries'][0]['visits'], '  └ 计次 1');

// ── ② 同一张卡再记另一半 → 拒 ──
$d2 = $dupApp->points()->grant('9707770001',
    [['member_id' => $dupMid, 'amount_cents' => 2787, 'portions' => 1]],
    Vip\PointsEngine::MODE_SPLIT, $dupOp);
ok(!$d2['ok'] && $d2['error'] === 'member_already_on_order',
   '★★★ 同一张卡在同一张单上记第二次 → 被拒（' . ($d2['error'] ?? '竟然成功了') . '）');
// 卡号入库时会走 Crockford 归一化（去连字符、U→V 等），所以认序号那一段
ok(str_contains((string)($d2['detail']['card_no'] ?? ''), '00097701'),
   '  └ 告诉前端是哪张卡（' . ($d2['detail']['card_no'] ?? '没给') . '），好把话说清楚');

// ── ③ 换一张卡记另一半 → 正常 ──
$dupMid2 = (int)$dupApp->members()->create('TK-00097702-DUP', null, null, null)['id'];
$d3 = $dupApp->points()->grant('9707770001',
    [['member_id' => $dupMid2, 'amount_cents' => 2787, 'portions' => 1]],
    Vip\PointsEngine::MODE_SPLIT, $dupOp);
ok($d3['ok'], '★★ 换一张卡记另一半照常 —— 挡的是「同一张卡」，不是「分两次」');

// ── ④ 钉住「本来就没有重复计分」──
/**
 * ★★ 这一条是回答现场那个质疑的正题：
 *   即便当初两半都记给了同一张卡，金额也是守恒的 —— 总额没有翻倍。
 */
$ord = $dupDb->one('SELECT total_amount, allocated_amount FROM pos_order WHERE store_code=? AND serial_id=?',
                   [SMOKE_STORE, '9707770001']);
ok((float)$ord['allocated_amount'] <= (float)$ord['total_amount'],
   sprintf('★★★ 金额守恒：已分配 €%s ≤ 总额 €%s —— 分几次记都不会重复计分',
           $ord['allocated_amount'], $ord['total_amount']));
eq('55.75', (string)$ord['allocated_amount'], '  └ 两半加起来正好是整单，不多不少');

// ── ⑤ 撤销之后可以重记 ──
$revId = (int)$dupDb->value(
    'SELECT id FROM point_ledger WHERE store_code=? AND serial_id=? AND member_id=? AND entry_type=1 AND status=1',
    [SMOKE_STORE, '9707770001', $dupMid]);
$dupApp->points()->reverse($revId, '冒烟测试 · 撤销后重记', $dupOp);
$d5 = $dupApp->points()->grant('9707770001',
    [['member_id' => $dupMid, 'amount_cents' => 2788, 'portions' => 1]],
    Vip\PointsEngine::MODE_SPLIT, $dupOp);
ok($d5['ok'], '★★ 撤销那一笔之后，同一张卡可以重新记 —— 挡的是有效流水，不是历史');

// 清理
$dupDb->exec('DELETE FROM point_ledger WHERE store_code=? AND member_id IN (?,?)', [SMOKE_STORE, $dupMid, $dupMid2]);
$dupDb->exec('DELETE FROM coupon WHERE store_code=? AND member_id IN (?,?)', [SMOKE_STORE, $dupMid, $dupMid2]);
$dupDb->exec('DELETE FROM member WHERE store_code=? AND id IN (?,?)', [SMOKE_STORE, $dupMid, $dupMid2]);
$dupDb->exec('DELETE FROM pos_order WHERE store_code=? AND serial_id=?', [SMOKE_STORE, '9707770001']);

step('㉕ 份数必须带金额：钱和次数绑在一起');

/**
 * ★ 这一段来自客人（店主）自己推演出来的一个洞，实测确实存在：
 *
 *     A 拿走全部金额 €71.70 / 1 份 → 积分 71、计次 1
 *     B 提交「金额 € 0 / 要 1 份」  → 竟然也成功，积分 0、计次 1
 *
 *   金额守恒只管上限，管不住「0 元也要一份」。而次数才是奖励的真正来源
 *   （十送一），金额分完之后份数还剩着，等于把最值钱的那一半白送出去。
 *
 *   规则因此改成：一笔分配要么【钱和次一起计】，要么整笔拒绝。
 *   反过来「有钱没份」照常允许 —— 只点酒水没点套餐是正常生意。
 */
$binApp = new App(['store_code' => SMOKE_STORE, 'local_db' => $dbCfg, 'pos_db' => []]);
$binDb  = $binApp->localDb();
$binApp->cfg()->set('late_grant_minutes', '0');
$binApp->cfg()->set('max_grants_per_period', '0');
$binApp->cfg()->set('visit_count_mode', 'once_per_period');

$binPos = new FakePosSource();
$binPos->now = date('Y-m-d H:i:s');
$binPos->addHead([
    'serial_id' => '9606660001', 'order_head_id' => 960001, 'check_id' => 1,
    'table_name' => 'BIND', 'eat_type' => 0, 'customer_num' => 3,
    'original_amount' => '71.70', 'should_amount' => '71.70', 'actual_amount' => '71.70',
    'order_end_time' => date('Y-m-d H:i:s'),
]);
$binPos->addDetail(960001, 1, [FakePosSource::line(2390, 'MENÚ INFINITY NOCHE', '23.90', '71.70', 3)]);
$binApp->setPosSource($binPos);
$binApp->points()->locate('BIND', 601);

$binA = (int)$binApp->members()->create('TK-00098801-BND', null, null, null)['id'];
$binB = (int)$binApp->members()->create('TK-00098802-BND', null, null, null)['id'];
$binOp = ['id' => 1, 'name' => '收银员', 'device' => 'SMOKE', 'role' => 1, 'is_manager' => false];

// ── ① A 正常记 47.80 / 2 份 ──
$b1 = $binApp->points()->grant('9606660001',
    [['member_id' => $binA, 'amount_cents' => 4780, 'portions' => 2]],
    Vip\PointsEngine::MODE_SPLIT, $binOp);
ok($b1['ok'], 'A 记 € 47.80 / 2 份');
eq(1, (int)$b1['entries'][0]['visits'], '  └ 计次 1');

// ── ② B 想「0 元换一次」→ 拒 ──
$b2 = $binApp->points()->grant('9606660001',
    [['member_id' => $binB, 'amount_cents' => 0, 'portions' => 1]],
    Vip\PointsEngine::MODE_SPLIT, $binOp);
ok(!$b2['ok'] && $b2['error'] === 'portions_without_amount',
   '★★★ 「金额 € 0、要 1 份」→ 被拒（' . ($b2['error'] ?? '竟然成功了') . '）—— 这就是那个洞');

// ── ③ 同一次提交里夹带一个 0 元的人 → 整笔拒 ──
$b3 = $binApp->points()->grant('9606660001',
    [['member_id' => $binB, 'amount_cents' => 2390, 'portions' => 1],
     ['member_id' => $binA, 'amount_cents' => 0,    'portions' => 1]],
    Vip\PointsEngine::MODE_SPLIT, $binOp);
ok(!$b3['ok'], '★★ 一笔里混进 0 元的人 → 整笔拒绝，要么一起计要么一起拒');
eq(0, (int)$binDb->value(
        'SELECT COUNT(*) FROM point_ledger WHERE store_code=? AND serial_id=? AND member_id=?',
        [SMOKE_STORE, '9606660001', $binB]),
   '  └ 整笔回滚：B 那半边也没落库（不能一半成功一半失败）');

// ── ④ B 老老实实分餐费 → 照常通过 ──
$b4 = $binApp->points()->grant('9606660001',
    [['member_id' => $binB, 'amount_cents' => 2390, 'portions' => 1]],
    Vip\PointsEngine::MODE_SPLIT, $binOp);
ok($b4['ok'], '★★ B 分到 € 23.90 / 1 份 → 通过（挡的是白拿，不是拆分）');
eq(1, (int)$b4['entries'][0]['visits'], '  └ 计次 1');

// ── ⑤ 有钱没份照常允许（点了不计次的 MENÚ DEL DIA）──
$binPos->addHead([
    'serial_id' => '9606660002', 'order_head_id' => 960002, 'check_id' => 1,
    'table_name' => 'BIND2', 'eat_type' => 0, 'customer_num' => 1,
    'original_amount' => '15.90', 'should_amount' => '15.90', 'actual_amount' => '15.90',
    'order_end_time' => date('Y-m-d H:i:s'),
]);
$binPos->addDetail(960002, 1, [FakePosSource::line(1590, 'MENÚ DEL DIA', '15.90', '15.90', 1)]);
$binApp->points()->locate('BIND2', 602);
$binC = (int)$binApp->members()->create('TK-00098803-BND', null, null, null)['id'];
$b5 = $binApp->points()->grant('9606660002',
    [['member_id' => $binC, 'amount_cents' => 1590, 'portions' => 0]],
    Vip\PointsEngine::MODE_WHOLE, $binOp);
ok($b5['ok'], '★★ 有金额、0 份 → 通过（该积分、不该计次：绑定只有一个方向）');
eq(0, (int)$b5['entries'][0]['visits'], '  └ 计次 0');

// ── ⑥ 全库不变量：不存在「0 元却计了次」的流水 ──
eq([], $binDb->all(
    'SELECT serial_id, member_id, amount, counted_visit
       FROM point_ledger
      WHERE store_code = ? AND entry_type = 1 AND status = 1
        AND counted_visit > 0 AND amount <= 0',
    [SMOKE_STORE]),
   '★★★ 全库不存在「金额 0 却计了次」的有效流水 —— 次数永远跟着钱走');

// ── ⑦ 反面：份数余数要摊开，不能堆给第一位 ──
/**
 * ★ 这是 ②③ 的镜像，店主同一轮里点出来的：「也要防止有积分但没份数」。
 *
 *   AA 均摊时份数除不尽，余数原本【全堆给第一位】——
 *   这在旧口径 by_portion 下没问题（第一位记 N 次，总数守恒）；
 *   换成 once_per_period 之后就变成：
 *
 *     10 份 4 人 → [4, 2, 2, 2]  第一位那多出来的 2 份完全白费，
 *                                而如果是 3 份 4 人 → [3,0,0,0]，
 *                                后三位付了钱【一次都没有】
 *
 *   而且不报错、不告警。客人要等到攒够十次那天才发现少了，
 *   那时候已经没法查了。份数余数因此改成【一人一份地摊开】。
 *
 * ★ 这里用「10 份 4 人」而不是「3 份 4 人」：⑧ 那条会员数上限
 *   （最多记到份数那么多位）已经把「份数比人少」的情况挡在门外了，
 *   纯函数那一侧的形状由 AllocationTest 钉着。
 */
$binPos->addHead([
    'serial_id' => '9606660003', 'order_head_id' => 960003, 'check_id' => 1,
    'table_name' => 'BIND3', 'eat_type' => 0, 'customer_num' => 4,
    'original_amount' => '239.00', 'should_amount' => '239.00', 'actual_amount' => '239.00',
    'order_end_time' => date('Y-m-d H:i:s'),
]);
$binPos->addDetail(960003, 1, [FakePosSource::line(2390, 'MENÚ INFINITY NOCHE', '23.90', '239.00', 10)]);
$binApp->points()->locate('BIND3', 603);

$binFour  = [];
$binAlloc = [];
$shares   = Vip\PointsEngine::splitEvenly(23900, 10, 4);   // 10 份分给 4 个人
foreach ($shares as $i => $sh) {
    $mid = (int)$binApp->members()->create(sprintf('TK-0009881%d-BND', $i), null, null, null)['id'];
    $binFour[]  = $mid;
    $binAlloc[] = ['member_id' => $mid] + $sh;
}
eq([3, 3, 2, 2], array_column($shares, 'portions'),
   '★★★ 10 份 4 人 → 份数摊成 [3,3,2,2]，不是 [4,2,2,2]');

$b7 = $binApp->points()->grant('9606660003', $binAlloc, Vip\PointsEngine::MODE_SPLIT, $binOp);
ok($b7['ok'], '四人 AA 一次提交成功');
eq([1, 1, 1, 1], array_map(static fn(array $e): int => (int)$e['visits'], $b7['entries']),
   '★★★ 四位各记 1 次 —— 没有人因为份数被别人多占而落空');
ok(count(array_filter($b7['entries'], static fn(array $e): bool => (int)$e['points'] > 0)) === 4,
   '  └ 四个人都拿到了积分');

// ── ⑧ 一张单最多记几位 = 计次套餐份数（0 份的单只准 1 位）──
/**
 * ★ 店主提的第三条：「添加会员也不可以无限添加，最多只能添加到份数的会员；
 *   如果没有套餐（套餐数 0），则只能添加 1 个会员」。
 *
 *   这一条挡得住守恒挡不住的形状：3 份的单拆给 5 个人、
 *   份数填成 [1,1,1,0,0] —— 份数没超、金额没超，前两层全都放行。
 *   份数是这张单上「有几个人在这儿吃了饭」唯一可信的凭据。
 */
$binPos->addHead([
    'serial_id' => '9606660004', 'order_head_id' => 960004, 'check_id' => 1,
    'table_name' => 'BIND4', 'eat_type' => 0, 'customer_num' => 5,
    'original_amount' => '71.70', 'should_amount' => '71.70', 'actual_amount' => '71.70',
    'order_end_time' => date('Y-m-d H:i:s'),
]);
$binPos->addDetail(960004, 1, [FakePosSource::line(2390, 'MENÚ INFINITY NOCHE', '23.90', '71.70', 3)]);
$binApp->points()->locate('BIND4', 604);

$binFive = [];
for ($i = 0; $i < 5; $i++) {
    $binFive[] = (int)$binApp->members()->create(sprintf('TK-0009882%d-BND', $i), null, null, null)['id'];
}
// 3 份的单，5 个人，份数填成 [1,1,1,0,0] —— 份数与金额都不超
$capAlloc = [];
foreach ($binFive as $i => $mid) {
    $capAlloc[] = ['member_id' => $mid, 'amount_cents' => 1434, 'portions' => $i < 3 ? 1 : 0];
}
$b8 = $binApp->points()->grant('9606660004', $capAlloc, Vip\PointsEngine::MODE_SPLIT, $binOp);
ok(!$b8['ok'] && $b8['error'] === 'too_many_members',
   '★★★ 3 份的单要记 5 位 → 被拒（' . ($b8['error'] ?? '竟然成功了') . '）—— 份数与金额都没超，守恒那两层看不出来');
eq(3, (int)($b8['detail']['cap'] ?? 0), '  └ 告诉前端上限是几位（3），好把话说清楚');

// 3 位正好，通过
$b8b = $binApp->points()->grant('9606660004', array_slice($capAlloc, 0, 3),
                                Vip\PointsEngine::MODE_SPLIT, $binOp);
ok($b8b['ok'], '★★ 同一张单记 3 位 → 通过（挡的是超出份数，不是拆分）');

// 第 4 位再来（换一张没记过的卡）→ 仍然被拒，跨提交也算数
$b8c = $binApp->points()->grant('9606660004',
    [['member_id' => $binFive[3], 'amount_cents' => 1434, 'portions' => 0]],
    Vip\PointsEngine::MODE_SPLIT, $binOp);
ok(!$b8c['ok'] && $b8c['error'] === 'too_many_members',
   '★★★ 记满 3 位之后第 4 位再来 → 还是拒 —— 上限算的是【这张单一共几位】，不是【这一笔几位】');

// 0 份的单只准 1 位：9606660002 是 MENÚ DEL DIA（算餐费、积分，但不计次）
$binZero = (int)$binApp->members()->create('TK-00098830-BND', null, null, null)['id'];
$b8d = $binApp->points()->grant('9606660002',
    [['member_id' => $binZero, 'amount_cents' => 100, 'portions' => 0]],
    Vip\PointsEngine::MODE_SPLIT, $binOp);
ok(!$b8d['ok'] && $b8d['error'] === 'too_many_members',
   '★★★ 0 份的单已经记了 1 位，第 2 位 → 拒（' . ($b8d['error'] ?? '竟然成功了') . '）');
ok((int)($b8d['detail']['cap'] ?? 0) === 1,
   '  └ 上限是 1 位 —— 纯酒水单该给积分，但证明不了几个人吃了饭，所以不给拆');

// 清理
$binExtra = implode(',', array_merge($binFour, $binFive, [$binZero]));
$binDb->exec("DELETE FROM point_ledger WHERE store_code=? AND member_id IN ({$binExtra})", [SMOKE_STORE]);
$binDb->exec("DELETE FROM coupon       WHERE store_code=? AND member_id IN ({$binExtra})", [SMOKE_STORE]);
$binDb->exec("DELETE FROM member       WHERE store_code=? AND id        IN ({$binExtra})", [SMOKE_STORE]);
$binDb->exec('DELETE FROM point_ledger WHERE store_code=? AND member_id IN (?,?,?)', [SMOKE_STORE, $binA, $binB, $binC]);
$binDb->exec('DELETE FROM coupon WHERE store_code=? AND member_id IN (?,?,?)', [SMOKE_STORE, $binA, $binB, $binC]);
$binDb->exec('DELETE FROM member WHERE store_code=? AND id IN (?,?,?)', [SMOKE_STORE, $binA, $binB, $binC]);
$binDb->exec('DELETE FROM pos_order WHERE store_code=? AND serial_id IN (?,?,?,?)', [SMOKE_STORE, '9606660001', '9606660002', '9606660003', '9606660004']);

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
