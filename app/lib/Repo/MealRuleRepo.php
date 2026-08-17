<?php
declare(strict_types=1);

namespace Vip\Repo;

use Vip\LocalDb;
use Vip\MealRules;

/** meal_item_rule → MealRules */
final class MealRuleRepo
{
    public function __construct(private LocalDb $db, private string $storeCode)
    {
    }

    public function load(): MealRules
    {
        return new MealRules($this->db->all(
            'SELECT menu_item_id, item_name, is_meal_fee, counts_visit, earns_points
               FROM meal_item_rule WHERE store_code = ? AND enabled = 1',
            [$this->storeCode]
        ));
    }

    public function all(): array
    {
        return $this->db->all(
            'SELECT * FROM meal_item_rule WHERE store_code = ? ORDER BY ref_price DESC, menu_item_id ASC',
            [$this->storeCode]
        );
    }

    public function upsert(array $r): void
    {
        $this->db->exec(
            'INSERT INTO meal_item_rule
               (store_code, menu_item_id, item_name, ref_price,
                is_meal_fee, counts_visit, earns_points, enabled, updated_at, updated_by)
             VALUES (?,?,?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE
               item_name = VALUES(item_name), ref_price = VALUES(ref_price),
               is_meal_fee = VALUES(is_meal_fee), counts_visit = VALUES(counts_visit),
               earns_points = VALUES(earns_points), enabled = VALUES(enabled),
               updated_at = VALUES(updated_at), updated_by = VALUES(updated_by)',
            [
                $this->storeCode, $r['menu_item_id'], $r['item_name'] ?? null, $r['ref_price'] ?? null,
                (int)($r['is_meal_fee'] ?? 0), (int)($r['counts_visit'] ?? 0), (int)($r['earns_points'] ?? 1),
                (int)($r['enabled'] ?? 1), $this->db->now(), $r['updated_by'] ?? null,
            ]
        );
    }
}
