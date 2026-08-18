<?php
declare(strict_types=1);

namespace Vip;

/**
 * POS 主库的全部读取入口 —— 除本类外，任何代码不得直接查主库。
 *
 * 每个方法都标注了命中的索引。改 SQL 前必须确认仍然走索引，
 * 否则会在营业高峰把 POS 拖垮。索引清单见 docs/01 §6。
 *
 * ★ 绝对不要按时间范围查 order_head（活动表）：
 *   该表只有 PRIMARY KEY(order_head_id,check_id) 和 KEY(table_id)，
 *   【没有任何时间索引】，按时间查必然全表扫。
 *   且已确认归档为「结账即写」，无需读活动表。
 */
final class PosReader implements PosSource
{
    /**
     * @param bool $detailFallback 历史明细读不到时是否回落读活单表 order_detail。
     *        【默认关闭】—— 实测该店 history_order_detail 是及时的
     *        （2026-08-17 23:27 结的账，明细当场就在），所以正常情况用不上。
     *        留着是给「归档确实延迟」的情况兜底，由 why.php ③bis 判定后再开。
     *        代价见 fetchDetail() 里的说明（活单表无 order_head_id 索引）。
     */
    public function __construct(private PosDb $db, private bool $detailFallback = false)
    {
    }

    /** 主库当前时间。时间基准必须取自主库（时钟/夏令时偏差） */
    public function now(): string
    {
        return $this->db->now();
    }

    /**
     * 订单定位：近 N 分钟 + 指定桌号 + 堂食。
     * 命中 idx_order_end_time。
     *
     * 不写 GROUP BY —— MySQL 5.5 的 GROUP BY 容易建临时表。
     * 取回最多 20 行后在 /app 侧用 PointsEngine::aggregateCandidates() 聚合。
     *
     * @return array<int,array<string,mixed>>
     */
    public function findRecentByTable(string $tableName, int $windowMinutes, int $limit = 20): array
    {
        $limit = max(1, min($limit, PosDb::MAX_LIMIT));
        $sql = "SELECT serial_id, order_head_id, check_id, table_name, eat_type,
                       customer_num, original_amount, should_amount, actual_amount,
                       tax_amount, order_end_time
                FROM history_order_head
                WHERE order_end_time >= NOW() - INTERVAL ? MINUTE
                  AND table_name = ?
                  AND eat_type = 0
                ORDER BY order_end_time DESC
                LIMIT {$limit}";
        return $this->db->select($sql, [$windowMinutes, $tableName], 'is');
    }

    /**
     * 按小票上的「Factura Simplificada」号取单。
     *
     * 实测（docs/01 §2.9）：小票印的 Factura Simplificada 就是 order_head_id，
     * 000092518 / 000092521 两张小票在库里都能精确命中。
     *
     * 相比按桌号查，这条路少了三个麻烦：不受 30 分钟窗口限制、
     * 不会撞上翻台、分单的多张 check 天然一次取全。
     * 代价也更低 —— 命中 idx_headcheck 是单点查（type=ref, rows=1），
     * 比按时间范围扫还便宜。
     *
     * ★ 这里【不过滤 eat_type】。既然收银员是照着小票输的号，
     *   外带单也该查得出来，再由 checkEligible 给出「外带不积分」的明确提示；
     *   直接返回「查无此单」会让人以为输错了号。
     */
    public function findByInvoice(int $orderHeadId, int $limit = 20): array
    {
        $limit = max(1, min($limit, PosDb::MAX_LIMIT));
        $sql = "SELECT serial_id, order_head_id, check_id, table_name, eat_type,
                       customer_num, original_amount, should_amount, actual_amount,
                       tax_amount, order_end_time
                FROM history_order_head
                WHERE order_head_id = ?
                ORDER BY check_id ASC
                LIMIT {$limit}";
        return $this->db->select($sql, [$orderHeadId], 'i');
    }

