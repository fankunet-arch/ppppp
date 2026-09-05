<?php
declare(strict_types=1);

/**
 * ════════════════════════════════════════════════════════════════
 * 连接诊断 —— 把「本地数据库暂时不可用」翻译成具体原因
 *
 * 页面上的提示是【统一兜底】，故意不显示连接串与堆栈（会泄露口令）。
 * 六种完全不同的故障在界面上长得一模一样：
 *   口令错 / 用户不存在 / 库名不存在 / 端口错 / 主机不可达 / 授权主机不匹配
 * 本脚本逐项试出来，直接告诉你是哪一种、怎么改。
 *
 * ★ 一定要用【Web 服务器的身份】跑一遍：
 *     Linux    sudo -u www-data php bin/diag.php
 *     Windows  在 IIS/Apache 服务账号下跑，或直接看 §④ 的授权提示
 *   很多「命令行好好的、网页就不行」都是身份差异造成的 ——
 *   配置文件权限、以及 MySQL 按来源主机授权（localhost 与 127.0.0.1
 *   在 MySQL 眼里是两个不同的授权条目）。
 * ════════════════════════════════════════════════════════════════
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("本脚本只能从命令行执行\n");
}

$C  = static fn(string $s, string $c): string => "\033[{$c}m{$s}\033[0m";
$ok = static fn(string $m) => print('  ' . "\033[32m✓\033[0m" . " {$m}\n");
$no = static fn(string $m) => print('  ' . "\033[31m✗\033[0m" . " {$m}\n");
$wa = static fn(string $m) => print('  ' . "\033[33m!\033[0m" . " {$m}\n");
$hd = static fn(string $m) => print("\n\033[1m{$m}\033[0m\n");

// posix_* 只在 Linux/Unix 有；Windows 上没有这套扩展，必须回落
$IS_WIN  = strncasecmp(PHP_OS_FAMILY, 'Windows', 7) === 0;
$whoami  = function_exists('posix_geteuid')
    ? (string)(posix_getpwuid(posix_geteuid())['name'] ?? get_current_user())
    : (string)(getenv('USERNAME') ?: get_current_user());
$whoami  = trim($whoami) !== '' ? trim($whoami) : '(未知)';
echo "\033[1m连接诊断\033[0m   当前身份：{$whoami}\n";
echo "                 平台：" . PHP_OS_FAMILY . "\n";
if ($whoami === 'root') {
    $wa('你正在用 root 跑。请【再用 Web 用户跑一遍】：sudo -u www-data php bin/diag.php');
}

$fail = 0;

// ── 1. 配置文件本身 ───────────────────────────────────────
$hd('① 配置文件');
$cfgFile = __DIR__ . '/../app/config/config.php';

if (!is_file($cfgFile)) {
    $no("不存在：{$cfgFile}");
    echo "     从模板复制：cp app/config/config.example.php app/config/config.php\n";
    exit(1);
}
$ok('文件存在');

if (!is_readable($cfgFile)) {
    $st = stat($cfgFile);
    $owner = function_exists('posix_getpwuid')
        ? (posix_getpwuid($st['uid'])['name'] ?? $st['uid']) : $st['uid'];
    $no("当前身份 {$whoami} 【读不到】这个文件（属主 {$owner}，权限 "
        . substr(sprintf('%o', $st['mode']), -3) . '）');
    echo "     这种情况页面通常是空白 500，不是「数据库不可用」。修法：\n";
    echo $IS_WIN
        ? "       在文件属性→安全里，给 IIS/Apache 的服务账号加上「读取」权限\n"
        : "       chown {$whoami}:{$whoami} app/config/config.php && chmod 640 app/config/config.php\n";
    exit(1);
}
$ok('可读');

$cfg = @include $cfgFile;
if (!is_array($cfg)) {
    $no('文件没有返回数组 —— 多半是 PHP 语法错误，或结尾少了 return');
    echo "     用这条看具体报错：php -l app/config/config.php\n";
    exit(1);
}
$ok('语法正确，返回了配置数组');

foreach (['store_code', 'local_db', 'pos_db'] as $k) {
    isset($cfg[$k]) ? $ok("config.{$k} 已填") : $no("config.{$k} 缺失");
}
$local = $cfg['local_db'] ?? [];
foreach (['host', 'port', 'database', 'user'] as $k) {
    if (($local[$k] ?? '') === '' || $local[$k] === null) {
        $no("config.local_db.{$k} 是空的");
        $fail++;
    }
}
if (($local['password'] ?? '') === '') {
    $wa('config.local_db.password 是空的（若数据库账号确实没口令可忽略）');
}
echo "     本地库目标：{$local['user']}@{$local['host']}:{$local['port']}/{$local['database']}\n";

/**
 * ★ card_prefix 必须在这里查，不能等到发卡那天。
 *
 *   前缀含 I / L / O / U 时，扫码纠错（normalize）会把它们换成
 *   1 / 1 / 0 / V，卡号于是跟自己对不上 —— 自己生成的卡号被自己判为非法，
 *   查卡、建卡、激活全部失效。而按 docs/10 的上线步骤，
 *   发卡是最后一步：卡都印出来了才发现，就只能重印。
 */
