<?php
declare(strict_types=1);

/**
 * 管理平台（CP）API 路由。
 *
 * 由 /wwwroot/cp/api.php 引导后 require。
 * 全部接口要求经理及以上权限；规则表与配置的写入要求管理员。
 *
 * @var \Vip\App $app
 */

use Vip\Http\Api;
use Vip\Money;
use Vip\Repo\LedgerRepo;
use Vip\Service\AuthService;

$api = new Api();

// 惰性构造：本地库不可达时也要能返回 JSON 而不是空响应体
$authRef = null;
$auth = static function () use ($app, &$authRef): AuthService {
    return $authRef ??= new AuthService($app->localDb(), $app->storeCode(), $app->audit());
};

$requireManager = static function () use ($auth): array {
    $op = $auth()->resolve(Api::readToken());
    if ($op === null) {
        Api::fail('unauthorized', 401);
    }
    if (!$op['is_manager']) {
        Api::fail('forbidden', 403);
    }
    return $op;
};
$requireAdmin = static function () use ($requireManager): array {
    $op = $requireManager();
    if ($op['role'] < AuthService::ROLE_ADMIN) {
        Api::fail('forbidden', 403);
    }
    return $op;
};

// ════════════════════════════════════════════════════════════
// 身份（复用 Pad 的会话）
// ════════════════════════════════════════════════════════════

/**
 * 后台要长期挂出来的提醒。跟着登录与 /auth/me 下发，前端渲染成顶部红条。
 * 目前只有一条：开了实名但确认短信还没接入。
 */
$warnings = static function () use ($app): array {
    return \Vip\Features::warnings(
        $app->cfg()->bool('member_collect_pii', false),
        $app->messaging()->readyChannels()
    );
};

/** 有没有配齐可用的发送渠道。前端据此决定开启实名前要不要先拦一下 */
$smsReady = static function () use ($app): bool {
    return (bool)$app->messaging()->readyChannels();
};

$api->on('POST', '/auth/login', static function () use ($auth, $warnings, $smsReady): void {
    $b = Api::body();
    $r = $auth()->login(
        Api::str($b, 'login_name', '') ?: '',
        Api::str($b, 'pin', '') ?: '',
        'CP',
        Api::clientIp()
    );
    if (!$r['ok']) {
        Api::fail((string)$r['error'], $r['error'] === 'locked' ? 423 : 401);
    }
    if (!$r['operator']['is_manager']) {
        // 服务员不得进入后台
        $auth()->logout((string)$r['token']);
        Api::fail('forbidden', 403);
    }
    Api::setToken((string)$r['token'], 12 * 3600);
    Api::ok(['operator' => $r['operator'], 'warnings' => $warnings(),
             'sms_ready' => $smsReady()]);
});

$api->on('POST', '/auth/logout', static function () use ($auth): void {
    $auth()->logout(Api::readToken());
    Api::clearToken();
    Api::ok();
});

$api->on('GET', '/auth/me', static function () use ($requireManager, $warnings, $smsReady): void {
    Api::ok(['operator' => $requireManager(), 'warnings' => $warnings(),
             'sms_ready' => $smsReady()]);
});

// ════════════════════════════════════════════════════════════
// 概览
// ════════════════════════════════════════════════════════════

