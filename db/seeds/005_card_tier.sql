-- ============================================================
-- 卡片等级的示例值
--
-- 只是【示例】：店家可以在后台改名、停用、删掉，也可以完全不用等级。
-- 不用的话发卡时选「不分级」即可，界面上不会出现任何与等级相关的东西。
--
-- 幂等：已存在的等级不覆盖 —— 店家改过名字之后重跑 seed 不能把名字冲掉。
-- ============================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET @store := 'S001';
SET @now   := NOW();

INSERT INTO `card_tier`
  (`store_code`,`code`,`name`,`name_es`,`points_multiplier`,`sort_order`,`enabled`,`created_at`,`updated_at`)
VALUES
-- 倍率先全给 1.00：等级差异化是店家的经营决定，不该由种子替他决定。
-- 后台可随时改，改了只影响【以后】的入账，历史流水一行都不会变。
(@store, 'std',    '普卡', 'Estándar', 1.00, 10, 1, @now, @now),
(@store, 'silver', '银卡', 'Plata',    1.00, 20, 1, @now, @now),
(@store, 'gold',   '金卡', 'Oro',      1.00, 30, 1, @now, @now)
ON DUPLICATE KEY UPDATE `updated_at` = VALUES(`updated_at`);
