-- ============================================================
-- 006 · 实体会员卡库存
--
-- 发卡方式从电子 Wallet 改成实体卡：卡面印二维码与卡号，
-- 卡背刮开层下印一次性可见的 PIN。建会员改为「扫卡绑定」。
--
-- 这个改动带来一个新的攻击面：如果扫到陌生卡号就直接建会员，
-- 任何人打印一张二维码都能变成合法会员。所以卡号必须【预先入库】——
-- 本表就是那份库存，也是判定卡片真伪的唯一权威。
-- 扫到不在本表里的号码，一律拒绝。
--
-- 卡号 = 前缀 + 8位顺序号 + 3位随机码（如 TK-00000123-4Q7）。
-- 顺序号让印刷厂按序生产、门店按序盘点、断号时知道少了哪张；
-- 随机码挡住「拿到一张真卡就推算邻居卡号」—— 邻居卡确实躺在库存里
-- （已印刷未发放），没有随机码就能直接拿去激活。
-- 详见 app/lib/CardNumber.php（含「为什么不用 HMAC」）。
--
-- ★ 兼容性同 001_init.sql，见 db/README.md
-- ============================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- 用 CREATE TABLE IF NOT EXISTS 而不是 DROP + CREATE：
-- init.php 的破坏性迁移闸门会对所有待应用文件全文搜 "DROP TABLE"，
-- 命中且库里已有业务数据就整批拒绝执行。本表是新增的，没有历史包袱，
-- 写成 DROP 只会让这条迁移在生产库上根本跑不起来。
-- 重复执行由 schema_migration 台账挡住，不需要 DROP 来保证幂等。
CREATE TABLE IF NOT EXISTS `card` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `store_code`   VARCHAR(20) NOT NULL,
  `card_no`      VARCHAR(32) NOT NULL COMMENT '完整卡号，二维码内容与卡面印刷一致',
  `serial`       INT UNSIGNED NOT NULL COMMENT '顺序号，印刷与盘点用',
  `batch_no`     VARCHAR(32) NOT NULL COMMENT '印刷批次，如 B20260822',

  `status`       TINYINT NOT NULL DEFAULT 0
                 COMMENT '0=库存中未激活 1=已激活绑定会员 2=已作废/挂失',
  `member_id`    BIGINT UNSIGNED DEFAULT NULL COMMENT '激活后绑定的会员',

  -- 卡背刮开层下的 PIN。批次生成时随机产生，明文只出现在给印刷厂的
  -- 清单里（印完即销毁），库里只存 hash。兑换免费餐时验它 ——
  -- 二维码印在正面可被拍照，PIN 藏在刮层下，抄了码的人不知道 PIN。
  `pin_hash`     VARCHAR(255) DEFAULT NULL COMMENT 'password_hash() 结果，绝不存明文',
  `pin_fail`     INT NOT NULL DEFAULT 0 COMMENT '连续验错次数',
  `pin_locked_until` DATETIME DEFAULT NULL COMMENT '连续验错后的锁定截止',

  `activated_at` DATETIME DEFAULT NULL,
  `activated_by` INT DEFAULT NULL COMMENT '激活时的操作员',
  `voided_at`    DATETIME DEFAULT NULL,
  `void_reason`  VARCHAR(190) DEFAULT NULL,

  `created_at`   DATETIME NOT NULL,
  `updated_at`   DATETIME NOT NULL,

  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_card`   (`store_code`,`card_no`),
  UNIQUE KEY `uk_serial` (`store_code`,`serial`),

  -- 一人一卡，数据库层保证。挂失换卡时先把旧卡的 member_id 清空
  -- （历史留在 audit_log 里），否则这条唯一键会挡住新卡绑定。
  -- MySQL/MariaDB 的唯一索引允许多个 NULL，所以未激活的卡不受影响。
  UNIQUE KEY `uk_member` (`store_code`,`member_id`),

  KEY `idx_batch`  (`store_code`,`batch_no`),
  KEY `idx_status` (`store_code`,`status`)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