// ★ 这里【不加载 autoloader】—— diag 的全部价值就在于「什么都坏了它还能跑」。
//   所以规则在这儿抄一份（与 CardNumber 构造函数一致），而不是去 new 它。
$prefix = strtoupper(trim((string)($cfg['card_prefix'] ?? 'TK')));
if (!preg_match('/^[A-Z]{1,4}$/', $prefix)) {
    $no("config.card_prefix = {$prefix} —— 必须是 1~4 位字母");
    $fail++;
} elseif (strpbrk($prefix, 'ILOU') !== false) {
    $no("config.card_prefix = {$prefix} —— 不能含 I / L / O / U");
    echo "\n     这几个字母在卡面上与 1 / 0 / V 分不清，扫码纠错会把它们换掉，\n";
    echo "     卡号于是跟自己对不上：查卡、建卡、激活会全部失效。\n";
    echo "     而按 docs/10，发卡是上线的最后一步 —— 不改的话，卡印出来了才会发现。\n";
    echo "     改成不含这四个字母的即可（TK、SV、MK…）。\n\n";
    $fail++;
} else {
    $ok("config.card_prefix = {$prefix}（不含 I/L/O/U，可用）");
}

// ── 2. PHP 扩展 ───────────────────────────────────────────
$hd('② PHP 扩展');
foreach (['pdo_mysql' => '本地库', 'mysqli' => 'POS 只读'] as $ext => $why) {
    if (extension_loaded($ext)) {
        $ok("{$ext}（{$why}）");
        continue;
    }
    $fail++;
    $no("缺少 {$ext}（{$why}）");
    // 这正是错误日志里 "could not find driver" 的成因
    echo "     ★ 页面报「本地数据库暂时不可用」、日志写 could not find driver，\n";
    echo "       就是这一条 —— PDO 装了但没有 MySQL 驱动。\n";
    if ($IS_WIN) {
        echo "       Windows：编辑 php.ini，去掉这两行前面的分号后重启 Web 服务\n";
        echo "           extension=pdo_mysql\n           extension=mysqli\n";
        echo "       php.ini 位置：" . (php_ini_loaded_file() ?: '(未加载 php.ini)') . "\n";
        echo "       确认 ext 目录下有 php_pdo_mysql.dll、php_mysqli.dll，\n";
        echo "       且 php.ini 的 extension_dir 指向它。\n";
    } else {
        echo "       Debian/Ubuntu：apt install php-mysql && systemctl restart php*-fpm\n";
        echo "       RHEL/CentOS ：dnf install php-mysqlnd && systemctl restart php-fpm\n";
    }
    echo "       改完用这条确认：php -m | findstr mysql   （Linux 用 grep）\n";
}
if (php_ini_loaded_file()) {
    echo "     当前 php.ini：" . php_ini_loaded_file() . "\n";
}

