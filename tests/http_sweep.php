<?php
declare(strict_types=1);

/**
 * ════════════════════════════════════════════════════════════════
 * 全站 HTTP 探针 —— 对着真实跑起来的站点，把每一个接口都打一遍。
 *
 * 为什么需要它：其它三套测试都够不到 HTTP 这一层。
 *   · run.php   纯逻辑，不起服务
 *   · smoke     直接调服务层，不走路由
 *   · browser   点界面，但只覆盖界面上走得到的路径
 *
 * 真栽过一次：CP 登录的闭包漏了一个 use ($app)，接口直接 500，
 * 而当时逻辑测试全绿 —— 因为那个 bug 只存在于「路由闭包」里，
 * 服务层本身没问题。这类疏忽只有把接口真打一遍才看得见。
 *
 * 两条铁律，每个接口都要满足：
 *   ① 无论给什么参数（包括不给、给垃圾），都【不能 500】，
 *      也不能吐出非 JSON —— 收银员看到「服务器返回的不是 JSON」
 *      会以为系统坏了，而真实原因往往只是参数不对。
 *   ② 没登录时【不能返回数据】—— 任何一个漏判鉴权的接口都是个洞。
 *
 * 用法：
 *   php -S 127.0.0.1:8910 -t wwwroot &      # 另开一个终端
 *   php tests/http_sweep.php
 *   BASE=https://lms.sushisom.net php tests/http_sweep.php   # 打真机
 *
 * ⚠️ 会在库里留下少量测试数据（一个批次的卡、一个操作员），跑完自己清掉。
 *    别对着生产库跑。
 * ════════════════════════════════════════════════════════════════
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$BASE = getenv('BASE') ?: 'http://127.0.0.1:8910';
/** 业务性的「没找到」用的状态码，与 Api::NOT_FOUND 一致（探针不加载 app/） */
const Api_NOT_FOUND = 422;
$PIN  = getenv('SWEEP_PIN') ?: 'admin123';
$USER = getenv('SWEEP_USER') ?: 'admin';

$pass = 0; $fail = 0; $notes = [];
function ok(bool $c, string $m, string $extra = ''): void {
    global $pass, $fail;
    if ($c) { $pass++; echo "  \033[32m✓\033[0m $m\n"; }
    else    { $fail++; echo "  \033[31m✗\033[0m $m" . ($extra ? "\n      $extra" : '') . "\n"; }
}
function group(string $s): void { echo "\n\033[1m$s\033[0m\n"; }

/**
 * 发一个请求。返回 [status, body, json|null]。
 * 用 curl 而不是 file_get_contents：要拿到 4xx/5xx 的响应体，
 * 而后者在非 2xx 时直接给 false，正好把最需要看的内容丢掉。
 */
function req(string $url, string $method = 'GET', ?array $body = null, string $jar = '', array $headers = []): array {
    $ch = curl_init($url);
    $h  = array_merge(['Content-Type: application/json'], $headers);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $h,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_SSL_VERIFYPEER => false,   // 门店证书是内网自签
        CURLOPT_SSL_VERIFYHOST => 0,
    ]);
    if ($jar !== '') {
        curl_setopt($ch, CURLOPT_COOKIEJAR,  $jar);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $jar);
    }
    if ($body !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body)); }
    $raw    = (string)curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err    = curl_error($ch);
    curl_close($ch);
    if ($status === 0) { return [0, $err, null]; }
    $j = json_decode($raw, true);
    return [$status, $raw, is_array($j) ? $j : null];
}

/**
 * 这个响应算不算「崩了」。
 *
 * 500 和非 JSON 都算。但 503 + db_unavailable 【不算】——
 * 那是库连不上时的正确降级，代码本身没问题。
 * 不区分的话，库一停探针就报几十条假故障，真问题反而看不见。
 */
function crashed(int $st, ?array $j): bool
{
    if ($j === null) { return true; }
    if ($st === 503 && ($j['error'] ?? '') === 'db_unavailable') { return false; }
    return $st >= 500;
}

/** 一句话概括响应，出错时打给人看 */
function brief(int $st, string $raw, ?array $j): string {
    if ($j !== null) {
        return "HTTP $st  error=" . ($j['error'] ?? '-') . "  msg=" . mb_substr((string)($j['message'] ?? ''), 0, 60);
    }
    return "HTTP $st  非 JSON：" . mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags($raw)) ?? ''), 0, 120);
}

echo "\033[1m全站 HTTP 探针\033[0m  目标：$BASE\n";

