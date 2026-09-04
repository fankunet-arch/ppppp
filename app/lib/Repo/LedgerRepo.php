<?php
declare(strict_types=1);

namespace Vip\Repo;

use Vip\LocalDb;
use Vip\Money;

/**
 * point_ledger —— 追加式账本。
 *
 * ★ 只增不删。撤销 = 写一条反向冲正记录，绝不物理删除原流水。
 *   理由：西班牙会计/税务留存义务 + 全流程可审计 +
 *   避免并发删除破坏金额守恒。docs/03 §4.1
 */
final class LedgerRepo
{
    // entry_type
    public const T_EARN     = 1;   // 消费积分
    public const T_REVERSE  = 2;   // 撤销冲正
    public const T_REFUND   = 3;   // 退单冲正（值比对发现金额变小）
    public const T_REDEEM   = 4;   // 兑换扣减
    public const T_EXPIRE   = 5;   // 过期清零
    public const T_MANUAL   = 6;   // 手工调整

    // status
    public const S_ACTIVE   = 1;
    public const S_REVERSED = 2;

    // source
    public const SRC_POS    = 1;   // POS 订单匹配
    public const SRC_MANUAL = 2;   // 手工录入（降级路径）

    public function __construct(private LocalDb $db, private string $storeCode)
    {
    }

