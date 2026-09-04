<?php
/**
 * 并发记账的工作进程 —— 只被 tests/smoke.php ㊱ 调用。
 *
 * ★ 单独一个文件而不是写在 smoke 里，理由和 reward_worker.php 一样：
 *   这条断言【只有真并发才测得出来】。单进程顺序调用 grant() 永远拿得到锁，
 *   怎么跑都是绿的。要复现「两台 Pad 各攥着对方要的那一行」，
 *   必须真的有两个进程。
 *
 * 它守的是什么：grantOne() 逐个锁会员行的顺序。
 * 两台 Pad 记两张 AA 单、单上是同两位客人（一对夫妻、常一起来的朋友，
 * 小店里天天有），只要点人的顺序不同就构成加锁顺序死锁 ——
 * MySQL 挑一个牺牲者整笔回滚，收银员看到「数据库不可用，请联系管理员」，
 * 而库好得很。实测不排序时 4 进程 320 单里死锁 172 次（53.8%）。
 *
 * 用法（由 smoke 传参，人不用手工跑）：
 *   php tests/deadlock_worker.php <会员id,逗号分隔> <起始订单序号> <单数> <对齐时刻>
 * 输出一行：成功数 空格 死锁数
 */
declare(strict_types=1);

require __DIR__ . '/worker_boot.php';

$memberIds = array_values(array_filter(array_map('intval', explode(',', (string)($argv[1] ?? '')))));
$base      = (int)($argv[2] ?? 0);
$count     = (int)($argv[3] ?? 0);
$startAt    = (float)($argv[4] ?? 0);
if (!$memberIds || $count <= 0) {
    worker_die('参数不对：usage: deadlock_worker.php <ids> <base> <count> <start_at>');
}

$app = worker_app();

$op    = ['id' => 1, 'name' => '冒烟并发', 'device' => 'DLW', 'role' => 1, 'is_manager' => true];
$alloc = [];
foreach ($memberIds as $mid) {
    $alloc[] = ['member_id' => $mid, 'amount_cents' => 2390, 'portions' => 1];
}

// 对齐起跑：几个进程必须真的同时冲上去，错开了就撞不出来
while (microtime(true) < $startAt) {
    usleep(200);
}

$ok = 0;
$deadlocks = 0;
for ($i = 0; $i < $count; $i++) {
    try {
        $r = $app->points()->grant(sprintf('88%08d', $base + $i), $alloc,
                                   Vip\PointsEngine::MODE_SPLIT, $op);
        if ($r['ok'] ?? false) { $ok++; }
    } catch (\PDOException $e) {
        if ((int)($e->errorInfo[1] ?? 0) === 1213) { $deadlocks++; }
    } catch (\Throwable $e) {
        // 其余异常不属于本段关心的范围，计入失败即可
    }
}


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
echo $ok . ' ' . ($deadlocks + Vip\LocalDb::$deadlockReplays) . "\n";
