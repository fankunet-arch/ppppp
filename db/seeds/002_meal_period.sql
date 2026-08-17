-- ============================================================
-- 餐期配置 —— 对应 docs/04-本地库Schema.md §6.3
--
-- 实测营业时段（按 order_end_time 小时分布，88,616 行）：
--   午市 13:00–17:00，峰值 16 点 22,875 单
--   晚市 20:00–01:00，峰值 22 点 14,612 单
--   02:00–10:00 全时段 0 单
-- 配置值留有余量。
--
-- cross_midnight = 1 的餐期，其 end_time 属次日；
-- 订单归属于【餐期起始日】。
-- ============================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET @store := 'S001';

DELETE FROM `meal_period` WHERE `store_code` = @store;

INSERT INTO `meal_period`
  (`store_code`,`period_name`,`start_time`,`end_time`,`cross_midnight`,`sort_order`) VALUES
(@store, '白天', '11:00:00', '18:00:00', 0, 1),
(@store, '晚上', '19:30:00', '02:00:00', 1, 2);
