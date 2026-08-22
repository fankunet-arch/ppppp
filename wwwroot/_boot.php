<?php
declare(strict_types=1);

/**
 * 引导前置检查 —— 这段代码必须【零依赖】。
 *
 * 为什么需要它：入口文件第一行就是 require '../app/bootstrap.php'，
 * 而它在顶层 try 的【外面】—— 一旦 /app 读不到，PHP 直接 fatal，
 * 吐出来的是 HTML 警告页，客户端拿到的不是 JSON。
 * 界面上表现为「无法连接服务」，于是所有人去查网线，
 * 真正的原因（部署目录被 PHP 挡在门外）一个字都看不见。
 *
 * 现场实例（宝塔面板）：
 *   open_basedir = /www/wwwroot/<站点>/www/wwwroot/:/tmp/:...
 *   而 app/ 在 /www/wwwroot/<站点>/www/app/ —— 恰好在允许范围外一层。
 *
 * 本文件放在 wwwroot 内（网络可见），但直接访问它不产生任何输出，
 * 它只定义函数。真正的检查由入口文件显式调用。
 */

/**
 * 纯字符串规范化路径，不碰文件系统。
 *
 * 不能用 realpath()：被 open_basedir 挡住的路径它一律返回 false，
 * 而我们恰恰需要在「被挡住」的情况下还能算出这个路径长什么样。
 */
function vip_boot_norm(string $p): string
{
    $p = str_replace('\\', '/', $p);
    $lead = '';
    if (preg_match('#^([A-Za-z]:)?/#', $p, $m)) {
        // 前缀必须从待切分的串里剥掉，否则盘符会作为普通段再拼一次
        // （C:\\w\\..\\app 会算成 C:/C:/w/app），Windows 上判定全错
        $lead = $m[0];
        $p    = substr($p, strlen($m[0]));
    }
    $out = [];
    foreach (explode('/', $p) as $seg) {
        if ($seg === '' || $seg === '.') {
            continue;
        }
        if ($seg === '..') {
            array_pop($out);
            continue;
        }
        $out[] = $seg;
    }
    return $lead . implode('/', $out);
}

/** 该路径是否落在 open_basedir 的允许范围内（未设置 open_basedir 时恒为 true）*/
function vip_boot_within_basedir(string $path): bool
{
    $base = (string)ini_get('open_basedir');
    if (trim($base) === '') {
        return true;
    }
    // Windows 上分隔符是 ';' 且路径大小写不敏感
    $ci   = DIRECTORY_SEPARATOR === '\\';
    $np   = vip_boot_norm($path);
    if ($ci) {
        $np = strtolower($np);   // 不用 mb_* —— 现场踩过 mbstring 没装
    }
    foreach (explode(PATH_SEPARATOR, $base) as $seg) {
        $ns = rtrim(vip_boot_norm(trim($seg)), '/');
        if ($ns === '') {
            continue;
        }
        if ($ci) {
            $ns = strtolower($ns);
        }
        if ($np === $ns || str_starts_with($np, $ns . '/')) {
            return true;
        }
    }
    return false;
}

/**
 * 确认引导文件真的能读；读不到就产出 JSON 并终止，绝不让它 fatal 成 HTML。
 *
 * @param string $bootstrap 引导文件绝对路径（可含 /../）
 * @param string $where     'api' | 'cp'，用于日志定位
 */
function vip_boot_require_or_json(string $bootstrap, string $where): void
{
    if (@is_readable($bootstrap)) {
        return;                       // 正常路径：什么都不做
    }

    $norm    = vip_boot_norm($bootstrap);
    $inBase  = vip_boot_within_basedir($bootstrap);
    $exists  = $inBase ? @file_exists($bootstrap) : null;

    if (!$inBase) {
        $code   = 'B001';
        $reason = 'PHP 的 open_basedir 把程序目录挡在了允许范围之外';
        $detail = 'open_basedir=' . ini_get('open_basedir') . ' 需要能读 ' . $norm;
    } elseif ($exists === false) {
        $code   = 'B002';
        $reason = '程序文件缺失，部署不完整';
        $detail = '文件不存在：' . $norm;
    } else {
        $code   = 'B003';
        $reason = '程序文件没有读取权限';
        $detail = '不可读：' . $norm;
    }

    $ref = $code . '-' . strtoupper(bin2hex(random_bytes(3)));

    // 此刻 bootstrap 还没跑，error_log 仍指向 Web 服务器的错误日志 ——
    // 这正好，因为自定义日志目录多半也在 open_basedir 外面，写不进去。
    error_log(sprintf('[%s:boot] %s | %s | %s', $where, $ref, $reason, $detail));

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
    }
    // 给客户端「原因分类 + 代码」，具体路径留在服务器日志里
    echo json_encode([
        'ok'      => false,
        'error'   => 'boot_failed',
        'message' => '服务未正确部署：' . $reason . '，请联系管理员（错误代码 ' . $ref . '）',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
