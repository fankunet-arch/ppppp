<?php
declare(strict_types=1);

/**
 * Pad 端 API 路由。
 *
 * 由 /wwwroot/api.php 引导后 require 本文件。
 * 业务代码全部在 /app（网络不可见），入口只在 /wwwroot。
 *
 * @var \Vip\App $app
 */

use Vip\Http\Api;
use Vip\Money;
use Vip\PointsEngine as PE;
use Vip\Service\AuthService;

$api = new Api();

/**
 * ★ 惰性构造 AuthService。
 * 若在此处直接 new（需要 localDb()），本地库一旦不可达，
 * 连 /health 都会在路由注册阶段就崩掉，且响应体为空 —— 无法排障。
 */
$authRef = null;
$auth = static function () use ($app, &$authRef): AuthService {
    return $authRef ??= new AuthService($app->localDb(), $app->storeCode(), $app->audit());
};

/** 需要登录的路由统一走这里 */
$requireOperator = static function () use ($auth): array {
    $op = $auth()->resolve(Api::readToken());
    if ($op === null) {
        Api::fail('unauthorized', 401);
    }
    return $op;
};

$requireManager = static function () use ($requireOperator): array {
    $op = $requireOperator();
    if (!$op['is_manager']) {
        Api::fail('forbidden', 403);
    }
    return $op;
};

// ════════════════════════════════════════════════════════════
// 健康检查
// ════════════════════════════════════════════════════════════

$api->on('GET', '/health', static function () use ($app): void {
    $localOk = true;
    try {
        $app->localDb()->value('SELECT 1');
    } catch (\Throwable) {
        $localOk = false;
    }
    // POS 不可达不算系统故障 —— 降级路径仍可用
    $posOk = true;
    $posMsg = null;
    $posStale = null;
    try {
        $posNow = $app->posReader()->now();
        /**
         * ── 🔴 「连得上」不等于「有新单」──────────────────────
         *
         * 这个接口是 docs/08 §0.1 让人**第一个打开**的东西，而它原来只答
         * 「连得上吗」。连得上就是绿的 —— 于是现场看到的是
         * 「一切正常，但按桌号就是查不到刚买单的桌」，没有任何线索。
         *
         * 而按桌号定位要求 order_end_time 落在时间窗内（出厂 30 分钟）。
         * 下面这四种局面都会让它恒为空，且在 Pad 上长得一模一样：
         *   · POS 写 order_end_time 用的时钟与 NOW() 不是同一个（时区配错）
         *     —— 此时 PHP 与 POS 的 NOW() 完全一致，时钟偏差告警一声不响；
         *   · 配置指到了 POS 库的备份/旧副本，或指错了库；
         *   · POS 那一侧停止写 history_order_head；
         *   · 门店真的还没开始营业。
         *
         * 所以这里多答一句「POS 侧最新一张单是多久以前」。
         * 它分得开「这一张单查不到」和「整个 POS 都没有新单」——
         * 后者不是把时间窗调大能解决的。
         *
         * ★ 一行查询、命中 idx_order_end_time，不会给主库添负担。
         * ★ 取不到就当没这回事：/health 的全部职责是「出问题时还能答话」，
         *   不能为了一个诊断字段把唯一的诊断入口拖垮。
         */
        try {
            $newest = $app->posReader()->newestOrderEndTime();
            $posStale = [
                'pos_now'            => $posNow,
                'newest_order_at'    => $newest,
                'newest_age_minutes' => $newest === null ? null
                    : (int)floor((strtotime($posNow) - strtotime($newest)) / 60),
            ];
        } catch (\Throwable) { /* 诊断字段而已，取不到就算了 */ }
    } catch (\Throwable $e) {
        $posOk  = false;
        $posMsg = 'POS 主库暂时无法访问，收银流程可继续（手工录入）';
    }
    /**
     * app_version：前端拿它和自己手里的 window.APP_VERSION 比对。
     *
     * 对不上就说明这个 Pad 上跑的是旧代码 —— 页面能自己在安全的时机
     * 刷新掉，不用收银员去点那个「点了也没用」的刷新按钮。
     * 取值口径与 wwwroot/_assets.php 完全一致，两处必须同源。
     */
    require_once __DIR__ . '/../../wwwroot/_assets.php';

    /**
     * default_lang 也从这里给：登录页要用，而那时还没有会话。
     *
     * ★ 必须容错。本接口的全部职责就是「库连不上时也要能答话」，
     *   为了一个语言默认值把它拖垮，等于把唯一的诊断入口也弄没了。
     *   （真栽过：加完这一行，库一停 /health 直接 500，
     *     登录页那句「本地数据库连接异常」再也不出现。）
     */
    $defaultLang = \Vip\Lang::FALLBACK;
    if ($localOk) {
        try {
            $defaultLang = \Vip\Lang::normalize($app->cfg()->get('default_lang', \Vip\Lang::FALLBACK));
        } catch (\Throwable) { /* 保持回落值 */ }
    }

    /**
     * ★ 只在「确实不新」时才多说一句，别给正常的店天天推噪音。
     *   判据用【定位窗口的两倍】：一倍会在午市与晚市之间的空档误报，
     *   而两倍还不够新，就真的该有人看一眼了。
     */
    $posStaleNote = null;
    if ($posStale !== null) {
        $win = max(1, $localOk ? $app->cfg()->int('order_lookup_window_min', 30) : 30);
        $age = $posStale['newest_age_minutes'];
        if ($age === null) {
            $posStaleNote = 'POS 侧一张已结账的单都没有 —— 请确认连的是不是正确的库';
        } elseif ($age > $win * 2) {
            $posStaleNote = sprintf(
                'POS 侧最新一张已结账的单是 %d 分钟前（%s），而按桌号只找最近 %d 分钟的单。'
              . '如果店里刚刚还在买单，那多半不是「时间窗太窄」—— 而是'
              . '① POS 写单的时钟与主库 NOW() 不是同一个（时区配错）'
              . '② 连到了备份库或错的库 ③ POS 那边不再写 history_order_head。'
              . '把窗口调大只会把陈年旧单放进来，把真正的问题盖住',
                $age, (string)$posStale['newest_order_at'], $win);
        }
    }

    Api::ok([
        'local_db' => $localOk, 'pos_db' => $posOk, 'pos_note' => $posMsg,
        'pos_data' => $posStale, 'pos_stale_note' => $posStaleNote,
        'default_lang' => $defaultLang,
        'app_version'  => vip_app_version([
            'index.php', 'assets/pad.js', 'assets/pad.css',
            'assets/ui.js', 'assets/i18n.js', 'assets/sushivip-bridge.js',
        ]),
    ]);
});

