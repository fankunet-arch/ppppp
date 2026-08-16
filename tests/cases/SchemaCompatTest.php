<?php
declare(strict_types=1);

/**
 * DDL 双兼容检查 —— 把 db/README.md 的禁用清单变成自动化检查。
 *
 * 环境里没有 MySQL / MariaDB 可供实跑，这层静态检查用来兜住
 * 「一边能建、另一边报错」的那几类问题。
 */

$sqlDir  = __DIR__ . '/../../db';
$files   = array_merge(
    glob($sqlDir . '/migrations/*.sql') ?: [],
    glob($sqlDir . '/seeds/*.sql') ?: []
);
$ddl     = file_get_contents($sqlDir . '/migrations/001_init.sql');
$allSql  = '';
foreach ($files as $f) {
    $allSql .= file_get_contents($f) . "\n";
}

/** 去掉注释行，避免把文档里的说明文字当成 SQL */
$strip = static function (string $s): string {
    $out = [];
    foreach (explode("\n", $s) as $line) {
        $t = ltrim($line);
        if (str_starts_with($t, '--')) {
            continue;
        }
        $out[] = $line;
    }
    return implode("\n", $out);
};
$ddlCode = $strip($ddl);
$allCode = $strip($allSql);

T::group('DDL 双兼容 —— 排序规则');

preg_match_all('/CREATE TABLE `(\w+)`/', $ddlCode, $m);
$tables = $m[1];
T::true(count($tables) >= 10, '建表语句数量 ' . count($tables) . ' ≥ 10');

$engineCount = preg_match_all('/ENGINE=InnoDB/', $ddlCode);
T::eq(count($tables), $engineCount, '每张表都显式 ENGINE=InnoDB');

$collateCount = preg_match_all('/COLLATE=utf8mb4_unicode_ci/', $ddlCode);
T::eq(count($tables), $collateCount,
    '每张表都显式 COLLATE=utf8mb4_unicode_ci（MySQL 8 默认的 0900_ai_ci 在 MariaDB 中不存在）');

$rowFmtCount = preg_match_all('/ROW_FORMAT=DYNAMIC/', $ddlCode);
T::eq(count($tables), $rowFmtCount, '每张表都显式 ROW_FORMAT=DYNAMIC');

T::false((bool)preg_match('/utf8mb4_0900/i', $allCode),
    '未使用 utf8mb4_0900_* 系列排序规则（MariaDB 不支持）');
T::false((bool)preg_match('/utf8mb4_general_ci/i', $allCode),
    '未使用 utf8mb4_general_ci（两边默认值不同，须显式统一为 unicode_ci）');

T::group('DDL 双兼容 —— 禁用的类型与特性');

T::false((bool)preg_match('/`\w+`\s+JSON\b/i', $ddlCode),
    '未使用 JSON 列类型（MariaDB 中仅为 LONGTEXT 别名，行为不一致）');
T::false((bool)preg_match('/\bCHECK\s*\(/i', $ddlCode),
    '未使用 CHECK 约束（MySQL 5.7 静默忽略、MariaDB 10.2+ 强制执行）');
T::false((bool)preg_match('/DEFAULT\s+CURRENT_TIMESTAMP/i', $ddlCode),
    '未使用 DEFAULT CURRENT_TIMESTAMP（时间一律由应用层写入，统一时区口径）');
T::false((bool)preg_match('/GENERATED\s+ALWAYS\s+AS/i', $ddlCode),
    '未使用生成列');

T::group('DDL 双兼容 —— 索引键长 ≤ 767 字节（兼容旧版 COMPACT 行格式）');

/**
 * 粗略估算：解析每张表的列长度，再算每个索引的键长。
 * utf8mb4 每字符 4 字节；INT=4、BIGINT=8、SMALLINT=2、TINYINT=1、
 * DATE=3、DATETIME=5、DECIMAL(11,2)≈6。
 */