// ── 3. 网络层能不能通 ─────────────────────────────────────
$hd('③ 能不能连到数据库服务器');
$host = (string)($local['host'] ?? '127.0.0.1');
$port = (int)($local['port'] ?? 3306);
$t0   = microtime(true);
$sock = @fsockopen($host, $port, $errno, $errstr, 5);
$ms   = (int)((microtime(true) - $t0) * 1000);
if ($sock) {
    fclose($sock);
    $ok("TCP {$host}:{$port} 通（{$ms} ms）");
} else {
    $no("TCP {$host}:{$port} 不通 —— [{$errno}] {$errstr}");
    echo "     常见原因：\n";
    echo "       · 数据库没启动           systemctl status mariadb\n";
    echo "       · 端口填错               默认 3306\n";
    echo "       · 数据库只监听本机       my.cnf 的 bind-address，跨机要设 0.0.0.0\n";
    echo "       · 防火墙挡住             firewall-cmd / ufw 放行 {$port}\n";
    exit(1);
}

// ── 4. 账号口令与库 ───────────────────────────────────────
$hd('④ 账号、口令、库名');
$dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s',
    $host, $port, $local['database'] ?? '', $local['charset'] ?? 'utf8mb4');
try {
    $pdo = new PDO($dsn, (string)($local['user'] ?? ''), (string)($local['password'] ?? ''), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 5,
    ]);
    $ok('连接成功');
} catch (PDOException $e) {
    $code = (string)$e->getCode();
    $no("连不上 —— SQLSTATE {$code}");
    echo '     ' . str_replace("\n", ' ', $e->getMessage()) . "\n\n";
    echo "     \033[1m这就是页面上「本地数据库暂时不可用」的真正原因。\033[0m\n";
    switch ($code) {
        case '1045':
            echo "     口令错，或该账号不允许从这台机器连。\n";
            echo "     ★ MySQL 是【按来源主机】授权的，'u'@'localhost' 与 'u'@'127.0.0.1'\n";
            echo "       是两条不同的授权 —— 命令行能连不代表网页能连。\n";
            echo "       在数据库上执行：SELECT user, host FROM mysql.user WHERE user='"
                 . ($local['user'] ?? '') . "';\n";
            echo "       缺哪个补哪个：\n";
            echo "         CREATE USER '{$local['user']}'@'{$host}' IDENTIFIED BY '口令';\n";
            echo "         GRANT ALL ON `{$local['database']}`.* TO '{$local['user']}'@'{$host}';\n";
            echo "         FLUSH PRIVILEGES;\n";
            break;
        case '1044':
            echo "     账号口令没问题，但对库 `{$local['database']}` 没有权限，\n";
            echo "     或者这个库根本不存在。\n";
            echo "       SHOW DATABASES LIKE '{$local['database']}';\n";
            echo "       GRANT ALL ON `{$local['database']}`.* TO '{$local['user']}'@'{$host}';\n";
            break;
        case '1049':
            echo "     库 `{$local['database']}` 不存在。先建库（注意排序规则）：\n";
            echo "       CREATE DATABASE `{$local['database']}`\n";
            echo "         DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\n";
            break;
        case '2002':
            echo "     连接被拒 —— 数据库没起来，或端口/socket 不对。\n";
            break;
        default:
            echo "     按上面的原文排查。\n";
    }
    exit(1);
}

// ── 5. 表建了没 ───────────────────────────────────────────
$hd('⑤ 表结构');
$need = ['pos_order','member','point_ledger','meal_item_rule','sys_config',
         'sync_cursor','audit_log','alert','operator','operator_session'];
