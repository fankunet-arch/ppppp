<?php
declare(strict_types=1);

namespace Vip;

/**
 * 营业日计算。
 *
 * ★ 绝不能取 serial_id 的前 6 位当营业日。
 *   serial_id 的日期部分是【结账自然日】：实测 1,072 单为
 *   「前一天开台、零点后结账」，其 serial_id 归到了第二天。
 *   例：21:44 开台、00:01 结账 → serial_id = 2401270001（1月27日第1单）
 *   docs/01 §2.1 性质 B
 *
 * ★ 切点 02:00 已用 POS 自身数据验证。
 *   拿 history_major_group.day（POS 认定的营业日）逐日比对 2024 年
 *   332 个营业日：
 *      结账自然日 + actual_amount   →  48/332 一致
 *      营业日(02:00) + original_amount → 324/332 一致，全年总额差 -0.03%
 *   切点取 02:00 / 04:00 / 06:00 结果完全相同（该区间实测 0 单）。
 *   docs/01 §5.2
 */
final class BusinessDay
{
    /**
     * @param string $cutoff 形如 '02:00'，该时刻之前结账的订单归前一日
     */
    public function __construct(private string $cutoff = '02:00')
    {
    }

    /**
     * 结账时间 → 营业日
     *
     * @param string $orderEndTime 'Y-m-d H:i:s'（主库原值）
     * @return string 'Y-m-d'
     */
    public function of(string $orderEndTime): string
    {
        $dt = new \DateTimeImmutable($orderEndTime);
        [$h, $m] = array_map('intval', explode(':', $this->cutoff) + [1 => '0']);
        $cutMinutes = $h * 60 + $m;
        $minutes    = ((int)$dt->format('H')) * 60 + (int)$dt->format('i');

        if ($minutes < $cutMinutes) {
            return $dt->modify('-1 day')->format('Y-m-d');
        }
        return $dt->format('Y-m-d');
    }

    /**
     * 营业日 → [起始时刻, 结束时刻)，用于按营业日查询。
     * @return array{0:string,1:string} 'Y-m-d H:i:s'
     */
    /**
     * 一个营业日的 [起, 止) 区间。
     *
     * ── 🔴 夏令时前跳那天，切点本身可能【不存在】 ─────────
     *
     * Europe/Madrid 在春季前跳的那天，本地时间 02:00 这一刻是不存在的
     * （02:00 直接跳到 03:00）。`new DateTimeImmutable('2026-03-29 02:00:00')`
     * 会被 PHP 归一化成 03:00，于是：
     *
     *     2026-03-29 -> [03-29 03:00, 03-30 03:00)
     *     2026-03-30 -> [03-30 02:00, 03-31 02:00)
     *                          ↑ 与上一行重叠了 1 小时
     *
     * 后果：riskWatch() 直接拿 earnedInRange() 的结果统计「今天记了几次」，
     * 一年里有一天会把前一天最后一小时的流水也算进来。
     * 影响极小（计次判定后面还有 sameSitting() 兜底），但结果确实是错的。
     *
     * ★ 修法：把「+1 天」放在【UTC 时间轴】上做，再转回本地。
     *   营业日的长度是 24 小时这件事，不该被时区规则改写 ——
     *   前跳那天本地只有 23 小时，区间照样应该是首尾相接、不重不漏。
     */
    public function range(string $businessDate): array
    {
        $tz = new \DateTimeZone(date_default_timezone_get());
        $at = fn(string $date): \DateTimeImmutable
            => new \DateTimeImmutable($date . ' ' . $this->cutoff . ':00', $tz);

        /**
         * ★ 止点 = 【下一个营业日的起点】，而不是「起点 + 1 天」。
         *
         *   这样相邻两天【按构造就是首尾相接】的，不管那天有没有
         *   23 小时或 25 小时。用 +1 天（无论按日历还是按 86400 秒）
         *   都会在切换日错开一小时：起点被归一化到 03:00，
         *   止点却还落在 02:00，或者反过来。
         *
         *   前跳那天这一天只有 23 小时 —— 那是对的，本地时钟真的少了一小时。
         */
        $next = (new \DateTimeImmutable($businessDate, $tz))->modify('+1 day')->format('Y-m-d');
        return [$at($businessDate)->format('Y-m-d H:i:s'), $at($next)->format('Y-m-d H:i:s')];
    }
}
