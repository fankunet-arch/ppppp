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

/**
 * ★ 路由里用到的守卫闭包，必须在【同一个文件】里定义过。
 * 实测踩过：CP 的 /auth/change-pin 写成了 use ($requireOperator)，
 * 而 cp/routes.php 只定义了 requireManager / requireAdmin ——
 * PHP 只报一条 Warning，然后调用 null 抛异常，
 * 接口直接 500，而单测因为不实际发请求完全发现不了。
 */
foreach ([
    'app/api/routes.php' => $routesSrc,
    'app/cp/routes.php'  => file_get_contents(__DIR__ . '/../../app/cp/routes.php'),
] as $file => $src) {
    preg_match_all('/^\$(require\w+)\s*=/m', $src, $defM);
    $defined = array_flip($defM[1]);
    preg_match_all('/\$(require\w+)/', $src, $useM);
    foreach (array_unique($useM[1]) as $used) {
        T::true(isset($defined[$used]),
            basename($file) . " 里用到的 \${$used} 在本文件有定义");
    }
}

// ── 按小票号查单（docs/01 §2.9）────────────────────────────
T::true(str_contains($routesSrc, "'/order/locate-invoice'"), '注册了按小票号查单的接口');
T::true((bool)preg_match('/locate-invoice.*?\$requireOperator\(\)/s', $routesSrc),
    '★ 按小票号查单同样要求登录（不能因为是新接口就漏掉鉴权）');
T::true((bool)preg_match("/preg_replace\('\/\\\\D\+\/'/", $routesSrc),
    '小票号只取数字（小票印的是 000092521 这种零填充）');

$posSrcIface = file_get_contents(__DIR__ . '/../../app/lib/PosSource.php');
T::true(str_contains($posSrcIface, 'findByInvoice'), 'PosSource 契约里有 findByInvoice');
$readerSrc = file_get_contents(__DIR__ . '/../../app/lib/PosReader.php');
T::true((bool)preg_match('/findByInvoice.*?WHERE order_head_id = \?/s', $readerSrc),
    '★ 按 order_head_id 单点查（命中 idx_headcheck，是最省 POS 的查法）');
T::false((bool)preg_match('/findByInvoice.*?eat_type\s*=/s', $readerSrc),
    '★ 按小票号查【不过滤 eat_type】—— 外带单也要查得出来，'
  . '再由 checkEligible 提示「外带不积分」，而不是让人以为号输错了');
$fakeSrc = file_get_contents(__DIR__ . '/../../tests/FakePosSource.php');
T::true(str_contains($fakeSrc, 'findByInvoice'), 'FakePosSource 实现了 findByInvoice（否则冒烟测试无法注入）');

$entrySrc = file_get_contents(__DIR__ . '/../../wwwroot/api.php');
/**
 * 守的是【行为】不是写法：路由注册阶段抛异常时也必须产出 JSON，
 * 且本地库不可达要给 db_unavailable 而非空响应体。
 * 现在统一走 Api::bootFail()，由它区分 PDOException 并带上错误代码。
 */
T::true((bool)preg_match('/catch\s*\(\s*\\\\?Throwable/', $entrySrc),
    '入口顶层兜底捕获（否则客户端只收到空响应体，无从排障）');
T::true(str_contains($entrySrc, 'bootFail'),
    '入口走 Api::bootFail —— 带分类码与事件号，且本地库不可达仍给 db_unavailable');
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

// ── PIN 可改可重置（账号生命周期不能只进不出）──────────────
// 早先只有 create 和 toggle，文档写「忘记只能重建账号」,而这条路走不通：
// (store_code, login_name) 唯一索引挡住同名重建，audit_log 按 operator_id
// 引用会变孤儿，唯一的管理员忘了 PIN 就彻底锁死。
T::true(str_contains($authSrc, 'function changePin'), 'AuthService 支持自助改 PIN');
T::true(str_contains($authSrc, 'function resetPin'),  'AuthService 支持管理员重置 PIN');
T::true((bool)preg_match('/changePin.*?password_verify\\(\\$oldPin/s', $authSrc),
    '★ 改自己的 PIN 必须验旧 PIN（否则拿到会话就能改密码）');
T::true((bool)preg_match('/resetPin.*?writePin\\(\\$operatorId, \\$newPin, true\\)/s', $authSrc),
    '★ 管理员重置时一并解除锁定（忘记 PIN 的人通常已试错到被锁）');
T::true((bool)preg_match('/function revokeSessions/', $authSrc),
    '★ 改/重置 PIN 后作废会话（PIN 疑似泄露时能把人踢下线）');
T::true(str_contains($authSrc, 'MIN_PIN = 6'), 'PIN 最短 6 位');

$initSrc = file_get_contents(__DIR__ . '/../../bin/init.php');
T::true(str_contains($initSrc, "case 'passwd'"),
    '★ CLI 有 passwd 逃生口（唯一管理员锁死时的唯一恢复路径）');
T::true((bool)preg_match('/doPasswd.*?resetPin\\(/s', $initSrc), 'passwd 走的是同一套 resetPin');

$cpSrcPin  = file_get_contents(__DIR__ . '/../../app/cp/routes.php');
$padSrcPin = file_get_contents(__DIR__ . '/../../app/api/routes.php');
T::true(str_contains($cpSrcPin, "'/operators/reset-pin'"), 'CP 有重置 PIN 接口');
T::true((bool)preg_match('/reset-pin.*?requireAdmin\\(\\)/s', $cpSrcPin), '★ 重置他人 PIN 限管理员');
T::true(str_contains($cpSrcPin,  "'/auth/change-pin'"), 'CP 有自助改 PIN 接口');
T::true(str_contains($padSrcPin, "'/auth/change-pin'"), 'Pad 有自助改 PIN 接口（收银员也能改）');
T::true((bool)preg_match('/change-pin.*?requireOperator\\(\\)/s', $padSrcPin), '自助改 PIN 要求已登录');

T::group('后台配置 —— 每个配置项都必须在后台露出来');

/**
 * 之前后台把 sys_config 当平铺 key-value 列出来，店家找不到
 * 「几送一」「免费餐额外消费算不算」这些开关在哪；而且有三个配置项
 * （free_meal_extra_earns / points_include_tax / pii_retention_years）
 * 建了却从没被代码读过，是死配置 —— 界面上能改，改了没有任何效果。
 * 这两类问题都靠下面的断言守住。
 */
require_once __DIR__ . '/../../app/lib/ConfigSchema.php';

$seedSql = (string)file_get_contents(__DIR__ . '/../../db/seeds/001_sys_config.sql');
preg_match_all("/\(@store,'([a-z0-9_]+)'/", $seedSql, $sm);
$seeded = array_unique($sm[1]);
T::true(count($seeded) > 20, '种子里有 ' . count($seeded) . ' 个配置项');

$schema = array_keys(\Vip\ConfigSchema::ITEMS);

