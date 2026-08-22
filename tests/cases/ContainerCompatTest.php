<?php
declare(strict_types=1);

/**
 * 原生容器兼容守卫。
 *
 * 平板跑的是 SushiVIP 原生容器（Android WebView），下面这些写法在里面
 * 是【静默失败】—— 不报错、不提示、点了完全没反应，比崩溃还难排查：
 *
 *   window.open()      容器未开多窗口，返回 null，什么都不发生
 *   <a download> / Blob 下载   容器未实现 setDownloadListener，点了没反应
 *   alert/confirm/prompt       系统弹窗标题会显示页面来源地址；
 *                              prompt 若容器没实现 onJsPrompt 就静默返回 null
 *
 * 现在这些已全部清零，本文件负责让它们回不来。
 *
 * ★ 检查前必须剥掉注释与字符串字面量 ——
 *   ui.js 的注释里就写着「alert/confirm/prompt」，
 *   直接 grep 会匹配到自己的说明文字，测试红着但代码是对的（或反过来）。
 *   上一轮已经在 PHP 侧栽过一次（断言把注释当代码），这里不再犯。
 */

/**
 * 把 JS 源码里的注释去掉、字符串/模板/正则的内容清空，只留下代码骨架。
 * 保留 '.' 等结构字符，这样 UI.confirm 与裸 confirm 才能区分开。
 */
function js_strip(string $src, bool $blankStrings = true): string
{
    $out = '';
    $n   = strlen($src);
    $i   = 0;
    $prevSignificant = '';     // 上一个有意义的字符，用于判断 / 是正则还是除号

    while ($i < $n) {
        $c  = $src[$i];
        $c2 = $i + 1 < $n ? $src[$i + 1] : '';

        // 行注释
        if ($c === '/' && $c2 === '/') {
            while ($i < $n && $src[$i] !== "\n") { $i++; }
            continue;
        }
        // 块注释
        if ($c === '/' && $c2 === '*') {
            $i += 2;
            while ($i + 1 < $n && !($src[$i] === '*' && $src[$i + 1] === '/')) { $i++; }
            $i += 2;
            continue;
        }
        // 字符串 / 模板
        if ($c === '"' || $c === "'" || $c === '`') {
            $q     = $c;
            $start = $i;
            $i++;
            while ($i < $n) {
                if ($src[$i] === '\\') { $i += 2; continue; }
                if ($src[$i] === $q)   { $i++; break; }
                $i++;
            }
            // 默认清空字符串内容（查「有没有裸 confirm(」时，字符串里的同名
            // 文本不该算数）；查事件名这类只存在于字符串里的东西时保留原文。
            $out .= $blankStrings ? ($q . $q) : substr($src, $start, $i - $start);
            $prevSignificant = $q;
            continue;
        }
        // 正则字面量：/ 前面若是这些字符，说明处于「期待值」的位置
        if ($c === '/' && ($prevSignificant === '' || strpos('(,=:[!&|?{};+-*%~^<>', $prevSignificant) !== false)) {
            $i++;
            $inClass = false;
            while ($i < $n) {
                if ($src[$i] === '\\')                  { $i += 2; continue; }
                if ($src[$i] === '[')                   { $inClass = true; }
                elseif ($src[$i] === ']')               { $inClass = false; }
                elseif ($src[$i] === '/' && !$inClass)  { $i++; break; }
                elseif ($src[$i] === "\n")              { break; }
                $i++;
            }
            while ($i < $n && strpos('gimsuyd', $src[$i]) !== false) { $i++; }
            $out .= '//';
            $prevSignificant = '/';
            continue;
        }

        $out .= $c;
        if (trim($c) !== '') { $prevSignificant = $c; }
        $i++;
    }
    return $out;
}

/** 去掉 HTML 注释 */
function html_strip(string $src): string
{
    return preg_replace('/<!--.*?-->/s', '', $src) ?? $src;
}

T::group('容器兼容 · 扫描器自检（先证明这把尺子是准的）');

