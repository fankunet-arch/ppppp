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
                alloc_mode, alloc_detail, status, reverses_id,
                source, manual_reason, review_status, approved_by,
                operator_id, operator_name, device, reason, created_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
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
                $e['status'] ?? self::S_ACTIVE,
                $e['reverses_id'] ?? null,
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
    public function manualCountToday(int $operatorId): int
    {
        return (int)$this->db->value(
            'SELECT COUNT(*) FROM point_ledger
              WHERE store_code = ? AND source = ? AND operator_id = ?
                AND created_at >= ?',
            [$this->storeCode, self::SRC_MANUAL, $operatorId, date('Y-m-d 00:00:00')]
        );
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
