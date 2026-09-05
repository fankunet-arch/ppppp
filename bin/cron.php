<?php
declare(strict_types=1);

/**
 * ════════════════════════════════════════════════════════════════
 * 定时任务入口（CLI）
 *
 * ★ 本文件在 /bin，不在 /wwwroot —— 绝不能从网络访问。
 *
 * 用法：
 *   php bin/cron.php incremental     增量补抓（营业时间，每 15–30 分钟）
 *   php bin/cron.php nightly         夜间全套（03:00–05:00）
 *   php bin/cron.php verify          仅值比对冲正
 *   php bin/cron.php integrity       仅数据完整性监控
 *   php bin/cron.php menu-audit      仅套餐规则表巡检
 *   php bin/cron.php compliance      仅合规到期处理
 *   php bin/cron.php status          打印水位线与未处理告警
 *
 * crontab 建议见 bin/crontab.example
 * （实测 02:00–10:00 全时段 0 单，03:00–05:00 是唯一绝对安全的窗口）
 * ════════════════════════════════════════════════════════════════
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("本脚本只能从命令行执行\n");
}

$task    = $argv[1] ?? '';
$verbose = in_array('-v', $argv, true) || in_array('--verbose', $argv, true);

/**
 * 位置参数（剔除脚本名、任务名与所有 -开头的选项）。
 *
 * ★ 不能直接拿 $argv[2] 当数值参数 —— 用法里写的是
 *   `php bin/cron.php <任务> [-v]`，于是 `integrity -v` 会把 "-v"
 *   当成天数，`(int)"-v"` = 0，循环一次都不跑，
 *   结果是「完整性监控报成功、却什么都没查」，
 *   这种静默失效比直接报错危险得多。
 */
$positional = array_values(array_filter(
    array_slice($argv, 2),
    static fn(string $a): bool => $a === '' || $a[0] !== '-'
));
/** 取第 n 个位置参数并转成正整数，缺省或非法时用默认值 */
$argInt = static function (int $n, int $default) use ($positional): int {
    $v = $positional[$n] ?? null;
    return ($v !== null && ctype_digit($v) && (int)$v > 0) ? (int)$v : $default;
};

$USAGE = <<<TXT
用法：php bin/cron.php <任务> [天数] [-v]

  incremental   增量补抓（营业时段，每 15–30 分钟）
  nightly       夜间全套（03:00–05:00）
  verify        仅值比对冲正
  integrity     仅完整性监控（可选天数，默认 7）
  menu-audit    仅套餐规则表巡检
  compliance    仅合规到期处理
  status        打印水位线与未处理告警

TXT;

// 用法输出不依赖配置文件，放在 bootstrap 之前
if ($task === '' || in_array($task, ['-h', '--help', 'help'], true)) {
    echo $USAGE;
    exit(0);
}

$config = require __DIR__ . '/../app/bootstrap.php';

use Vip\App;

$t0 = microtime(true);
$log = static function (string $m) use ($verbose): void {
    if ($verbose) {
        echo '[' . date('H:i:s') . '] ' . $m . "\n";
    }
};

/**
 * 并发锁 —— 上一次没跑完就被下一次叠上，会造成重复抓取与水位线错乱。
 * 用文件锁，不依赖数据库（数据库正是可能卡住的那一环）。
 *
 * ── 🔴 锁要按「抢的是哪件事」取名，不是按「哪个任务」 ──────────
 *
 * 第一版每个任务用自己的任务名当锁名，于是 nightly 和 incremental
 * 各拿各的锁 —— 而 nightly 里【第一步就是 incremental】。
 * 营业时段最后一轮 01:40 的增量补抓要是还没跑完（POS 慢、积压多，
 * 上限是 200 批），03:15 的 nightly 照样开跑，两个进程同时推同一条
 * 水位线：正是这把锁本来要防的那件事。
 * integrity / menu-audit / compliance 三个任务则【一把锁都没有】。
 *
 * 改成按资源取名，一个任务把它要碰的资源锁全拿上：
 *   sync        增量补抓（水位线）
 *   verify      值比对冲正（订单镜像与流水）
 *   maintenance 巡检 / 合规 / 完整性（只读为主，但都会打告警）
 *
 * ★ 全部 LOCK_NB、按固定顺序拿：拿不到就整轮跳过，绝不等待，
 *   所以不会有「两个 cron 各拿一半互相等」的死锁。
 *
 * @param string|string[] $names
 */
