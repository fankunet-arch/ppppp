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