// ════════════════════════════════════════════════════════════
// 身份
// ════════════════════════════════════════════════════════════

/**
 * Pad 需要知道的后台开关。
 *
 * 跟着登录与 /auth/me 一起下发，前端据此决定界面上出不出现某些东西。
 * 界面隐藏只是体验层，真正的约束在服务端（见 /member/create 的拒收）——
 * 两边都做才站得住。
 */
$padSettings = static function () use ($app): array {
    $out = [
        // 关闭时 Pad 完全不显示手机号/邮箱/生日输入框，后端也拒收
        'collect_pii' => $app->cfg()->bool('member_collect_pii', false),
        // ★ 结果页要按口径换措辞：by_visit 下「不计次」等于「一分没有」，
        //   照着 by_amount 那句「只记积分不计次」念出来是错的
        'points_mode' => $app->cfg()->get('points_mode', 'by_amount'),
        // 还没选过语言的账号用这个；已选过的以 operator.lang 为准
        'default_lang'       => \Vip\Lang::normalize($app->cfg()->get('default_lang', \Vip\Lang::FALLBACK)),
        'langs'              => \Vip\Lang::ALL,
        'cards_ok'           => true,
        'cards_error'        => null,
    ];

    /**
     * ★★★ 实体卡这一块坏掉，不能把【登录】一起拖下水。
     *
     *   card_prefix 含 I/L/O/U 时 CardNumber 构造即抛（那是对的，见该类说明）。
     *   但 CardService 是在这里被构造的，于是异常从 /auth/login 抛出去 ——
     *   实测：首页 200、/health 说一切正常，收银员就是登不进，
     *   屏幕上只有一句「系统内部错误（E302-xxxx）」。
     *
     *   这把一个【局部故障】（发卡/查卡用不了）升级成了【全店停摆】。
     *   与 docs/03 §10 一贯的取舍相反 —— 那一条说的是「不阻塞收银流程」。
     *
     *   所以这里降级：卡相关的两个阈值置空、挂一个明确的错误串带回 Pad，
     *   积分照记。真正该在部署前拦住它的是 bin/init.php repair 与 bin/diag.php。
     */
    try {
        $out['expiring_soon_days'] = $app->cardService()->expiringSoonDays();
        $out['grace_months']       = $app->cardService()->graceMonths();
    } catch (\InvalidArgumentException $e) {
        $out['expiring_soon_days'] = null;
        $out['grace_months']       = null;
        $out['cards_ok']           = false;
        $out['cards_error']        = $e->getMessage();
        try {
            $app->alerts()->raiseOnce('card_prefix_invalid', 'config', 'card_prefix',
                '配置项 card_prefix 不可用，实体卡的查卡/建卡/激活全部停用（积分照常）：'
                . $e->getMessage(),
                ['severity' => 3]);
        } catch (\Throwable) { /* 告警本身坏了也不能挡住登录 */ }
    }
    return $out;
};

/**
 * 这个账号实际该用哪种语言：自己选过的 > 后台默认。
 *
 * 回落放在服务端而不是让前端 `op.lang || settings.default_lang` ——
 * 前端有三处要用（登录、会话恢复、切换后），漏一处就是「换台平板语言变了」，
 * 而且这种 bug 在开发机上永远复现不出来。
 */
$withLang = static function (array $op) use ($app): array {
    $op['lang'] = \Vip\Lang::isValid($op['lang'] ?? null)
        ? (string)$op['lang']
        : \Vip\Lang::normalize($app->cfg()->get('default_lang', \Vip\Lang::FALLBACK));
    return $op;
};

$api->on('POST', '/auth/login', static function () use ($auth, $padSettings, $withLang): void {
    $b     = Api::body();
    $login = Api::str($b, 'login_name', '');
    $pin   = Api::str($b, 'pin', '');
    $dev   = Api::str($b, 'device');

    if ($login === '' || $pin === '') {
        Api::fail('bad_request');
    }
    $r = $auth()->login($login, $pin, $dev, Api::clientIp());
    if (!$r['ok']) {
        Api::fail((string)$r['error'], $r['error'] === 'locked' ? 423 : 401, $r['detail'] ?? []);
    }
    Api::setToken((string)$r['token'], 12 * 3600);
    $op = $withLang((array)$r['operator']);
    // 登录响应本身也按这个人的语言回话 —— 否则登录页是中文、
    // 进去之后第一条提示还是中文，要等下一次请求才切过来
    Api::setLang($op['lang']);
    /**
     * ★ 令牌【也】交给前端存一份（localStorage）。
     *
     * 平板熄屏后系统会回收 WebView 进程，而 Android WebView 的 Cookie
     * 默认只在内存里，没 flush 就丢 —— 于是「熄屏一会儿再打开就要重新登录」。
     * 放在顶层而不是塞进 operator 里：那个数组会被 $withLang() 重塑，
     * 多出来的键未必留得住。说明见 Api::readToken()。
     */
    Api::ok([
        'operator' => $op,
        'settings' => $padSettings(),
        'session_token' => (string)$r['token'],
    ]);
});

/**
 * 收银员改自己的 PIN。必须验旧 PIN。
 * 改完保留当前会话，其余会话作废（PIN 泄露时能把别人踢下线）。
 */
$api->on('POST', '/auth/change-pin', static function () use ($app, $requireOperator): void {
    $op  = $requireOperator();
    $b   = Api::body();
    $old = (string)($b['old_pin'] ?? '');
    $new = (string)($b['new_pin'] ?? '');
    if ($old === '' || $new === '') {
        Api::fail('bad_request');
    }
    $r = $app->auth()->changePin((int)$op['id'], $old, $new, Api::readToken());
    if (!($r['ok'] ?? false)) {
        Api::fail((string)$r['error'], $r['error'] === 'invalid_credentials' ? 401 : 400);
    }
    Api::ok(['changed' => true]);
});

$api->on('POST', '/auth/logout', static function () use ($auth): void {
    $auth()->logout(Api::readToken());
    Api::clearToken();
    Api::ok();
});

$api->on('GET', '/auth/me', static function () use ($requireOperator, $padSettings, $withLang): void {
    Api::ok(['operator' => $withLang($requireOperator()), 'settings' => $padSettings()]);
});

/**
 * 切换界面语言 —— 记在账号上，换台平板登录也还是这个语言。
 *
 * 之所以要落库而不是只存在平板本地：收银台的平板是共用的，
 * 中文和西语的员工换班轮着用同一台。存本地就变成「谁后切的算谁的」。
 */
$api->on('POST', '/auth/lang', static function () use ($app, $requireOperator): void {
    $op   = $requireOperator();
    $lang = Api::str(Api::body(), 'lang', '') ?: '';
    if (!\Vip\Lang::isValid($lang)) {
        Api::fail('bad_request', 400, ['hint' => '不支持的语言']);
    }
    $app->auth()->setLang((int)$op['id'], $lang);
    Api::setLang($lang);
    Api::ok(['lang' => $lang]);
});

