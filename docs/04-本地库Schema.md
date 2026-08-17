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
  `tax_amount`        DECIMAL(11,2) NOT NULL DEFAULT 0    COMMENT 'POS 税额快照；points_include_tax=0 时据此折算不含税价',
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
  `rewards_issued` INT NOT NULL DEFAULT 0                 COMMENT '累计已发奖励券张数；达标判定用 floor(进度/阈值) 减本字段',
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
  `counted_visit`   SMALLINT NOT NULL DEFAULT 0      COMMENT '本条计入的次数（冲正为负值）',
  `portions_counted`   SMALLINT NOT NULL DEFAULT 0   COMMENT '份数快照：counts_visit=1 菜品的 SUM(quantity)',
  `portions_uncounted` SMALLINT NOT NULL DEFAULT 0   COMMENT '份数快照：counts_visit=0 的套餐份数（DEL DIA / 儿童套餐等）',
  `excluded_amount` DECIMAL(11,2) NOT NULL DEFAULT 0 COMMENT 'earns_points=0 的项被扣除的金额',

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

**默认口径 `by_portion`**：`counted_visit` = 该会员认领的、`meal_item_rule.counts_visit = 1` 的菜品的 **`SUM(quantity)`**（见 `03` §3.2）。

| 场景 | `portions_counted` | `portions_uncounted` | `counted_visit` |
|---|---|---|---|
| 整单记一人，3 份 INFINITY | 3 | 0 | **3** |
| AA 3 人，各 1 份 INFINITY | 1（每人） | 0 | **1**（每人） |
| AA 3 人，2 份 INFINITY + 1 份 DEL DIA | 1/1/0 | 0/0/1 | **1 / 1 / 0** |
| 儿童同行（后台关闭儿童计次） | 2 份成人 | 1 份儿童 | **2** |
| 只点单品无套餐 | 0 | 0 | **0**（金额照常积分） |

`portions_counted` / `portions_uncounted` 为快照字段，用于事后审计与口径切换时的重算。

> 📌 **已确认采用 `by_portion`**：整单记一人时 3 份套餐 = +3 次，3 人同行来 4 次即可换 1 份免费餐，此商业影响已知悉并接受。备用口径 `by_ledger`（每笔流水最多 1 次）保留在配置中，`portions_counted` 快照支持切换后重算历史数据。

## 5. 卡券表 `coupon`

