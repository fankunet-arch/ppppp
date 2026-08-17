<?php
declare(strict_types=1);

namespace Vip;

/**
 * POS 主库读取契约。
 *
 * 抽成接口有两个目的：
 *   1. 冒烟测试可以注入假实现，不依赖门店内网的 POS 可达；
 *   2. 明确「能对主库做哪些事」—— 全部是读，没有任何写方法。
 *
 * 唯一的生产实现是 PosReader。
 */
interface PosSource
{
    /** 主库当前时间。时间基准必须取自主库（时钟/夏令时偏差） */
    public function now(): string;

    /** 近 N 分钟 + 指定桌号 + 堂食。命中 idx_order_end_time */
    public function findRecentByTable(string $tableName, int $windowMinutes, int $limit = 20): array;

    /**
     * 按小票上的「Factura Simplificada」号取单 = order_head_id。
     * 命中 idx_headcheck，单点查，是最省 POS 的一种查法。
     */
    public function findByInvoice(int $orderHeadId, int $limit = 20): array;

    /** 水位线之后的订单，增量补抓用。命中 idx_order_end_time */
    public function fetchSince(string $watermark, string $until, int $limit = 100, int $offset = 0): array;

    /** 金额回读（值比对冲正）。命中 idx_headcheck */
    public function reloadAmounts(int $orderHeadId, int $checkId): ?array;

    /** 单张 check 的明细。命中 idx_detailcheck */
    public function fetchDetail(int $orderHeadId, int $checkId, int $limit = 100): array;

    /** 同一订单多张 check 的明细合并 */
    public function fetchDetailForChecks(int $orderHeadId, array $checkIds): array;

    /** 菜单快照，供本地缓存与规则表巡检 */
    public function fetchMenuItems(int $limit = 100, int $offset = 0): array;

    public function fetchMajorGroups(): array;

    public function fetchFamilyGroups(): array;

    /** 某时间区间的订单数，数据完整性监控用 */
    public function countInRange(string $from, string $to): int;
}
