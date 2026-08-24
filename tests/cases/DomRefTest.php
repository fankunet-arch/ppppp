<?php
declare(strict_types=1);

/**
 * 前端引用的元素，必须真的存在。
 *
 * ★ 这条测试是补上一次现场事故的。
 *
 *   改版时把 index.html 里的 `<span id="win-label">` 删掉了，
 *   但 pad.js 里 `$('#win-label').textContent = d.window;` 还在。
 *   结果：收银员输桌号一查，弹出
 *       Cannot set properties of null (setting 'textContent')
 *   而且因为异常被 catch 吃掉，连「放宽再找」和「改用手工录入」
 *   两个降级入口也一起消失了 —— 现场看到的是「按钮没了」，
 *   根本猜不到是一个删剩的引用。
 *
 *   这类 bug 的共性是【HTML 改了，JS 里的引用没跟着改】。
 *   静态查一遍就能全挡掉，比事后靠人在平板上发现便宜太多。
 *
 * 判定规则：`$('#foo')` 里的 foo，必须能在下面两处之一找到 `id="foo"`
 *   · 对应的页面（index.php / cp/index.php）—— 静态存在的元素
 *   · 脚本自己拼出来的 HTML —— 动态生成的元素
 * 两处都没有 = 引用了一个不存在的东西。
 */

$root = __DIR__ . '/../../wwwroot/';

/** 取出脚本里所有 $('#id') / $$('#id') 形式的选择器 */
function dom_refs(string $js): array
{
    // 只认单一 id 选择器；带层级或属性的（'#a .b'、'#x[hidden]'）另算，这里不管
    preg_match_all('/\$\$?\(\s*[\x27"]#([A-Za-z][\w-]*)[\x27"]\s*[,)]/', $js, $m);
    return array_values(array_unique($m[1]));
}

/** 取出一份文本里所有 id="foo"（HTML 里的，或 JS 拼串里的） */
function declared_ids(string $text): array
{
    preg_match_all('/\bid\s*=\s*[\x27"]([A-Za-z][\w-]*)[\x27"]/', $text, $m);
    // 模板串里形如 id="rw-${i}" 的动态 id 前缀也收进来，避免误报
    preg_match_all('/\bid\s*=\s*[\x27"]([A-Za-z][\w-]*)\$\{/', $text, $m2);
    return array_values(array_unique(array_merge($m[1], $m2[1])));
}

$pairs = [
    ['pad',  'assets/pad.js', 'index.php'],
    ['cp',   'cp/cp.js',      'cp/index.php'],
];

foreach ($pairs as [$label, $jsFile, $htmlFile]) {
    T::group("DOM 引用 · {$label}（{$jsFile} ↔ {$htmlFile}）");

    $js   = file_get_contents($root . $jsFile);
    $html = file_get_contents($root . $htmlFile);
    T::true($js !== false && $html !== false, "两个文件都读得到");

    $refs      = dom_refs($js);
    $inHtml    = declared_ids($html);
    $inJs      = declared_ids($js);       // 脚本自己拼出来的
    $available = array_merge($inHtml, $inJs);

    $missing = [];
    foreach ($refs as $id) {
        if (!in_array($id, $available, true)) { $missing[] = $id; }
    }

    T::true($missing === [],
        "★★ {$jsFile} 引用的元素都存在（共查 " . count($refs) . " 个）"
        . ($missing
            ? "\n      找不到：" . implode(', ', $missing)
              . "\n      —— 多半是 HTML 里删了元素、JS 里的引用忘了跟着删"
            : ''));

    T::true(count($refs) > 10, "确实扫到了引用（" . count($refs) . " 个），不是正则没匹配上");
}

T::group('DOM 引用 · 反向：页面上标了 id 却没人用的元素');

/**
 * 反向检查是【提示】不是断言：留一个没人用的 id 不会出错，
 * 但往往说明改版时漏删了什么。所以只统计，不判失败。
 */
$padJs   = file_get_contents($root . 'assets/pad.js');
$padHtml = file_get_contents($root . 'index.php');
$unused  = [];
foreach (declared_ids($padHtml) as $id) {
    if (!str_contains($padJs, "'#{$id}'") && !str_contains($padJs, "\"#{$id}\"")
        && !str_contains($padJs, "data-i18n") /* 纯展示元素靠属性驱动，不需要 JS 引用 */) {
        $unused[] = $id;
    }
}
T::true(true, '（仅统计）页面上没有被 pad.js 直接引用的 id：' . (count($unused) ?: 0) . ' 个');

T::group('DOM 引用 · 靠 JS 填文字的元素，登录后必须先填一次');

/**
 * 「放宽到 N 分钟再找」这类带参数的文案没法用 data-i18n 静态标注，
 * 只能由 JS 填。那就必须保证【进主界面时先填一次】——
 * 否则按钮上一个字都没有，现场看到的是「按钮不见了」。
 */
$jsFilled = ['table-hint', 'btn-widen'];
foreach ($jsFilled as $id) {
    T::true((bool)preg_match('/<[^>]*\bid="' . preg_quote($id, '/') . '"[^>]*>\s*<\//', $padHtml)
         || (bool)preg_match('/<[^>]*\bid="' . preg_quote($id, '/') . '"[^>]*\/?>/', $padHtml),
        "#{$id} 在页面上是空的（文字由 JS 按当前语言填）");
}
T::true(str_contains($padJs, 'refreshDynamicText();')
    && preg_match('/function enterMain\(.*?refreshDynamicText\(\);.*?\n\}/s', $padJs) === 1,
    '★★ enterMain 里调了 refreshDynamicText —— 登录后这些文字才有内容');
