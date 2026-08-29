-- ============================================================
-- 013 · 按等级设不同的券有效期（比如金卡的券给 180 天）
--
-- ★ 留空 = 跟随全局的 coupon_valid_days，同 012 那两个门槛。
--
-- ★ 券的有效期在【发券当刻】就算成一个具体日期存进 coupon.valid_to，
--   不是每次查的时候现算。所以改这个设置【只影响以后发的券】，
--   已经在客人手里的券到期日一个字都不会变 —— 这与「奖励规则」里
--   改全局天数的语义完全一致（见 06 §6），不能因为分了等级就变样。
--
-- ★ 兼容性同 001_init.sql，见 db/README.md §1.5（ALTER 必须幂等）
-- ============================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'card_tier' AND COLUMN_NAME = 'coupon_valid_days') > 0,
  'DO 0',
  'ALTER TABLE `card_tier` ADD COLUMN `coupon_valid_days` INT DEFAULT NULL COMMENT ''本等级发的券多少天有效；NULL = 跟随全局；0 = 永久'' AFTER `threshold_amount`');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
