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

require __DIR__ . '/worker_boot.php';

$memberId = (int)($argv[1] ?? 0);
$startAt  = (float)($argv[2] ?? 0);
if ($memberId <= 0) {
    worker_die('参数不对：usage: reward_worker.php <member_id> <start_at>');
}

$app = worker_app();

// 预热：把连接与服务装配都做完，免得对齐之后还在各干各的
$app->localDb()->value('SELECT 1');
$app->rewards();

// 自旋到同一时刻 —— usleep 的精度足够，几毫秒的抖动不影响结论
while (microtime(true) < $startAt) {
    usleep(200);
}

$r = $app->rewards()->checkAndGrant($memberId, ['id' => 1, 'name' => '并发冒烟', 'device' => 'SMOKE']);
// 三字段约定：发出的张数、死锁次数、失败原因，与另两个工作进程一致

/**
 * ── 🔴 只数「冒出来的死锁」是不够的 ─────────────────────────
 *
 * 这里原来只统计【逃出重试、抛到调用方】的死锁。而 LocalDb::transaction
 * 会自动回滚重放，于是「内部每一轮都死锁、重放之后结果又对了」这种局面
 * 在断言眼里是满分。
 *
 * 那不是学术问题：实测「同一张单上一边核销、一边撤销」在改加锁顺序之前
 * 【每一轮都死锁】，结果却次次正确 —— 因为重放会重新读账，把两条路
 * 各读一份旧账、各退各的那个错悄悄改对了。于是既看不出死锁，
 * 也看不出竞态，两头都被盖住。
 *
 * 所以第二个字段改成【逃出来的 + 内部重放的】。判据是「一次都不该有」：
 * 固定加锁顺序是第一道，重试只是兜底（docs/13 §3.4）。
 */
echo (int)($r['granted'] ?? 0), ' ', Vip\LocalDb::$deadlockReplays, "\n";
