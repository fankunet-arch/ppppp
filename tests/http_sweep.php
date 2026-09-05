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
 *   PHP_CLI_SERVER_WORKERS=8 php -S 127.0.0.1:8910 -t wwwroot &   # 另开一个终端
 *   php tests/http_sweep.php
 *   BASE=https://lms.sushisom.net php tests/http_sweep.php   # 打真机
 *
 * 🔴 **`php -S` 默认是单进程的** —— 它把请求排队一个个处理，于是任何并发
 *    缺陷（死锁、竞态、重复发券）在本地开发服务器上【结构性地复现不出来】，
 *    跑多少轮都是绿的。而现场真机跑的是 PHP-FPM，天然多进程 ——
 *    也就是说开发环境比生产环境更宽容，宽容的还正好是最难查的那一类。
 *    所以务必带上 PHP_CLI_SERVER_WORKERS。
 *    （真正的并发断言在 `smoke.php` ㉖ / ㊱，它们自己 fork 进程，不靠这里。）
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

/**
 * ── 🔴 /health 必须答得出「POS 侧还在出单吗」──────────────────
 *
 * docs/08 §0.1 让现场第一个打开的就是这个接口，而它原来只答
 * 「连得上吗」。连得上就是绿的 —— 于是最难查的那一类故障
 * （连得上、但按桌号永远查不到刚买单的桌）在这里看不出任何异常。
 *
 * 那一类的成因至少四种，在 Pad 上长得一模一样：POS 写单的时钟与
 * 主库 NOW() 不是同一个（时区配错，此时 PHP 与 POS 的 NOW() 完全一致，
 * 时钟偏差告警一声不响）、连到了备份库或错的库、POS 停止写
 * history_order_head、店里还没开始营业。
 *
 * 前三种都不是「时间窗太窄」，而按桌号查不到时最容易做的动作恰恰是
 * 把窗口调大 —— 那只会把陈年旧单放进来，把真正的问题盖住。
 */
if (($h['pos_db'] ?? false) === true) {
    ok(array_key_exists('pos_data', $h),
       '★★ /health 报了 POS 侧的数据新鲜度（pos_data）—— 「连得上」不等于「有新单」');
    $pd = $h['pos_data'] ?? null;
    ok(is_array($pd) && array_key_exists('newest_order_at', $pd)
       && array_key_exists('newest_age_minutes', $pd) && isset($pd['pos_now']),
       '  └ 带着 POS 的 NOW()、最新一张单的时间、以及它有多旧');
    ok(array_key_exists('pos_stale_note', $h),
       '  └ 太旧时给一句人话（正常时是 null，不给正常的店推噪音）');
} else {
    ok(($h['pos_note'] ?? null) !== null, '  └ POS 连不上时给了降级提示');
}

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

/**
 * ★ 「这一单会不会计次」的预览挂在查会员/查卡这条路上（visit_preview），
 *   也就是说它【每次扫卡都会执行】。所以订单号无效时绝不能把查卡打挂 ——
 *   一个只为提示服务的功能，不该有本事让收银台扫不了卡。
 */
[$st, $raw, $j] = req($BASE . '/api.php/card/lookup', 'POST',
    ['card_no' => 'TK-99999999-ZZZ', 'serial_id' => '0000000000'], $padJar);
ok($st === 422 && ($j['error'] ?? '') === 'card_unknown',
   '★★ 带一个不存在的订单号去查卡：照旧 422 card_unknown，没被打成 500',
   brief($st, $raw, $j));
[$st, $raw, $j] = req($BASE . '/api.php/card/lookup', 'POST',
    ['card_no' => 'TK-99999999-ZZZ', 'serial_id' => "1' OR '1'='1"], $padJar);
ok($st === 422, '  └ 订单号里塞 SQL 也一样（参数化查询，不拼串）', brief($st, $raw, $j));
ok(!preg_match('/SELECT|SQLSTATE|Stack trace|\.php/i', $raw),
   '  └ 回给客户端的没有 SQL 也没有堆栈');

