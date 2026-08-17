-- ============================================================
-- 004 · 奖励（N 送 1）机制
--
-- 起因：coupon 表在 001 里建好了，但【从来没有任何代码写入过】——
--   系统会累计 visit_count，可达到 10 次之后什么都不会发生：
--   不发券、Pad 不提示、服务员不知道这位客人可以免费吃了。
--   「十送一」只实现了两头（计次、认出 POS 的 TARJETA 10+1 折扣行），
--   中间「达标→发券→提示→核销」整段是空的。
--
-- 本迁移补上落库部分：
--   · member 增加 rewards_issued，记录已发过几张，避免重复发
--   · coupon 增加发放来源与面额，支持「按次」与「按金额」两种口径
--
-- 双兼容：TINYINT/INT/DECIMAL、无 DEFAULT CURRENT_TIMESTAMP、无 CHECK
-- ============================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

ALTER TABLE `member`
  ADD COLUMN `rewards_issued` INT(11) NOT NULL DEFAULT 0
      COMMENT '累计已发放的奖励券张数；达标判定用 floor(进度/阈值) 减本字段，改阈值或补数据后能自愈，不重复发'
      AFTER `total_spent`;

ALTER TABLE `coupon`
  ADD COLUMN `source` TINYINT(4) NOT NULL DEFAULT 1
      COMMENT '1=满次自动发 2=满额自动发 3=后台手工发'
      AFTER `coupon_type`,
  ADD COLUMN `amount_cents` INT(11) NOT NULL DEFAULT 0
      COMMENT '面额（分）。0 表示「免一份套餐」，按核销时的实际套餐价抵扣'
      AFTER `source`,
  ADD COLUMN `progress_at_grant` INT(11) NOT NULL DEFAULT 0
      COMMENT '发放时的进度快照（次数或金额分），便于对账与申诉'
      AFTER `amount_cents`,
  ADD COLUMN `note` VARCHAR(200) NULL
      COMMENT '手工发放时的原因'
      AFTER `progress_at_grant`;

-- 一名会员的可用券要能快速查出来（Pad 每次定位订单都会问一次）
ALTER TABLE `coupon`
  ADD INDEX `idx_member_status` (`store_code`, `member_id`, `status`);
