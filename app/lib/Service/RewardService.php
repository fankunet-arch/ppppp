<?php
declare(strict_types=1);

namespace Vip\Service;

use Vip\LocalDb;
use Vip\Money;
use Vip\Repo\AuditRepo;
use Vip\Repo\CardRepo;
use Vip\Repo\ConfigRepo;
use Vip\Repo\MemberRepo;

/**
 * 奖励（N 送 1）—— 达标发券、查券、核销。
 *
 * ★ 这一整块此前是空的：coupon 表在 001 就建好了，但没有任何代码写入过。
 *   系统一直在累计 visit_count，可达到阈值之后什么都不会发生 ——
 *   不发券、Pad 不提示、服务员根本不知道客人可以免费吃了。
 *
 * 两种门槛口径（后台可切）：
 *   visits  按次   —— 集满 N 次送 1 次（默认，N 可配）
 *   amount  按金额 —— 累计消费满 X 元送 1 次
 *
 * ★ 达标判定用「floor(进度 / 阈值) - 已发张数」而不是「每次 +1」。
 *   好处是自愈：后台把阈值从 10 改成 8、或事后补录了历史消费，
 *   数量都会自动对上，不会重复发也不会漏发。
 */
final class RewardService
{
    // coupon.source
    public const SRC_VISITS = 1;   // 满次自动发
    public const SRC_AMOUNT = 2;   // 满额自动发
    public const SRC_MANUAL = 3;   // 后台手工发

    // coupon.status
    public const ST_ACTIVE   = 1;  // 可用
    public const ST_REDEEMED = 2;  // 已核销
    public const ST_EXPIRED  = 3;  // 已过期
    public const ST_VOID     = 4;  // 已作废

    public function __construct(
        private LocalDb    $db,
        private string     $storeCode,
        private ConfigRepo $cfg,
        private MemberRepo $members,
        private AuditRepo  $audit,
        private CardRepo   $cards,
    ) {
    }

    // ════════════════════════════════════════════════════════
    // 规则读取
    // ════════════════════════════════════════════════════════

    /** 当前生效的奖励规则，同时供后台展示与 Pad 提示 */
    public function rule(): array
    {
        $mode = $this->cfg->get('reward_mode', 'visits');
        if (!in_array($mode, ['visits', 'amount'], true)) {
            $mode = 'visits';
        }
        return [
            'enabled'          => $this->cfg->get('reward_enabled', '1') === '1',
            'mode'             => $mode,
            'threshold_visits' => max(1, $this->cfg->int('reward_threshold_visits', 10)),
            'threshold_cents'  => max(1, Money::toCents($this->cfg->get('reward_threshold_amount', '300.00'))),
            'auto_grant'       => $this->cfg->get('reward_auto_grant', '1') === '1',
            'valid_days'       => max(0, $this->cfg->int('coupon_valid_days', 90)),
        ];
    }

    /** 规则的人话描述，后台与 Pad 都直接显示这句 */
    public function ruleText(): string
    {
        $r = $this->rule();
        if (!$r['enabled']) {
            return '奖励功能已关闭';
        }
        return $r['mode'] === 'visits'
            ? sprintf('每满 %d 次送 1 次', $r['threshold_visits'])
            : sprintf('累计消费每满 € %s 送 1 次', Money::toStr($r['threshold_cents']));
    }

    // ════════════════════════════════════════════════════════
    // 进度与达标
    // ════════════════════════════════════════════════════════

    /**
     * 某会员的奖励进度。
     *
     * @return array{mode:string,progress:int,threshold:int,issued:int,
     *               earned:int,pending:int,remain:int,text:string}
     */
    public function progress(int $memberId): array
    {
        $m = $this->members->findById($memberId);
        if ($m === null) {
            return ['mode' => 'visits', 'progress' => 0, 'threshold' => 1, 'issued' => 0,
                    'earned' => 0, 'pending' => 0, 'remain' => 0, 'text' => ''];
        }
        return $this->progressOf($m);
    }