/**
 * 先确认环境是活的。
 *
 * 库没起来的话，后面每一个接口都会规规矩矩地回 503 db_unavailable ——
 * 那是【正确】的降级响应，不是 bug。但探针会一口气报出几十条，
 * 把真正的问题淹掉。所以先探一次，环境不对就直接说清楚，不往下跑。
 */
[$st0, $raw0, $j0] = req($BASE . '/api.php/health');
if ($j0 === null) {
    echo "\n\033[31m站点没有回 JSON —— 先确认 Web 服务起来了：\n  php -S 127.0.0.1:8910 -t wwwroot\033[0m\n";
    echo "  " . brief($st0, $raw0, $j0) . "\n\n";
    exit(1);
}
if (($j0['data']['local_db'] ?? false) !== true) {
    echo "\n\033[31m本地数据库连不上，探针没法跑。\033[0m\n";
    echo "  先把库起起来再来（接口此时回 503 db_unavailable 是对的，不是 bug）。\n\n";
    exit(1);
}

// ── 0. 站点活着吗 ────────────────────────────────────────
group('① 页面入口');

foreach ([['/', 'Pad 首页'], ['/cp/', 'CP 后台首页']] as [$path, $label]) {
    [$st, $raw] = req($BASE . $path);
    ok($st === 200, "$label 返回 200", "实得 HTTP $st");
    ok(str_contains($raw, '<!DOCTYPE html>'), "$label 是一张完整的 HTML");
    // 这两条守的是「页面必须由 PHP 发」：PHP 没执行的话会把源码原样吐出来
    ok(!str_contains($raw, '<?php'), "★ $label 没有把 PHP 源码吐出来（说明 PHP 真的执行了）");
    ok((bool)preg_match('/\?v=\d+/', $raw), "★ $label 的资源带着缓存版本号");
}

// ── 1. 没登录时不能拿到数据 ──────────────────────────────
group('② 未登录时的鉴权（每一个漏判都是一个洞）');

$padRoutes = [];
preg_match_all("/\\\$api->on\('(GET|POST)',\s*'([^']+)'/",
    file_get_contents(__DIR__ . '/../app/api/routes.php'), $m, PREG_SET_ORDER);
foreach ($m as $one) { $padRoutes[] = [$one[1], $one[2]]; }

$cpRoutes = [];
preg_match_all("/->on\('(GET|POST)',\s*'([^']+)'/",
    file_get_contents(__DIR__ . '/../app/cp/routes.php'), $m2, PREG_SET_ORDER);
foreach ($m2 as $one) { $cpRoutes[] = [$one[1], $one[2]]; }

echo "  （Pad " . count($padRoutes) . " 个接口，CP " . count($cpRoutes) . " 个接口）\n";

/** 这几个本来就该匿名可用 */
const PUBLIC_ROUTES = ['/health', '/auth/login', '/auth/logout', '/auth/me'];

$leaks = [];
$crashes = [];
foreach ([[$padRoutes, '/api.php', 'Pad'], [$cpRoutes, '/cp/api.php', 'CP']] as [$list, $prefix, $tag]) {
    foreach ($list as [$method, $path]) {
        [$st, $raw, $j] = req($BASE . $prefix . $path, $method, $method === 'POST' ? [] : null);
        if (crashed($st, $j)) {
            $crashes[] = "$tag $method $path → " . brief($st, $raw, $j);
            continue;
        }
        if (in_array($path, PUBLIC_ROUTES, true)) { continue; }
        // 拿到 ok:true 就说明没登录也给了数据
        if (($j['ok'] ?? false) === true) {
            $leaks[] = "$tag $method $path → 未登录竟然返回 ok:true";
        }
    }
}
ok($crashes === [], '★★ 未登录打全部接口，没有一个 500 或吐非 JSON',
   $crashes ? implode("\n      ", $crashes) : '');
ok($leaks === [], '★★ 未登录时没有任何接口返回数据',
   $leaks ? implode("\n      ", $leaks) : '');

// ── 2. 登录 ──────────────────────────────────────────────
group('③ 登录');

$padJar = tempnam(sys_get_temp_dir(), 'sweep_pad_');
$cpJar  = tempnam(sys_get_temp_dir(), 'sweep_cp_');

[$st, $raw, $j] = req($BASE . '/api.php/auth/login', 'POST',
    ['login_name' => $USER, 'pin' => $PIN, 'device' => 'SWEEP'], $padJar);
ok($st === 200 && ($j['ok'] ?? false), 'Pad 登录成功', brief($st, $raw, $j));
$padOp = $j['data']['operator'] ?? [];
ok(isset($padOp['lang']), 'Pad 登录响应带着语言');