// ════════════════════════════════════════════════════════════
// 订单
// ════════════════════════════════════════════════════════════

/**
 * 按桌号定位近 N 分钟的已结账订单。
 * POS 不可达时返回 503 + pos_unavailable，前端据此提示改用手工录入。
 */
$api->on('POST', '/order/locate', static function () use ($app, $requireOperator): void {
    $requireOperator();
    $b     = Api::body();
    $table = Api::str($b, 'table_name', '');
    if ($table === '') {
        Api::fail('bad_request');
    }
    /**
     * ★★★ 找单的时间窗【由服务端说了算】，客户端只能在后台设定的范围内挑。
     *
     * ── 原来漏了什么 ──────────────────────────────────
     * 原来是 `$win > 0 ? $win : null`，客户端传多少就是多少，一路传到
     * PosReader 的 `WHERE order_end_time >= NOW() - INTERVAL ? MINUTE`，
     * 中间没有任何封顶。实测：一个普通收银员账号传 window_minutes=5256000
     * （十年），一次捞回 19 张跨三周的历史单，带金额、份数、菜品明细、
     * 已经记给了谁。
     *
     * 两件事同时坏掉：
     *
     * ① 后台那两项配置形同虚设。order_lookup_window_min /
     *    lookup_fallback_window_min 正是店家用来限制「能往回捞多久」的。
     *    补记时限（late_grant_minutes）只在【记账】时拦，【找单】不拦 ——
     *    而找单本身就把别桌的单摊开给你看了。
     *
     * ② 对 POS 的无上限扫描入口。docs/02 铁律第一条是「绝不全表扫」，
     *    docs/README「核心前提」写着「POS 主机性能极度受限」。
     *    虽然 SQL 带 LIMIT 20，但十年窗口 + table_name 无索引 =
     *    沿 idx_order_end_time 一路倒扫，冷门桌号能扫到库底。
     *
     * ── 对照 ──────────────────────────────────────────
     * /order/locate-invoice 把「会被人拿去当探针」写进了注释，做了四层防护。
     * 按桌号这条是同一类入口，原来一层都没有。
     *
     * 现在：客户端最多只能放宽到后台设定的「放宽窗口」，
     * 传得再大也按那个数走，并且回话里把真正生效的 window 告诉前端。
     */
    $reqWin = Api::int($b, 'window_minutes', 0);
    // 上限取两项配置里较大的那个：默认窗口本身就是店家认可的范围，
    // 若它比「放宽窗口」还大，客户端不该反而被卡到更小的数上
    $capWin = max(
        1,
        $app->cfg()->int('order_lookup_window_min', 30),
        $app->cfg()->int('lookup_fallback_window_min', 60)
    );
    $win    = $reqWin > 0 ? min($reqWin, $capWin) : null;
    $r      = $app->points()->locate($table, $win);

    if (!$r['ok'] && ($r['reason'] ?? '') === 'pos_unavailable') {
        Api::fail('pos_unavailable', 503);
    }
    Api::ok([
        'window'     => $r['window'],
        'candidates' => $r['candidates'],
        'fallback_window' => $app->cfg()->int('lookup_fallback_window_min', 60),
    ]);
});

/**
 * 按小票上的「Factura Simplificada」号定位订单。
 *
 * 小票印的是零填充的 000092521，这里接受任意形式（带前导零、带空格都行）。
 * 与按桌号查并存：手边有小票就输号（精确），没有就照旧输桌号。
 */
/**
 * 按小票号找单。
 *
 * ── 🔴 这个接口是全站唯一一个「按连号整数直接取别人订单」的入口 ──
 *
 * 小票号就是 order_head_id，连号。手里有一张自己的小票就知道号段在哪儿，
 * 往前减一个个试就能把别人的单翻出来。所以这里的回话必须当成
 * 【会被人拿去当探针】来设计：
 *
 *   ① 查不到与过了时效，对普通收银员是【同一句话】。
 *      区分开的话，「这张小票是 2026-08-12 的、超过 7 天」本身就告诉了
 *      对方：这个号是真的，而且那天有生意。一句一句试下去就能把号段
 *      和营业日都摸出来。
 *
 *   ② 差异只留给经理，但也【只给到「在期内 / 在期外」这一个二值】。
 *      经理要分的是「没这张单」还是「有单但太旧了」——
 *      到这一步就够查错了，具体是哪天并不需要。
 *      ★ 连经理都不给日期：经理账号一旦外泄，
 *        泄露的东西不该比收银员账号多。
 *
 *   ③ 【要在服务端就砍掉】，不能只改前端文案。
 *      Pad 是柜台上一台安卓平板，返回的 JSON 谁都看得见 ——
 *      前端换个说法而 reason/order_end_time 照发，等于没做。
 *
 *   ④ 查不到的每一次都留痕，短时间内连着太多次就告警（见 watchInvoiceProbe）。
 *      前三条管的是「试出来能知道什么」，这一条记的是「有人在试」。
 */
$api->on('POST', '/order/locate-invoice', static function () use ($app, $requireOperator): void {
    $op  = $requireOperator();
    $b   = Api::body();
    $raw = trim((string)($b['invoice_no'] ?? ''));
    // 小票上是 000092521，去掉前导零与分隔符；只留数字
    $digits = preg_replace('/\D+/', '', $raw) ?? '';
    if ($digits === '') {
        Api::fail('bad_request', 400, ['hint' => '请输入小票上的 Factura Simplificada 号']);
    }
    $invoice = (int)ltrim($digits, '0');

    $r = $app->points()->locateByInvoice($invoice);

    if (!$r['ok'] && ($r['reason'] ?? '') === 'pos_unavailable') {
        Api::fail('pos_unavailable', 503);
    }

    $isManager = (bool)($op['is_manager'] ?? false);
    $found     = $r['candidates'] !== [];

    if (!$found) {
        $app->points()->watchInvoiceProbe($invoice, $op);
    }

    Api::ok([
        'invoice_no' => $invoice,
        // ★ 普通收银员一律拿到 'unavailable'：查不到、太旧、号不合法，长得一模一样
        'reason'     => $found ? null
                      : ($isManager ? ($r['reason'] ?? 'not_found') : 'unavailable'),
        // 回溯天数是后台配置，经理本来就看得到，用来把话说完整（「超过 7 天」）
        'max_days'       => $isManager ? ($r['max_days'] ?? null) : null,
        // ★ 结账日期【谁都不给】——「这张小票是 8-16 的」本身就是答案。
        //   服务层已经不再带出来（见 locateByInvoice），这里再钉一道：
        //   将来有人把它加回服务层，也不会顺着这个接口漏出去。
        'order_end_time' => null,
        'is_manager'     => $isManager,
        'candidates' => $r['candidates'],
    ]);
});