[$st, $raw, $j] = req($BASE . '/api.php/member/search', 'POST',
    ['type' => 'phone', 'value' => '600000000'], $padJar);
ok(($j['ok'] ?? false) === true, '  └ 不带订单号查会员照常可用（visit_preview 为 null，不是报错）', brief($st, $raw, $j));

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

// ── 7b. 小票号查单不能当探针用 ────────────────────────────
group('⑨ 小票号：查不到与超时效，对收银员必须长得一模一样');

/**
 * ★ 小票号就是 order_head_id，【连号的整数】。
 *   手里有一张自己的小票就知道号段在哪儿，往前减一个个试就能翻出别人的单。
 *
 *   原来的回话把三种结果分得清清楚楚：
 *     · 查到了                → 直接给单
 *     · 「这张小票是 8-16 的，超过 7 天」 → 等于确认【这个号是真的】，还附送日期
 *     · 「没找到小票号 xxx」  → 这个号是空的
 *   一个一个试下去，号段和哪天有生意都能摸出来。
 *
 *   ★ 只改前端文案是没用的：Pad 是柜台上一台安卓平板，返回的 JSON 谁都看得见。
 *     所以这一组断言打的是【接口本身】。
 */
/**
 * ⚠️ 这个号码是【跟着夹具库走】的：要在库里真实存在、且已超过
 *    invoice_lookup_max_days。换一个库就要跟着换，否则会走 not_found 分支
 *    （那一支不返回 max_days），下面「回溯天数还给」那条断言会红 ——
 *    看上去像撞了 bug，其实只是夹具不对。
 *    换法：SWEEP_OLD_INVOICE=<库里一个够老的 order_head_id> php tests/http_sweep.php
 */
$OLD_INVOICE  = (int)(getenv('SWEEP_OLD_INVOICE') ?: 92521);   // 真实存在、但已超回溯天数
$FAKE_INVOICE = 99999999;                                       // 不存在

foreach ([['超时效的真单', $OLD_INVOICE], ['根本不存在的号', $FAKE_INVOICE]] as [$what, $no]) {
    [$st, $raw, $j] = req($BASE . '/api.php/order/locate-invoice', 'POST',
        ['invoice_no' => $no], $clerkPad);
    $d = $j['data'] ?? $j;
    ok(($j['ok'] ?? false) === true && ($d['candidates'] ?? null) === [],
       "收银员查{$what} → 没有候选订单", brief($st, $raw, $j));
    ok(($d['reason'] ?? '') === 'unavailable',
       "★★★ 收银员拿到的 reason 是笼统的 unavailable（实际：" . ($d['reason'] ?? '-') . "）—— {$what}");
    ok(($d['order_end_time'] ?? null) === null,
       '★★★ 不给结账日期 —— 那一句本身就等于「这个号是真的，那天有生意」');
    ok(($d['max_days'] ?? null) === null, '  └ 也不给回溯天数');
}

// 经理照常看得到真原因 —— 查错、对账要分得清
[$st, $raw, $j] = req($BASE . '/api.php/order/locate-invoice', 'POST',
    ['invoice_no' => $OLD_INVOICE], $padJar);
$dm = $j['data'] ?? $j;
ok(($dm['is_manager'] ?? false) === true, '经理会话认得出是经理', brief($st, $raw, $j));
ok(in_array($dm['reason'] ?? '', ['too_old', 'not_found'], true),
   '★★ 经理拿到的是真实原因（' . ($dm['reason'] ?? '-') . '）—— 分不清就没法查错');

/**
 * ★ 但经理【也不给结账日期】。
 *
 *   经理要分的只是「没这张单」还是「有单但太旧了」，到这一步就够查错了；
 *   具体是哪天并不需要。而经理账号一旦外泄，
 *   泄露的东西不该比收银员账号多 —— 那样等于绕一圈又把预言机装回去了。
 */
ok(($dm['order_end_time'] ?? null) === null,
   '★★★ 经理也拿不到结账日期 —— 经理账号外泄时，泄露面不该比收银员大');
