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
  `original_amount`   DECIMAL(11,2) NOT NULL DEFAULT 0    COMMENT '折扣前金额；订单级折扣要按它做分母等比扣除（01 §3.3.1）',
  `should_amount`     DECIMAL(11,2) NOT NULL DEFAULT 0,
  `actual_amount`     DECIMAL(11,2) NOT NULL DEFAULT 0,
  `tax_amount`        DECIMAL(11,2) NOT NULL DEFAULT 0    COMMENT 'POS 税额快照；points_include_tax=0 时据此折算不含税价',
  `total_amount`      DECIMAL(11,2) NOT NULL DEFAULT 0 COMMENT '可积分总额 = LEAST(should, actual)',
  `allocated_amount`  DECIMAL(11,2) NOT NULL DEFAULT 0 COMMENT '已分配金额',
  `allocated_portions` SMALLINT     NOT NULL DEFAULT 0 COMMENT '已分配份数，与金额一样受守恒约束',

  -- 状态
  `is_free_meal`      TINYINT      NOT NULL DEFAULT 0 COMMENT '1=10送1 免费餐，不计次',
  -- 十送一核销（见 03 §6）。要区分「整单免」与「混合单」：
  -- portions_counted 是净份数，为 0 才是整桌都兑换来的
  `is_redeemed`       TINYINT      NOT NULL DEFAULT 0 COMMENT '1=这一单里打了核销折扣行',
  `redeem_amount`     DECIMAL(11,2) NOT NULL DEFAULT 0 COMMENT '核销折扣额，用它反推抵掉了几份',
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
  `card_no`        VARCHAR(32) NOT NULL             COMMENT '实体卡号，来自 card 库存表；权威在 card 表，这里冗余是为了按卡号查会员时不用多 join',

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

  -- 现场确认码（见 09 §八）。不用「点链接确认」是因为那需要一个公网可达的
  -- 端点接收点击，而门店网络是单向的 —— 只发一个 6 位码，客人当场报给收银员
  `consent_code_hash`    VARCHAR(255) DEFAULT NULL COMMENT '确认码的 hash，明文不落库',
  `consent_code_sent_at` DATETIME DEFAULT NULL,
  `consent_code_expires` DATETIME DEFAULT NULL,
  `consent_code_fail`    TINYINT NOT NULL DEFAULT 0 COMMENT '连续输错次数，超限需重发',
  `consent_channel`      VARCHAR(10) DEFAULT NULL   COMMENT 'sms / mail，码是从哪条路发出去的',

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

> ⚠️ **卡号这一档已改走 `/card/lookup`**（查 `card` 库存表）而不是 `/member/search`。
> 实体卡有四种状态，「查无此人」这一种答案不够用 —— 库存卡要引导去建会员，
> 作废卡要说清楚换一张，不是本店的卡要当场拒绝。详见 [`09-实体卡.md`](./09-实体卡.md)。

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
  `portions_counted`   SMALLINT NOT NULL DEFAULT 0   COMMENT '份数快照：counts_visit=1 的 SUM(quantity)，已扣除券抵掉的份数',
  `portions_uncounted` SMALLINT NOT NULL DEFAULT 0   COMMENT '份数快照：counts_visit=0 的套餐份数（DEL DIA / 儿童套餐等）',
  `excluded_amount` DECIMAL(11,2) NOT NULL DEFAULT 0 COMMENT 'earns_points=0 的项被扣除的金额',

  -- AA 记账方式
  `alloc_mode`      TINYINT DEFAULT NULL             COMMENT '1=整单 2=均摊AA 3=点选菜品',
  `alloc_detail`    TEXT DEFAULT NULL                COMMENT '模式3时记录认领的 menu_item 明细快照（JSON）',
  -- 多桌合并（同行分桌，见 03 §12.2）。NULL = 单桌记账，老流水全是这个
  `grant_group`     CHAR(16) DEFAULT NULL            COMMENT '同一次多桌合并产出的几笔共用一个值',

  -- 入账时那张卡的等级与【实际套用的】倍率（见 09 §10.3）
  -- 倍率是活查的，不在流水里定格的话，改一次倍率历史就再也对不上账
  `tier_code`       VARCHAR(20) DEFAULT NULL         COMMENT '当时的卡片等级码',
  `tier_multiplier` DECIMAL(4,2) DEFAULT NULL        COMMENT '当时实际套用的等级倍率',

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
  KEY `idx_review`  (`store_code`,`review_status`,`created_at`),  -- 待复核队列
  KEY `idx_group`   (`store_code`,`grant_group`)                  -- 整组撤销 / 风控计数
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

