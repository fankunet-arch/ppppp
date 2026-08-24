<?php
declare(strict_types=1);

/**
 * 静态资源的版本戳 —— 专治「代码传上去了，Pad 上还是旧页面」。
 *
 * ★ 为什么必须由 PHP 来发这个页面
 *
 *   浏览器（尤其是容器里的 WebView）会把 index.html / pad.js / pad.css
 *   缓存起来。静态文件自己没法告诉浏览器「我换了」，于是收银员看到的
 *   还是上一版，而 Pad 上没有地址栏、没有刷新按钮，清缓存要进系统设置 ——
 *   现场根本做不到。
 *
 *   页面由 PHP 发出来之后，就能做两件静态文件做不到的事：
 *     1. 给页面本身加 no-store：文档永远重新取
 *     2. 给每个资源 URL 带上版本号：内容变了 URL 就变，
 *        浏览器认成新文件，必然重新下载；没变的照旧命中缓存，不牺牲速度
 *
 * ★ 版本号取 filemtime 而不是文件内容哈希
 *
 *   哈希要把整个文件读一遍，而这是【每次开页面】都要跑的。
 *   mtime 只是一次 stat，几乎不花钱，而且任何一种部署方式
 *   （scp / rsync / 面板上传 / git pull）都会让它变。
 *
 * 本文件零依赖：不 require /app 下的任何东西。
 * 页面是收银员唯一的入口，它不能因为 open_basedir 之类的环境问题打不开
 * （教训见 07 §1 第 11 条与 _boot.php）。
 */

/**
 * 单个资源的带版本 URL：assets/pad.js → /assets/pad.js?v=1755500000
 *
 * ★ 返回的一定是【以 / 开头】的绝对路径。
 *   cp/index.php 里写 vip_asset('cp/cp.css')，若返回相对路径，
 *   浏览器会按当前目录 /cp/ 去解析，得到 /cp/cp/cp.css —— 404。
 *   全站本来就假定从站点根部署（API 也是写死的 /api.php）。
 */
function vip_asset(string $rel): string
{
    $rel = ltrim($rel, '/');
    $mt  = @filemtime(__DIR__ . '/' . $rel);
    // 取不到就退回当前时间戳：宁可每次都重新下载，也不要发出一个会被缓存住的旧版本
    return '/' . $rel . '?v=' . ($mt === false ? time() : $mt);
}

/**
 * 整个部署的版本号 —— 取所有前端资源里最新的那个 mtime。
 *
 * 客户端把它记在 window.APP_VERSION，之后与 /health 返回的值比对：
 * 对不上就说明手里这份是旧的，可以在安全的时机自动刷新。
 */
function vip_app_version(array $files): string
{
    $max = 0;
    foreach ($files as $rel) {
        $mt = @filemtime(__DIR__ . '/' . ltrim($rel, '/'));
        if ($mt !== false && $mt > $max) { $max = $mt; }
    }
    return (string)($max ?: time());
}

/**
 * 页面本身绝不缓存。
 *
 * 资源靠版本号，文档靠这个 —— 两者缺一不可：
 * 文档要是被缓存住，里面写的还是旧版本号，资源再怎么带版本也没用。
 */
function vip_no_store(): void
{
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
}