$have = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
$miss = array_diff($need, $have);
if ($miss) {
    $no('缺少表：' . implode(', ', $miss));
    echo "     执行：php bin/init.php migrate\n";
    $fail++;
} else {
    $ok('全部 ' . count($need) . ' 张表都在');
    $nOp = (int)$pdo->query('SELECT COUNT(*) FROM operator')->fetchColumn();
    $nOp > 0 ? $ok("operator 表有 {$nOp} 个账号")
             : $no('operator 表是空的 —— 没有账号就登不进去，执行：php bin/init.php admin <工号> <显示名>');
    if ($nOp === 0) { $fail++; }

    $store = (string)($cfg['store_code'] ?? '');
    $nMine = (int)$pdo->query('SELECT COUNT(*) FROM operator WHERE store_code = '
        . $pdo->quote($store))->fetchColumn();
    if ($nOp > 0 && $nMine === 0) {
        $no("有账号，但没有一个属于门店码「{$store}」—— 登录是按 (store_code, login_name) 查的");
        echo "     库里现有的门店码：";
        echo implode(', ', $pdo->query('SELECT DISTINCT store_code FROM operator')->fetchAll(PDO::FETCH_COLUMN));
        echo "\n     要么改 config.store_code，要么改 operator.store_code，两边必须一致。\n";
        $fail++;
    } elseif ($nMine > 0) {
        $ok("其中 {$nMine} 个属于当前门店码「{$store}」");
    }
}

// ── 6. 日志位置 ───────────────────────────────────────────
/**
 * ── ⑥ POS 主库：连得上，还要「有新单」──────────────────────
 *
 * 这一节是照着现场那句「按桌号就是查不到刚买单的桌，也不知道为什么」加的。
 *
 * 按桌号定位要求 order_end_time 落在时间窗内（出厂 30 分钟）。
 * 下面这几种局面都会让它恒为空，而在 Pad 上长得一模一样（「未找到」）：
 *   · POS 写 order_end_time 用的时钟与主库 NOW() 不是同一个（时区配错）
 *     —— 最阴的一种：PHP 与 POS 的 NOW() 会完全一致，
 *     现有的时钟偏差告警（阈值一整天）一声不响；
 *   · pos_db 指到了备份库或旧副本，或指错了库；
 *   · POS 那一侧停止写 history_order_head；
 *   · 店里现在确实还没有人买单。
 *
 * 前三种都不是「时间窗太窄」，而现场最容易做的动作恰恰是把窗口调大 ——
 * 那只会把陈年旧单放进来，把真正的问题盖住。所以这里直接把
 * 「主库现在几点」和「最新一张单几点」并排摆出来，让人一眼能对。
 */
$hd('⑥ POS 主库（只读）');
/**
 * ★ 这一节也【不用 autoloader】—— 与本文件其余部分同一条规矩：
 *   diag 的全部价值就在于「什么都坏了它还能跑」。所以直接用 mysqli，
 *   顺带把「mysqli 能不能连主库」这件事本身也验了，
 *   不依赖 app/ 那一层是否装配得起来。
 */