// ① 种子里的每一项都要在 schema 里登记，否则后台没有标签与说明
foreach ($seeded as $k) {
    T::true(in_array($k, $schema, true), "配置项 {$k} 已在 ConfigSchema 登记（否则后台显示为未归类）");
}

// ② schema 里的每一项都要真的被代码读取，不能是死配置
$appSrc = '';
foreach ([__DIR__ . '/../../app', __DIR__ . '/../../bin'] as $dir) {
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $fi) {
        if ($fi->isFile() && $fi->getExtension() === 'php'
            && $fi->getFilename() !== 'ConfigSchema.php') {
            $appSrc .= (string)file_get_contents($fi->getPathname());
        }
    }
}
foreach ($schema as $k) {
    T::true(str_contains($appSrc, "'{$k}'"),
        "★ 配置项 {$k} 确实被代码读取（不是改了没效果的死配置）");
}

// ③ 每项都要有分组、标签、说明
foreach (\Vip\ConfigSchema::ITEMS as $k => $meta) {
    T::true(isset(\Vip\ConfigSchema::GROUPS[$meta['group']]), "{$k} 的分组有效");
    T::true(($meta['label'] ?? '') !== '' && ($meta['desc'] ?? '') !== '',
        "{$k} 有中文标签与说明");
    if ($meta['type'] === 'select') {
        T::true(!empty($meta['options']), "{$k} 是下拉框，选项不为空");
    }
}

// ④ 类型校验真的管用
T::eq(null, \Vip\ConfigSchema::validate('reward_threshold_visits', '10'), '合法整数通过');
T::true(\Vip\ConfigSchema::validate('reward_threshold_visits', '-1') !== null, '负数被拒');
T::true(\Vip\ConfigSchema::validate('reward_threshold_visits', '很多') !== null, '文字被拒');
T::true(\Vip\ConfigSchema::validate('reward_enabled', '2') !== null, '开关只能 0/1');
T::true(\Vip\ConfigSchema::validate('reward_mode', 'whatever') !== null, '下拉框只能选已有项');
T::eq(null, \Vip\ConfigSchema::validate('reward_mode', 'amount'), '合法选项通过');
T::eq(null, \Vip\ConfigSchema::validate('business_day_cutoff', '02:00'), '时间格式通过');
T::true(\Vip\ConfigSchema::validate('business_day_cutoff', '25:00') !== null, '非法时间被拒');

T::group('配置 · 两个门槛由「门槛口径」决定哪个能改');

/**
 * 后台里「几次送一次」和「累计消费多少送一次」两格并排，而实际只有一格
 * 在起作用 —— 起哪一格由「门槛口径」定。两格都能填的话，店家改了不起作用
 * 的那一格，会以为规则变了而其实没变，等客人来问才发现。
 *
 * ★ 置灰【不影响存储】：值照旧在库里，口径切回去原样还在。
 *   这里连带钉住这一点 —— 免得以后有人「顺手」在切口径时把另一格清空。
 */
$byVisits = ['reward_mode' => 'visits'];
$byAmount = ['reward_mode' => 'amount'];
$visitsItem = \Vip\ConfigSchema::ITEMS['reward_threshold_visits'];
$amountItem = \Vip\ConfigSchema::ITEMS['reward_threshold_amount'];

T::true(\Vip\ConfigSchema::isActive($visitsItem, $byVisits),  '★ 按次数时「几次送一次」可改');
T::true(!\Vip\ConfigSchema::isActive($amountItem, $byVisits), '★★ 按次数时「累计消费多少送一次」置灰');
T::true(\Vip\ConfigSchema::isActive($amountItem, $byAmount),  '★ 按金额时「累计消费多少送一次」可改');
T::true(!\Vip\ConfigSchema::isActive($visitsItem, $byAmount), '★★ 按金额时「几次送一次」置灰');

// 没声明依赖的项一律可改 —— 别让这套机制误伤其他配置
T::true(\Vip\ConfigSchema::isActive(\Vip\ConfigSchema::ITEMS['reward_enabled'], $byVisits),
    '没声明依赖的项不受影响');

/**
 * ★★ 老库里根本没有 reward_mode 这一项时，两格都要放行。
 *   按「不等于就置灰」判的话两格【同时】锁死，管理员一格都改不了 ——
 *   而唯一的解法（改口径）本身就在这个页面上，等于把自己关在门外。
 */
T::true(\Vip\ConfigSchema::isActive($visitsItem, []),
    '★★ 库里没有 reward_mode 时「几次送一次」仍可改');
T::true(\Vip\ConfigSchema::isActive($amountItem, []),
    '★★ 「累计消费多少送一次」也可改 —— 两格同时锁死等于把管理员关在门外');
T::true(!\Vip\ConfigSchema::isActive($amountItem, ['reward_mode' => '']),
    '  └ 但有这一项、值是空串时照常判（那是数据脏，不是没有）');

// 置灰要给理由，否则看着就是坏了
$hint = \Vip\ConfigSchema::inactiveHint($amountItem);
T::true(is_string($hint) && str_contains($hint, '门槛口径'),
    "★★ 置灰时说清要先改哪一项：「{$hint}」");
T::true(str_contains((string)$hint, '按金额'),
    '  └ 并且点名要改成哪个选项（照抄下拉框里的原话，不另造词）');
T::eq(null, \Vip\ConfigSchema::inactiveHint(\Vip\ConfigSchema::ITEMS['reward_enabled']),
    '没依赖的项没有这句话');

// grouped() 要把结论一并带给前端，否则后台还得自己算一遍
$groups = \Vip\ConfigSchema::grouped(['reward_mode' => 'visits',
                                       'reward_threshold_visits' => '10',
                                       'reward_threshold_amount' => '300.00']);
$found = null;
foreach ($groups as $g) {
    foreach ($g['items'] as $it) {
        if ($it['key'] === 'reward_threshold_amount') { $found = $it; }
    }
}
T::true($found !== null, 'grouped() 里找得到这一项');
T::true(($found['active'] ?? null) === false, '★ grouped() 直接给出 active=false');
T::eq('300.00', $found['value'] ?? null,
    '★★ 置灰的项【值照样带出来】—— 置灰只是不让改，不是清空');

// ⑤ 用户点名要的三项必须在
foreach (['reward_mode' => '按次还是按金额',
          'reward_threshold_visits' => '几送一',
          'free_meal_extra_earns' => '免费餐额外消费是否计入'] as $k => $what) {
    T::true(in_array($k, $schema, true), "★ 「{$what}」在后台可设（{$k}）");
}

T::group('券的有效期写在券上，不随规则变动');

/**
 * 硬性约定：客人拿到手的券，到期日不该再变。
 * 发券当刻按当时的 coupon_valid_days 算出 valid_to 存进那一行；
 * 之后店家把规则从 180 天改成 90 天，已发的券一律不受影响。
 * 真库行为由 tests/smoke.php 验证，这里守住实现方式不被「优化」掉。
 */
