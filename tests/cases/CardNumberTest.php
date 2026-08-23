<?php
declare(strict_types=1);

use Vip\CardNumber;

/**
 * 实体卡号 —— 顺序编号 + HMAC 校验位。
 *
 * 这一组的重点不是「能生成」，而是几条安全性质：
 *   · 拿到一张卡，推算不出邻居卡号（顺序编号的固有风险，靠校验位挡）
 *   · 输错任何一位都能立刻发现（否则收银员会以为是卡的问题去翻库存）
 *   · 换了密钥，旧卡全部失效（所以密钥必须离线备份，测试里钉死这个事实）
 */

$SECRET = 'test-secret-至少十六字节-abcdef';
$cn = new CardNumber('TK', $SECRET);

T::group('实体卡号 · 生成与格式');

$c1 = $cn->make(1);
$c2 = $cn->make(123);
T::eq(14, strlen($c1), '长度 = 前缀2 + 顺序8 + 校验4 = 14');
T::true(str_starts_with($c1, 'TK00000001'), "顺序号补零到 8 位（{$c1}）");
T::eq('TK-00000123-' . substr($c2, -4), $cn->format($c2), "印刷分组形式（{$cn->format($c2)}）");
T::eq(123, $cn->serialOf($c2), '能取回顺序号');

// 校验位只用 Crockford Base32 的字符，卡片上不会出现 I/L/O/U
$check = substr($c2, -4);
T::true(strspn($check, CardNumber::ALPHABET) === 4,
    "校验位只用无歧义字符集（{$check}）");

T::group('实体卡号 · 校验位挡住伪造');

T::true($cn->isValid($c2), '自己生成的卡号能通过校验');
T::false($cn->isValid('TK000001234Q7X'), '瞎编的校验位过不了');

/**
 * ★ 最要紧的一条：顺序编号意味着拿到一张真卡就知道邻居的顺序号，
 *   而那些卡确实在库存里（已印刷未发放）。校验位必须让人算不出来。
 */
$neighbor = substr($c2, 0, 10);                    // TK + 00000123 → 改成 124
$guess    = 'TK00000124' . substr($c2, -4);        // 沿用手上这张的校验位
T::false($cn->isValid($guess), '★ 拿邻居顺序号 + 手上的校验位，验不过');
T::true($cn->isValid($cn->make(124)), '而正确生成的 124 号是有效的');

// 逐位篡改：任何一位错了都必须被发现
$bad = 0;
for ($i = 0; $i < strlen($c2); $i++) {
    foreach (['0', '9', 'A', 'Z'] as $ch) {
        $t = $c2;
        if ($t[$i] === $ch) { continue; }
        $t[$i] = $ch;
        if ($cn->isValid($t)) { $bad++; }
    }
}
T::eq(0, $bad, '★ 单字符篡改一律验不过（手输错字当场能发现）');

T::group('实体卡号 · 密钥就是命根子');

$other = new CardNumber('TK', 'another-secret-十六字节以上-xyz');
T::false($other->isValid($c2),
    '★ 换了 card_hmac_secret，已印出的卡全部失效 —— 所以它必须离线备份');
T::true($other->isValid($other->make(123)), '新密钥下自己生成的仍然有效');

T::group('实体卡号 · 手工输入的容错');

$printed = $cn->format($c2);                        // TK-00000123-XXXX
T::true($cn->isValid($printed), '带连字符的印刷形式可直接输入');
T::true($cn->isValid(strtolower($printed)), '小写也认');
T::true($cn->isValid(" {$printed} "), '首尾空格不影响');
T::true($cn->isValid(str_replace('-', ' ', $printed)), '用空格分组也认');

/**
 * 字母表里没有 I/L/O/U，所以可以放心把它们映射回数字，不会撞车。
 * 收银员在卡片印刷体下把 0 看成 O 是常事。
 */
T::eq('0', CardNumber::normalize('O'), 'O → 0');
T::eq('1', CardNumber::normalize('I'), 'I → 1');
T::eq('1', CardNumber::normalize('L'), 'L → 1');
T::eq('TK000001234Q7X', CardNumber::normalize('tk-OOOOOI23-4q7x'),
    '★ 混合误读也能纠正回来');

T::group('实体卡号 · 拒绝不安全的配置');

$threw = false;
try { new CardNumber('TK', 'short'); } catch (\InvalidArgumentException $e) { $threw = true; }
T::true($threw, '★ 密钥太短直接拒绝，不给「先跑起来再说」的机会');

$threw = false;
try { new CardNumber('T-K', str_repeat('x', 32)); } catch (\InvalidArgumentException $e) { $threw = true; }
T::true($threw, '前缀必须是纯字母');

T::group('实体卡号 · 边界');

T::true($cn->isValid($cn->make(0)), '顺序号 0 可用');
T::true($cn->isValid($cn->make(99999999)), '顺序号上限 8 位可用');
$threw = false;
try { $cn->make(100000000); } catch (\InvalidArgumentException $e) { $threw = true; }
T::true($threw, '超出 8 位拒绝生成');
T::false($cn->isValid(''), '空串不是合法卡号');
T::false($cn->isValid('TK'), '只有前缀不是合法卡号');
T::eq(null, $cn->serialOf('TK000001234Q7X'), '非法卡号取不到顺序号');
