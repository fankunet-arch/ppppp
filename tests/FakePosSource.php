<?php
declare(strict_types=1);

namespace Vip\Test;

use Vip\PosSource;

/**
 * POS 主库的假实现 —— 让完整业务流程能在没有门店内网的环境下跑通。
 *
 * 返回的数据形态严格照搬真实导出：
 *   · 金额是 DECIMAL(11,2) 读出来的【字符串】
 *   · actual_price 是【行小计】（已含 quantity）
 *   · 明细含 -3 备注行、-4 支付行、配料行、退菜行等需过滤的伪行
 *   · bit(1) 字段已按 PosReader 的写法转成 0/1
 */
final class FakePosSource implements PosSource
{
    /** @var array<int,array> history_order_head 形态的行 */
    public array $heads = [];
    /** @var array<string,array> key = "orderHeadId:checkId" */
    public array $details = [];
    public string $now = '2026-08-13 23:30:00';

    public function now(): string
    {
        return $this->now;
    }

    public function findRecentByTable(string $tableName, int $windowMinutes, int $limit = 20): array
    {
        $cut = date('Y-m-d H:i:s', strtotime($this->now) - $windowMinutes * 60);
        $out = [];
        foreach ($this->heads as $h) {
            if ((string)$h['table_name'] !== $tableName) {
                continue;
            }
            if ((int)$h['eat_type'] !== 0) {
                continue;
            }
            if ((string)$h['order_end_time'] < $cut) {
                continue;
            }
            $out[] = $h;
            if (count($out) >= $limit) {
                break;
            }
        }
        usort($out, static fn($a, $b) => strcmp((string)$b['order_end_time'], (string)$a['order_end_time']));
        return $out;
    }

    /**
     * 按 Factura Simplificada 号（= order_head_id）取单。
     * 与 PosReader 一致：【不过滤 eat_type】，外带单也要能查出来，
     * 再由 checkEligible 给出「外带不积分」的明确提示。
     */
    public function findByInvoice(int $orderHeadId, int $limit = 20): array
    {
        $out = [];
        foreach ($this->heads as $h) {
            if ((int)$h['order_head_id'] !== $orderHeadId) {
                continue;
            }
            $out[] = $h;
            if (count($out) >= $limit) {
                break;
            }
        }
        usort($out, static fn($a, $b) => (int)$a['check_id'] <=> (int)$b['check_id']);
        return $out;
    }

    public function fetchSince(string $watermark, string $until, int $limit = 100, int $offset = 0): array
    {
        $out = [];
        foreach ($this->heads as $h) {
            $t = (string)$h['order_end_time'];
            if ($t >= $watermark && $t < $until) {
                $out[] = $h;
            }
        }
        usort($out, static fn($a, $b) => strcmp((string)$a['order_end_time'], (string)$b['order_end_time']));
        return array_slice($out, $offset, $limit);
    }

    public function reloadAmounts(int $orderHeadId, int $checkId): ?array
    {
        foreach ($this->heads as $h) {
            if ((int)$h['order_head_id'] === $orderHeadId && (int)$h['check_id'] === $checkId) {
                return [
                    'serial_id'       => $h['serial_id'],
                    'original_amount' => $h['original_amount'],
                    'should_amount'   => $h['should_amount'],
                    'actual_amount'   => $h['actual_amount'],
                    'edit_time'       => $h['edit_time'] ?? null,
                ];
            }
        }
        return null;
    }

    public function fetchDetail(int $orderHeadId, int $checkId, int $limit = 100): array
    {
        $rows = $this->details["{$orderHeadId}:{$checkId}"] ?? [];
        /**
         * 照搬 PosReader 的 SQL 层过滤，必须【逐字对齐】：
         *   AND (menu_item_id > 0 OR menu_item_id = -2)
         *   AND condiment_belong_item = 0
         *
         * ★ -2 折扣伪行必须保留 —— 十送一核销（TARJETA 10+1）就是这么一行，
         *   PointsEngine 靠读它的名称来判定核销。
         *   这里若按 menu_item_id <= 0 一刀切，假对象就比真实现少喂一类行：
         *   核销识别在冒烟测试里【永远走不到】，连「纸质券不得被误判成核销」
         *   这种断言都会因为行被丢掉而假通过 —— 比没有测试更糟。
         */
        $out = [];
        foreach ($rows as $r) {
            $mid = (int)$r['menu_item_id'];
            if ($mid <= 0 && $mid !== \Vip\PointsEngine::PSEUDO_DISCOUNT) {
                continue;
            }
            if ((int)($r['condiment_belong_item'] ?? 0) !== 0) {
                continue;
            }
            $out[] = $r;
            if (count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }

    public function fetchDetailForChecks(int $orderHeadId, array $checkIds): array
    {
        $out = [];
        foreach ($checkIds as $cid) {
            foreach ($this->fetchDetail($orderHeadId, (int)$cid) as $r) {
                $out[] = $r;
            }
        }
        return $out;
    }

    public function fetchMenuItems(int $limit = 100, int $offset = 0): array
    {
        return [];
    }

    public function fetchMajorGroups(): array
    {
        return [
            ['major_group_id' => 1, 'major_group_name' => 'Comida'],
            ['major_group_id' => 2, 'major_group_name' => 'Bebida'],
            ['major_group_id' => 3, 'major_group_name' => 'Menú'],
            ['major_group_id' => 4, 'major_group_name' => 'Postres'],
        ];
    }

    public function fetchFamilyGroups(): array
    {
        return [['family_group_id' => 8, 'family_group_name' => 'Menus']];
    }

    public function countInRange(string $from, string $to): int
    {
        $n = 0;
        foreach ($this->heads as $h) {
            $t = (string)$h['order_end_time'];
            if ($t >= $from && $t < $to) {
                $n++;
            }
        }
        return $n;
    }

    // ── 构造夹具的便捷方法 ────────────────────────────────

    public function addHead(array $h): void
    {
        $this->heads[] = $h + ['eat_type' => 0, 'check_id' => 1, 'customer_num' => 2];
    }

    public function addDetail(int $orderHeadId, int $checkId, array $rows): void
    {
        $this->details["{$orderHeadId}:{$checkId}"] = $rows;
    }

    /** 明细行的标准形态，字段默认值与真实导出一致 */
    public static function line(
        int $menuItemId,
        string $name,
        string $productPrice,
        string $actualPrice,
        int $qty = 1,
        ?string $originalPrice = null,
        int $isReturn = 0,
        int $condimentBelong = 0
    ): array {
        return [
            'menu_item_id'          => $menuItemId,
            'menu_item_name'        => $name,
            'quantity'              => $qty,
            'product_price'         => $productPrice,
            'original_price'        => $originalPrice,
            'actual_price'          => $actualPrice,
            'is_discount'           => 0,
            'is_return_item'        => $isReturn,
            'condiment_belong_item' => $condimentBelong,
        ];
    }
}