$api->on('GET', '/dashboard', static function () use ($app, $requireManager): void {
    $requireManager();
    $db    = $app->localDb();
    $store = $app->storeCode();
    $today = $app->businessDay()->of(date('Y-m-d H:i:s'));

    $row = static fn(string $sql, array $p = []) => (int)$db->value($sql, $p);

    Api::ok([
        'business_date' => $today,
        'orders_today'  => $row('SELECT COUNT(*) FROM pos_order WHERE store_code=? AND business_date=?', [$store, $today]),
        'granted_today' => $row('SELECT COUNT(*) FROM point_ledger WHERE store_code=? AND entry_type=1 AND status=1 AND created_at>=?',
                                [$store, date('Y-m-d 00:00:00')]),
        'points_today'  => $row('SELECT COALESCE(SUM(points),0) FROM point_ledger WHERE store_code=? AND created_at>=?',
                                [$store, date('Y-m-d 00:00:00')]),
        'members_total' => $row('SELECT COUNT(*) FROM member WHERE store_code=? AND pseudonymized=0', [$store]),
        'members_pending' => $row('SELECT COUNT(*) FROM member WHERE store_code=? AND consent_status=0', [$store]),
        'reviews_pending' => $row('SELECT COUNT(*) FROM point_ledger WHERE store_code=? AND review_status=1', [$store]),
        'alerts_open'   => $row('SELECT COUNT(*) FROM alert WHERE store_code=? AND status=0', [$store]),
        'alerts_severe' => $row('SELECT COUNT(*) FROM alert WHERE store_code=? AND status=0 AND severity=3', [$store]),
        'cursors'       => array_map(static function (array $c): array {
            $lag = (time() - strtotime((string)$c['watermark'])) / 3600;
            return [
                'name'        => $c['cursor_name'],
                'watermark'   => $c['watermark'],
                'lag_hours'   => round($lag, 1),
                'stale'       => $lag > 72,     // 超 72 小时说明 Cron 可能长期未成功
                'last_run_at' => $c['last_run_at'],
                'last_status' => (int)($c['last_status'] ?? 0),
                'last_error'  => $c['last_error'],
            ];
        }, $app->cursors()->all()),
    ]);
});

// ════════════════════════════════════════════════════════════
// 告警
// ════════════════════════════════════════════════════════════

$api->on('GET', '/alerts', static function () use ($app, $requireManager): void {
    $requireManager();
    Api::ok(['alerts' => array_map(static fn($a) => [
        'id'         => (int)$a['id'],
        'type'       => $a['alert_type'],
        'severity'   => (int)$a['severity'],
        'ref_type'   => $a['ref_type'],
        'ref_id'     => $a['ref_id'],
        'message'    => $a['message'],
        'detail'     => $a['detail'] ? json_decode((string)$a['detail'], true) : null,
        'created_at' => $a['created_at'],
    ], $app->alerts()->open(100))]);
});

$api->on('POST', '/alerts/handle', static function () use ($app, $requireManager): void {
    $op  = $requireManager();
    $b   = Api::body();
    $id  = Api::int($b, 'id', 0);
    $st  = Api::int($b, 'status', 1);      // 1=已处理 2=已忽略
    if ($id <= 0 || !in_array($st, [1, 2], true)) {
        Api::fail('bad_request');
    }
    $app->localDb()->exec(
        'UPDATE alert SET status=?, handled_by=?, handled_at=? WHERE store_code=? AND id=?',
        [$st, $op['id'], $app->localDb()->now(), $app->storeCode(), $id]
    );
    Api::ok(['id' => $id, 'status' => $st]);
});

// ════════════════════════════════════════════════════════════
// 待复核（手工录入）
// ════════════════════════════════════════════════════════════

$api->on('GET', '/reviews', static function () use ($app, $requireManager): void {
    $requireManager();
    $rows = $app->ledger()->pendingReview(100);
    Api::ok(['reviews' => array_map(static fn($r) => [
        'id'            => (int)$r['id'],
        'member_id'     => (int)$r['member_id'],
        'amount'        => $r['amount'],
        'points'        => (int)$r['points'],
        'manual_reason' => $r['manual_reason'],
        'operator'      => $r['operator_name'],
        'device'        => $r['device'],
        'created_at'    => $r['created_at'],
    ], $rows)]);
});

/**
 * 复核裁决。
 * 通过 → 仅标记；驳回 → 追加反向冲正流水（不物理删除，账本只增不删）。
 */