    /** 同上，但直接吃一行 member，省一次查询 */
    public function progressOf(array $m): array
    {
        $r      = $this->rule();
        $issued = (int)($m['rewards_issued'] ?? 0);

        if ($r['mode'] === 'amount') {
            $progress  = Money::toCents((string)($m['total_spent'] ?? '0'));
            $threshold = $r['threshold_cents'];
        } else {
            $progress  = (int)($m['visit_count'] ?? 0);
            $threshold = $r['threshold_visits'];
        }

        $earned  = intdiv($progress, $threshold);   // 按当前规则总共该发几张
        $pending = max(0, $earned - $issued);       // 还欠几张
        // 距离下一张还差多少
        $remain  = ($earned + 1) * $threshold - $progress;

        return [
            'mode'      => $r['mode'],
            'progress'  => $progress,
            'threshold' => $threshold,
            'issued'    => $issued,
            'earned'    => $earned,
            'pending'   => $pending,
            'remain'    => $remain,
            'text'      => $r['mode'] === 'amount'
                ? sprintf('已累计 € %s / 每 € %s 送 1 次，还差 € %s',
                    Money::toStr($progress), Money::toStr($threshold), Money::toStr($remain))
                : sprintf('已累计 %d 次 / 每 %d 次送 1 次，还差 %d 次',
                    $progress, $threshold, $remain),
        ];
    }

    /**
     * 发分之后调用：够门槛就发券。
     *
     * 幂等靠 rewards_issued —— 重复调用不会多发。
     * 关掉自动发放时只返回 pending 数量，由后台人工发。
     *
     * @return array{granted:int,pending:int,coupons:array}
     */
    public function checkAndGrant(int $memberId, array $operator = []): array
    {
        $r = $this->rule();
        if (!$r['enabled']) {
            return ['granted' => 0, 'pending' => 0, 'coupons' => []];
        }

        $m = $this->members->findById($memberId);
        if ($m === null) {
            return ['granted' => 0, 'pending' => 0, 'coupons' => []];
        }
        $p = $this->progressOf($m);
        if ($p['pending'] <= 0) {
            return ['granted' => 0, 'pending' => 0, 'coupons' => []];
        }
        if (!$r['auto_grant']) {
            // 只提示不发，后台「会员」页可手工发
            return ['granted' => 0, 'pending' => $p['pending'], 'coupons' => []];
        }

        $out = [];
        for ($i = 0; $i < $p['pending']; $i++) {
            $out[] = $this->issue(
                $memberId,
                $r['mode'] === 'amount' ? self::SRC_AMOUNT : self::SRC_VISITS,
                $p['progress'],
                $r['valid_days'],
                null,
                $operator
            );
        }
        $this->db->exec(
            'UPDATE member SET rewards_issued = rewards_issued + ?, updated_at = ?
              WHERE store_code = ? AND id = ?',
            [count($out), $this->db->now(), $this->storeCode, $memberId]
        );

        return ['granted' => count($out), 'pending' => 0, 'coupons' => $out];
    }

    /** 后台手工发一张（补偿、投诉处理等），需写明原因 */
    public function grantManual(int $memberId, string $note, array $operator): array
    {
        if (trim($note) === '') {
            return ['ok' => false, 'error' => 'bad_request'];
        }
        if ($this->members->findById($memberId) === null) {
            return ['ok' => false, 'error' => 'member_not_found'];
        }
        $c = $this->issue($memberId, self::SRC_MANUAL, 0,
            $this->rule()['valid_days'], $note, $operator);
        // 手工发的不计入 rewards_issued —— 否则会顶掉客人靠消费挣来的那张
        return ['ok' => true, 'coupon' => $c];
    }

