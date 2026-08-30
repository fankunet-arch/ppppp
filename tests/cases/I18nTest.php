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

/**
 * ★★ 同一个数组里不能出现重复的键 —— 这条是补一次真实事故的。
 *
 * 加防刷闸门的文案时，我又写了一条 'reason_required'，
 * 而这个键在下面「强制核销」那一段里已经有了。
 * PHP 对数组字面量里的重复键【不报错也不警告】，后面那条直接覆盖前面那条 ——
 * 于是新写的文案根本没生效，而收银员会在一个完全不相干的场景下
 * 看到「强制核销必须填写原因」。
 *
 * 上面的中西对照断言也抓不到：Api::messageKeys() 拿到的已经是
 * PHP 解析【去重之后】的结果，两边一样多、一样齐全，全绿。
 * 只有回去数源文件里的字面量才看得见。
 */
$src = file_get_contents(__DIR__ . '/../../app/lib/Http/Api.php');
foreach (['MESSAGES', 'MESSAGES_ES'] as $arr) {
    T::true((bool)preg_match('/const ' . $arr . ' = \[(.*?)\n    \];/s', $src, $m),
        "找得到 {$arr} 的字面量");
    preg_match_all("/^\s*'([a-z_]+)'\s*=>/m", $m[1], $km);
    $counts = array_count_values($km[1]);
    $dups   = array_keys(array_filter($counts, static fn(int $n): bool => $n > 1));
    T::true($dups === [],
        "★★ {$arr} 里没有重复的键（共 " . count($km[1]) . " 条）"
        . ($dups ? "\n      重复：" . implode(', ', $dups)
                 . "\n      —— PHP 不报错，后一条会静默覆盖前一条，新文案等于没写"
                 : ''));
}

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
$html = file_get_contents(__DIR__ . '/../../wwwroot/index.php');
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
/*
 * src 现在是 `<?= vip_asset('assets/pad.js') ?\>`（带缓存版本号），两种写法都要认。
 *
 * ★ 这一段【必须用块注释】。
 *   原来是 `//` 单行注释，而 PHP 的单行注释是会被 `?` + `>` 终止的 ——
 *   注释里那个闭合标签直接把 PHP 段关掉了，从这一行往下的断言
 *   全部变成了原样输出的文本，一条都没跑，而且【测试还是绿的】。
 *   块注释里的 `?` + `>` 没有这个效果。见下面「测试文件里不能有会关标签的注释」。
 */
preg_match_all(
    '/<script[^>]+src="(?:<\?=\s*vip_asset\(\s*\x27([^\x27]+)\x27\s*\)\s*\?>|([^"]+))"/',
    $html, $scripts, PREG_SET_ORDER
);
$order = array_map(
    static fn(array $m): string => basename($m[1] !== '' ? $m[1] : $m[2]),
    $scripts
);
$iI18n = array_search('i18n.js', $order, true);
$iPad  = array_search('pad.js',  $order, true);
T::true($iI18n !== false, 'index.php 引了 i18n.js');
T::true($iI18n !== false && $iPad !== false && $iI18n < $iPad,
    '★ i18n.js 必须排在 pad.js 之前（实际顺序：' . implode(' → ', $order) . '）');

T::group('界面上不能印出真实的小票号');

/**
 * ★ 小票号就是 order_head_id —— 一个【连号的整数】。
 *
 *   界面上原本举了一个具体号码当例子：位数对、前导零对，
 *   中间那几位正好压在实际号段上 —— 拿去一查，在 POS 库里就是一张真单。
 *   等于把「号长什么样、现在数到哪儿了」一起印在了柜台的屏幕上，
 *   照着往前减就能一个个试别人的单。
 *
 *   这句提示要解决的问题只是「读小票上哪一行」，指到那一行就够了 ——
 *   号码本身客人手里那张小票上就印着，不需要界面再教一遍。
 *
 *   这条断言防的是【以后有人觉得「举个例子更清楚」又加回来】。
 */
$i18nSrc = file_get_contents(dirname(__DIR__, 2) . '/wwwroot/assets/i18n.js');
T::true($i18nSrc !== false, '读得到 i18n.js');

/**
 * 只扫【找单那一屏】的文案（lookup.*）与整份文件的注释。
 *
 * ★ 不整份文件一刀切：会员那一屏有一个卡号例子 TK-00000123-4Q7，
 *   那个留着是对的 —— 卡号末尾三位是随机码，正是为了让人猜不出来，
 *   所以举例不会变成一把钥匙。小票号没有这一段，纯连号，所以不能举例。
 *
 * ★ 注释也要扫：这份文件是发到 Pad 上的静态资源，注释跟着一起发。
 *   「在注释里记一下原来那个号是多少」等于没删。
 */
