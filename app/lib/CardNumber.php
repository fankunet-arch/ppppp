<?php
declare(strict_types=1);

namespace Vip;

/**
 * 实体会员卡卡号 —— 前缀 + 顺序号 + 随机后缀。
 *
 * 格式：<前缀><8位顺序号><3位随机码>，例如 TK000001234Q7
 * 印刷/显示时分组：TK-00000123-4Q7
 *
 * ── 为什么随机后缀不用 HMAC 算 ────────────────────────────
 * 曾经考虑过把后缀做成 HMAC(密钥, 前缀+顺序号) 的截断，好处是「不查库
 * 也能验真伪」。但这个好处在本系统里等于零：卡号无论如何都要查一次
 * card 表——因为还得知道它是未发放、已激活、还是已挂失。既然那次查询
 * 躲不掉，离线验证就没有价值。
 *
 * 而 HMAC 的代价是实打实的：一个【永远不能更换】的密钥（换了所有已印
 * 的卡当场作废）、必须独立于数据库离线备份（config.php 不进 git，
 * 常规代码备份救不了它）、丢失即全部实体卡报废且无法恢复。
 *
 * 随机后缀存库，猜中难度完全一样（都是猜 3 位 = 32768 种），
 * 备份跟着数据库走，少一个终身负担。
 *
 * ⚠️ 所以：不要"顺手加强"成 HMAC 或其他派生算法。它不会更安全，
 *    只会把一个不可恢复的单点故障塞回系统里。
 *
 * ── 顺序号 + 随机后缀的分工 ──────────────────────────────
 * 顺序号：让印刷厂按序生产、门店按序盘点、丢卡时知道断号在哪。
 * 随机后缀：挡住「拿到一张真卡就推算邻居卡号」——邻居卡确实存在于
 *          库存里（已印刷未发放），没有随机后缀就能直接拿去激活。
 *
 * ── 字母表 ────────────────────────────────────────────────
 * Crockford Base32：去掉 I / L / O / U。
 * 前三个是为了不和 1 / 0 混淆（卡片印刷字体下尤其容易），
 * U 是 Crockford 为避免拼出脏话而排除的。
 *
 * 正因为表里没有这四个字母，normalize() 才能放心把它们映射回数字
 * 而不会撞车 —— 收银员照着卡面把 0 读成 O 是常事。
 */
final class CardNumber
{
    /** Crockford Base32，无 I/L/O/U */
    public const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    /** 顺序号位数：8 位够 1 亿张，不会有换格式的一天 */
    public const SERIAL_LEN = 8;

    /** 随机后缀位数：32^3 = 32768 种 */
    public const SUFFIX_LEN = 3;

    private string $prefix;

    /**
     * @param string $prefix 卡号前缀，1~4 位字母。
     *
     * ── 🔴 前缀里不能有 I / L / O / U ────────────────────
     *
     * normalize() 会把印刷体下容易读错的字符映射回去
     * （O→0、I→1、L→1、U→V），而它作用于【整个输入串，包括前缀】。
     * 前缀如果含这几个字母，归一化之后就跟自己对不上了：
     *
     *     prefix=VIP   make()=VIP00000123Q7X   normalize()=V1P00000123Q7X  ❌
     *     prefix=GOLD  make()=GOLD00000123Q7X  normalize()=G01D00000123Q7X ❌
     *     prefix=CLUB  make()=CLUB00000123Q7X  normalize()=C1VB00000123Q7X ❌
     *
     * isWellFormed() 里的 str_starts_with($n, $this->prefix) 于是恒为假 ——
     * **自己生成的卡号被自己判为非法**。后果是 /card/lookup、/card/status、
     * /member/create 全部返回 card_malformed，而 CardRepo::findByCardNo()
     * 拿归一化后的串去查、generateBatch() 存的是未归一化的串，永远查不到。
     *
     * ★ 直接拒绝，而不是「把前缀也归一化一遍」。
     *   归一化的话 VIP 会变成 V1P 印在卡面上，客人看到的是一个
     *   莫名其妙的串；而 O/0、I/1 本来就是人眼分不清的字符 ——
     *   前缀是要被口头念、被手输的，本来就不该用它们。
     *
     * ★ 而且要在【配置阶段】就拒绝。按 docs/10 的上线步骤，
     *   发卡是最后一步 —— 不拦的话，卡已经印出来了才会发现。
     *   VIP 恰恰是这套系统最可能被填的前缀（前端桥接文件就叫 sushivip-bridge.js）。
     */
    public function __construct(string $prefix)
    {
        $prefix = strtoupper(trim($prefix));
        if (!preg_match('/^[A-Z]{1,4}$/', $prefix)) {
            throw new \InvalidArgumentException('卡号前缀必须是 1~4 位字母');
        }
        if ($prefix !== self::normalize($prefix)) {
            throw new \InvalidArgumentException(sprintf(
                '卡号前缀不能含 I / L / O / U（这几个字母在卡面上与 1 / 0 / V 分不清，'
                . '扫码纠错会把 %s 读成 %s）。请改用别的字母，例如 TK',
                $prefix, self::normalize($prefix)
            ));
        }
        $this->prefix = $prefix;
    }