$rwSrc = (string)file_get_contents(__DIR__ . '/../../app/lib/Service/RewardService.php');
T::true((bool)preg_match('/private function issue\(.*?\$validDays.*?\)/s', $rwSrc),
    '发券时把有效期天数当参数传进去（而不是在里面读配置）');
T::true((bool)preg_match('/INSERT INTO coupon.*?valid_to/s', $rwSrc),
    '★ valid_to 在发券时就写进 coupon 行');
T::true((bool)preg_match('/expireStale.*?valid_to IS NOT NULL AND valid_to <\s*\?/s', $rwSrc),
    '★ 过期判定读券上的 valid_to，不读当前配置');
T::false((bool)preg_match('/expireStale.*?coupon_valid_days/s', $rwSrc),
    '★ 过期判定里没有出现 coupon_valid_days（否则改规则会波及老券）');
T::false((bool)preg_match("/UPDATE coupon SET[^;]*valid_to\s*=/i", $rwSrc),
    '★ 没有任何地方批量改写已发券的 valid_to');

T::group('跨平台 —— Windows 与 Linux 都要能跑');

/**
 * 运行环境会在 Windows / Linux 之间切换，任何一边的专属调用都不能裸用。
 * 实测踩过：diag.php 用 posix_geteuid()（Windows 没有这套扩展 → 直接崩），
 * init.php 用 stty 隐藏 PIN 输入（Windows 没有这个命令）。
 */
$phpFiles = [];
foreach (['app', 'bin'] as $dir) {
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(__DIR__ . '/../../' . $dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $fi) {
        if ($fi->isFile() && $fi->getExtension() === 'php') { $phpFiles[] = $fi->getPathname(); }
    }
}
T::true(count($phpFiles) > 20, '扫到 ' . count($phpFiles) . ' 个 PHP 文件');

foreach ($phpFiles as $f) {
    $src  = (string)file_get_contents($f);
    $name = basename($f);
    // posix_* 必须有 function_exists 保护
    if (preg_match('/(?<![\w>])posix_\w+\s*\(/', $src)) {
        T::true(str_contains($src, "function_exists('posix_"),
            "{$name} 用了 posix_*，有 function_exists 保护（Windows 无此扩展）");
    }
    // 调外部命令必须先判平台
    if (preg_match("/(?<![\w>])(system|exec|shell_exec|passthru)\s*\(\s*['\"]/", $src)) {
        T::true(str_contains($src, 'PHP_OS_FAMILY'),
            "{$name} 调了外部命令，有 PHP_OS_FAMILY 平台判断");
    }
    // 不得硬编码盘符或 Unix 绝对路径
    T::false((bool)preg_match('/[\'\"][A-Za-z]:\\\\/', $src), "{$name} 没有硬编码 Windows 盘符");
    T::false((bool)preg_match('#[\'\"](/tmp|/var/log|/etc)/#', $src), "{$name} 没有硬编码 Unix 绝对路径");
}

// 目录分隔符：拼路径前要把两种分隔符都剥掉
// （不要用 [^)]* 去框参数 —— rtrim(sys_get_temp_dir(), ...) 内层就有括号，
//   会在那里截断，这条断言本身之前就是这么写错的）
foreach (['app/bootstrap.php', 'bin/cron.php', 'bin/diag.php'] as $rel) {
    $src = (string)file_get_contents(__DIR__ . '/../../' . $rel);
    if (!str_contains($src, 'rtrim')) {
        continue;
    }
    // 允许 "/\\" 或 '/\\' 两种写法
    T::true(str_contains($src, '"/\\\\"') || str_contains($src, "'/\\\\'"),
        basename($rel) . ' 剥路径分隔符时两种都剥（Windows 路径以 \\ 结尾）');
    // 反过来：不许再出现只剥正斜杠的写法
    T::false((bool)preg_match('/rtrim\s*\(.*,\s*[\'"]\/[\'"]\s*\)/', $src),
        basename($rel) . ' 没有只剥正斜杠的 rtrim 残留');
}

// PSR-4：类名/命名空间必须与路径大小写严格一致
// Windows 文件系统不区分大小写，Linux 区分 —— 在 Windows 上开发好好的，
// 拷到 Linux 就 class not found
$libDir = __DIR__ . '/../../app/lib';
$itLib = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($libDir, FilesystemIterator::SKIP_DOTS));
$nCls = 0; $badCase = [];
foreach ($itLib as $fi) {
    if (!$fi->isFile() || $fi->getExtension() !== 'php') { continue; }
    $src = (string)file_get_contents($fi->getPathname());
    if (!preg_match('/^\s*(?:final\s+|abstract\s+)*(?:class|interface|trait)\s+(\w+)/m', $src, $m)) {
        continue;
    }
    $nCls++;
    if ($m[1] !== $fi->getBasename('.php')) {
        $badCase[] = $fi->getBasename() . ' 里是 ' . $m[1];
    }
    // 命名空间要对应目录
    /**
     * ★ 这里必须写四个反斜杠。
     *   单引号串里 '\\' 只是【一个】反斜杠，正则会变成 ([\w\]+) ——
     *   \] 把右括号转义掉，字符类永不闭合，preg_match 直接编译失败返回 false。
     *   于是这段命名空间检查一直在空跑，谁都没发现（Linux 上 display_errors
     *   关着，警告都看不到；在 Windows 上跑才把它暴露出来）。
     */
    if (preg_match('/^namespace\s+([\w\\\\]+);/m', $src, $nm)) {
        $relDir = trim(str_replace([$libDir, '\\'], ['', '/'], $fi->getPath()), '/');
        $expect = 'Vip' . ($relDir !== '' ? '\\' . str_replace('/', '\\', $relDir) : '');
        if ($nm[1] !== $expect) { $badCase[] = $fi->getBasename() . " namespace={$nm[1]} 期望={$expect}"; }
    }
}
T::eq([], $badCase, "★ {$nCls} 个类的类名/命名空间与路径大小写完全一致（Linux 区分大小写）");

T::group('前端 —— hidden 属性不得被样式压过');

/**
 * 浏览器内置的 [hidden]{display:none} 属于 UA 样式表，
 * 优先级低于任何作者规则。于是 `.modal{display:flex}` 会让
 * <div class="modal" hidden> 照样显示 —— 实测会员选择弹层常驻最上层，
 * 连登录页都被盖住，而所有 API 测试都发现不了（它们不渲染页面）。
 */