/** 标记/取消标记免费餐（10送1 核销） */
$api->on('POST', '/order/free-meal', static function () use ($app, $requireOperator): void {
    $op     = $requireOperator();
    $b      = Api::body();
    $serial = Api::str($b, 'serial_id', '');
    $isFree = (bool)($b['is_free_meal'] ?? true);
    if ($serial === '') {
        Api::fail('bad_request');
    }
    if ($app->orders()->findBySerial($serial) === null) {
        Api::fail('order_not_found', Api::NOT_FOUND);
    }
    $app->orders()->markFreeMeal($serial, $isFree);
    /**
     * ★ 单独一个 action 名，不要借用 coupon_redeem。
     *
     *   RewardService::redeem() 用的就是 coupon_redeem。两件事混在同一个桶里，
     *   后台审计页按 action 一筛，「真的核销了一张券」和「服务员点了下免费餐开关」
     *   分不开 —— 而这与 auditForced() 里立的原则正相反
     *   （「单独一个 action 名，筛一下就是全部破例」）。
     */
    $app->audit()->log('order_free_meal', [
        'target_type' => 'order', 'target_id' => $serial,
        'operator_id' => $op['id'], 'operator_name' => $op['name'], 'device' => $op['device'],
        'detail' => ['is_free_meal' => $isFree],
    ]);
    Api::ok(['serial_id' => $serial, 'is_free_meal' => $isFree]);
});

// ════════════════════════════════════════════════════════════
// 会员
// ════════════════════════════════════════════════════════════

/** 三选一检索：卡号 / 手机号 / 邮箱。不做跨字段模糊搜索。 */
/**
 * 「这一单给这位客人会不会计次」—— 选会员时就把答案带上。
 *
 * ★ 为什么挂在查会员这一步，而不是等提交：
 *   once_per_period 下同餐期第二单照样记得上，但计次是 0。
 *   原来这件事只在结果页说，那时账已经记了，服务员没法再回头问客人。
 *   一桌吃完又加点甜点另开一单，是天天发生的事。
 *
 * serial_id 是可选的 —— 发卡、查卡这些没有订单的场景照常用这两个接口，
 * 不传就不算，返回里也不会有这一项。
 */
$visitPreview = static function (?string $serialId, int $memberId) use ($app): ?array {
    if ($serialId === null || $serialId === '') {
        return null;
    }
    return $app->points()->visitPreview($memberId, $serialId);
};

$api->on('POST', '/member/search', static function () use ($app, $requireOperator, $visitPreview): void {
    $requireOperator();
    $b    = Api::body();
    $type = Api::str($b, 'type', '');
    $val  = Api::str($b, 'value', '');
    if (!in_array($type, ['card', 'phone', 'email'], true) || $val === '') {
        Api::fail('bad_request');
    }
    $m = $app->members()->findBy($type, $val);
    if ($m === null) {
        Api::ok(['found' => false]);
    }
    Api::ok(['found' => true, 'member' => [
        'id'             => (int)$m['id'],
        'card_no'        => $m['card_no'],
        'phone'          => $m['phone'],
        'email'          => $m['email'],
        'points_balance' => (int)$m['points_balance'],
        'visit_count'    => (int)$m['visit_count'],
        'total_spent'    => $m['total_spent'],
        'consent_status' => (int)$m['consent_status'],
        // 未同意前积分冻结、不可兑换、不可营销推送
        'points_frozen'  => (int)$m['consent_status'] !== 1,
        // 带着订单来查时，顺便告诉 Pad「这一单会不会计次」
        'visit_preview'  => $visitPreview(Api::str($b, 'serial_id', '') ?: null, (int)$m['id']),
    ]]);
});

/**
 * 内联新建会员（客人现场没卡）。
 * 积分当场入账但冻结，同时发 double opt-in。
 */
/**
 * 扫卡/输卡号后的第一步 —— 系统判断这张卡现在是什么状态，Pad 据此决定下一步。
 *
 * 返回的 state 有四种，Pad 端一一对应一个动作：
 *   active → 直接进入该会员（正常使用）
 *   stock  → 弹出建卡表单（填手机/邮箱后绑定）
 *   unknown / void → 报错，不给继续
 *
 * ★ 防伪造就在这里：卡号不在 card 库存表里一律拒绝。
 *   卡号里的随机后缀只让人猜不到，判真伪的是那张表。
 */
$api->on('POST', '/card/lookup', static function () use ($app, $requireOperator, $visitPreview): void {
    $requireOperator();
    $b   = Api::body();
    $raw = Api::str($b, 'card_no', '');
    if ($raw === null || trim($raw) === '') {
        Api::fail('card_required');
    }

    $r = $app->cardService()->lookup($raw);

    /**
     * 过期卡是【可以换】的，所以不能只丢一个错误回去 ——
     * 要把「这张卡绑的是谁」一并带上，Pad 才能直接进入换卡流程，
     * 收银员不用再查一遍。
     */
    if (!$r['ok'] && ($r['state'] ?? '') === 'expired') {
        $card = $r['card'];
        $m    = $r['member'] ?? null;
        Api::ok([
            'state'      => 'expired',
            'card_no'    => $app->cardNumber()->format((string)$card['card_no']),
            'serial'     => (int)$card['serial'],
            'tier'       => $app->cardTiers()->describe($card['tier_code'] ?? null),
            'valid_to'   => $card['valid_to'],
            'grace_over' => (bool)($r['grace_over'] ?? false),
            'member'     => $m === null ? null : [
                'id'             => (int)$m['id'],
                'points_balance' => (int)$m['points_balance'],
                'visit_count'    => (int)$m['visit_count'],
            ],
        ]);
    }

    if (!$r['ok']) {
        Api::fail((string)$r['error'], Api::NOT_FOUND);
    }

    $card = $r['card'];
    $out  = [
        'state'     => $r['state'],
        'card_no'   => $app->cardNumber()->format((string)$card['card_no']),
        'serial'    => (int)$card['serial'],
        // 等级：客人扫卡时系统就知道这张卡什么级别。不分级时为 null
        'tier'      => $app->cardTiers()->describe($card['tier_code'] ?? null),
        'valid_to'  => $card['valid_to'],
        // 发卡前要提醒收银员「这张快到期了」，判断在前端做，天数由后端算
        // ★ 显式传营业日：静态助手默认读的是进程级 resolver，
        //   单请求单 App 时无碍，但一个进程装配多个 App（批处理/测试）时
        //   会读到「最后装配那个 App」的切点。传进来就与本请求同源，不依赖全局态。
        'days_left' => \Vip\Repo\CardRepo::daysLeft($card, $app->businessDay()->today()),
    ];

    if ($r['state'] === 'active') {
        $m = $r['member'];
        $out['member'] = [
            'id'             => (int)$m['id'],
            'card_no'        => $app->cardNumber()->format((string)$m['card_no']),
            'phone'          => $m['phone'],
            'email'          => $m['email'],
            'points_balance' => (int)$m['points_balance'],
            'visit_count'    => (int)$m['visit_count'],
            'consent_status' => (int)$m['consent_status'],
            'points_frozen'  => (int)$m['consent_status'] !== 1,
            'visit_preview'  => $visitPreview(Api::str($b, 'serial_id', '') ?: null, (int)$m['id']),
        ];
    }
    Api::ok($out);
});

