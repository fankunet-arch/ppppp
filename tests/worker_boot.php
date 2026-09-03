<?php
/**
 * 并发工作进程的共同引导 —— 被 tests/*_worker.php 各自 require。
 *
 * ── 🔴 为什么不直接 require app/bootstrap.php ─────────────────
 *
 * bootstrap.php 在缺 app/config/config.php 时会往 STDERR 写一句话然后
 * exit(1)。而调用方（smoke）是用 popen 读【标准输出】收结果的，
 * 并且把 stderr 丢掉了 —— 于是那句话谁也看不到，
 * 工作进程只是「什么都没输出」，smoke 把它解析成 0 次成功，
 * 最后报出来的是「期望 320 单，实际 0 单」。
 *
 * 那条断言守的是加锁顺序，而人看到的是一个和加锁毫无关系的数字。
 * 现场排查会从死锁一路查下去，真正的原因（没有配置文件）
 * 一个字都没提。
 *
 * 所以工作进程【永远只往标准输出写一行】，并把失败原因写在第三个字段上，
 * 由 smoke 原样带进断言文案里。
 *
 * 这些进程需要的连接信息全部来自环境变量（SMOKE_DB_*），
 * config.php 只提供时区与自动加载，缺了也能跑 —— 就地补上即可。
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/** 出错时按约定格式吐一行再退出 —— 三个字段，第三个是给人看的原因 */
function worker_die(string $reason): never
{
    echo "0 0 {$reason}\n";
    exit(0);
}

$__cfg = __DIR__ . '/../app/config/config.php';
if (is_file($__cfg)) {
    require __DIR__ . '/../app/bootstrap.php';
} else {
    // 没有 config.php 也要能跑：自己装上自动加载与时区，
    // 连接信息本来就是从环境变量来的。
    spl_autoload_register(static function (string $class): void {
        if (!str_starts_with($class, 'Vip\\')) {
            return;
        }
        $f = __DIR__ . '/../app/lib/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($f)) {
            require $f;
        }
    });
    date_default_timezone_set(getenv('SMOKE_TZ') ?: 'Europe/Madrid');
}

if ((getenv('SMOKE_DB_NAME') ?: '') === '' || (getenv('SMOKE_DB_USER') ?: '') === '') {
    worker_die('没拿到 SMOKE_DB_NAME/SMOKE_DB_USER（父进程没把环境变量传下来）');
}

/** 工作进程统一的容器构造 */
function worker_app(): Vip\App
{
    return new Vip\App([
        'store_code' => getenv('SMOKE_STORE') ?: 'SMOKE',
        'local_db'   => [
            'host'     => getenv('SMOKE_DB_HOST') ?: '127.0.0.1',
            'port'     => (int)(getenv('SMOKE_DB_PORT') ?: 3306),
            'database' => getenv('SMOKE_DB_NAME') ?: '',
            'user'     => getenv('SMOKE_DB_USER') ?: '',
            'password' => getenv('SMOKE_DB_PASS') ?: '',
            'charset'  => 'utf8mb4',
        ],
        'pos_db' => [],
    ]);
}
