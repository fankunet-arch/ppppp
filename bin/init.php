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
      seed                      灌入配置、餐期、套餐规则
                                ⚠️ 会把配置覆盖回默认值 —— 已上线的库慎用，见 docs/06 §3.4
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
              'mbstring' => '多字节字符串', 'json' => 'JSON', 'openssl' => '令牌与加密',
              'curl' => '出站发送确认短信'] as $ext => $why) {
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
        // ★ 加表时这里必须跟着加，否则自检会漏 —— 曾经漏掉 card 与 coupon，
        //   结果 migrate 没跑的库照样报「全部 10 张表已存在」，
        //   现场看到这句以为没问题，实际点发卡直接报错
        $need = ['pos_order','member','point_ledger','coupon','meal_item_rule','meal_period',
                 'sys_config','sync_cursor','audit_log','alert','operator','operator_session',
                 'card'];
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
/**
 * 该迁移文件是否真的执行 DROP TABLE。
 * 判定逻辑在 Vip\SqlText —— 必须先剥注释与字符串，
 * 否则注释里提一句就会把整条迁移拦在生产库外面（实测踩过）。
 */
function sqlHasDropTable(string $file): bool
{
    return \Vip\SqlText::hasDropTable((string)file_get_contents($file));
}

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
            if (sqlHasDropTable($f)) {
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
    $destructive = array_filter($pending, fn($f) => sqlHasDropTable($f));
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
    $seedFiles = glob(__DIR__ . '/../db/seeds/*.sql') ?: [];
    /**
     * ★ 一个种子文件都没有时必须明说。
     *   实测现场踩过：部署时只拷了 wwwroot/app/bin，db 目录没传，
     *   于是这里静默跑完、只报「规则表 0 条」，看起来像门店码配错，
     *   实际是文件根本不在。少这一句，方向就指反了。
     */
    if (!$seedFiles) {
        bad('db/seeds/ 下一个 .sql 都没有 —— 种子文件没部署到服务器上');
        echo "      期望位置：" . realpath(__DIR__ . '/..') . DIRECTORY_SEPARATOR
           . 'db' . DIRECTORY_SEPARATOR . "seeds\n";
        echo "      把项目的 db 目录整个拷过来（migrations 与 seeds 都要），再跑一次\n";
        return;
    }
    foreach ($seedFiles as $f) {
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
    // stty 是 Unix 专属；Windows 上没有，只能回显输入
    $canHide = strncasecmp(PHP_OS_FAMILY, 'Windows', 7) !== 0;
    echo "  请输入 PIN（至少 {$min} 位"
       . ($canHide ? '，输入不回显' : '，注意：Windows 下会显示出来') . '）：';
    if ($canHide) { system('stty -echo 2>/dev/null'); }
    $pin = trim((string)fgets(STDIN));
    echo "\n  请再输入一次：";
    $pin2 = trim((string)fgets(STDIN));
    if ($canHide) { system('stty echo 2>/dev/null'); }
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

/**
 * ════════════════════════════════════════════════════════════
 * repair —— 一条命令跑完「诊断 + 能修的直接修 + 说清还差什么」
 *
 * 做这个是因为现场排一次故障要来回三趟：先 diag、再 migrate、再 seed，
 * 中间还要等人回话。三步都是幂等的，没有理由不合成一步。
 *
 * 只做安全动作：建表（含 DROP 的迁移在有数据时仍拒绝）、灌种子（幂等）。
 * 不删数据、不动 POS。
 * ════════════════════════════════════════════════════════════
 */
function doRepair(App $app, array $config): void
{
    $problems = [];

    // ── 1. PHP 扩展 ──────────────────────────────────────
    head('1/5 PHP 扩展');
    /**
     * ★ 必须把【全部】必需扩展列全。
     *   实测踩过：这里原先只查 pdo_mysql 与 mysqli，漏了 mbstring，
     *   于是 repair 报「全部就绪」，而现场每张带折扣行的单一查就抛
     *   Call to undefined function mb_strtoupper() —— 界面只显示
     *   「系统内部错误」，查了好几轮。自检漏一项，等于把故障推到线上。
     */
    foreach (['pdo_mysql' => '本地库', 'mysqli' => 'POS 只读',
              'mbstring'  => '多字节字符串', 'json' => 'JSON',
              'openssl'   => '登录令牌',
              'curl'      => '出站发送确认短信'] as $ext => $why) {
        if (extension_loaded($ext)) {
            ok("{$ext}（{$why}）");
        } else {
            bad("缺少 {$ext}（{$why}）");
            $problems[] = "装/开启 PHP 扩展 {$ext}"
                . "（Windows：php.ini 里去掉 extension={$ext} 前的分号，然后重启 Web 服务）";
        }
    }

    // ── 2. 本地库连通性 ──────────────────────────────────
    head('2/5 本地库连接');
    $d = $config['local_db'] ?? [];
    printf("  目标 %s@%s:%s/%s\n", $d['user'] ?? '?', $d['host'] ?? '?',
        $d['port'] ?? '?', $d['database'] ?? '?');
    try {
        $app->localDb()->value('SELECT 1');
        ok('连接正常');
    } catch (Throwable $e) {
        // 驱动码才能区分原因，SQLSTATE 一律是 HY000
        $drv = 0;
        if (preg_match('/\[(\d{4})\]/', $e->getMessage(), $m)) {
            $drv = (int)$m[1];
        }
        $why = match ($drv) {
            2002, 2003 => 'MySQL 服务没起，或端口/主机不对',
            1045       => '口令错，或这个来源主机没被授权'
                        . "（'u'@'localhost' 与 'u'@'127.0.0.1' 是两条不同授权）",
            1044, 1049 => '库不存在，或该用户对这个库没权限',
            1040       => '连接数打满',
            default    => str_contains($e->getMessage(), 'could not find driver')
                        ? 'pdo_mysql 扩展没装/没开' : '未知',
        };
        bad("连不上：{$why}");
        // ★ 不能直接用 mb_substr：这一支的前提就是「现场环境不对」，
        //   而扩展检查那一步只 bad() 报一句、不退出。于是
        //   「Windows 没装 mbstring」+「库还没起来」同时成立时，
        //   这里会抛 Call to undefined function mb_substr() ——
        //   而它本来要打印的正是下面那句「服务管理器里启动 MySQL」。
        //   诊断工具在报告故障时自己崩掉，比不报还糟（审计 F12 同一类）。
        echo '      原文：' . (function_exists('mb_substr')
            ? mb_substr($e->getMessage(), 0, 100)
            : substr($e->getMessage(), 0, 100)) . "\n";
        echo "\n\033[31m本地库连不上，后面几步没法做。先解决这一条。\033[0m\n";
        if ($drv === 2002 || $drv === 2003) {
            echo "  Windows：服务管理器里启动 MySQL/MariaDB，或在宝塔/XAMPP 面板里启动\n";
            echo "  Linux：  sudo service mysql start\n";
        }
        exit(1);
    }

    // ── 3. 表结构 ────────────────────────────────────────
    head('3/5 表结构（缺就补，幂等）');
    try {
        doMigrate($app);
    } catch (Throwable $e) {
        bad('建表失败：' . $e->getMessage());
        $problems[] = '手工处理建表：' . $e->getMessage();
    }

    // ── 4. 配置与套餐规则 ────────────────────────────────
    head('4/5 配置与套餐规则（按当前门店码重灌，幂等）');
    $store = $app->storeCode();
    try {
        doSeed($app);
    } catch (Throwable $e) {
        bad('灌种子失败：' . $e->getMessage());
        $problems[] = '手工处理种子：' . $e->getMessage();
    }

    // ── 5. 结果核验 —— 直接回答「套餐会不会显示 0 份」 ────
    head('5/5 核验：套餐份数能不能算出来');
    $db = $app->localDb();
    $mine = (int)$db->value('SELECT COUNT(*) FROM meal_item_rule WHERE store_code = ?', [$store]);
    $cv   = (int)$db->value(
        'SELECT COUNT(*) FROM meal_item_rule WHERE store_code = ? AND counts_visit = 1', [$store]);
    printf("  当前门店码 %s\n", $store);
    if ($mine === 0) {
        bad('规则表里没有本门店的规则 → 所有订单都会显示「套餐 0 份」');
        $others = $db->all('SELECT store_code, COUNT(*) n FROM meal_item_rule GROUP BY store_code');
        // ★ 必须区分这两种，修法完全不同：
        //   表里有别的门店码 → 门店码配错
        //   表整个是空的     → 种子压根没灌进来（多半是 db 目录没部署）
        if ($others) {
            foreach ($others as $o) {
                printf("      门店码「%s」下有 %d 条\n", $o['store_code'], $o['n']);
            }
            $problems[] = "规则表门店码对不上：把 config.php 的 store_code 改成上面那个，"
                        . '或重新跑 php bin/init.php seed';
        } else {
            echo "      整张表是空的（不是门店码的问题）—— 种子没灌进来\n";
            $seedDir = realpath(__DIR__ . '/..') . DIRECTORY_SEPARATOR . 'db'
                     . DIRECTORY_SEPARATOR . 'seeds';
            $n = count(glob($seedDir . DIRECTORY_SEPARATOR . '*.sql') ?: []);
            echo "      {$seedDir} 下有 {$n} 个 .sql 文件\n";
            $problems[] = $n === 0
                ? "把项目的 db 目录整个拷到服务器（现在 {$seedDir} 是空的或不存在），再跑一次"
                : '种子文件在但没灌进去，看上面 4/5 的报错';
        }
    } elseif ($cv === 0) {
        bad("有 {$mine} 条规则，但没有一条 counts_visit=1 → 份数照样恒为 0");
        $problems[] = '到后台「套餐规则」页把套餐项的「计次」打开';
    } else {
        ok("规则 {$mine} 条，其中 {$cv} 条参与计次 —— 份数可以正常算出来");
    }

    $ops = (int)$db->value('SELECT COUNT(*) FROM operator WHERE store_code = ? AND enabled = 1', [$store]);
    $ops > 0 ? ok("可用账号 {$ops} 个")
             : bad('一个可用账号都没有 → 登不进后台，跑 php bin/init.php admin admin 系统管理员');
    if ($ops === 0) {
        $problems[] = '建管理员：php bin/init.php admin admin 系统管理员';
    }

    /**
     * ── 配置项 card_prefix ──────────────────────────────
     *
     * ★ 这一项必须在 repair 里查，因为 README 指的第一条排查命令就是 repair。
     *
     *   前缀含 I / L / O / U 时，扫码纠错（CardNumber::normalize）会把它们
     *   换成 1 / 1 / 0 / V，卡号于是跟自己对不上 —— 而且 CardService 是在
     *   /auth/login 的响应里被构造的，于是【收银员整个登不进去】，
     *   屏幕上只有一句「系统内部错误」。
     *
     *   实测过一次「repair 说全部就绪，而店里登不进」——
     *   那种组合比直接报错难查得多。
     */
    $prefix = strtoupper(trim((string)($config['card_prefix'] ?? 'TK')));
    if (!preg_match('/^[A-Z]{1,4}$/', $prefix)) {
        bad("config.card_prefix = {$prefix} —— 必须是 1~4 位字母");
        $problems[] = '改 app/config/config.php 的 card_prefix（1~4 位字母）';
    } elseif (strpbrk($prefix, 'ILOU') !== false) {
        bad("config.card_prefix = {$prefix} —— 不能含 I / L / O / U");
        echo "     这几个字母在卡面上与 1 / 0 / V 分不清，扫码纠错会把它们换掉，
";
        echo "     卡号跟自己对不上：实体卡的【查卡 / 建卡 / 激活 / 换卡】全部停用。\n";
        echo "     记积分不受影响（找单、记账、手工录入照常），但客人手里那张卡\n";
        echo "     查不了也激活不了 —— 等于会员体系只剩下已经绑好的那些人能用。\n";
        echo "     上线前改掉，别等卡印出来。\n";
        $problems[] = "改 app/config/config.php 的 card_prefix：{$prefix} → 换成不含 I/L/O/U 的（TK、SV、MK…）";
    } else {
        ok("config.card_prefix = {$prefix}（不含 I/L/O/U）");
    }

    // ── 汇总 ─────────────────────────────────────────────
    head('结论');
    if (!$problems) {
        ok('全部就绪 —— 后台应该能进，套餐份数应该能算出来');
        echo "  若 Pad 上仍有问题，跑：php bin/why.php <桌号>  或  php bin/why.php --invoice <小票号>\n";
        return;
    }
    echo "\033[31m还差这些（按顺序处理）：\033[0m\n";
    foreach ($problems as $i => $p) {
        printf("  %d) %s\n", $i + 1, $p);
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
        case 'repair':  doRepair($app, $config); break;
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
