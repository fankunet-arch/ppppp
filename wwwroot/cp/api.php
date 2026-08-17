<?php
declare(strict_types=1);

/**
 * 管理平台 API 入口。
 * 与 Pad 的 /api.php 同构：只做引导、取路径、派发，业务逻辑全在 /app。
 */

$config = require __DIR__ . '/../../app/bootstrap.php';

use Vip\App;
use Vip\Http\Api;

$app = new App($config);

$path = (string)($_SERVER['PATH_INFO'] ?? '');
if ($path === '') {
    $uri  = parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '';
    $path = preg_replace('#^.*?/cp/api(?:\.php)?#', '', $uri) ?: '';
}
$path = '/' . trim($path, '/');

// 顶层兜底：路由注册阶段的异常也要产出 JSON，绝不返回空响应体
try {
    /** @var Api $api */
    $api = require __DIR__ . '/../../app/cp/routes.php';
    $api->dispatch((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'), $path);
} catch (\PDOException $e) {
    error_log('[cp:boot] db: ' . $e->getMessage());
    Api::fail('db_unavailable', 503);
} catch (\Throwable $e) {
    error_log('[cp:boot] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    Api::fail('server_error', 500);
}
