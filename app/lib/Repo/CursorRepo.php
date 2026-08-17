<?php
declare(strict_types=1);

namespace Vip\Repo;

use Vip\LocalDb;

/** 同步水位线。只在整批成功落库后才前移。 */
final class CursorRepo
{
    public function __construct(private LocalDb $db, private string $storeCode)
    {
    }

    public function get(string $name, string $default): string
    {
        $v = $this->db->value(
            'SELECT watermark FROM sync_cursor WHERE store_code = ? AND cursor_name = ?',
            [$this->storeCode, $name]
        );
        if ($v === null) {
            $this->db->exec(
                'INSERT INTO sync_cursor (store_code, cursor_name, watermark) VALUES (?,?,?)',
                [$this->storeCode, $name, $default]
            );
            return $default;
        }
        return (string)$v;
    }

    /** @param string $watermark 必须取自主库返回的 order_end_time，不用本地时间 */
    public function advance(string $name, string $watermark, int $rows, int $status = 1, ?string $err = null): void
    {
        $this->db->exec(
            'UPDATE sync_cursor
                SET watermark = ?, last_run_at = ?, last_status = ?, last_error = ?,
                    rows_processed = rows_processed + ?
              WHERE store_code = ? AND cursor_name = ?',
            [$watermark, $this->db->now(), $status, $err, $rows, $this->storeCode, $name]
        );
    }

    public function touch(string $name, int $status, ?string $err = null): void
    {
        $this->db->exec(
            'UPDATE sync_cursor SET last_run_at = ?, last_status = ?, last_error = ?
              WHERE store_code = ? AND cursor_name = ?',
            [$this->db->now(), $status, $err, $this->storeCode, $name]
        );
    }

    public function all(): array
    {
        return $this->db->all('SELECT * FROM sync_cursor WHERE store_code = ?', [$this->storeCode]);
    }
}
