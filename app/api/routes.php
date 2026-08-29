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
    try {
        $app->posReader()->now();
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

    Api::ok([
        'local_db' => $localOk, 'pos_db' => $posOk, 'pos_note' => $posMsg,
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
    return [
        // 关闭时 Pad 完全不显示手机号/邮箱/生日输入框，后端也拒收
        'collect_pii' => $app->cfg()->bool('member_collect_pii', false),
        // 有效期相关的两个阈值，Pad 拿它决定什么时候提醒换卡
        'expiring_soon_days' => $app->cardService()->expiringSoonDays(),
        'grace_months'       => $app->cardService()->graceMonths(),
        // 还没选过语言的账号用这个；已选过的以 operator.lang 为准
        'default_lang'       => \Vip\Lang::normalize($app->cfg()->get('default_lang', \Vip\Lang::FALLBACK)),
        'langs'              => \Vip\Lang::ALL,
    ];
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
    $win = Api::int($b, 'window_minutes', 0);
    $r   = $app->points()->locate($table, $win > 0 ? $win : null);

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
$api->on('POST', '/order/locate-invoice', static function () use ($app, $requireOperator): void {
    $requireOperator();
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
    Api::ok([
        'invoice_no' => $invoice,
        'reason'     => $r['reason'] ?? null,
        'max_days'   => $r['max_days'] ?? null,
        'order_end_time' => $r['order_end_time'] ?? null,
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
    $app->audit()->log('coupon_redeem', [
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
$api->on('POST', '/member/search', static function () use ($app, $requireOperator): void {
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
$api->on('POST', '/card/lookup', static function () use ($app, $requireOperator): void {
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
        'days_left' => \Vip\Repo\CardRepo::daysLeft($card),
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
        'days_left'   => \Vip\Repo\CardRepo::daysLeft($card),
        'expired'     => \Vip\Repo\CardRepo::isExpired($card),
        'grace_over'  => \Vip\Repo\CardRepo::graceOver($card, $app->cardService()->graceMonths()),
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
    Api::ok(['shares' => array_map(static fn($p) => [
        'amount'   => Money::toStr($p['amount_cents']),
        'portions' => $p['portions'],
    ], $parts)]);
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
    Api::fromResult($r);
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
