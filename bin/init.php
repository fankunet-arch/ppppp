<?php
declare(strict_types=1);

/**
 * ════════════════════════════════════════════════════════════════
 * 首次部署初始化（CLI）
 *
 *   php bin/init.php check              只检查环境与连接，不改任何数据
 *   php bin/init.php migrate            建表（会 DROP，有安全闸门）
 *   php bin/init.php seed               灌入配置、餐期、套餐规则
 *   php bin/init.php admin <工号> <姓名> 创建管理员（PIN 交互输入）
 *   php bin/init.php passwd <工号>      重置某人的 PIN（管理员锁死时的逃生口）
 *   php bin/init.php all                以上全部（migrate 除外，需显式执行）
 * ════════════════════════════════════════════════════════════════
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("本脚本只能从命令行执行\n");
}

$task = $argv[1] ?? '';
if ($task === '' || in_array($task, ['-h', '--help', 'help'], true)) {
    echo <<<TXT
    用法：php bin/init.php <任务>

      check                     检查 PHP 扩展、配置、两个数据库的连通性
      migrate                   建表（DROP TABLE，有安全闸门）
      seed                      灌入配置、餐期、套餐规则（幂等，可重复执行）
      admin <工号> <显示名>      创建管理员账号
      passwd <工号>              重置该账号 PIN 并解除锁定（忘记 PIN 时用）
      all                       check + seed（migrate 需显式单独执行）

    TXT;
    exit(0);
}

$config = require __DIR__ . '/../app/bootstrap.php';

use Vip\App;
use Vip\Service\AuthService;

$app  = new App($config);
$fail = 0;

function ok(string $m): void  { echo "  \033[32m✓\033[0m {$m}\n"; }
function bad(string $m): void { global $fail; $fail++; echo "  \033[31m✗\033[0m {$m}\n"; }
function warn(string $m): void{ echo "  \033[33m!\033[0m {$m}\n"; }
function head(string $m): void{ echo "\n\033[1m{$m}\033[0m\n"; }

// ════════════════════════════════════════════════════════════
function doCheck(App $app, array $config): void
{
    head('PHP 环境');
    PHP_VERSION_ID >= 80200
        ? ok('PHP ' . PHP_VERSION)
        : bad('PHP ' . PHP_VERSION . '，需要 8.2 以上');
    foreach (['pdo_mysql' => '本地库', 'mysqli' => 'POS 只读（需要 MYSQLI_OPT_READ_TIMEOUT）',
              'mbstring' => '多字节字符串', 'json' => 'JSON', 'openssl' => '令牌与加密'] as $ext => $why) {
        extension_loaded($ext) ? ok("扩展 {$ext}（{$why}）") : bad("缺少扩展 {$ext}（{$why}）");
    }

    head('配置');
    foreach (['store_code', 'local_db', 'pos_db'] as $k) {
        isset($config[$k]) ? ok("config.{$k}") : bad("config.{$k} 缺失");
    }
    if (($config['local_db']['charset'] ?? '') === 'utf8mb4') {
        ok('本地库字符集 utf8mb4');
    } else {
        warn('本地库字符集建议 utf8mb4');
    }
    if (($config['pos_db']['charset'] ?? '') === 'utf8') {
        ok('POS 字符集 utf8（主库是 3 字节 utf8，不是 utf8mb4）');
    } else {
        warn('POS 字符集应为 utf8 —— 主库是 3 字节 utf8');
    }
    (int)($config['pos_db']['read_timeout'] ?? 0) > 0
        ? ok('POS 读超时已设置（MySQL 5.5 服务端无 MAX_EXECUTION_TIME，全靠客户端兜底）')
        : bad('POS read_timeout 未设置 —— 慢查询将无法掐断');

    head('本地数据库');
    try {
        $db = $app->localDb();
        $v  = (string)$db->pdo()->query('SELECT VERSION()')->fetchColumn();
        ok("连接成功：{$v}（{$db->serverFlavor()}）");
        $tables = [];
        foreach ($db->all('SHOW TABLES') as $r) {
            $tables[] = (string)array_values($r)[0];
        }
        $need = ['pos_order','member','point_ledger','meal_item_rule','sys_config',
                 'sync_cursor','audit_log','alert','operator','operator_session'];
        $miss = array_diff($need, $tables);
        $miss ? warn('缺少表：' . implode(', ', $miss) . ' —— 请执行 migrate')
              : ok('全部 ' . count($need) . ' 张表已存在');
    } catch (\Throwable $e) {
        bad('本地库连接失败：' . $e->getMessage());
    }

    head('POS 主库（只读）');
    try {
        $now = $app->posReader()->now();
        ok("连接成功，主库当前时间 {$now}");
        $drift = abs(time() - strtotime($now));
        $drift <= 60
            ? ok("与本机时钟相差 {$drift} 秒")
            : warn("与本机时钟相差 {$drift} 秒 —— 时间基准一律用主库 NOW()，但仍建议校时");

        $probe = $app->posReader()->findRecentByTable('__nonexistent__', 30, 1);
        ok('history_order_head 可读（' . count($probe) . ' 行）');
        $app->posReader()->fetchMajorGroups();
        ok('major_group / family_group 可读');
    } catch (\Throwable $e) {
        // POS 不可达不算致命 —— 降级路径仍可用
        warn('POS 主库暂时不可达：' . $e->getMessage());
        warn('收银流程仍可用（手工录入降级），但订单定位与补抓会停摆');
    }
}

// ════════════════════════════════════════════════════════════
/**
 * 建表 / 升级。
 *
 * ★ 必须支持【增量升级】：系统上线后仍会有 schema 变更
 *   （例如 003 为十送一核销加列）。早先的实现是把 migrations 全量重跑，
 *   而 001 里有 DROP TABLE，于是安全闸门一拦，
 *   线上库就再也没有任何办法应用新迁移了。
 *
 * 现在的规则：
 *   · 用 schema_migration 表记录已应用的文件，只跑没跑过的
 *   · 含 DROP TABLE 的迁移属破坏性，库里有业务数据时一律拒绝
 *   · 纯 ALTER 类迁移随时可应用
 *   · 老库首次引入本机制时做一次基线登记：已经建好的表说明
 *     对应的破坏性迁移早已执行过，直接标记为已应用，不重跑
 */