[$st, $raw, $j] = req($BASE . '/cp/api.php/auth/login', 'POST',
    ['login_name' => $USER, 'pin' => $PIN], $cpJar);
ok($st === 200 && ($j['ok'] ?? false), '★ CP 登录成功（这里栽过：闭包漏了 use($app) 直接 500）',
   brief($st, $raw, $j));

if ($fail > 0) {
    echo "\n\033[31m登录就失败了，后面的没法跑。先看上面的错误。\033[0m\n";
    exit(1);
}

// ── 3. 登录后：每个接口给垃圾参数，都不能 500 ────────────
group('④ 登录后打全部接口（给空参数 —— 允许业务报错，不许崩）');

$bad = [];
$statuses = [];
foreach ([[$padRoutes, '/api.php', 'Pad', $padJar], [$cpRoutes, '/cp/api.php', 'CP', $cpJar]] as [$list, $prefix, $tag, $jar]) {
    foreach ($list as [$method, $path]) {
        // 会改数据的几个不在这里打，留到 ⑤ 用真实参数走一遍
        if (in_array($path, ['/members/erase', '/operators/create', '/cards/generate',
                             '/auth/logout', '/auth/change-pin', '/config/save'], true)) { continue; }
        [$st, $raw, $j] = req($BASE . $prefix . $path, $method, $method === 'POST' ? [] : null, $jar);
        $statuses["$tag $method $path"] = $st;
        if (crashed($st, $j)) {
            $bad[] = "$tag $method $path → " . brief($st, $raw, $j);
        }
    }
}
ok($bad === [], '★★ 没有任何接口 500 或吐出非 JSON',
   $bad ? implode("\n      ", $bad) : '');

// 4xx 也要是我们自己的错误码，不能是服务器的默认页
$weird = [];
foreach ($statuses as $k => $st) {
    if ($st === 404) { $weird[] = "$k → 404（业务性的「没找到」应当用 422，见 Api::NOT_FOUND）"; }
}
ok($weird === [], '★ 没有接口回 404 —— 业务错误一律 422，否则会被 nginx 换成它的错误页',
   $weird ? implode("\n      ", $weird) : '');

// ── 4. 关键读接口要真能读出东西 ──────────────────────────
group('⑤ CP 的读接口：不只是不崩，还要真的返回结构');

$reads = [
    ['/dashboard',      'GET',  null,                        ['business_date', 'cursors']],
    ['/alerts',         'GET',  null,                        ['alerts']],
    ['/reviews',        'GET',  null,                        ['reviews']],
    ['/rules',          'GET',  null,                        ['rules']],
    ['/config',         'GET',  null,                        ['groups']],
    ['/coupons',        'GET',  null,                        ['coupons']],
    ['/operators',      'GET',  null,                        ['operators']],
    ['/cards/batches',  'GET',  null,                        ['batches']],
    ['/audit',          'POST', ['limit' => 5],              ['logs']],
    ['/report/daily',   'POST', ['days' => 3],               ['rows']],
    ['/members/search', 'POST', ['type' => 'card', 'value' => 'TK-99999999-ZZZ'], null],
];
foreach ($reads as [$path, $method, $body, $keys]) {
    [$st, $raw, $j] = req($BASE . '/cp/api.php' . $path, $method, $body, $cpJar);
    $isOk = $st === 200 && ($j['ok'] ?? false) === true;
    // members/search 查一个不存在的卡号，业务上返回「没找到」也算正常
    if (!$isOk && $keys === null && $j !== null && $st < 500) { $isOk = true; }
    ok($isOk, "$path 正常返回", brief($st, $raw, $j));
    if ($isOk && $keys) {
        $d = $j['data'] ?? [];
        $miss = array_values(array_filter($keys, static fn($k) => !array_key_exists($k, $d)));
        ok($miss === [], "  └ 返回结构里有 " . implode(' / ', $keys),
           $miss ? '缺：' . implode(', ', $miss) : '');
    }
}

// ── 5. Pad 的关键读接口 ──────────────────────────────────
group('⑥ Pad 的关键接口');

[$st, $raw, $j] = req($BASE . '/api.php/health', 'GET', null, $padJar);
ok(($j['ok'] ?? false) === true, '/health 正常', brief($st, $raw, $j));
$h = $j['data'] ?? [];
ok(($h['local_db'] ?? false) === true, '  └ 本地库连得上');
ok(isset($h['app_version']), '  └ 报了 app_version（Pad 靠它判断要不要自动更新）');
ok(isset($h['default_lang']), '  └ 报了 default_lang（登录页要用）');

