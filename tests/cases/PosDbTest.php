<?php
/**
 * PosDb —— 主库不可用时【必须】归到 PosUnavailable。
 *
 * ── 🔴 为什么值得单独一个用例文件 ────────────────────────────
 *
 * PHP 8.1 起 mysqli 的默认报错模式是
 * MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT —— 连不上、查询超时一律抛
 * mysqli_sql_exception，而不是返回 false。原来 PosDb 里写的是
 * 「返回 false 就 throw new PosUnavailable」，于是那两句是【死代码】，
 * 一次都执行不到（`@` 压得住 warning，压不住异常）。
 *
 * 后果不是「错误码不好看」，是一整条链断掉：
 *   POS 断线 → locate() 的 catch (PosUnavailable) 抓不到
 *            → HTTP 500 server_error
 *            → Pad 只在 error === 'pos_unavailable' 时才显示手工录入入口
 *            → 收银员根本看不到那个按钮。
 * POS 一断线，收银台不是「降级」，是整个不能用 ——
 * 而手工录入这条降级通道正是为这一刻准备的（docs/03 §10）。
 * 夜间两条 cron 同样中招：pos_unreachable 告警不挂、水位线不 touch，
 * 整轮异常退出（实测「任务 incremental 失败：Connection refused」）。
 *
 * ★ 这几条断言不碰任何数据库内容，只往一个【关着的端口】上连，
 *   所以又快又不依赖夹具。它守的就是「以后 PHP 再改默认值、
 *   或者有人把 try/catch 删掉」这两件事。
 */
declare(strict_types=1);

use Vip\PosDb;
use Vip\PosUnavailable;

T::group('POS 主库不可用 → PosUnavailable（收银台才看得到手工录入）');

/** 一个几乎不可能有人在听的本地端口 */
$dead = static fn(): array => [
    'host' => '127.0.0.1', 'port' => 15399, 'database' => 'nosuch',
    'user' => 'nosuch', 'password' => 'nosuch',
    'connect_timeout' => 2, 'read_timeout' => 2,
];

$caught = null;
try {
    (new PosDb($dead()))->select('SELECT 1 AS a LIMIT 1');
} catch (\Throwable $e) {
    $caught = $e;
}
T::true($caught instanceof PosUnavailable,
    '★★★ 连不上主库 → PosUnavailable（而不是 mysqli_sql_exception 冒到顶层变成 500）'
  . ($caught === null ? '（居然没抛）' : '（实际 ' . get_class($caught) . '）'));

/**
 * ★ 反向那一半：SQL 自己写坏了【不能】冒充 POS 断线。
 *   否则收银员会看到「POS 暂时连不上，请手工录入」，
 *   而真正的问题在我们的代码里 —— 方向指反了比不给码更糟
 *   （docs/13 §3.2 那条 E203 的教训）。
 */
$cfgFile = __DIR__ . '/../../app/config/config.php';
if (is_file($cfgFile)) {
    $cfg  = require $cfgFile;
    $live = $cfg['local_db'] ?? [];
    if (($live['host'] ?? '') !== '') {
        $bad = null;
        try {
            (new PosDb($live + ['connect_timeout' => 3, 'read_timeout' => 5]))
                ->select('SELECT no_such_column_here FROM member LIMIT 1');
        } catch (\Throwable $e) {
            $bad = $e;
        }
        T::true($bad !== null && !($bad instanceof PosUnavailable),
            '★★ 列不存在这类【我们自己的 SQL 错】不冒充 POS 断线'
          . ($bad === null ? '（居然没抛）' : '（实际 ' . get_class($bad) . '）'));
    }
}

/**
 * ★ 源码层再钉一道：两处都必须接住 mysqli_sql_exception。
 *   光靠上面那条行为断言的话，有人把 catch 删掉、
 *   同时 PHP 又把默认值改回不抛异常，断言会重新变绿而防线已经没了。
 */
$posSrc = file_get_contents(__DIR__ . '/../../app/lib/PosDb.php');
// ★ 先把注释剥掉：上面那段说明里就写着 mysqli_report，
//   不剥的话这条断言量到的是注释（第一版就是这么写的，当场红了）
$posSrc = (string)preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $posSrc);
T::eq(2, substr_count($posSrc, 'mysqli_sql_exception $e'),
    '★ conn() 与 select() 两处都接住了 mysqli_sql_exception');
T::false((bool)preg_match('/mysqli_report\s*\(/', $posSrc),
    '  └ 没有改全局报错模式 —— 那等于把防线钉死在「当前 PHP 的默认值」上');
