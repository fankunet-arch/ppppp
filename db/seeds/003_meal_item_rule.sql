-- ============================================================
-- 套餐规则表 —— 对应 docs/04-本地库Schema.md §6.2
--
-- 三个开关互相独立：
--   is_meal_fee   是否算「餐费项」→ 免费餐兜底判据用
--   counts_visit  是否参与十送一计次
--   earns_points  金额是否计入积分基数
--
-- 未被本表覆盖的菜品按安全默认值处理（在代码中，不入表）：
--   is_meal_fee=0 / counts_visit=0 / earns_points=1
--   → 漏配的后果仅是少计次，不会算错金额。
--
-- ⚠️ ref_price 仅供后台显示。菜单会涨价（实测 MENÚ INFINITY
--    MEDIODIA 17.90→18.90、Agua 2.80→2.95、Cerveza 3.30→3.50），
--    业务逻辑只认 menu_item_id。
-- ============================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET @store := 'S001';
SET @now   := NOW();

-- ── ① 堂食套餐（参与积分体系）─────────────────────────────
INSERT INTO `meal_item_rule`
  (`store_code`,`menu_item_id`,`item_name`,`ref_price`,
   `is_meal_fee`,`counts_visit`,`earns_points`,`enabled`,`updated_at`) VALUES

(@store,  2590, 'MENÚ INFINITY VIERNES NOCHE-FIN DE SEMANA-FESTIVOS', 25.90, 1, 1, 1, 1, @now),
(@store, 25900, 'TAKE WAY',                                           25.90, 1, 1, 1, 1, @now),
(@store,  2390, 'MENÚ INFINITY NOCHE LUNES A JUEVES-ADULTOS',         23.90, 1, 1, 1, 1, @now),
(@store,  1890, 'MENÚ INFINITY MEDIODIA - ADULTOS',                   18.90, 1, 1, 1, 1, @now),

-- MENÚ DEL DIA：算餐费，但【不参与十送一】（counts_visit = 0）。
-- 金额是否积分由 earns_points 控制，默认计入。
(@store,  1590, 'MENÚ DEL DIA (Lunes - Jueves)',                      15.90, 1, 0, 1, 1, @now),

-- 儿童套餐：counts_visit 默认 1（参与），后台可自由关闭。
(@store,  1490, 'MENU INFANTIL NOCHE FINDE SEMANA',                   14.90, 1, 1, 1, 1, @now),
(@store,  1290, 'MENÚ INFINITY - INFANTIL MEDIODIA',                  12.90, 1, 1, 1, 1, @now),


-- ── ② 外卖产品线 BOX / COMBO（22 项）──────────────────────
-- 业务规则：BOX 属外卖产品，堂食客人不可点；
--           若堂食单中出现 BOX，仍按外卖处理，一律不计入。
-- 三个开关全 0，其中 earns_points=0 会让该行金额通过按比例扣除
-- 机制从积分基数中剔除；堂食单若全部是 BOX，基数归零自动不积分。
--
-- 实测佐证：跨 2024-01 与 2026-08、220 个订单，20 行 BOX/COMBO
-- 全部出现在 eat_type=3（table_name='Llevar'）订单，堂食 0 行。

(@store,  6049, 'COMBO XL', 65.00, 0, 0, 0, 1, @now),
(@store,  1018, 'BOX 18',   46.50, 0, 0, 0, 1, @now),
(@store,  6047, 'COMBO L',  45.00, 0, 0, 0, 1, @now),
(@store,  1014, 'BOX 14',   39.50, 0, 0, 0, 1, @now),
(@store,  6053, 'COMBO M',  35.00, 0, 0, 0, 1, @now),
(@store,  1017, 'BOX 17',   26.50, 0, 0, 0, 1, @now),
(@store,  1013, 'BOX 13',   25.50, 0, 0, 0, 1, @now),
(@store,  1016, 'BOX 16',   23.50, 0, 0, 0, 1, @now),
(@store,  1009, 'BOX 9',    20.50, 0, 0, 0, 1, @now),
(@store,  6052, 'COMBO S',  20.00, 0, 0, 0, 1, @now),
(@store,  1005, 'BOX 5',    19.50, 0, 0, 0, 1, @now),
(@store,  1006, 'BOX 6',    19.50, 0, 0, 0, 1, @now),
(@store,  1007, 'BOX 7',    19.50, 0, 0, 0, 1, @now),
(@store,  1008, 'BOX 8',    19.50, 0, 0, 0, 1, @now),
(@store,  1011, 'BOX 11',   19.50, 0, 0, 0, 1, @now),
(@store,  1012, 'BOX 12',   19.50, 0, 0, 0, 1, @now),
(@store,  1010, 'BOX 10',   16.50, 0, 0, 0, 1, @now),
(@store,  1015, 'BOX 15',   16.50, 0, 0, 0, 1, @now),
(@store,  1003, 'BOX 3',    13.50, 0, 0, 0, 1, @now),
(@store,  1004, 'BOX 4',    13.50, 0, 0, 0, 1, @now),
(@store,  1002, 'BOX 2',    12.50, 0, 0, 0, 1, @now),
(@store,  1001, 'BOX 1',    10.00, 0, 0, 0, 1, @now),


-- ── ③ 杂项：与套餐同组但明确不算餐费 ──────────────────────
-- 这些项在 POS 的 major_group=3 (Menú) 组内，但属餐具/调料/附加费。
-- 显式入表可避免巡检反复告警。
(@store,  6046, 'PORTES',            3.00, 0, 0, 1, 1, @now),   -- 外送费
(@store,  1200, 'Suplemento (2.00)', 2.00, 0, 0, 1, 1, @now),
(@store,  1100, 'Suplemento (1.00)', 1.00, 0, 0, 1, 1, @now),
(@store, 43435, 'Palillos',          2.00, 0, 0, 1, 1, @now),   -- 筷子
(@store,   555, 'Tenedor',           0.00, 0, 0, 1, 1, @now),   -- 叉子
(@store,   666, 'Wasabi y Jenjibre', 0.00, 0, 0, 1, 1, @now),
(@store,   777, 'Wasabi',            0.00, 0, 0, 1, 1, @now),
(@store,   888, 'Temporal',          0.00, 0, 0, 1, 1, @now),
(@store, 43445, 'Jengibre',          0.00, 0, 0, 1, 1, @now),   -- 姜
(@store, 43447, 'Vaso y Hielo',      0.00, 0, 0, 1, 1, @now),
(@store, 43457, 'Cuchara',           0.00, 0, 0, 1, 1, @now),
(@store, 43458, 'Cuchillo',          0.00, 0, 0, 1, 1, @now)

ON DUPLICATE KEY UPDATE
  `item_name`    = VALUES(`item_name`),
  `ref_price`    = VALUES(`ref_price`),
  `is_meal_fee`  = VALUES(`is_meal_fee`),
  `counts_visit` = VALUES(`counts_visit`),
  `earns_points` = VALUES(`earns_points`),
  `enabled`      = VALUES(`enabled`),
  `updated_at`   = VALUES(`updated_at`);
