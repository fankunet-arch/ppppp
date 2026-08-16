-- ============================================================
-- 操作员与会话
--
-- 设计文档里 point_ledger 与 audit_log 都记录 operator_id / device，
-- 但没有定义操作员从哪来。若无身份验证，局域网内任何人都能发分，
-- 审计字段也失去意义。这里补上最小可用的一套。
--
-- 不从 POS 的 employee 表取：那样会让登录依赖主库可达，
-- 主库抖动时收银员将无法登录 —— 与「不阻塞收银流程」的原则冲突。
--
-- ★ 兼容性同 001_init.sql，见 db/README.md
-- ============================================================

SET NAMES utf8mb4;

DROP TABLE IF EXISTS `operator`;
CREATE TABLE `operator` (
  `id`            INT NOT NULL AUTO_INCREMENT,
  `store_code`    VARCHAR(20) NOT NULL,
  `login_name`    VARCHAR(40) NOT NULL COMMENT '登录名/工号',
  `display_name`  VARCHAR(40) NOT NULL,
  `pin_hash`      VARCHAR(255) NOT NULL COMMENT 'password_hash() 结果，绝不存明文',
  `role`          TINYINT NOT NULL DEFAULT 1 COMMENT '1=服务员 2=经理 3=管理员',
  `enabled`       TINYINT NOT NULL DEFAULT 1,
  `failed_count`  INT NOT NULL DEFAULT 0 COMMENT '连续失败次数，用于锁定',
  `locked_until`  DATETIME DEFAULT NULL,
  `last_login_at` DATETIME DEFAULT NULL,
  `created_at`    DATETIME NOT NULL,
  `updated_at`    DATETIME NOT NULL,

  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_login` (`store_code`,`login_name`),
  KEY `idx_enabled` (`store_code`,`enabled`)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `operator_session`;
CREATE TABLE `operator_session` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `store_code`   VARCHAR(20) NOT NULL,
  `token_hash`   VARCHAR(64) NOT NULL COMMENT 'SHA-256(token)；令牌明文只在 Cookie 中',
  `operator_id`  INT NOT NULL,
  `device`       VARCHAR(40) DEFAULT NULL COMMENT 'Pad 标识',
  `ip`           VARCHAR(45) DEFAULT NULL,
  `expires_at`   DATETIME NOT NULL,
  `revoked_at`   DATETIME DEFAULT NULL,
  `created_at`   DATETIME NOT NULL,
  `last_seen_at` DATETIME NOT NULL,

  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_token` (`token_hash`),
  KEY `idx_op`     (`store_code`,`operator_id`),
  KEY `idx_expire` (`expires_at`)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