$api->on('POST', '/reviews/decide', static function () use ($app, $requireManager): void {
    $op     = $requireManager();
    $b      = Api::body();
    $id     = Api::int($b, 'id', 0);
    $accept = (bool)($b['accept'] ?? true);
    $reason = Api::str($b, 'reason', '') ?: '';
    if ($id <= 0) {
        Api::fail('bad_request');
    }

    if ($accept) {
        $app->localDb()->exec(
            'UPDATE point_ledger SET review_status=2, approved_by=? WHERE store_code=? AND id=? AND review_status=1',
            [$op['id'], $app->storeCode(), $id]
        );
        $app->audit()->log('review_accept', [
            'target_type' => 'ledger', 'target_id' => (string)$id,
            'operator_id' => $op['id'], 'operator_name' => $op['name'],
        ]);
        Api::ok(['id' => $id, 'accepted' => true]);
    }

    $r = $app->points()->reverse($id, '后台复核驳回：' . $reason, [
        'id' => $op['id'], 'name' => $op['name'], 'device' => 'CP', 'is_manager' => true,
    ]);
    if (!$r['ok']) {
        Api::fromResult($r);
    }
    $app->localDb()->exec(
        'UPDATE point_ledger SET review_status=3 WHERE store_code=? AND id=?',
        [$app->storeCode(), $id]
    );
    Api::ok(['id' => $id, 'accepted' => false, 'reversal_id' => $r['reversal_id']]);
});

// ════════════════════════════════════════════════════════════
// 套餐规则表 —— 三个开关
// ════════════════════════════════════════════════════════════

$api->on('GET', '/rules', static function () use ($app, $requireManager): void {
    $requireManager();
    Api::ok([
        'rules' => array_map(static fn($r) => [
            'menu_item_id' => (int)$r['menu_item_id'],
            'item_name'    => $r['item_name'],
            'ref_price'    => $r['ref_price'],
            'is_meal_fee'  => (bool)(int)$r['is_meal_fee'],
            'counts_visit' => (bool)(int)$r['counts_visit'],
            'earns_points' => (bool)(int)$r['earns_points'],
            'enabled'      => (bool)(int)$r['enabled'],
            'updated_at'   => $r['updated_at'],
        ], $app->mealRuleRepo()->all()),
        // 未被规则表覆盖的菜品按此默认值处理
        'defaults' => ['is_meal_fee' => false, 'counts_visit' => false, 'earns_points' => true],
        'note'     => '漏配一个菜品的后果仅是少计次，不会算错金额、不会误报免费餐',
    ]);
});

$api->on('POST', '/rules/save', static function () use ($app, $requireAdmin): void {
    $op = $requireAdmin();
    $b  = Api::body();
    $id = Api::int($b, 'menu_item_id', 0);
    if ($id <= 0) {
        Api::fail('bad_request');
    }
    $app->mealRuleRepo()->upsert([
        'menu_item_id' => $id,
        'item_name'    => Api::str($b, 'item_name'),
        'ref_price'    => Api::str($b, 'ref_price'),
        'is_meal_fee'  => (int)(bool)($b['is_meal_fee'] ?? false),
        'counts_visit' => (int)(bool)($b['counts_visit'] ?? false),
        'earns_points' => (int)(bool)($b['earns_points'] ?? true),
        'enabled'      => (int)(bool)($b['enabled'] ?? true),
        'updated_by'   => $op['id'],
    ]);
    $app->audit()->log('rule_save', [
        'target_type' => 'menu_item', 'target_id' => (string)$id,
        'operator_id' => $op['id'], 'operator_name' => $op['name'], 'detail' => $b,
    ]);
    Api::ok(['menu_item_id' => $id]);
});

// ════════════════════════════════════════════════════════════
// 系统配置
// ════════════════════════════════════════════════════════════

$api->on('GET', '/config', static function () use ($app, $requireManager): void {
    $requireManager();
    // 按业务分组返回，每项带中文标签与说明 —— 后台不再是一张平铺的 key-value 表
    Api::ok([
        'groups'      => \Vip\ConfigSchema::grouped($app->cfg()->all()),
        'reward_text' => $app->rewards()->ruleText(),
    ]);
});