> 🔴 **`counted_visit` 怎么算，取决于 `visit_count_mode`。**
> 现行默认是「一张卡一个餐期最多 1 次」，见 §4.2 与 `03` §13。
> 这一列存的是**当时算出来的结果**，改口径不会重算历史。

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

**默认口径 `once_per_period`**：**一张卡，一个餐期，最多计 1 次**，
不管这一单给他分了几份套餐（完整规则见 `03` §13）。

`portions_counted` 仍然照实存该会员认领的、`meal_item_rule.counts_visit = 1`
的菜品的 `SUM(quantity)` —— 它是快照，不因口径而变；变的只是
`counted_visit` 怎么由它算出来。

| 场景 | `portions_counted` | `counted_visit`（现行 `once_per_period`） | 旧口径 `by_portion` |
|---|---|---|---|
| 整单记一人，3 份 INFINITY | 3 | **1** | 3 |
| 一桌 10 人 10 份，1 张卡 | 10 | **1** | 10 ⚠️ |
| AA 3 人，各 1 份 INFINITY | 1（每人） | **1**（每人） | 1（每人） |
| AA 3 人，2 份 INFINITY + 1 份 DEL DIA | 1/1/0 | **1 / 1 / 0** | 1 / 1 / 0 |
| 同一餐期该卡第二次记账 | 2 | **0**（积分照给） | 2 |
| 同一天换个餐期再来 | 2 | **1** | 2 |
| 只点单品无套餐 | 0 | **0**（金额照常积分） | 0 |

`portions_counted` / `portions_uncounted` 为快照字段，用于事后审计与口径切换时的重算。

🔴 **`portions_counted` 存的是【净】份数，不是明细里的原始份数。**
含十送一核销的订单，被券抵掉的那几份已经在写库前扣除
（抵掉几份从核销额反推，见 `03` §5.5）。这样设计的好处是
分配上限与计次口径天然一致 —— `validateAllocations` 直接用这个数就对了，
不必在每个调用点重复减一次。

举例：一桌 4 份、券抵 1 份 → 本字段存 **3**，明细里的 4 份不再出现在本表。
需要原始份数时看 POS 明细，或看 locate 返回的 `portions_total`。

> 📌 **2026-08 换过口径。** 原为 `by_portion`（吃 10 份套餐送 1 份），
> 现为 `once_per_period`（来 10 趟送 1 次）。起因是防刷：
> 旧口径下一张 10 人的小票一次顶 10 次计次，捡到一张就直接换一顿饭。
> 理由、影响与必须跟着改的店内告示见 **`03` §13**。
>
> 三种口径都保留在配置里（`once_per_period` / `by_portion` / `by_order`），
> `portions_counted` 快照支持切换后重算历史数据。
> **切换口径不重算已有流水** —— 每笔的 `counted_visit` 是当时定格的。

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
  -- 发券当刻定格的等级与门槛（见 09 §10.6）。门槛是活查的，
  -- 不定格的话改一次之后「这张券当初凭什么发的」就再也答不上来
  `tier_code`     VARCHAR(20) DEFAULT NULL      COMMENT '发券时该会员那张卡的等级',
  `threshold_used` INT DEFAULT NULL             COMMENT '发券时实际套用的门槛（次数或分）',
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

### 5.2 `valid_to` 是快照，不是派生值

发券当刻按当时的 `coupon_valid_days` 算好写进这一行。改配置**只影响以后发的券**，
已发的一律不动；`expireStale()` 也只比较券上的 `valid_to`。

