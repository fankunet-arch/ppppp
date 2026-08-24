<?php
declare(strict_types=1);

use Vip\Http\Api;
use Vip\Lang;

/**
 * 多语言 —— 守的是「漏翻译」。
 *
 * 漏一条不会报错、不会崩，只会在西语界面上冒出一句中文，
 * 而现场没人会为这种「小事」专门来报。所以只能靠机器逐键比对。
 *
 * 两套文案分居两处，各有各的道理：
 *   · 服务端 Api::MESSAGES —— 错误码对应的文案，前端不重复一份
 *   · 前端 i18n.js         —— 界面上的静态文字，服务端用不着
 * 这里两边都查。
 */

T::group('多语言 · 语言码');

T::true(Lang::isValid('zh'), 'zh 是支持的');
T::true(Lang::isValid('es'), 'es 是支持的');
T::true(!Lang::isValid('fr'), '没做的语言不认');
T::true(!Lang::isValid(null), 'null 不是语言');
T::eq('zh', Lang::normalize('zh-CN'), 'zh-CN 收敛成 zh');
T::eq('es', Lang::normalize('es-ES'), 'es-ES 收敛成 es');
T::eq('es', Lang::normalize('ES'), '大小写不敏感');
T::eq('es', Lang::normalize('es_MX'), '下划线写法也认');
T::eq('zh', Lang::normalize('fr'), '认不出的回落到中文');
T::eq('zh', Lang::normalize(null), 'null 回落到中文');
T::eq('es', Lang::normalize(null, 'es'), '回落目标可指定');

T::group('多语言 · 服务端错误文案两种语言齐全');

$k  = Api::messageKeys();
$zh = $k['zh'];
$es = $k['es'];

$missing = array_diff($zh, $es);
T::true($missing === [], '★ 每一条中文错误文案都有西语版'
    . ($missing ? '（缺：' . implode(', ', $missing) . '）' : ''));

$extra = array_diff($es, $zh);
T::true($extra === [], '西语里没有多出来的孤儿键'
    . ($extra ? '（多：' . implode(', ', $extra) . '）' : ''));

T::true(count($zh) > 50, '文案条数看着正常（' . count($zh) . ' 条）');

// 按语言取词
Api::setLang('zh');
T::eq('zh', Api::lang(), '语言设成中文');
$zhMsg = Api::message('card_unknown');
Api::setLang('es');
T::eq('es', Api::lang(), '语言设成西语');
$esMsg = Api::message('card_unknown');
T::true($zhMsg !== $esMsg, '★ 同一个错误码在两种语言下给出不同文案');
T::true(preg_match('/[\x{4e00}-\x{9fa5}]/u', $esMsg) !== 1,
    '★ 西语文案里不该混进中文：「' . $esMsg . '」');

// 不存在的键：原样吐出错误码，不要静默变成空字符串
T::eq('no_such_code', Api::message('no_such_code'), '未知错误码原样返回，便于排查');
Api::setLang('zh');   // 还原，别影响后面的用例

T::group('多语言 · 前端词典两种语言齐全');

/**
 * i18n.js 是浏览器脚本，这里不跑 JS，直接把词典段落解析出来核对。
 * 只认 `'key': { zh: ..., es: ... }` 这一种写法 —— 词典本来就该只有这一种。
 */
$js = file_get_contents(__DIR__ . '/../../wwwroot/assets/i18n.js');
T::true($js !== false && $js !== '', 'i18n.js 读得到');

preg_match_all("/'([a-zA-Z][\w.]*)':\s*\{\s*zh:/", $js, $m);
$keys = $m[1];
T::true(count($keys) > 150, '词条数看着正常（' . count($keys) . ' 条）');
T::eq(count($keys), count(array_unique($keys)), '★ 没有重复的键（后一条会静默盖掉前一条）');