$probe = <<<'JS'
// window.open('注释里的，不算')
/* prompt('块注释里的，不算') */
const re = /[&<>"']/g;          // 正则里有引号，不能把解析带偏
const s = "字符串里的 window.open( 不算";
const t = `模板里的 confirm( 不算`;
UI.confirm('这是页内弹层，不算');
JS;
$stripped = js_strip($probe);
T::false(str_contains($stripped, 'window.open'), '★ 注释与字符串里的 window.open 不会被误判');
T::false((bool)preg_match('/(?<![\w.$])prompt\s*\(/', $stripped), '★ 块注释里的 prompt( 不会被误判');
T::false((bool)preg_match('/(?<![\w.$])confirm\s*\(/', $stripped), '★ UI.confirm 不会被当成裸 confirm');
T::true(str_contains($stripped, 'UI.confirm'), '保留 . 结构，UI.confirm 仍可见');

$keep = js_strip("// 注释里的 popstate 不算\naddEventListener('popstate', f);", false);
T::false(str_contains($keep, '注释里的'), '★ 保留字符串模式下，注释仍然被剥掉');
T::true(str_contains($keep, "'popstate'"), '★ 保留字符串模式下，字符串原文留着');

$probe2 = js_strip("window.open('/x');\nif (confirm('y')) {}\nconst v = prompt('z');");
T::true(str_contains($probe2, 'window.open'), '★ 真的 window.open 抓得到');
T::true((bool)preg_match('/(?<![\w.$])confirm\s*\(/', $probe2), '★ 真的裸 confirm 抓得到');
T::true((bool)preg_match('/(?<![\w.$])prompt\s*\(/', $probe2), '★ 真的裸 prompt 抓得到');

T::group('容器兼容 · 三类静默失败必须保持零');

$root = __DIR__ . '/../../wwwroot/';
$jsFiles = ['assets/pad.js', 'assets/ui.js', 'cp/cp.js'];

foreach ($jsFiles as $rel) {
    $code = js_strip(file_get_contents($root . $rel));

    T::false(str_contains($code, 'window.open'),
        "$rel 无 window.open（容器返回 null，静默失败）");
    T::false((bool)preg_match('/(?<![\w.$])(alert|confirm|prompt)\s*\(/', $code),
        "$rel 无裸 alert/confirm/prompt（改用 UI.confirm / UI.input）");
    T::false(str_contains($code, 'createObjectURL'),
        "$rel 无 Blob 下载（容器未实现 setDownloadListener）");
}

foreach (['index.html', 'cp/index.html'] as $rel) {
    $html = html_strip(file_get_contents($root . $rel));
    T::false((bool)preg_match('/<a[^>]+\bdownload\b/i', $html), "$rel 无 <a download> 下载链接");
    T::false((bool)preg_match('/target\s*=\s*["\']_blank/i', $html), "$rel 无 target=\"_blank\"");
    T::false((bool)preg_match('/href\s*=\s*["\'][^"\']*\.pdf/i', $html), "$rel 无 PDF 直链（WebView 渲染不了）");
}

T::group('容器兼容 · 平板必需的页面设置');

foreach (['index.html', 'cp/index.html'] as $rel) {
    $html = file_get_contents($root . $rel);
    T::true((bool)preg_match('/<meta\s+name=["\']viewport["\'][^>]*viewport-fit=cover/i', $html),
        "$rel 的 viewport 含 viewport-fit=cover（容器是全面屏沉浸模式）");
    T::true(str_contains($html, 'id="btn-refresh-login"'),
        "$rel 登录页有刷新入口（容器没有地址栏和刷新按钮）");
}

T::group('容器兼容 · 脚本加载顺序（顺序即依赖）');

/**
 * 都是同步脚本：桥接与 ui.js 必须排在业务脚本之前，
 * 否则 pad.js 顶层求值 DEVICE 时 window.SushiVIP 还不存在，
 * 会静默回落到浏览器兜底 ID —— 表现为「设备 ID 一直是 PAD-xxxx」，
 * 而不会报任何错。
 */
$pad = file_get_contents($root . 'index.html');
$posBridge = strpos($pad, 'sushivip-bridge.js');
$posUi     = strpos($pad, '/assets/ui.js');
$posPad    = strpos($pad, '/assets/pad.js');
T::true($posBridge !== false, 'Pad 页引入了桥接封装');
T::true($posUi !== false, 'Pad 页引入了 ui.js');
T::true($posBridge !== false && $posPad !== false && $posBridge < $posPad,
    '★ 桥接排在 pad.js 之前（晚了就取不到原生设备 ID，且不报错）');
T::true($posUi !== false && $posPad !== false && $posUi < $posPad,
    '★ ui.js 排在 pad.js 之前');

$cp = file_get_contents($root . 'cp/index.html');
T::true(strpos($cp, '/assets/ui.js') !== false
    && strpos($cp, '/assets/ui.js') < strpos($cp, '/cp/cp.js'),
    '★ 后台 ui.js 排在 cp.js 之前');

T::group('容器兼容 · 设备标识');

$padJs = js_strip(file_get_contents($root . 'assets/pad.js'));
T::true(str_contains($padJs, 'SushiVIP'),
    '★ 设备 ID 走容器桥接，不再只靠 localStorage 随机串');
T::true(str_contains($padJs, 'localStorage'),
    'PC 浏览器调试时仍有本地兜底（否则开发环境登录不了）');

T::group('容器兼容 · 物理返回键');

/**
 * 容器的返回键先问 WebView.canGoBack()：有历史就后退，没有就弹「确认退出」。
 * 两个页面都是单页状态机，不写历史的话 canGoBack() 恒为 false ——
 * 收银员在记账任何一步按返回，得到的都是「要退出应用吗」。
 * 这个问题只在容器里出现，浏览器上完全看不出来。
 */
$ui  = js_strip(file_get_contents($root . 'assets/ui.js'));
$pad = js_strip(file_get_contents($root . 'assets/pad.js'));

// 'popstate' 只出现在字符串里，且 ui.js 的注释里也写着这个词 ——
// 所以既不能用清空字符串的模式，也不能直接读原文
$uiNoComment = js_strip(file_get_contents($root . 'assets/ui.js'), false);
T::true((bool)preg_match("/addEventListener\\(\\s*['\"]popstate['\"]/", $uiNoComment),
    '★ ui.js 监听 popstate（否则返回键在容器里等于直接退出应用）');
T::true(str_contains($ui, 'pushState'),
    '★ 深层时往历史里放哨兵，让 canGoBack() 为真');
T::true(str_contains($pad, 'UI.back.register'),
    '★ Pad 注册了自己的步骤层级（弹层 → 步骤，逐级后退）');
T::true(str_contains($pad, 'STEP_BACK'),
    '每一步的上一步是显式写死的，不靠下标算');

// 有 push 就必须有对应的收尾，否则回到起点后还残留哨兵，
// 表现为「按了一下返回没反应」
T::true(str_contains($ui, 'history.back'),
    '★ 自行回到最外层时会把残留哨兵收掉');

T::group('容器兼容 · 安全区避让');

/**
 * 容器是边到边沉浸式（setDecorFitsSystemWindows(false) + 隐藏系统栏），
 * 加上 viewport-fit=cover，内容会一直铺到屏幕物理边缘。
 * 平板若有挖孔或圆角，不做避让就会被压住 —— 横屏关注 left/right。
 *
 * 这两件事必须成对存在：声明了 viewport-fit=cover 却不避让，
 * 比两样都不做更糟（cover 正是让内容铺到边缘的那个开关）。
 */
foreach (['assets/pad.css', 'cp/cp.css'] as $rel) {
    $css = file_get_contents($root . $rel);
    T::true(str_contains($css, 'safe-area-inset-left'),
        "$rel 做了左侧安全区避让（横屏挖孔多在左右）");
    T::true(str_contains($css, 'safe-area-inset-right'),
        "$rel 做了右侧安全区避让");
    // 必须留一个不带 env() 的普通值兜底：不支持 env() 的浏览器会把
    // 整条声明判为非法丢弃，没有兜底就完全没有内边距
    T::true((bool)preg_match('/padding:\s*\d+px/', $css),
        "$rel 保留了不带 env() 的普通 padding 作兜底");
}