因此 `coupon` 表里绝不该出现「按当前配置批量改写 valid_to」的语句 ——
那会让老客人手里的券凭空缩水或延长。测试里有断言守着这一点。

### 5.3 过期不靠定时任务

`expireStale()` 在每次查券时顺手把 `valid_to < 今天` 的置为已过期。
不单开 Cron 任务 —— 券的过期不需要即时性，查的时候顺带处理即可。

## 5bis. 实体卡 `card` 与卡片等级 `card_tier`

> 概念、发卡流程、有效期与告知见 [`09-实体卡.md`](./09-实体卡.md) 与
> [`11-卡片有效期与告知.md`](./11-卡片有效期与告知.md)。这里只列结构。

```sql
CREATE TABLE `card` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `store_code`   VARCHAR(20) NOT NULL,
  `card_no`      VARCHAR(32) NOT NULL  COMMENT '完整卡号，二维码内容与卡面印刷一致',
  `serial`       INT UNSIGNED NOT NULL COMMENT '顺序号，印刷与盘点用',
  `batch_no`     VARCHAR(32) NOT NULL  COMMENT '印刷批次，如 B20260822',

  `status`       TINYINT NOT NULL DEFAULT 0
                 COMMENT '0=库存中未激活 1=已激活绑定会员 2=已作废/挂失',
  `member_id`    BIGINT UNSIGNED DEFAULT NULL,

  -- 卡背刮开层下的 PIN。明文只出现在给印刷厂的清单里（印完即销毁），
  -- 库里只存 hash。二维码印在正面可被拍照，PIN 藏在刮层下 ——
  -- 抄了码的人不知道 PIN，所以兑换免费餐时验它
  `pin_hash`     VARCHAR(255) DEFAULT NULL COMMENT 'password_hash() 结果，绝不存明文',
  `pin_fail`     INT NOT NULL DEFAULT 0,
  `pin_locked_until` DATETIME DEFAULT NULL,

  -- 有效期（migration 008）。🔴 必须与卡面印刷的日期完全一致 ——
  -- 客人查不到任何线上信息，卡面那行日期就是唯一的告知证据
  `valid_to`     DATE DEFAULT NULL     COMMENT '卡面印的有效期至',
  `points_cleared_at` DATETIME DEFAULT NULL COMMENT '超宽限期后清分的时间（预留，尚无定时任务）',

  -- 卡片等级（migration 011）。等级属于【卡】不属于会员 —— 它印在卡面上，
  -- 换卡时跟着新卡走。挂在会员上会出现「卡面印银卡、系统说金卡」的错位
  `tier_code`    VARCHAR(20) DEFAULT NULL COMMENT '关联 card_tier.code；NULL = 不分级',

  `activated_at` DATETIME DEFAULT NULL,
  `activated_by` INT DEFAULT NULL,
  `voided_at`    DATETIME DEFAULT NULL,
  `void_reason`  VARCHAR(190) DEFAULT NULL,
  `created_at`   DATETIME NOT NULL,
  `updated_at`   DATETIME NOT NULL,

  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_card`   (`store_code`,`card_no`),
  UNIQUE KEY `uk_serial` (`store_code`,`serial`),
  UNIQUE KEY `uk_member` (`store_code`,`member_id`)   -- ★ 一人一卡，数据库层保证
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

> ★ `uk_member` 是**一人一卡**的硬保证。挂失换卡时必须**先清空旧卡的
> `member_id` 再绑新卡**，顺序反了会撞这条唯一键。历史留在 `audit_log` 里。