    /**
     * 增量补抓：水位线之后的订单。
     * 命中 idx_order_end_time。
     *
     * 用 >= 而非 >，靠 serial_id 在本地做幂等去重（避免同秒边界丢单）。
     */
    public function fetchSince(string $watermark, string $until, int $limit = 100, int $offset = 0): array
    {
        $limit  = max(1, min($limit, PosDb::MAX_LIMIT));
        $offset = max(0, $offset);
        $sql = "SELECT serial_id, order_head_id, check_id, table_name, eat_type,
                       customer_num, original_amount, should_amount, actual_amount,
                       tax_amount, order_end_time
                FROM history_order_head
                WHERE order_end_time >= ?
                  AND order_end_time <  ?
                ORDER BY order_end_time ASC, order_head_id ASC, check_id ASC
                LIMIT {$limit} OFFSET {$offset}";
        return $this->db->select($sql, [$watermark, $until], 'ss');
    }

    /**
     * 金额回读（值比对冲正）。
     * 命中 idx_headcheck，主键单点查，开销极小。
     *
     * 批量回读时逐条调用并在批次间停顿，不要拼 IN (...) 大列表。
     */
    public function reloadAmounts(int $orderHeadId, int $checkId): ?array
    {
        $sql = "SELECT serial_id, original_amount, should_amount, actual_amount, edit_time
                FROM history_order_head
                WHERE order_head_id = ? AND check_id = ?
                LIMIT 1";
        $r = $this->db->select($sql, [$orderHeadId, $checkId], 'ii');
        return $r[0] ?? null;
    }

    /**
     * 明细读取。命中 idx_detailcheck。
     *
     * ★ 每次积分都要调用（计次份数、不计分项扣除都要靠明细）。
     * ★ bit(1) 字段必须 +0 转换，否则 PHP 读到的是二进制字节而非 0/1。
     * ★ 三个价格字段都要取：actual_price=0 有两种含义，
     *   靠 product_price / original_price 区分「套餐内本来免费」与「被免的收费项」。
     * ★ actual_price 已是行小计，取回后【不要】再乘 quantity。
     *
     * 退菜行与套餐内 0 元菜品都在 /app 侧过滤，SQL 的索引条件保持最简。
     */
    public function fetchDetail(int $orderHeadId, int $checkId, int $limit = 100): array
    {
        $limit = max(1, min($limit, PosDb::MAX_LIMIT));
        // ★ 除菜品行外，还要取回 menu_item_id = -2 的【折扣伪行】：
        //   十送一核销在 POS 里就是加一条 `-2 / TARJETA 10+1 / 负金额` 的折扣行
        //   （实测订单 92293），不认它就会给核销餐重复发分计次。
        //   -2 行不参与任何金额累加，PointsEngine 只读它的名称做判定。
        //   过滤条件仍只作用于非索引列，索引命中不变（ref idx_detailcheck）。
        $pseudoDiscount = PointsEngine::PSEUDO_DISCOUNT;
        $sql = "SELECT menu_item_id, menu_item_name, quantity,
                       product_price, original_price, actual_price,
                       is_discount + 0    AS is_discount,
                       is_return_item + 0 AS is_return_item,
                       condiment_belong_item
                FROM history_order_detail
                WHERE order_head_id = ? AND check_id = ?
                  AND (menu_item_id > 0 OR menu_item_id = {$pseudoDiscount})
                  AND condiment_belong_item = 0
                LIMIT {$limit}";
        $rows = $this->db->select($sql, [$orderHeadId, $checkId], 'ii');
        if ($rows !== []) {
            return $rows;
        }

        /**
         * ★ 回落读活单表 order_detail。
         *
         * 用途：万一 POS 归档滞后，刚结账的单会「有头无明细」，份数恒为 0
         * （桌号查和小票查都一样，因为两条路读的是同一张表）。
         * 那种情况下明细还在活单表里，字段布局与历史表一致，直接可用。
         *
         * ★ 但【默认关闭】：实测该店归档是及时的 —— 2026-08-17 23:27 结的账，
         *   当天导出的 history_order_detail 里就有它的明细。
         *   先前一版曾据「明细落后 4 天」默认开启，那个判断来自被截断的导出，
         *   是错的。没有证据就不该往 POS 热路径上加全表扫。
         *
         * ⚠ 代价要说清楚：实测 order_detail 只有 PRIMARY(order_detail_id)，
         *   【没有 order_head_id 索引】，所以这是一次全表扫。
         *   之所以仍可接受：
         *     · 只在历史表查不到时才走（即只针对最近的单）；
         *     · 实测（2026-08-18 导出）活单明细表只有 2 行，远小于历史表；
         *     · 仍带 LIMIT，步长可控。
         *
         *   ⚠ 另一个不开的理由：活单表会留【孤儿行】。实测那 2 行属于
         *     order_head_id=54421，时间是 2025-07-14 —— 一年多前没归档干净的单。
         *     开启回落后，若正好查到这类订单，读到的是过期明细。
         *   若门店发现 POS 变慢，把 pos_detail_fallback 关掉即可，
         *   代价是最近的单份数显示 0（Pad 会提示「明细还没同步」）。
         */
        if (!$this->detailFallback) {
            return [];
        }
        $sqlLive = str_replace('FROM history_order_detail', 'FROM order_detail', $sql);
        try {
            return $this->db->select($sqlLive, [$orderHeadId, $checkId], 'ii');
        } catch (\Throwable $e) {
            // 活单表读不到不该影响主流程：金额仍按订单头算，份数交人工确认
            return [];
        }
    }

