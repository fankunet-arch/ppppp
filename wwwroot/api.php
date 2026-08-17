<?php
declare(strict_types=1);

/**
 * API 入口 —— /wwwroot 是唯一网络可见的目录。
 *
 * 本文件只做三件事：引导、取路径、派发。
 * 全部业务逻辑在 /app（网络不可见）。
 *
 * 路由形式：/api.php/order/locate
 * 若 Web 服务器配置了重写，也可用 /api/order/locate。
 */

$config = require __DIR__ . '/../app/bootstrap.php';

use Vip\App;
use Vip\Http\Api;

$app = new App($config);

// 取出 /api.php 之后的路径部分
$path = (string)($_SERVER['PATH_INFO'] ?? '');
if ($path === '') {
    $uri  = parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '';
    $path = preg_replace('#^.*?/api(?:\.php)?#', '', $uri) ?: '';
}
$path = '/' . trim($path, '/');

// 顶层兜底：路由注册阶段的异常也要产出 JSON。
// 否则客户端只会收到空响应体的 500，无从排障。
try {
    /** @var Api $api */
    $api = require __DIR__ . '/../app/api/routes.php';
    $api->dispatch((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'), $path);
} catch (\PDOException $e) {
    error_log('[api:boot] db: ' . $e->getMessage());
    Api::fail('db_unavailable', 503);
} catch (\Throwable $e) {
    error_log('[api:boot] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    Api::fail('server_error', 500);
}