function doMigrate(App $app): void
{
    $db = $app->localDb();

    head('安全检查');
    $tables = [];
    foreach ($db->all('SHOW TABLES') as $r) {
        $tables[] = (string)array_values($r)[0];
    }
    $rows = 0;
    foreach (['pos_order', 'member', 'point_ledger'] as $t) {
        if (in_array($t, $tables, true)) {
            $rows += (int)$db->value("SELECT COUNT(*) FROM `{$t}`");
        }
    }

    // 迁移登记表本身用 IF NOT EXISTS，双兼容且可重复执行
    $db->pdo()->exec(
        "CREATE TABLE IF NOT EXISTS `schema_migration` (
           `filename`   VARCHAR(120) NOT NULL,
           `applied_at` DATETIME     NOT NULL,
           PRIMARY KEY (`filename`)
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC"
    );

    $applied = [];
    foreach ($db->all('SELECT filename FROM schema_migration') as $r) {
        $applied[(string)$r['filename']] = true;
    }

    $files = glob(__DIR__ . '/../db/migrations/*.sql') ?: [];
    sort($files);

    // 基线登记：库里已有业务表却没有登记记录 → 破坏性迁移显然早已跑过
    if (!$applied && $rows > 0) {
        foreach ($files as $f) {
            if (stripos((string)file_get_contents($f), 'DROP TABLE') !== false) {
                $db->exec('INSERT INTO schema_migration (filename, applied_at) VALUES (?,?)',
                    [basename($f), $db->now()]);
                $applied[basename($f)] = true;
                warn('基线登记（判定为早已执行，不重跑）：' . basename($f));
            }
        }
    }

    $pending = array_values(array_filter($files, fn($f) => !isset($applied[basename($f)])));
    if (!$pending) {
        ok('没有待应用的迁移，schema 已是最新');
        return;
    }

    // 破坏性迁移 + 有数据 = 拒绝
    $destructive = array_filter($pending,
        fn($f) => stripos((string)file_get_contents($f), 'DROP TABLE') !== false);
    if ($destructive && $rows > 0) {
        echo "\n\033[31m拒绝执行：库中已有 {$rows} 行业务数据，而待应用的 "
           . implode(', ', array_map('basename', $destructive))
           . " 含 DROP TABLE，会全部销毁。\033[0m\n";
        echo "若确实要重建，请先手工备份并清空，或换一个空库。\n";
        exit(1);
    }
    ok($rows > 0 ? "库中 {$rows} 行业务数据，待应用的迁移均为非破坏性" : '库中无业务数据，可以安全建表');

    head('执行 migrations');
    foreach ($pending as $f) {
        $db->pdo()->exec((string)file_get_contents($f));
        $db->exec('INSERT INTO schema_migration (filename, applied_at) VALUES (?,?)',
            [basename($f), $db->now()]);
        ok(basename($f));
    }
}

// ════════════════════════════════════════════════════════════
function doSeed(App $app): void
{
    head('执行 seeds（幂等，可重复执行）');
    $db    = $app->localDb();
    $store = $app->storeCode();
    foreach (glob(__DIR__ . '/../db/seeds/*.sql') ?: [] as $f) {
        $sql = file_get_contents($f);
        // 种子里门店码写死为 S001，按当前配置替换
        if ($store !== 'S001') {
            $sql = str_replace("SET @store := 'S001';", "SET @store := '{$store}';", $sql);
        }
        $db->pdo()->exec($sql);
        ok(basename($f) . ($store !== 'S001' ? "（门店码替换为 {$store}）" : ''));
    }
    $n = (int)$db->value('SELECT COUNT(*) FROM meal_item_rule WHERE store_code = ?', [$store]);
    ok("套餐规则表 {$n} 条");
    $c = (int)$db->value('SELECT COUNT(*) FROM sys_config WHERE store_code = ?', [$store]);
    ok("系统配置 {$c} 项");
}