    /** @return int 新流水 id */
    public function insert(array $e): int
    {
        $this->db->exec(
            'INSERT INTO point_ledger
               (store_code, member_id, serial_id, entry_type, amount, points, counted_visit,
                portions_counted, portions_uncounted, excluded_amount,
                alloc_mode, alloc_detail, grant_group, status, reverses_id,
                tier_code, tier_multiplier,
                source, manual_reason, review_status, approved_by,
                operator_id, operator_name, device, reason, created_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [
                $this->storeCode,
                $e['member_id'],
                $e['serial_id'] ?? null,
                $e['entry_type'],
                Money::toStr($e['amount_cents'] ?? 0),
                $e['points'] ?? 0,
                $e['counted_visit'] ?? 0,
                $e['portions_counted'] ?? 0,
                $e['portions_uncounted'] ?? 0,
                Money::toStr($e['excluded_cents'] ?? 0),
                $e['alloc_mode'] ?? null,
                isset($e['alloc_detail']) ? json_encode($e['alloc_detail'], JSON_UNESCAPED_UNICODE) : null,
                // 多桌合并时同一组的几笔共用一个值；单桌记账为 NULL
                $e['grant_group'] ?? null,
                $e['status'] ?? self::S_ACTIVE,
                $e['reverses_id'] ?? null,
                // 入账时那张卡的等级与实际套用的倍率。倍率是活查的，
                // 不在流水里定格的话，改一次倍率历史就再也对不上账
                $e['tier_code'] ?? null,
                isset($e['tier_multiplier']) ? number_format((float)$e['tier_multiplier'], 2, '.', '') : null,
                $e['source'] ?? self::SRC_POS,
                $e['manual_reason'] ?? null,
                $e['review_status'] ?? 0,
                $e['approved_by'] ?? null,
                $e['operator_id'] ?? null,
                $e['operator_name'] ?? null,
                $e['device'] ?? null,
                $e['reason'] ?? null,
                $this->db->now(),
            ]
        );
        return $this->db->lastInsertId();
    }

    /**
     * 把「这个操作员的手工录入」这条线串行化 —— 单行 X 锁，不锁区间。
     *
     * ── 🔴 为什么必须有互斥（审计 F14） ────────────────────
     *
     * 日额度是「读一下再写」：读出今天用了多少、判没超、才写。
     * 中间没有互斥就等于没上限 —— 实测 4 台 Pad 同时连录，
     * € 300 的上限落进 € 360。与 checkAndGrant 那次
     * 「四个进程发出四张券」完全同形。
     *
     * ── 为什么是【单行】而不是区间 ─────────────────────────
     *
     * 第一版锁流水的区间（SELECT … FOR UPDATE），实测每 160 笔死锁
     * 5–7 次：InnoDB 的 gap 锁彼此不冲突，两笔手工录入都拿得到同一个
     * gap，然后各自要往里 INSERT，互相等对方的 gap 锁。
     * 单行 X 锁没有 gap，两个事务不可能同时持有，排队即可，不会成环。
     *
     * ── 为什么单开一张表，不复用 operator 那一行 ─────────────
     *
     * 复用要求那一行确实存在。冒烟里的合成操作员没有对应行，
     * 锁当场落空、上限被撑破 € 360/€ 300，而代码看上去完全正确 ——
     * 「碰巧存在」不是可以拿来守钱的性质（docs/13 §3.4）。
     * 这里的行由 ensureQuotaRow() 自己按需补齐，不依赖任何外部前提。
     *
     * ★ 补行那一步【必须在事务外】先跑掉（autocommit）。
     *   放在事务里的话，两个事务同时 INSERT 同一个主键，
     *   后到的会先拿一把 S 锁，随后两边都要升级成 X —— 又是一个环。
     *   固定调用顺序：ensureQuotaRow() → transaction{ lockManualQuota() → … }
     *
     * ★ 只当互斥量，不存任何业务数值：「今天用了多少」仍然实时从
     *   point_ledger 算。单一真相不变，撤销后额度自动释放这一现有语义也不变。
     */
    public function ensureQuotaRow(int $operatorId): void
    {
        if ($operatorId <= 0) {
            return;
        }
        $this->db->exec(
            'INSERT IGNORE INTO manual_entry_lock (store_code, operator_id, updated_at)
             VALUES (?,?,?)',
            [$this->storeCode, $operatorId, $this->db->now()]
        );
    }

    /** 取那把单行 X 锁。调用方须已在事务内，且已先调过 ensureQuotaRow()。 */
    public function lockManualQuota(int $operatorId): void
    {
        if ($operatorId <= 0) {
            return;
        }
        $this->db->value(
            'SELECT operator_id FROM manual_entry_lock
              WHERE store_code = ? AND operator_id = ? FOR UPDATE',
            [$this->storeCode, $operatorId]
        );
    }

    /**
     * 已经针对某一笔流水做过、且仍然有效的【补偿流水】。
     *
     * ── 🔴 撤销一笔时，必须先看它已经被补偿掉多少 ────────────
     *
     * 「先记账、后核销」时，clawBackVisitOnRedeem() 会另插一条
     * 「免费那一餐不计次」的负数流水，reverses_id 指向原流水，
     * 但【原流水本身不动】（账本是追加式的，不改历史行）。
     *
     * 于是原流水上那个 counted_visit 已经不代表「现在还欠客人几次」了。
     * 谁再照着它退一次，就是把同一次退两遍 —— 实测客人计次被扣穿：
     *
     *     ① 记账      #109 +1 有效
     *     ② 核销      #110 -1 有效（reverses_id=109），会员 1 → 0
     *     ③ 撤销 #109 #112 -1 有效 —— 又退了一次，会员 0 → -1  🔴
     *
     * 而这个形状有【两处】踩：手工撤销（reverseInTx）与值比对整单归零
     * （ReconcileService::applyShrink）。两处都要先问一句
     * 「这一笔还剩多少没退」，而不是「这一笔当初记了多少」。
     *
     * ★ clawBackVisitOnRedeem 自己是按「这位客人在这一单上现存的净次数」
     *   算的，所以一单核销两张券不会退两遍 —— 但那条判据没被另外两处共用，
     *   正是 docs/13 §3.1「修了那一处、没修那一类」。
     *
     * 命中 idx_reverse (reverses_id)。
     */
    public function activeCompensationsOf(int $ledgerId, bool $forUpdate = false): array
    {
        return $this->db->all(
            'SELECT id, amount, points, counted_visit
               FROM point_ledger
              WHERE store_code = ? AND reverses_id = ? AND status = ?'
            . ($forUpdate ? ' FOR UPDATE' : ''),
            [$this->storeCode, $ledgerId, self::S_ACTIVE]
        );
    }

    /**
     * 这一单上有哪些会员的记账流水（只取 id，用来【先加锁再读账】）。
     *
     * ★ 调用方必须已经握着这一单的订单行 X 锁 —— 那把锁挡住了并发记账，
     *   所以这里普通读拿到的名单不会再变；名单定下来才谈得上「按 id 升序
     *   把人锁完」。顺序固定是防死锁的第一道（docs/13 §3.4）。
     *
     * @return int[] 已按 id 升序
     */
    public function memberIdsOnSerial(string $serialId): array
    {
        $ids = [];
        foreach ($this->db->all(
            'SELECT DISTINCT member_id FROM point_ledger
              WHERE store_code = ? AND serial_id = ?',
            [$this->storeCode, $serialId]
        ) as $r) {
            $ids[] = (int)$r['member_id'];
        }
        sort($ids);
        return $ids;
    }

    public function findById(int $id): ?array
    {
        return $this->db->one(
            'SELECT * FROM point_ledger WHERE store_code = ? AND id = ?',
            [$this->storeCode, $id]
        );
    }

    public function lockById(int $id): ?array
    {
        return $this->db->one(
            'SELECT * FROM point_ledger WHERE store_code = ? AND id = ? FOR UPDATE',
            [$this->storeCode, $id]
        );
    }

    /** 标记原流水已被冲正 */
    public function markReversed(int $id, int $reversalId): void
    {
        $this->db->exec(
            'UPDATE point_ledger SET status = ?, reversed_by_id = ? WHERE store_code = ? AND id = ?',
            [self::S_REVERSED, $reversalId, $this->storeCode, $id]
        );
    }

    /** 某订单下的有效流水（用于展示「已记给谁」与撤销入口） */
    /**
     * 这一单上每位会员【现在还剩多少】—— 计次与份数的净额。
     *
     * ── 🔴 为什么不能用 activeBySerial() 去加 ──────────────────
     *
     * 撤销是「原流水标 status=2，另插一条负数流水（status=1）」。
     * 于是按 status=1 筛出来的行里，【负数留着、被它抵掉的正数没了】——
     * 净额会凭空少一份。实测（fuzz by_portion seed 30）：
     *   +2（已冲正）  −2（冲正行）  +2（重记）
     *   按活动行加 = 0，而这位客人手上实实在在是 2 次。
     * 少算的后果是「该退的没退」—— 券抵掉的那一份还给客人留着计次，
     * 也就是往下一顿白送。
     *
     * 追加式账本里「现在还剩多少」永远是【全部流水求和】，不筛状态 ——
     * member.visit_count / points_balance 就是这么维护的
     * （见不变量①：余额 == 全部流水合计，同样不带状态条件）。
     *
     * @return array<int,array{visits:int,portions:int}> 按 member_id
     */
    public function netBySerial(string $serialId, bool $forUpdate = false): array
    {
        /**
         * ★ 要加锁时【不能用 GROUP BY】—— MySQL 不允许聚合查询带 FOR UPDATE。
         *   所以加锁那一档把行取回来在 PHP 里合计，结果完全一样。
         *
         * ★ 为什么非要 FOR UPDATE：InnoDB 默认 REPEATABLE READ，
         *   一笔事务里【普通读】看到的是事务开头那一刻的快照 ——
         *   哪怕你在读之前已经把会员行锁到手、等对方提交完了，
         *   普通读【照样返回旧值】。只有加锁读才读得到最新已提交版本。
         *   「先加锁再读」这句话，在 InnoDB 上必须写成「先加锁再【加锁读】」。
         *
         * ★ 命中 idx_order (store_code, serial_id)，锁的范围就是这一单的流水。
         */
        $rows = $forUpdate
            ? $this->db->all(
                'SELECT member_id, counted_visit, portions_counted
                   FROM point_ledger
                  WHERE store_code = ? AND serial_id = ? FOR UPDATE',
                [$this->storeCode, $serialId])
            : $this->db->all(
                'SELECT member_id,
                        COALESCE(SUM(counted_visit), 0)    AS counted_visit,
                        COALESCE(SUM(portions_counted), 0) AS portions_counted
                   FROM point_ledger
                  WHERE store_code = ? AND serial_id = ?
                  GROUP BY member_id',
                [$this->storeCode, $serialId]);

        $out = [];
        foreach ($rows as $r) {
            $mid = (int)$r['member_id'];
            $out[$mid] ??= ['visits' => 0, 'portions' => 0];
            $out[$mid]['visits']   += (int)$r['counted_visit'];
            $out[$mid]['portions'] += (int)$r['portions_counted'];
        }
        return $out;
    }

    public function activeBySerial(string $serialId, bool $forUpdate = false): array
    {
        /**
         * ★ 加锁那一档【不能带 LEFT JOIN member】：那会把会员行也一起锁上，
         *   而会员行在本仓库的加锁顺序里排在订单之后、流水之前
         *   （pos_order → member → coupon → point_ledger）。
         *   在这里顺手锁会员，等于把顺序打乱。要卡号自己再查。
         */
        if ($forUpdate) {
            return $this->db->all(
                'SELECT * FROM point_ledger
                  WHERE store_code = ? AND serial_id = ? AND status = ?
                  ORDER BY id ASC FOR UPDATE',
                [$this->storeCode, $serialId, self::S_ACTIVE]
            );
        }
        return $this->db->all(
            'SELECT l.*, m.card_no
               FROM point_ledger l
               LEFT JOIN member m ON m.id = l.member_id AND m.store_code = l.store_code
              WHERE l.store_code = ? AND l.serial_id = ? AND l.status = ?
              ORDER BY l.id ASC',
            [$this->storeCode, $serialId, self::S_ACTIVE]
        );
    }

    /**
     * 这张卡在某个时间段内【有效的消费流水】，带上对应订单的结账时间。
     *
     * 给风控用（docs/03 §12）：判「这一顿记了几次」「几单跨了多久」都要
     * 订单的 order_end_time，光看流水的 created_at 不行 ——
     * 补记的那一笔 created_at 是今天，而它记的可能是三天前的一顿饭。
     *
     * 只取 entry_type=消费 且 status=有效：撤销过的不该再算进风控里。
     * LIMIT 200 是防呆上限，一张卡一天不可能有这么多笔。
     */
    public function earnedInRange(int $memberId, string $from, string $to, int $limit = 200): array
    {
        return $this->db->all(
            'SELECT l.id, l.serial_id, l.grant_group, l.counted_visit, o.order_end_time
               FROM point_ledger l
               JOIN pos_order o ON o.store_code = l.store_code AND o.serial_id = l.serial_id
              WHERE l.store_code = ? AND l.member_id = ? AND l.entry_type = ? AND l.status = ?
                AND o.order_end_time >= ? AND o.order_end_time < ?
              ORDER BY o.order_end_time
              LIMIT ' . max(1, min($limit, 500)),
            [$this->storeCode, $memberId, self::T_EARN, self::S_ACTIVE, $from, $to]
        );
    }

    /**
     * 一次多桌合并产出的全部有效流水，用于整组撤销。
     *
     * ── 🔴 这里【不能】加 FOR UPDATE ──────────────────────────
     *
     * 加锁顺序全仓库统一成 pos_order → member → coupon → point_ledger。
     * 在这里先把流水行锁住，就是把 point_ledger 提到了最前面 ——
     * 而 reverseInTx 逐条处理时会去锁订单和会员，两者交叉就是死锁。
     *
     * 不加锁也不会漏：reverseInTx 自己会按顺序锁到订单、会员，
     * 最后【加锁读】这一行并复核 status，中途被别人撤掉的那一笔
     * 会当场返回 already_reversed，整组一起回滚。
     *
     * ★ 排序按 serial_id 在前：整组撤销要按固定顺序去锁那几张订单行，
     *   否则两笔重叠的整组撤销会互相等（和 grantMerged 的 sort($serials)
     *   同一个道理）。
     */
    public function activeByGroup(string $group): array
    {
        return $this->db->all(
            'SELECT * FROM point_ledger
              WHERE store_code = ? AND grant_group = ? AND entry_type = ? AND status = ?
              ORDER BY serial_id IS NULL, serial_id, id',
            [$this->storeCode, $group, self::T_EARN, self::S_ACTIVE]
        );
    }

    public function recentByMember(int $memberId, int $limit = 20): array
    {
        return $this->db->all(
            'SELECT * FROM point_ledger
              WHERE store_code = ? AND member_id = ?
              ORDER BY id DESC LIMIT ' . max(1, min($limit, 100)),
            [$this->storeCode, $memberId]
        );
    }

    /** 手工录入的日频次（风控告警用） */
    /**
     * 同一员工在【当前营业日】内手工录入的笔数。
     *
     * ★ 起点由调用方按营业日算好传进来，不在这里用 date('Y-m-d 00:00:00')。
     *   这套系统的营业日切点是 02:00，晚市 19:30 做到凌晨 02:00 ——
     *   一个班次是跨零点的。按日历零点切，等于在班次中间把额度清零重来。
     *
     * ★ 只数【消费】流水（T_EARN）。撤销会插一条 entry_type=T_REVERSE
     *   的负数行，而它的 source 是从原流水复制来的（也是 MANUAL）——
     *   不排除的话，撤销一笔就把已用额度算成负的。
     */
    public function manualCountSince(int $operatorId, string $since): int
    {
        return (int)$this->db->value(
            'SELECT COUNT(*) FROM point_ledger
              WHERE store_code = ? AND source = ? AND operator_id = ?
                AND entry_type = ? AND status = ? AND created_at >= ?',
            [$this->storeCode, self::SRC_MANUAL, $operatorId,
             self::T_EARN, self::S_ACTIVE, $since]
        );
    }

    /**
     * 同一员工今日手工录入的【金额合计】（分）。
     *
     * ★ 与 manualCountToday 是两件事，不能互相代替：
     *   原来的风控只数【笔数】（超过 5 笔告警）。可 3 笔 200.00 就是 600.00，
     *   在「按金额」发券、门槛 100 的配置下等于当场造出 6 张免费餐券 ——
     *   笔数没超，告警不响，账面上什么都看不出来。
     *   钱的事要用钱来管。
     */
    /**
     * 同一员工在【当前营业日】内手工录入的金额合计（分）。
     *
     * ── 🔴 两处都踩过坑，都写在这里 ────────────────────
     *
     * ① 起点必须按【营业日】算，不能用 date('Y-m-d 00:00:00')。
     *    切点是 02:00，晚市 19:30 做到凌晨 02:00 —— 一个班次跨零点。
     *    按日历切的话，同一个人、同一个班次，零点一过额度就重置：
     *    上限写 € 300，实测一个班次录进 € 600。
     *
     * ② 必须只算【消费】流水（T_EARN）。撤销插入的那一行
     *    entry_type = T_REVERSE、金额为负，而 source 是从原流水
     *    复制来的（同样是 MANUAL），状态也是有效。不排除的话：
     *    录 300 → 已用 300（到顶）→ 撤销 → 已用变成【-300】
     *    → 又能再录 600。额度不是还回来，是【翻倍】。
     *    实测一个班次因此录进 € 600，而上限写的是 € 300。
     *
     *    排除之后：撤销把原流水标成 REVERSED，那一笔的额度正好还回来一次
     *    （值也一并没了，clawBackOverIssued 会把券收回），前后是一致的。
     */
    /**
     * 这位操作员从 $since 起手工录进去多少钱（分）。
     *
     * ── 判日额度时【不在这里加锁】，互斥交给 lockManualQuota() ────
     *
     * 这里曾经带过 FOR UPDATE，想用区间锁把「我刚量过的这一段」封住。
     * 看着对，实测每 160 笔稳定死锁 5–7 次。InnoDB 自己的记录：
     *
     *     TRX A：持有 idx_operator 上的 X gap 锁，等 member 行锁
     *     TRX B：持有 member 行锁，  等着往那个 gap 里 insert
     *
     * 根因是 gap 锁【彼此不冲突】：两笔手工录入都拿得到同一个 gap，
     * 然后各自要往里 INSERT，于是互相等对方的 gap 锁。
     * 「先 FOR UPDATE 一段区间，再往这段区间 INSERT」是经典死锁形状，
     * 与锁的列粒度无关 —— 把 source 加进索引也没用，冲突双方都是手工录入。
     *
     * 现在这里是纯读，互斥由 lockManualQuota() 的【单行】X 锁提供。
     *
     * ★ 仍然要走 idx_operator（迁移 016 建、017 把 source 提前）——
     *   否则每笔手工录入都要全表扫一遍流水。
     */
    public function manualAmountSince(int $operatorId, string $since): int
    {
        // 条件顺序照着 idx_operator 写：store_code / operator_id / source 等值，created_at 范围
        return Money::toCents((string)($this->db->value(
            'SELECT COALESCE(SUM(amount), 0) FROM point_ledger
              WHERE store_code = ? AND operator_id = ? AND source = ? AND created_at >= ?
                AND entry_type = ? AND status = ?',
            [$this->storeCode, $operatorId, self::SRC_MANUAL, $since,
             self::T_EARN, self::S_ACTIVE]
        ) ?? '0'));
    }

    /**
     * 这位会员的累计消费里，有多少是【手工录入】来的（分）。
     *
     * 给发券那一刻的风控用：手工录入没有 POS 订单作证，
     * 它证明的只是「有人说这笔钱花了」。在「按金额」口径下它却
     * 全额计入 total_spent、直接换券 —— 而「按次数」口径下同一笔录入
     * 计次恒为 0，一张券都换不到。同一个动作在两种口径下待遇天差地别，
     * 这个差别没有任何人会预料到。
     */
    public function manualAmountByMember(int $memberId): int
    {
        return Money::toCents((string)($this->db->value(
            // 同上：只算消费流水，撤销那条负数行的 source 也是 MANUAL
            'SELECT COALESCE(SUM(amount), 0) FROM point_ledger
              WHERE store_code = ? AND member_id = ? AND source = ?
                AND entry_type = ? AND status = ?',
            [$this->storeCode, $memberId, self::SRC_MANUAL, self::T_EARN, self::S_ACTIVE]
        ) ?? '0'));
    }

    /** 待复核队列 */
    public function pendingReview(int $limit = 50): array
    {
        return $this->db->all(
            'SELECT * FROM point_ledger
              WHERE store_code = ? AND review_status = 1
              ORDER BY id ASC LIMIT ' . max(1, min($limit, 100)),
            [$this->storeCode]
        );
    }
}
