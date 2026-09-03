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
     * 进程级默认切点。
     *
     * ── 🔴 为什么需要它（而不是把 $today 一路传下去） ──────────
     *
     * 判「今天」的地方有一部分是【静态方法】（CardRepo::isExpired /
     * daysLeft / graceOver），它们拿不到配置。原来这些方法写的是
     * `?? date('Y-m-d')` —— 日历日，00:00 就翻页。
     *
     * 逐个调用点补传 $today 也能修，但那正是本项目反复栽的那一类
     * （docs/13 §3.1「修了那一处没修那一类」）：全仓库 9 个调用点，
     * 漏一个就等于没修，而且漏掉的那个不会有任何报错，
     * 只会在跨年夜 00:30 让某位客人的卡当场作废。
     *
     * 所以改在【默认值本身】上：App 启动时把配置里的切点登记一次，
     * 之后所有没显式传 $today 的地方自动按营业日走。
     * 只登记一个不可变的配置值，且与 businessDay() 实例同源。
     */
    private static ?\Closure $cutoffResolver = null;

    /**
     * 登记切点的【取值方式】，不是取好的值。
     *
     * ★ 存快照会过期，实测踩到过：App::cfg() 构造时读一次存起来，
     *   而那一刻 sys_config 还没写入（测试里）或后台刚改过（生产里），
     *   静态助手就一直用着旧切点，跟 businessDay() 实例说两套话。
     *   存 closure 每次现取，ConfigRepo 自己有内存缓存，开销可忽略。
     */
    public static function setDefaultCutoffResolver(callable $fn): void
    {
        self::$cutoffResolver = \Closure::fromCallable($fn);
    }

    /** 拿不到实例的地方（静态助手）问「此刻属于哪个营业日」 */
    public static function todayDefault(): string
    {
        $cutoff = self::$cutoffResolver !== null ? (string)(self::$cutoffResolver)() : '02:00';
        return (new self($cutoff !== '' ? $cutoff : '02:00'))->today();
    }

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
    /**
     * 「此刻」属于哪个营业日。
     *
     * ── 🔴 凡是「今天」参与判钱的地方，都必须走这一个方法 ──────
     *
     * 直接写 date('Y-m-d') 拿到的是【日历日】，它在 00:00 翻页；
     * 而营业日在 02:00 才翻页。晚市 00:00–02:00 这两个小时里，
     * 两者相差整整一天 —— 而那正是这家店【客人最多】的时段之一。
     *
     * 实测踩过的三处（都在客人面前当场发作）：
     *   · coupon.valid_to = 今天 → 00:30 拿出来核销 → coupon_expired，
     *     券当场被置成【已过期】写库，客人再也用不了；
     *   · expireStale() 半夜跑一遍 → 把「今天到期」的券整批判死，
     *     客人第二天中午拿来，券已经没了；
     *   · card.valid_to = 今天 → 00:30 刷卡 → card_expired，
     *     客人手上的实体卡在跨年夜那一餐当场作废。
     *
     * 三处的共同点：日期一到就【写库】或【当场拒绝】，没有第二次机会。
     * 所以宁可让它多活两小时（对客人有利），也不能早死两小时。
     */
    public function today(): string
    {
        return $this->of(date('Y-m-d H:i:s'));
    }
}