ok(!preg_match('/\d{4}-\d{2}-\d{2}/', (string)$raw),
   '★★★ 整个响应体里没有任何日期（不只是那一个字段）'
   . (preg_match('/\d{4}-\d{2}-\d{2}/', (string)$raw, $mm) ? '：找到 ' . $mm[0] : ''));
ok(($dm['max_days'] ?? null) !== null,
   '  └ 但回溯天数还给 —— 那是后台配置，经理本来就看得到，用来把话说完整');

[$st, $raw, $j] = req($BASE . '/api.php/order/locate-invoice', 'POST',
    ['invoice_no' => $FAKE_INVOICE], $padJar);
$dm2 = $j['data'] ?? $j;
ok(($dm2['reason'] ?? '') === 'not_found',
   '★★ 经理能分清「不存在」和「超时效」（' . ($dm2['reason'] ?? '-') . '）');

// ── 7c. 找单时间窗不能由客户端说了算 ─────────────────────
group('⑩ 按桌号找单：时间窗由服务端封顶');

/**
 * ★ 原来客户端传多少就是多少。实测一个【普通收银员】账号传
 *   window_minutes = 5256000（十年），一次捞回 19 张跨三周的历史单，
 *   带金额、份数、菜品明细、已经记给了谁。
 *
 *   两件事同时坏掉：后台那两项窗口配置形同虚设；
 *   以及对 docs/README 里写明「性能极度受限」的 POS 主机开了一个
 *   无上限的扫描入口（SQL 有 LIMIT 20，但扫描范围没有上限）。
 *
 *   这条断言打的是接口本身 —— 前端改文案是没用的，
 *   Pad 是柜台上一台安卓平板，请求谁都能自己构造。
 */
[$st, $raw, $j] = req($BASE . '/api.php/order/locate', 'POST',
    ['table_name' => '15'], $clerkPad);
$w0 = ($j['data']['window'] ?? null);
ok(is_int($w0) && $w0 > 0, '不带 window_minutes 时走后台配置（window=' . var_export($w0, true) . '）',
   brief($st, $raw, $j));

[$st, $raw, $j] = req($BASE . '/api.php/order/locate', 'POST',
    ['table_name' => '15', 'window_minutes' => 5256000], $clerkPad);
$wBig = ($j['data']['window'] ?? null);
ok(is_int($wBig) && $wBig <= max($w0, 60),
   sprintf('★★★ 传十年（5256000 分钟）被夹到 %s 分钟 —— 后台配置说了算', var_export($wBig, true)),
   brief($st, $raw, $j));
ok($wBig !== 5256000, '★★★ 服务端没有原样采纳客户端给的窗口');

[$st, $raw, $j] = req($BASE . '/api.php/order/locate', 'POST',
    ['table_name' => '15', 'window_minutes' => 45], $clerkPad);
ok(($j['data']['window'] ?? null) === 45,
   '  └ 上限以内的值照常生效（45 分钟）—— 封的是上限，不是「一律不许传」',
   brief($st, $raw, $j));

// ── 7d. 实体卡坏掉不能把登录一起拖下水 ───────────────────
group('⑪ 卡片功能的开关随登录一起带回来');

/**
 * ★ card_prefix 含 I/L/O/U 时 CardNumber 构造即抛（那是对的）。
 *   但 CardService 是在 /auth/login 的响应里被构造的 ——
 *   曾经因此变成：首页 200、/health 说一切正常、收银员就是登不进去，
 *   屏幕上只有一句「系统内部错误（E302-xxxx）」。
 *   一个【局部故障】（发卡/查卡用不了）被升级成了【全店停摆】。
 *
 *   这里只能验「正常配置下这两个字段确实回来了」——
 *   真正的降级路径要改 config.php 才测得到，那属于部署期检查
 *   （bin/init.php repair / bin/diag.php 各有一条）。
 *   这条断言守的是【字段本身别被删掉】：删了的话降级分支就再也没人看得见。
 */
[$st, $raw, $j] = req($BASE . '/api.php/auth/login', 'POST',
    ['login_name' => 'cashier1', 'pin' => $PIN, 'device' => 'SWEEP2'], $clerkPad);
