<?php
declare(strict_types=1);

/**
 * 缓存与版本 —— 专治「代码传上去了，Pad 上还是旧页面」。
 *
 * 现场症状：点了半天「刷新」按钮，跑的还是老代码。原因是 WebView
 * 认为自己已经有 pad.js 了，压根不去取。而 Pad 上没有地址栏、
 * 没有开发者工具，清缓存要进系统设置 —— 现场做不到。
 *
 * 解法是页面改由 PHP 发：文档 no-store，资源 URL 带 mtime 版本号。
 * 这几条断言守的就是「别哪天又改回静态文件」。
 */

$root = __DIR__ . '/../../wwwroot/';

T::group('缓存 · 页面必须由 PHP 发');

foreach (['index.php', 'cp/index.php'] as $rel) {
    T::true(is_file($root . $rel), "$rel 存在");
}
// 旧的静态页必须删掉：多数 Web 服务器的 index 顺序里 index.html 在前，
// 留着它就等于 index.php 白写了 —— 而且症状和以前一模一样，极难发现
foreach (['index.html', 'cp/index.html'] as $rel) {
    T::false(is_file($root . $rel),
        "★ $rel 已删除（留着的话服务器多半仍会优先发它，等于这套机制没生效）");
}

T::group('缓存 · 文档本身不缓存');

foreach (['index.php', 'cp/index.php'] as $rel) {
    $src = file_get_contents($root . $rel);
    T::true(str_contains($src, 'vip_no_store()'),
        "$rel 发了 no-store（文档被缓存住的话，里面写的还是旧版本号，资源再带版本也没用）");
    T::true(str_contains($src, "require __DIR__") && str_contains($src, '_assets.php'),
        "$rel 引入了 _assets.php");
}

T::group('缓存 · 每个资源引用都带版本号');

/**
 * 只要有一个漏了，那个文件就永远是旧的 —— 而且因为其它文件更新了，
 * 表现是「新旧代码混着跑」，比整体不更新更难查。
 */
foreach (['index.php', 'cp/index.php'] as $rel) {
    $src = file_get_contents($root . $rel);
    // 页面里所有 <script src> 与 <link href>
    preg_match_all('/<(?:script[^>]+src|link[^>]+href)="([^"]+)"/', $src, $m);
    $bare = [];
    foreach ($m[1] as $u) {
        if (str_starts_with($u, 'http')) { continue; }           // 外链不管（本项目也没有）
        if (str_contains($u, 'vip_asset(')) { continue; }        // 带版本号的正确写法
        $bare[] = $u;
    }
    T::true($bare === [], "★ $rel 里没有裸的资源引用"
        . ($bare ? '（漏了：' . implode(', ', $bare) . '）' : ''));
}

T::group('缓存 · 版本号两处同源');

/**
 * 页面把版本号写进 window.APP_VERSION，/health 也报一个。
 * 前端靠两者比对判断「手里这份是不是旧的」，所以取值口径必须一致 ——
 * 用的文件清单不同的话，会得到「永远不一致 → 无限刷新」这种最坏情况。
 */
$page   = file_get_contents($root . 'index.php');
$routes = file_get_contents(__DIR__ . '/../../app/api/routes.php');

preg_match('/vip_app_version\(\[(.*?)\]\)/s', $page, $mp);
preg_match('/vip_app_version\(\[(.*?)\]\)/s', $routes, $mr);
T::true(isset($mp[1]) && isset($mr[1]), '两边都调了 vip_app_version');

$norm = static function (string $raw): array {
    preg_match_all("/'([^']+)'/", $raw, $mm);
    $l = $mm[1];
    sort($l);
    return $l;
};
$listPage   = isset($mp[1]) ? $norm($mp[1]) : [];
$listRoutes = isset($mr[1]) ? $norm($mr[1]) : [];
T::eq($listPage, $listRoutes,
    '★ 页面与 /health 用的是同一份文件清单（不一致 = 版本号永远对不上 = 无限刷新）');
T::true(count($listPage) >= 5, '清单覆盖了全部前端资源（' . count($listPage) . ' 个）');

T::group('缓存 · 前端拿版本号做什么');

$padJs = file_get_contents($root . 'assets/pad.js');
T::true(str_contains($padJs, 'APP_VERSION'), 'pad.js 读了 window.APP_VERSION');
T::true(str_contains($padJs, 'applyUpdateIfIdle'), '版本不一致时会自己刷新');
/**
 * 🔴 最要紧的一条：绝不能在收银员干活干到一半时刷新。
 * 已经填好的金额、选好的会员会当场清空，比看到旧界面严重得多。
 */
T::true(str_contains($padJs, 'const busy =') && str_contains($padJs, 'pendingUpdate'),
    '★ 忙的时候先记下来，等空了再刷 —— 不能把收银员填了一半的东西冲掉');

T::group('缓存 · /health 在库连不上时也要能答话');

/**
 * 真栽过：为了给登录页提供 default_lang，在 /health 里读了一次配置，
 * 结果库一停这个接口直接 500 —— 而它的全部职责就是「告诉前端库连不上」，
 * 登录页那句「本地数据库连接异常，请联系管理员」再也不出现。
 *
 * 同理，api.php 在派发【之前】解析语言，那里读配置也会把整个 API 打死。
 */
$health = $routes;
preg_match('/\$api->on\(\x27GET\x27, \x27\/health\x27.*?\n\}\);/s', $health, $mh);
T::true(isset($mh[0]), '找得到 /health 的实现');
if (isset($mh[0])) {
    T::true(str_contains($mh[0], 'if ($localOk)') || str_contains($mh[0], 'catch'),
        '★ /health 读配置时有容错，库连不上不会把它自己带崩');
}

$apiEntry = file_get_contents($root . 'api.php');
T::true(str_contains($apiEntry, 'catch (\Throwable)') || str_contains($apiEntry, 'catch (\\Throwable'),
    '★ api.php 派发前解析语言时有容错（否则库一停，连 /health 都 500）');
