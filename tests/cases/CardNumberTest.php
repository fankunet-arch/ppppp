<?php
declare(strict_types=1);

use Vip\CardNumber;

/**
 * 实体卡号 —— 前缀 + 顺序号 + 随机后缀。
 *
 * 卡号的「真伪」由 card 库存表说了算，这里只管两件事：
 *   · 生成：顺序号 + 密码学随机后缀
 *   · 结构：查库之前挡掉明显的垃圾输入，并容忍收银员的手输误差
 */

$cn = new CardNumber('TK');

T::group('实体卡号 · 生成与格式');

$c = $cn->make(123, '4Q7');
T::eq('TK000001234Q7', $c, '前缀 + 8位顺序号 + 3位随机');
T::eq(13, $cn->length(), '总长 13 位');
T::eq('TK-00000123-4Q7', $cn->format($c), '印刷分组形式');
T::eq(123, $cn->serialOf($c), '能取回顺序号');
T::eq('4Q7', $cn->suffixOf($c), '能取回随机后缀');

T::group('实体卡号 · 随机后缀');

/**
 * 后缀是防「拿到一张真卡推算邻居卡号」的唯一屏障 ——
 * 邻居卡确实躺在库存里（已印刷未发放），没有它就能直接拿去激活。
 */
$suffixes = [];
$malformed = 0;
for ($i = 0; $i < 200; $i++) {
    $s = $cn->randomSuffix();
    $suffixes[$s] = true;
    if (strlen($s) !== 3 || strspn($s, CardNumber::ALPHABET) !== 3) {
        $malformed++;
    }
}
T::eq(0, $malformed, '后缀恒为 3 位且只用无歧义字符集');
T::true(count($suffixes) > 100, '★ 200 次生成得到 ' . count($suffixes) . ' 种不同后缀（确实是随机的）');

// 用 random_int 而不是 rand/mt_rand —— 卡号可猜就等于没有后缀
$src = file_get_contents(__DIR__ . '/../../app/lib/CardNumber.php');
T::true(str_contains($src, 'random_int('), '★ 用密码学随机源');
T::false((bool)preg_match('/(?<![\w_])(mt_)?rand\s*\(/', $src),
    '★ 没有用 rand / mt_rand（可预测，等于没有后缀）');

T::group('实体卡号 · 不要再加 HMAC');

/**
 * 这一条是防止后人「顺手加强」。
 *
 * 卡号无论如何都要查一次 card 表（还得知道是未发放/已激活/已挂失），
 * 所以 HMAC 提供的「离线验真伪」收益为零；而代价是一个永远不能更换、
 * 丢失即全部实体卡作废、还必须独立于数据库离线备份的密钥。
 */
T::false(str_contains($src, 'hash_hmac'),
    '★ 卡号不依赖任何密钥 —— 备份跟着数据库走，没有额外的单点故障');

T::group('实体卡号 · 结构检查');

T::true($cn->isWellFormed($c), '自己生成的通过');
T::false($cn->isWellFormed(''), '空串不通过');
T::false($cn->isWellFormed('TK'), '只有前缀不通过');
T::false($cn->isWellFormed('TK000001234Q'), '少一位不通过');
T::false($cn->isWellFormed('TK000001234Q77'), '多一位不通过');
T::false($cn->isWellFormed('XY000001234Q7'), '别家的前缀不通过');
T::false($cn->isWellFormed('TK0000ABCD4Q7'), '顺序号必须是数字');

// 结构合法 ≠ 卡存在。真伪只有库存表说了算，这里不做也做不到判断。
T::true($cn->isWellFormed('TK999999994Q7'),
    '★ 结构合法但库里没有 —— 这一层只挡垃圾输入，不判真伪');

T::group('实体卡号 · 手工输入的容错');

$printed = $cn->format($c);                       // TK-00000123-4Q7
T::true($cn->isWellFormed($printed), '带连字符的印刷形式可直接输入');
T::true($cn->isWellFormed(strtolower($printed)), '小写也认');
T::true($cn->isWellFormed(" {$printed} "), '首尾空格不影响');
T::true($cn->isWellFormed(str_replace('-', ' ', $printed)), '用空格分组也认');
T::eq($c, CardNumber::normalize($printed), '归一化后与原始卡号一致');

/**
 * 字母表里没有 I/L/O/U，所以能放心把它们映射回数字而不会撞车。
 * 收银员照着卡面把 0 读成 O、1 读成 I 是常事。
 */
T::eq('0', CardNumber::normalize('O'), 'O → 0');
T::eq('1', CardNumber::normalize('I'), 'I → 1');
T::eq('1', CardNumber::normalize('L'), 'L → 1');
T::eq('V', CardNumber::normalize('U'), 'U → V');
T::eq('TK000001234Q7', CardNumber::normalize('tk-OOOOOI23-4q7'),
    '★ 混合误读也能纠正回来');

