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
     * 落库或更新订单镜像（增量补抓与积分前都会调用）。
     * 已存在时只刷新金额快照与份数，不动分配状态。
     */
    public function upsert(array $o): int
    {
        $now = $this->db->now();
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
               order_head_id      = VALUES(order_head_id),
               check_ids          = VALUES(check_ids),
               table_name         = VALUES(table_name),
               eat_type           = VALUES(eat_type),
               customer_num       = VALUES(customer_num),
               order_end_time     = VALUES(order_end_time),
               business_date      = VALUES(business_date),
               original_amount    = VALUES(original_amount),
               should_amount      = VALUES(should_amount),
               actual_amount      = VALUES(actual_amount),
               tax_amount         = VALUES(tax_amount),
               total_amount       = VALUES(total_amount),
               excluded_amount    = VALUES(excluded_amount),
               portions_counted   = VALUES(portions_counted),
               portions_uncounted = VALUES(portions_uncounted),
               is_redeemed        = VALUES(is_redeemed),
               redeem_amount      = VALUES(redeem_amount),
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

    /** 分配后回写已分配金额/份数与状态 */
    public function applyAllocation(string $serialId, int $deltaCents, int $deltaPortions): void
    {
        $this->db->exec(
            'UPDATE pos_order
                SET allocated_amount   = allocated_amount   + ?,
                    allocated_portions = allocated_portions + ?,
                    alloc_status = CASE
                        WHEN allocated_amount + ? >= total_amount AND total_amount > 0 THEN 2
                        WHEN allocated_amount + ? > 0 THEN 1
                        ELSE 0 END,
                    updated_at = ?
              WHERE store_code = ? AND serial_id = ?',
            [
                Money::toStr($deltaCents), $deltaPortions,
                Money::toStr($deltaCents), Money::toStr($deltaCents),
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