```sql
CREATE TABLE `card_tier` (
  `store_code`  VARCHAR(20) NOT NULL,
  `code`        VARCHAR(20) NOT NULL COMMENT '机器用的标识，如 std/silver/gold；定了就别改',
  `name`        VARCHAR(40) NOT NULL COMMENT '中文名，如「银卡」',
  `name_es`     VARCHAR(40) DEFAULT NULL COMMENT '西语名；为空则回落中文名',
  `sort_order`  INT NOT NULL DEFAULT 0,
  `points_multiplier` DECIMAL(4,2) NOT NULL DEFAULT 1.00
                COMMENT '叠在全局倍率之上：积分 = 金额 × 每欧元分数 × 全局倍率 × 本等级倍率',

  -- 按等级的奖励门槛（migration 012）。NULL = 跟随「奖励规则」里的全局设置，
  -- 只想优待金卡的店只填金卡那一格就行
  `threshold_visits` INT DEFAULT NULL           COMMENT '几次送 1 次；NULL=跟随全局',
  `threshold_amount` DECIMAL(11,2) DEFAULT NULL COMMENT '满额送 1 次；NULL=跟随全局',
  -- 按等级的券有效期（migration 013）。NULL=跟随全局，0=永久有效
  `coupon_valid_days` INT DEFAULT NULL          COMMENT '★ NULL 与 0 含义完全不同',

  `enabled`     TINYINT NOT NULL DEFAULT 1 COMMENT '停用只是不再出现在发卡下拉框里，老卡照常显示',
  `created_at`  DATETIME NOT NULL,
  `updated_at`  DATETIME NOT NULL,

  PRIMARY KEY (`store_code`,`code`),
  KEY `idx_sort` (`store_code`,`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

> 🔴 **`coupon_valid_days`：`NULL` ≠ `0`。** NULL 表示跟随全局，0 表示永久有效。
> 代码里判的一律是 `!== null` 而不是真值 —— 写成 `?:` 的话「永久」会被
> 当成「没设置」。详见 [`09`](./09-实体卡.md) §10.7。

## 5ter. 操作员 `operator` 与告警 `alert`

