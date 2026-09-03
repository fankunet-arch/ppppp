<?php
/**
 * 手工录入日额度的并发工作进程 —— 只被 tests/smoke.php ㊾ 调用。
 *
 * ★ 单独一个文件的理由和 deadlock_worker.php / reward_worker.php 一样：
 *   这条断言【只有真并发才测得出来】。单进程顺序调用 manualGrant()
 *   永远读得到上一笔的结果，怎么跑都是绿的。
 *
 * 它守的是什么（审计 F14）：日累计额度的「读一下再写」。
 * manual_entry_daily_cap 原来在事务【外面】读 manualAmountSince 判累计，
 * 然后才进事务写流水。两台 Pad 同时提交时各自读到「今天用了 0」、
 * 各自判「没超」、各自写进去 —— 上限 € 300 被撑成 € 600。
 * 与 checkAndGrant 那次「四个进程发出四张券」是同一个形状。
 *
 * 用法（由 smoke 传参，人不用手工跑）：
 *   php tests/manual_cap_worker.php <会员id> <每笔金额分> <笔数> <对齐时刻>
 * 输出一行：成功笔数 空格 被上限拦下的笔数
 */
declare(strict_types=1);

require __DIR__ . '/worker_boot.php';

$memberId = (int)($argv[1] ?? 0);
$cents    = (int)($argv[2] ?? 0);
$count    = (int)($argv[3] ?? 0);
$startAt  = (float)($argv[4] ?? 0);
if ($memberId <= 0 || $cents <= 0 || $count <= 0) {
    worker_die('参数不对：usage: manual_cap_worker.php <member_id> <cents> <count> <start_at>');
}

$app = worker_app();

// ★ 同一个操作员 id —— 额度就是按操作员算的，换了人这条断言什么也测不出
$op = ['id' => 1, 'name' => '冒烟并发', 'device' => 'MCW', 'approved_by' => 1];

while (microtime(true) < $startAt) {
    usleep(200);
}

$ok = 0;
$capped = 0;
for ($i = 0; $i < $count; $i++) {
    try {
        $r = $app->points()->manualGrant($memberId, $cents, 'system_not_found', $op);
        if ($r['ok'] ?? false) {
            $ok++;
        } elseif (($r['error'] ?? '') === 'exceeds_manual_daily_cap') {
            $capped++;
        }
    } catch (\Throwable $e) {
        // 锁等待超时等一律算「没记进去」，不影响本段要守的那条
    }
}

echo $ok . ' ' . $capped . "\n";