$api->on('POST', '/config/save', static function () use ($app, $requireAdmin, $warnings): void {
    $op  = $requireAdmin();
    $b   = Api::body();
    $key = Api::str($b, 'key', '') ?: '';
    $val = Api::str($b, 'value', '') ?? '';
    if ($key === '') {
        Api::fail('bad_request');
    }
    // 按 schema 校验，别让「几次送一次」被填成负数或文字
    $err = \Vip\ConfigSchema::validate($key, $val);
    if ($err !== null) {
        Api::fail('bad_request', 400, ['hint' => $err]);
    }
    $app->cfg()->set($key, $val);
    $app->audit()->log('config_save', [
        'target_type' => 'config', 'target_id' => $key,
        'operator_id' => $op['id'], 'operator_name' => $op['name'],
        'detail' => ['value' => $val],
    ]);
    // 顺带把最新提醒带回去，前端不用为了刷新红条再请求一次
    Api::ok(['key' => $key, 'value' => $val, 'warnings' => $warnings()]);
});

// ════════════════════════════════════════════════════════════
// 奖励券
// ════════════════════════════════════════════════════════════

$api->on('GET', '/coupons', static function () use ($app, $requireManager): void {
    $requireManager();
    $app->rewards()->expireStale();
    $rows = $app->localDb()->all(
        'SELECT c.id, c.code, c.source, c.status, c.valid_to, c.note,
                c.redeemed_at, c.redeemed_serial_id, c.created_at,
                m.card_no, m.phone
           FROM coupon c
           LEFT JOIN member m ON m.id = c.member_id AND m.store_code = c.store_code
          WHERE c.store_code = ?
          ORDER BY c.id DESC
          LIMIT 200',
        [$app->storeCode()]
    );
    Api::ok([
        'rule'    => $app->rewards()->ruleText(),
        'stats'   => $app->rewards()->stats(),
        'coupons' => $rows,
    ]);
});

/** 手工发一张券（补偿、投诉处理），必须写原因 */
$api->on('POST', '/coupons/grant', static function () use ($app, $requireManager): void {
    $op  = $requireManager();
    $b   = Api::body();
    $mid = Api::int($b, 'member_id', 0);
    $note = Api::str($b, 'note', '') ?: '';
    if ($mid <= 0 || trim($note) === '') {
        Api::fail('bad_request', 400, ['hint' => '需要会员与发放原因']);
    }
    $r = $app->rewards()->grantManual($mid, $note, $op);
    Api::fromResult($r, ['coupon' => $r['coupon'] ?? null]);
});

/** 作废一张券 */
$api->on('POST', '/coupons/void', static function () use ($app, $requireManager): void {
    $op  = $requireManager();
    $b   = Api::body();
    $cid = Api::int($b, 'id', 0);
    $why = Api::str($b, 'reason', '') ?: '';
    if ($cid <= 0 || trim($why) === '') {
        Api::fail('bad_request', 400, ['hint' => '需要券与作废原因']);
    }
    Api::fromResult($app->rewards()->void($cid, $why, $op));
});

// ════════════════════════════════════════════════════════════
// 会员
// ════════════════════════════════════════════════════════════

$api->on('POST', '/members/search', static function () use ($app, $requireManager): void {
    $requireManager();
    $b    = Api::body();
    $type = Api::str($b, 'type', 'card');
    $val  = Api::str($b, 'value', '') ?: '';
    if ($val === '' || !in_array($type, ['card', 'phone', 'email'], true)) {
        Api::fail('bad_request');
    }
    $m = $app->members()->findBy($type, $val);
    if ($m === null) {
        Api::ok(['found' => false]);
    }
    Api::ok(['found' => true, 'member' => [
        'id' => (int)$m['id'], 'card_no' => $m['card_no'],
        'phone' => $m['phone'], 'email' => $m['email'], 'birthday' => $m['birthday'],
        'points_balance' => (int)$m['points_balance'], 'visit_count' => (int)$m['visit_count'],
        'total_spent' => $m['total_spent'], 'consent_status' => (int)$m['consent_status'],
        'created_at' => $m['created_at'],
    ], 'ledger' => array_map(static fn($l) => [
        'id' => (int)$l['id'], 'serial_id' => $l['serial_id'],
        'entry_type' => (int)$l['entry_type'], 'amount' => $l['amount'],
        'points' => (int)$l['points'], 'visits' => (int)$l['counted_visit'],
        'status' => (int)$l['status'], 'source' => (int)$l['source'],
        'operator' => $l['operator_name'], 'created_at' => $l['created_at'],
    ], $app->ledger()->recentByMember((int)$m['id'], 50))]);
});

