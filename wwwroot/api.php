<?php
declare(strict_types=1);

/**
 * API 入口 —— /wwwroot 是唯一网络可见的目录。
 *
 * 本文件只做三件事：引导、取路径、派发。
 * 全部业务逻辑在 /app（网络不可见）。
 *
 * 路由形式：/api.php/order/locate
 * 若 Web 服务器配置了重写，也可用 /api/order/locate。
 */

// ★ 这一行在顶层 try 的【外面】—— 读不到 /app 就会 fatal 成 HTML 页，
//   客户端解析不出 JSON，界面上表现为「无法连接服务」，害人去查网线。
//   先零依赖地确认一次，读不到就产出带错误码的 JSON。
require __DIR__ . '/_boot.php';
vip_boot_require_or_json(__DIR__ . '/../app/bootstrap.php', 'api');

$config = require __DIR__ . '/../app/bootstrap.php';

use Vip\App;
use Vip\Http\Api;

$app = new App($config);

// 取出 /api.php 之后的路径部分
$path = (string)($_SERVER['PATH_INFO'] ?? '');
if ($path === '') {
    $uri  = parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '';
    $path = preg_replace('#^.*?/api(?:\.php)?#', '', $uri) ?: '';
}
$path = '/' . trim($path, '/');

/**
 * 这次请求用哪种语言回话。
 *
 * 必须在派发【之前】定下来，因为 Api::fail() 随时可能被调用 ——
 * 包括登录失败、参数不对这些还没查到账号的场合。
 *
 * 请求头优先：Pad 每次请求都带上它当前显示的语言，这样
 * 「界面是西语、报错是中文」这种割裂不会出现。取不到才用后台默认。
 */
$lang = $_SERVER['HTTP_X_LANG'] ?? null;
if ($lang === null) {
    /**
     * ★ 读后台默认语言要连库，而这一句在【派发之前】——
     *   库连不上时它会直接抛出去，把整个 API 打死，
     *   连 /health 都返回 500。而 /health 的全部职责就是
     *   「告诉前端库连不上」，正是最需要它能答话的时候。
     *
     *   所以这里连不上就算了，用内置回落语言。
     *   语言只影响提示语言，不该有能力让接口整个失效。
     */
    try {
        $lang = $app->cfg()->get('default_lang', \Vip\Lang::FALLBACK);
    } catch (\Throwable) {
        $lang = \Vip\Lang::FALLBACK;
    }
}
Api::setLang($lang);

// 顶层兜底：路由注册阶段的异常也要产出 JSON。
// 否则客户端只会收到空响应体的 500，无从排障。
try {
    /** @var Api $api */
    $api = require __DIR__ . '/../app/api/routes.php';
    $api->dispatch((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'), $path);
} catch (\Throwable $e) {
    // 统一走 bootFail：带分类码与事件号，日志与界面对得上
    Api::bootFail($e, 'api');
}
