<?php
declare(strict_types=1);

use Vip\Service\RewardService;

T::group('券的到期日 —— 必须按日历天，不能按 86400 秒');

/**
 * 客人手里那张券上印的日期，是这家店唯一的告知证据（docs/11）。
 * 少一天的后果不是算错，是客人拿着一张「还没过期」的券被拒。
 *
 * 原来的写法是 `date('Y-m-d', strtotime($now) + $validDays * 86400)`。
 * 跨夏令时切换时，N × 86400 秒与 N 个日历天差一小时 ——
 * 而这一小时足以把日期推过午夜。
 *
 * ★ 这不是理论问题：晚市餐期是 19:30–次日 02:00
 *   （db/seeds/002_meal_period.sql），**00:xx 发券是常态**。
 */

$tz = date_default_timezone_get();
date_default_timezone_set('Europe/Madrid');

// 十月回跳前：00:xx 发的券，旧写法少一天
T::eq('2026-10-27', RewardService::expiryDate('2026-07-29 00:30:00', 90),
    '★★★ 00:30 发券 + 90 天 → 10-27（旧写法给 10-26，少一天）');
T::eq('2026-10-27', RewardService::expiryDate('2026-07-29 23:30:00', 90),
    '  └ 同一天 23:30 发的券到期日相同 —— 到期日只跟【发券那天】有关，跟钟点无关');

// 三月前跳前：23:xx 发的券，旧写法多一天
T::eq('2026-04-01', RewardService::expiryDate('2026-01-01 23:30:00', 90),
    '★★ 元旦 23:30 发券 + 90 天 → 4-01（旧写法给 4-02，多一天）');

// 不跨切换的普通日子，两种写法本来就一致 —— 确认没把对的改坏
T::eq('2026-09-13', RewardService::expiryDate('2026-06-15 20:00:00', 90),
    '不跨夏令时的日子照旧');
T::eq('2026-03-27', RewardService::expiryDate('2025-12-27 23:30:00', 90),
    '跨年 + 跨切换，仍是日历天');

T::eq(null, RewardService::expiryDate('2026-06-15 20:00:00', 0),
    '0 天 = 永久有效（valid_to 存 NULL）');
T::eq(null, RewardService::expiryDate('2026-06-15 20:00:00', -5),
    '负数当成永久，不倒着算出一个过去的日期');

/**
 * 穷举：整年 × 三个发券时刻 × 三档有效期。
 * 期望值不复用被测代码 —— 直接按「年月日 + N 天」的定义算。
 */
$bad = 0; $n = 0; $firstBad = '';
foreach (['00:30:00', '23:30:00', '20:00:00'] as $t) {
    for ($i = 0; $i < 365; $i++) {
        $issued = (new DateTimeImmutable("2026-01-01 {$t}"))->modify("+{$i} days");
        foreach ([30, 90, 180] as $d) {
            $n++;
            // 期望值：只看日期部分，加 N 天 —— 与钟点、与夏令时都无关
            $want = (new DateTimeImmutable($issued->format('Y-m-d')))
                ->modify("+{$d} days")->format('Y-m-d');
            $got  = RewardService::expiryDate($issued->format('Y-m-d H:i:s'), $d);
            if ($got !== $want) {
                $bad++;
                if ($firstBad === '') {
                    $firstBad = $issued->format('Y-m-d H:i') . " +{$d}天 → {$got}，应为 {$want}";
                }
            }
        }
    }
}
T::eq(0, $bad, "★★ Madrid 全年穷举 {$n} 组，到期日恒等于「发券日 + N 个日历天」"
             . ($firstBad !== '' ? "（首个偏差：{$firstBad}）" : ''));

// 换一个南半球时区再来一遍 —— 夏令时切换方向相反
date_default_timezone_set('Pacific/Auckland');
$bad = 0; $n = 0;
foreach (['00:30:00', '23:57:00'] as $t) {
    for ($i = 0; $i < 365; $i++) {
        $issued = (new DateTimeImmutable("2026-01-01 {$t}"))->modify("+{$i} days");
        foreach ([90, 180] as $d) {
            $n++;
            $want = (new DateTimeImmutable($issued->format('Y-m-d')))
                ->modify("+{$d} days")->format('Y-m-d');
            if (RewardService::expiryDate($issued->format('Y-m-d H:i:s'), $d) !== $want) {
                $bad++;
            }
        }
    }
}
T::eq(0, $bad, "★ 南半球（Auckland，切换方向相反）穷举 {$n} 组，同样零偏差");

date_default_timezone_set($tz);