$pc = $cfg['pos_db'] ?? [];
if (!extension_loaded('mysqli')) {
    $no('mysqli 没装，POS 侧无法检查（上面②已经报过）');
} elseif (($pc['host'] ?? '') === '') {
    $wa('config.php 里没有配 pos_db —— 只用手工录入的话可以，按桌号查单则用不了');
} else {
    $pm = mysqli_init();
    $pm->options(MYSQLI_OPT_CONNECT_TIMEOUT, (int)($pc['connect_timeout'] ?? 3));
    $pm->options(MYSQLI_OPT_READ_TIMEOUT,    (int)($pc['read_timeout'] ?? 5));
    try {
        @$pm->real_connect((string)$pc['host'], (string)$pc['user'], (string)$pc['password'],
                           (string)$pc['database'], (int)($pc['port'] ?? 3306));
        $ok(sprintf('连得上：%s@%s/%s', (string)$pc['user'], (string)$pc['host'], (string)$pc['database']));

        $posNow = (string)($pm->query('SELECT NOW() AS n')->fetch_assoc()['n'] ?? '');
        $r = $pm->query('SELECT MAX(order_end_time) AS t FROM history_order_head');
        $newest = $r ? ($r->fetch_assoc()['t'] ?? null) : null;

        echo "     主库 NOW()        {$posNow}\n";
        if ($newest === null) {
            $no('history_order_head 里一张已结账的单都没有 —— 多半是连错了库');
            $fail++;
        } else {
            $age = (int)floor((strtotime($posNow) - strtotime((string)$newest)) / 60);
            echo "     最新一张已结账单  {$newest}（{$age} 分钟前）\n";
            /**
             * ── 🔴 「连得上」不等于「有新单」──────────────────────
             *
             * 按桌号定位要求 order_end_time 落在时间窗内（出厂 30 分钟）。
             * 下面几种局面都会让它恒为空，而在 Pad 上长得一模一样（「未找到」）：
             *   · POS 写 order_end_time 用的时钟与主库 NOW() 不是同一个（时区配错）
             *     —— 最阴的一种：PHP 与 POS 的 NOW() 会完全一致，
             *     现有的时钟偏差告警（阈值一整天）一声不响；
             *   · pos_db 指到了备份库或旧副本，或指错了库；
             *   · POS 那一侧停止写 history_order_head；
             *   · 店里现在确实还没有人买单。
             *
             * 前三种都不是「时间窗太窄」，而现场最容易做的动作恰恰是把窗口调大 ——
             * 那只会把陈年旧单放进来，把真正的问题盖住。
             */
            if ($age > 120) {
                $no("最新一张单已经是 {$age} 分钟前 —— 按桌号（默认只找近 30 分钟）会一直查不到");
                echo "     \033[33m→ 店里刚刚还在买单的话，这【不是】时间窗太窄。按可能性查：\033[0m\n";
                echo "     \033[33m  ① POS 写单的时钟与主库 NOW() 不是同一个（时区配错）——\033[0m\n";
                echo "     \033[33m     拿一张刚打的小票，对照它上面的时间和上面那个 NOW()\033[0m\n";
                echo "     \033[33m  ② config.php 的 pos_db 指到了备份库或旧副本（确认 host / database）\033[0m\n";
                echo "     \033[33m  ③ POS 那一侧不再写 history_order_head（升级、换版本、磁盘满）\033[0m\n";
                echo "     \033[33m  ④ 现在确实还没有人买单 —— 那就等有单了再看一次\033[0m\n";
                $fail++;
            } else {
                $ok('POS 侧在正常出单');
            }
        }
        @$pm->close();
    } catch (\Throwable $e) {
        $no('连不上 POS 主库：' . $e->getMessage());
        echo "     \033[33m→ 收银流程仍可继续（手工录入），但按桌号/小票号查单会一直查不到\033[0m\n";
        echo "     \033[33m  先确认 pos_db 的 host / port / user / password / database\033[0m\n";
        $fail++;
    }
}

$hd('⑦ 错误日志');
$logDir = $cfg['log_path'] ?? (__DIR__ . '/../var/log');
$logFile = rtrim((string)$logDir, "/\\") . '/php-error.log';
if (is_file($logFile)) {
    $ok("日志：{$logFile}");
    is_writable($logFile) ? $ok('可写')
        : $no('当前身份写不了 —— ' . ($IS_WIN ? '在文件属性里给服务账号「写入」权限' : "chown {$whoami} {$logFile}"));
} else {
    is_dir($logDir) && is_writable($logDir)
        ? $wa("日志尚未生成：{$logFile}")
        : $no("日志目录不可写：{$logDir} —— "
            . ($IS_WIN ? '建好目录后给服务账号「写入」权限' : "mkdir -p 后 chown {$whoami}"));
}
echo "     出问题时先看它：tail -50 {$logFile}\n";

echo "\n" . str_repeat('─', 58) . "\n";
if ($fail > 0) {
    echo "\033[31m发现 {$fail} 个问题\033[0m，按上面的提示处理\n";
    exit(1);
}
echo "\033[32m本地库一切正常\033[0m";
echo $whoami === 'root' ? "（记得再用 Web 用户跑一遍：sudo -u www-data php bin/diag.php）\n" : "\n";
