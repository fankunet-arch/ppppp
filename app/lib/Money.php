<?php
declare(strict_types=1);

namespace Vip;

/**
 * 金额一律以【整数分】在内部流转，避免浮点误差。
 *
 * POS 主库的金额字段是 DECIMAL(11,2)，通过 mysqli 读出来是字符串。
 * 在边界处一次性转成分，出口处再转回来。中间所有运算都是整数。
 */
final class Money
{
    /** 欧元（字符串或数字）→ 整数分 */
    public static function toCents(string|int|float|null $euros): int
    {
        if ($euros === null || $euros === '') {
            return 0;
        }
        // 用字符串路径处理，绕开 (float) 的二进制误差
        $s = trim((string)$euros);
        if ($s === '' || !preg_match('/^-?\d+(\.\d+)?$/', $s)) {
            // 兜底：非预期格式走浮点并四舍五入
            return (int)round(((float)$s) * 100);
        }
        $neg = str_starts_with($s, '-');
        if ($neg) {
            $s = substr($s, 1);
        }
        $parts = explode('.', $s, 2);
        $int   = (int)$parts[0];
        $frac  = $parts[1] ?? '';
        $frac  = substr(str_pad($frac, 3, '0'), 0, 3);   // 多取一位用于四舍五入
        $cents = $int * 100 + (int)substr($frac, 0, 2);
        if ((int)$frac[2] >= 5) {
            $cents++;
        }
        return $neg ? -$cents : $cents;
    }

    /** 整数分 → 欧元字符串（两位小数，用于落库与展示） */
    public static function toStr(int $cents): string
    {
        $neg = $cents < 0;
        $c   = abs($cents);
        $s   = sprintf('%d.%02d', intdiv($c, 100), $c % 100);
        return $neg ? '-' . $s : $s;
    }

    /**
     * 按比例缩放并四舍五入（银行家舍入不适用于积分场景，用常规四舍五入）。
     * 分子分母都用整数分，避免中间产生浮点。
     */
    public static function scale(int $cents, int $numerator, int $denominator): int
    {
        if ($denominator === 0) {
            return $cents;
        }
        // 先乘后除，PHP int 是 64 位，金额量级下不会溢出
        $v = $cents * $numerator;
        $q = intdiv(abs($v), abs($denominator));
        if ((abs($v) % abs($denominator)) * 2 >= abs($denominator)) {
            $q++;
        }
        $sign = (($v < 0) !== ($denominator < 0)) ? -1 : 1;
        return $sign * $q;
    }

    /**
     * 把总额平均分成 n 份，余数分配给第一份。
     * 保证 array_sum(结果) === $cents，分毫不差。
     */
    public static function splitEvenly(int $cents, int $n): array
    {
        if ($n <= 0) {
            return [];
        }
        $base = intdiv($cents, $n);
        $rem  = $cents - $base * $n;
        $out  = array_fill(0, $n, $base);
        $out[0] += $rem;
        return $out;
    }
}