$blocks = preg_split('/CREATE TABLE /', $ddlCode);
$violations = [];
$checked = 0;
foreach ($blocks as $b) {
    if (!preg_match('/^`(\w+)`/', $b, $tm)) {
        continue;
    }
    $table = $tm[1];
    $len = [];
    if (preg_match_all('/`(\w+)`\s+(VARCHAR\((\d+)\)|BIGINT|INT|SMALLINT|TINYINT|DATETIME|DATE|TIME|DECIMAL\(\d+,\d+\)|TEXT)/i', $b, $cm, PREG_SET_ORDER)) {
        foreach ($cm as $c) {
            $type = strtoupper($c[2]);
            $len[$c[1]] = match (true) {
                str_starts_with($type, 'VARCHAR') => ((int)$c[3]) * 4,
                str_starts_with($type, 'BIGINT')  => 8,
                str_starts_with($type, 'SMALLINT') => 2,
                str_starts_with($type, 'TINYINT') => 1,
                str_starts_with($type, 'INT')     => 4,
                str_starts_with($type, 'DECIMAL') => 6,
                $type === 'DATETIME'              => 5,
                $type === 'DATE'                  => 3,
                $type === 'TIME'                  => 3,
                default                           => 0,
            };
        }
    }
    // 解析索引定义。注意必须支持前缀索引写法 `email`(100)：
    // 用 (?:[^()]|\(\d+\))+ 允许括号内嵌套「(数字)」，否则会在前缀括号处截断，
    // 把前缀索引误算成整列长度。
    if (preg_match_all('/(?:PRIMARY KEY|UNIQUE KEY `\w+`|KEY `\w+`)\s*\(((?:[^()]|\(\d+\))+)\)/i', $b, $km, PREG_SET_ORDER)) {
        foreach ($km as $k) {
            $cols  = $k[1];
            $total = 0;
            // 支持前缀索引写法 `email`(100)
            preg_match_all('/`(\w+)`(?:\((\d+)\))?/', $cols, $pm, PREG_SET_ORDER);
            foreach ($pm as $p) {
                $col = $p[1];
                if (isset($p[2]) && $p[2] !== '') {
                    $total += ((int)$p[2]) * 4;      // 前缀长度按字符 ×4
                } else {
                    $total += $len[$col] ?? 0;
                }
            }
            $checked++;
            if ($total > 767) {
                $violations[] = "$table: ($cols) = {$total} 字节";
            }
        }
    }
}
T::true($checked > 20, "共检查 {$checked} 个索引定义");
T::eq([], $violations, '所有索引键长均 ≤ 767 字节' . ($violations ? "\n      超限: " . implode("\n            ", $violations) : ''));

T::group('应用层 SQL —— 禁用语法（db/README.md §2.1）');

