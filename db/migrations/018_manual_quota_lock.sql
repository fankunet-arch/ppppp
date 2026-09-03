-- ============================================================
-- 018 · 手工录入日额度改用【单行互斥】(修 016/017 那把区间锁)
--
-- ── 🔴 区间锁在这里结构上就不对 ────────────────────────
--
-- 016/017 用 `SELECT … FOR UPDATE` 把「这个操作员今天那一段流水」
-- 锁起来判日额度。看着对，实测每 160 笔里稳定死锁 5–7 次。
-- InnoDB 自己的记录说得很清楚：
--
--     TRX A: 持有 idx_operator 上的 X gap 锁,等 member 行锁
--     TRX B: 持有 member 行锁,  等着往那个 gap 里 insert(insert intention)
--
-- 根因是 InnoDB 的 gap 锁【彼此不冲突】:两笔手工录入都拿得到同一个
-- gap,然后各自要往里插一行,于是互相等对方的 gap 锁。
-- 这是「先 SELECT … FOR UPDATE 一段区间,再往这段区间 INSERT」的
-- 经典死锁形状,与锁的列粒度无关 —— 017 把 source 加进索引也没用,
-- 因为冲突的双方【都是】手工录入。
--
-- ── 修法:锁一行,不锁区间 ─────────────────────────────
--
-- 每个操作员一行,只当互斥量用,不存任何业务数值 ——
-- 「今天用了多少」仍然实时从 point_ledger 算(单一真相不变,
-- 撤销后额度自动释放这一现有语义也不变)。
--
-- 单行 X 锁没有 gap,不存在「两个事务同时持有」的情况,
-- 排队即可,不会成环。
--
-- ★ 为什么不复用 operator 表那一行:那要求那一行确实存在。
--   冒烟里的合成操作员没有对应行,锁当场落空而代码看上去完全正确 ——
--   「碰巧存在」不是可以拿来守钱的性质(docs/13 §3.4)。
--   这张表的行由 LedgerRepo 自己按需补齐,不依赖任何外部前提。
--
-- ★ 016/017 建的 idx_operator 保留:判额度那次读虽然不再加锁,
--   但仍然要走索引,否则每笔手工录入都全表扫。
-- ============================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `manual_entry_lock` (
  `store_code`  VARCHAR(20) NOT NULL,
  `operator_id` INT         NOT NULL,
  `updated_at`  DATETIME    NOT NULL,
  PRIMARY KEY (`store_code`,`operator_id`)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci
  COMMENT='手工录入日额度的互斥行,每操作员一行,不存业务数值';