foreach ([
    'wwwroot/assets/pad.css' => 'wwwroot/index.php',
    'wwwroot/cp/cp.css'      => 'wwwroot/cp/index.php',
] as $cssPath => $htmlPath) {
    $css  = (string)file_get_contents(__DIR__ . '/../../' . $cssPath);
    $name = basename($cssPath);
    T::true((bool)preg_match('/\[hidden\]\s*\{[^}]*display:\s*none\s*!important/i', $css),
        "{$name} 有 [hidden]{display:none!important} 全局兜底");

    // 再逐个查：HTML 里带 hidden 的元素，其类名不得在 CSS 里被赋予 display
    $html = (string)file_get_contents(__DIR__ . '/../../' . $htmlPath);
    preg_match_all('/<[^>]*class="([^"]+)"[^>]*\shidden[\s>]/i', $html, $m);
    $classes = [];
    foreach ($m[1] as $cl) {
        foreach (preg_split('/\s+/', trim($cl)) as $one) {
            if ($one !== '') { $classes[$one] = true; }
        }
    }
    foreach (array_keys($classes) as $cl) {
        if (preg_match('/\.' . preg_quote($cl, '/') . '\s*\{([^}]*)\}/', $css, $mm)
            && preg_match('/display:\s*(?!none)/i', $mm[1])) {
            // 有兜底规则时不算失败，只是提醒它确实在起作用
            T::true((bool)preg_match('/\[hidden\][^{]*\{[^}]*!important/i', $css),
                "{$name}: .{$cl} 带 display，靠 [hidden] 兜底才不会误显示");
        }
    }
}

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
T::true((bool)preg_match('/catch\s*\(\s*\\\\?Throwable/', $cpEntry), 'CP 入口顶层兜底捕获');
T::true(str_contains($cpEntry, 'bootFail'), 'CP 入口同样走 Api::bootFail');

// CP 不得直接查 POS 主库 —— 后台的数据一律来自本地镜像
T::false((bool)preg_match('/posReader\(\)|history_order_head|history_order_detail/', $cpSrc),
    '★ CP 后台不直接查 POS 主库（数据一律来自本地镜像，避免后台操作拖垮 POS）');

$initSrc = file_get_contents(__DIR__ . '/../../bin/init.php');
T::true((bool)preg_match('/PHP_SAPI\s*!==\s*.cli./', $initSrc), 'init.php 拒绝从网络访问');
T::true((bool)preg_match('/拒绝执行/', $initSrc), 'migrate 有数据安全闸门');

// ── 回归：排序规则必须钉死（db/README.md §2.4）────────────────────
// DSN 的 charset 只设字符集；排序规则回落到服务器默认时，
// 「列 = @用户变量」两侧同为 IMPLICIT 却规则不同 → 1267 非法混用，
// 实测会让 seed 在任何原厂服务器上都跑不通。
T::group('排序规则钉死 —— 防止 1267 非法混用');
foreach (array_merge(
    glob(__DIR__ . '/../../db/migrations/*.sql') ?: [],
    glob(__DIR__ . '/../../db/seeds/*.sql') ?: []
) as $f) {
    $sql  = (string)file_get_contents($f);
    $name = basename($f);
    if (!preg_match('/^SET\s+NAMES\s+/mi', $sql)) {
        continue;   // 没写 SET NAMES 的文件不做要求
    }
    T::true((bool)preg_match('/SET\s+NAMES\s+utf8mb4\s+COLLATE\s+utf8mb4_unicode_ci/i', $sql),
        "{$name} 的 SET NAMES 带 COLLATE utf8mb4_unicode_ci");
}
$localDbSrc = (string)file_get_contents(__DIR__ . '/../../app/lib/LocalDb.php');
T::true((bool)preg_match('/SET\s+NAMES\s+\{?\$charset/i', $localDbSrc),
    'LocalDb 连接后显式执行 SET NAMES ... COLLATE');
T::true(str_contains($localDbSrc, 'utf8mb4_unicode_ci'),
    'LocalDb 的默认排序规则是 utf8mb4_unicode_ci');

$cronSrc = file_get_contents(__DIR__ . '/../../bin/cron.php');
T::true((bool)preg_match('/PHP_SAPI\s*!==\s*.cli./', $cronSrc), 'cron.php 拒绝从网络访问');
T::true((bool)preg_match('/flock\(/', $cronSrc), 'cron 有并发锁');

/**
 * ★ 并发锁要按【抢的是哪件事】取名，不是按【哪个任务】取名。
 *
 *   第一版每个任务拿自己任务名的锁，于是 nightly 和 incremental
 *   各拿各的 —— 而 nightly 的第一步就是 incremental。
 *   营业时段最后一轮 01:40 的增量补抓没跑完（POS 慢、积压多，
 *   上限 200 批），03:15 的 nightly 照样开跑，两个进程同时推同一条
 *   水位线：正是这把锁要防的那件事，锁本身放它过去了。
 *   而 integrity / menu-audit / compliance 三个任务当时一把锁都没有。
 */
T::false((bool)preg_match("/withLock\\(\\s*'nightly'/", $cronSrc),
    "★★ nightly 不拿一把只属于自己的锁 —— 它跑的是别人也在跑的那几件事");
T::true((bool)preg_match("/withLock\\(\\[[^\\]]*'sync'/", $cronSrc),
    "  └ nightly 把 sync 那把锁也拿上（否则和 incremental 会叠上）");
foreach (['integrity', 'menu-audit', 'compliance'] as $t) {
    T::true((bool)preg_match("/case '" . preg_quote($t, '/') . "':\s*\n\s*withLock\(/", $cronSrc),
        "  └ {$t} 也有并发锁（原来这三个一把都没有）");
}

$diagSrc = file_get_contents(__DIR__ . '/../../bin/diag.php');
T::true((bool)preg_match('/PHP_SAPI\s*!==\s*.cli./', $diagSrc), 'diag.php 拒绝从网络访问');
T::true(str_contains($diagSrc, '1045') && str_contains($diagSrc, '1044'),
    'diag 能把 SQLSTATE 翻译成具体原因（页面提示是统一兜底，看不出是哪种）');
T::true(str_contains($diagSrc, 'www-data'),
    '★ diag 提醒用 Web 用户再跑一遍（MySQL 按来源主机授权，命令行能连不代表网页能连）');

/**
 * ★ 所有 CLI 脚本一律拒绝网络访问 —— 包括 /tests 下的。
 *
 * 「反正 /tests 在文档根之外」不是理由：文档根配错是真会发生的事，
 * 守卫不能依赖部署时摆对了位置。没有守卫时，一次未认证的 GET 就会
 * 连库跑流程并把库名/主机/版本打回页面。
 *
 * （实测 PHP 8.4：被查询串填充的是 $_SERVER['argv']，全局 $argv 不会，
 *   所以 ?--fresh 当下触发不了 DROP TABLE；但把读法换成 $_SERVER['argv']
 *   就通了 —— 守卫放在读参数之前，两种情况一起挡。）
 */
foreach (['smoke.php', 'run.php', 'e2e_pos.php'] as $t) {
    $src = (string)file_get_contents(__DIR__ . '/../' . $t);
    T::true((bool)preg_match('/PHP_SAPI\s*!==\s*.cli./', $src),
        "★ tests/{$t} 拒绝从网络访问");
}
/**
 * 守卫必须在【真正读 $argv】之前 —— 放在后面等于没放。
 * 按 token 扫，不能按字符串找：注释里也会提到 $argv（本文件上面就提了），
 * 用 strpos 会把注释当成读取，测试就白写了。
 */