$api->on('POST', '/member/create', static function () use ($app, $requireOperator): void {
    $op    = $requireOperator();
    $b     = Api::body();
    $card  = Api::str($b, 'card_no', '');
    $phone = Api::str($b, 'phone');
    $email = Api::str($b, 'email');
    $bday  = Api::str($b, 'birthday');

    // 发实体卡之后，建会员必须先有卡 —— 卡号不再由系统凭空生成，
    // 而是从 card 库存表里取一张真实存在的绑上去。
    if ($card === null || trim($card) === '') {
        Api::fail('card_required');
    }

    /**
     * 手机号与邮箱是否可收，由后台开关 member_collect_pii 决定。
     *
     * ★ 关闭时后端【拒收】，不是悄悄丢掉。
     *   光靠前端隐藏输入框是不够的：字段藏起来而接口照收，
     *   面对合规检查一样说不清。拒收之后才能说「系统在关闭状态下
     *   技术上就收不了个人信息」，这句话是站得住的。
     *   悄悄丢掉也不行 —— 那样收银员以为存进去了，客人也以为留了，
     *   等到丢卡来找回时才发现什么都没有。
     *
     * 开启时：留了联系方式的记录重新落入个人数据范畴，走双重确认
     * （待确认 + 积分冻结），详见 MemberRepo::create。
     */
    $collectPii = $app->cfg()->bool('member_collect_pii', false);
    if (!$collectPii) {
        $given = array_filter([$phone, $email, $bday], static fn($v) => $v !== null && trim((string)$v) !== '');
        if ($given) {
            Api::fail('pii_disabled', 400);
        }
    }

    $r = $app->cardService()->bindNewMember(
        $card, $phone ?: null, $email ?: null, $bday ?: null, $op
    );
    if (!$r['ok']) {
        Api::fail((string)$r['error'], 400,
            isset($r['hint']) ? ['hint' => $r['hint']] : []);
    }
    $m = $r['member'];

    $app->audit()->log('member_create', [
        'target_type' => 'member', 'target_id' => (string)$m['id'],
        'operator_id' => $op['id'], 'operator_name' => $op['name'], 'device' => $op['device'],
        'detail' => ['has_phone' => (bool)$phone, 'has_email' => (bool)$email,
                     'card_no' => $m['card_no']],
    ]);

    /**
     * 留了联系方式的才需要双重确认；全匿名的卡当场就可用。
     *
     * 确认走【现场输码】：短信/邮件只发一个 6 位码（纯出站），客人当场
     * 报给收银员，Pad 里输入即完成。不用「点链接确认」是因为那需要一个
     * 公网可达的端点接收点击，而门店网络是单向的 —— 这个矛盾原设计里没发现。
     */
    $pending = (int)$m['consent_status'] !== 1;
    $codeSent = null;
    if ($pending) {
        $sent = $app->consent()->sendCode((int)$m['id'], $op);
        // 发失败不阻断建卡：卡已经绑好了，积分照常入账，
        // 只是暂时冻结。收银员可以在会员那一栏重发。
        $codeSent = $sent['ok']
            ? ['channel' => $sent['channel'], 'expires_at' => $sent['expires_at']]
            : ['error' => $sent['error']];
    }
    Api::ok(['member' => [
        'id' => (int)$m['id'],
        'card_no' => $app->cardNumber()->format((string)$m['card_no']),
        'points_balance' => 0, 'visit_count' => 0,
        'consent_status' => (int)$m['consent_status'],
        'points_frozen'  => $pending,
    ], 'consent_pending' => $pending, 'consent_code' => $codeSent]);
});

/**
 * 换发新卡 —— 过期、损坏、挂失都走这里。
 *
 * 积分、计次、未兑换的券全部结转（它们挂在 member 上，卡只是钥匙）。
 * 这是「卡片有有效期、而积分不因此损失」这条规则的落地点：
 * 卡面印着到期日作为告知证据，客人到店换一张就什么都不损失。
 */
/**
 * 查一张卡现在什么状态 —— 客人当面问「我这卡还能用吗」。
 *
 * 后台也有一个（CP 的 /cards/lookup），两个都要留：
 *   · 客人问的是【服务员】，让服务员转告经理再回话，既麻烦又没必要
 *   · 经理仍然需要在后台查（对账、处理投诉、看作废原因）
 *
 * ★ 与后台那个的关键差别：这里【不返回手机号和邮箱】。
 *   卡片本来就是不实名的，服务员没有任何理由看到客人的联系方式 ——
 *   同一个道理，后台关闭「允许收集联系方式」时 Pad 上连输入框都不渲染。
 *   查卡是为了回答「还能用吗」，不是为了翻客人的档案。
 *
 * 只读：不写库、不留痕、不需要卡背 PIN。防线加在会掉钱的地方（核销），
 * 不是加在所有地方。
 */
