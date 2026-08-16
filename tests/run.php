<?php
declare(strict_types=1);

/**
 * 极简测试运行器 —— 不引入 PHPUnit，保持门店服务器零依赖部署。
 *   php tests/run.php
 */

spl_autoload_register(static function (string $class): void {
    $prefix = 'Vip\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $file = __DIR__ . '/../app/lib/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

final class T
{
    public static int $pass = 0;
    public static int $fail = 0;
    private static string $group = '';

    public static function group(string $g): void
    {
        self::$group = $g;
        echo "\n\033[1m$g\033[0m\n";
    }

    public static function eq(mixed $expected, mixed $actual, string $msg): void
    {
        if ($expected === $actual) {
            self::$pass++;
            echo "  \033[32m✓\033[0m $msg\n";
        } else {
            self::$fail++;
            echo "  \033[31m✗\033[0m $msg\n";
            echo "      期望: " . var_export($expected, true) . "\n";
            echo "      实际: " . var_export($actual, true) . "\n";
        }
    }

    public static function true(bool $v, string $msg): void
    {
        self::eq(true, $v, $msg);
    }

    public static function false(bool $v, string $msg): void
    {
        self::eq(false, $v, $msg);
    }

    public static function summary(): int
    {
        $total = self::$pass + self::$fail;
        echo "\n" . str_repeat('─', 60) . "\n";
        if (self::$fail === 0) {
            echo "\033[32m全部通过\033[0m  $total 项断言\n";
            return 0;
        }
        echo "\033[31m失败 " . self::$fail . "\033[0m / 共 $total 项断言\n";
        return 1;
    }
}

require __DIR__ . '/cases/MoneyTest.php';
require __DIR__ . '/cases/PointsEngineTest.php';
require __DIR__ . '/cases/BusinessDayTest.php';

exit(T::summary());