$smokeToks = token_get_all((string)file_get_contents(__DIR__ . '/../smoke.php'));
$sapiLine = $argvLine = 0;
foreach ($smokeToks as $tk) {
    if (!is_array($tk)) {
        continue;
    }
    if ($sapiLine === 0 && $tk[0] === T_STRING   && $tk[1] === 'PHP_SAPI') { $sapiLine = $tk[2]; }
    if ($argvLine === 0 && $tk[0] === T_VARIABLE && $tk[1] === '$argv')    { $argvLine = $tk[2]; }
}
T::true($sapiLine > 0 && $argvLine > 0 && $sapiLine < $argvLine,
    "★ smoke.php 的 CLI 守卫在读 \$argv 之前（守卫 L{$sapiLine} / 首次读取 L{$argvLine}）");

/**
 * ★ 明细读取必须能回落到活单表 order_detail。
 *
 * 实测该店 history_order_detail 明显落后于 history_order_head
 * （订单头到 2026-08-17，历史明细只到 08-13），刚结账的单因此
 * 「有头无明细」，套餐份数恒为 0 —— 桌号查与小票查都一样，
 * 因为两条路都走 buildContext() 读同一张表。
 * 去掉回落就会让最近的单永远算不出份数。
 */
$prSrc = (string)file_get_contents(__DIR__ . '/../../app/lib/PosReader.php');
T::true(str_contains($prSrc, "str_replace('FROM history_order_detail', 'FROM order_detail'"),
    '★ fetchDetail 在历史表查不到时回落读活单表 order_detail');
T::true((bool)preg_match('/if\s*\(\s*\$rows\s*!==\s*\[\]\s*\)/', $prSrc),
    '回落只在历史表【真的没有】时触发，不是每次都查两张表');
T::true(str_contains($prSrc, 'detailFallback'),
    '回落可通过 pos_detail_fallback 关掉（活单表无索引，POS 变慢时要能立刻停）');

$exCfg = (string)file_get_contents(__DIR__ . '/../../app/config/config.example.php');
T::true(str_contains($exCfg, 'pos_detail_fallback'),
    'config.example.php 里有 pos_detail_fallback 说明（否则现场不知道有这个开关）');

/**
 * ★ 假对象的明细过滤必须与 PosReader 一致 —— 否则测试会「假通过」。
 *
 * 实测踩过：PosReader 改成 (menu_item_id > 0 OR menu_item_id = -2) 之后，
 * FakePosSource 仍按 menu_item_id <= 0 一刀切，把 -2 折扣伪行全丢掉。
 * 后果不是少测一点，而是【十送一核销识别在冒烟测试里永远走不到】，
 * 连「纸质券不得被误判成核销」这种断言都会因为行被丢掉而假通过 ——
 * 比没有测试更糟：它给出的是虚假的安全感。
 */
$fakeSrc = (string)file_get_contents(__DIR__ . '/../FakePosSource.php');
T::true(str_contains($fakeSrc, 'PSEUDO_DISCOUNT'),
    '★ FakePosSource 保留 -2 折扣伪行（与 PosReader 的 SQL 过滤一致）');
$readerSrc = (string)file_get_contents(__DIR__ . '/../../app/lib/PosReader.php');
T::true(str_contains($readerSrc, 'menu_item_id = {$pseudoDiscount}'),
    'PosReader 的明细 SQL 确实保留 -2 行（假对象照的就是它）');

// ── 回归：数值参数不能直接取 $argv[2] ────────────────────────────
// 用法是 `cron.php <任务> [天数] [-v]`，直接取 $argv[2] 会把 "-v"
// 当天数，(int)"-v" = 0 → 完整性监控一天都不查却报成功（静默失效）。
T::false((bool)preg_match('/checkIntegrity\(\(int\)\(\$argv\[2\]/', $cronSrc),
    'cron 不把 $argv[2] 直接当天数（-v 会被当成 0）');
T::true((bool)preg_match('/\$a\[0\]\s*!==\s*.-./', $cronSrc),
    'cron 解析位置参数时先剔除 - 开头的选项');

/**
 * 实跑：-v 绝不能改变检查天数。
 *
 * ★ 这一段需要 app/config/config.php（cron 要连库）。没有配置文件时
 *   【必须明说跳过了】—— 原来是静默 if，新人 clone 之后跑出来是 887 项，
 *   而 README 写着 892，对不上还查不出原因；而跳掉的这几条
 *   恰恰是检查部署配置的那几条。静默跳过比跑不过更危险。
 */
$cronBin = __DIR__ . '/../../bin/cron.php';
if (!is_file(__DIR__ . '/../../app/config/config.php')) {
    T::skip(5, '缺 app/config/config.php —— cron 实跑那 5 项跳过'
              . '（复制 app/config/config.example.php 并填好数据库即可跑全）');
} else {
    $run = static function (string $args) use ($cronBin): int {
        $out = (string)shell_exec('php ' . escapeshellarg($cronBin) . ' ' . $args . ' 2>&1');
        // findings 里每条都带一个 "kind"，数它就知道到底查了没有
        return substr_count($out, '"kind"');
    };
    $plain    = $run('integrity');
    $verbose_ = $run('integrity -v');
    T::eq($plain, $verbose_, '★ 加 -v 与不加 -v 的检查结果必须一致（-v 不得被当成天数）');

    $d3  = $run('integrity 3');
    $d3v = $run('integrity 3 -v');
    $d3r = $run('integrity -v 3');
    T::eq($d3, $d3v,  '天数在 -v 之前可用');
    T::eq($d3, $d3r,  '天数在 -v 之后同样可用');
}

T::group('错误代码分类 —— 收银员能念出来、日志能对上');

/**
 * 现场只能看到一句话时，排查方向全靠这个分类码。
 * 分类粒度按【该去查哪里】定，不是按异常类名：
 *   E1xx 本地库   E2xx POS 主库   E3xx 代码/参数
 *
 * PDOException 那几档需要真实的 errorInfo，放在 tests/smoke.php 用真库验；
 * 这里覆盖能直接构造的分支。
 */
class_exists('Vip\\PosDb');   // PosUnavailable 定义在 PosDb.php 里，先触发加载

/**
 * ★ 连接类故障不能只看 SQLSTATE —— 实测端口不通 / 主机不可达 / 库不存在 /
 *   口令错，四种需要完全不同修法的故障，SQLSTATE 全是 HY000。
 *   只有驱动错误码能区分，都归一个码等于没分类。
 *   （现场 E101 那次就是这么发现的。）
 */
$pdo = static function (string $msg, ?array $info = null): PDOException {
    $e = new PDOException($msg);
    return $e;   // errorInfo 为空时，classify 会从消息里取 [nnnn]
};
T::eq('E101', \Vip\Http\Api::classify($pdo('SQLSTATE[HY000] [2002] Connection refused')),
    '★ 2002 连不上 → E101（服务没起 / 端口错 / 主机不可达）');
