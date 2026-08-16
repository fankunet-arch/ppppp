-- ============================================================
-- 本地会员库 —— 初始化
-- 对应 docs/04-本地库Schema.md
--
-- 本地库是【唯一可写】的数据库。POS 主库全程只读。
-- 由于主库只读、无法在 POS 上打「已积分」标记，
-- 本地库是幂等的唯一来源 —— 丢失即导致历史订单重复发分。
-- 备份要求见 docs/04 §9。
--
-- ★ 兼容性：本文件同时支持 MySQL 与 MariaDB，详见 db/README.md
--   · 显式 COLLATE utf8mb4_unicode_ci（MySQL 8 默认的 0900_ai_ci 在 MariaDB 中不存在）
--   · 显式 ROW_FORMAT=DYNAMIC（保证 3072 字节索引键长上限）
--   · 索引键长全部控制在 767 字节内（兼容旧版 COMPACT 行格式）
--   · 不使用 JSON 列类型（MariaDB 中仅为 LONGTEXT 别名）
--   · 不使用 CHECK 约束（MySQL 5.7 静默忽略，MariaDB 10.2+ 强制执行，行为不一致）
--   · 不使用 DEFAULT CURRENT_TIMESTAMP（时间一律由应用层写入，便于统一时区口径）
--   最低版本：MySQL 5.7+ / MariaDB 10.2+
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ── 订单镜像 ──────────────────────────────────────────────
-- 幂等主键 (store_code, serial_id)：serial_id 是 POS 生成的业务流水号
-- YYMMDD+4 位，非自增代理键，数据库迁移/重建不受影响。
DROP TABLE IF EXISTS `pos_order`;
CREATE TABLE `pos_order` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `store_code`        VARCHAR(20)   NOT NULL           COMMENT '门店识别码，来自配置文件',
  `serial_id`         VARCHAR(16)   NOT NULL           COMMENT 'POS 业务流水号 YYMMDD+NNNN',

  -- POS 侧原始信息（仅供展示与回读明细，不作键）
  `order_head_id`     INT           NOT NULL           COMMENT 'POS 自增 ID，仅用于回读明细',
  `check_ids`         VARCHAR(100)  NOT NULL DEFAULT '' COMMENT '该订单包含的 check_id，逗号分隔',
  `table_name`        VARCHAR(30)   DEFAULT NULL,
  `eat_type`          TINYINT       NOT NULL DEFAULT 0 COMMENT '0=堂食（唯一可积分类型）',
  `customer_num`      INT           DEFAULT NULL       COMMENT '就餐人数，AA 均摊默认值',
  `order_end_time`    DATETIME      NOT NULL           COMMENT '结账时间（主库原值）',
  `business_date`     DATE          NOT NULL           COMMENT '营业日：按餐期规则计算，非 serial_id 前 6 位',

  -- 金额快照（发分时刻）
  `original_amount`   DECIMAL(11,2) NOT NULL DEFAULT 0 COMMENT '折扣前原价，明细金额合计的分母',
  `should_amount`     DECIMAL(11,2) NOT NULL DEFAULT 0 COMMENT '应收（折扣后）',
  `actual_amount`     DECIMAL(11,2) NOT NULL DEFAULT 0 COMMENT '收款额（含待找零），不可直接当消费额',
  `total_amount`      DECIMAL(11,2) NOT NULL DEFAULT 0 COMMENT '可积分总额 = LEAST(should,actual) 扣除不计分项后',
  `allocated_amount`  DECIMAL(11,2) NOT NULL DEFAULT 0 COMMENT '已分配金额',
  `excluded_amount`   DECIMAL(11,2) NOT NULL DEFAULT 0 COMMENT 'earns_points=0 的项被扣除的金额',

  -- 份数快照
  `portions_counted`   SMALLINT NOT NULL DEFAULT 0 COMMENT 'counts_visit=1 菜品的 SUM(quantity)',
  `portions_uncounted` SMALLINT NOT NULL DEFAULT 0 COMMENT 'counts_visit=0 的套餐份数',
  `allocated_portions` SMALLINT NOT NULL DEFAULT 0 COMMENT '已分配份数',

  -- 状态
  `is_free_meal`      TINYINT  NOT NULL DEFAULT 0 COMMENT '1=10送1 免费餐，不计次',
  `alloc_status`      TINYINT  NOT NULL DEFAULT 0 COMMENT '0=未分配 1=部分分配 2=已全额分配',
  `verify_status`     TINYINT  NOT NULL DEFAULT 0 COMMENT '0=保护期内 1=已核对一致 2=已冲正 3=待人工复核',
  `last_verified_at`  DATETIME DEFAULT NULL,

  `created_at`        DATETIME NOT NULL,
  `updated_at`        DATETIME NOT NULL,

  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_business` (`store_code`,`serial_id`),
  KEY `idx_verify`  (`verify_status`,`order_end_time`),
  KEY `idx_table`   (`store_code`,`table_name`,`order_end_time`),
  KEY `idx_bizdate` (`store_code`,`business_date`),
  KEY `idx_headid`  (`store_code`,`order_head_id`)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ── 会员 ──────────────────────────────────────────────────
DROP TABLE IF EXISTS `member`;
CREATE TABLE `member` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `store_code`     VARCHAR(20) NOT NULL,
  `card_no`        VARCHAR(32) NOT NULL             COMMENT '会员卡号，系统生成，Wallet 二维码内容',

  -- PII（数据最小化：仅这三项，无姓名）
  `phone`          VARCHAR(32)  DEFAULT NULL,
  `email`          VARCHAR(190) DEFAULT NULL        COMMENT '190 而非 200：兼容旧版 767 字节索引键长上限',
  `birthday`       DATE         DEFAULT NULL,

  -- 合规状态（docs/05-合规与Wallet.md §2）
  `consent_status` TINYINT NOT NULL DEFAULT 0 COMMENT '0=pending 1=active 2=withdrawn 3=expired',
  `consent_at`     DATETIME     DEFAULT NULL,
  `consent_ip`     VARCHAR(45)  DEFAULT NULL COMMENT '同意来源 IP，举证用',
  `consent_token`  VARCHAR(64)  DEFAULT NULL COMMENT 'double opt-in 链接令牌',
  `pseudonymized`  TINYINT NOT NULL DEFAULT 0 COMMENT '1=已假名化（PII 已抹除，流水保留）',

  -- 积分与等级
  `points_balance` INT NOT NULL DEFAULT 0,
  `visit_count`    INT NOT NULL DEFAULT 0 COMMENT '累计计次，用于 10送1',
  `total_spent`    DECIMAL(12,2) NOT NULL DEFAULT 0,
  `level_id`       INT  DEFAULT NULL,
  `level_since`    DATE DEFAULT NULL,

  `created_at`     DATETIME NOT NULL,
  `updated_at`     DATETIME NOT NULL,

  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_card`  (`store_code`,`card_no`),
  UNIQUE KEY `uk_token` (`consent_token`),
  KEY `idx_phone`  (`store_code`,`phone`),
  KEY `idx_email`  (`store_code`,`email`(100)),
  KEY `idx_expire` (`consent_status`,`created_at`)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ── 积分流水（追加式账本，只增不删）──────────────────────
