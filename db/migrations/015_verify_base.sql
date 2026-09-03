-- ============================================================
-- 015 · 值比对的【基准】要有自己的列
--
-- ── 问题（审计 F2 · P0，实测复现） ─────────────────────
--
-- 「发分时的金额快照」和「主库当前金额的镜像」原来是同一组列
-- （should_amount / actual_amount）。而这组列有两条写入路径：
--
--   · PointsService::buildContext()  —— 收银员每次 locate 都写
--   · SyncService::storeOrder()      —— 每轮同步都写
--
-- 于是只要在发分之后【再 locate 一次】（或等一轮同步），
-- 镜像就被刷成 POS 的当前值，而值比对拿来当基准的正是这组列 ——
-- 它在拿新值跟新值比，永远相等，永远判「一致」。
--
-- 实测：100.00 的单发了 71.70 分，POS 改成 0.00，再 locate 一次，
--       值比对 checked=1 changed=0，会员积分照旧 71，
--       镜像变成 total=0.00 / allocated=71.70 ——
--       「已分配额 > 可积分总额」这条最基本的不变量当场破掉。
--
-- 这不是罕见时序：locate 是收银员每记一单都会做的动作。
--
-- ── 修法 ────────────────────────────────────────────
--
-- 基准单独存三列，【只有发分和冲正两条路会写】，
-- 同步与 locate 一律不碰（OrderRepo::upsert 的列清单里没有它们）。
--
-- verify_base_at 为 NULL = 这一单还没发过分，或者是本迁移之前的老数据。
-- 老数据按 created_at 回填一份 —— 那是它当初落库的值，
-- 是我们能拿到的最接近「发分时刻」的东西。
-- ============================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pos_order'
       AND COLUMN_NAME = 'verify_base_should') > 0,
  'DO 0',
  'ALTER TABLE `pos_order` ADD COLUMN `verify_base_should` DECIMAL(11,2) DEFAULT NULL COMMENT ''发分时刻的应收额；值比对的基准，同步/locate 不得覆盖'' AFTER `last_verified_at`');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pos_order'
       AND COLUMN_NAME = 'verify_base_actual') > 0,
  'DO 0',
  'ALTER TABLE `pos_order` ADD COLUMN `verify_base_actual` DECIMAL(11,2) DEFAULT NULL COMMENT ''发分时刻的收款额；同上'' AFTER `verify_base_should`');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pos_order'
       AND COLUMN_NAME = 'verify_base_at') > 0,
  'DO 0',
  'ALTER TABLE `pos_order` ADD COLUMN `verify_base_at` DATETIME DEFAULT NULL COMMENT ''基准定格于何时；NULL=还没发过分'' AFTER `verify_base_actual`');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- 老数据回填：已发过分的单，用落库时的金额当基准
UPDATE `pos_order`
   SET `verify_base_should` = `should_amount`,
       `verify_base_actual` = `actual_amount`,
       `verify_base_at`     = `created_at`
 WHERE `allocated_amount` > 0 AND `verify_base_at` IS NULL;

-- ============================================================
-- 015b · 值比对要能【反复跑】（审计 F1 · P0）
--
-- pendingVerify() 原来只取 verify_status = 0，而全仓库没有任何一处
-- 把它改回 0。于是每张单一生只比对一次：发分当晚跑一遍判「一致」，
-- 从此退出视野。POS 侧的改单/作废【实测 2.9% 发生在结账之后，
-- 其中 1,144 单晚于结账 30 分钟以上】—— 也就是说，
-- 绝大多数改单都发生在那唯一一次比对【之后】，永远查不到。
--
-- 实测：第 1 晚比对判一致 → POS 把 50.00 改成 10.00 →
--       第 2 晚 checked=0，一分钱都没退。
--
-- 修法不需要新列，改判据即可：不再看 verify_status，只问
-- 「在保护期内、发过分、离上次比对够久」。四种状态都要复查 ——
-- 判过一致的会再被改，冲正过的会再缩水，挂人工的也会在人处理前又变。
-- 复查本身是自愈的（applyShrink 按 allocated - newTotal 算），重跑不会重复扣。
--
-- 补一个 (store_code, last_verified_at) 索引让这个条件走得动索引，
-- 不至于每晚全表扫一遍把 POS 主机拖垮。
-- ============================================================

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pos_order'
       AND INDEX_NAME = 'idx_recheck') > 0,
  'DO 0',
  'ALTER TABLE `pos_order` ADD KEY `idx_recheck` (`store_code`,`last_verified_at`)');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
