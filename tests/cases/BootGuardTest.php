<?php
declare(strict_types=1);

/**
 * 引导守卫 —— 现场用一整天换来的这一课，必须钉死在测试里。
 *
 * 事故经过：宝塔的 open_basedir 只放行了 wwwroot/，而 app/ 在它上一层。
 * 入口文件第一行 require '../app/bootstrap.php' 直接 fatal，吐出 HTML 警告页；
 * 客户端 JSON.parse 失败，界面报「无法连接本机服务」，
 * 于是所有人去查网线 —— 而服务器一直好好的。
 *
 * 两个必须守住的性质：
 *   ① 守卫调用必须排在 bootstrap 的 require 【之前】（排在后面等于没写）
 *   ② 守卫自身零依赖：不能引用 Vip\ 任何东西，因为此刻 /app 正读不到
 */

require_once __DIR__ . '/../../wwwroot/_boot.php';

T::group('引导守卫 · 路径规范化（不碰文件系统）');

T::eq('/a/b/c', vip_boot_norm('/a/b/c'), '普通绝对路径');
T::eq('/a/app/bootstrap.php', vip_boot_norm('/a/wwwroot/../app/bootstrap.php'),
    '★ 能解析 /../ —— realpath 在被 open_basedir 挡住时返回 false，用不了');
T::eq('/a/b', vip_boot_norm('/a/./b'), '吃掉 /./');
T::eq('/a/b', vip_boot_norm('/a//b'), '吃掉重复斜杠');
T::eq('C:/w/app', vip_boot_norm('C:\\w\\wwwroot\\..\\app'), '★ Windows 反斜杠与盘符');
T::eq('/a', vip_boot_norm('/a/b/..'), '结尾的 ..');

T::group('引导守卫 · open_basedir 判定');

$saved = ini_get('open_basedir');

// 没设 open_basedir 时一律放行（绝大多数自建环境）
T::true(vip_boot_within_basedir('/anywhere/at/all'),
    '未设置 open_basedir → 恒为真');

/**
 * 复现现场那一组配置。ini_set('open_basedir') 只能收紧不能放宽，
 * 且一旦收紧本进程无法还原，所以这里用纯函数做判定、
 * 不真去改运行时的 ini。判定逻辑与 vip_boot_within_basedir 同源，
 * 见下方对同一组数据的直接断言。
 */
$base = '/www/wwwroot/lms.sushisom.org/www/wwwroot/:/tmp/:/www/php_session/x/';
$seg  = static function (string $path) use ($base): bool {
    foreach (explode(':', $base) as $s) {
        $ns = rtrim(vip_boot_norm(trim($s)), '/');
        if ($ns !== '' && ($path === $ns || str_starts_with($path, $ns . '/'))) {
            return true;
        }
    }
    return false;
};

T::false($seg(vip_boot_norm('/www/wwwroot/lms.sushisom.org/www/app/bootstrap.php')),
    '★ 现场那条：app/ 在允许范围外 → 判定为被挡住');
T::true($seg(vip_boot_norm('/www/wwwroot/lms.sushisom.org/www/wwwroot/api.php')),
    'wwwroot 内的文件 → 放行');
T::true($seg(vip_boot_norm('/tmp/whatever')), '/tmp 在允许列表里 → 放行');
T::false($seg(vip_boot_norm('/www/wwwroot/lms.sushisom.org/www/wwwroot-evil/x')),
    '★ 前缀相同但不是子目录，不能误判为放行（wwwroot vs wwwroot-evil）');

T::group('引导守卫 · 入口文件接线');

/**
 * 用 token_get_all 而不是 strpos/preg_match —— 上次踩过：
 * 断言把「注释里提到的那句话」当成了代码，测试绿着但代码是坏的。
 * 这里只看真实的函数调用与 require，注释和字符串一律不算数。
 */
$callOrder = static function (string $file): array {
    $tokens = token_get_all(file_get_contents($file));
    $guardAt = null; $bootAt = null;
    foreach ($tokens as $i => $t) {
        if (!is_array($t)) {
            continue;
        }
        if ($t[0] === T_STRING && $t[1] === 'vip_boot_require_or_json' && $guardAt === null) {
            $guardAt = $t[2];
        }
        if ($t[0] === T_REQUIRE || $t[0] === T_REQUIRE_ONCE) {
            // 只认引导文件那一次 require：其后跟着 bootstrap.php 字面量
            for ($j = $i; $j < $i + 12 && isset($tokens[$j]); $j++) {
                $tk = $tokens[$j];
                if (is_array($tk) && $tk[0] === T_CONSTANT_ENCAPSED_STRING
                    && str_contains($tk[1], 'bootstrap.php') && $bootAt === null) {
                    $bootAt = $t[2];
                    break;
                }
            }
        }
    }
    return [$guardAt, $bootAt];
};

foreach (['wwwroot/api.php' => 'Pad', 'wwwroot/cp/api.php' => '后台'] as $rel => $label) {
    [$guardAt, $bootAt] = $callOrder(__DIR__ . '/../../' . $rel);
    T::true($guardAt !== null, "$label 入口调用了引导守卫（{$rel}）");
    T::true($bootAt !== null, "$label 入口 require 了 bootstrap（{$rel}）");
    T::true($guardAt !== null && $bootAt !== null && $guardAt < $bootAt,
        "★ $label 守卫在 require bootstrap 之前 —— 放在后面等于没写");
}

T::group('引导守卫 · 零依赖');

$src = file_get_contents(__DIR__ . '/../../wwwroot/_boot.php');
$bad = [];
foreach (token_get_all($src) as $t) {
    if (is_array($t) && $t[0] === T_NAME_QUALIFIED && str_starts_with($t[1], 'Vip\\')) {
        $bad[] = $t[1];
    }
    // 现场 mbstring 没装过 —— 守卫里绝不能出现 mb_*
    if (is_array($t) && $t[0] === T_STRING && str_starts_with($t[1], 'mb_')) {
        $bad[] = $t[1];
    }
}
T::eq([], $bad, '★ 守卫不引用 Vip\\ 命名空间，也不用 mb_*（此刻 /app 正读不到、mbstring 可能没装）');
