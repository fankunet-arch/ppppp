<?php
declare(strict_types=1);

namespace Vip\Repo;

use Vip\LocalDb;

/** 告警队列（巡检产出，供后台复核） */
final class AlertRepo
{
    public function __construct(private LocalDb $db, private string $storeCode)
    {
    }

    public function raise(string $type, string $message, array $opt = []): int
    {
        $this->db->exec(
            'INSERT INTO alert (store_code, alert_type, severity, ref_type, ref_id, message, detail, created_at)
             VALUES (?,?,?,?,?,?,?,?)',
            [
                $this->storeCode, $type, $opt['severity'] ?? 1,
                $opt['ref_type'] ?? null, $opt['ref_id'] ?? null,
                mb_substr($message, 0, 500),
                isset($opt['detail']) ? json_encode($opt['detail'], JSON_UNESCAPED_UNICODE) : null,
                $this->db->now(),
            ]
        );
        return $this->db->lastInsertId();
    }

    /** 同一目标同一类型已有未处理告警时不重复插入，避免刷屏 */
    public function raiseOnce(string $type, string $refType, string $refId, string $message, array $opt = []): void
    {
        $exists = $this->db->value(
            'SELECT 1 FROM alert
              WHERE store_code = ? AND alert_type = ? AND ref_type = ? AND ref_id = ? AND status = 0
              LIMIT 1',
            [$this->storeCode, $type, $refType, $refId]
        );
        if (!$exists) {
            $this->raise($type, $message, $opt + ['ref_type' => $refType, 'ref_id' => $refId]);
        }
    }

    public function open(int $limit = 50): array
    {
        return $this->db->all(
            'SELECT * FROM alert WHERE store_code = ? AND status = 0
              ORDER BY severity DESC, id DESC LIMIT ' . max(1, min($limit, 100)),
            [$this->storeCode]
        );
    }
}
