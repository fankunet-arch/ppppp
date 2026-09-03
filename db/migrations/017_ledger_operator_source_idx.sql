-- ============================================================
-- 017 · idx_operator 要把 source 放进去（修 016 引入的死锁）
--
-- ── 🔴 016 那把锁锁多了 ────────────────────────────────
--
-- 016 加 `(store_code, operator_id, created_at)` 是为了让手工录入的
-- 日额度能用 SELECT … FOR UPDATE 把「这个操作员今天那一段」锁住。
-- 锁住的目的是不让【手工录入】往里加，但索引里没有 source，
-- 于是【正常记账】的 INSERT 也落在同一段区间里。
--
-- 实测（同一个操作员，2 个手工录入进程 + 2 个正常记账进程，各 40 笔）：
--
--     manualGrant : 先锁区间 → 再锁会员行
--     grantOne    : 先锁会员行 → 再 INSERT 进那段区间
--
-- 正好是一个 AB-BA 环。160 笔里有 7 笔冲破了 LocalDb 的两次重试，
-- 在柜台上表现为「数据库繁忙」。账目不变量没破（钱没错乱），
-- 但那是 7 次当着客人面失败的操作。
--
-- ── 修法 ──────────────────────────────────────────────
--
-- 把 source 放在 created_at 前面，判额度时按 source = 手工 这个等值条件
-- 走索引 —— 正常记账（source = POS）落在索引的另一段，
-- 两者【在索引层面就不相邻】，锁根本不会碰面。
--
-- 剩下的争用只有「手工 ↔ 手工」，而那正是这道上限需要的争用。
--
-- ★ 教训记在 docs/13 §3.4：
--   加锁不只要问「锁够不够」，还要问【锁多了会挡住谁】。
--   一把为 A 设的锁，如果 B 也落在它的范围里，B 就成了它的死锁对手。
-- ============================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- 先删旧的（016 建的那个粒度太粗）
SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'point_ledger'
       AND INDEX_NAME = 'idx_operator'
       AND COLUMN_NAME = 'source') > 0,
  'DO 0',                                   -- 已经是新粒度，什么都不做
  IF((SELECT COUNT(*) FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'point_ledger'
          AND INDEX_NAME = 'idx_operator') > 0,
     'ALTER TABLE `point_ledger` DROP KEY `idx_operator`',
     'DO 0'));
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'point_ledger'
       AND INDEX_NAME = 'idx_operator') > 0,
  'DO 0',
  'ALTER TABLE `point_ledger` ADD KEY `idx_operator` (`store_code`,`operator_id`,`source`,`created_at`)');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