T::eq('E105', \Vip\Http\Api::classify($pdo("SQLSTATE[HY000] [1045] Access denied for user 'u'@'h'")),
    '★ 1045 口令错或来源主机没授权 → E105（与 E101 分开，修法完全不同）');
T::eq('E107', \Vip\Http\Api::classify($pdo("SQLSTATE[HY000] [1044] Access denied for user 'u' to database 'x'")),
    '★ 1044 库不存在或无权限 → E107');
T::eq('E108', \Vip\Http\Api::classify($pdo('SQLSTATE[HY000] [1040] Too many connections')),
    '1040 连接数打满 → E108');
T::eq('E106', \Vip\Http\Api::classify($pdo('could not find driver')),
    '★ pdo_mysql 没装 → E106（现场早先真踩过这个）');
T::true(str_contains((string)file_get_contents(__DIR__ . '/../../app/lib/Http/Api.php'),
    "preg_match('/\\[(\\d{4})\\]/'"),
    '★ errorInfo 为空时从消息里取驱动码（连接阶段的异常常常没有 errorInfo）');

T::eq('E201', \Vip\Http\Api::classify(new \Vip\PosUnavailable('POS 主库连接失败: timeout')),
    'POS 不可达 → E201');
T::eq('E203', \Vip\Http\Api::classify(new LogicException('POS 查询必须带 LIMIT（铁律 2）')),
    'POS 护栏拦截 → E203');
T::eq('E202', \Vip\Http\Api::classify(new RuntimeException('POS 查询 prepare 失败: Unknown column')),
    '★ POS prepare 失败 → E202（SQL 引用了主库上没有的列，最难猜的一种）');
T::eq('E209', \Vip\Http\Api::classify(new RuntimeException('别的运行时错误')),
    '非 POS 的 RuntimeException → E209');
T::eq('E301', \Vip\Http\Api::classify(new TypeError('must be of type string, null given')),
    '类型错误 → E301');
T::eq('E301', \Vip\Http\Api::classify(new ValueError('bad value')), '取值错误 → E301');
T::eq('E309', \Vip\Http\Api::classify(new Exception('boom')), '未归类 → E309');

/**
 * ★ PosUnavailable 继承 RuntimeException，判断顺序必须在它之前 ——
 *   顺序反了的话 POS 掉线会被归成 E202，方向指错。
 */
$apiSrc = (string)file_get_contents(__DIR__ . '/../../app/lib/Http/Api.php');
T::true(strpos($apiSrc, 'PosUnavailable') < strpos($apiSrc, 'instanceof \\RuntimeException'),
    '★ PosUnavailable 的判断在 RuntimeException 之前（它是子类，顺序反了会归错档）');
T::true(str_contains($apiSrc, '错误代码'),
    '错误代码直接拼进给收银员看的提示语（拍照就能带出来）');
T::true(str_contains($apiSrc, 'bin2hex(random_bytes(3))'),
    '每次故障有独立事件号，日志里能精确定位到那一次');
T::eq(3, substr_count($apiSrc, "instanceof \\PDOException"),
    '★ classify / dispatch / bootFail 三处都区分 PDOException（本地库不可达要给 503 db_unavailable）');
T::false(str_contains($apiSrc, 'getTraceAsString'),
    '★ 绝不把堆栈吐给客户端 —— 只给代码，细节留在服务器日志里');

T::group('隐私开关 · member_collect_pii');

/**
 * 后台开关：关闭时 Pad 上完全看不到手机号/邮箱/生日输入框，后端也拒收。
 *
 * 只藏前端是不够的 —— 字段藏起来而接口照收，面对合规检查一样说不清。
 * 两边都做，才说得出「系统在关闭状态下技术上就收不了个人信息」。
 */
$schema = \Vip\ConfigSchema::ITEMS;
T::true(isset($schema['member_collect_pii']), '配置项已登记进 ConfigSchema（后台能看到）');
T::eq('bool', $schema['member_collect_pii']['type'] ?? '', '是布尔开关');
T::eq('compliance', $schema['member_collect_pii']['group'] ?? '', '归在「合规与隐私」组');

$seed = (string)file_get_contents(__DIR__ . '/../../db/seeds/001_sys_config.sql');
T::true(str_contains($seed, "'member_collect_pii','0'"),
    '★ 种子里默认值是 0（关闭）—— 新装的店默认不收集个人信息');

$routes = (string)file_get_contents(__DIR__ . '/../../app/api/routes.php');
T::true(str_contains($routes, 'pii_disabled'),
    '★ 后端在关闭时拒收，不是只靠前端隐藏');

$pad = (string)file_get_contents(__DIR__ . '/../../wwwroot/assets/pad.js');
T::true(str_contains($pad, 'box.remove()'),
    '★ 前端是把那一栏从 DOM 移除，不是 hidden（隐藏的字段仍然存在）');

T::group('业务错误不能用会被 nginx 拦掉的状态码');

/**
 * 现场踩过：卡号不存在时应用返回 JSON + HTTP 404，而 nginx 开着
 * fastcgi_intercept_errors，配合 error_page 404，把响应体【整个换成】
 * 它自己的 404 页面。收银员看到「服务器返回的不是 JSON…404 Not Found
 * nginx」，以为系统坏了，实际只是卡号打错。
 *
 * 影响面比看上去大：order_not_found 也是 404，而输错桌号/小票号
 * 是收银员最常遇到的情况 —— 全系统最高频的错误路径全被吞掉。
 *
 * 修法放在应用层而不是靠运维配对 nginx：换台机器、换个面板，
 * 配置就可能又变回去。
 */

/**
 * 剥掉 PHP 注释，保留代码与字符串。
 *
 * ★ 不能用 SqlText —— 那是 SQL 的剥离器：它不认 PHP 的 // 注释，
 *   还会把字符串内容清空。第一版就是这么写的，结果
 *   fail('not_found', 404) 里的字符串被抹掉，断言永远匹配不上。
 */
$phpCode = static function (string $file): string {
    $out = '';
    foreach (token_get_all((string)file_get_contents($file)) as $t) {
        if (is_array($t)) {
            if (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                $out .= ' ';
                continue;
            }
            $out .= $t[1];
            continue;
        }
        $out .= $t;
    }
    return $out;
};

foreach (['app/api/routes.php', 'app/cp/routes.php'] as $rel) {
    $code = $phpCode(__DIR__ . '/../../' . $rel);
    T::false((bool)preg_match('/Api::fail\([^;]*?,\s*404\s*\)/', $code),
        "★ {$rel} 里没有业务级 404（业务「没找到」要用 Api::NOT_FOUND）");
}

T::eq(422, \Vip\Http\Api::NOT_FOUND,
    '★ 业务「没找到」用 422 —— 不在任何常见 error_page 的拦截名单里');

