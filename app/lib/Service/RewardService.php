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
        private \Vip\Repo\CardTierRepo $tiers,
        /**
         * ★ 都是只挂在 localDb 上的普通仓储，不会像 CardRepo 那样
         *   牵出 CardNumber —— card_prefix 配错时本类照样构造得出来。
         */
        private \Vip\Repo\LedgerRepo $ledger,
        private \Vip\Repo\AlertRepo  $alerts,
    ) {
    }

    // ════════════════════════════════════════════════════════
    // 规则读取
    // ════════════════════════════════════════════════════════

    /** 当前生效的奖励规则，同时供后台展示与 Pad 提示 */
    /**
     * 当前生效的奖励规则，同时供后台展示与 Pad 提示。
     *
     * @param array|null $tier 该会员的卡片等级（CardTierRepo::forMember 的结果）。
     *                         给了就用它的门槛覆盖全局值；等级没设门槛（NULL）
     *                         的那一项仍然跟随全局 —— 这样「只想优待金卡」的店家
     *                         只填金卡那一格就行，其余等级不用动。
     */
    public function rule(?array $tier = null): array
    {
        $mode = $this->cfg->get('reward_mode', 'visits');
        if (!in_array($mode, ['visits', 'amount'], true)) {
            $mode = 'visits';
        }

        $visits = max(1, $this->cfg->int('reward_threshold_visits', 10));
        $cents  = max(1, Money::toCents($this->cfg->get('reward_threshold_amount', '300.00')));

        if ($tier !== null) {
            if (($tier['threshold_visits'] ?? null) !== null) {
                $visits = max(1, (int)$tier['threshold_visits']);
            }
            if (($tier['threshold_amount'] ?? null) !== null) {
                $cents = max(1, Money::toCents((string)$tier['threshold_amount']));
            }
        }

        /**
         * 券有效期同理：等级没设就跟随全局。
         *
         * 0 是有意义的取值（永久有效），所以判的是 !== null 而不是真值 ——
         * 写成 `?: ` 的话金卡设成「永久」会被当成没设置。
         */
        $days = max(0, $this->cfg->int('coupon_valid_days', 90));
        if ($tier !== null && ($tier['coupon_valid_days'] ?? null) !== null) {
            $days = max(0, (int)$tier['coupon_valid_days']);
        }

        return [
            'enabled'          => $this->cfg->get('reward_enabled', '1') === '1',
            'mode'             => $mode,
            'threshold_visits' => $visits,
            'threshold_cents'  => $cents,
            'auto_grant'       => $this->cfg->get('reward_auto_grant', '1') === '1',
            'valid_days'       => $days,
            'tier_code'        => $tier['code'] ?? null,
        ];
    }

    /** 某会员当前该套用的规则 —— 按他手里那张卡的等级 */
    public function ruleForMember(int $memberId): array
    {
        return $this->rule($this->tiers->forMember($memberId));
    }

    /**
     * 规则的人话描述，后台与 Pad 都直接显示这句。
     *
     * ★ 按当前请求的语言出。
     *   这是服务端【生成】的句子（数字要填进去），不是界面上的静态标签，
     *   所以按既定分工归服务端翻 —— 与 Api::MESSAGES 同一个道理：
     *   前端再存一份必然漂移，而漂移的表现是西语界面里冒出一句中文。
     *   （真发生过：「查一张卡」上线时，进度那句就是中文漏进西语界面的。）
     */
    public function ruleText(?array $tier = null): string
    {
        $r  = $this->rule($tier);
        $es = \Vip\Http\Api::lang() === \Vip\Lang::ES;

        if (!$r['enabled']) {
            return $es ? 'El programa de puntos está desactivado' : '奖励功能已关闭';
        }
        if ($r['mode'] === 'visits') {
            return $es
                ? sprintf('1 gratis cada %d visitas', $r['threshold_visits'])
                : sprintf('每满 %d 次送 1 次', $r['threshold_visits']);
        }
        return $es
            ? sprintf('1 gratis por cada € %s de consumo', Money::toStr($r['threshold_cents']))
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
                    'earned' => 0, 'pending' => 0, 'remain' => 0, 'text' => '', 'tier_code' => null];
        }
        return $this->progressOf($m, $this->tiers->forMember($memberId));
    }

    /**
     * 同上，但直接吃一行 member，省一次查询。
     *
     * @param array|null $tier 不给就退回全局规则 —— 调用方拿得到等级时应当传进来，
     *                         否则金卡客人会按普卡的门槛算进度，界面上就骗人了
     */
    public function progressOf(array $m, ?array $tier = null): array
    {
        $r      = $this->rule($tier);
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
            // 记下按的是哪个等级的门槛 —— 界面上要说清楚「你是金卡，8 次就送」
            'tier_code' => $r['tier_code'] ?? null,
            // 同 ruleText()：服务端生成的句子，按当前请求的语言出
            'text'      => self::progressText($r['mode'], $progress, $threshold, $remain),
        ];
    }

    /** 进度那句话，两种语言各一版 */
    private static function progressText(string $mode, int $progress, int $threshold, int $remain): string
    {
        $es = \Vip\Http\Api::lang() === \Vip\Lang::ES;
        if ($mode === 'amount') {
            return $es
                ? sprintf('Lleva € %s de € %s — faltan € %s',
                    Money::toStr($progress), Money::toStr($threshold), Money::toStr($remain))
                : sprintf('已累计 € %s / 每 € %s 送 1 次，还差 € %s',
                    Money::toStr($progress), Money::toStr($threshold), Money::toStr($remain));
        }
        return $es
            ? sprintf('Lleva %d visitas de %d — faltan %d', $progress, $threshold, $remain)
            : sprintf('已累计 %d 次 / 每 %d 次送 1 次，还差 %d 次', $progress, $threshold, $remain);
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
        // 按【这位客人手里那张卡】的等级取规则：金卡 8 次送 1 次时，
        // 用全局的 10 次去算就等于等级白设了
        $tier = $this->tiers->forMember($memberId);
        $r    = $this->rule($tier);
        if (!$r['enabled']) {
            return ['granted' => 0, 'pending' => 0, 'coupons' => []];
        }

        /**
         * ★★★ 整段必须在【一个事务】里，并且第一步就把会员那一行锁住。
         *
         * ── 不锁会怎样 ──────────────────────────────────
         * 这个方法原来是「读 → 算 pending → 发券 → 加计数」四步裸奔。
         * 幂等只靠 rewards_issued，而那个结论【只在串行下成立】：
         *
         *   两个请求同时读到 rewards_issued = 0、visit_count = 10
         *   → 各自算出 pending = 1 → 各发一张 → 各加 1
         *   实测 4 个进程对齐调用：发出 4 张免费餐券（应发 1 张）。
         *
         * ── 现实中怎么撞上 ──────────────────────────────
         * ① 门店有多台 Pad。grantMerged() 专门为「两台 Pad 同时合并」
         *    写了排序防死锁，多 Pad 并发是这套系统的既定前提。
         * ② 同一位客人的两桌单被两名收银员几乎同时记账 —— 而「同行分桌」
         *    正是这套系统重点支持的场景。
         * ③ ★ 不需要并发也会中招：原来是【全部发完才加计数】，
         *    中间任何一次进程终止（PHP 超时、平板断网重试打断 FPM）
         *    都会留下「券已发出、计数没加」，下一次记账再发一遍。
         *    实测：券 1 张 + 计数 0 → 下次记账又发 1 张 → 共 2 张。
         *
         * 券是真金白银的一顿饭，而 void() 不回退 rewards_issued，
         * 发多了只能人工逐张作废。
         *
         * ★ 调用方（api/routes.php）刻意把 checkAndGrant 放在记账事务【之外】
         *   —— 发券失败不该把已记好的积分一起回滚。那个决定是对的，
         *   所以这里自己开一个独立事务，而不是指望外面那个。
         */
        return $this->db->transaction(function () use ($memberId, $tier, $r, $operator): array {
            // 行锁：并发的第二个请求会停在这里，等第一个提交后才读到新的 rewards_issued
            $m = $this->members->lockById($memberId);
            if ($m === null) {
                return ['granted' => 0, 'pending' => 0, 'coupons' => []];
            }
            $p = $this->progressOf($m, $tier);
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
                    $operator,
                    // 门槛是活查的 —— 券上不定格的话，改一次门槛就再也说不清
                    // 「这张券当初凭什么发的」。客人申诉、会计对账都要看它
                    $tier['code'] ?? null,
                    $p['threshold']
                );
            }
            $this->db->exec(
                'UPDATE member SET rewards_issued = rewards_issued + ?, updated_at = ?
                  WHERE store_code = ? AND id = ?',
                [count($out), $this->db->now(), $this->storeCode, $memberId]
            );

            /**
             * ── 🔴 这一张是不是"打字打出来的" ────────────────────
             *
             * 手工录入没有 POS 订单作证，它证明的只是「有人说这笔钱花了」。
             * 按次数口径下这一点是落实了的 —— 手工录入 counted_visit 恒为 0，
             * 一张券都换不到（PointsService::manualGrant 的说明）。
             *
             * 但按【金额】口径下，同一笔录入全额计入 total_spent、直接换券。
             * 同一个动作在两种口径下待遇天差地别，而没有任何人会预料到这一点。
             *
             * 事前拦不住：手工录入是 POS 挂掉时的降级通道，堵死它等于
             * 把正常客人一起挡在门外（docs/03 §10）。所以做两件事 ——
             * 上限把最坏情况框住（manual_entry_daily_cap），
             * 这里保证【造出免费餐的那一刻永远不是静默的】。
             *
             * 判据取「手工录入的金额本身已经够一个门槛」：正常的偶发降级
             * （POS 抽风一两次）远达不到，不会天天叫；而真要靠打字凑出一顿饭，
             * 必然越过它。
             */
            if ($r['mode'] === 'amount') {
                $manual = $this->ledger->manualAmountByMember($memberId);
                if ($manual >= $r['threshold_cents']) {
                    $this->alerts->raise(
                        'reward_from_manual_entry',
                        sprintf('刚给会员 #%d 发出 %d 张免费餐券，而这位客人的累计消费里有 € %s '
                              . '来自【手工录入】（没有 POS 订单作证，金额是收银员填的）。'
                              . '请核对这些录入是否真实', $memberId, count($out), Money::toStr($manual)),
                        ['severity' => 2, 'ref_type' => 'member', 'ref_id' => (string)$memberId]
                    );
                }
            }

            return ['granted' => count($out), 'pending' => 0, 'coupons' => $out];
        });
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
            $this->rule($tierForManual = $this->tiers->forMember($memberId))['valid_days'],
            $note, $operator, $tierForManual['code'] ?? null, null);
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
    /**
     * @param string|null $tierCode  发券时这张卡的等级
     * @param int|null    $threshold 发券时实际套用的门槛
     *
     * 后两个参数是【为了事后能解释】：门槛与倍率都是活查的，改一次之后
     * 「这张券当初凭什么发的」就再也答不上来。客人申诉、会计对账都要看它。
     */
    /**
     * 券的到期日 = 发券当天 + N 个【日历天】。0 天 = 永久（返回 null）。
     *
     * ── 🔴 为什么不能写成 strtotime($now) + N * 86400 ──────
     *
     * 跨夏令时切换时两者差一小时，足以把日期推过午夜：
     *
     *     Europe/Madrid  2026-07-29 00:30 发券 + 90 天
     *       加秒数 → 2026-10-26      日历天 → 2026-10-27   ❌ 少一天
     *
     * 而晚市餐期是 19:30–次日 02:00（db/seeds/002_meal_period.sql），
     * **00:xx 发券是这家店的常态**，不是边角。实测 Madrid 全年
     * ×3 个时刻 ×3 档有效期共 3285 组，旧写法错 550 组（17%）。
     *
     * docs/11 立的规矩是「券面/卡面上印的日子就是最终的日子」——
     * 少一天正好落在这条承诺的反面，而客人是拿着券被拒时才发现的。
     *
     * ★ 单独拎成静态方法是为了能不连库、跨时区地测（tests/cases/RewardTest.php）。
     */
    public static function expiryDate(string $issuedAt, int $validDays): ?string
    {
        if ($validDays <= 0) {
            return null;
        }
        return (new \DateTimeImmutable($issuedAt))
            ->modify('+' . $validDays . ' days')
            ->format('Y-m-d');
    }

    private function issue(int $memberId, int $source, int $progress,
                           int $validDays, ?string $note, array $operator,
                           ?string $tierCode = null, ?int $threshold = null): array
    {
        $now  = $this->db->now();
        $code = strtoupper(bin2hex(random_bytes(4)));   // 8 位，够短能口头核对
        $to   = self::expiryDate($now, $validDays);

        $this->db->exec(
            'INSERT INTO coupon
               (store_code, member_id, coupon_type, source, amount_cents, progress_at_grant,
                tier_code, threshold_used,
                note, code, status, valid_from, valid_to, created_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [$this->storeCode, $memberId, 1, $source, 0, $progress,
             $tierCode, $threshold,
             $note, $code, self::ST_ACTIVE, date('Y-m-d', strtotime($now)), $to, $now]
        );
        $id = $this->db->lastInsertId();

        $this->audit->log('coupon_grant', [
            'target_type'   => 'coupon', 'target_id' => (string)$id,
            'operator_id'   => $operator['id']   ?? null,
            'operator_name' => $operator['name'] ?? null,
            'detail' => ['member_id' => $memberId, 'code' => $code,
                         'source' => $source, 'valid_to' => $to, 'note' => $note,
                         'tier_code' => $tierCode, 'threshold_used' => $threshold],
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

    /**
     * 计次/消费被退回之后，把【已经不再算挣到】的券收回来。
     *
     * ── 🔴 不做这件事会怎样 ────────────────────────────
     *
     * 服务员拿错卡，把 B 桌的账记到张三名下，张三因此正好满了十次、
     * 系统当场发了一张免费餐券。经理十分钟后发现，撤销那笔记账。
     *
     * 撤销回退了计次、积分、累计消费 —— 唯独没碰券，也没碰 rewards_issued。
     * 于是两头都错：
     *   ① 那张券还在客人手上，而且【能正常核销】—— 一顿饭就这么送出去了；
     *   ② 客人后来自己老老实实吃到第十次，一张券都拿不到 ——
     *      progressOf 里 pending = earned − issued，issued 虚高 1，
     *      于是永远算出 0。界面上还写着「还差 N 次」，看上去完全正常。
     *
     * 实测（门槛按 3 次）：撤销后计次退回 2，券仍在且核销成功；
     * 客人真吃到第 3 次时 checkAndGrant 发出 0 张。
     *
     * ── 为什么按「应发 − 已发」重算，而不是记住哪张券是哪笔账发的 ──
     *
     * 与 §5.2 的自愈公式同一条思路：只看当下的进度该发几张、已经发了几张，
     * 多出来的收回。这样无论中间经历过改门槛、补录、多次撤销，都能自动对上，
     * 不需要在券和流水之间维护一条会断的对应关系。
     *
     * ★ 已核销的券不动。那顿饭已经吃掉了，收不回来 ——
     *   这时 rewards_issued 也【不减】（客人确实拿到了那份奖励），
     *   转而挂一条告警让经理知道有一份奖励是发错的账换来的。
     *
     * ★ 必须由调用方开事务并先锁住会员行（reverseInTx 已经锁了）。
     *   这里不自己开事务：收回券和退回计次必须是同一笔，
     *   中间断开就又回到「计次退了、券没退」那个状态。
     *
     * @return array{voided:int, unrecoverable:int, codes:array<int,string>}
     */
    public function clawBackOverIssued(int $memberId, array $operator, string $reason): array
    {
        $m = $this->members->findById($memberId);
        if ($m === null) {
            return ['voided' => 0, 'unrecoverable' => 0, 'codes' => []];
        }

        $p    = $this->progressOf($m, $this->tiers->forMember($memberId));
        $over = (int)$p['issued'] - (int)$p['earned'];
        if ($over <= 0) {
            return ['voided' => 0, 'unrecoverable' => 0, 'codes' => []];
        }

        /**
         * ── 🔴 只收【新进度已经不再支撑】的那几张，不能"抓一张顶数" ──
         *
         * 每张券上都定格着发它时的进度（progress_at_grant，见 issue()）。
         * 退完之后进度变成 P，那么「发于进度 > P」的那些券就是这次
         * 撤销带出来的；发于进度 ≤ P 的是客人早就挣到的，不能动。
         *
         * ★ 原来这里是 `ORDER BY id DESC` 拿最新的几张 —— 看着差不多，
         *   实际会挑错人。实测：客人在进度 3 时挣到 C1（还拿在手上），
         *   记错账把他推到进度 6 发出 C2，客人把 C2 吃掉了；
         *   撤销时 C2 已是"已核销"选不中，于是【C1 被作废】——
         *   客人手里那张合法的券凭空消失。
         *   总张数最后会自愈（挣几张给几张），但客人当场看到的是
         *   "我的券没了"，而且没有任何人能解释清楚。
         *
         * ★ 更糟的是那时【告警不会响】：因为找到了一张可作废的券，
         *   unrecoverable 是 0，于是"白送了一顿饭"这件事没人知道。
         *   按进度定位之后，C2 已核销 → unrecoverable=1 → 告警照响。
         *
         * 只收【靠消费挣来的】券，后台手工发的那些不动 ——
         * 那是补偿、投诉处理发出去的，与计次进度无关
         * （grantManual 本来也不加 rewards_issued，见 §5.2）。
         */
        $stale = $this->db->all(
            'SELECT id, code, status FROM coupon
              WHERE store_code = ? AND member_id = ? AND source IN (?, ?)
                AND progress_at_grant > ?
              ORDER BY progress_at_grant DESC, id DESC LIMIT 50',
            [$this->storeCode, $memberId, self::SRC_VISITS, self::SRC_AMOUNT, (int)$p['progress']]
        );

        /**
         * 已经核销掉的排在后面不动 —— 那顿饭吃掉了，收不回来。
         * 它们仍然占着 rewards_issued（客人确实拿到了那份奖励），
         * 由调用方按 unrecoverable 去挂告警。
         */
        $cands = array_values(array_filter(
            $stale, static fn(array $c): bool => (int)$c['status'] === self::ST_ACTIVE));
        $cands = array_slice($cands, 0, $over);

        $codes = [];
        foreach ($cands as $c) {
            $this->db->exec('UPDATE coupon SET status = ? WHERE id = ?', [self::ST_VOID, (int)$c['id']]);
            $this->audit->log('coupon_void', [
                'target_type'   => 'coupon', 'target_id' => (string)$c['id'],
                'operator_id'   => $operator['id']   ?? null,
                'operator_name' => $operator['name'] ?? null,
                'detail' => ['code' => $c['code'], 'reason' => $reason, 'auto' => 'clawback'],
            ]);
            $codes[] = (string)$c['code'];
        }

        /**
         * ★ rewards_issued 只按【真收回来的张数】减。
         *
         *   减多了的后果比不减更糟：pending 会立刻变正，
         *   下一次记账把刚作废的那张原样再发一遍。
         */
        if ($codes) {
            $this->db->exec(
                'UPDATE member SET rewards_issued = GREATEST(0, rewards_issued - ?), updated_at = ?
                  WHERE store_code = ? AND id = ?',
                [count($codes), $this->db->now(), $this->storeCode, $memberId]
            );
        }

        return ['voided' => count($codes), 'unrecoverable' => $over - count($codes), 'codes' => $codes];
        // unrecoverable > 0 ＝ 有券【发于已被退掉的那段进度】却已经吃掉了：
        // 白送了一顿饭，rewards_issued 也就不减（客人确实拿到了那份奖励）
    }

    /**
     * 按【当前规则】算一遍：全店总共还欠多少张券。
     *
     * ★ 用途只有一个：门槛调低时，把「这一下会补发多少张」摆在店家面前。
     *
     *   达标判定是自愈式的（应发 = floor(进度 / 门槛) − 已发），
     *   所以把「十次送一」改成「三次送一」的那一刻，系统会按全部历史进度
     *   给每一位会员回溯补发。一个来过 10 次的客人当场从 1 张变成 3 张。
     *   **而发出去的券收不回来**（docs/03 §5.1）。
     *
     *   这件事本身是有意的设计（改门槛能自动对上，既不重复也不遗漏），
     *   问题在于它原来是【静默】的：后台点一下保存，提示「已保存」，
     *   几十顿饭就送出去了，没有任何地方说过一句。
     *
     * ⚠️ 一位会员挂多张卡时 LEFT JOIN 会出现多行，那时这个数会偏大。
     *   实际是一人一卡（换卡走 replaceCard，旧卡置为作废），
     *   偏大也只会让告警更保守，不会漏报。
     */
    public function pendingAcrossMembers(): int
    {
        $global = $this->rule(null);
        if (!$global['enabled']) {
            return 0;
        }

        /**
         * ★★ 必须【按会员各自的等级】算。
         *
         *   原来这里是 rule(null) —— 全局规则，不看任何人的等级。
         *   而真正发几张券用的是 progressOf($m, tiers->forMember($id))，
         *   也就是【这张卡的等级门槛】。两个数不是一回事：
         *
         *     金卡门槛 2 次的会员，攒了 15 次、已发 3 张 → 实际欠 12 张
         *     而按全局门槛（10 次）算出来是 0 张
         *
         *   于是护栏拿着一个偏小的数在报警，经理照着它做决定。
         *   /tiers/save 改等级门槛时更是完全看不见。
         *
         * ★ 不逐个 forMember() 查库（那是每位会员一次查询）——
         *   等级表很小，一次读进来，会员连着卡的等级码一次读出来，
         *   在内存里配对。
         */
        $tiers = [];
        foreach ($this->tiers->all() as $t) {
            $tiers[(string)$t['code']] = $t;
        }

        $rows = $this->db->all(
            'SELECT m.visit_count, m.total_spent, m.rewards_issued, c.tier_code
               FROM member m
               LEFT JOIN card c ON c.store_code = m.store_code AND c.member_id = m.id
              WHERE m.store_code = ?',
            [$this->storeCode]
        );

        $pending = 0;
        foreach ($rows as $row) {
            $code = $row['tier_code'] ?? null;
            $tier = ($code !== null && isset($tiers[$code])) ? [
                'code'             => $code,
                'threshold_visits' => $tiers[$code]['threshold_visits'],
                'threshold_amount' => $tiers[$code]['threshold_amount'],
            ] : null;
            $r = $this->rule($tier);
            if (!$r['enabled']) {
                continue;
            }
            $progress = $r['mode'] === 'amount'
                ? Money::toCents((string)($row['total_spent'] ?? '0'))
                : (int)($row['visit_count'] ?? 0);
            $thr = max(1, $r['mode'] === 'amount' ? $r['threshold_cents'] : $r['threshold_visits']);
            $pending += max(0, intdiv($progress, $thr) - (int)($row['rewards_issued'] ?? 0));
        }
        return $pending;
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
