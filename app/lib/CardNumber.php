<?php
declare(strict_types=1);

namespace Vip;

/**
 * 实体会员卡卡号 —— 顺序编号 + HMAC 校验位。
 *
 * 格式：<前缀><8位顺序号><4位校验位>，例如 TK000001234Q7X
 * 显示/印刷时分组：TK-00000123-4Q7X
 *
 * ── 为什么要校验位 ────────────────────────────────────────
 * 卡号是顺序的，方便印刷厂按序生产、也方便门店盘点。但顺序号意味着
 * 拿到一张卡就能推算出邻居卡号，而那些卡是真实存在于库存里的
 * （已印刷、尚未发放）—— 不加保护就能拿去激活。
 *
 * 校验位 = HMAC-SHA256(密钥, 前缀+顺序号) 取前 4 位 Base32。
 * 密钥只在服务器上，攻击者算不出来，猜中概率 1/32^4 ≈ 百万分之一。
 *
 * 它同时挡掉手工输入的错字：输错任何一位，校验位几乎必然对不上，
 * 立刻能提示「卡号输错了」而不是「查无此卡」——后者会让收银员
 * 以为卡有问题，去翻库存。
 *
 * ★ 校验位不是权威，只是快速门槛。真正的权威是 card 表：
 *   校验位过了，卡也必须在库存里、状态正常，才算数。
 *
 * ── 密钥一旦启用就不能更换 ────────────────────────────────
 * 换密钥 = 所有已印出的卡全部验不过。它必须：
 *   · 放在 app/config/config.php 的 card_hmac_secret（不入库、不进 git）
 *   · 单独离线备份 —— 服务器重装时丢了它，全部实体卡作废
 *
 * ── 字母表 ────────────────────────────────────────────────
 * Crockford Base32：去掉了 I / L / O / U。
 * 前三个是为了不和 1 / 0 混淆（卡片印刷字体下尤其容易），
 * U 是 Crockford 为了避免拼出脏话而排除的。
 */
final class CardNumber
{
    /** Crockford Base32，无 I/L/O/U */
    public const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    /** 顺序号位数：8 位够 1 亿张，不会有换格式的一天 */
    public const SERIAL_LEN = 8;

    /** 校验位位数 */
    public const CHECK_LEN = 4;

    private string $prefix;
    private string $secret;

    public function __construct(string $prefix, string $secret)
    {
        $prefix = strtoupper(trim($prefix));
        if ($prefix === '' || !preg_match('/^[A-Z]{1,4}$/', $prefix)) {
            throw new \InvalidArgumentException('卡号前缀必须是 1~4 位字母');
        }
        if (strlen($secret) < 16) {
            // 短密钥等于没有 —— 直接拒绝，不给「先跑起来再说」的机会
            throw new \InvalidArgumentException('card_hmac_secret 至少 16 字节，且必须离线备份');
        }
        $this->prefix = $prefix;
        $this->secret = $secret;
    }

    /** 由顺序号生成完整卡号 */
    public function make(int $serial): string
    {
        if ($serial < 0 || $serial > 99999999) {
            throw new \InvalidArgumentException('顺序号超出范围');
        }
        $body = $this->prefix . str_pad((string)$serial, self::SERIAL_LEN, '0', STR_PAD_LEFT);
        return $body . $this->check($body);
    }

    /**
     * 校验卡号。只看格式与校验位，不查库 ——
     * 库存与状态由 CardRepo 负责，两道关分开，职责清楚。
     */
    public function isValid(string $cardNo): bool
    {
        $n = self::normalize($cardNo);
        $expectLen = strlen($this->prefix) + self::SERIAL_LEN + self::CHECK_LEN;
        if (strlen($n) !== $expectLen) {
            return false;
        }
        if (!str_starts_with($n, $this->prefix)) {
            return false;
        }
        $body  = substr($n, 0, strlen($this->prefix) + self::SERIAL_LEN);
        $given = substr($n, -self::CHECK_LEN);
        if (!ctype_digit(substr($body, strlen($this->prefix)))) {
            return false;
        }
        // 定长比较用 hash_equals：卡号校验不是高频路径，但没理由留时序侧信道
        return hash_equals($this->check($body), $given);
    }

    /** 取出顺序号；卡号非法时返回 null */
    public function serialOf(string $cardNo): ?int
    {
        if (!$this->isValid($cardNo)) {
            return null;
        }
        $n = self::normalize($cardNo);
        return (int)substr($n, strlen($this->prefix), self::SERIAL_LEN);
    }

    /**
     * 归一化：去掉分组用的连字符与空格，统一大写，
     * 并把印刷体上容易被人读错的字符纠正回来。
     *
     * 收银员手工输入时把 O 打成 0、I 打成 1 是常态。字母表里既然
     * 没有 I/L/O/U，就可以放心地把它们映射成对应数字，而不会撞车。
     */
    public static function normalize(string $s): string
    {
        $s = strtoupper(preg_replace('/[\s\-]+/', '', trim($s)) ?? '');
        return strtr($s, ['O' => '0', 'I' => '1', 'L' => '1', 'U' => 'V']);
    }

    /** 印刷/显示用的分组形式：TK-00000123-4Q7X */
    public function format(string $cardNo): string
    {
        $n = self::normalize($cardNo);
        $p = strlen($this->prefix);
        if (strlen($n) !== $p + self::SERIAL_LEN + self::CHECK_LEN) {
            return $n;
        }
        return substr($n, 0, $p) . '-'
             . substr($n, $p, self::SERIAL_LEN) . '-'
             . substr($n, $p + self::SERIAL_LEN);
    }

    private function check(string $body): string
    {
        $mac = hash_hmac('sha256', $body, $this->secret, true);
        $out = '';
        for ($i = 0; $i < self::CHECK_LEN; $i++) {
            $out .= self::ALPHABET[ord($mac[$i]) & 31];
        }
        return $out;
    }
}