-- 撤销 = 写反向冲正记录，绝不物理删除。
-- 理由：西班牙会计/税务留存义务 + 全流程可审计。
DROP TABLE IF EXISTS `point_ledger`;
CREATE TABLE `point_ledger` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `store_code`      VARCHAR(20) NOT NULL,
  `member_id`       BIGINT UNSIGNED NOT NULL,

  `serial_id`       VARCHAR(16) DEFAULT NULL COMMENT '关联 pos_order.serial_id；手工录入时为 NULL',

  `entry_type`      TINYINT NOT NULL COMMENT '1=消费积分 2=撤销冲正 3=退单冲正 4=兑换扣减 5=过期清零 6=手工调整',
  `amount`          DECIMAL(11,2) NOT NULL DEFAULT 0 COMMENT '本次计入的消费金额（冲正为负）',
  `points`          INT NOT NULL DEFAULT 0 COMMENT '本次积分变动（冲正为负）',
  `counted_visit`   SMALLINT NOT NULL DEFAULT 0 COMMENT '本条计入的次数（冲正为负）',

  -- 快照，供审计与口径切换后重算
  `portions_counted`   SMALLINT NOT NULL DEFAULT 0 COMMENT 'counts_visit=1 菜品的 SUM(quantity)',
  `portions_uncounted` SMALLINT NOT NULL DEFAULT 0,
  `excluded_amount`    DECIMAL(11,2) NOT NULL DEFAULT 0 COMMENT 'earns_points=0 的项被扣除的金额',

  -- 记账方式
  `alloc_mode`      TINYINT DEFAULT NULL COMMENT '1=整单 2=均摊AA 3=点选菜品',
  `alloc_detail`    TEXT    DEFAULT NULL COMMENT '模式 3 记录认领明细快照（JSON 文本，非 JSON 列类型）',

  -- 撤销链
  `status`          TINYINT NOT NULL DEFAULT 1 COMMENT '1=有效 2=已被撤销',
  `reverses_id`     BIGINT UNSIGNED DEFAULT NULL COMMENT '本条冲正的是哪条流水',
  `reversed_by_id`  BIGINT UNSIGNED DEFAULT NULL COMMENT '本条被哪条流水冲正',

  -- 数据来源与降级（docs/03 §10）
  `source`          TINYINT NOT NULL DEFAULT 1 COMMENT '1=POS订单匹配 2=手工录入（降级）',
  `manual_reason`   VARCHAR(40) DEFAULT NULL COMMENT 'source=2 时必填',
  `review_status`   TINYINT NOT NULL DEFAULT 0 COMMENT '0=无需复核 1=待复核 2=通过 3=驳回',
  `approved_by`     INT DEFAULT NULL,

  -- 审计
  `operator_id`     INT DEFAULT NULL,
  `operator_name`   VARCHAR(40)  DEFAULT NULL,
  `device`          VARCHAR(40)  DEFAULT NULL,
  `reason`          VARCHAR(200) DEFAULT NULL,
  `created_at`      DATETIME NOT NULL,

  PRIMARY KEY (`id`),
  KEY `idx_member`  (`store_code`,`member_id`,`created_at`),
  KEY `idx_order`   (`store_code`,`serial_id`),
  KEY `idx_reverse` (`reverses_id`),
  KEY `idx_review`  (`store_code`,`review_status`,`created_at`)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ── 卡券 ──────────────────────────────────────────────────