// 每个词条都必须同时有 zh 和 es
preg_match_all("/'([a-zA-Z][\w.]*)':\s*\{(.*?)\}\s*,?\s*(?=\n\s*(?:'|\/\*|\};))/s", $js, $mm);
$noEs = [];
foreach ($mm[1] as $i => $key) {
    if (!str_contains($mm[2][$i], 'es:')) { $noEs[] = $key; }
}
T::true($noEs === [], '★ 每个词条都有西语'
    . ($noEs ? '（缺：' . implode(', ', $noEs) . '）' : ''));

T::group('多语言 · pad.js 里不该再有硬编码的界面文字');

/**
 * 防回归：以后加功能时顺手写一句中文提示，是最容易发生的事。
 * 这条断言会在那时候红掉，提醒去补词典。
 *
 * 只看代码，注释里的中文不算 —— 本项目的注释本来就是中文写的。
 */
$pad   = file_get_contents(__DIR__ . '/../../wwwroot/assets/pad.js');
$bad   = [];
$inBlock = false;
foreach (explode("\n", $pad) as $no => $line) {
    $trimmed = ltrim($line);
    if ($inBlock) {
        if (str_contains($line, '*/')) { $inBlock = false; }
        continue;
    }
    if (str_starts_with($trimmed, '/*')) {
        if (!str_contains($line, '*/')) { $inBlock = true; }
        continue;
    }
    if (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*')) { continue; }
    // 行尾注释两种写法都要剥掉：`// …` 和 `/* … */`
    $code = preg_replace('#/\*.*?\*/#u', '', $line);
    $code = preg_replace('#//.*$#u', '', (string)$code);
    if (preg_match('/[\x{4e00}-\x{9fa5}]/u', (string)$code)) {
        $bad[] = ($no + 1) . ': ' . trim((string)$code);
    }
}
// 语言名按惯例用各自的语言写（中文 / Español），那一行是允许的
$bad = array_values(array_filter($bad, static fn($l) => !str_contains($l, "zh: '中文'")));
T::true($bad === [], '★ pad.js 里没有漏掉的硬编码中文'
    . ($bad ? "\n      " . implode("\n      ", $bad) : ''));

T::group('多语言 · 静态 DOM 的翻译标记不会吃掉输入框');

/**
 * `data-i18n` 走的是 textContent，会把元素里的子节点【整个换掉】。
 * 所以带 <input>/<select> 的 <label> 绝不能直接挂 data-i18n ——
 * 一切语言，输入框就没了。文字要单独包一层 <span>。
 *
 * 这个错误在中文界面下完全看不出来（初始 HTML 是对的），
 * 只有切一次语言才会暴露，所以必须静态查出来。
 */
$html = file_get_contents(__DIR__ . '/../../wwwroot/index.html');
preg_match_all('/<(\w+)[^>]*\sdata-i18n="[^"]+"[^>]*>(.*?)<\/\1>/s', $html, $tags, PREG_SET_ORDER);
$withChildren = [];
foreach ($tags as $t) {
    if (preg_match('/<(input|select|textarea|button|video)\b/i', $t[2])) {
        $withChildren[] = $t[1] . ': ' . substr(preg_replace('/\s+/', ' ', $t[2]), 0, 40);
    }
}
T::true($withChildren === [], '★ 没有 data-i18n 元素里裹着输入控件'
    . ($withChildren ? '（' . implode(' | ', $withChildren) . '）' : ''));

/**
 * 引用顺序：pad.js 在顶层就调 T()，词典必须先加载好。
 *
 * ★ 只看 <script> 标签，不能对整份 HTML 做 strpos ——
 *   上面那行注释里就写着 “pad.js”，会先被找到，于是这条断言
 *   在顺序完全正确时也红。（第一版就是这么写错的。）
 */
preg_match_all('/<script[^>]+src="([^"]+)"/', $html, $scripts);
$order = array_map(static fn(string $p): string => basename($p), $scripts[1]);
$iI18n = array_search('i18n.js', $order, true);
$iPad  = array_search('pad.js',  $order, true);
T::true($iI18n !== false, 'index.html 引了 i18n.js');
T::true($iI18n !== false && $iPad !== false && $iI18n < $iPad,
    '★ i18n.js 必须排在 pad.js 之前（实际顺序：' . implode(' → ', $order) . '）');