/**
 * 数据主体权利 —— 删除请求。
 * 抹除 PII，保留全部流水（会计与税务留存义务）。docs/05 §4
 */
$api->on('POST', '/members/erase', static function () use ($app, $requireAdmin): void {
    $op = $requireAdmin();
    $b  = Api::body();
    $id = Api::int($b, 'member_id', 0);
    if ($id <= 0 || $app->members()->findById($id) === null) {
        Api::fail('member_not_found', Api::NOT_FOUND);
    }
    $app->members()->pseudonymize($id);
    $app->audit()->log('data_erase', [
        'target_type' => 'member', 'target_id' => (string)$id,
        'operator_id' => $op['id'], 'operator_name' => $op['name'],
        'detail' => ['reason' => Api::str($b, 'reason', '数据主体删除请求'),
                     'note' => 'PII 已假名化，积分流水按会计与税务留存义务保留'],
    ]);
    Api::ok(['member_id' => $id, 'pseudonymized' => true]);
});

// ════════════════════════════════════════════════════════════
// 报表
// ════════════════════════════════════════════════════════════

$api->on('POST', '/report/daily', static function () use ($app, $requireManager): void {
    $requireManager();
    $b    = Api::body();
    $days = min(max(Api::int($b, 'days', 14), 1), 90);
    $from = date('Y-m-d', strtotime("-{$days} days"));

    Api::ok(['rows' => $app->localDb()->all(
        'SELECT o.business_date,
                COUNT(*)                          AS orders,
                COALESCE(SUM(o.total_amount),0)   AS total_amount,
                COALESCE(SUM(o.allocated_amount),0) AS allocated_amount,
                SUM(CASE WHEN o.alloc_status > 0 THEN 1 ELSE 0 END) AS granted_orders,
                SUM(CASE WHEN o.is_free_meal = 1 THEN 1 ELSE 0 END) AS free_meals
           FROM pos_order o
          WHERE o.store_code = ? AND o.business_date >= ?
          GROUP BY o.business_date
          ORDER BY o.business_date DESC',
        [$app->storeCode(), $from]
    )]);
});

// ════════════════════════════════════════════════════════════
// 操作员
// ════════════════════════════════════════════════════════════

$api->on('GET', '/operators', static function () use ($app, $requireManager): void {
    $requireManager();
    Api::ok(['operators' => $app->localDb()->all(
        'SELECT id, login_name, display_name, display_name_es, lang, role, enabled,
                failed_count, locked_until, last_login_at, created_at
           FROM operator WHERE store_code = ? ORDER BY role DESC, id ASC',
        [$app->storeCode()]
    )]);
});

$api->on('POST', '/operators/create', static function () use ($app, $requireAdmin): void {
    $op    = $requireAdmin();
    $b     = Api::body();
    $login  = Api::str($b, 'login_name', '') ?: '';
    $name   = Api::str($b, 'display_name', '') ?: '';
    $nameEs = Api::str($b, 'display_name_es', '') ?: '';
    $pin    = Api::str($b, 'pin', '') ?: '';
    $role   = Api::int($b, 'role', AuthService::ROLE_STAFF);

    if ($login === '' || $name === '' || strlen($pin) < 4) {
        Api::fail('bad_request', 400, ['hint' => 'PIN 至少 4 位']);
    }
    if (!in_array($role, [1, 2, 3], true)) {
        Api::fail('bad_request');
    }
    try {
        $id = $app->auth()->createOperator($login, $name, $pin, $role, $nameEs);
    } catch (\PDOException $e) {
        Api::fail('bad_request', 400, ['hint' => '工号已存在']);
    }
    $app->audit()->log('operator_create', [
        'target_type' => 'operator', 'target_id' => (string)$id,
        'operator_id' => $op['id'], 'operator_name' => $op['name'],
        'detail' => ['login_name' => $login, 'role' => $role],
    ]);
    Api::ok(['id' => $id]);
});

