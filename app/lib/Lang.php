<?php
declare(strict_types=1);

namespace Vip;

/**
 * 界面语言 —— 只管「哪一种」，不管具体文案。
 *
 * 支持的语言写死在这里，而不是做成可配置的：多一种语言就要多一整套
 * 翻译（服务端错误文案 + 前端词典 + 测试），不是改个配置就能长出来的。
 * 硬编码反而诚实 —— 加语言必须改代码，也就必然会想起来去补翻译。
 */
final class Lang
{
    public const ZH = 'zh';
    public const ES = 'es';

    /** 顺序即前端语言切换器里的显示顺序 */
    public const ALL = [
        self::ZH => '中文',
        self::ES => 'Español',
    ];

    public const FALLBACK = self::ZH;

    public static function isValid(?string $v): bool
    {
        return $v !== null && isset(self::ALL[$v]);
    }

    /** 收敛成一个受支持的语言码；认不出就回落 */
    public static function normalize(?string $v, string $fallback = self::FALLBACK): string
    {
        if ($v === null) {
            return $fallback;
        }
        // 容忍 zh-CN / es-ES / ES 这类写法 —— 浏览器与容器给的值五花八门
        $v = strtolower(trim($v));
        $v = explode('-', explode('_', $v)[0])[0];
        return self::isValid($v) ? $v : $fallback;
    }
}