$api->on('POST', '/card/status', static function () use ($app, $requireOperator): void {
    $requireOperator();
    $raw = Api::str(Api::body(), 'card_no', '') ?: '';
    if (trim($raw) === '') {
        Api::fail('card_required');
    }

    $r    = $app->cardService()->lookup($raw);
    $card = $r['card'] ?? null;
    // 过期卡、作废卡都【要能查到】—— 客人拿着卡来问，
    // 回一句「查无此卡」是错的，得告诉他为什么不能用
    if ($card === null) {
        Api::fail((string)($r['error'] ?? 'card_unknown'), Api::NOT_FOUND);
    }

    $out = [
        'state'       => $r['state'],
        'card_no'     => $app->cardNumber()->format((string)$card['card_no']),
        'status'      => (int)$card['status'],
        'valid_to'    => $card['valid_to'],
        'days_left'   => \Vip\Repo\CardRepo::daysLeft($card, $app->businessDay()->today()),
        'expired'     => \Vip\Repo\CardRepo::isExpired($card, $app->businessDay()->today()),
        'grace_over'  => \Vip\Repo\CardRepo::graceOver($card, $app->cardService()->graceMonths(), $app->businessDay()->today()),
        'void_reason' => $card['void_reason'],
        // 等级：不分级时为 null，前端据此不显示这一栏
        'tier'        => $app->cardTiers()->describe($card['tier_code'] ?? null),
    ];

    $m = $r['member'] ?? null;
    if ($m !== null) {
        $mid = (int)$m['id'];
        $out['member'] = [
            'points_balance' => (int)$m['points_balance'],
            'visit_count'    => (int)$m['visit_count'],
            'points_frozen'  => (int)$m['consent_status'] !== 1,
            // 「我还能换免费餐吗」才是客人真正想问的
            'coupons'        => count($app->rewards()->availableFor($mid)),
            'progress'       => $app->rewards()->progress($mid),
        ];
        // 规则文案也要按【这位客人的等级】说 —— 金卡 8 次送 1 次时，
        // 屏幕上却写着「每满 10 次」，服务员照着念就是错的
        $out['rule'] = $app->rewards()->ruleText($app->cardTiers()->forMember($mid));
    }
    Api::ok($out);
});

$api->on('POST', '/card/replace', static function () use ($app, $requireOperator): void {
    $op     = $requireOperator();
    $b      = Api::body();
    $mid    = Api::int($b, 'member_id', 0);
    $newNo  = Api::str($b, 'card_no', '') ?: '';
    $reason = Api::str($b, 'reason', '') ?: '换发新卡';

    if ($mid <= 0 || trim($newNo) === '') {
        Api::fail('bad_request', 400, ['hint' => '需要会员与新卡号']);
    }

    /**
     * 超过宽限期的卡，前台换不了 —— 经理带原因才放行。
     * 客户端只有在拿到 grace_over 之后才该带这个字段上来。
     */
    $force    = Api::str($b, 'force_reason', '') ?: '';
    $override = trim($force) === '' ? null : ['reason' => $force];

    $r = $app->cardService()->replaceCard($mid, $newNo, $reason, $op, $override);
    if (!$r['ok']) {
        // grace_over 不是「出错了」，是「这一步需要经理」——
        // 把判定依据一并带回，Pad 才能把话说清楚
        if (($r['error'] ?? '') === 'grace_over') {
            Api::fail('grace_over', 409, [
                'old_valid_to' => $r['old_valid_to'] ?? null,
                'grace_months' => $r['grace_months'] ?? null,
            ]);
        }
        Api::fail((string)$r['error'], 400);
    }

    $m = $app->members()->findById($mid);
    Api::ok([
        'card_no'  => $app->cardNumber()->format((string)$r['card']['card_no']),
        'valid_to' => $r['card']['valid_to'],
        'forced'   => (bool)($r['forced'] ?? false),
        'member'   => $m === null ? null : [
            'id'             => (int)$m['id'],
            'card_no'        => $app->cardNumber()->format((string)$m['card_no']),
            'points_balance' => (int)$m['points_balance'],
            'visit_count'    => (int)$m['visit_count'],
            'consent_status' => (int)$m['consent_status'],
            'points_frozen'  => (int)$m['consent_status'] !== 1,
        ],
    ]);
});

/**
 * 重发确认码。客人没收到、码过期、或连续输错锁住时用。
 * 每次重发都换一个新码，旧码立即作废。
 */
$api->on('POST', '/consent/send', static function () use ($app, $requireOperator): void {
    $op  = $requireOperator();
    $mid = Api::int(Api::body(), 'member_id', 0);
    if ($mid <= 0) {
        Api::fail('bad_request');
    }
    $r = $app->consent()->sendCode($mid, $op);
    if (!$r['ok']) {
        Api::fail((string)$r['error'], 400);
    }
    Api::ok(['channel' => $r['channel'], 'expires_at' => $r['expires_at']]);
});

/** 校验确认码。通过则积分解冻。 */
$api->on('POST', '/consent/verify', static function () use ($app, $requireOperator): void {
    $op   = $requireOperator();
    $b    = Api::body();
    $mid  = Api::int($b, 'member_id', 0);
    $code = Api::str($b, 'code', '') ?: '';
    if ($mid <= 0 || trim($code) === '') {
        Api::fail('bad_request');
    }
    $r = $app->consent()->verifyCode($mid, $code, $op, Api::clientIp());
    if (!$r['ok']) {
        Api::fail((string)$r['error'], 400,
            isset($r['left']) ? ['left' => $r['left']] : []);
    }
    Api::ok(['consent_status' => 1, 'points_frozen' => false]);
});

// ════════════════════════════════════════════════════════════
// 积分
// ════════════════════════════════════════════════════════════

/**
 * 发放积分。三种记账方式共用此接口，差别只在 allocations 的构造：
 *   1 整单   —— 一个会员拿全额与全部份数
 *   2 均摊AA —— 由前端调 /points/split 得到分摊结果后提交
 *   3 点选菜品 —— 前端按认领的菜品汇总金额与份数
 */