// ════════════════════════════════════════════════════════════
function doAdmin(App $app, ?string $login, ?string $name): void
{
    if ($login === null || $name === null) {
        echo "用法：php bin/init.php admin <工号> <显示名>\n";
        exit(1);
    }
    head("创建管理员 {$login}（{$name}）");

    $pin = readPinTwice();
    $id  = $app->auth()->createOperator($login, $name, $pin, AuthService::ROLE_ADMIN);
    ok("已创建，operator_id = {$id}");
    warn('PIN 用 password_hash 存储，不可找回。忘记了用 `php bin/init.php passwd ' . $login . '` 重置');
}

/** 交互式读两遍 PIN，不回显 */
function readPinTwice(): string
{
    $min = AuthService::MIN_PIN;
    echo "  请输入 PIN（至少 {$min} 位，输入不回显）：";
    system('stty -echo 2>/dev/null');
    $pin = trim((string)fgets(STDIN));
    echo "\n  请再输入一次：";
    $pin2 = trim((string)fgets(STDIN));
    system('stty echo 2>/dev/null');
    echo "\n";

    if ($pin !== $pin2)      { bad('两次输入不一致'); exit(1); }
    if (strlen($pin) < $min) { bad("PIN 至少 {$min} 位"); exit(1); }
    return $pin;
}

/**
 * 重置某个操作员的 PIN —— 【管理员把自己锁死时唯一的逃生口】。
 *
 * 没有这条路的话：唯一的管理员忘了 PIN → 登不进后台建新管理员，
 * 而 (store_code, login_name) 是唯一索引、同名也建不了第二个，
 * audit_log 又按 operator_id 引用，删号会把历史审计记录变成孤儿 ——
 * 等于整个后台永久锁死。
 *
 * 本命令要求服务器 shell 权限，这个信任边界是合理的：
 * 能登服务器的人本来就能直接改库。
 */
function doPasswd(App $app, ?string $login): void
{
    if ($login === null) {
        echo "用法：php bin/init.php passwd <工号>\n";
        exit(1);
    }
    $db = $app->localDb();
    $op = $db->one('SELECT id, login_name, display_name, role, enabled FROM operator
                     WHERE store_code = ? AND login_name = ?',
                   [$app->storeCode(), $login]);
    if ($op === null) {
        bad("门店 {$app->storeCode()} 下没有工号 {$login}");
        $all = $db->all('SELECT login_name, role, enabled FROM operator WHERE store_code = ? ORDER BY id',
                        [$app->storeCode()]);
        if ($all) {
            echo "  现有工号：\n";
            foreach ($all as $a) {
                printf("    %-12s role=%s %s\n", $a['login_name'], $a['role'],
                    (int)$a['enabled'] === 1 ? '' : '（已停用）');
            }
        }
        exit(1);
    }

    head("重置 {$op['login_name']}（{$op['display_name']}）的 PIN");
    $pin = readPinTwice();

    $r = $app->auth()->resetPin((int)$op['id'], $pin, ['id' => 0, 'name' => 'CLI']);
    if (!($r['ok'] ?? false)) {
        bad('重置失败：' . ($r['error'] ?? '未知'));
        exit(1);
    }
    ok('已重置，连续失败锁定一并解除');
    warn('该账号原有的登录会话全部作废，需要重新登录');
    if ((int)$op['enabled'] !== 1) {
        warn('注意：该账号当前是【停用】状态，重置后仍然登不进去，请先在后台启用');
    }
}

// ════════════════════════════════════════════════════════════
try {
    switch ($task) {
        case 'check':   doCheck($app, $config); break;
        case 'migrate': doMigrate($app); break;
        case 'seed':    doSeed($app); break;
        case 'admin':   doAdmin($app, $argv[2] ?? null, $argv[3] ?? null); break;
        case 'passwd':  doPasswd($app, $argv[2] ?? null); break;
        case 'all':     doCheck($app, $config); doSeed($app); break;
        default:
            fwrite(STDERR, "未知任务：{$task}\n");
            exit(1);
    }
} catch (Throwable $e) {
    echo "\n\033[31m失败：" . $e->getMessage() . "\033[0m\n";
    exit(1);
}

echo "\n";
if ($fail > 0) {
    echo "\033[31m{$fail} 项未通过\033[0m\n";
    exit(1);
}
echo "\033[32m完成\033[0m\n";
exit(0);
