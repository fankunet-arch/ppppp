<?php
declare(strict_types=1);

namespace Vip\Repo;

use Vip\LocalDb;
use Vip\Money;

/**
 * pos_order —— POS 已结账订单在本地的镜像，是积分分配的容器。
 *
 * ★ 幂等主键 (store_code, serial_id)。
 *   serial_id 是 POS 生成的业务流水号（YYMMDD+4 位），非自增代理键，
 *   数据库迁移/重建不受影响。order_head_id 保留但不参与任何唯一约束。
 *
 * ★ 主库只读 → 无法在 POS 上打「已积分」标记 → 本地库是幂等的唯一来源。
 *   丢失即导致历史订单重复发分，备份要求见 docs/04 §9。
 */
final class OrderRepo
{
    public function __construct(private LocalDb $db, private string $storeCode)
    {
    }

    /**
     * 落库或更新订单镜像。
     *
     * ── 🔴 两条调用路径，权限【不一样】 ──────────────────
     *
     * | 调用方 | $computed | 能写什么 |
     * |---|---|---|
     * | `PointsService::buildContext()`（读了明细，算得出真值） | `true` | 全部列 |
     * | `SyncService::storeOrder()`（补抓，没读明细） | `false` | 只写订单头与原始金额 |
     *
     * ── 为什么必须分开 ──────────────────────────────────
     *
     * 补抓阶段不读明细（读了请求数翻倍），所以它对
     * total_amount / excluded / portions / is_redeemed 这些
     * 【要靠明细才算得出来的列】只有占位值：total 按「无排除项」估，
     * 其余一律 0。
     *
     * 这些占位值放进【新行】是对的（后台报表与完整性监控要有个数）；
     * 但原来那份 ON DUPLICATE KEY UPDATE 把它们一并写进了【已存在的行】,
     * 于是每 20 分钟一轮的 Cron 会把 Pad 刚算好的真值冲掉：
     *
     *     locate 之后   total=71.70  excl=18.30  份数=3   is_redeemed=1
     *     cron 之后     total=90.00  excl=0.00   份数=0   is_redeemed=0
     *
     * 而 allocated_amount / allocated_portions 不在更新列表里、不会被冲，
     * 镜像因此进入「总额是毛额、已分配是净额、份数为 0」的自相矛盾状态。
     * 后果：报表金额永久失真；「这一单当初是不是用券免的」这条审计线索
     * 被抹掉；收银员在 locate 与 submit 之间撞上一轮 Cron 时，
     * 提交会被 exceeds_portions 拦下来（客人正站在柜台前）。
     *
     * ★ 加新列时想清楚它属于哪一类：POS 直接给的（should/actual/tax…）
     *   放进两边都写的那份；要算的（份数、排除项、核销…）只放 computed 那份。
     */
    public function upsert(array $o, bool $computed = true): int
    {
        $now = $this->db->now();
        // POS 直接给的列 —— 两条路径都拿得到真值，都可以写
        $setRaw = [
            'order_head_id', 'check_ids', 'table_name', 'eat_type', 'customer_num',
            'order_end_time', 'business_date',
            'original_amount', 'should_amount', 'actual_amount', 'tax_amount',
        ];
        // 要读明细才算得出来的列 —— 只有 buildContext 有权写
        $setComputed = [
            'total_amount', 'excluded_amount',
            'portions_counted', 'portions_uncounted',
            'is_redeemed', 'redeem_amount',
        ];
        $cols = $computed ? array_merge($setRaw, $setComputed) : $setRaw;
        $updates = implode(",\n               ",
            array_map(static fn(string $c): string => "{$c} = VALUES({$c})", $cols));

        $this->db->exec(
            'INSERT INTO pos_order
               (store_code, serial_id, order_head_id, check_ids, table_name, eat_type,
                customer_num, order_end_time, business_date,
                original_amount, should_amount, actual_amount, tax_amount, total_amount,
                excluded_amount, portions_counted, portions_uncounted,
                is_redeemed, redeem_amount,
                created_at, updated_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE
               ' . $updates . ',
               updated_at         = VALUES(updated_at)',
            [
                $this->storeCode,
                $o['serial_id'],
                $o['order_head_id'],
                implode(',', $o['check_ids'] ?? []),
                $o['table_name'] ?? null,
                $o['eat_type'] ?? 0,
                $o['customer_num'] ?? null,
                $o['order_end_time'],
                $o['business_date'],
                Money::toStr($o['original_cents'] ?? 0),
                Money::toStr($o['should_cents'] ?? 0),
                Money::toStr($o['actual_cents'] ?? 0),
                Money::toStr($o['tax_cents'] ?? 0),
                Money::toStr($o['total_cents'] ?? 0),
                Money::toStr($o['excluded_cents'] ?? 0),
                $o['portions_counted'] ?? 0,
                $o['portions_uncounted'] ?? 0,
                !empty($o['is_redeemed']) ? 1 : 0,
                Money::toStr($o['redeem_cents'] ?? 0),
                $now, $now,
            ]
        );
        return (int)$this->db->value(
            'SELECT id FROM pos_order WHERE store_code = ? AND serial_id = ?',
            [$this->storeCode, $o['serial_id']]
        );
    }

    public function findBySerial(string $serialId): ?array
    {
        return $this->db->one(
            'SELECT * FROM pos_order WHERE store_code = ? AND serial_id = ?',
            [$this->storeCode, $serialId]
        );
    }

    /**
     * 取行锁。
     * ★ 用普通 FOR UPDATE，不用 SKIP LOCKED / NOWAIT
     *   —— 后者需 MySQL 8.0+ / MariaDB 10.6+，不满足双兼容要求（db/README.md §2.1）。
     * 必须在事务内调用。
     */
    public function lockBySerial(string $serialId): ?array
    {
        return $this->db->one(
            'SELECT * FROM pos_order WHERE store_code = ? AND serial_id = ? FOR UPDATE',
            [$this->storeCode, $serialId]
        );
    }

    /**
     * 分配后回写已分配金额/份数与状态。
     *
     * ── 🔴 CASE 里【不能再写 + ?】 ───────────────────────
     *
     * MySQL / MariaDB 的 UPDATE 赋值是【从左到右求值的，后面的表达式
     * 看到的是前面已经更新过的值】（这一点与标准 SQL 不同）。
     * 所以走到 CASE 时，allocated_amount 已经是加过本次的新值了。
     *
     * 原来写成 `WHEN allocated_amount + ? >= total_amount`，
     * 等于判「旧值 + 2×本次 >= 总额」—— 实测 100.00 的单：
     *
     *     分 25 → 1     分到 50 → 1
     *     分到 75 → 2   ← 还剩 25.00 就被标成「已全额分配」
     *
     * 四人 AA 的第二个人一提交，整单就被标成记完了。
     *
     * 当前影响有限（全仓库只有 /report/daily 读这一列，且只判 > 0），
     * 但任何后续代码只要按 alloc_status = 2 判「这单记完了」就会立刻出错。
     */
    public function applyAllocation(string $serialId, int $deltaCents, int $deltaPortions): void
    {
        $this->db->exec(
            'UPDATE pos_order
                SET allocated_amount   = allocated_amount   + ?,
                    allocated_portions = allocated_portions + ?,
                    alloc_status = CASE
                        WHEN allocated_amount >= total_amount AND total_amount > 0 THEN 2
                        WHEN allocated_amount > 0 THEN 1
                        ELSE 0 END,
                    updated_at = ?
              WHERE store_code = ? AND serial_id = ?',
            [
                Money::toStr($deltaCents), $deltaPortions,
                $this->db->now(), $this->storeCode, $serialId,
            ]
        );
    }

    public function markFreeMeal(string $serialId, bool $isFree): void
    {
        $this->db->exec(
            'UPDATE pos_order SET is_free_meal = ?, updated_at = ? WHERE store_code = ? AND serial_id = ?',
            [$isFree ? 1 : 0, $this->db->now(), $this->storeCode, $serialId]
        );
    }

    /** 值比对：更新核对状态 */
    public function markVerified(string $serialId, int $status): void
    {
        $this->db->exec(
            'UPDATE pos_order SET verify_status = ?, last_verified_at = ?, updated_at = ?
              WHERE store_code = ? AND serial_id = ?',
            [$status, $this->db->now(), $this->db->now(), $this->storeCode, $serialId]
        );
    }

    /**
     * 取保护期内待值比对的订单（已发过分的才需要比对）。
     * 命中 idx_verify。
     */
    public function pendingVerify(int $protectDays, int $limit, int $offset = 0): array
    {
        $limit  = max(1, min($limit, 100));
        $since  = date('Y-m-d H:i:s', strtotime("-{$protectDays} days"));
        return $this->db->all(
            // ★ 这里【故意不取 tax_amount】：值比对要的是「当下的」税额，
            //   由 reloadAmounts() 从主库回读（见 ReconcileService::verifyOne）。
            //   镜像里那一份是下单时的旧值，金额改过之后它一定是错的。
            'SELECT serial_id, order_head_id, check_ids, original_amount, should_amount,
                    actual_amount, total_amount, allocated_amount
               FROM pos_order
              WHERE store_code = ? AND verify_status = 0
                AND order_end_time >= ? AND allocated_amount > 0
              ORDER BY order_end_time ASC
              LIMIT ' . $limit . ' OFFSET ' . max(0, $offset),
            [$this->storeCode, $since]
        );
    }

    /** 数据完整性监控：本地某营业日的订单数 */
    public function countByBusinessDate(string $date): int
    {
        return (int)$this->db->value(
            'SELECT COUNT(*) FROM pos_order WHERE store_code = ? AND business_date = ?',
            [$this->storeCode, $date]
        );
    }
}