$api->on('POST', '/points/grant', static function () use ($app, $requireOperator): void {
    $op     = $requireOperator();
    $b      = Api::body();
    $serial = Api::str($b, 'serial_id', '');
    $mode   = Api::int($b, 'mode', PE::MODE_WHOLE);
    $allocs = $b['allocations'] ?? [];

    if ($serial === '' || !is_array($allocs) || !$allocs) {
        Api::fail('bad_request');
    }
    if (!in_array($mode, [PE::MODE_WHOLE, PE::MODE_SPLIT, PE::MODE_PICK], true)) {
        Api::fail('bad_request');
    }
    /**
     * ★ 先把条数框住，再开始逐条转换。
     *
     *   一张桌子最多坐十几个人，这个上限任何正常客户端都碰不到。
     *   加它不是因为观测到了卡死 —— 实测 10 万条（4.7 MB）也只是
     *   1.77 秒后被 member_already_on_order 挡掉，业务闸门跑在前面。
     *   加它是因为【那是巧合】：闸门在 grantOne 里，而 Money::toCents
     *   这一趟循环在闸门之前，条数完全由客户端说了算。
     *   哪天有人把校验顺序挪一下，这里就成了一个无界循环。
     *   而 POS 主机性能极度受限，一次 1.77 秒的空转本身也不该白给。
     */
    if (count($allocs) > 100) {
        Api::fail('too_many_members', 400, ['given' => count($allocs), 'max' => 100]);
    }

    $clean = [];
    foreach ($allocs as $a) {
        $clean[] = [
            'member_id'    => (int)($a['member_id'] ?? 0),
            'amount_cents' => Money::toCents((string)($a['amount'] ?? '0')),
            'portions'     => (int)($a['portions'] ?? 0),
            'detail'       => $a['detail'] ?? null,
        ];
    }

    // 撞了防刷闸门时，经理可以带原因强制放行（docs/03 §12）
    $reason   = trim((string)(Api::str($b, 'override_reason', '') ?? ''));
    $override = $reason === '' ? null : ['reason' => $reason];

    $r = $app->points()->grant($serial, $clean, $mode, [
        'id' => $op['id'], 'name' => $op['name'], 'device' => $op['device'],
        'role' => $op['role'] ?? 0, 'is_manager' => $op['is_manager'] ?? false,
    ], $override);

    // 发分成功后检查奖励达标（N 送 1）。
    // 放在事务外：发券失败不该把已经记好的积分一起回滚，
    // 而且 checkAndGrant 本身靠 rewards_issued 幂等，下次记账会自动补上。
    $rewards = [];
    if (($r['ok'] ?? false) === true) {
        foreach (array_unique($r['member_ids'] ?? []) as $mid) {
            $g = $app->rewards()->checkAndGrant((int)$mid,
                ['id' => $op['id'], 'name' => $op['name']]);
            if (($g['granted'] ?? 0) > 0 || ($g['pending'] ?? 0) > 0) {
                $m = $app->members()->findById((int)$mid);
                $rewards[] = [
                    'member_id' => (int)$mid,
                    'card_no'   => $m['card_no'] ?? '',
                    'granted'   => $g['granted'],
                    'pending'   => $g['pending'],
                    'coupons'   => $g['coupons'],
                ];
            }
        }
    }
    Api::fromResult($r, ['entries' => $r['entries'] ?? [], 'rewards' => $rewards]);
});

/**
 * 多桌合并记账 —— 同行分桌，几桌的积分整单记进同一张卡。
 *
 * 场景见 docs/03 §12.2：一大帮人坐了三桌、一起结账，
 * 自愿把三桌的分都记到其中一位的卡上。
 *
 * ★ 只有整单模式。合并之后再 AA 或点选菜品没有意义 ——
 *   会走到这条路上本身就意味着「不用再分了，都算一个人的」。
 */
$api->on('POST', '/points/grant-merged', static function () use ($app, $requireOperator): void {
    $op      = $requireOperator();
    $b       = Api::body();
    $serials = $b['serial_ids'] ?? [];
    $mid     = Api::int($b, 'member_id', 0);

    if (!is_array($serials) || !$serials || $mid <= 0) {
        Api::fail('bad_request');
    }
    $reason   = trim((string)(Api::str($b, 'override_reason', '') ?? ''));
    $override = $reason === '' ? null : ['reason' => $reason];

    $r = $app->points()->grantMerged(
        array_map(static fn($v): string => (string)$v, $serials),
        $mid,
        ['id' => $op['id'], 'name' => $op['name'], 'device' => $op['device'],
         'role' => $op['role'] ?? 0, 'is_manager' => $op['is_manager'] ?? false],
        $override
    );

    // 与单桌记账同理：发券放在事务外，失败不该把已记好的积分一起回滚
    $rewards = [];
    if (($r['ok'] ?? false) === true) {
        $g = $app->rewards()->checkAndGrant($mid, ['id' => $op['id'], 'name' => $op['name']]);
        if (($g['granted'] ?? 0) > 0 || ($g['pending'] ?? 0) > 0) {
            $m = $app->members()->findById($mid);
            $rewards[] = [
                'member_id' => $mid, 'card_no' => $m['card_no'] ?? '',
                'granted' => $g['granted'], 'pending' => $g['pending'], 'coupons' => $g['coupons'],
            ];
        }
    }
    Api::fromResult($r, [
        'group'   => $r['group']   ?? null,
        'entries' => $r['entries'] ?? [],
        'forced'  => $r['forced']  ?? false,
        'rewards' => $rewards,
    ]);
});

/** 整组撤销 —— 合并是一次操作，撤销也该是一次操作 */
$api->on('POST', '/points/reverse-group', static function () use ($app, $requireOperator): void {
    $op     = $requireOperator();
    $b      = Api::body();
    $group  = Api::str($b, 'group', '') ?: '';
    $reason = Api::str($b, 'reason', '') ?: '';
    if ($group === '' || trim($reason) === '') {
        Api::fail('bad_request');
    }
    $r = $app->points()->reverseGroup($group, $reason, [
        'id' => $op['id'], 'name' => $op['name'], 'device' => $op['device'],
        'role' => $op['role'] ?? 0, 'is_manager' => $op['is_manager'] ?? false,
    ]);
    Api::fromResult($r, ['count' => $r['count'] ?? 0]);
});

/** 某会员的奖励进度与可用券 —— Pad 选中会员后显示 */
$api->on('POST', '/member/rewards', static function () use ($app, $requireOperator): void {
    $requireOperator();
    $b   = Api::body();
    $mid = Api::int($b, 'member_id', 0);
    if ($mid <= 0) {
        Api::fail('bad_request');
    }
    Api::ok([
        // 按这位会员的等级说 —— 他是金卡就该看到金卡的门槛
        'rule'      => $app->rewards()->ruleText($app->cardTiers()->forMember($mid)),
        'progress'  => $app->rewards()->progress($mid),
        'available' => $app->rewards()->availableFor($mid),
    ]);
});

/** 核销一张奖励券 */
/**
 * 核销免费餐券 —— 整条链路上唯一真正会造成损失的一步。
 *
 * 必须验卡背 PIN：二维码印在卡正面可被拍照，PIN 藏在刮开层下，
 * 只有真正拿到卡的人知道。
 *
 * force + reason 是经理强制核销：PIN 用 bcrypt 存、不可还原，
 * 客人忘了或卡背磨花了谁也查不出来，必须留这条路。它要经理权限、
 * 必须填原因，并单独记 coupon_redeem_forced 审计事件。
 */
$api->on('POST', '/coupon/redeem', static function () use ($app, $requireOperator): void {
    $op  = $requireOperator();
    $b   = Api::body();
    $cid = Api::int($b, 'coupon_id', 0);
    $ser = Api::str($b, 'serial_id');
    $pin = Api::str($b, 'pin');
    $force  = !empty($b['force']);
    $reason = Api::str($b, 'reason', '') ?: '';

    if ($cid <= 0) {
        Api::fail('bad_request');
    }

    $r = $app->rewards()->redeem(
        $cid, $ser ?: null,
        ['id' => $op['id'], 'name' => $op['name'], 'role' => $op['role'] ?? 0],
        $pin,
        $force ? ['reason' => $reason] : null
    );
    Api::fromResult($r, [
        'code'         => $r['code'] ?? null,
        'forced'       => $r['forced'] ?? false,
        'locked_until' => $r['locked_until'] ?? null,
        // ★ 核销之后这一单还剩多少可分 —— Pad 据此重算分摊（审计 F9）
        'order'        => $r['order'] ?? null,
    ]);
});

