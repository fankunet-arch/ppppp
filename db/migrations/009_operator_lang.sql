-- ============================================================
-- 009 · 操作员的界面语言
--
-- 收银台上中文和西班牙语的员工混着用，语言必须跟着【账号】走，
-- 不能跟着平板走 —— 同一台 Pad 换个人登录就该换语言。
--
-- NULL = 这个账号还没选过，登录时用后台配置的默认语言（default_lang）。
-- 不给 NOT NULL 默认值就是为了区分「没选过」和「选了中文」：
-- 店里把默认语言改成西班牙语时，没选过的人应该跟着变，
-- 已经明确选过中文的人不应该被改掉。
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
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'operator' AND COLUMN_NAME = 'lang') > 0,
  'DO 0',
  'ALTER TABLE `operator` ADD COLUMN `lang` VARCHAR(5) DEFAULT NULL COMMENT ''界面语言 zh|es；NULL=跟随后台默认'' AFTER `role`');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