function withLock(array|string $names, callable $fn): mixed
{
    $names = (array)$names;
    sort($names);                       // 固定顺序，配合 LOCK_NB 就不会互等
    // sys_get_temp_dir() 在 Windows 下返回带反斜杠的路径，两个分隔符都剥
    $dir = rtrim(sys_get_temp_dir(), "/\\") . '/vip-cron';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }

    $held = [];
    foreach ($names as $name) {
        $fh = fopen($dir . '/' . preg_replace('/[^a-z0-9_-]/i', '_', $name) . '.lock', 'c');
        if ($fh === false) {
            foreach ($held as $h) { flock($h, LOCK_UN); fclose($h); }
            throw new RuntimeException('无法创建锁文件');
        }
        if (!flock($fh, LOCK_EX | LOCK_NB)) {
            fclose($fh);
            foreach ($held as $h) { flock($h, LOCK_UN); fclose($h); }
            echo "另一轮任务正占着「{$name}」，本次跳过\n";
            return null;
        }
        $held[] = $fh;
    }

    try {
        return $fn();
    } finally {
        foreach ($held as $h) { flock($h, LOCK_UN); fclose($h); }
    }
}

function out(string $title, array $r): void
{
    $flag = ($r['ok'] ?? true) ? 'OK ' : '!! ';
    echo $flag . str_pad($title, 22) . json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
}

$app = new App($config);

try {
    switch ($task) {

        // ── 营业时段：增量补抓 ────────────────────────────
        case 'incremental':
            withLock('sync', function () use ($app, $log): void {
                out('增量补抓', $app->sync()->incremental($log));
            });
            break;

        // ── 夜间全套（03:00–05:00）────────────────────────
        case 'nightly':
            // ★ 三把锁全拿：这一轮里 incremental / verify / 各类巡检都要跑，
            //   任何一件正被别的 cron 占着，整轮就跳过
            withLock(['sync', 'verify', 'maintenance'], function () use ($app, $log): void {
                // 顺序有讲究：先把订单补齐，再做值比对，最后才是巡检类
                out('增量补抓',   $app->sync()->incremental($log));
                out('值比对冲正', $app->reconcile()->verifyAmounts($log));
                out('完整性监控', $app->sync()->checkIntegrity(7));
                out('规则表巡检', $app->maintenance()->auditMenuRules($log));
                out('合规到期',   $app->maintenance()->expireUnconfirmedMembers($log));
                out('PII 留存', $app->maintenance()->purgeStalePii($log));
                out('会话清理',   $app->maintenance()->purgeSessions());
            });
            break;

        case 'verify':
            withLock('verify', fn() => out('值比对冲正', $app->reconcile()->verifyAmounts($log)));
            break;

        case 'integrity':
            withLock('maintenance',
                fn() => out('完整性监控', $app->sync()->checkIntegrity($argInt(0, 7))));
            break;

        case 'menu-audit':
            withLock('maintenance',
                fn() => out('规则表巡检', $app->maintenance()->auditMenuRules($log)));
            break;

        case 'compliance':
            withLock('maintenance',
                fn() => out('合规到期', $app->maintenance()->expireUnconfirmedMembers($log)));
            break;

        // ── 状态速查 ──────────────────────────────────────
        case 'status':
            echo "水位线\n";
            foreach ($app->cursors()->all() as $c) {
                $lag = (time() - strtotime((string)$c['watermark'])) / 3600;
                printf("  %-16s %s  滞后 %.1f 小时  上次 %s  状态 %s\n",
                    $c['cursor_name'], $c['watermark'], $lag,
                    $c['last_run_at'] ?? '从未', $c['last_status'] ?? '-');
                if ($lag > 72) {
                    echo "    !! 滞后超过 72 小时，Cron 可能长期未成功执行\n";
                }
            }
            $open = $app->alerts()->open(20);
            echo "\n未处理告警 " . count($open) . " 条\n";
            foreach ($open as $a) {
                printf("  [%s] %-18s %s\n",
                    ['1' => '提示', '2' => '警告', '3' => '严重'][(string)$a['severity']] ?? '?',
                    $a['alert_type'], function_exists('mb_substr')
                        ? mb_substr((string)$a['message'], 0, 70)
                        : substr((string)$a['message'], 0, 70));
            }
            break;

        default:
            fwrite(STDERR, "未知任务：{$task}\n\n" . $USAGE);
            exit(1);
    }
} catch (Throwable $e) {
    // Cron 失败必须留痕并以非 0 退出，否则会静默失效
    error_log('[cron:' . $task . '] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    fwrite(STDERR, "任务 {$task} 失败：" . $e->getMessage() . "\n");
    exit(1);
}

if ($verbose) {
    printf("耗时 %.1f 秒\n", microtime(true) - $t0);
}
exit(0);
