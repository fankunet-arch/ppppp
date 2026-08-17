<?php
declare(strict_types=1);

namespace Vip\Service;

use Vip\LocalDb;
use Vip\Repo\AuditRepo;

/**
 * 操作员登录与会话。
 *
 * 设计取舍：
 *   · 不从 POS 的 employee 表取操作员 —— 那会让登录依赖主库可达，
 *     主库抖动时收银员将无法登录，与「不阻塞收银流程」冲突。
 *   · PIN 用 password_hash() 存，绝不存明文。
 *   · 令牌明文只出现在 httpOnly Cookie 中，库里存 SHA-256。
 *   · 连续失败锁定，防止 4 位 PIN 被暴力枚举。
 */
final class AuthService
{
    public const ROLE_STAFF   = 1;
    public const ROLE_MANAGER = 2;
    public const ROLE_ADMIN   = 3;

    private const MAX_FAILED   = 5;
    private const LOCK_MINUTES = 15;
    private const TTL_HOURS    = 12;   // 覆盖一个整班次

    public function __construct(
        private LocalDb $db,
        private string $storeCode,
        private AuditRepo $audit,
    ) {
    }

    /**
     * @return array{ok:bool,error?:string,token?:string,operator?:array}
     */
    public function login(string $loginName, string $pin, ?string $device, ?string $ip): array
    {
        $op = $this->db->one(
            'SELECT * FROM operator WHERE store_code = ? AND login_name = ? AND enabled = 1',
            [$this->storeCode, $loginName]
        );

        // 用户不存在时也走一次 hash 校验，避免通过响应时间探测账号是否存在
        if ($op === null) {
            password_verify($pin, '$2y$10$usesomesillystringfor.HashingToEqualizeTiming00000000000');
            return ['ok' => false, 'error' => 'invalid_credentials'];
        }

        if ($op['locked_until'] !== null && strtotime((string)$op['locked_until']) > time()) {
            return ['ok' => false, 'error' => 'locked', 'detail' => ['until' => $op['locked_until']]];
        }

        if (!password_verify($pin, (string)$op['pin_hash'])) {
            $failed = (int)$op['failed_count'] + 1;
            $lock   = $failed >= self::MAX_FAILED
                ? date('Y-m-d H:i:s', time() + self::LOCK_MINUTES * 60)
                : null;
            $this->db->exec(
                'UPDATE operator SET failed_count = ?, locked_until = ?, updated_at = ? WHERE id = ?',
                [$failed, $lock, $this->db->now(), $op['id']]
            );
            return ['ok' => false, 'error' => $lock ? 'locked' : 'invalid_credentials'];
        }

        // 登录成功 —— 清零失败计数
        $this->db->exec(
            'UPDATE operator SET failed_count = 0, locked_until = NULL, last_login_at = ?, updated_at = ? WHERE id = ?',
            [$this->db->now(), $this->db->now(), $op['id']]
        );

        $token = bin2hex(random_bytes(32));
        $this->db->exec(
            'INSERT INTO operator_session
               (store_code, token_hash, operator_id, device, ip, expires_at, created_at, last_seen_at)
             VALUES (?,?,?,?,?,?,?,?)',
            [
                $this->storeCode, hash('sha256', $token), $op['id'], $device, $ip,
                date('Y-m-d H:i:s', time() + self::TTL_HOURS * 3600),
                $this->db->now(), $this->db->now(),
            ]
        );

        $this->audit->log('operator_login', [
            'target_type' => 'operator', 'target_id' => (string)$op['id'],
            'operator_id' => (int)$op['id'], 'operator_name' => (string)$op['display_name'],
            'device' => $device,
        ]);

        return ['ok' => true, 'token' => $token, 'operator' => $this->publicView($op, $device)];
    }

    /** 校验令牌，顺带滑动续期 last_seen_at */
    public function resolve(?string $token): ?array
    {
        if ($token === null || $token === '') {
            return null;
        }
        $s = $this->db->one(
            'SELECT s.*, o.display_name, o.role, o.enabled
               FROM operator_session s
               JOIN operator o ON o.id = s.operator_id AND o.store_code = s.store_code
              WHERE s.store_code = ? AND s.token_hash = ?',
            [$this->storeCode, hash('sha256', $token)]
        );
        if ($s === null || $s['revoked_at'] !== null || (int)$s['enabled'] !== 1) {
            return null;
        }
        if (strtotime((string)$s['expires_at']) <= time()) {
            return null;
        }
        $this->db->exec(
            'UPDATE operator_session SET last_seen_at = ? WHERE id = ?',
            [$this->db->now(), $s['id']]
        );
        return [
            'id'         => (int)$s['operator_id'],
            'name'       => (string)$s['display_name'],
            'role'       => (int)$s['role'],
            'is_manager' => (int)$s['role'] >= self::ROLE_MANAGER,
            'device'     => $s['device'],
            'session_id' => (int)$s['id'],
        ];
    }

    public function logout(?string $token): void
    {
        if ($token === null || $token === '') {
            return;
        }
        $this->db->exec(
            'UPDATE operator_session SET revoked_at = ? WHERE store_code = ? AND token_hash = ?',
            [$this->db->now(), $this->storeCode, hash('sha256', $token)]
        );
    }

    /** 建操作员（CP 后台与初始化脚本用） */
    public function createOperator(string $loginName, string $displayName, string $pin, int $role): int
    {
        if (strlen($pin) < 4) {
            throw new \InvalidArgumentException('PIN 至少 4 位');
        }
        $this->db->exec(
            'INSERT INTO operator
               (store_code, login_name, display_name, pin_hash, role, created_at, updated_at)
             VALUES (?,?,?,?,?,?,?)',
            [$this->storeCode, $loginName, $displayName,
             password_hash($pin, PASSWORD_DEFAULT), $role, $this->db->now(), $this->db->now()]
        );
        return $this->db->lastInsertId();
    }

    /** 清理过期会话（随日常校准执行） */
    public function purgeExpired(): int
    {
        return $this->db->exec(
            'DELETE FROM operator_session WHERE store_code = ? AND expires_at < ?',
            [$this->storeCode, date('Y-m-d H:i:s', time() - 86400)]
        );
    }

    private function publicView(array $op, ?string $device): array
    {
        return [
            'id'         => (int)$op['id'],
            'name'       => (string)$op['display_name'],
            'role'       => (int)$op['role'],
            'is_manager' => (int)$op['role'] >= self::ROLE_MANAGER,
            'device'     => $device,
        ];
    }
}