T::group('实体卡号 · 边界与拒绝');

T::true($cn->isWellFormed($cn->make(0)), '顺序号 0 可用');
T::true($cn->isWellFormed($cn->make(99999999)), '顺序号上限 8 位可用');

foreach ([[-1, '负数'], [100000000, '超出 8 位']] as [$bad, $why]) {
    $threw = false;
    try { $cn->make($bad); } catch (\InvalidArgumentException $e) { $threw = true; }
    T::true($threw, "{$why}的顺序号拒绝生成");
}

foreach ([['T-K', '带连字符'], ['', '空'], ['TOOLONG', '超过 4 位']] as [$bad, $why]) {
    $threw = false;
    try { new CardNumber($bad); } catch (\InvalidArgumentException $e) { $threw = true; }
    T::true($threw, "{$why}的前缀被拒绝");
}

$threw = false;
try { $cn->make(1, 'ab'); } catch (\InvalidArgumentException $e) { $threw = true; }
T::true($threw, '后缀位数不对时拒绝');

T::eq(null, $cn->serialOf('TK'), '结构不合法时取不到顺序号');
T::eq(null, $cn->suffixOf('TK'), '结构不合法时取不到后缀');

T::group('实体卡号 · 前缀不能含 I / L / O / U');

/**
 * ★ 这一条来自一次实测：换个前缀，整套实体卡功能当场全废。
 *
 *   normalize() 会把印刷体下容易读错的字符映射回去（O→0、I→1、L→1、U→V），
 *   而它作用于【整个输入串，包括前缀】。前缀含这几个字母时：
 *
 *       prefix=VIP   make()=VIP00000123Q7X   normalize()=V1P00000123Q7X  ❌
 *       prefix=GOLD  make()=GOLD00000123Q7X  normalize()=G01D00000123Q7X ❌
 *       prefix=CLUB  make()=CLUB00000123Q7X  normalize()=C1VB00000123Q7X ❌
 *       prefix=OK    make()=OK00000123Q7X    normalize()=0K00000123Q7X   ❌
 *
 *   isWellFormed() 里的 str_starts_with($n, $this->prefix) 恒为假 ——
 *   【自己生成的卡号被自己判为非法】。/card/lookup、/card/status、
 *   /member/create 全部返回 card_malformed；CardRepo::findByCardNo() 拿
 *   归一化后的串去查、generateBatch() 存的是未归一化的串，永远查不到。
 *
 *   ★ 原来这个文件只测了 new CardNumber('TK') —— 恰好是安全字符，
 *     所以一直是绿的。而 VIP 恰恰是这套系统最可能被填的前缀
 *     （前端桥接文件就叫 sushivip-bridge.js）。
 *
 *   ★ 按 docs/10 的上线步骤，发卡是最后一步 ——
 *     不在配置阶段拦住的话，卡已经印出来了才会发现。
 */
foreach (['VIP', 'GOLD', 'CLUB', 'OK', 'ILO', 'U'] as $bad) {
    $threw = false;
    try {
        new CardNumber($bad);
    } catch (\InvalidArgumentException $e) {
        $threw = str_contains($e->getMessage(), 'I / L / O / U');
    }
    T::true($threw, "★★★ 前缀 {$bad} 在构造时就被拒（不是等到发卡那天）");
}

// 安全字符照常可用，而且【自己生成的卡号自己认得】
foreach (['TK', 'SV', 'MK', 'ABCD'] as $good) {
    $g = new CardNumber($good);
    $no = $g->make(123, '4Q7');
    T::true($g->isWellFormed($no), "前缀 {$good} 可用，且 make() 的结果过得了 isWellFormed()");
    T::eq($no, CardNumber::normalize($no), "  └ 归一化之后一个字符都不变（{$no}）");
}

/**
 * ★ 反过来钉住 normalize() 那张映射表没被悄悄改动 ——
 *   它是「收银员照着卡面手输也能查到」的全部依据。
 */
T::eq('V1101', CardNumber::normalize('UIL-O1'),
    'normalize 仍然是 U→V、I→1、L→1、O→0，并去掉连字符（UIL-O1 → V1101）');

/**
 * ★ diag.php 里抄了一份同样的规则（它不加载 autoloader —— 那正是它的价值：
 *   什么都坏了它还能跑）。抄了就会漂，所以在这里对一眼。
 */
$diagSrc = (string)file_get_contents(dirname(__DIR__, 2) . '/bin/diag.php');
T::true(str_contains($diagSrc, 'ILOU'),
    '★★ bin/diag.php 也查 card_prefix 里的 I/L/O/U —— 上线前就该发现，而不是发卡那天');
T::true(str_contains($diagSrc, 'card_prefix'), '  └ 并且是对着 config.card_prefix 查的');
