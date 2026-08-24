-- ============================================================
-- 010 · 账号的西班牙语显示名
--
-- 顶栏原本长这样：「系统管理员 (encargado)」——「(经理)」跟着语言走了，
-- 名字本身没有，中西混排。要么全中文，要么全西文，才像话。
--
-- 为什么不在词典里翻：display_name 是【店家自己填的】，
-- 可能是「小王」也可能是「María」。翻译一个人名是没有意义的，
-- 只能让填的人两边都填。填一边的话，另一边就用这一边（见 §回落）。
--
-- 回落：display_name_es 为空 → 显示 display_name。
-- 所以老账号不改也不会坏，只是西语界面下仍显示中文名 —— 这是可接受的，
-- 且店家在后台把西语名补上就好了。
--
-- 非破坏性：只加列，不动任何现有数据。
-- ============================================================


-- ────────────────────────────────────────────────────────────
-- ★ 下面每一步都写成【可重复执行】的。
--
--   现场栽过：006_card.sql 用的是 CREATE TABLE IF NOT EXISTS，
--   card 表已存在时它什么都不做；而这里原本是裸的 ALTER ADD COLUMN，
--   列已存在时直接报 1060 Duplicate column name，整条迁移链就停在这。
--   两者不对称，导致「库还在、登记表没了」的情况下永远跑不完。
--
--   MariaDB 有 ADD COLUMN IF NOT EXISTS，MySQL 8 没有，所以用
--   information_schema 判一下再动态执行 —— 两种库都吃这一套。
--   （列已存在时执行 DO 0，即什么都不做。）
-- ────────────────────────────────────────────────────────────

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'operator' AND COLUMN_NAME = 'display_name_es') > 0,
  'DO 0',
  'ALTER TABLE `operator` ADD COLUMN `display_name_es` VARCHAR(40) DEFAULT NULL COMMENT ''西班牙语显示名；为空则回落到 display_name'' AFTER `display_name`');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

