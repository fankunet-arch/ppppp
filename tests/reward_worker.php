<?php
/**
 * 并发发券的工作进程 —— 只被 tests/smoke.php ㉖ 调用。
 *
 * ★ 单独一个文件而不是写在 smoke 里，是因为这条断言【只有真并发才测得出来】：
 *   单进程顺序调用 checkAndGrant() 走的是另一条路（第二次 pending 已经是 0），
 *   永远是绿的。要复现「两个请求同时读到 rewards_issued = 0」，
 *   必须真的有两个进程。
 *
 * 用法（由 smoke 传参，人不用手工跑）：
 *   php tests/reward_worker.php <member_id> <对齐到的 unix 浮点时刻>
 * 数据库连接从 SMOKE_DB_* 环境变量取，与 smoke 用同一个库。
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../app/bootstrap.php';

$memberId = (int)($argv[1] ?? 0);
$startAt  = (float)($argv[2] ?? 0);
if ($memberId <= 0) {
    fwrite(STDERR, "usage: reward_worker.php <member_id> <start_at>\n");
    exit(2);
}

$app = new Vip\App([
    'store_code' => getenv('SMOKE_STORE') ?: 'SMOKE',
    'local_db'   => [
        'host'     => getenv('SMOKE_DB_HOST') ?: '127.0.0.1',
        'port'     => (int)(getenv('SMOKE_DB_PORT') ?: 3306),
        'database' => getenv('SMOKE_DB_NAME') ?: '',
        'user'     => getenv('SMOKE_DB_USER') ?: '',
        'password' => getenv('SMOKE_DB_PASS') ?: '',
    ],
    'pos_db' => [],
]);

// 预热：把连接与服务装配都做完，免得对齐之后还在各干各的
$app->localDb()->value('SELECT 1');
$app->rewards();

// 自旋到同一时刻 —— usleep 的精度足够，几毫秒的抖动不影响结论
while (microtime(true) < $startAt) {
    usleep(200);
}

$r = $app->rewards()->checkAndGrant($memberId, ['id' => 1, 'name' => '并发冒烟', 'device' => 'SMOKE']);
echo (int)($r['granted'] ?? 0), "\n";
