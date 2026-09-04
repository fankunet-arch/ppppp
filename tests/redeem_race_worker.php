<?php
/**
 * 并发核销 / 撤销的工作进程 —— 被 tests/smoke.php 调用。
 *
 * ★ 单独开进程而不是在 smoke 里顺序调两次：这条断言【只有真并发才测得出来】。
 *   顺序执行时第二个动作读到的已经是第一个写完的账，永远是绿的；
 *   要复现「两条路各自读到同一份旧账、各退各的」，必须真有两个进程
 *   卡在同一个时刻动手。
 *
 * 用法（由 smoke 传参，人不用手工跑）：
 *   php tests/redeem_race_worker.php redeem  <coupon_id> <serial_id> <对齐时刻>
 *   php tests/redeem_race_worker.php reverse <ledger_id>  -          <对齐时刻>
 */
declare(strict_types=1);

require __DIR__ . '/worker_boot.php';

$mode   = (string)($argv[1] ?? '');
$id     = (int)($argv[2] ?? 0);
$serial = (string)($argv[3] ?? '');
$startAt = (float)($argv[4] ?? 0);
if ($id <= 0 || !in_array($mode, ['redeem', 'reverse'], true)) {
    worker_die('参数不对：usage: redeem_race_worker.php <redeem|reverse> <id> <serial|-> <start_at>');
}

$app = worker_app();
$op  = ['id' => 1, 'name' => '并发冒烟', 'device' => 'SMOKE', 'role' => 2, 'is_manager' => true];

// 预热：连接与服务装配先做完，免得对齐之后还在各干各的
$app->localDb()->value('SELECT 1');
$app->rewards();
$app->points();

while (microtime(true) < $startAt) {
    usleep(200);
}

try {
    $r = $mode === 'redeem'
        ? $app->rewards()->redeem($id, $serial, $op, null, ['reason' => '并发冒烟'])
        : $app->points()->reverse($id, '并发冒烟', $op);
    $ok = ($r['ok'] ?? false) ? 1 : 0;
    /**
     * 三字段约定：成功与否、本进程死锁重放次数、失败原因。
     *
     * ★ 第二个字段在这里【不是保留位】。结果对不对说明不了问题：
     *   两条路各读到一份旧账、各退各的，本该退成 -1 —— 可它们恰好
     *   互相等锁成环，被 InnoDB 挑一个回滚，重放时重新读账，结果又对了。
     *   实测修复前同一组并发【每一轮都死锁】，而结果次次正确。
     *   要让并发断言有牙，就得直接盯住这个数。
     *
     * ★ 这段原来是 // 注释，里面写了「成功 + 问号 + 尖括号」——
     *   那两个字符连在一起就是 PHP 的结束标记，把后面的代码整段吃掉了。
     *   docs/13 §3.6 记着同一个坑（当年吃掉了 8 条断言而测试全绿）。
     */
    echo $ok, ' ', Vip\LocalDb::$deadlockReplays, ' ', ($ok ? '-' : (string)($r['error'] ?? '?')), "\n";
} catch (\Throwable $e) {
    echo '0 ', Vip\LocalDb::$deadlockReplays, ' ', get_class($e), ':',
         substr(str_replace("\n", ' ', $e->getMessage()), 0, 120), "\n";
}