// 路由层自己那个 404 是对的：那确实是「这个 URL 上没东西」
T::true((bool)preg_match("/fail\('not_found',\s*404\)/",
        $phpCode(__DIR__ . '/../../app/lib/Http/Api.php')),
    '接口不存在仍然是 404（那是真的路径不存在，语义正确）');

T::group('迁移 · 重跑一遍不能卡住');

/**
 * ★ 现场事故：`php bin/init.php migrate` 停在
 *     SQLSTATE[42S21] Duplicate column name 'valid_to'
 *
 *   起因是两类迁移的对称性被打破了：
 *     · 001/002 是 DROP TABLE + CREATE —— 重跑时表被重建，列自然没了，
 *       后面对这些表的 ALTER 照样能跑（003/004/005/007 就属于这一类）
 *     · 006 的 card 表是 CREATE TABLE IF NOT EXISTS（不能 DROP，
 *       一 DROP 已发出去的实体卡就全没了）—— 重跑时表【原样保留】，
 *       于是 008 那句裸 ALTER ADD COLUMN 撞上已存在的列，1060 报错，
 *       整条迁移链停在这里，后面的 009/010 永远跑不到。
 *
 *   所以规则不是「所有 ALTER 都要幂等」，而是：
 *     对一张【不会被重建】的表做 ALTER，那句 ALTER 必须幂等。
 *
 *   MariaDB 有 ADD COLUMN IF NOT EXISTS，MySQL 8 没有 ——
 *   两种库都要跑，所以统一用 information_schema 判一下再动态执行。
 */
$migrations = glob(__DIR__ . '/../../db/migrations/*.sql') ?: [];
sort($migrations);
T::true(count($migrations) >= 10, '找得到迁移文件（' . count($migrations) . ' 个）');

/**
 * 只剥注释，【不要动反引号】。
 *
 * 不能用 SqlText::stripComments —— 它把反引号当字符串定界符，
 * 会把 `card`、`pos_order` 这些标识符一起抹成空白，
 * 于是「ALTER TABLE `pos_order` ADD …」被读成「ALTER TABLE  ADD」，
 * 表名变成了 ADD。（这条测试第一版就是这么错的。）
 */
$sqlBody = static function (string $file): string {
    $raw = (string)file_get_contents($file);
    $raw = preg_replace('#/\*.*?\*/#s', ' ', $raw) ?? $raw;      // 块注释
    $raw = preg_replace('/^\s*--.*$/m', '', $raw) ?? $raw;        // 整行的 -- 注释
    return $raw;
};

/** 每张表是在哪个迁移里被「重建」的（DROP + CREATE），没有则为 null */
$rebuiltAt = [];
/** 每张表是在哪个迁移里被「软建」的（CREATE IF NOT EXISTS —— 重跑时原样保留） */
$softAt = [];

foreach ($migrations as $idx => $f) {
    $sql = $sqlBody($f);
    preg_match_all('/DROP\s+TABLE\s+(?:IF\s+EXISTS\s+)?`?(\w+)`?/i', $sql, $d);
    preg_match_all('/CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+`?(\w+)`?/i', $sql, $c);
    foreach ($d[1] as $t) { $rebuiltAt[$t] = $idx; }
    foreach ($c[1] as $t) { if (!isset($rebuiltAt[$t])) { $softAt[$t] = $idx; } }
}

T::true($softAt !== [],
    '确实有不会被重建的表（' . implode(', ', array_keys($softAt)) . '）—— 对它们的 ALTER 才是风险点');
T::true(isset($softAt['card']), '★ card 就是其中之一（实体卡不能 DROP，一 DROP 已发的卡全没了）');

$risky = [];
foreach ($migrations as $idx => $f) {
    $sql  = $sqlBody($f);
    $name = basename($f);
    // 这个文件里对哪些表做了 ADD 类 DDL
    preg_match_all('/ALTER\s+TABLE\s+`?(\w+)`?([^;]*)/is', $sql, $m, PREG_SET_ORDER);
    foreach ($m as $one) {
        $tbl  = $one[1];
        $body = $one[2];
        if (!preg_match('/\bADD\s+(COLUMN|KEY|INDEX|UNIQUE)\b/i', $body)) { continue; }
        // 表在这一步之前会被重建 → 重跑时列本来就没了，裸 ALTER 是安全的
        if (isset($rebuiltAt[$tbl]) && $rebuiltAt[$tbl] < $idx) { continue; }
        // 否则必须幂等
        $guarded = str_contains($sql, 'information_schema')
                || (bool)preg_match('/ADD\s+COLUMN\s+IF\s+NOT\s+EXISTS/i', $sql);
        if (!$guarded) { $risky[] = "{$name} → ALTER `{$tbl}`"; }
    }
}
T::true($risky === [],
    '★★ 对「不会被重建的表」的 ALTER 都是幂等的 —— 否则重跑迁移会 1060/1061 卡死'
    . ($risky ? "\n      " . implode("\n      ", $risky) : ''));

// 008/009/010 是这次事故涉及的三个，单独点名，改坏了要立刻看得见
foreach (['008_card_valid_to.sql', '009_operator_lang.sql', '010_operator_name_es.sql'] as $n) {
    $f = __DIR__ . '/../../db/migrations/' . $n;
    T::true(is_file($f) && str_contains((string)file_get_contents($f), 'information_schema'),
        "{$n} 用 information_schema 做了存在性判定");
}

T::group('Schema 文档没有落后于迁移');

/**
 * ★★ 这条测试是补一次真实的文档腐烂。
 *
 *   写完防刷那一版之后回头看 docs/04-本地库Schema.md，发现它【落后了三个迁移】：
 *     · point_ledger 缺 grant_group / tier_code / tier_multiplier
 *     · coupon 缺 tier_code / threshold_used
 *     · pos_order 缺 original_amount / allocated_portions / is_redeemed / redeem_amount
 *     · card、card_tier、operator、alert、operator_session、schema_migration
 *       这六张表【整张都没写进去】
 *
 *   没人会主动去发现这件事 —— 文档不会报错，测试也不跑它。
 *   下一个人照着 docs/04 建表，建出来的东西跑不起来，
 *   而他会先怀疑自己而不是怀疑文档。
 *
 * 判定方式：从 db/migrations/*.sql 里抠出所有 CREATE TABLE 与 ADD COLUMN 的列名，
 * 逐个到文档里找。找不到就是文档没跟上。
 *
 * 只查【存在性】不查注释是否准确 —— 后者机器判不了，
 * 但「整列漏写」这种最常见也最伤人的情况，这一条就能全挡掉。
 */
$docPath = __DIR__ . '/../../docs/04-本地库Schema.md';
$doc     = (string)file_get_contents($docPath);
T::true($doc !== '', 'docs/04 读得到');

$migDir = __DIR__ . '/../../db/migrations';
$files  = glob($migDir . '/*.sql') ?: [];
T::true(count($files) >= 14, '找得到迁移文件（' . count($files) . ' 个）');

/** 这些不是业务表，或纯属实现细节，文档不必逐列写 */
$skipTables = [];

