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
    public function activeBySerial(string $serialId): array
    {
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
     * 加锁：撤销要改会员余额与订单已分配额，必须和并发的记账排队。
     */
    public function lockActiveByGroup(string $group): array
    {
        return $this->db->all(
            'SELECT * FROM point_ledger
              WHERE store_code = ? AND grant_group = ? AND entry_type = ? AND status = ?
              ORDER BY id FOR UPDATE',
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
    public function manualAmountSince(int $operatorId, string $since): int
    {
        return Money::toCents((string)($this->db->value(
            'SELECT COALESCE(SUM(amount), 0) FROM point_ledger
              WHERE store_code = ? AND source = ? AND operator_id = ?
                AND entry_type = ? AND status = ? AND created_at >= ?',
            [$this->storeCode, self::SRC_MANUAL, $operatorId,
             self::T_EARN, self::S_ACTIVE, $since]
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
