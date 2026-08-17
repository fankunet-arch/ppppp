-- ============================================================
-- 005 · 订单税额快照
--
-- 起因：配置项 points_include_tax 建了却从没被代码读过（死配置）——
--   后台能改，改了没有任何效果。要真正实现「按不含税价积分」,
--   就得把 POS 的 tax_amount 一起取回来存下。
--
-- 实测 88,791 行里 87,138 行 tax_amount 非零，且与实物小票吻合：
--   92518  actual 57.70 − tax 5.25 = 52.45 = 小票 SubTotal
--   92521  actual 58.25 − tax 5.30 = 52.95 = 小票 SubTotal
-- 所以按【真实税额】折算，不用硬编码 10% 税率。
-- ============================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

ALTER TABLE `pos_order`
  ADD COLUMN `tax_amount` DECIMAL(11,2) NOT NULL DEFAULT 0.00
      COMMENT 'POS 的税额快照；points_include_tax=0 时用它折算成不含税价'
      AFTER `actual_amount`;
