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
        /** 核销成功时把「这一单是用券吃的」写回订单镜像 —— 见 redeem() */
        private \Vip\Repo\OrderRepo  $orders,
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

            /**
             * ── 🔴 一次能自动发多少张，必须有上限 ──────────────────
             *
             * pending = ⌊进度 ÷ 门槛⌋ − 已发。门槛是后台可改的一个数，
             * 于是【一个打字错误就是几百顿免费餐】：
             *
             *   实测：reward_mode = amount、老板想填「满 100 欧送一次」
             *   却漏了两个零填成 1.00 —— 一位累计消费 800 欧的普通熟客，
             *   下一次记账时这个 for 循环一口气发出【800 张免费餐券】，
             *   0.24 秒，账面上一声不响。
             *   （门槛的合法下限是 0.01 欧，最坏情况是 80000 张。）
             *
             * 这套系统对「员工那一侧」已经把最坏情况框住了 ——
             * 手工录入有单笔限额、有绝对上限、有日累计上限
             * （PointsService::manualGrant）。而「配置那一侧」通向的是
             * 同一样东西（免费餐），却一道闸门都没有。
             *
             * ★ 为什么是【一张都不发】而不是「先发 N 张」：
             *   pending 大到超过上限，就不是「客人跨过了门槛」，
             *   而是【门槛本身被改了】—— 正常客人的 pending 永远是 1。
             *   先发一部分只是把 800 张摊到 80 次记账里，照样发得出去。
             *
             * ★ 不发 ≠ 丢掉。pending 是【算出来的】，不是队列：
             *   rewards_issued 不动，进度就还在，后台「待发」页照样看得到，
             *   人工确认配置没问题之后点一下就能补发（issuePending）。
             *   这正是 auto_grant 关掉时那条路，天然就是为这种时候准备的。
             */
            $cap = max(1, $this->cfg->int('reward_max_auto_grant', 10));
            if ($p['pending'] > $cap) {
                $this->alerts->raise(
                    'reward_burst_blocked',
                    sprintf('会员 #%d 一次算出 %d 张免费餐券（上限 %d），已【全部暂缓发放】。'
                          . '正常客人一次只会达标 1 张 —— 这个数说明门槛刚被改过，'
                          . '或者进度是手工录入堆出来的。当前口径：%s，门槛 %s，进度 %s。'
                          . '请先核对「奖励规则」页的门槛；确认无误后到「待发」页人工发放',
                        $memberId, $p['pending'], $cap,
                        $p['mode'] === 'amount' ? '按金额' : '按次数',
                        $p['mode'] === 'amount' ? '€ ' . Money::toStr($p['threshold']) : $p['threshold'] . ' 次',
                        $p['mode'] === 'amount' ? '€ ' . Money::toStr($p['progress']) : $p['progress'] . ' 次'),
                    ['severity' => 3, 'ref_type' => 'member', 'ref_id' => (string)$memberId]
                );
                return ['granted' => 0, 'pending' => $p['pending'], 'coupons' => [],
                        'blocked' => true];
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

    /** 券码撞了最多换几次（见 issue() 里的生日问题说明） */
    private const CODE_TRIES = 8;

    private function issue(int $memberId, int $source, int $progress,
                           int $validDays, ?string $note, array $operator,
                           ?string $tierCode = null, ?int $threshold = null): array
    {
        $now  = $this->db->now();
        $to   = self::expiryDate($now, $validDays);

        /**
         * ── 🔴 券码会撞，撞了必须换一个再来 ────────────────────
         *
         * 券码是 8 位十六进制（random_bytes(4)），码空间 42.9 亿，
         * 而 coupon 表上有唯一键 uk_code(store_code, code)。看着很宽，
         * 但撞码走的是【生日问题】，不是「发满 42.9 亿张才撞」：
         *
         *   累计   20000 张 → 至少撞一次的概率  4.5%
         *   累计   36500 张 → 14.4%   （每天 20 张，五年）
         *   累计  100000 张 →  68.8%
         *   实测抽样：第一次撞码的中位数落在【第 7 万张】左右。
         *
         * 券只增不减（过期、核销的行都留着占码），所以这个数只会涨。
         *
         * 撞上以后原来是直接抛 PDOException 1062 冒到最外层，
         * classify() 把它归进 default → E109「本地数据库暂时不可用，
         * 请联系管理员」—— 库好得很，人又一次被指到完全没问题的地方
         * （和 1213/1205 当初那个坑一模一样，见 Api::classify）。
         *
         * ★ 换一个码重试就完事了：InnoDB 的唯一键冲突只回滚【这一条语句】，
         *   不会废掉整个事务，所以在事务里原地重试是安全的。
         * ★ 不加长码：8 位是为了「客人报码、店员口头核对」定的，
         *   加长解决的是同一个概率问题，代价却落在每天的使用上。
         * ★ 连着几次都撞，说明码空间真的填满了（不是运气），
         *   那需要有人知道 —— 告警一次，别闷声重试到天亮。
         */
        $code = '';
        for ($try = 1; ; $try++) {
            $code = strtoupper(bin2hex(random_bytes(4)));   // 8 位，够短能口头核对
            try {
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
                break;
            } catch (\PDOException $e) {
                if ((int)($e->errorInfo[1] ?? 0) !== 1062 || $try >= self::CODE_TRIES) {
                    throw $e;
                }
                if ($try === 2) {
                    $this->alerts->raiseOnce('coupon_code_space', 'store', $this->storeCode,
                        sprintf('券码连续 %d 次撞到已有的券（8 位十六进制）。'
                              . '目前库里有 %d 张券 —— 码空间开始填满了，'
                              . '再涨下去每发一张都要重试多次，该考虑加长券码位数了',
                            $try, (int)$this->db->value(
                                'SELECT COUNT(*) FROM coupon WHERE store_code = ?', [$this->storeCode])),
                        ['severity' => 2]);
                }
            }
        }
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

    /**
     * 「今天」= 营业日的今天，不是日历日的今天。
     *
     * ── 🔴 券的死活只认这一个口径 ─────────────────────────
     *
     * 营业日 02:00 才翻页（BusinessDay::today()）。用 date('Y-m-d') 判过期，
     * 晚市 00:00–02:00 这两小时里会把【当天仍然有效】的券判成过期，
     * 而且是【写库】的——status 一置成 3 就再也回不来，客人当场丢券。
     *
     * 这两小时不是边角料：跨年夜、周五周六的最后一桌都落在里面。
     */
    private function todayBiz(): string
    {
        /**
         * ★ 不缓存 BusinessDay 实例 —— 缓存的是【切点这个配置值】，
         *   而它是后台可改的。ConfigRepo 自己有内存缓存且在 set() 时失效，
         *   所以每次现取既便宜又不会过期；存一个实例反而会让本服务
         *   与 BusinessDay::todayDefault()（卡那一侧）说两套话。
         */
        return (new \Vip\BusinessDay($this->cfg->get('business_day_cutoff', '02:00')))->today();
    }

    /** 过期券置状态。每次查券时顺手做，不必单开定时任务 */
    public function expireStale(): int
    {
        return $this->db->exec(
            'UPDATE coupon SET status = ?
              WHERE store_code = ? AND status = ? AND valid_to IS NOT NULL AND valid_to < ?',
            // ★ 营业日，不是日历日 —— 见 todayBiz()
            [self::ST_EXPIRED, $this->storeCode, self::ST_ACTIVE, $this->todayBiz()]
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
            // ★ 营业日，不是日历日。00:30 拿券来核销时 date('Y-m-d') 已经是
            //   第二天了，会把当晚仍然有效的券当场判死并写库（见 todayBiz()）。
            if ($c['valid_to'] !== null && (string)$c['valid_to'] < $this->todayBiz()) {
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

            /**
             * ★ 把「这一单是用券吃的」写回订单镜像 —— 这是 App 的地面真值。
             *
             *   原来这件事只写在券上（redeemed_serial_id），订单那一侧
             *   仍然靠匹配 POS 折扣行的名字去猜。名字对不上时，
             *   免费餐那一单会被当成正常一单【又攒一次】——
             *   门槛 10 时变成「9 顿付费送 1 顿」，发放量多约 11%，
             *   而且全程零告警（见 OrderRepo::markRedeemedByApp 的说明）。
             *
             *   ★ 必须在同一笔事务里：券已核销而订单没标记，就又回到那个状态。
             *   ★ 没带单号时（客人先吃、事后补核销）跳过 —— 那时确实不知道是哪一单，
             *     只能继续靠匹配串，兜底由 checkIntegrity 的对账告警负责。
             */
            $visitsBack = 0;
            if ($serialId !== null && trim($serialId) !== '') {
                $this->orders->markRedeemedByApp($serialId);
                // ★ 顺序不能反：先把镜像标好（份数已减 1），
                //   下面才按【减完之后】的份数判该退几次。
                $visitsBack = $this->clawBackVisitOnRedeem($serialId, (int)$c['member_id'], $c['code']);
            }
            $this->audit->log($forced ? 'coupon_redeem_forced' : 'coupon_redeem', [
                'target_type'   => 'coupon', 'target_id' => (string)$couponId,
                'operator_id'   => $operator['id']   ?? null,
                'operator_name' => $operator['name'] ?? null,
                'detail' => ['code' => $c['code'], 'member_id' => (int)$c['member_id'],
                             'serial_id' => $serialId]
                          + ($visitsBack > 0 ? ['visits_clawed_back' => $visitsBack] : [])
                          + ($forced ? ['forced' => true,
                                        'reason' => (string)$override['reason']] : []),
            ]);
            return ['ok' => true, 'code' => $c['code'], 'member_id' => (int)$c['member_id'],
                    'visits_clawed_back' => $visitsBack,
                    'forced' => $forced];
        });
    }

    /**
     * 先记账、后核销 —— 把那一餐【已经记进去的次数】退回来。
     *
     * ── 🔴 这条路原来是空的（审计 F4） ────────────────────
     *
     * 系统一直假设「核销发生在记账之前」：locate 时订单已经带着核销标记，
     * buildContext 据此把免费那一份从 portions_counted 里剔掉，
     * 于是那一餐自然不计次。
     *
     * 但前台的真实顺序常常是反的 —— 服务员先按 AA 把这一桌记完，
     * 客人才想起来「我有张券」。此时：
     *   · 次数【已经写进 point_ledger 和 member.visit_count 了】
     *   · markRedeemedByApp() 只改订单镜像的 portions_counted，
     *     动不了已经落库的流水
     * 结果：免费那一餐自己又攒了一次。门槛 10 时变成「9 顿付费送 1 顿」。
     *
     * 实测（scratchpad/p0.php F4）：发券后计次 3 → 记账 → 4 → 核销 → 还是 4。
     * 而且 checkIntegrity 的 redeem_unflagged 告警【抓不到】——
     * 订单确实标了 is_redeemed，账面上一切正常。
     *
     * ── 退几次：看【券的持有人自己】那一份，不看整单 ────────
     *
     * 一开始我按订单镜像的剩余份数判，结果混合桌上一次也退不掉：
     * 4 人桌记了 1 份给他，核销后订单还剩 3 份 > 0 → 判成「别人还在吃」→ 退 0。
     * 可那 3 份是【另外三个人】的，与他无关 —— 他自己那一份就是免费的。
     *
     * 正确的轴是这位客人自己：
     *   一张券 = 一份免费套餐，$freed = 他在这一单上已核销的券数
     *   按份计次(by_portion) → 每免一份就少一次，退 min($freed, 现存次数)
     *   其它口径            → 他记的份数全被券盖住了才退（那一餐他没花钱），
     *                         还剩自费的份数就不退（他确实来吃了）
     *
     * ── 幂等 ───────────────────────────────────────────
     *
     * 退次数是【另插一条负数流水】，原流水不动。所以判据必须是
     * 「这位客人在这一单上现存的净次数」（把之前退过的负数行也加进来），
     * 不能是原流水上的 counted_visit —— 否则一单核销两张券时，
     * 第二次核销会把同一次再退一遍，把客人的次数扣成负的。
     *
     * ── 为什么只退次数，不退积分 ────────────────────────
     *
     * 次数是 App 自己的地面真值：券核销在哪一单，App 一清二楚。
     * 而积分来自【金额】，金额的权威在 POS —— 收银员在 POS 上加那条
     * 折扣行之后，夜间值比对会回读到缩水的金额并按同一套算法冲正
     * （ReconcileService::applyShrink）。在这里凭空猜一个「免费那份值多少钱」
     * 去扣分，等于给同一件事造第二套算法，两边迟早对不上
     * （docs/13 §3.5「前后说两套话」）。
     *
     * @return int 实际退回的次数
     */
    private function clawBackVisitOnRedeem(string $serialId, int $memberId, string $code): int
    {
        /**
         * ── 🔴 免掉的份数属于【订单】，不属于某个人 ──────────────
         *
         * 这个方法第一版只看【券持有人】自己的流水。而现实里
         * 记账人和用券的人经常不是同一个：
         *
         *   一家人：爸爸把整单（2 份）记到自己卡上，妈妈拿自己的券免掉一份。
         *   → markRedeemedByApp 把净份数减到 1，
         *     而 clawBackVisitOnRedeem 去找妈妈的流水 —— 她在这一单上没有，
         *     于是【一次都没退】，爸爸名下仍记着 2 次。
         *   店里既免了一份饭，又给了那份饭一次「十送一」的进度 —— 白送两头。
         *
         * F4 修的是「券持有人 = 记账人」那一条，这是同一形状的另一条
         * （docs/13 §3.1）。现在按【订单】来算：
         *
         *   by_portion  ：计次与份数绑定 —— 这一单的净计次总和
         *                 不得超过净份数，超出的部分退掉。
         *   其它口径     ：一个人一单最多 1 次，与份数无关。
         *                 只有当他自己那几份【全被券盖住】时才退他那一次
         *                 （原有判据，混合桌里付了钱的人不连坐）。
         *                 而券持有人根本不在这一单上时，谁的那一次该退
         *                 是判不出来的 —— 不猜，挂告警让人看一眼。
         *
         * ★ 退的时候券持有人排最前：免费吃的是他。
         * ★ 幂等：判据是「现存净次数」（含以前退过的负数行），
         *   一单核销两张券不会把同一次退两遍。
         */
        $order = $this->orders->findBySerial($serialId);
        if ($order === null) {
            return 0;   // 核销时填了个本地没有的单号 —— 与计次无关
        }

        /**
         * 锚点：新插的那条负数流水挂在谁身上。必须是一条【还活着】的
         * 记账流水 —— 挂到已经被冲正的行上，等于把一次退给一笔不存在的账。
         */
        $anchor = [];
        foreach ($this->ledger->activeBySerial($serialId) as $e) {
            if ((int)$e['entry_type'] === \Vip\Repo\LedgerRepo::T_EARN) {
                $anchor[(int)$e['member_id']] ??= (int)$e['id'];
            }
        }
        if (!$anchor) {
            return 0;   // 还没记账（正常顺序：先核销后记账），什么都不用做
        }

        /**
         * ── 🔴 净额要用【全部流水】求和，不能只看活动行 ──────────
         *
         * 这里第一版是拿上面那趟 activeBySerial() 顺手加出来的。
         * 而撤销的写法是「原流水标 status=2，另插一条负数流水」——
         * 只筛活动行，就变成【负数留着、被它抵掉的正数丢了】。
         *
         * 实测（fuzz by_portion seed 30 第 89 步）：
         *   +2（已冲正） −2（冲正行） +2（重记）
         *   活动行加出来 = 0，客人手上其实是 2 次。
         *   于是「净计次 0 ≤ 净份数 1」判成「不用退」，
         *   券抵掉的那一份仍旧给客人留着计次 —— 又是一顿白送。
         *
         * 这就是 docs/13 §3.0 那个老坑的第 N 次现身：
         * 「当初记了多少」和「现在还剩多少」是两个数。
         * 追加式账本里后者永远是【全部流水求和】（不变量①同此口径）。
         */
        $net = []; $portions = [];
        foreach ($this->ledger->netBySerial($serialId) as $mid => $r) {
            $net[$mid]      = $r['visits'];
            $portions[$mid] = $r['portions'];
        }

        // 这一单上一共核销了几张券（不分持有人）—— 一张券 = 一份免费套餐
        $freedAll = (int)$this->db->value(
            'SELECT COUNT(*) FROM coupon
              WHERE store_code = ? AND redeemed_serial_id = ? AND status = ?',
            [$this->storeCode, $serialId, self::ST_REDEEMED]
        );
        if ($freedAll <= 0) {
            return 0;
        }

        $byPortion = $this->cfg->get('visit_count_mode', 'once_per_period') === 'by_portion';
        $netLeft   = (int)$order['portions_counted'];   // markRedeemedByApp 已经减过了
        $totalNet  = array_sum($net);

        /** @var array<int,int> $want 每位会员要退几次 */
        $want = [];

        /**
         * ── 实付份数归零：谁的计次都不留（两种口径共用）─────────
         *
         * netLeft 是【订单的净份数】，券抵掉的已经减过。它到 0，
         * 意思是这顿饭没有一份是花钱吃的 —— 那这一单上任何人名下的
         * 计次都不该留着，与谁是券持有人无关。
         *
         * ★ 这里原来是「券持有人不在这一单的记账人里 → 判不出该退谁，
         *   挂个告警了事」。听着谨慎，实际是【一次都不退】：
         *   实测（fuzz by_order seed 12 第 105 步）1 份的单由会员甲记账，
         *   会员乙用自己的券把它免掉，甲名下那一次原样留着 —— 又一顿白送。
         *
         *   「判不出该退谁」这个顾虑在这里根本不成立：份数归零时
         *   要退的是【所有人的全部】，没有需要挑的余地。真正判不出的
         *   只有「还剩份数在付费」的那一档，那一档下面照旧只动券持有人。
         *
         * ★ 计次只从餐费项来（meal_item_rule.counts_visit）。净份数 0
         *   的单本来就产不出计次，所以纯酒水单不会被这一条误伤 ——
         *   它的 totalNet 本身就是 0。
         */
        if ($netLeft <= 0) {
            foreach ($net as $mid => $v) {
                if ($v > 0 && isset($anchor[$mid])) {
                    $want[$mid] = $v;
                }
            }
        } elseif ($byPortion) {
            // 计次 = 份数：这一单的净计次总和不得超过净份数
            $excess = $totalNet - $netLeft;
            if ($excess > 0) {
                // 券持有人排最前，其余按净次数从多到少。
                // 只找【挂得上锚点的人】—— 没有活动记账流水的人退不了，
                // 把额度分给他等于白白漏掉一次。
                $order2 = array_values(array_filter(array_keys($net),
                    static fn(int $mid): bool => isset($anchor[$mid])));
                usort($order2, static function (int $x, int $y) use ($memberId, $net): int {
                    if ($x === $memberId) { return -1; }
                    if ($y === $memberId) { return 1; }
                    return ($net[$y] ?? 0) <=> ($net[$x] ?? 0);
                });
                foreach ($order2 as $mid) {
                    if ($excess <= 0) { break; }
                    $take = min($excess, max(0, $net[$mid] ?? 0));
                    if ($take > 0) { $want[$mid] = $take; $excess -= $take; }
                }
            }
        } else {
            /**
             * 还有份数在付费：一个人一单最多 1 次，只动券持有人 ——
             * 只有他自己记的那几份【全被券盖住】时才退。
             * 混合桌里真掏了钱的人不连坐（docs/03 §5.5「静默少给」）。
             */
            $mine = (int)($portions[$memberId] ?? 0);
            if (isset($anchor[$memberId]) && ($net[$memberId] ?? 0) > 0
                && $mine <= $freedAll) {
                $want[$memberId] = (int)$net[$memberId];
            }
        }

        /**
         * 该退而退不掉的：次数长在没有活动记账流水的人身上，
         * 冲正流水没地方挂。不猜，报出来让人看一眼。
         */
        $short = $totalNet - max(0, $netLeft) - array_sum($want);
        if ($short > 0) {
            $this->alerts->raiseOnce(
                'redeem_visit_unattributable', 'order', $serialId,
                sprintf('订单 %s 用券之后净份数只剩 %d，可这一单上还记着 %d 次，'
                      . '多出来的 %d 次找不到可以冲正的记账流水，请人工核对',
                        $serialId, max(0, $netLeft), $totalNet, $short),
                ['severity' => 2, 'detail' => ['coupon' => $code, 'holder' => $memberId]]);
        }

        $done = 0;
        foreach ($want as $mid => $take) {
            if ($take <= 0 || !isset($anchor[$mid])) { continue; }
            $this->members->lockById($mid);
            $this->ledger->insert([
                'member_id'     => $mid,
                'serial_id'     => $serialId,
                'entry_type'    => \Vip\Repo\LedgerRepo::T_REVERSE,
                'amount_cents'  => 0,
                'points'        => 0,
                'counted_visit' => -$take,
                'reverses_id'   => $anchor[$mid],
                'reason'        => sprintf('券 %s 核销在本单上，免费那一餐不计次', $code),
            ]);
            $this->members->applyDelta($mid, 0, -$take, 0);
            $done += $take;
        }
        return $done;
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
        /**
         * ── 🔴 只跟【同一个口径】发出来的券比进度 ──────────────
         *
         * progress_at_grant 是个【不带单位】的整数：按次数发券时它是
         * 「第几次」，按金额发券时它是「累计多少分」。两者差三个数量级。
         *
         * 店家把 reward_mode 从 amount 改成 visits 之后，
         * 客人手上那些老券的 progress_at_grant 还是 18000（=180 €），
         * 而新口径下的 $p['progress'] 是 3（次）。
         * `18000 > 3` 恒真 → 一次撤销就把【客人手上所有老券】全作废。
         *
         * 实测：按金额发出 3 张券，改成按次数后撤销一单 —— 3 张全变状态 4。
         * 客人拿着券来吃饭，系统说「这张券无效」，而店里查不出为什么。
         *
         * source 本来就记着是哪个口径发的（checkAndGrant 按 mode 写入），
         * 只跟当前口径的那一批比就行。另一个口径的券不动 ——
         * 它是按当时的规则挣到的，新规则没有资格判它。
         */
        $srcNow = $p['mode'] === 'amount' ? self::SRC_AMOUNT : self::SRC_VISITS;
        /**
         * ── 🔴 只捞【有效】券，而且要多少捞多少 ────────────────────
         *
         * 原来这一句不按状态过滤，只 `ORDER BY progress_at_grant DESC LIMIT 50`，
         * 捞回来再在 PHP 里筛出有效的。一旦前 50 名被【已作废】的券占满，
         * 之后每一次调用都捞回同样那 50 张作废券 → 候选为空 → 收回 0 张，
         * 而 rewards_issued 永远卡在那个数上：
         *
         *   实测（门槛 1、60 张券、计次归零）：
         *     第一次回收 → 收回 50 张，issued 60 → 10
         *     第二次回收 → 收回 【0】 张，issued 卡在 10          🔴
         *     客人手上白留 10 张免费餐券，再怎么调用都收不回来。
         *
         * 这不是「一次收不完、下次接着收」——是【永久卡死】。
         * 60 张不算离谱：门槛 10 的老客攒到 600 次就是 60 张，
         * 而一次数据订正把计次调下来就会走到这里。
         *
         * 修法两条，缺一不可：
         *   ① status = 有效 放进 SQL —— LIMIT 才是「50 张能收的」，
         *      不是「50 张随便什么状态的」；
         *   ② 上限按【还要收几张】取，不再写死 50 —— 一次就能收干净。
         *      仍保留一个上限防止极端情况下拼出巨大语句，
         *      而因为①，重复调用一定能继续收，收敛有保证。
         */
        $lim   = max(1, min($over, 500));
        $cands = $this->db->all(
            'SELECT id, code FROM coupon
              WHERE store_code = ? AND member_id = ? AND source = ? AND status = ?
                AND progress_at_grant > ?
              ORDER BY progress_at_grant DESC, id DESC LIMIT ' . $lim,
            [$this->storeCode, $memberId, $srcNow, self::ST_ACTIVE, (int)$p['progress']]
        );

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

        /**
         * ── unrecoverable：只数【同口径、发于已退掉那段进度、且已经吃掉】的 ──
         *
         * 原来是 `$over - count($codes)` —— 差额里可能混进
         * 「换过发券口径」造成的账面差（issued 是两个口径累计的，
         * earned 只按当前口径算）。那种差额收不回来也不该报警：
         * 没有人多吃一顿饭，只是两把尺子量出来的数不一样。
         *
         * 真正要报警的是这一件事：有张券发在【后来被退掉的那段进度上】，
         * 而客人已经把它吃了 —— 店里实打实亏一顿饭。
         */
        /**
         * ★ 已经吃掉的单独数一遍，不再从上面那批候选里筛。
         *   候选现在只含有效券（且带 LIMIT），从里面筛「已核销」恒为 0，
         *   告警就再也不会响 —— 而那条告警说的正是「白送了一顿饭」。
         *   这里不加 LIMIT：数数不产生大结果集。
         */
        $eaten = (int)$this->db->value(
            'SELECT COUNT(*) FROM coupon
              WHERE store_code = ? AND member_id = ? AND source = ? AND status = ?
                AND progress_at_grant > ?',
            [$this->storeCode, $memberId, $srcNow, self::ST_REDEEMED, (int)$p['progress']]
        );

        return ['voided'        => count($codes),
                'unrecoverable' => max(0, min($over - count($codes), $eaten)),
                'codes'         => $codes];
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
    /**
     * 「待发」队列 —— 谁攒够了、还欠他几张。
     *
     * ── 🔴 影子模式原来只有一半（审计 F8） ───────────────────
     *
     * docs/13 §6 建议上线第一个月把 reward_auto_grant 关掉：
     * 「达标的客人进后台【待发】队列，由经理逐张确认后发出」。
     * checkAndGrant 那一侧也确实写着「只提示不发，后台可手工发」。
     *
     * 但后台【没有这个队列】。/coupons 只列已经发出去的券，
     * pendingAcrossMembers() 只回一个总数，没有名单。
     * 于是关掉自动发放之后，经理看得到「欠 7 张」，
     * 却查不出是哪 7 位客人 —— 只能一张卡一张卡去搜。
     * 建议的上线方式实际上没法执行，最后必然是把自动发放打开硬上。
     *
     * ★ 与 pendingAcrossMembers 同一套算法（按各人自己那档门槛），
     *   两处必须给出同一个数，否则「总数 7、名单 5 行」谁也不敢信。
     *
     * @return array<int,array{member_id:int,card_no:?string,tier_code:?string,
     *                         pending:int,progress:int,threshold:int,mode:string}>
     */
    public function pendingList(int $limit = 200): array
    {
        $global = $this->rule(null);
        if (!$global['enabled']) {
            return [];
        }
        $tiers = [];
        foreach ($this->tiers->all() as $t) {
            $tiers[(string)$t['code']] = $t;
        }

        $rows = $this->db->all(
            'SELECT m.id, m.card_no, m.visit_count, m.total_spent, m.rewards_issued,
                    m.updated_at, c.tier_code
               FROM member m
               LEFT JOIN card c ON c.store_code = m.store_code AND c.member_id = m.id
              WHERE m.store_code = ?',
            [$this->storeCode]
        );

        $out = [];
        foreach ($rows as $row) {
            $code = $row['tier_code'] ?? null;
            $tier = ($code !== null && isset($tiers[$code])) ? [
                'code'             => $code,
                'threshold_visits' => $tiers[$code]['threshold_visits'],
                'threshold_amount' => $tiers[$code]['threshold_amount'],
            ] : null;
            $p = $this->progressOf($row, $tier);
            if ($p['pending'] <= 0) {
                continue;
            }
            $out[] = [
                'member_id' => (int)$row['id'],
                'card_no'   => $row['card_no'] ?? null,
                'tier_code' => $code,
                'pending'   => (int)$p['pending'],
                'progress'  => (int)$p['progress'],
                'threshold' => (int)$p['threshold'],
                'mode'      => (string)$p['mode'],
                'text'      => (string)$p['text'],
                'updated_at'=> $row['updated_at'] ?? null,
            ];
        }
        // 欠得最多的排最前 —— 那几位是最可能出问题、也最该先看一眼的
        usort($out, static fn(array $a, array $b): int => $b['pending'] <=> $a['pending']);
        return array_slice($out, 0, max(1, min($limit, 500)));
    }

    /**
     * 经理在「待发」队列上按下确认 —— 把这位客人欠的券发出来。
     *
     * 与 checkAndGrant 的唯一区别是【不看 auto_grant 开关】：
     * 关掉自动发放本来就是为了「由人确认」，这里就是那个确认动作。
     * 其余（行锁、按各人门槛算、rewards_issued 幂等、券上定格门槛）
     * 完全走同一条路 —— 两条路对同一件事必须给出同一个结果。
     */
    public function issuePending(int $memberId, array $operator = []): array
    {
        $tier = $this->tiers->forMember($memberId);
        $r    = $this->rule($tier);
        if (!$r['enabled']) {
            return ['ok' => false, 'error' => 'reward_disabled'];
        }

        return $this->db->transaction(function () use ($memberId, $tier, $r, $operator): array {
            $m = $this->members->lockById($memberId);
            if ($m === null) {
                return ['ok' => false, 'error' => 'member_not_found'];
            }
            $p = $this->progressOf($m, $tier);
            if ($p['pending'] <= 0) {
                return ['ok' => false, 'error' => 'nothing_pending'];
            }

            /**
             * ★ 人工放行这条路也要按批来。
             *
             *   这里是「人点了一下」，比自动发放可信得多，所以不像
             *   checkAndGrant 那样一张都不发 —— 但一次点击也不该吐出
             *   几百张券：那既是一笔几百行的长事务（锁着这位会员的行），
             *   也让点的人根本看不清自己放行了多少顿饭。
             *   一次发一批、把「还剩几张」回给界面，再点就是再一次确认。
             */
            $cap  = max(1, $this->cfg->int('reward_max_auto_grant', 10));
            $take = min($p['pending'], $cap);

            $out = [];
            for ($i = 0; $i < $take; $i++) {
                $out[] = $this->issue(
                    $memberId,
                    $r['mode'] === 'amount' ? self::SRC_AMOUNT : self::SRC_VISITS,
                    $p['progress'],
                    $r['valid_days'],
                    null,
                    $operator,
                    $tier['code'] ?? null,
                    $p['threshold']
                );
            }
            $this->db->exec(
                'UPDATE member SET rewards_issued = rewards_issued + ?, updated_at = ?
                  WHERE store_code = ? AND id = ?',
                [count($out), $this->db->now(), $this->storeCode, $memberId]
            );
            $this->audit->log('coupon_grant', [
                'target_type'   => 'member', 'target_id' => (string)$memberId,
                'operator_id'   => $operator['id']   ?? null,
                'operator_name' => $operator['name'] ?? null,
                'detail' => ['from' => 'pending_queue', 'count' => count($out),
                             'progress' => $p['progress'], 'threshold' => $p['threshold'],
                             'remaining' => $p['pending'] - count($out),
                             'codes' => array_map(static fn(array $c): string => (string)$c['code'], $out)],
            ]);
            return ['ok' => true, 'granted' => count($out), 'coupons' => $out,
                    'remaining' => $p['pending'] - count($out)];
        });
    }

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