    /**
     * 多张 check 的明细合并读取（同一订单的分单）。
     * 逐张单点查，不拼 IN —— 保持索引命中且步长可控。
     */
    public function fetchDetailForChecks(int $orderHeadId, array $checkIds): array
    {
        $out = [];
        foreach ($checkIds as $cid) {
            foreach ($this->fetchDetail($orderHeadId, (int)$cid) as $row) {
                $out[] = $row;
            }
        }
        return $out;
    }

    /**
     * 菜单快照 —— 供本地缓存与规则表巡检。
     * 全表但行数少（数千），且每日仅刷新一次，放在 03:00-05:00 窗口执行。
     */
    public function fetchMenuItems(int $limit = 100, int $offset = 0): array
    {
        $limit  = max(1, min($limit, PosDb::MAX_LIMIT));
        $offset = max(0, $offset);
        $sql = "SELECT item_id, item_name1, price_1, major_group, family_group
                FROM menu_item
                ORDER BY item_id ASC
                LIMIT {$limit} OFFSET {$offset}";
        return $this->db->select($sql, []);
    }

    /** 分组名称字典（各几十行），启动时缓存 */
    public function fetchMajorGroups(): array
    {
        return $this->db->select(
            'SELECT major_group_id, major_group_name FROM major_group ORDER BY major_group_id LIMIT 100'
        );
    }

    public function fetchFamilyGroups(): array
    {
        return $this->db->select(
            'SELECT family_group_id, family_group_name FROM family_group ORDER BY family_group_id LIMIT 100'
        );
    }

    /**
     * 数据完整性监控：某营业日的订单数。
     * 命中 idx_order_end_time。
     *
     * 用于发现 history_order_head 的数据缺失 —— 实测 2024-08-12~18
     * 有约 6 天、478 个订单号、29,233.53 欧的记录整段丢失，
     * 而校准任务无法自愈这类缺口（数据本就不在主库里）。
     */
    public function countInRange(string $from, string $to): int
    {
        $r = $this->db->select(
            'SELECT COUNT(*) AS c FROM history_order_head
             WHERE order_end_time >= ? AND order_end_time < ? LIMIT 1',
            [$from, $to],
            'ss'
        );
        return (int)($r[0]['c'] ?? 0);
    }
}