DROP TABLE IF EXISTS `coupon`;
CREATE TABLE `coupon` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `store_code`    VARCHAR(20) NOT NULL,
  `member_id`     BIGINT UNSIGNED NOT NULL,
  `coupon_type`   TINYINT NOT NULL COMMENT '1=10送1免餐券 2=赠券 3=生日券',
  `code`          VARCHAR(40) NOT NULL,
  `status`        TINYINT NOT NULL DEFAULT 1 COMMENT '1=未使用 2=已核销 3=已过期 4=已作废',
  `valid_from`    DATE DEFAULT NULL,
  `valid_to`      DATE DEFAULT NULL,
  `redeemed_at`   DATETIME DEFAULT NULL,
  `redeemed_serial_id` VARCHAR(16) DEFAULT NULL COMMENT '核销时关联的订单',
  `operator_id`   INT DEFAULT NULL,
  `created_at`    DATETIME NOT NULL,

  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_code` (`store_code`,`code`),
  KEY `idx_member` (`store_code`,`member_id`,`status`)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ── 套餐规则表（后台可维护）────────────────────────────────
-- 三个开关互相独立，见 docs/04 §6.2。
-- 未被本表覆盖的菜品按安全默认值处理：
--   is_meal_fee=0 / counts_visit=0 / earns_points=1
-- 漏配的后果仅是少计次，不会算错金额。
DROP TABLE IF EXISTS `meal_item_rule`;
CREATE TABLE `meal_item_rule` (
  `id`            INT NOT NULL AUTO_INCREMENT,
  `store_code`    VARCHAR(20) NOT NULL,
  `menu_item_id`  INT NOT NULL COMMENT 'POS 的 menu_item.item_id',
  `item_name`     VARCHAR(60)   DEFAULT NULL COMMENT '名称快照，仅供后台显示',
  `ref_price`     DECIMAL(11,2) DEFAULT NULL COMMENT '参考价快照，仅供显示；逻辑只认 item_id',

  `is_meal_fee`   TINYINT NOT NULL DEFAULT 1 COMMENT '是否算「餐费项」→ 免费餐判据用',
  `counts_visit`  TINYINT NOT NULL DEFAULT 1 COMMENT '是否参与十送一计次',
  `earns_points`  TINYINT NOT NULL DEFAULT 1 COMMENT '金额是否计入积分基数',

  `enabled`       TINYINT NOT NULL DEFAULT 1,
  `updated_at`    DATETIME NOT NULL,
  `updated_by`    INT DEFAULT NULL,

  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_item` (`store_code`,`menu_item_id`)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ── 餐期配置 ──────────────────────────────────────────────
