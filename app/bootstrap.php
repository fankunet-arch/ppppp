<?php
declare(strict_types=1);

/**
 * 引导文件 —— 所有入口（API / Cron / CLI）都先 require 本文件。
 */

// ── 自动加载（PSR-4 风格，命名空间 Vip\ → app/lib/）─────────
spl_autoload_register(static function (string $class): void {
    $prefix = 'Vip\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $rel  = str_replace('\\', '/', substr($class, strlen($prefix)));
    $file = __DIR__ . '/lib/' . $rel . '.php';
    if (is_file($file)) {
        require $file;
    }
});

// ── 配置 ───────────────────────────────────────────────────
$configFile = __DIR__ . '/config/config.php';
if (!is_file($configFile)) {
    fwrite(STDERR, "缺少 app/config/config.php —— 请从 config.example.php 复制并填写。\n");
    exit(1);
}
/** @var array $VIP_CONFIG */
$VIP_CONFIG = require $configFile;

date_default_timezone_set($VIP_CONFIG['timezone'] ?? 'Europe/Madrid');

// ── 错误处理 ───────────────────────────────────────────────
// 生产环境不向客户端吐堆栈（可能含 PII 与连接串）
$debug = (bool)($VIP_CONFIG['debug'] ?? false);
ini_set('display_errors', $debug ? '1' : '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

$logDir = $VIP_CONFIG['log_path'] ?? (__DIR__ . '/../var/log');
if (!is_dir($logDir)) {
    @mkdir($logDir, 0750, true);
}
// 两个分隔符都要剥 —— Windows 上路径可能是 C:\wwwroot\var\log\
// （PHP 在 Windows 上正斜杠也认，所以拼接统一用 '/'）
ini_set('error_log', rtrim($logDir, "/\\") . '/php-error.log');

return $VIP_CONFIG;
