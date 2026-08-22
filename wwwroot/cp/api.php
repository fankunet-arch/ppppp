<?php
declare(strict_types=1);

/**
 * 管理平台 API 入口。
 * 与 Pad 的 /api.php 同构：只做引导、取路径、派发，业务逻辑全在 /app。
 */

// 同 /api.php：引导失败必须是 JSON，不能是 HTML 致命错误页
require __DIR__ . '/../_boot.php';
vip_boot_require_or_json(__DIR__ . '/../../app/bootstrap.php', 'cp');

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
} catch (\Throwable $e) {
    // 统一走 bootFail：带分类码与事件号，日志与界面对得上
    Api::bootFail($e, 'cp');
}
