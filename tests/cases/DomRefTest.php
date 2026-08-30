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

T::group('每一屏都要有一个「主色按钮」—— 视觉优先级不能反过来');

/**
 * ★★ 这条测试是补一次现场事故的。
 *
 *   「③ 记账方式」那一屏上，唯一有颜色的是「标记为免费餐（10送1核销）」——
 *   一个既危险又极少用的按钮。而每天要点几百次的「均摊 AA」是白底黑字，
 *   跟旁边的说明文字一样平。
 *
 *   现场反馈：收银员求快时，眼睛先落在那抹红色上，点错的正是它。
 *
 *   根因不是配色难看，是【视觉优先级和使用频率反过来了】。
 *   其余每一屏都有 .primary 主按钮，只有这两屏没有 ——
 *   因为它们的主按钮不是普通 button（一个是 .mode 卡片，
 *   一个是动态填文字的 #btn-widen），当初就漏掉了。
 *
 * 判定：每个 .step 里至少要有一个「带主色」的元素
 * （.primary 或 .mode-main）。
 * 只有纯展示、没有下一步动作的步骤才可以没有。
 */
$padHtml = file_get_contents(__DIR__ . '/../../wwwroot/index.php');

preg_match_all('/<div id="(step-[a-z-]+)" class="step[^"]*">(.*?)\n  <\/div>/s', $padHtml, $steps, PREG_SET_ORDER);
T::true(count($steps) >= 5, '扫到了 ' . count($steps) . ' 个步骤屏');

/**
 * 有的屏主按钮是 JS 生成的（step-order 的订单卡片），HTML 里看不到 ——
 * 所以两边都认。第一版只扫 HTML，把 step-order 报成了「没有主色按钮」，
 * 而它其实只是把主色加在动态卡片上。
 */
$padJs = file_get_contents(__DIR__ . '/../../wwwroot/assets/pad.js');
$dynamicPrimary = [
    'step-order' => 'pickable',    // 可选的订单卡，renderOrders() 里加的
];

$noPrimary = [];
foreach ($steps as [, $id, $body]) {
    $hasPrimary = str_contains($body, 'class="primary')
               || str_contains($body, 'primary big')
               || str_contains($body, 'mode-main');
    if (!$hasPrimary && isset($dynamicPrimary[$id])) {
        $hasPrimary = str_contains($padJs, $dynamicPrimary[$id]);
    }
    if (!$hasPrimary) { $noPrimary[] = $id; }
}
T::true($noPrimary === [],
    '★★ 每个步骤屏都有一个主色按钮，眼睛知道该先看哪儿'
    . ($noPrimary
        ? "\n      没有主色按钮的屏：" . implode('、', $noPrimary)
          . "\n      —— 这一屏上最显眼的会变成别的东西（比如那个红色的危险按钮），"
          . "\n         收银员求快时就会点错"
        : ''));

/**
 * 危险动作要【隔开放】，光换颜色不够 —— 手指连点时会顺势滑过去。
 */
foreach (['btn-free-meal' => '标记为免费餐', 'btn-manual' => '改用手工录入'] as $id => $label) {
    T::true((bool)preg_match(
        '/<div class="danger-zone">.*?id="' . preg_quote($id, '/') . '"/s', $padHtml),
        "★ 「{$label}」放在 .danger-zone 里（与常用按钮之间有分隔线和留白）");
}

// 主色按钮不能反过来给危险动作
foreach (['btn-free-meal', 'btn-manual'] as $id) {
    T::true(!(bool)preg_match('/id="' . preg_quote($id, '/') . '"[^>]*class="[^"]*primary/', $padHtml)
         && !(bool)preg_match('/class="[^"]*primary[^"]*"[^>]*id="' . preg_quote($id, '/') . '"/', $padHtml),
        "★★ #{$id} 不是主色按钮 —— 危险动作永远不该是最显眼的那个");
}

T::group('按钮配色只有三档，红色专属于危险动作');

/**
 * ★ 现场问过「返回/取消能不能加红框」。量过之后没这么做，理由钉在这里。
 *
 *   真问题是【看不见】：原来的边框 #d9dce1 对页面底色只有 1.26:1，
 *   按钮的形状根本立不出来。所以「找不到返回键」是真的。
 *
 *   但红色在这几屏上已经有含义了 —— 免费餐核销、手工录入这类
 *   危险且不可逆的动作。返回/取消恰恰是最安全的操作，点错没有任何后果。
 *   给最安全的动作配最强的警示色，等于把视觉优先级再反一次
 *   （上一版刚修好过一次）。
 *
 *   所以改的是「看得见」而不是「看着危险」。三档固定下来：
 *     主色蓝    = 该点的那个
 *     中性描边  = 安全的退出/返回
 *     暖色+隔离 = 危险且不可逆
 */
$padCss = file_get_contents(__DIR__ . '/../../wwwroot/assets/pad.css');

// 抓 .ghost 那条规则本体（不含 .ghost.warn）
T::true((bool)preg_match('/^\.ghost\s+\{([^}]*)\}/m', $padCss, $g),
    '找得到 .ghost 的样式规则');
$ghost = $g[1] ?? '';

foreach (['--warn', '--err', 'var(--warn', 'var(--err'] as $needle) {
    T::false(str_contains($ghost, $needle),
        "★★ .ghost（返回/取消）没有用危险色 {$needle} —— 红色只留给不可逆的动作");
}
T::false((bool)preg_match('/#(c0392b|b45309|b26a00|d0021b)/i', $ghost),
    '★★ .ghost 也没有硬编码的红/橙 —— 同上');

// 边框要真的看得见，且必须走 --exit 这一档命名颜色（不是散落的 hex）
T::true(str_contains($ghost, 'var(--exit'),
    '★★ .ghost 用 --exit 这一档命名颜色 —— 三档要有名字才守得住');
T::true((bool)preg_match('/--exit-line:\\s*#([0-9a-f]{6})/i', $padCss, $bc),
    '★ 调色板里定义了 --exit-line');
if (isset($bc[1])) {
    $lum = static function (string $hex): float {
        $c = array_map(static function (string $h): float {
            $v = hexdec($h) / 255;
            return $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
        }, str_split($hex, 2));
        return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
    };
    $bg = $lum('f4f5f7');
    $fg = $lum($bc[1]);
    $ratio = (max($bg, $fg) + 0.05) / (min($bg, $fg) + 0.05);
    T::true($ratio >= 3.0,
        sprintf('★★ 边框对页面底色 %.2f:1，过 WCAG 对 UI 控件的 3:1（原来是 1.26:1，等于看不见）', $ratio));
}

// 危险动作那一档仍然是暖色 —— 别在「统一配色」时把它一起抹平了
T::true((bool)preg_match('/\.ghost\.warn\s*\{[^}]*var\(--warn\)/', $padCss),
    '★★ .ghost.warn 仍然是暖色 —— 危险动作要保持可辨认');
