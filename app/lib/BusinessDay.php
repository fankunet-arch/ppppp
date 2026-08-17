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
    public function range(string $businessDate): array
    {
        $d = new \DateTimeImmutable($businessDate . ' ' . $this->cutoff . ':00');
        return [$d->format('Y-m-d H:i:s'), $d->modify('+1 day')->format('Y-m-d H:i:s')];
    }
}
