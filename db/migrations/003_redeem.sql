-- ============================================================
-- 003 · 十送一核销标记
--
-- 起因（模拟环境实测）：
--   POS 的十送一核销动作 = 在明细里加一条折扣伪行
--     menu_item_id = -2、menu_item_name = 'TARJETA 10+1'、actual_price 为负
--   对应头部的 discount_amount。实测订单 92293：
--     9 人、5 份 MENÚ、original 125.40 → discount -95.60 → should/actual 29.80
--
--   在此之前系统认不出这条行：actual_amount = 29.80 ≠ 0 故不算零元单，
--   餐费项合计 119.50 ≠ 0 故免费餐兜底也不触发 ——
--   结果是客人【用掉一次奖励的同时又攒了 5 次、还照常拿了积分】。
--
-- 本迁移把核销标记落到订单镜像上，与「服务员在 Pad 上手工标记免费餐」
-- （is_free_meal）分开存，便于审计区分「POS 数据判定」与「人工判定」。
--
-- 双兼容：TINYINT/DECIMAL、无 DEFAULT CURRENT_TIMESTAMP、无 CHECK、
--         显式 ROW_FORMAT 与 COLLATE 由表继承（ALTER 不改表级属性）。
-- ============================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

ALTER TABLE `pos_order`
  ADD COLUMN `is_redeemed`   TINYINT(4)     NOT NULL DEFAULT 0
      COMMENT '1=明细含十送一核销折扣行（POS 数据判定，非人工标记）'
      AFTER `is_free_meal`,
  ADD COLUMN `redeem_amount` DECIMAL(11,2)  NOT NULL DEFAULT 0.00
      COMMENT '核销折扣额（正数），来自 -2 折扣行的绝对值'
      AFTER `is_redeemed`;