/**
 * 改显示名 —— 两种语言各一个。
 *
 * 顶栏要么全中文要么全西文，靠的就是这两个名字。
 * 西语留空则回落中文名（人名本来就常常两边一样）。
 *
 * 限管理员：改名会改变审计日志里「谁做的」这件事在界面上的呈现。
 */
$api->on('POST', '/operators/rename', static function () use ($app, $requireAdmin): void {
    $op   = $requireAdmin();
    $b    = Api::body();
    $id   = Api::int($b, 'id', 0);
    $zh   = Api::str($b, 'display_name', '') ?: '';
    $es   = Api::str($b, 'display_name_es', '') ?: '';

    if ($id <= 0 || trim($zh) === '') {
        Api::fail('bad_request', 400, ['hint' => '中文显示名不能为空']);
    }
    $row = $app->localDb()->one(
        'SELECT display_name, display_name_es FROM operator WHERE store_code = ? AND id = ?',
        [$app->storeCode(), $id]
    );
    if ($row === null) {
        Api::fail('not_found', Api::NOT_FOUND);
    }
    $app->auth()->renameOperator($id, $zh, $es);
    $app->audit()->log('operator_rename', [
        'target_type' => 'operator', 'target_id' => (string)$id,
        'operator_id' => $op['id'], 'operator_name' => $op['name'],
        'detail' => [
            'from' => [$row['display_name'], $row['display_name_es']],
            'to'   => [$zh, $es !== '' ? $es : null],
        ],
    ]);
    Api::ok(['renamed' => true]);
});

/**
 * 管理员重置他人 PIN（不需要旧 PIN）。
 *
 * 与 toggle 一样限管理员。会一并解掉连续失败锁定，
 * 并作废该账号的全部会话 —— 忘记 PIN 的人通常已经试错到被锁了。
 */
$api->on('POST', '/operators/reset-pin', static function () use ($app, $requireAdmin): void {
    $op  = $requireAdmin();
    $b   = Api::body();
    $id  = Api::int($b, 'id', 0);
    $pin = (string)($b['new_pin'] ?? '');
    if ($id <= 0 || $pin === '') {
        Api::fail('bad_request');
    }
    $r = $app->auth()->resetPin($id, $pin, $op);
    if (!($r['ok'] ?? false)) {
        Api::fail((string)$r['error'], $r['error'] === 'not_found' ? 404 : 400);
    }
    Api::ok(['id' => $id]);
});

/**
 * 改自己的 PIN（管理员与经理都可用；必须验旧 PIN）。
 * 改完保留当前这条会话，其余会话作废。
 */