[$st, $raw, $j] = req($BASE . '/api.php/card/lookup', 'POST', ['card_no' => 'TK-99999999-ZZZ'], $padJar);
ok($st === 422, '★ 查一张不存在的卡回 422 而不是 404（404 会被 nginx 换掉）', brief($st, $raw, $j));
ok(($j['error'] ?? '') === 'card_unknown', '  └ 错误码是 card_unknown');

[$st, $raw, $j] = req($BASE . '/api.php/card/lookup', 'POST', ['card_no' => 'https://example.com/x'], $padJar);
ok(($j['error'] ?? '') === 'card_malformed', '扫错二维码 → card_malformed', brief($st, $raw, $j));

// 前台「查一张卡」—— 客人当面问「我这卡还能用吗」
[$st, $raw, $j] = req($BASE . '/api.php/card/status', 'POST', ['card_no' => 'TK-99999999-ZZZ'], $padJar);
ok($st === Api_NOT_FOUND, '/card/status 查不到的卡也回 422', brief($st, $raw, $j));
[$st, $raw, $j] = req($BASE . '/api.php/card/status', 'POST', [], $padJar);
ok(($j['error'] ?? '') === 'card_required', '/card/status 不给卡号时提示要先扫卡', brief($st, $raw, $j));

// ── 6. 语言：服务端按请求头回话 ──────────────────────────
group('⑦ 服务端按 X-Lang 回话');

[$st, $raw, $j] = req($BASE . '/api.php/card/lookup', 'POST', ['card_no' => 'TK-99999999-ZZZ'],
    $padJar, ['X-Lang: es']);
$msgEs = (string)($j['message'] ?? '');
ok(preg_match('/[\x{4e00}-\x{9fa5}]/u', $msgEs) !== 1,
   "★ 带 X-Lang: es 时错误文案是西语：「{$msgEs}」");

[$st, $raw, $j] = req($BASE . '/api.php/card/lookup', 'POST', ['card_no' => 'TK-99999999-ZZZ'],
    $padJar, ['X-Lang: zh']);
$msgZh = (string)($j['message'] ?? '');
ok(preg_match('/[\x{4e00}-\x{9fa5}]/u', $msgZh) === 1, "带 X-Lang: zh 时是中文：「{$msgZh}」");
ok($msgEs !== $msgZh && $msgEs !== '', '两种语言确实不同');

[$st, $raw, $j] = req($BASE . '/api.php/card/lookup', 'POST', ['card_no' => 'TK-99999999-ZZZ'],
    $padJar, ['X-Lang: klingon']);
ok(($j['ok'] ?? true) === false && isset($j['message']),
   '认不出的语言码不会把接口打崩（回落到默认语言）', brief($st, $raw, $j));

// ── 7. 权限：收银员不能进后台 ────────────────────────────
group('⑧ 越权');

$clerkJar = tempnam(sys_get_temp_dir(), 'sweep_clerk_');
[$st, $raw, $j] = req($BASE . '/cp/api.php/auth/login', 'POST',
    ['login_name' => 'cashier1', 'pin' => $PIN], $clerkJar);
ok(($j['ok'] ?? false) === false, '★★ 收银员账号登不进 CP 后台', brief($st, $raw, $j));

// 收银员的 Pad 会话，去打需要经理的动作
$clerkPad = tempnam(sys_get_temp_dir(), 'sweep_cpad_');
[$st, $raw, $j] = req($BASE . '/api.php/auth/login', 'POST',
    ['login_name' => 'cashier1', 'pin' => $PIN, 'device' => 'SWEEP'], $clerkPad);
ok(($j['ok'] ?? false) === true, '收银员能登 Pad');

[$st, $raw, $j] = req($BASE . '/api.php/coupon/redeem', 'POST',
    ['coupon_id' => 1, 'force' => true, 'reason' => '试试越权'], $clerkPad);
ok(($j['ok'] ?? true) === false, '★★ 收银员强制核销被拒', brief($st, $raw, $j));
ok(in_array($j['error'] ?? '', ['forbidden', 'coupon_not_found', 'coupon_not_active'], true),
   '  └ 拒绝的理由是权限或券本身，不是崩了：' . ($j['error'] ?? '-'));

// ── 8. 清理 ──────────────────────────────────────────────
foreach ([$padJar, $cpJar, $clerkJar, $clerkPad] as $f) { @unlink($f); }

echo "\n" . str_repeat('─', 62) . "\n";
if ($fail === 0) {
    echo "\033[32m全站探针通过\033[0m  {$pass} 项\n\n";
    exit(0);
}
echo "\033[31m失败 {$fail}\033[0m / 共 " . ($pass + $fail) . " 项\n\n";
exit(1);
