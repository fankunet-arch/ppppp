-- ============================================================
-- 019 · 订单镜像要记住「券抵之前一共几份」
--
-- pos_order.portions_counted 存的是【净】份数（券抵掉的已经扣过）。
-- 而「扣掉几份」这件事有两个写者，它们算法不同：
--
--   ① buildContext()（locate / 同步时）：净 = 总份数 − max(匹配串反推, App 核销张数)
--      —— 对的，N 张券就扣 N 份。
--   ② markRedeemedByApp()（前台点「核销」时）：
--      净 = GREATEST(0, 净 − IF(is_redeemed = 1, 0, 1))
--      —— is_redeemed 是个【布尔】，被当成计数用了：
--         一桌两张券只扣得掉一份。
--
-- 实测（4 份的家庭桌，甲乙各用一张券）：
--   先记账后核销 → 4 次记进去，两次核销只退回 1 次，
--   实付 2 份却留着 3 次 —— 白送一顿饭的进度。
--   先核销后记账 → 净份数停在 3，四人 AA 直接被
--   exceeds_portions 拒掉，收银员当场记不进去。
--
-- ★ 为什么不能简单改成「每次核销都减 1」：
--   POS 上已经有折扣行、匹配串认出来过一次时，buildContext 已经替
--   那张券扣过了；再减一次就是把同一份免费餐扣两遍 —— 方向是对客人不利的。
--
-- 所以把【总份数】也存下来，核销时直接按地面真值重算：
--   净 = LEAST(现在的净份数, 总份数 − App 已核销张数)
-- 取 LEAST 保证「只减不加」：匹配串比 App 多认出来的那部分不会被撤销。
--
-- ★ 老数据 portions_gross = 0：那一档退回原来的「减 1」写法，
--   不会把历史订单的净份数一把清零。
-- ============================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pos_order'
       AND COLUMN_NAME = 'portions_gross') > 0,
  'DO 0',
  'ALTER TABLE `pos_order` ADD COLUMN `portions_gross` SMALLINT NOT NULL DEFAULT 0 COMMENT ''券抵之前的总份数；0=老数据未回填'' AFTER `portions_counted`');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- 已有订单回填：净份数 + App 已核销的张数 ≈ 总份数。
-- 没核销过的单，净就是总，回填完全准确；核销过的单按张数补回去。
UPDATE `pos_order` o
   SET o.portions_gross = GREATEST(0, o.portions_counted) + (
         SELECT COUNT(*) FROM `coupon` c
          WHERE c.store_code = o.store_code
            AND c.redeemed_serial_id = o.serial_id
            AND c.status = 2)
 WHERE o.portions_gross = 0;