-- 营业日切点已用 POS 自身数据验证 = 02:00（docs/01 §5.2）
DROP TABLE IF EXISTS `meal_period`;
CREATE TABLE `meal_period` (
  `id`             INT NOT NULL AUTO_INCREMENT,
  `store_code`     VARCHAR(20) NOT NULL,
  `period_name`    VARCHAR(40) NOT NULL,
  `start_time`     TIME NOT NULL,
  `end_time`       TIME NOT NULL,
  `cross_midnight` TINYINT NOT NULL DEFAULT 0 COMMENT '1=end_time 属次日',
  `sort_order`     INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_store` (`store_code`)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ── 系统配置 ──────────────────────────────────────────────
DROP TABLE IF EXISTS `sys_config`;
CREATE TABLE `sys_config` (
  `store_code`   VARCHAR(20) NOT NULL,
  `config_key`   VARCHAR(64) NOT NULL,
  `config_value` TEXT,
  `updated_at`   DATETIME NOT NULL,
  PRIMARY KEY (`store_code`,`config_key`)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ── 同步水位线 ────────────────────────────────────────────
-- 水位线只在整批成功落库后才前移；
-- 值取自主库返回的 order_end_time，绝不用本地时间。
DROP TABLE IF EXISTS `sync_cursor`;
CREATE TABLE `sync_cursor` (
  `store_code`     VARCHAR(20) NOT NULL,
  `cursor_name`    VARCHAR(40) NOT NULL COMMENT 'incremental / rolling_verify',
  `watermark`      DATETIME NOT NULL COMMENT '取自主库 order_end_time',
  `last_run_at`    DATETIME DEFAULT NULL,
  `last_status`    TINYINT  DEFAULT NULL COMMENT '1=成功 2=部分成功 3=失败',
  `last_error`     VARCHAR(500) DEFAULT NULL,
  `rows_processed` INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`store_code`,`cursor_name`)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ── 操作审计 ──────────────────────────────────────────────
-- GDPR/LOPDGDD 要求的数据主体权利行使记录一并记入本表。
DROP TABLE IF EXISTS `audit_log`;
CREATE TABLE `audit_log` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `store_code`    VARCHAR(20) NOT NULL,
  `action`        VARCHAR(40) NOT NULL COMMENT 'point_grant / point_reverse / member_create / coupon_redeem / consent_change / data_export / data_erase',
  `target_type`   VARCHAR(20) DEFAULT NULL,
  `target_id`     VARCHAR(64) DEFAULT NULL,
  `operator_id`   INT DEFAULT NULL,
  `operator_name` VARCHAR(40) DEFAULT NULL,
  `device`        VARCHAR(40) DEFAULT NULL,
  `detail`        TEXT DEFAULT NULL,
  `created_at`    DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_action` (`store_code`,`action`,`created_at`),
  KEY `idx_target` (`target_type`,`target_id`)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ── 告警队列（巡检产出，供后台复核）──────────────────────
DROP TABLE IF EXISTS `alert`;
CREATE TABLE `alert` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `store_code`  VARCHAR(20) NOT NULL,
  `alert_type`  VARCHAR(40) NOT NULL COMMENT 'free_meal_suspect / amount_changed / data_gap / new_menu_item / manual_entry',
  `severity`    TINYINT NOT NULL DEFAULT 1 COMMENT '1=提示 2=警告 3=严重',
  `ref_type`    VARCHAR(20) DEFAULT NULL,
  `ref_id`      VARCHAR(64) DEFAULT NULL,
  `message`     VARCHAR(500) NOT NULL,
  `detail`      TEXT DEFAULT NULL,
  `status`      TINYINT NOT NULL DEFAULT 0 COMMENT '0=未处理 1=已处理 2=已忽略',
  `handled_by`  INT DEFAULT NULL,
  `handled_at`  DATETIME DEFAULT NULL,
  `created_at`  DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_open` (`store_code`,`status`,`created_at`),
  KEY `idx_type` (`store_code`,`alert_type`,`created_at`)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
