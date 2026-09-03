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
 * ── 🔴 它还守着第二件事：不能和【正常记账】死锁 ──────────────
 *
 * 第一版的互斥是 `SELECT … FOR UPDATE` 锁流水的区间。实测（同一操作员，
 * 2 个手工进程 + 2 个记账进程各 40 笔）160 笔里有 5–7 笔冲破了
 * LocalDb 的两次重试，在柜台上表现为「数据库繁忙」。
 * InnoDB 的记录说得很清楚：gap 锁彼此不冲突，两笔手工录入都拿得到
 * 同一个 gap，然后各自要往里 INSERT，互相等对方的 gap 锁。
 * 改成 manual_entry_lock 上的【单行】X 锁之后 160/160 全过、0 死锁。
 *
 * 所以这个进程支持两种模式，由 smoke 混着开 —— 只跑手工那一种
 * 复现不出上面那个环。
 *
 * 用法（由 smoke 传参，人不用手工跑）：
 *   php tests/manual_cap_worker.php <manual|grant> <会员id> <每笔金额分> <笔数> <对齐时刻> [订单号前缀]
 * 输出一行：成功笔数 空格 逃逸的死锁数 [失败原因]
 */
declare(strict_types=1);

require __DIR__ . '/worker_boot.php';

$kind     = (string)($argv[1] ?? '');
$memberId = (int)($argv[2] ?? 0);
$cents    = (int)($argv[3] ?? 0);
$count    = (int)($argv[4] ?? 0);
$startAt  = (float)($argv[5] ?? 0);
// grant 模式下用哪一段订单号（由 smoke 造好夹具再传进来）
$prefix   = (string)($argv[6] ?? '860000');
if (!in_array($kind, ['manual', 'grant'], true) || $memberId <= 0 || $cents <= 0 || $count <= 0) {
    worker_die('参数不对：usage: manual_cap_worker.php <manual|grant> <member_id> <cents> <count> <start_at>');
}

$app = worker_app();

// ★ 同一个操作员 id —— 额度就是按操作员算的，换了人这条断言什么也测不出；
//   死锁那一条也一样：两条路要撞上，得是同一个操作员。
$op = ['id' => 1, 'name' => '冒烟并发', 'device' => 'MCW', 'approved_by' => 1,
       'role' => 2, 'is_manager' => true];

while (microtime(true) < $startAt) {
    usleep(200);
}

$ok = 0;
$dead = 0;
for ($i = 0; $i < $count; $i++) {
    try {
        $r = $kind === 'manual'
            ? $app->points()->manualGrant($memberId, $cents, 'system_not_found', $op)
            : $app->points()->grant(sprintf('%s%04d', $prefix, $i),
                  [['member_id' => $memberId, 'amount_cents' => $cents, 'portions' => 1]],
                  Vip\PointsEngine::MODE_SPLIT, $op);
        if ($r['ok'] ?? false) { $ok++; }
    } catch (\PDOException $e) {
        // ★ 冲破了 LocalDb 两次重试的死锁/锁等待 —— 柜台上就是「数据库繁忙」
        if (in_array((int)($e->errorInfo[1] ?? 0), [1213, 1205], true)) { $dead++; }
    } catch (\Throwable $e) {
        // 其余异常不属于本段关心的范围
    }
}

echo $ok . ' ' . $dead . "\n";
