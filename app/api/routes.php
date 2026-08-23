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
    Api::ok(['local_db' => $localOk, 'pos_db' => $posOk, 'pos_note' => $posMsg]);
});

// ════════════════════════════════════════════════════════════
// 身份
// ════════════════════════════════════════════════════════════

$api->on('POST', '/auth/login', static function () use ($auth): void {
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
    Api::ok(['operator' => $r['operator']]);
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

$api->on('GET', '/auth/me', static function () use ($requireOperator): void {
    Api::ok(['operator' => $requireOperator()]);
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
        Api::fail('order_not_found', 404);
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
    if (!$r['ok']) {
        Api::fail((string)$r['error'], 404);
    }

    $card = $r['card'];
    $out  = [
        'state'   => $r['state'],
        'card_no' => $app->cardNumber()->format((string)$card['card_no']),
        'serial'  => (int)$card['serial'],
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
     * 手机号与邮箱都是【选填】。
     *
     * 实体卡默认不实名：凭卡号 + 卡背 PIN 就能积分与兑换，系统里不存
     * 任何可识别到人的数据，因此没有可同意的对象，积分直接生效。
     * 客人自愿留联系方式时才走双重确认那一套（详见 MemberRepo::create）。
     */

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

    // 留了联系方式的才需要双重确认；全匿名的卡当场就可用
    // TODO(下一批)：留了联系方式时，出站调用短信/邮件服务商发送 double opt-in
    $pending = (int)$m['consent_status'] !== 1;
    Api::ok(['member' => [
        'id' => (int)$m['id'],
        'card_no' => $app->cardNumber()->format((string)$m['card_no']),
        'points_balance' => 0, 'visit_count' => 0,
        'consent_status' => (int)$m['consent_status'],
        'points_frozen'  => $pending,
    ], 'consent_pending' => $pending]);
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

    $r = $app->points()->grant($serial, $clean, $mode, [
        'id' => $op['id'], 'name' => $op['name'], 'device' => $op['device'],
    ]);

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

/** 某会员的奖励进度与可用券 —— Pad 选中会员后显示 */
$api->on('POST', '/member/rewards', static function () use ($app, $requireOperator): void {
    $requireOperator();
    $b   = Api::body();
    $mid = Api::int($b, 'member_id', 0);
    if ($mid <= 0) {
        Api::fail('bad_request');
    }
    Api::ok([
        'rule'      => $app->rewards()->ruleText(),
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
        Api::fail('order_not_found', 404);
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