```sql
CREATE TABLE `operator` (
  `id`            INT NOT NULL AUTO_INCREMENT,
  `store_code`    VARCHAR(20) NOT NULL,
  `login_name`    VARCHAR(40) NOT NULL COMMENT '工号',
  `display_name`  VARCHAR(40) NOT NULL COMMENT '中文显示名',
  -- 西语显示名（migration 010）。为空则在西语界面回落中文名，
  -- 否则顶栏会中西混排
  `display_name_es` VARCHAR(40) DEFAULT NULL,
  `pin_hash`      VARCHAR(255) NOT NULL COMMENT 'password_hash()，绝不存明文',
  `role`          TINYINT NOT NULL DEFAULT 1 COMMENT '1=服务员 2=经理 3=管理员',
  -- 界面语言（migration 009）。★ 记在【账号】上不是记在平板上：
  -- 收银台的平板是共用的，中文和西语的员工换班轮着用同一台。
  -- NULL = 这个账号还没选过，跟随后台的 default_lang
  `lang`          VARCHAR(5) DEFAULT NULL,
  `failed_count`  INT NOT NULL DEFAULT 0 COMMENT '连续登录失败次数',
  `locked_until`  DATETIME DEFAULT NULL  COMMENT '锁定截止（防 4 位 PIN 被枚举）',
  `last_login_at` DATETIME DEFAULT NULL,
  `created_at`    DATETIME NOT NULL,
  `updated_at`    DATETIME NOT NULL,

  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_login` (`store_code`,`login_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `alert` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `store_code` VARCHAR(20) NOT NULL,
  `alert_type` VARCHAR(40) NOT NULL COMMENT 'free_meal_suspect / grant_many_per_day / grant_span_wide 等',
  `severity`   TINYINT NOT NULL DEFAULT 1 COMMENT '1=提示 2=警告 3=严重',
  `ref_type`   VARCHAR(20) DEFAULT NULL,
  `ref_id`     VARCHAR(40) DEFAULT NULL,
  `message`    VARCHAR(500) NOT NULL,
  `detail`     TEXT DEFAULT NULL COMMENT 'JSON 文本',
  `status`     TINYINT NOT NULL DEFAULT 0 COMMENT '0=未处理 1=已处理 2=已忽略',
  `handled_by` INT DEFAULT NULL,
  `handled_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL,

  PRIMARY KEY (`id`),
  KEY `idx_open` (`store_code`,`status`,`severity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

> `AlertRepo::raiseOnce()` 保证同一目标同一类型只要还未处理就不重复插入 ——
> 防刷那几条告警每记一次账都会判一遍，不去重会刷屏。

```sql
-- 会话。Pad 与后台共用同一张表、同一个 vip_session cookie
CREATE TABLE `operator_session` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `store_code`   VARCHAR(20) NOT NULL,
  `operator_id`  INT NOT NULL,
  `token_hash`   VARCHAR(64) NOT NULL COMMENT 'token 的 hash；明文只发给客户端，不落库',
  `ip`           VARCHAR(45) DEFAULT NULL,
  -- ★ 滑动续期：剩不到 4 小时时，任何一次请求都会把它续满 12 小时。
  --   原来是从登录起硬性 12 小时，晚市高峰正忙时突然掉线 ——
  --   对一台整天开着的收银平板没道理。真正的下线由「退出」和
  --   连续 12 小时没有任何请求来决定。
  `expires_at`   DATETIME NOT NULL,
  `revoked_at`   DATETIME DEFAULT NULL COMMENT '主动退出时打戳，不物理删除',
  `last_seen_at` DATETIME DEFAULT NULL,
  `created_at`   DATETIME NOT NULL,

  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_token` (`token_hash`),
  KEY `idx_op` (`store_code`,`operator_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 迁移登记。migrate 靠它判断哪些还没跑过
CREATE TABLE `schema_migration` (
  `filename`   VARCHAR(190) NOT NULL COMMENT '如 014_grant_group.sql',
  `applied_at` DATETIME NOT NULL,
  PRIMARY KEY (`filename`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

> ⚠️ 从别处导入 schema 时**必须手工补登记 `schema_migration`**，
> 否则下次 `migrate` 会把已经跑过的迁移再跑一遍并失败（见 `06` §3）。

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
| `points_mode` | `by_amount` | 积分口径：`by_amount`=按金额／`by_visit`=一次积一分。见 `03` §2.4 |
| `points_per_euro` | `1` | 每 1 欧元积分数（仅 `by_amount` 口径生效） |
| `points_per_visit` | `1.0` | 来一次积几分（仅 `by_visit` 口径生效） |
| `points_multiplier` | `1.0` | 积分倍率（1.0 = 不启用） |
| `min_amount_per_visit` | `5.00` | 计一次至少要分到多少钱。一分钱不能换一次「十送一」进度，见 `03` §3.1quinquies。填 0 = 不设门槛 |
| `manual_entry_min` | `1.00` | 手工录入的最低金额。按次数积分口径下自动抬到与上一项的较大值，见 `03` §10.3 |
| `manual_entry_daily_cap` | `300.00` | 单个员工每天手工录入的**累计**上限，超了经理也不能放行。原风控只数笔数，管不住金额，见 `03` §10.3 |
| `points_include_tax` | `1` | 积分按含税价（已确认为 1） |
| `free_meal_extra_earns` | `0` | 免费餐当次的额外消费（饮料甜品）是否计金额积分 |
| `visit_count_mode` | `once_per_period` | 计次口径：**`once_per_period`=一张卡一个餐期 1 次（现行，见 `03` §13）**／`by_portion`=按套餐份数（旧默认）／`by_order`=每笔流水最多 1 次 |
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

> 🔴 **这张表从 001_init 就建好了，但直到 2026-08 才有代码读它**
> （`app/lib/MealPeriod.php`）。此前营业日一直是按固定的 02:00 切点算的，
> 餐期只是个摆设。
>
> 现在它决定「一张卡一个餐期最多 1 次」里的**餐期**边界（`03` §13）。
> **一个餐期都不配的话，系统只能退回按整个营业日算** ——
> 于是「中午来一次、晚上又来一次」被当成同一顿，客人少拿一半次数，
> 而且没有任何地方会报错。所以后台顶栏会挂一条常驻红条
> （`meal_period_missing`）提醒补配。

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
