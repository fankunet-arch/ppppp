<?php
declare(strict_types=1);

namespace Vip\Repo;

use Vip\LocalDb;

/** sys_config 的读写与类型转换 */
final class ConfigRepo
{
    private ?array $cache = null;

    public function __construct(private LocalDb $db, private string $storeCode)
    {
    }

    /** @return array<string,string> */
    public function all(): array
    {
        if ($this->cache === null) {
            $rows = $this->db->all(
                'SELECT config_key, config_value FROM sys_config WHERE store_code = ?',
                [$this->storeCode]
            );
            $this->cache = [];
            foreach ($rows as $r) {
                $this->cache[$r['config_key']] = (string)$r['config_value'];
            }
        }
        return $this->cache;
    }

    public function get(string $key, string $default = ''): string
    {
        return $this->all()[$key] ?? $default;
    }

    public function int(string $key, int $default = 0): int
    {
        $v = $this->get($key, '');
        return $v === '' ? $default : (int)$v;
    }

    public function float(string $key, float $default = 0.0): float
    {
        $v = $this->get($key, '');
        return $v === '' ? $default : (float)$v;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $v = $this->get($key, '');
        return $v === '' ? $default : in_array($v, ['1', 'true', 'yes', 'on'], true);
    }

    public function set(string $key, string $value): void
    {
        $this->db->exec(
            'INSERT INTO sys_config (store_code, config_key, config_value, updated_at)
             VALUES (?,?,?,?)
             ON DUPLICATE KEY UPDATE config_value = VALUES(config_value), updated_at = VALUES(updated_at)',
            [$this->storeCode, $key, $value, $this->db->now()]
        );
        $this->cache = null;
    }
}