$scanLines = [];
foreach (explode("\n", (string)$i18nSrc) as $ln) {
    if (str_contains($ln, "'lookup.") || preg_match('/^\s*(\*|\/\/|\/\*)/', $ln)) {
        $scanLines[] = $ln;
    }
}
// 连着 5 位以上的数字 —— 门槛设在 5 位：{min}、{days} 这类占位符和
// 「30 分钟」「10+1」都不会命中，而任何像小票号的东西都会
preg_match_all('/\b0*\d{5,}\b/', implode("\n", $scanLines), $longNums);
$hits = array_values(array_unique($longNums[0]));
T::true($hits === [],
    '★★★ 找单那一屏（含注释）里没有 5 位以上的数字字面量 —— 举例用的小票号会教人怎么枚举'
    . ($hits ? '（找到：' . implode('、', $hits) . '）' : ''));

// 提示语本身还得留着，别为了过上面那条把话删没了
T::true(str_contains((string)$i18nSrc, 'lookup.invoiceHint'), '  └ 小票号那句提示还在');
T::true(str_contains((string)$i18nSrc, 'Factura Simplificada'),
    '  └ 并且仍然指到小票上的那一行（收银员要的是这个，不是一个号）');

T::group('查不到与超时效，对收银员必须是同一句话');

/**
 * 服务端按角色砍字段才是真的把关（tests/http_sweep.php ⑨ 打的是接口本身）。
 * 这里守的是【别把它改回去】：
 *   · 路由里必须按 is_manager 决定 reason
 *   · 前端必须有一句笼统的兜底文案
 */
$routeSrc = (string)file_get_contents(dirname(__DIR__, 2) . '/app/api/routes.php');
$seg = strstr($routeSrc, "'/order/locate-invoice'");
$seg = $seg === false ? '' : substr($seg, 0, 2600);
T::true(str_contains($seg, 'is_manager'),
    '★★ locate-invoice 路由里按 is_manager 分岔 —— 只改前端文案等于没做');
T::true(str_contains($seg, "'unavailable'"),
    '★★ 非经理拿到的是笼统的 unavailable');
T::true(str_contains($seg, 'watchInvoiceProbe'),
    '★ 查不到的每一次都留痕（有人在一个个试号时才看得出来）');

T::true(str_contains((string)$i18nSrc, 'lookup.invoiceUnavailable'),
    '★★ 前端有那句笼统文案「订单不存在或已超过时效」');
$padSrc = (string)file_get_contents(dirname(__DIR__, 2) . '/wwwroot/assets/pad.js');
T::true(!preg_match('/lookup\.tooOld\b(?!Mgr)/', $padSrc),
    '★ 前端不再用那句会泄底的 lookup.tooOld（带日期的那版只留给经理，叫 tooOldMgr）');

T::group('测试文件里不能有会关掉 PHP 标签的注释');

/**
 * ★ 这一条是被真事逼出来的。
 *
 *   I18nTest 里有一行 `//` 注释，内容里带着一个闭合标签（`?` + `>`）。
 *   PHP 的【单行注释会被闭合标签终止】—— 于是从那一行往下的断言
 *   全部变成了原样输出的文本，一条都没跑。
 *
 *   最糟的地方是【测试仍然是绿的】：没跑的断言不会失败。
 *   那几条就这么静静地死了不知道多久，直到有人往文件末尾加东西、
 *   发现新加的断言数没涨才看出来。
 *
 *   块注释 `/* *​/` 里的闭合标签没有这个效果，所以改用块注释即可。
 */
$deadFiles = [];
foreach (glob(__DIR__ . '/*.php') ?: [] as $f) {
    foreach (explode("\n", (string)file_get_contents($f)) as $n => $ln) {
        // 只看行注释：先掐掉字符串里的内容，免得把正则字面量误判成注释
        if (!preg_match('~^\s*(//|#)~', $ln)) {
            continue;
        }
        if (str_contains($ln, '?' . '>')) {
            $deadFiles[] = basename($f) . ':' . ($n + 1);
        }
    }
}
T::true($deadFiles === [],
    '★★★ cases/ 里没有「行注释中含闭合标签」的写法 —— 它会把后面的断言全部变成死代码，而且测试还是绿的'
    . ($deadFiles ? '（' . implode('、', $deadFiles) . '）' : ''));