$phpFiles = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/../../app'));
foreach ($it as $f) {
    if ($f->isFile() && $f->getExtension() === 'php') {
        $phpFiles[] = $f->getPathname();
    }
}
/** 剥掉 PHP 注释 —— 否则「不用 SKIP LOCKED」这类说明会被当成实际用法 */
$stripPhpComments = static function (string $code): string {
    $out = '';
    foreach (token_get_all($code) as $tok) {
        if (is_array($tok)) {
            if (in_array($tok[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $out .= $tok[1];
        } else {
            $out .= $tok;
        }
    }
    return $out;
};

$phpSrc = '';
foreach ($phpFiles as $f) {
    $phpSrc .= $stripPhpComments(file_get_contents($f)) . "\n";
}

T::false((bool)preg_match('/SKIP\s+LOCKED/i', $phpSrc),
    '未使用 SKIP LOCKED（需 MySQL 8.0+ / MariaDB 10.6+）');
T::false((bool)preg_match('/FOR\s+UPDATE\s+NOWAIT/i', $phpSrc),
    '未使用 FOR UPDATE NOWAIT');
T::false((bool)preg_match('/\bWITH\s+\w+\s+AS\s*\(/i', $phpSrc),
    '未使用 CTE');
T::false((bool)preg_match('/\b(ROW_NUMBER|RANK|DENSE_RANK|LAG|LEAD)\s*\(\s*\)\s*OVER/i', $phpSrc),
    '未使用窗口函数');
T::false((bool)preg_match('/JSON_TABLE\s*\(/i', $phpSrc),
    '未使用 JSON_TABLE（仅 MySQL 8.0）');
T::false((bool)preg_match('/\bRETURNING\b/i', $phpSrc),
    '未使用 RETURNING（仅 MariaDB 10.5+）');
T::true((bool)preg_match("/STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION/", $phpSrc),
    '连接后统一设置 sql_mode');
T::false((bool)preg_match('/ONLY_FULL_GROUP_BY/i', $phpSrc),
    'sql_mode 不含 ONLY_FULL_GROUP_BY（两边默认值相反）');

T::group('POS 只读约束 —— 主库不得出现任何写语句');

$posSrc = $stripPhpComments(file_get_contents(__DIR__ . '/../../app/lib/PosReader.php'));
foreach (['INSERT', 'UPDATE', 'DELETE', 'REPLACE', 'TRUNCATE', 'ALTER', 'DROP', 'CREATE'] as $kw) {
    T::false((bool)preg_match('/\b' . $kw . '\s+(INTO|TABLE|FROM|`)/i', $posSrc),
        "PosReader 中不含 {$kw} 语句");
}
T::true((bool)preg_match('/history_order_head/', $posSrc), 'PosReader 读 history_order_head');
T::false((bool)preg_match('/FROM\s+order_head\b/i', $posSrc),
    '★ 不读活动表 order_head（该表无任何时间索引，按时间查必然全表扫）');

$posDbSrc = file_get_contents(__DIR__ . '/../../app/lib/PosDb.php');  // 这里要保留注释外的常量定义
T::true((bool)preg_match('/MYSQLI_OPT_READ_TIMEOUT/', $posDbSrc),
    '设置了查询读取超时（MySQL 5.5 无 MAX_EXECUTION_TIME，服务端无法掐断慢查询）');
T::true((bool)preg_match('/MAX_LIMIT\s*=\s*100/', $posDbSrc),
    'LIMIT 上限固化为 100');

T::group('API 层 —— 启动期不得急切连库');

$routesSrc = file_get_contents(__DIR__ . '/../../app/api/routes.php');

/**
 * 回归防护：曾经在 routes.php 顶层直接
 *   $auth = new AuthService($app->localDb(), ...)
 * 导致本地库一旦不可达，路由注册阶段就抛 PDOException，
 * 连 /health 都到不了，且响应体为空 —— 完全无法排障。
 * 一切依赖数据库的对象必须惰性构造。
 */
T::false((bool)preg_match('/^\$\w+\s*=\s*new\s+\w+\(\s*\$app->(localDb|cfg|orders|members|ledger|points)\(/m', $routesSrc),
    '顶层没有用 $app->localDb() 等急切构造对象（必须惰性）');
T::true((bool)preg_match('/\$authRef\s*\?\?=/', $routesSrc),
    'AuthService 惰性构造');

$entrySrc = file_get_contents(__DIR__ . '/../../wwwroot/api.php');
T::true((bool)preg_match('/catch\s*\(\s*\\\\?PDOException/', $entrySrc),
    '入口顶层捕获 PDOException → 返回 db_unavailable 而非空响应体');
T::true((bool)preg_match('/catch\s*\(\s*\\\\?Throwable/', $entrySrc),
    '入口顶层捕获 Throwable → 任何异常都产出 JSON');

$apiSrc = file_get_contents(__DIR__ . '/../../app/lib/Http/Api.php');
T::true((bool)preg_match("/'httponly'\s*=>\s*true/", $apiSrc), '会话 Cookie 设为 httpOnly');
T::true((bool)preg_match("/'samesite'\s*=>\s*'Strict'/", $apiSrc), '会话 Cookie 设为 SameSite=Strict');

$authSrc = file_get_contents(__DIR__ . '/../../app/lib/Service/AuthService.php');
T::true((bool)preg_match('/password_hash\s*\(/', $authSrc), 'PIN 用 password_hash 存储');
T::true((bool)preg_match('/password_verify\s*\(/', $authSrc), '校验用 password_verify');
T::true((bool)preg_match("/hash\('sha256',\s*\\\$token\)/", $authSrc),
    '会话令牌库中存 SHA-256，明文只在 Cookie');
T::true((bool)preg_match('/MAX_FAILED/', $authSrc), '有连续失败锁定（防 4 位 PIN 被枚举）');

T::group('CP 后台 —— 权限与只读边界');

$cpSrc = file_get_contents(__DIR__ . '/../../app/cp/routes.php');

T::true((bool)preg_match('/\$authRef\s*\?\?=/', $cpSrc),
    'CP 的 AuthService 同样惰性构造');
T::true((bool)preg_match('/is_manager.*Api::fail\(.forbidden/s', $cpSrc),
    '非经理账号被拒（服务员不得进入后台）');
T::true((bool)preg_match('/ROLE_ADMIN.*Api::fail\(.forbidden/s', $cpSrc),
    '管理员专属操作有 role 校验');

// 写操作必须要求管理员，不能只要求经理
foreach (['/rules/save', '/config/save', '/members/erase', '/operators/create', '/operators/toggle'] as $route) {
    $ok = (bool)preg_match(
        '#\$api->on\(\'POST\', \'' . preg_quote($route, '#') . '\'.{0,200}?\$requireAdmin#s',
        $cpSrc
    );
    T::true($ok, "写操作 {$route} 要求管理员权限");
}

$cpEntry = file_get_contents(__DIR__ . '/../../wwwroot/cp/api.php');
T::true((bool)preg_match('/catch\s*\(\s*\\\\?PDOException/', $cpEntry),
    'CP 入口捕获 PDOException');

// CP 不得直接查 POS 主库 —— 后台的数据一律来自本地镜像
T::false((bool)preg_match('/posReader\(\)|history_order_head|history_order_detail/', $cpSrc),
    '★ CP 后台不直接查 POS 主库（数据一律来自本地镜像，避免后台操作拖垮 POS）');

$initSrc = file_get_contents(__DIR__ . '/../../bin/init.php');
T::true((bool)preg_match('/PHP_SAPI\s*!==\s*.cli./', $initSrc), 'init.php 拒绝从网络访问');
T::true((bool)preg_match('/拒绝执行/', $initSrc), 'migrate 有数据安全闸门');

$cronSrc = file_get_contents(__DIR__ . '/../../bin/cron.php');
T::true((bool)preg_match('/PHP_SAPI\s*!==\s*.cli./', $cronSrc), 'cron.php 拒绝从网络访问');
T::true((bool)preg_match('/flock\(/', $cronSrc), 'cron 有并发锁');
