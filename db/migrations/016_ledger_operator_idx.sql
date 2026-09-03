-- ============================================================
-- 016 · 手工录入按操作员查询要有自己的索引
--
-- 两处都在按 (操作员, 时间) 过滤 point_ledger，而表上没有这个索引：
--   · manualAmountSince() —— 手工录入的【日累计金额上限】
--   · manualCountSince()  —— 同一员工单日笔数告警
--
-- 加索引不只是快慢的事（审计 F14）：
--
-- 日额度是「读一下再写」。原来那次读在事务【外面】，四台 Pad 同时提交
-- 各自读到「今天用了 0」、各自放行、各自写进去 —— 上限 € 300 被撑成 € 600。
-- 修法是把这次读挪进事务并加 FOR UPDATE，让它对【这个操作员今天这段区间】
-- 上区间锁，后来的 INSERT 就得排队。
--
-- 而 FOR UPDATE 锁住的是「扫过的那些行」：没有索引就是全表扫，
-- 等于把整张流水表锁住 —— 一个手工录入把全店记账都堵住。
-- 有了这个索引，锁的范围恰好就是「这个操作员今天的手工流水」。
--
-- ★ 不加操作员那一行的行锁（本来的第一版方案）：那要求 operator 表里
--   确实有这一行才锁得住，而「碰巧存在」不是可以拿来守钱的性质 ——
--   冒烟测试里那个合成操作员没有对应行，上限当场被撑破，
--   而代码看上去完全正确。
-- ============================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'point_ledger'
       AND INDEX_NAME = 'idx_operator') > 0,
  'DO 0',
  'ALTER TABLE `point_ledger` ADD KEY `idx_operator` (`store_code`,`operator_id`,`created_at`)');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