$se = $j['data']['settings'] ?? [];
ok(array_key_exists('cards_ok', $se),
   '★★ 登录响应里带 cards_ok —— 实体卡这一块能不能用，Pad 要知道', brief($st, $raw, $j));
ok(array_key_exists('cards_error', $se), '  └ 以及坏在哪（cards_error）');
ok(($se['cards_ok'] ?? null) === true, '  └ 本机配置正常，cards_ok = true');
ok(($se['expiring_soon_days'] ?? null) !== null, '  └ 卡片阈值照常带回来');

// ── 7e. 自动维护的配置项不能手工改 ───────────────────────
group('⑫ readonly 的配置项：两头都要堵');

/**
 * ★ pos_clock_offset_sec 是机器自己记的（Cron 每 20 分钟一轮）。
 *   schema 里标了 readonly，但那个标记一度是【装饰性的】：
 *   后台照样渲染成可编辑输入框，/config/save 也不看它。
 *
 *   实测能被改成 99999，而且【改不回去】—— 它当时是 int 类型，
 *   ctype_digit() 拒绝负数，而 POS 比本机慢时这个值天然是负的。
 *   也就是「能改坏、改不回来」，只能等下一轮 Cron 自愈；
 *   在那 ≤20 分钟里，补记时限那道闸门的基准被挪走了。
 */
[$st, $raw, $j] = req($BASE . '/cp/api.php/config/save', 'POST',
    ['key' => 'pos_clock_offset_sec', 'value' => '99999'], $cpJar);
ok(($j['ok'] ?? true) === false,
   '★★★ 管理员也改不了自动维护的项（pos_clock_offset_sec）', brief($st, $raw, $j));
ok(str_contains((string)($j['detail']['hint'] ?? ''), '自动维护'),
   '  └ 并且说清为什么改不了：' . ($j['detail']['hint'] ?? '-'));

// 未登记的键照旧拒
[$st, $raw, $j] = req($BASE . '/cp/api.php/config/save', 'POST',
    ['key' => 'totally_made_up_key', 'value' => '1'], $cpJar);
ok(($j['ok'] ?? true) === false, '  └ 没登记过的键也拒（sys_config 不该长出没人认识的行）');

// ── 7f. 改奖励门槛这条路必须真的能走通 ─────────────────
group('⑬ 改奖励规则不能把后台打挂，也不能静默送钱');

/**
 * 🔴 这一组存在的理由，是我自己踩过的一次：
 *
 *   给 /config/save 加「调低门槛要算出补发张数」的护栏时，写了
 *   `$app->cfg()->get($key, null)` —— 而 ConfigRepo::get 的第二个参数
 *   是 string，不可为 null。于是【每一次保存配置都 500】。
 *
 *   服务层的探针跑得好好的，因为它没走路由。一走 HTTP 就炸。
 *   所以这条路必须有 HTTP 层的断言：能不能保存、回不回 will_issue，
 *   都得真的打一遍接口才算数。
 */
$thrOld = null;
[$st, $raw, $j] = req($BASE . '/cp/api.php/config', 'GET', null, $cpJar);
foreach (($j['data']['groups'] ?? []) as $grp) {
    foreach (($grp['items'] ?? []) as $it) {
        if (($it['key'] ?? '') === 'reward_threshold_visits') { $thrOld = (string)($it['value'] ?? ''); }
    }
}
ok($thrOld !== null && $thrOld !== '', "读到当前门槛：" . (string)$thrOld, brief($st, $raw, $j));

/**
 * ★ 只【调高】一格再改回来。
 *   调高不会产生补发，所以这一组不会在测试库里凭空发出券；
 *   而「保存得了、不 500」才是这一组真正要守的东西。
 */
if ($thrOld !== null && $thrOld !== '' && ctype_digit($thrOld)) {
    $thrUp = (string)((int)$thrOld + 1);
    [$st, $raw, $j] = req($BASE . '/cp/api.php/config/save', 'POST',
        ['key' => 'reward_threshold_visits', 'value' => $thrUp], $cpJar);
    ok(($j['ok'] ?? false) === true,
       '★★★ 保存奖励门槛不会 500（护栏本身不能把后台打挂）', brief($st, $raw, $j));
    ok(!array_key_exists('will_issue', $j['data'] ?? []),
       '  └ 调【高】门槛不会补发，所以不带 will_issue');
} else {
    ok(false, '拿不到当前门槛，下面两条跳过');
}

