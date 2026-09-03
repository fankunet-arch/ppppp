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
                // 不用 mb_substr：缺 mbstring 时会直接抛 Error 而不是少截几个字。
                // 500 是列宽上限，按字节截断即可（utf8mb4 下最多浪费几个字节）。
                function_exists('mb_substr') ? mb_substr($message, 0, 500) : substr($message, 0, 500),
                isset($opt['detail']) ? json_encode($opt['detail'], JSON_UNESCAPED_UNICODE) : null,
                $this->db->now(),
            ]
        );
        return $this->db->lastInsertId();
    }

    /** 同一目标同一类型已有未处理告警时不重复插入，避免刷屏 */
    /**
     * 同一件事只推一条 —— 但「同一件事」的定义在 refId 里，务必写全。
     *
     * ── 🔴 refId 粒度不对 = 后面的损失全被吞掉（审计 F13） ──────
     *
     * 去重键是 (alert_type, ref_type, ref_id, status=0)。
     * 「白送了一顿饭」这类告警原来只按【会员】去重：
     *
     *     raiseOnce('reward_on_shrunk_order', 'member', $memberId, …)
     *
     * 于是同一位常客身上第二次、第三次发生同样的事，
     * 只要第一条还没被处理掉（status 仍是 0），后面的一条都不推。
     * 而每一条都是实打实的一顿免费餐 —— 越是反复出问题的那位客人，
     * 越会被吞得干净。
     *
     * ★ 规则：refId 要能唯一标识【这一次事故】，不是【这个人】。
     *   跟订单有关的一律带上 serial_id，跟流水有关的带上流水号。
     *   「这个人今天记太多次了」那种确实该按人去重的，才只写人。
     */
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
