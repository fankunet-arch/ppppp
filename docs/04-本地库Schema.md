# 04 · 本地库 Schema

> 本地会员库为**唯一可写**的数据库。POS 主库全程只读。
>
> 引擎 `InnoDB`，字符集 **`utf8mb4`**（主库是 3 字节 `utf8`，本地不受此限制）。

## 1. 设计要点

| 要点 | 说明 |
|---|---|
| 业务主键 | `(store_code, serial_id)` —— 避开一切数据库自增 ID |
| 多店维度 | `store_code` 写入配置文件，落库时一并记录，跨店天然隔离 |
| 追加式账本 | 积分流水只增不删，撤销写反向冲正记录 |
| 金额守恒 | `SUM(ledger.amount) ≤ pos_order.total_amount`，事务内校验 |
| PII 最小化 | 仅手机号、邮箱、生日，无姓名 |
| 假名化删除 | 删除会员时保留流水、抹除 PII，满足会计留存义务 |

## 2. 订单表 `pos_order`

镜像 POS 侧已结账订单，是积分分配的容器。

```sql
CREATE TABLE `pos_order` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `store_code`        VARCHAR(20)  NOT NULL           COMMENT '门店识别码，来自配置文件',
  `serial_id`         VARCHAR(16)  NOT NULL           COMMENT 'POS 业务流水号 YYMMDD+NNNN',

  -- POS 侧原始信息（仅供展示与追溯，不作键）
  `order_head_id`     INT          NOT NULL           COMMENT 'POS 自增 ID，仅用于回读明细',
  `check_ids`         VARCHAR(100) NOT NULL           COMMENT '该订单包含的 check_id，逗号分隔',
  `table_name`        VARCHAR(30)  DEFAULT NULL,
  `eat_type`          TINYINT      NOT NULL DEFAULT 0 COMMENT '0=堂食（唯一可积分类型）',
  `customer_num`      INT          DEFAULT NULL       COMMENT '就餐人数，AA 均摊默认值',
  `order_end_time`    DATETIME     NOT NULL           COMMENT '结账时间（主库原值）',
  `business_date`     DATE         NOT NULL           COMMENT '营业日，按餐期规则计算，非 serial_id 前6位',

  -- 金额快照（发分时刻）
  `should_amount`     DECIMAL(11,2) NOT NULL DEFAULT 0,
  `actual_amount`     DECIMAL(11,2) NOT NULL DEFAULT 0,
  `total_amount`      DECIMAL(11,2) NOT NULL DEFAULT 0 COMMENT '可积分总额 = LEAST(should, actual)',
  `allocated_amount`  DECIMAL(11,2) NOT NULL DEFAULT 0 COMMENT '已分配金额',

  -- 状态
  `is_free_meal`      TINYINT      NOT NULL DEFAULT 0 COMMENT '1=10送1 免费餐，不计次',
  `alloc_status`      TINYINT      NOT NULL DEFAULT 0 COMMENT '0=未分配 1=部分分配 2=已全额分配',
  `verify_status`     TINYINT      NOT NULL DEFAULT 0 COMMENT '0=保护期内 1=已核对一致 2=已冲正 3=待人工复核',
  `last_verified_at`  DATETIME     DEFAULT NULL       COMMENT '最近一次值比对时间',

  `created_at`        DATETIME     NOT NULL,
  `updated_at`        DATETIME     NOT NULL,

  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_business` (`store_code`,`serial_id`),          -- ★ 幂等主键
  KEY `idx_verify`  (`verify_status`,`order_end_time`),          -- 值比对任务扫描
  KEY `idx_table`   (`store_code`,`table_name`,`order_end_time`),
  KEY `idx_bizdate` (`store_code`,`business_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**注意** `order_head_id` 保留但**不参与任何唯一约束** —— 它是 POS 侧的 `AUTO_INCREMENT` 代理键，仅用于回读明细时拼 `WHERE order_head_id = ? AND check_id = ?`。

## 3. 会员表 `member`

```sql
CREATE TABLE `member` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `store_code`     VARCHAR(20) NOT NULL,
  `card_no`        VARCHAR(32) NOT NULL             COMMENT '会员卡号，系统生成，Wallet 二维码内容',

  -- PII（数据最小化：仅这三项，无姓名）
  `phone`          VARCHAR(32)  DEFAULT NULL,
  `email`          VARCHAR(200) DEFAULT NULL,
  `birthday`       DATE         DEFAULT NULL,

  -- 合规状态
  `consent_status` TINYINT NOT NULL DEFAULT 0       COMMENT '0=pending 1=active 2=withdrawn 3=expired',
  `consent_at`     DATETIME DEFAULT NULL,
  `consent_ip`     VARCHAR(45) DEFAULT NULL         COMMENT '同意来源 IP，举证用',
  `consent_token`  VARCHAR(64) DEFAULT NULL         COMMENT 'double opt-in 链接令牌',
  `pseudonymized`  TINYINT NOT NULL DEFAULT 0       COMMENT '1=已假名化（PII 已抹除，流水保留）',

  -- 积分与等级
  `points_balance` INT NOT NULL DEFAULT 0,
  `visit_count`    INT NOT NULL DEFAULT 0           COMMENT '累计消费次数，用于 10送1',
  `total_spent`    DECIMAL(12,2) NOT NULL DEFAULT 0,
  `level_id`       INT DEFAULT NULL,
  `level_since`    DATE DEFAULT NULL,

  `created_at`     DATETIME NOT NULL,
  `updated_at`     DATETIME NOT NULL,

  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_card`  (`store_code`,`card_no`),
  KEY `idx_phone`  (`store_code`,`phone`),
  KEY `idx_email`  (`store_code`,`email`),
  KEY `idx_expire` (`consent_status`,`created_at`)   -- 扫描超期未同意
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**三选一检索**：Pad 端明确选择输入类型（卡号 / 手机号 / 邮箱），对应走 `uk_card` / `idx_phone` / `idx_email`，不做跨字段模糊搜索。

## 4. 积分流水 `point_ledger`（追加式账本）

**只增不删。** 撤销通过写反向记录实现。

```sql
CREATE TABLE `point_ledger` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `store_code`      VARCHAR(20) NOT NULL,
  `member_id`       BIGINT UNSIGNED NOT NULL,

  -- 关联订单（可为空：手工调整、活动赠分等）
  `serial_id`       VARCHAR(16) DEFAULT NULL         COMMENT '关联 pos_order.serial_id',

  `entry_type`      TINYINT NOT NULL                 COMMENT '1=消费积分 2=撤销冲正 3=退单冲正 4=兑换扣减 5=过期清零 6=手工调整',
  `amount`          DECIMAL(11,2) NOT NULL DEFAULT 0 COMMENT '本次计入的消费金额（冲正为负）',
  `points`          INT NOT NULL DEFAULT 0           COMMENT '本次积分变动（冲正为负）',
  `counted_visit`   SMALLINT NOT NULL DEFAULT 0      COMMENT '本条计入的次数 = A档套餐份数（冲正为负值）',
  `portions_a`      SMALLINT NOT NULL DEFAULT 0      COMMENT 'A档套餐份数快照（参与十送一）',
  `portions_b`      SMALLINT NOT NULL DEFAULT 0      COMMENT 'B档套餐份数快照（MENÚ DEL DIA，不计次）',
  `excluded_amount` DECIMAL(11,2) NOT NULL DEFAULT 0 COMMENT '因开关关闭而从积分基数扣除的金额',

  -- AA 记账方式
  `alloc_mode`      TINYINT DEFAULT NULL             COMMENT '1=整单 2=均摊AA 3=点选菜品',
  `alloc_detail`    TEXT DEFAULT NULL                COMMENT '模式3时记录认领的 menu_item 明细快照（JSON）',

  -- 撤销链
  `status`          TINYINT NOT NULL DEFAULT 1       COMMENT '1=有效 2=已被撤销',
  `reverses_id`     BIGINT UNSIGNED DEFAULT NULL     COMMENT '本条冲正的是哪条流水',
  `reversed_by_id`  BIGINT UNSIGNED DEFAULT NULL     COMMENT '本条被哪条流水冲正',

  -- 数据来源与降级（见 03 §10）
  `source`          TINYINT NOT NULL DEFAULT 1       COMMENT '1=POS订单匹配 2=手工录入（降级）',
  `manual_reason`   VARCHAR(40) DEFAULT NULL         COMMENT 'source=2 时必填：system_not_found / network_error / other',
  `review_status`   TINYINT NOT NULL DEFAULT 0       COMMENT '0=无需复核 1=待复核 2=已复核通过 3=已复核驳回',
  `approved_by`     INT DEFAULT NULL                 COMMENT '超限时的审批人',

  -- 审计
  `operator_id`     INT DEFAULT NULL                 COMMENT '操作员工',
  `operator_name`   VARCHAR(40) DEFAULT NULL,
  `device`          VARCHAR(40) DEFAULT NULL         COMMENT '来源 Pad',
  `reason`          VARCHAR(200) DEFAULT NULL        COMMENT '撤销/调整原因',
  `created_at`      DATETIME NOT NULL,

  PRIMARY KEY (`id`),
  KEY `idx_member`  (`store_code`,`member_id`,`created_at`),
  KEY `idx_order`   (`store_code`,`serial_id`),
  KEY `idx_reverse` (`reverses_id`),
  KEY `idx_review`  (`store_code`,`review_status`,`created_at`)   -- 待复核队列
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### 4.0 手工录入流水（降级路径）

`history_order_head` 曾发生真实数据丢失（2024-08，6 天、478 单、29,233 欧，见 `01` §5.3）。缺失期间收银员查不到订单，必须允许手工录入。

此类流水的特征：

- `source = 2`，`manual_reason` 必填
- `serial_id` 为 `NULL`（没有对应的 POS 订单）
- **不写入 `pos_order`**（无业务号可作主键）
- `review_status = 1`，自动进入后台待复核队列
- 金额超过 `sys_config.manual_entry_limit` 时需 `approved_by`

### 4.1 撤销示例

```
-- 原始登记
id=101  member=A  serial_id=2608130080  entry_type=1  amount=+53.70  points=+53  counted_visit=1  status=2  reversed_by_id=102

-- 撤销冲正
id=102  member=A  serial_id=2608130080  entry_type=2  amount=-53.70  points=-53  counted_visit=-1 status=1  reverses_id=101
        reason='客人要求改为 AA 分记'  operator_name='...'
```

同时 `pos_order.allocated_amount` 减去 53.70，`alloc_status` 回到 `0`。

### 4.2 计次口径

`member.visit_count = SUM(point_ledger.counted_visit WHERE status=1)`

**默认口径 `by_portion`**：`counted_visit` = 该会员认领的 **A 档套餐份数**（见 `03` §3.2）。

| 场景 | `portions_a` | `portions_b` | `counted_visit` |
|---|---|---|---|
| 整单记一人，3 份 INFINITY | 3 | 0 | **3** |
| AA 3 人，各 1 份 INFINITY | 1（每人） | 0 | **1**（每人） |
| AA 3 人，2 份 INFINITY + 1 份 DEL DIA | 1/1/0 | 0/0/1 | **1 / 1 / 0** |
| 只点单品无套餐 | 0 | 0 | **0**（金额照常积分） |

`portions_a` / `portions_b` 为快照字段，用于事后审计与口径切换时的重算。

> 🟡 **商业确认项**：整单记一人时 3 份套餐 = +3 次，3 人同行来 4 次即可换 1 份免费餐。若不接受，把 `visit_count_mode` 改为 `by_ledger`（每笔流水最多 1 次）。见 `README.md` 事项 #1。

## 5. 卡券表 `coupon`

```sql
CREATE TABLE `coupon` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `store_code`    VARCHAR(20) NOT NULL,
  `member_id`     BIGINT UNSIGNED NOT NULL,
  `coupon_type`   TINYINT NOT NULL              COMMENT '1=10送1免餐券 2=赠券 3=生日券',
  `code`          VARCHAR(40) NOT NULL,
  `status`        TINYINT NOT NULL DEFAULT 1    COMMENT '1=未使用 2=已核销 3=已过期 4=已作废',
  `valid_from`    DATE DEFAULT NULL,
  `valid_to`      DATE DEFAULT NULL,
  `redeemed_at`   DATETIME DEFAULT NULL,
  `redeemed_serial_id` VARCHAR(16) DEFAULT NULL COMMENT '核销时关联的订单',
  `operator_id`   INT DEFAULT NULL,
  `created_at`    DATETIME NOT NULL,

  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_code` (`store_code`,`code`),
  KEY `idx_member` (`store_code`,`member_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

核销动作**严格在 Pad 端标记本地库**，收银员再到 POS 端手动做对应折扣收银。

## 6. 配置表

### 6.1 系统配置 `sys_config`

```sql
CREATE TABLE `sys_config` (
  `store_code`  VARCHAR(20) NOT NULL,
  `config_key`  VARCHAR(64) NOT NULL,
  `config_value` TEXT,
  `updated_at`  DATETIME NOT NULL,
  PRIMARY KEY (`store_code`,`config_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**必备配置项：**

| key | 默认值 | 说明 |
|---|---|---|
| `order_lookup_window_min` | `30` | 订单查找时间窗（分钟） |
| `points_per_euro` | `1` | 每 1 欧元积分数 |
| `points_multiplier` | `1.0` | 积分倍率（1.0 = 不启用） |
| `points_include_tax` | `1` | 积分按含税价（已确认为 1） |
| `free_meal_extra_earns` | `0` | 免费餐当次的额外消费（饮料甜品）是否计金额积分 |
| `visit_count_mode` | `by_portion` | 计次口径：`by_portion`=按 A 档套餐份数（默认）／`by_ledger`=每笔流水最多 1 次 |
| `menu_del_dia_earns_points` | `1` | `MENÚ DEL DIA`(1590) 的金额是否计入积分（**计次永远为否**，不可配置） |
| `reversal_window_hours` | `24` | 自由撤销时间窗，超出需经理权限 |
| `verify_protect_days` | `30` | 值比对保护期 |
| `sync_window_hours` | `48` | 滚动校准窗口 |
| `consent_expire_days` | `30` | 未同意的会员积分冻结期限 |
| `meal_item_whitelist` | `2590,25900,2390,1890,1590,1490,1290` | 「餐费项」`menu_item_id` 列表（见 `01` §4.2） |
| `meal_item_alert_price` | `5.00` | 白名单巡检阈值：`major_group=3` 且超过此价的新项 → 提醒 |
| `business_day_cutoff` | `02:00` | 营业日切点（已用 POS 数据验证，见 `01` §5.2） |
| `manual_entry_enabled` | `1` | 是否允许降级手工录入 |
| `manual_entry_limit` | `200.00` | 手工录入单笔金额上限，超出需审批 |
| `manual_entry_daily_alert` | `5` | 同一员工单日手工录入超过此数 → 告警 |
| `lookup_fallback_window_min` | `60` | 首次查不到时的放宽时间窗 |

### 6.2 餐期配置 `meal_period`

```sql
CREATE TABLE `meal_period` (
  `id`          INT NOT NULL AUTO_INCREMENT,
  `store_code`  VARCHAR(20) NOT NULL,
  `period_name` VARCHAR(40) NOT NULL,
  `start_time`  TIME NOT NULL,
  `end_time`    TIME NOT NULL,
  `cross_midnight` TINYINT NOT NULL DEFAULT 0 COMMENT '1=end_time 属次日',
  `sort_order`  INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_store` (`store_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

初始数据：

```sql
INSERT INTO meal_period (store_code, period_name, start_time, end_time, cross_midnight, sort_order) VALUES
('S001', '白天', '11:00:00', '18:00:00', 0, 1),
('S001', '晚上', '19:30:00', '02:00:00', 1, 2);
```

`cross_midnight = 1` 的餐期，其结束时间属次日；订单归属于**餐期起始日**。

## 7. 同步水位线 `sync_cursor`

```sql
CREATE TABLE `sync_cursor` (
  `store_code`     VARCHAR(20) NOT NULL,
  `cursor_name`    VARCHAR(40) NOT NULL       COMMENT 'incremental / rolling_verify',
  `watermark`      DATETIME NOT NULL          COMMENT '取自主库 order_end_time，非本地时间',
  `last_run_at`    DATETIME DEFAULT NULL,
  `last_status`    TINYINT DEFAULT NULL       COMMENT '1=成功 2=部分成功 3=失败',
  `last_error`     VARCHAR(500) DEFAULT NULL,
  `rows_processed` INT DEFAULT 0,
  PRIMARY KEY (`store_code`,`cursor_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**水位线只在整批成功落库后才前移。** 值取自主库返回的 `order_end_time`，绝不用本地时间。

## 8. 操作审计 `audit_log`

```sql
CREATE TABLE `audit_log` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `store_code` VARCHAR(20) NOT NULL,
  `action`     VARCHAR(40) NOT NULL      COMMENT 'point_grant / point_reverse / member_create / coupon_redeem / consent_change / data_export / data_erase',
  `target_type` VARCHAR(20) DEFAULT NULL,
  `target_id`  VARCHAR(64) DEFAULT NULL,
  `operator_id` INT DEFAULT NULL,
  `operator_name` VARCHAR(40) DEFAULT NULL,
  `device`     VARCHAR(40) DEFAULT NULL,
  `detail`     TEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_action` (`store_code`,`action`,`created_at`),
  KEY `idx_target` (`target_type`,`target_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

GDPR/LOPDGDD 要求的数据主体权利行使记录（访问、更正、删除、限制、携带、反对）一并记入此表。

## 9. 备份要求（硬性）

主库只读 → **本地库是幂等的唯一来源**。丢失即导致历史订单重复发分。

| 项 | 要求 |
|---|---|
| 频率 | 每日冷备 |
| 存储 | **异地**（利用"仅出站"网络推送到云端对象存储） |
| 加密 | 必须（含会员 PII） |
| 保留 | 30 天滚动 |
| 演练 | 定期恢复演练，验证备份可用 |