// 换口径也要保存得了 —— 这条路旧护栏完全看不见
[$st, $raw, $j] = req($BASE . '/cp/api.php/config/save', 'POST',
    ['key' => 'reward_mode', 'value' => 'amount'], $cpJar);
ok(($j['ok'] ?? false) === true, '★★ 换积分口径也保存得了', brief($st, $raw, $j));

/**
 * ★ 新增的配置项也要【真的打一遍路由】。
 *
 *   栽过一次：`ConfigRepo::get()` 第二个参数不可为 null，
 *   而 /config/save 里传了 null —— 服务层探针一路绿，
 *   后台【每一次保存都 500】。新加一个 schema 项就补一条，不要省。
 */
[$st, $raw, $j] = req($BASE . '/cp/api.php/config/save', 'POST',
    ['key' => 'verify_recheck_hours', 'value' => '168'], $cpJar);
ok(($j['ok'] ?? false) === true, '★★ 值比对复查间隔保存得了', brief($st, $raw, $j));
[$st, $raw, $j] = req($BASE . '/cp/api.php/config/save', 'POST',
    ['key' => 'verify_recheck_hours', 'value' => '0'], $cpJar);
ok(($j['ok'] ?? true) === false,
   '  └ 填 0 被拒（0 意味着每轮都全量重扫，会把 POS 主机压垮）', brief($st, $raw, $j));

/**
 * ★ 后台奖励券页现在要带「待发」名单（审计 F8）。
 *   影子模式（reward_auto_grant = 0）全靠它，缺了那条上线建议就执行不了。
 */
[$st, $raw, $j] = req($BASE . '/cp/api.php/coupons', 'GET', null, $cpJar);
ok(($j['ok'] ?? false) === true && is_array($j['data']['pending'] ?? null),
   '★★ /coupons 带回「待发」名单（不只是一个总数）', brief($st, $raw, $j));
ok(array_key_exists('auto_grant', $j['data'] ?? []),
   '  └ 并且说清当前自动发放是开是关（开着却还有待发的，本身就是个信号）');
[$st, $raw, $j] = req($BASE . '/cp/api.php/config/save', 'POST',
    ['key' => 'reward_mode', 'value' => 'visits'], $cpJar);
ok(($j['ok'] ?? false) === true, '  └ 换回来同样', brief($st, $raw, $j));

// 门槛填 0 要被拦（一个按键回溯补发的那条）
[$st, $raw, $j] = req($BASE . '/cp/api.php/config/save', 'POST',
    ['key' => 'reward_threshold_visits', 'value' => '0'], $cpJar);
ok(($j['ok'] ?? true) === false, '★★ 门槛填 0 被拒', brief($st, $raw, $j));
ok(str_contains((string)($j['detail']['hint'] ?? ''), '收不回来'),
   '  └ 并且说清后果：' . mb_substr((string)($j['detail']['hint'] ?? '-'), 0, 30) . '…');

// 还原（拿不到原值时不动它 —— 宁可不还原，也不能写一个空值进去）
if ($thrOld !== null && $thrOld !== '' && ctype_digit($thrOld)) {
    req($BASE . '/cp/api.php/config/save', 'POST',
        ['key' => 'reward_threshold_visits', 'value' => $thrOld], $cpJar);
}

// ── 8. 清理 ──────────────────────────────────────────────
foreach ([$padJar, $cpJar, $clerkJar, $clerkPad] as $f) { @unlink($f); }

echo "\n" . str_repeat('─', 62) . "\n";
if ($fail === 0) {
    echo "\033[32m全站探针通过\033[0m  {$pass} 项\n\n";
    exit(0);
}
echo "\033[31m失败 {$fail}\033[0m / 共 " . ($pass + $fail) . " 项\n\n";
exit(1);