    public function prefix(): string
    {
        return $this->prefix;
    }

    /** 完整卡号的字符数 */
    public function length(): int
    {
        return strlen($this->prefix) + self::SERIAL_LEN + self::SUFFIX_LEN;
    }

    /**
     * 生成卡号。
     *
     * @param int         $serial 顺序号
     * @param string|null $suffix 指定随机后缀（仅测试与重放用；正常留空）
     */
    public function make(int $serial, ?string $suffix = null): string
    {
        if ($serial < 0 || $serial > 99999999) {
            throw new \InvalidArgumentException('顺序号超出范围（0 ~ 99999999）');
        }
        $suffix ??= $this->randomSuffix();
        if (strlen($suffix) !== self::SUFFIX_LEN
            || strspn($suffix, self::ALPHABET) !== self::SUFFIX_LEN) {
            throw new \InvalidArgumentException('随机后缀格式不对');
        }
        return $this->prefix
             . str_pad((string)$serial, self::SERIAL_LEN, '0', STR_PAD_LEFT)
             . $suffix;
    }

    /** 密码学安全的随机后缀 */
    public function randomSuffix(): string
    {
        $out = '';
        $max = strlen(self::ALPHABET) - 1;
        for ($i = 0; $i < self::SUFFIX_LEN; $i++) {
            $out .= self::ALPHABET[random_int(0, $max)];
        }
        return $out;
    }

    /**
     * 结构检查：长度、前缀、字符集对不对。
     *
     * ★ 通过不代表这张卡存在 —— 真伪只有 card 表说了算。
     *   这一层的作用是在查库之前挡掉明显的垃圾输入（扫错了别的二维码、
     *   手输少打一位），并让提示语能说得更准。
     */
    public function isWellFormed(string $cardNo): bool
    {
        $n = self::normalize($cardNo);
        if (strlen($n) !== $this->length()) {
            return false;
        }
        if (!str_starts_with($n, $this->prefix)) {
            return false;
        }
        $rest = substr($n, strlen($this->prefix));
        if (!ctype_digit(substr($rest, 0, self::SERIAL_LEN))) {
            return false;
        }
        $suffix = substr($rest, self::SERIAL_LEN);
        return strspn($suffix, self::ALPHABET) === self::SUFFIX_LEN;
    }

    /** 取顺序号；结构不合法时返回 null */
    public function serialOf(string $cardNo): ?int
    {
        if (!$this->isWellFormed($cardNo)) {
            return null;
        }
        return (int)substr(self::normalize($cardNo), strlen($this->prefix), self::SERIAL_LEN);
    }

    /** 取随机后缀；结构不合法时返回 null */
    public function suffixOf(string $cardNo): ?string
    {
        if (!$this->isWellFormed($cardNo)) {
            return null;
        }
        return substr(self::normalize($cardNo), -self::SUFFIX_LEN);
    }

    /**
     * 归一化：去掉分组用的连字符与空格，统一大写，
     * 并纠正印刷体下容易读错的字符。
     */
    public static function normalize(string $s): string
    {
        $s = strtoupper(preg_replace('/[\s\-]+/', '', trim($s)) ?? '');
        return strtr($s, ['O' => '0', 'I' => '1', 'L' => '1', 'U' => 'V']);
    }

    /** 印刷/显示用的分组形式：TK-00000123-4Q7 */
    public function format(string $cardNo): string
    {
        $n = self::normalize($cardNo);
        if (strlen($n) !== $this->length()) {
            return $n;
        }
        $p = strlen($this->prefix);
        return substr($n, 0, $p) . '-'
             . substr($n, $p, self::SERIAL_LEN) . '-'
             . substr($n, $p + self::SERIAL_LEN);
    }
}