```sql
CREATE TABLE `coupon` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `store_code`    VARCHAR(20) NOT NULL,
  `member_id`     BIGINT UNSIGNED NOT NULL,
  `coupon_type`   TINYINT NOT NULL              COMMENT '1=10送1免餐券 2=赠券 3=生日券',
  `source`        TINYINT NOT NULL DEFAULT 1    COMMENT '1=满次自动 2=满额自动 3=后台手工',
  `amount_cents`  INT NOT NULL DEFAULT 0        COMMENT '面额（分）；0=免一份套餐，按核销时实际套餐价抵扣',
  `progress_at_grant` INT NOT NULL DEFAULT 0    COMMENT '发放时的进度快照，便于对账与申诉',
  `note`          VARCHAR(200) DEFAULT NULL     COMMENT '手工发放的原因',
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

### 5.1 发券由 `RewardService` 负责

发分成功后调用 `checkAndGrant()`，按下式判断该不该发：

```
应发 = floor(进度 / 阈值)     进度 = member.visit_count 或 total_spent
待发 = 应发 − member.rewards_issued
```

用「应发 − 已发」而不是「每次 +1」,是为了**自愈**：店家改阈值或事后补录
历史消费后，数量会自动对上，既不重复发也不漏发。规则与配置见 `03` §5。

`source` 区分券的来路。**后台手工发放（`source=3`）不计入 `rewards_issued`** ——
否则补偿性质的一张会顶掉客人靠消费攒来的那张。

`idx_member_status (store_code, member_id, status)` 供 Pad 每次选中会员时
快速查「有几张可用券」,这是高频查询。

### 5.2 过期不靠定时任务

`expireStale()` 在每次查券时顺手把 `valid_to < 今天` 的置为已过期。
不单开 Cron 任务 —— 券的过期不需要即时性，查的时候顺带处理即可。

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
| `visit_count_mode` | `by_portion` | 计次口径：**`by_portion`=按套餐份数（已确认采用）**／`by_ledger`=每笔流水最多 1 次 |
| `reversal_window_hours` | `24` | 自由撤销时间窗，超出需经理权限 |
| `verify_protect_days` | `30` | 值比对保护期 |
| `sync_window_hours` | `48` | 滚动校准窗口 |
| `consent_expire_days` | `30` | 未同意的会员积分冻结期限 |
| `meal_item_alert_price` | `8.00` | 规则表巡检阈值：**全表**扫 `price_1 ≥ 此值` 且未被规则表覆盖的项 → 提醒。**不可按 `major_group` 过滤**（BOX/COMBO 在 `major_group=1`）。阈值取 8.00 是因为 `BOX 1` 2024 年售价 9.00，取 10.00 会漏。当前 `menu_item` 中 ≥8.00 的共 61 项，初始化时一次性归类即可。见 `03` §5.4 |
| `business_day_cutoff` | `02:00` | 营业日切点（已用 POS 数据验证，见 `01` §5.2） |
| `manual_entry_enabled` | `1` | 是否允许降级手工录入 |
| `manual_entry_limit` | `200.00` | 手工录入单笔金额上限，超出需审批 |
| `manual_entry_daily_alert` | `5` | 同一员工单日手工录入超过此数 → 告警 |
| `lookup_fallback_window_min` | `60` | 首次查不到时的放宽时间窗 |

### 6.2 套餐规则表 `meal_item_rule`（后台可维护）

「哪些菜品算餐费 / 参与十送一 / 计入积分」是三个**互相独立**的开关，且需要按菜品逐项配置（`MENÚ DEL DIA` 不计次、儿童套餐是否计次可调）。因此不用硬编码数组，改为后台可维护的规则表。

```sql
CREATE TABLE `meal_item_rule` (
  `id`            INT NOT NULL AUTO_INCREMENT,
  `store_code`    VARCHAR(20) NOT NULL,
  `menu_item_id`  INT NOT NULL                COMMENT 'POS 的 menu_item.item_id',
  `item_name`     VARCHAR(60)  DEFAULT NULL   COMMENT '名称快照，仅供后台显示',
  `ref_price`     DECIMAL(11,2) DEFAULT NULL  COMMENT '参考价快照，仅供后台显示',

  `is_meal_fee`   TINYINT NOT NULL DEFAULT 1  COMMENT '是否算「餐费项」→ 免费餐判据用',
  `counts_visit`  TINYINT NOT NULL DEFAULT 1  COMMENT '是否参与十送一计次',
  `earns_points`  TINYINT NOT NULL DEFAULT 1  COMMENT '金额是否计入积分基数',

  `enabled`       TINYINT NOT NULL DEFAULT 1,
  `updated_at`    DATETIME NOT NULL,
  `updated_by`    INT DEFAULT NULL,

  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_item` (`store_code`,`menu_item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**初始数据**（来自 `01-POS主库数据字典.md` §4.2）：

| menu_item_id | 名称 | 参考价 | `is_meal_fee` | `counts_visit` | `earns_points` |
|---|---|---|---|---|---|
| `2590` | MENÚ INFINITY VIERNES NOCHE-FIN DE SEMANA | 25.90 | 1 | 1 | 1 |
| `25900` | TAKE WAY | 25.90 | 1 | 1 | 1 |
| `2390` | MENÚ INFINITY NOCHE LUNES A JUEVES | 23.90 | 1 | 1 | 1 |
| `1890` | MENÚ INFINITY MEDIODIA - ADULTOS | 18.90 | 1 | 1 | 1 |
| `1590` | **MENÚ DEL DIA** (Lunes - Jueves) | 15.90 | 1 | **0** | 1 |
| `1490` | MENU INFANTIL NOCHE FINDE SEMANA | 14.90 | 1 | **1** ⚙️ | 1 |
| `1290` | MENÚ INFINITY - INFANTIL MEDIODIA | 12.90 | 1 | **1** ⚙️ | 1 |

- `1590` **MENÚ DEL DIA**：`counts_visit = 0`（不参与十送一），金额积分默认计入
- `1490` / `1290` **儿童套餐**：⚙️ 后台可自由切换是否参与十送一，默认参与

```sql
-- ① 堂食套餐（参与积分体系）
INSERT INTO meal_item_rule
  (store_code, menu_item_id, item_name, ref_price, is_meal_fee, counts_visit, earns_points, enabled, updated_at) VALUES
('S001', 2590,  'MENÚ INFINITY VIERNES NOCHE-FIN DE SEMANA-FESTIVOS', 25.90, 1, 1, 1, 1, NOW()),
('S001', 25900, 'TAKE WAY',                                          25.90, 1, 1, 1, 1, NOW()),
('S001', 2390,  'MENÚ INFINITY NOCHE LUNES A JUEVES-ADULTOS',        23.90, 1, 1, 1, 1, NOW()),
('S001', 1890,  'MENÚ INFINITY MEDIODIA - ADULTOS',                  18.90, 1, 1, 1, 1, NOW()),
('S001', 1590,  'MENÚ DEL DIA (Lunes - Jueves)',                     15.90, 1, 0, 1, 1, NOW()),
('S001', 1490,  'MENU INFANTIL NOCHE FINDE SEMANA',                  14.90, 1, 1, 1, 1, NOW()),
('S001', 1290,  'MENÚ INFINITY - INFANTIL MEDIODIA',                 12.90, 1, 1, 1, 1, NOW());

-- ② 外卖产品线 BOX / COMBO（22 项）：三个开关全 0，金额也不计入积分
--    业务规则：BOX 属外卖产品，堂食客人不可点；若堂食单中出现，仍按外卖处理，一律不计入
INSERT INTO meal_item_rule
  (store_code, menu_item_id, item_name, ref_price, is_meal_fee, counts_visit, earns_points, enabled, updated_at) VALUES
('S001', 6049, 'COMBO XL', 65.00, 0, 0, 0, 1, NOW()),
('S001', 1018, 'BOX 18',   46.50, 0, 0, 0, 1, NOW()),
('S001', 6047, 'COMBO L',  45.00, 0, 0, 0, 1, NOW()),
('S001', 1014, 'BOX 14',   39.50, 0, 0, 0, 1, NOW()),
('S001', 6053, 'COMBO M',  35.00, 0, 0, 0, 1, NOW()),
('S001', 1017, 'BOX 17',   26.50, 0, 0, 0, 1, NOW()),
('S001', 1013, 'BOX 13',   25.50, 0, 0, 0, 1, NOW()),
('S001', 1016, 'BOX 16',   23.50, 0, 0, 0, 1, NOW()),
('S001', 1009, 'BOX 9',    20.50, 0, 0, 0, 1, NOW()),
('S001', 6052, 'COMBO S',  20.00, 0, 0, 0, 1, NOW()),
('S001', 1005, 'BOX 5',    19.50, 0, 0, 0, 1, NOW()),
('S001', 1006, 'BOX 6',    19.50, 0, 0, 0, 1, NOW()),
('S001', 1007, 'BOX 7',    19.50, 0, 0, 0, 1, NOW()),
('S001', 1008, 'BOX 8',    19.50, 0, 0, 0, 1, NOW()),
('S001', 1011, 'BOX 11',   19.50, 0, 0, 0, 1, NOW()),
('S001', 1012, 'BOX 12',   19.50, 0, 0, 0, 1, NOW()),
('S001', 1010, 'BOX 10',   16.50, 0, 0, 0, 1, NOW()),
('S001', 1015, 'BOX 15',   16.50, 0, 0, 0, 1, NOW()),
('S001', 1003, 'BOX 3',    13.50, 0, 0, 0, 1, NOW()),
('S001', 1004, 'BOX 4',    13.50, 0, 0, 0, 1, NOW()),
('S001', 1002, 'BOX 2',    12.50, 0, 0, 0, 1, NOW()),
('S001', 1001, 'BOX 1',    10.00, 0, 0, 0, 1, NOW());
```

### 6.2.1 外卖产品线 BOX / COMBO

**业务规则：BOX 属外卖产品，堂食客人不可点。若堂食订单中出现 BOX，仍按外卖处理，一律不计入。**

三个开关全部为 `0`，其中 **`earns_points = 0` 是关键** —— 它会让该行金额通过 §2.3 的按比例扣除机制从积分基数中剔除。

| 场景 | 处理 |
|---|---|
| 外带订单（`eat_type = 3`）含 BOX | 整单本就不积分 |
| **堂食订单（`eat_type = 0`）含 BOX** | **该 BOX 行金额从积分基数扣除，不计次** |
| 堂食订单**全部**是 BOX | 排除金额 = 全额 → 积分基数 = 0 → **不积分** ✅ |

**实测佐证**：跨 2024-01 与 2026-08 两个时间窗、220 个订单，**20 行 BOX/COMBO 全部出现在 `eat_type = 3`（`table_name = 'Llevar'`）订单中，堂食 0 行**，与业务规则一致。

> 📌 `COMBO S/M/L/XL` 按与 BOX 同一产品线处理（同属 `major_group = 1` / `family_group = 7`、同价位区间，实测唯一一行 `COMBO XL` 也出现在外带单）。

**三个开关的用途**：

| 开关 | 用途 |
|---|---|
| `is_meal_fee` | 免费餐兜底判据（`03` §5.2 第二层）：该订单的餐费项金额合计是否为 0 |
| `counts_visit` | 计次：`SUM(quantity)` where `counts_visit = 1`（`03` §3.2） |
| `earns_points` | 积分基数扣除：`earns_points = 0` 的项，其金额按比例从基数中扣除（`03` §2.3） |

> 该表**取代**了原先的 `/app/config/meal_items.php` 硬编码数组、`sys_config.meal_item_whitelist` 与 `sys_config.menu_del_dia_earns_points`。

**未被本表覆盖的菜品，按安全默认值处理**：`is_meal_fee = 0`、`counts_visit = 0`、`earns_points = 1`（正常积分、不计次、不参与免费餐判据）。漏配的后果仅是少计次，不会算错金额。

> ✅ **`BOX` / `COMBO` 共 22 项已归类**：外卖产品线，三个开关全 `0`，见 §6.2.1。

### 6.3 餐期配置 `meal_period`

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
