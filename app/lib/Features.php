<?php
declare(strict_types=1);

namespace Vip;

/**
 * 后台需要长期挂出来的提醒。
 *
 * 这里管的是「开了某个功能，但它依赖的东西还没就绪」这类状态 ——
 * 它们不会自己暴露：客人收不到确认码，积分默默冻结着，
 * 等有人来投诉才发现，而那时可能已经过去几个月。
 */
final class Features
{
    /**
     * 确认码的出站发送是否已实现。
     *
     * 曾经这里是 false，因为 routes.php 里只有一个 TODO。现在
     * Vip\Service\Messaging 已经实现（Twilio 短信 + SMTP 邮件），
     * 所以是 true —— 剩下的是「配没配凭据」，那由 Messaging::ready() 判断。
     *
     * 保留这个常量是为了把两件事分开：代码写没写（这里），
     * 与凭据配没配（config.php）。用配置去判断前者会给出错误的安全感 ——
     * 早先就是这样：凭据填齐了，发送代码没写，一样一封发不出去。
     */
    public const OUTBOUND_MESSAGING = true;

    /**
     * @param bool  $collectPii     是否开启了收集联系方式
     * @param array $readyChannels  已配齐凭据的渠道（Messaging::readyChannels()）
     * @param bool  $countOncePerPeriod 计次口径是不是「一人一餐期一次」
     * @param int   $mealPeriodCount    库里配了几个餐期
     * @return array<int, array{key:string, level:string, text:string}>
     */
    public static function warnings(bool $collectPii, array $readyChannels = [],
                                    bool $countOncePerPeriod = false, int $mealPeriodCount = 0): array
    {
        $out = [];

        /**
         * 计次按餐期算，却一个餐期都没配 —— 这会【静默地把规则改严】。
         *
         * MealPeriod 查不到餐期时退回「同一营业日」这个更粗的口径
         * （不这么退的话，没配餐期的店连合并都用不了）。于是：
         *   本意：中午来一次、晚上来一次 = 2 次
         *   实际：一天不管来几趟 = 1 次
         *
         * 客人少拿一半的次数，而且没有任何地方会报错 ——
         * 等客人来问「我明明来了两回」才发现，那时已经过去几个月。
         * 所以挂在顶栏上，直到配好为止。
         */
        if ($countOncePerPeriod && $mealPeriodCount === 0) {
            $out[] = [
                'key'   => 'meal_period_missing',
                'level' => 'error',
                'text'  => '计次口径是「一人一餐期一次」，但一个餐期都没配 —— '
                         . '系统只能退回按【整个营业日】算，于是「中午来一次、晚上又来一次」'
                         . '会被当成同一顿，客人少拿一半的次数。'
                         . '请到「配置」里补上餐期（例：白天 11:00–18:00、晚上 19:30–次日 02:00）。',
            ];
        }

        if ($collectPii && !self::OUTBOUND_MESSAGING) {
            $out[] = [
                'key'   => 'sms_not_implemented',
                'level' => 'error',
                'text'  => '已开启「收集客人联系方式」，但确认码的发送功能尚未实现。',
            ];
            return $out;   // 代码都没写，再提凭据没意义
        }

        if ($collectPii && !$readyChannels) {
            $out[] = [
                'key'   => 'channel_not_configured',
                'level' => 'error',
                'text'  => '已开启「收集客人联系方式」，但短信与邮件都还没配置凭据 —— '
                         . '客人收不到确认码，积分会一直冻结、无法兑换。'
                         . '请在 app/config/config.php 里填好 sms 或 mail 的凭据，'
                         . '或先把该开关关闭。',
            ];
        }

        return $out;
    }
}
