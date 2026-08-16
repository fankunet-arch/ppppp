<?php
declare(strict_types=1);

namespace Vip;

/**
 * 套餐规则表（meal_item_rule）的内存视图。
 *
 * 三个开关互相独立，见 docs/04-本地库Schema.md §6.2：
 *   is_meal_fee   是否算「餐费项」→ 免费餐兜底判据用
 *   counts_visit  是否参与十送一计次
 *   earns_points  金额是否计入积分基数
 *
 * ★ 未被规则表覆盖的菜品，按【安全默认值】处理：
 *      is_meal_fee = false, counts_visit = false, earns_points = true
 *   即：正常积分、不计次、不参与免费餐判据。
 *   漏配一个菜品的后果仅是少计次，不会算错金额、不会误报免费餐。
 */
final class MealRules
{
    /** @var array<int,array{is_meal_fee:bool,counts_visit:bool,earns_points:bool,item_name:?string}> */
    private array $byItemId;

    /** @param array<int,array> $rows meal_item_rule 的行（enabled=1） */
    public function __construct(array $rows = [])
    {
        $this->byItemId = [];
        foreach ($rows as $r) {
            $id = (int)($r['menu_item_id'] ?? 0);
            if ($id === 0) {
                continue;
            }
            $this->byItemId[$id] = [
                'is_meal_fee'  => (bool)(int)($r['is_meal_fee']  ?? 0),
                'counts_visit' => (bool)(int)($r['counts_visit'] ?? 0),
                'earns_points' => (bool)(int)($r['earns_points'] ?? 1),
                'item_name'    => $r['item_name'] ?? null,
            ];
        }
    }

    /** 该菜品是否算餐费项（默认否） */
    public function isMealFee(int $menuItemId): bool
    {
        return $this->byItemId[$menuItemId]['is_meal_fee'] ?? false;
    }

    /** 该菜品是否参与十送一计次（默认否） */
    public function countsVisit(int $menuItemId): bool
    {
        return $this->byItemId[$menuItemId]['counts_visit'] ?? false;
    }

    /** 该菜品金额是否计入积分基数（默认【是】—— 漏配不能少给客人钱） */
    public function earnsPoints(int $menuItemId): bool
    {
        return $this->byItemId[$menuItemId]['earns_points'] ?? true;
    }

    public function isKnown(int $menuItemId): bool
    {
        return isset($this->byItemId[$menuItemId]);
    }

    /** @return int[] 已配置的 menu_item_id 列表，供巡检比对 */
    public function knownItemIds(): array
    {
        return array_keys($this->byItemId);
    }

    public function count(): int
    {
        return count($this->byItemId);
    }
}
