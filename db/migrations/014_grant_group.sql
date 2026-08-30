-- ============================================================
-- 014 · 多桌合并记账（同行分桌，积分并到一张卡）
--
-- 场景：一大帮人来吃饭，坐了三桌，分桌计费、最后一起结账，
--       然后自愿把三桌的积分都记到其中一位的卡上。
--
-- 这件事以前也做得到 —— 做三次独立记账就行。问题在于系统看不出
-- 这三笔有关系：撤销要撤三次、审计里是三条孤立记录、
-- 风控上也没法把它和「捡了三张别人的小票」区分开。
--
-- grant_group 就是给这三笔盖同一个戳：
--   · NULL       = 单桌记账（历史流水全是这个，不需要回填）
--   · 同一个值   = 同一次合并操作产出的几笔
--
-- ★ 只加一个可空列和一个索引，不动任何现有数据。
--   老流水 grant_group 为 NULL，语义上正好就是「单桌」，天然正确。
--
-- 索引用来两件事：按组撤销、以及风控里数「这张卡这个餐期记了几次」
-- （一次合并算一次，不是算三次 —— 见 docs/03 §12）。
-- ============================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'point_ledger'
       AND COLUMN_NAME = 'grant_group') > 0,
  'DO 0',
  'ALTER TABLE `point_ledger` ADD COLUMN `grant_group` CHAR(16) DEFAULT NULL COMMENT ''同一次多桌合并记账的几笔共用一个值；NULL=单桌'' AFTER `alloc_detail`');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'point_ledger'
       AND INDEX_NAME = 'idx_group') > 0,
  'DO 0',
  'ALTER TABLE `point_ledger` ADD KEY `idx_group` (`store_code`,`grant_group`)');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