    /**
     * 落一张券。
     *
     * ★ 有效期【写死在券上】，不是全局规则实时算出来的。
     *   发券当刻按当时的 coupon_valid_days 算出 valid_to 存进这一行，
     *   之后店家把规则从 180 天改成 90 天，**已发出去的券一律不受影响**，
     *   只有新发的按新规则。过期判定（expireStale）读的也是券上的
     *   valid_to，不碰配置。
     *
     *   这是硬性约定：客人拿到手的券，到期日就不该再变。
     *   别把它「优化」成按当前配置实时计算 —— 那会让老客人的券凭空缩水或延长。
     *   tests/cases/SchemaCompatTest.php 有断言守着。
     */
    private function issue(int $memberId, int $source, int $progress,
                           int $validDays, ?string $note, array $operator): array
    {
        $now  = $this->db->now();
        $code = strtoupper(bin2hex(random_bytes(4)));   // 8 位，够短能口头核对
        // 发券当刻定死；0 = 永久（valid_to 存 NULL）
        $to   = $validDays > 0
            ? date('Y-m-d', strtotime($now) + $validDays * 86400)
            : null;

        $this->db->exec(
            'INSERT INTO coupon
               (store_code, member_id, coupon_type, source, amount_cents, progress_at_grant,
                note, code, status, valid_from, valid_to, created_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
            [$this->storeCode, $memberId, 1, $source, 0, $progress,
             $note, $code, self::ST_ACTIVE, date('Y-m-d', strtotime($now)), $to, $now]
        );
        $id = $this->db->lastInsertId();

        $this->audit->log('coupon_grant', [
            'target_type'   => 'coupon', 'target_id' => (string)$id,
            'operator_id'   => $operator['id']   ?? null,
            'operator_name' => $operator['name'] ?? null,
            'detail' => ['member_id' => $memberId, 'code' => $code,
                         'source' => $source, 'valid_to' => $to, 'note' => $note],
        ]);

        return ['id' => $id, 'code' => $code, 'valid_to' => $to, 'source' => $source];
    }

    // ════════════════════════════════════════════════════════
    // 查询与核销
    // ════════════════════════════════════════════════════════

    /** 某会员当前可用的券（顺带把过期的标掉） */
    public function availableFor(int $memberId): array
    {
        $this->expireStale();
        return $this->db->all(
            'SELECT id, code, source, amount_cents, valid_to, note, created_at
               FROM coupon
              WHERE store_code = ? AND member_id = ? AND status = ?
              ORDER BY valid_to IS NULL, valid_to ASC, id ASC',
            [$this->storeCode, $memberId, self::ST_ACTIVE]
        );
    }

    /** 过期券置状态。每次查券时顺手做，不必单开定时任务 */
    public function expireStale(): int
    {
        return $this->db->exec(
            'UPDATE coupon SET status = ?
              WHERE store_code = ? AND status = ? AND valid_to IS NOT NULL AND valid_to < ?',
            [self::ST_EXPIRED, $this->storeCode, self::ST_ACTIVE, date('Y-m-d')]
        );
    }

    /**
     * 核销一张券。
     *
     * ★ 与 POS 侧的关系：收银员在 POS 上加那条 `TARJETA 10+1` 折扣行，
     *   本系统这边把券置为已核销。两边通过 serial_id 对上账。
     *   订单本身是否计分计次，由 PointsService 依 is_redeemed 判定，
     *   与本方法无关 —— 这里只管券的状态流转。
     */
    /**
     * 核销一张券。
     *
     * ★ 这是整条链路上唯一真正会造成损失的一步 —— 前面所有防护都是为了它。
     *
     * 二维码印在卡正面，可以被拍照复制；PIN 藏在卡背刮开层下，只有真正
     * 拿到卡的人知道。所以核销必须验 PIN —— 抄了码的人兑不走免费餐。
     * 而积分入账那一侧不验：被人抄卡去攒分，店家没有损失，
     * 受害者反而多了分，为它加一道门槛得不偿失。
     *
     * @param string|null $pin      卡背 PIN
     * @param array|null  $override 经理强制核销 ['reason' => ...]。
     *                              PIN 用 bcrypt 存、不可还原，客人忘了
     *                              或卡背磨花了谁也查不出来，必须留这条路；
     *                              但它要经理权限、必须填原因、单独记审计。
     */
    public function redeem(
        int $couponId,
        ?string $serialId,
        array $operator,
        ?string $pin = null,
        ?array $override = null,
    ): array {
        return $this->db->transaction(function () use ($couponId, $serialId, $operator, $pin, $override) {
            $c = $this->db->one(
                'SELECT * FROM coupon WHERE store_code = ? AND id = ? FOR UPDATE',
                [$this->storeCode, $couponId]
            );
            if ($c === null) {
                return ['ok' => false, 'error' => 'coupon_not_found'];
            }
            if ((int)$c['status'] !== self::ST_ACTIVE) {
                return ['ok' => false, 'error' => 'coupon_not_active'];
            }
            if ($c['valid_to'] !== null && (string)$c['valid_to'] < date('Y-m-d')) {
                $this->db->exec('UPDATE coupon SET status = ? WHERE id = ?', [self::ST_EXPIRED, $couponId]);
                return ['ok' => false, 'error' => 'coupon_expired'];
            }

            // ── 持卡验证 ──
            $forced = false;
            if ($override !== null) {
                if ((int)($operator['role'] ?? 0) < 2) {
                    return ['ok' => false, 'error' => 'forbidden'];
                }
                if (trim((string)($override['reason'] ?? '')) === '') {
                    return ['ok' => false, 'error' => 'reason_required'];
                }
                $forced = true;
            } else {
                $card = $this->cards->findByMemberId((int)$c['member_id']);
                if ($card === null) {
                    // 卡挂失后还没换新的 —— 说清楚，别让人对着 PIN 框干瞪眼
                    return ['ok' => false, 'error' => 'card_missing'];
                }
                if ($pin === null || trim($pin) === '') {
                    return ['ok' => false, 'error' => 'pin_required'];
                }
                $v = $this->cards->verifyPin($card, $pin);
                if (!$v['ok']) {
                    return ['ok' => false, 'error' => (string)$v['error'],
                            'locked_until' => $v['locked_until'] ?? null];
                }
            }

            $this->db->exec(
                'UPDATE coupon SET status = ?, redeemed_at = ?, redeemed_serial_id = ?, operator_id = ?
                  WHERE id = ?',
                [self::ST_REDEEMED, $this->db->now(), $serialId, $operator['id'] ?? null, $couponId]
            );
            $this->audit->log($forced ? 'coupon_redeem_forced' : 'coupon_redeem', [
                'target_type'   => 'coupon', 'target_id' => (string)$couponId,
                'operator_id'   => $operator['id']   ?? null,
                'operator_name' => $operator['name'] ?? null,
                'detail' => ['code' => $c['code'], 'member_id' => (int)$c['member_id'],
                             'serial_id' => $serialId]
                          + ($forced ? ['forced' => true,
                                        'reason' => (string)$override['reason']] : []),
            ]);
            return ['ok' => true, 'code' => $c['code'], 'member_id' => (int)$c['member_id'],
                    'forced' => $forced];
        });
    }

    /** 作废一张券（发错了、客人投诉撤销等） */
    public function void(int $couponId, string $reason, array $operator): array
    {
        $c = $this->db->one('SELECT * FROM coupon WHERE store_code = ? AND id = ?',
            [$this->storeCode, $couponId]);
        if ($c === null) {
            return ['ok' => false, 'error' => 'coupon_not_found'];
        }
        if ((int)$c['status'] === self::ST_REDEEMED) {
            return ['ok' => false, 'error' => 'coupon_already_redeemed'];
        }
        $this->db->exec('UPDATE coupon SET status = ? WHERE id = ?', [self::ST_VOID, $couponId]);
        $this->audit->log('coupon_void', [
            'target_type'   => 'coupon', 'target_id' => (string)$couponId,
            'operator_id'   => $operator['id']   ?? null,
            'operator_name' => $operator['name'] ?? null,
            'detail' => ['code' => $c['code'], 'reason' => $reason],
        ]);
        return ['ok' => true];
    }

    /** 后台统计 */
    public function stats(): array
    {
        $row = $this->db->one(
            'SELECT
               SUM(status = ?) AS active,
               SUM(status = ?) AS redeemed,
               SUM(status = ?) AS expired,
               SUM(status = ?) AS void_,
               COUNT(*) AS total
             FROM coupon WHERE store_code = ?',
            [self::ST_ACTIVE, self::ST_REDEEMED, self::ST_EXPIRED, self::ST_VOID, $this->storeCode]
        ) ?: [];
        return [
            'active'   => (int)($row['active']   ?? 0),
            'redeemed' => (int)($row['redeemed'] ?? 0),
            'expired'  => (int)($row['expired']  ?? 0),
            'void'     => (int)($row['void_']    ?? 0),
            'total'    => (int)($row['total']    ?? 0),
        ];
    }
}
