-- ============================================================
-- 011 · 卡片等级（普卡 / 银卡 / 金卡 …）
--
-- ★ 等级属于【卡】，不属于会员。
--
--   和有效期同一个道理：等级是印在卡面上的，客人手里那张卡长什么样，
--   他就是什么级别。换卡时等级跟着新卡走 —— 想给客人升级，
--   就是发一张新卡给他，这与实体卡的实际用法一致。
--   （若挂在会员上，会出现「卡面印着银卡、系统说是金卡」的错位，
--     而客人只看得见卡面。）
--
-- ★ 等级是【可选】的。
--
--   店家不定义等级，这套东西就完全不出现：老卡的 tier_code 为 NULL，
--   发卡时可以选「不分级」。所以这条迁移对现有数据零影响。
--
-- ★ 等级带积分倍率，且【实际用了多少必须记进流水】。
--
--   倍率是活查的（改了立刻对以后生效），所以流水里不记的话，
--   事后就回答不了「这单为什么给了 150 分」—— 撤销、对账、
--   客人申诉全都变成猜。所以 point_ledger 上同时留下当时的等级与倍率。
--   改倍率只影响【以后】的入账，历史一行都不会变。
--
-- ★ 兼容性同 001_init.sql，见 db/README.md
-- ============================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- 用 IF NOT EXISTS：这张表里是店家自己配的等级，
-- 重跑迁移绝不能把它清掉（同 card 表的理由）
CREATE TABLE IF NOT EXISTS `card_tier` (
  `store_code`  VARCHAR(20) NOT NULL,
  `code`        VARCHAR(20) NOT NULL COMMENT '机器用的标识，如 std/silver/gold；定了就别改',
  `name`        VARCHAR(40) NOT NULL COMMENT '中文名，如「银卡」',
  `name_es`     VARCHAR(40) DEFAULT NULL COMMENT '西语名；为空则回落中文名',
  `sort_order`  INT NOT NULL DEFAULT 0 COMMENT '小的排前面，后台与下拉框都按它排',
  -- 叠在后台那个全局 points_multiplier 之上：
  -- 积分 = 金额 × 每欧元分数 × 全局倍率 × 本等级倍率
  `points_multiplier` DECIMAL(4,2) NOT NULL DEFAULT 1.00
                COMMENT '本等级的积分倍率，1.00 = 与普通卡相同',
  `enabled`     TINYINT NOT NULL DEFAULT 1 COMMENT '停用的等级不再出现在发卡下拉框里，但老卡照常显示',
  `created_at`  DATETIME NOT NULL,
  `updated_at`  DATETIME NOT NULL,

  PRIMARY KEY (`store_code`,`code`),
  KEY `idx_sort` (`store_code`,`sort_order`)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- card_tier 也是 IF NOT EXISTS 建的 —— 表已存在时上面的 CREATE 什么都不做，
-- 所以后来加的列同样要单独补（同 db/README.md §1.5 那条规则）。
SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'card_tier' AND COLUMN_NAME = 'points_multiplier') > 0,
  'DO 0',
  'ALTER TABLE `card_tier` ADD COLUMN `points_multiplier` DECIMAL(4,2) NOT NULL DEFAULT 1.00 COMMENT ''本等级的积分倍率，1.00 = 与普通卡相同'' AFTER `name_es`');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;


-- ────────────────────────────────────────────────────────────
-- 给 card 加等级列。写成可重复执行的 —— card 是用
-- CREATE TABLE IF NOT EXISTS 建的，重跑时表原样保留，
-- 裸 ALTER 会撞 1060（见 db/README.md §1.5，这个坑现场栽过一次）。
-- ────────────────────────────────────────────────────────────

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'card' AND COLUMN_NAME = 'tier_code') > 0,
  'DO 0',
  'ALTER TABLE `card` ADD COLUMN `tier_code` VARCHAR(20) DEFAULT NULL COMMENT ''卡片等级；NULL = 不分级'' AFTER `batch_no`');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'card' AND INDEX_NAME = 'idx_tier') > 0,
  'DO 0',
  'ALTER TABLE `card` ADD KEY `idx_tier` (`store_code`,`tier_code`)');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;


-- ────────────────────────────────────────────────────────────
-- 流水上留下【当时】的等级与倍率。
--
-- 倍率是活查的，改了立刻对以后生效。不记的话，事后没有任何办法
-- 回答「这单为什么给了 150 分」—— 而这正是客人申诉、会计对账、
-- 以及撤销重算时第一个要问的问题。
-- 记下来之后：改倍率只影响以后，历史一行都不会变。
-- ────────────────────────────────────────────────────────────

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'point_ledger' AND COLUMN_NAME = 'tier_code') > 0,
  'DO 0',
  'ALTER TABLE `point_ledger` ADD COLUMN `tier_code` VARCHAR(20) DEFAULT NULL COMMENT ''入账时这张卡的等级；NULL = 不分级''');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'point_ledger' AND COLUMN_NAME = 'tier_multiplier') > 0,
  'DO 0',
  'ALTER TABLE `point_ledger` ADD COLUMN `tier_multiplier` DECIMAL(4,2) DEFAULT NULL COMMENT ''入账时实际套用的等级倍率''');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
