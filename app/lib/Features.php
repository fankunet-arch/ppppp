<?php
declare(strict_types=1);

namespace Vip;

/**
 * 尚未实现的能力开关。
 *
 * 这里放的不是「配置」，而是【代码写没写】—— 所以不能靠 config.php 判断。
 * 比如确认短信：就算把 Twilio 的凭据填齐，出站发送那段代码没写，
 * 一样一封都发不出去。用配置去判断会给出错误的安全感。
 */
final class Features
{
    /**
     * 确认短信/邮件的出站发送是否已实现。
     *
     * ★ 实现 app/api/routes.php 里那个 TODO(下一批) 之后，把这里改成 true。
     *   改了之后后台那条红色提醒会自动消失，开启实名时的拦截弹窗也不再出现。
     *
     * 为什么要有这个常量：实名功能依赖确认消息才能闭环 ——
     * 客人留了手机号却收不到确认链接，积分会永久冻结。
     * 这种「开了但用不了」的状态时间一长就会被忘掉，所以让系统自己一直提醒。
     */
    public const OUTBOUND_MESSAGING = false;

    /**
     * 后台需要长期挂出来的提醒。
     *
     * @return array<int, array{key:string, level:string, text:string}>
     */
    public static function warnings(bool $collectPii): array
    {
        $out = [];
        if ($collectPii && !self::OUTBOUND_MESSAGING) {
            $out[] = [
                'key'   => 'sms_not_ready',
                'level' => 'error',
                'text'  => '已开启「收集客人联系方式」，但确认短信/邮件尚未接入 —— '
                         . '留了手机号或邮箱的客人收不到确认链接，积分会一直冻结、无法兑换。'
                         . '请尽快接入，或先把该开关关闭。',
            ];
        }
        return $out;
    }
}
