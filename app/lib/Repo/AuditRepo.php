<?php
declare(strict_types=1);

namespace Vip\Repo;

use Vip\LocalDb;

/** 操作审计。GDPR/LOPDGDD 的权利行使记录也记这里。 */
final class AuditRepo
{
    public function __construct(private LocalDb $db, private string $storeCode)
    {
    }

    public function log(string $action, array $opt = []): void
    {
        $this->db->exec(
            'INSERT INTO audit_log
               (store_code, action, target_type, target_id, operator_id, operator_name, device, detail, created_at)
             VALUES (?,?,?,?,?,?,?,?,?)',
            [
                $this->storeCode, $action,
                $opt['target_type'] ?? null, $opt['target_id'] ?? null,
                $opt['operator_id'] ?? null, $opt['operator_name'] ?? null,
                $opt['device'] ?? null,
                isset($opt['detail']) ? json_encode($opt['detail'], JSON_UNESCAPED_UNICODE) : null,
                $this->db->now(),
            ]
        );
    }

    /**
     * 某个操作员最近 N 分钟内做了多少次某个动作。
     *
     * 给「有人在枚举小票号」这类判断用：单看一次查不到什么都说明不了，
     * 短时间内连着查不到十几次才是信号。
     */
    public function countRecent(string $action, ?int $operatorId, int $minutes): int
    {
        $since = date('Y-m-d H:i:s', strtotime($this->db->now()) - max(1, $minutes) * 60);
        if ($operatorId === null) {
            return (int)$this->db->value(
                'SELECT COUNT(*) FROM audit_log
                  WHERE store_code = ? AND action = ? AND created_at >= ?',
                [$this->storeCode, $action, $since]
            );
        }
        return (int)$this->db->value(
            'SELECT COUNT(*) FROM audit_log
              WHERE store_code = ? AND action = ? AND operator_id = ? AND created_at >= ?',
            [$this->storeCode, $action, $operatorId, $since]
        );
    }
}