/** 均摊计算 —— 放服务端算，保证与落库口径完全一致（余数给第一位） */
$api->on('POST', '/points/split', static function () use ($app, $requireOperator): void {
    $requireOperator();
    $b      = Api::body();
    $serial = Api::str($b, 'serial_id', '');
    $n      = Api::int($b, 'people', 0);
    if ($serial === '' || $n < 1 || $n > 50) {
        Api::fail('bad_request');
    }
    $o = $app->orders()->findBySerial($serial);
    if ($o === null) {
        Api::fail('order_not_found', Api::NOT_FOUND);
    }
    $remainCents = Money::toCents($o['total_amount']) - Money::toCents($o['allocated_amount']);
    $remainPort  = (int)$o['portions_counted'] - (int)$o['allocated_portions'];

    $parts = PE::splitEvenly(max(0, $remainCents), max(0, $remainPort), $n);

    /**
     * ★ once_per_period 口径下，每人就是【1 份】，不按剩余份数摊。
     *
     *   splitEvenly 会把 8 份摊给 7 个人成 [2,1,1,1,1,1,1] —— 第一位拿 2 份。
     *   而在这个口径下多出来的那 1 份对他毫无意义（照样只记 1 次），
     *   却会让界面上出现一个「2」，看着像哪里算错了。
     *
     *   人数已经被 too_many_members 限制在 ≤ 份数以内，
     *   所以每人 1 份的合计一定不超。仍然按剩余份数兜一道底：
     *   万一人数比份数多（绕开界面直接调的），排在后面的人拿 0 份，
     *   由服务端的 exceeds_portions / portions_without_amount 接住。
     */
    $perPerson = $app->cfg()->get('visit_count_mode', 'once_per_period') === 'once_per_period' ? 1 : null;
    $left      = max(0, $remainPort);
    $shares    = [];
    foreach ($parts as $p) {
        $give = $p['portions'];
        if ($perPerson !== null) {
            // 没分到钱的人不给份 —— 与 splitEvenly / portions_without_amount 同一条规则
            $give = ($p['amount_cents'] > 0 && $left > 0) ? min($perPerson, $left) : 0;
            $left -= $give;
        }
        $shares[] = ['amount' => Money::toStr($p['amount_cents']), 'portions' => $give];
    }
    Api::ok(['shares' => $shares, 'portions_per_person' => $perPerson]);
});

/** 撤销 —— 追加反向冲正，不物理删除 */
$api->on('POST', '/points/reverse', static function () use ($app, $requireOperator): void {
    $op       = $requireOperator();
    $b        = Api::body();
    $ledgerId = Api::int($b, 'ledger_id', 0);
    $reason   = Api::str($b, 'reason', '') ?: '';
    if ($ledgerId <= 0) {
        Api::fail('bad_request');
    }
    $r = $app->points()->reverse($ledgerId, $reason, [
        'id' => $op['id'], 'name' => $op['name'], 'device' => $op['device'],
        'is_manager' => $op['is_manager'],
    ]);
    Api::fromResult($r);
});

/** 手工录入 —— 主库数据缺失或不可达时的降级路径 */
$api->on('POST', '/points/manual', static function () use ($app, $requireOperator): void {
    $op       = $requireOperator();
    $b        = Api::body();
    $memberId = Api::int($b, 'member_id', 0);
    $amount   = Money::toCents((string)($b['amount'] ?? '0'));
    $reason   = Api::str($b, 'reason_code', 'other') ?: 'other';

    if ($memberId <= 0 || $amount <= 0) {
        Api::fail('bad_request');
    }
    if (!in_array($reason, ['system_not_found', 'network_error', 'other'], true)) {
        Api::fail('bad_request');
    }

    $r = $app->points()->manualGrant($memberId, $amount, $reason, [
        'id' => $op['id'], 'name' => $op['name'], 'device' => $op['device'],
        // 超限时经理身份即视为已审批
        'approved_by' => $op['is_manager'] ? $op['id'] : null,
    ]);

    /**
     * ── 🔴 手工录入也要查一次达标（审计 F7） ──────────────────
     *
     * manualGrant 会 applyDelta 把 total_spent 加上去，但从来没调过
     * checkAndGrant。于是 reward_mode = amount 时：客人这一笔正好跨过
     * 门槛，券【不发】—— 而客人就站在柜台前等着。
     *
     * 靠「下次记账自愈」不成立：手工录入本来就是 POS 查不到单时的
     * 降级路径（docs/03 §10），发生的当天往往正是最容易出岔子的那天；
     * 而且客人可能就是拿着这一顿来兑的。
     *
     * ★ 与 /points/grant 完全同构：放在事务外、幂等靠 rewards_issued、
     *   一条路怎么做另一条就怎么做（docs/13 §3.5）。
     * ★ checkAndGrant 里那条 reward_from_manual_entry 告警本来就是
     *   为这条路写的 —— 之前根本没被触发过，因为压根没走到。
     */
    $rewards = [];
    if (($r['ok'] ?? false) === true) {
        $g = $app->rewards()->checkAndGrant($memberId, ['id' => $op['id'], 'name' => $op['name']]);
        if (($g['granted'] ?? 0) > 0 || ($g['pending'] ?? 0) > 0) {
            $m = $app->members()->findById($memberId);
            $rewards[] = [
                'member_id' => $memberId, 'card_no' => $m['card_no'] ?? '',
                'granted' => $g['granted'], 'pending' => $g['pending'], 'coupons' => $g['coupons'],
            ];
        }
    }
    Api::fromResult($r, ['rewards' => $rewards]);
});

/** 某会员近期流水（Pad 上查「刚才记给谁了」用） */
$api->on('POST', '/points/recent', static function () use ($app, $requireOperator): void {
    $requireOperator();
    $b  = Api::body();
    $id = Api::int($b, 'member_id', 0);
    if ($id <= 0) {
        Api::fail('bad_request');
    }
    $rows = $app->ledger()->recentByMember($id, 20);
    Api::ok(['entries' => array_map(static fn($r) => [
        'id'         => (int)$r['id'],
        'serial_id'  => $r['serial_id'],
        'entry_type' => (int)$r['entry_type'],
        'amount'     => $r['amount'],
        'points'     => (int)$r['points'],
        'visits'     => (int)$r['counted_visit'],
        'status'     => (int)$r['status'],
        'created_at' => $r['created_at'],
        'operator'   => $r['operator_name'],
    ], $rows)]);
});

return $api;
