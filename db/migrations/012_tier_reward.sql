-- ============================================================
-- 012 · 按等级设不同的奖励门槛（比如金卡 8 次送 1 次）
--
-- ★ 留空 = 跟随全局设置。
--
--   这样「只想给金卡一点优待」的店家只填金卡那一格就行，
--   其余等级不用动。也因此，不定义等级的店完全不受影响。
--
-- ★ 为什么改门槛是安全的
--
--   达标判定一直是「floor(进度 / 阈值) − 已发张数」，不是「每次 +1」。
--   所以门槛一改，数量会自动对上：
--     · 调低（升级成金卡）→ 立刻补发差额，客人当场享受新待遇
--     · 调高（降级）      → pending 取 max(0, …)，【不会】把已发的券收回去
--   收回已经给出去的东西是绝对不能做的，那是投诉的直接来源。
--
-- ★ 兼容性同 001_init.sql，见 db/README.md §1.5（ALTER 必须幂等）
-- ============================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'card_tier' AND COLUMN_NAME = 'threshold_visits') > 0,
  'DO 0',
  'ALTER TABLE `card_tier` ADD COLUMN `threshold_visits` INT DEFAULT NULL COMMENT ''本等级几次送 1 次；NULL = 跟随全局设置'' AFTER `points_multiplier`');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'card_tier' AND COLUMN_NAME = 'threshold_amount') > 0,
  'DO 0',
  'ALTER TABLE `card_tier` ADD COLUMN `threshold_amount` DECIMAL(10,2) DEFAULT NULL COMMENT ''本等级累计消费多少送 1 次；NULL = 跟随全局设置'' AFTER `threshold_visits`');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;


-- ────────────────────────────────────────────────────────────
-- 券上也记下【发它的时候】用的是哪个等级、什么门槛。
--
-- 同积分倍率那条：门槛是活查的，不定格的话，改一次门槛就再也
-- 解释不了「这张券当初凭什么发的」。客人申诉、会计对账都要看这个。
-- ────────────────────────────────────────────────────────────

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'coupon' AND COLUMN_NAME = 'tier_code') > 0,
  'DO 0',
  'ALTER TABLE `coupon` ADD COLUMN `tier_code` VARCHAR(20) DEFAULT NULL COMMENT ''发券时这张卡的等级；NULL = 不分级''');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'coupon' AND COLUMN_NAME = 'threshold_used') > 0,
  'DO 0',
  'ALTER TABLE `coupon` ADD COLUMN `threshold_used` INT DEFAULT NULL COMMENT ''发券时实际套用的门槛（次数或分）''');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