$declared = [];        // table => [col, ...]
foreach ($files as $f) {
    $sql = (string)file_get_contents($f);

    // CREATE TABLE 块里的列
    if (preg_match_all('/CREATE TABLE(?:\s+IF NOT EXISTS)?\s+`([a-z_]+)`\s*\((.*?)\n\)/is', $sql, $ms, PREG_SET_ORDER)) {
        foreach ($ms as $m) {
            preg_match_all('/^\s*`([a-z_]+)`\s+[A-Za-z]/m', $m[2], $cm);
            foreach ($cm[1] as $col) { $declared[$m[1]][] = $col; }
        }
    }
    // 后续迁移里 ADD COLUMN 的列（含 information_schema + PREPARE 那种拼字符串的写法）
    if (preg_match_all('/ALTER TABLE `?([a-z_]+)`? ADD COLUMN `([a-z_]+)`/i', $sql, $am, PREG_SET_ORDER)) {
        foreach ($am as $m) { $declared[$m[1]][] = $m[2]; }
    }
}
T::true(count($declared) >= 10, '从迁移里解析出 ' . count($declared) . ' 张表');

$missing = [];
foreach ($declared as $table => $cols) {
    if (in_array($table, $skipTables, true)) { continue; }
    if (!preg_match('/\b' . preg_quote($table, '/') . '\b/', $doc)) {
        $missing[] = "整张表 `{$table}` 没写";
        continue;
    }
    foreach (array_unique($cols) as $col) {
        // 必须是「`列名` 类型」这种定义形态 —— 出现在 KEY(...) 里不算
        if (!preg_match('/`' . preg_quote($col, '/') . '`\\s+[A-Z]/', $doc)) {
            $missing[] = "{$table}.{$col}";
        }
    }
}
T::true($missing === [],
    '★★ docs/04 覆盖了迁移里的每一张表、每一列'
    . ($missing
        ? "\n      文档里找不到：" . implode('、', array_slice($missing, 0, 12))
          . (count($missing) > 12 ? ' …共 ' . count($missing) . ' 处' : '')
          . "\n      —— 加了迁移就要同步更 docs/04，否则下一个人照着文档建表会建错"
        : ''));

// ════════════════════════════════════════════════════════════
T::group('★ App 自己管的那几列，同步/locate 不许碰（审计 F2 的根因）');

/**
 * ── 🔴 为什么要在这里钉一道 ────────────────────────────────
 *
 * F2 那条 P0 的根因不是算法错，是【一列被两个主人写】：
 * 「发分时的金额快照」和「主库当前值的镜像」共用了 should/actual/total
 * 三列，而后者由 buildContext（收银员每次 locate）与 storeOrder
 * （每轮同步）不停刷。于是值比对拿新值跟新值比，永远判「一致」——
 * 整条防线是空的，且账面上完全看不出异常。
 *
 * 修法是把基准拆成独立的 verify_base_* 三列。但那个修法的正确性
 * 【完全取决于这三列不出现在 upsert 的列清单里】—— 而那是一份
 * 手写的字符串数组，下一个人加列时顺手写进去，一个报错都不会有，
 * 防线又悄悄变空。
 *
 * 所以这条断言钉的是【列名】，不是方法名（docs/13 §4「两次操作之间」）。
 * 同样归 App 所有、绝不能被主库镜像覆盖的还有：已分配额、已分配份数、
 * 核对状态与核对时间。
 */
$orderRepoSrc = (string)file_get_contents(__DIR__ . '/../../app/lib/Repo/OrderRepo.php');
$upsertStart  = strpos($orderRepoSrc, 'public function upsert');
T::true($upsertStart !== false, 'OrderRepo::upsert() 找得到');

// 只看 upsert 这一个方法体（到下一个 public function 为止）
$nextFn      = strpos($orderRepoSrc, "\n    public function", (int)$upsertStart + 10);
$upsertBody  = substr($orderRepoSrc, (int)$upsertStart,
    ($nextFn === false ? strlen($orderRepoSrc) : $nextFn) - (int)$upsertStart);
// 注释里提到列名是允许的（那正是解释「为什么不能写」的地方）
$upsertCode  = preg_replace('#/\*.*?\*/#s', ' ', $upsertBody) ?? $upsertBody;
$upsertCode  = preg_replace('#^\s*(//|\*).*$#m', '', $upsertCode) ?? $upsertCode;

$appOwned = [
    'verify_base_should' => '值比对的基准（应收）—— 被刷掉就等于没有值比对',
    'verify_base_actual' => '值比对的基准（收款）—— 同上',
    'verify_base_at'     => '基准定格时刻',
    'allocated_amount'   => '已分配金额 —— 被刷成 0 等于同一单可以再分一遍',
    'allocated_portions' => '已分配份数 —— 同上',
    'verify_status'      => '核对状态 —— 被刷回 0 会让同一单反复冲正',
    'last_verified_at'   => '最近核对时刻 —— 值比对靠它排队',
];
$leaked = [];
foreach ($appOwned as $col => $why) {
    if (str_contains($upsertCode, $col)) {
        $leaked[] = "{$col}（{$why}）";
    }
}
T::true($leaked === [],
    '★★★ upsert() 的列清单里没有任何一列是 App 自己管的'
    . ($leaked
        ? "\n      混进去了：" . implode("\n      ", $leaked)
          . "\n      —— 这几列由发分/冲正两条路写，一旦被主库镜像覆盖，"
          . "\n         值比对会拿新值跟新值比，永远判「一致」（审计 F2）"
        : ''));

/** 反过来也钉一下：POS 直接给的那几列【必须】在里面，否则镜像永远是旧的 */
$mustMirror = ['should_amount', 'actual_amount', 'tax_amount', 'order_end_time', 'business_date'];
$absent = [];
foreach ($mustMirror as $col) {
    if (!str_contains($upsertCode, $col)) { $absent[] = $col; }
}
T::true($absent === [],
    '  └ 而 POS 直接给的那几列确实在里面（' . implode('、', $mustMirror) . '）'
    . ($absent ? '，缺：' . implode('、', $absent) : ''));

/** verify_base_* 只许这两个方法写 —— 再加一条按列名的全局扫描 */
$appFiles = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/../../app'));
foreach ($it as $f) {
    if ($f->isFile() && $f->getExtension() === 'php') { $appFiles[] = $f->getPathname(); }
}
$badWriters = [];
foreach ($appFiles as $f) {
    $src = (string)file_get_contents($f);
    // 找「SET ... verify_base_xxx =」这种写入形态
    if (preg_match('/\bverify_base_(?:should|actual|at)\s*=/', $src)
        && !str_ends_with($f, 'Repo/OrderRepo.php')) {
        $badWriters[] = basename($f);
    }
}
T::true($badWriters === [],
    '  └ 全仓库只有 OrderRepo 写得了 verify_base_*（其余都只读）'
    . ($badWriters ? '，越界的：' . implode('、', $badWriters) : ''));
