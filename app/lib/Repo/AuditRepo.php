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
}