$api->on('POST', '/auth/change-pin', static function () use ($app, $requireManager): void {
    // CP 只有经理以上能登录，这里用 requireManager 即可
    // （本文件没有 requireOperator —— 守卫必须用本文件定义过的）
    $op  = $requireManager();
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

$api->on('POST', '/operators/toggle', static function () use ($app, $requireAdmin): void {
    $op = $requireAdmin();
    $b  = Api::body();
    $id = Api::int($b, 'id', 0);
    if ($id <= 0) {
        Api::fail('bad_request');
    }
    if ($id === $op['id']) {
        Api::fail('bad_request', 400, ['hint' => '不能停用自己的账号']);
    }
    $app->localDb()->exec(
        'UPDATE operator SET enabled = 1 - enabled, failed_count = 0, locked_until = NULL, updated_at = ?
          WHERE store_code = ? AND id = ?',
        [$app->localDb()->now(), $app->storeCode(), $id]
    );
    Api::ok(['id' => $id]);
});

// ════════════════════════════════════════════════════════════
// 审计日志
// ════════════════════════════════════════════════════════════

$api->on('POST', '/audit', static function () use ($app, $requireManager): void {
    $requireManager();
    $b      = Api::body();
    $action = Api::str($b, 'action', '') ?: '';
    $limit  = min(max(Api::int($b, 'limit', 100), 1), 200);

    $sql = 'SELECT * FROM audit_log WHERE store_code = ?';
    $p   = [$app->storeCode()];
    if ($action !== '') {
        $sql .= ' AND action = ?';
        $p[]  = $action;
    }
    $sql .= ' ORDER BY id DESC LIMIT ' . $limit;

    Api::ok(['logs' => array_map(static fn($r) => [
        'id' => (int)$r['id'], 'action' => $r['action'],
        'target_type' => $r['target_type'], 'target_id' => $r['target_id'],
        'operator' => $r['operator_name'], 'device' => $r['device'],
        'detail' => $r['detail'] ? json_decode((string)$r['detail'], true) : null,
        'created_at' => $r['created_at'],
    ], $app->localDb()->all($sql, $p))]);
});

// ════════════════════════════════════════════════════════════
// 实体卡发放
// ════════════════════════════════════════════════════════════

$api->on('GET', '/cards/batches', static function () use ($app, $requireManager): void {
    $requireManager();
    Api::ok([
        'prefix'      => $app->cardNumber()->prefix(),
        'next_serial' => $app->cards()->nextSerial(),
        'batches'     => array_map(static fn($b) => [
            'batch_no'    => $b['batch_no'],
            'total'       => (int)$b['total'],
            'stock'       => (int)$b['stock'],
            'active'      => (int)$b['active'],
            'void'        => (int)$b['void_cnt'],
            'serial_from' => (int)$b['serial_from'],
            'serial_to'   => (int)$b['serial_to'],
            'valid_to'    => $b['valid_to'],
            'created_at'  => $b['created_at'],
        ], $app->cards()->batches()),
    ]);
});

/**
 * 生成一批卡，返回给印刷厂的清单。
 *
 * 🔴 返回里带【全部卡的明文 PIN】—— 这是一份总钥匙。
 *    库里存的是 bcrypt hash，不可还原，所以这一次响应是明文 PIN 唯一
 *    出现的时刻。窗口一关就再也取不回来，只能作废整批重发。
 *
 * 仅管理员可用：能拿到全批 PIN 的操作，不该开给经理。
 */
$api->on('POST', '/cards/generate', static function () use ($app, $requireAdmin): void {
    $op    = $requireAdmin();
    $b     = Api::body();
    $batch   = Api::str($b, 'batch_no', '') ?: '';
    $count   = Api::int($b, 'count', 0);
    $validTo = Api::str($b, 'valid_to', '') ?: '';

    /**
     * 有效期【必填】。
     *
     * 它会直接印在卡面上，而卡面是唯一的告知证据 —— 客人查不到任何线上
     * 信息，手里只有一张卡。库里的日期与卡面印的必须一致，否则等于没告知。
     *
     * 做成必填而不是给个默认值，是为了每次做卡都强制过一遍脑子：
     * 这批印的是哪个日期？跟发给印刷厂的稿子对得上吗？
     */
    if (trim($validTo) === '') {
        Api::fail('bad_request', 400, ['hint' => '必须填写有效期 —— 它要印在卡面上，是唯一的告知证据']);
    }

    if (trim($batch) === '') {
        // 批次号留空时按日期给一个，同一天多批自动加序号
        $base = 'B' . date('ymd');
        $batch = $base;
        for ($i = 2; $app->cards()->batchExists($batch) && $i < 100; $i++) {
            $batch = $base . '-' . $i;
        }
    }

    try {
        $rows = $app->cards()->generateBatch($batch, $count, $validTo);
    } catch (\InvalidArgumentException $e) {
        Api::fail('bad_request', 400, ['hint' => $e->getMessage()]);
    }

    $app->audit()->log('card_batch_generate', [
        'target_type' => 'card_batch', 'target_id' => strtoupper(trim($batch)),
        'operator_id' => $op['id'], 'operator_name' => $op['name'],
        'detail' => ['count' => count($rows), 'valid_to' => $validTo,
                     'serial_from' => $rows[0]['serial'] ?? null,
                     'serial_to' => $rows[count($rows) - 1]['serial'] ?? null],
    ]);

    Api::ok([
        'batch_no' => strtoupper(trim($batch)),
        'count'    => count($rows),
        'valid_to' => $validTo,
        'rows'     => $rows,
        'warning'  => '这份清单包含全部卡的明文 PIN，是一份总钥匙。'
                    . '库里只存不可还原的 hash，关掉窗口就再也取不回来。'
                    . '请立刻复制保存并交给印刷厂，印完销毁，不要留在邮箱或网盘里。',
    ]);
});

/** 查一张卡现在什么状态 —— 客人来问「我这卡还能用吗」时用 */
$api->on('POST', '/cards/lookup', static function () use ($app, $requireManager): void {
    $requireManager();
    $raw = Api::str(Api::body(), 'card_no', '') ?: '';
    if (trim($raw) === '') {
        Api::fail('card_required');
    }

    $r    = $app->cardService()->lookup($raw);
    $card = $r['card'] ?? null;
    // 过期与作废的卡在后台【要能查到】—— 客人拿着一张卡来问「还能用吗」，
    // 回一句「查无此卡」是错的，得告诉他为什么不能用
    if ($card === null) {
        Api::fail((string)($r['error'] ?? 'card_unknown'), Api::NOT_FOUND);
    }

    $out = [
        'state'        => $r['state'],
        'card_no'      => $app->cardNumber()->format((string)$card['card_no']),
        'serial'       => (int)$card['serial'],
        'batch_no'     => $card['batch_no'],
        'valid_to'     => $card['valid_to'],
        'expired'      => \Vip\Repo\CardRepo::isExpired($card),
        'status'       => (int)$card['status'],
        'activated_at' => $card['activated_at'],
        'voided_at'    => $card['voided_at'],
        'void_reason'  => $card['void_reason'],
        'pin_locked_until' => $card['pin_locked_until'],
    ];
    if (($r['member'] ?? null) !== null) {
        $out['member'] = [
            'id'             => (int)$r['member']['id'],
            'phone'          => $r['member']['phone'],
            'email'          => $r['member']['email'],
            'points_balance' => (int)$r['member']['points_balance'],
            'visit_count'    => (int)$r['member']['visit_count'],
        ];
    }
    Api::ok($out);
});

/**
 * 挂失/作废一张卡。
 *
 * 只作废，不自动换新卡 —— 换卡要当面把新卡交给客人，属于 Pad 端的动作。
 * 这里作废后，该会员会暂时没有卡，下次到店扫新卡时走 replaceCard。
 */
$api->on('POST', '/cards/void', static function () use ($app, $requireManager): void {
    $op     = $requireManager();
    $b      = Api::body();
    $raw    = Api::str($b, 'card_no', '') ?: '';
    $reason = Api::str($b, 'reason', '') ?: '';

    if (trim($raw) === '' || trim($reason) === '') {
        Api::fail('bad_request', 400, ['hint' => '卡号与作废原因都必填']);
    }

    $card = $app->cards()->findByCardNo($raw);
    if ($card === null) {
        Api::fail('card_unknown', Api::NOT_FOUND);
    }
    if ((int)$card['status'] === \Vip\Repo\CardRepo::STATUS_VOID) {
        Api::fail('card_void', 400);
    }

    $memberId = $card['member_id'] !== null ? (int)$card['member_id'] : null;
    $app->cards()->void((int)$card['id'], $reason);

    $app->audit()->log('card_void', [
        'target_type' => 'card', 'target_id' => (string)$card['card_no'],
        'operator_id' => $op['id'], 'operator_name' => $op['name'],
        'detail' => ['reason' => $reason, 'member_id' => $memberId,
                     'serial' => (int)$card['serial']],
    ]);

    Api::ok(['card_no' => $app->cardNumber()->format((string)$card['card_no']),
             'member_id' => $memberId]);
});

return $api;
