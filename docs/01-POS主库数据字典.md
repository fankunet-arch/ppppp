# 01 · POS 主库数据字典

> 主库：`192.168.2.40:3308` · MySQL **5.5.47** · 库名 `coolroid` · 190 张表 · 字符集 `utf8`（3 字节，非 utf8mb4）
>
> 本文档所有"实测"结论基于 `history_order_head` 全量 **88,616 行**（2024-01-22 ~ 2026-08-13，927 个营业日）。

## 1. 我们只关心的表

| 表 | 用途 | 行数量级 | 读取方式 |
|---|---|---|---|
| `history_order_head` | **主数据源**：已结账订单头 | 88,616 | 时间范围扫 + 主键单点查 |
| `history_order_detail` | 订单明细（AA 点选菜品、免费餐兜底判据） | ~138 万 | **仅**按 `(order_head_id, check_id)` 单点查 |
| `menu_item` | 菜品分类（区分餐费/饮料） | 数千 | 启动时全量缓存到本地，之后不再读 |
| `major_group` / `family_group` | 分组名称字典 | 各几十行 | 启动时全量缓存 |

**其余 186 张表一律不读。**

## 2. `history_order_head` 字段字典

### 2.1 定位与识别

| 字段 | 类型 | 实测结论 | 用途 |
|---|---|---|---|
| `serial_id` | `int unsigned` | **`YYMMDD` + 4 位当日流水**，如 `2401220001`。与 `order_head_id` 严格 **1:1**（81,909 : 81,909，0 例外） | ✅ **业务主键** |
| `order_head_id` | `int` | `AUTO_INCREMENT` 代理键。范围 9932~92327，缺口 0.59% | ⚠️ 仅用于关联明细，不做业务键 |
| `check_id` | `int` | 分单号。`(order_head_id, check_id)` 实测 0 重复 | 关联明细 |
| `check_number` | `int` | **1~999 循环**。857/927 天存在同日重复，加 `pos_device_id` 仍有 2% 重复 | ❌ **不可用作标识** |
| `table_name` | `varchar(30)` | 堂食为桌号（90 种），外带恒为 `'Llevar'`（10,421 单）或 `'Paquetear'`（74 单） | 定位条件 |
| `table_id` | `int` | 桌位 ID | — |

#### `serial_id` 的两个关键性质

**性质 A — 在结账时点分配，按结账先后严格递增。**

| serial_id | order_head_id | order_end_time |
|---|---|---|
| 2608130079 | 92326 | 23:07:28 |
| 2608130080 | 92319 | 23:11:47 |
| 2608130081 | 92322 | 23:16:12 |
| 2608130085 | 92314 | 23:25:02 |
| 2608130086 | 92327 | 23:26:08 |

`order_head_id` 是**开台顺序**（乱序），`serial_id` 是**结账顺序**（严格递增）。这也解释了全表 4,224 条 `order_head_id` 与时间的"逆序"——不是时钟偏差，是开台序与结账序的差别。

**性质 B — 日期部分是「结账自然日」，不是「营业日」。**

实测 **1,072 单**为"前一天开台、零点后结账"，其 `serial_id` 归到第二天。例：

```
order_start_time = 2024-01-26 21:44:54
order_end_time   = 2024-01-27 00:01:43
serial_id        = 2401270001   ← 归到 1 月 27 日第 1 单
```

> ⚠️ **作为幂等主键完全可用**（唯一性不受影响）。
> ⚠️ **不可直接取前 6 位当营业日**。后台按餐期/营业日统计、积分按日清零时，必须用配置的餐期规则自行计算营业日。

### 2.2 金额字段

| 字段 | 实测 | 说明 |
|---|---|---|
| `original_amount` | 98.13% 非零 | 原价 |
| `discount_amount` | 4.79% 非零，**全部为负数**，最小 -310.70 | 折扣（负值） |
| `should_amount` | 98.12% 非零 | **应收**。恒等式 `original + discount = should` 成立，**0 例外** |
| `actual_amount` | 98.12% 非零 | **实收**。147 行（0.17%）大于 `should_amount` |
| `tax_amount` | 98.14% 非零 | IVA。`SUM(tax)/SUM(should) = 9.09%` → **IVA 10% 内含**（10/110） |
| `return_amount` | **恒为 0** | ❌ 全表 0 行非零 |
| `service_amount` | **恒为 0** | ❌ 未启用 |
| `tips_amount` | **恒为 0** | ❌ 未启用 |
| `member_discount` | **恒为 0** | ❌ 未启用 |
| `delivery_fee` | **恒为 0** | ❌ 未启用 |
| `second_tax_amount` | **恒为 0** | ❌ 未启用 |

#### `actual_amount > should_amount` 的 147 行 = 现金抹零

| order_head_id | should | actual | 差额 |
|---|---|---|---|
| 11192 | 211.70 | 215.00 | +3.30 |
| 13419 | 125.45 | 126.00 | +0.55 |
| 11689 | 61.15 | 62.00 | +0.85 |
| 11212 | 178.95 | 178.98 | +0.03 |

客人付整数不找零（隐性小费）或刷卡舍入。**积分基数取 `LEAST(should_amount, actual_amount)`**，两种场景一次覆盖。

#### `actual_amount = 0` 的 1,666 行（1.88%）

- `should_amount` 同为 0
- 按月分布均匀（每月 40~78 单），**贯穿全程，不是早期测试数据**
- 业务含义：员工餐 / 招待 / 作废单
- **一律不积分、不计次**

### 2.3 状态与分类

| 字段 | 实测 | 说明 |
|---|---|---|
| `status` | **恒为 2**（88,616 行无第二取值） | ❌ 不能用于判断结账状态。**能出现在本表即等于已结账** |
| `eat_type` | `0`=堂食 88.15% / `3`=外带 11.84% / `1`=3 单 / `2`=4 单 | ✅ 用**白名单** `eat_type = 0` |
| `is_divide` | **恒为 0** | ❌ 该字段未被这套 POS 使用。真实分单需靠"同 `order_head_id` 有多个金额>0 的 `check_id`"判断 |
| `customer_id` | `4`=78,110 / `2`=10,491 | ⚠️ **不是会员 ID**，被挪用为订单类型码（4≈堂食，2≈外带） |
| `customer_name` | **全空** | POS 自带会员模块完全未启用 |
| `source` / `online_txid` / `offline_payment` | **全空** | 无外卖平台/线上订单接入 |
| `customer_num` | 2 人=42,089 / 1 人=13,895 / 3 人=12,453 | 就餐人数，AA 均摊时可作默认值 |
| `edit_time` | 2,569 行非空（2.9%），其中 1,144 行晚于结账 30 分钟以上 | **结账后修改的唯一信号**，但含噪声（存在 2026 年批量刷 2024 年订单的记录） |

### 2.4 时间字段

| 字段 | 说明 |
|---|---|
| `order_start_time` | 开台时间。有 `ids_order_start_time` 索引 |
| `order_end_time` | **结账时间**。有 `idx_order_end_time` 索引 → **所有时间范围查询走这个字段** |

**营业时段实测**（按 `order_end_time` 小时分布）：

```
13:00  ███                      1,040
14:00  ████████████████████████ 8,409
15:00  ████████████████████████████████████████████████ 16,802
16:00  ████████████████████████████████████████████████████████████ 22,875   ← 午市峰值
17:00  █████                    1,665
20:00  ████                     1,390
21:00  ██████████████████████   8,783
22:00  ████████████████████████████████████████ 14,612   ← 晚市峰值
23:00  ████████████████████████████████ 11,803
00:00  ███                      1,036   ← 跨零点
01:00                              27
02:00 - 10:00                        0   ← 完全无营业
```

> **定时任务窗口：03:00 – 05:00**（实测该时段 0 单）。

**负载基线**：日均 95.6 单，P50 = 91，P95 = 159，峰值 205。单个 30 分钟窗口内最多 54 单结账。

## 3. `history_order_detail` 字段字典

> ~138 万行（活动表 `order_detail_id` 已到 1,387,928，历史表从 1 起连续）。
> **绝对禁止全表扫描。仅允许按 `(order_head_id, check_id)` 走 `idx_detailcheck` 单点查。**

### 3.1 必须过滤掉的伪行

明细表里混着大量**非菜品行**，任何金额计算都必须先过滤：

| 条件 | 含义 | 实例 |
|---|---|---|
| `menu_item_id = -3` | 操作留痕行 | `'**999 Enviado 19:16**'`、`'**555 下单 22:09**'` |
| `menu_item_id = -4` | **支付方式行** | `'EFECTIVO'`（现金） |
| `menu_item_id < 0`（其他） | 其他系统行 | — |
| `condiment_belong_item != 0` | 配料/做法行 | `S/Pepino`（不要黄瓜）、`无鸡` |
| `is_return_item + 0 = 1` | 已退菜行 | — |

> 💡 `menu_item_id = -4` 的行**免费提供了订单支付方式**。若后台规则需要"现金不积分/刷卡加倍"，数据现成可用。

**标准过滤条件**：

```sql
WHERE order_head_id = ? AND check_id = ?
  AND menu_item_id > 0
  AND condiment_belong_item = 0
  AND is_return_item + 0 = 0
```

### 3.2 退菜机制（关键）

| 字段 | 说明 |
|---|---|
| `is_return_item` | `bit(1)`。退菜标记 |
| `return_time` | 退菜时间。有 `idx_return_time` 索引 |
| `return_reason` | 退菜原因，如 `'Doblado'`（重复下单） |
| `actual_price` | ⚠️ **退菜后不清零**，仍保留原价 |

**实测三条结论：**

1. **退菜是原地 UPDATE 原行，不是新增负数行。**
2. **退菜 100% 发生在结账之前。** 明细样本 14 条退菜行，`return_time` 全部 ≤ `order_end_time`（订单 9934：退菜后 8 秒才结账）。
3. **`history_order_head.should_amount` 已是扣除退菜后的净额。** 订单 9934 全单退 → `should_amount = 0.00`。

**佐证：** 主库存在触发器

```sql
CREATE TRIGGER `trigger_history_order_detail_update`
AFTER UPDATE ON `history_order_detail` FOR EACH ROW BEGIN
	update history_order_head set status=1 where order_head_id=NEW.order_head_id;
END
```

若历史明细在归档后被修改，`status` 会变成 1。而全表 88,616 行 `status` **恒为 2** —— 说明 **927 天里历史明细从未被归档后修改过**。

> 🔴 **因此：按 `return_time` 增量扫描来触发扣分，会对早已在净额中扣除的退菜重复扣分。** 正确做法见 `03-积分与防刷引擎.md` §6。

## 4. `menu_item` 分类结构

> ⚠️ **这是一家「MENÚ INFINITY」无限量套餐店（日式自助）。**

| family / major | 项数 | 单价为 0 的比例 | 内容 |
|---|---|---|---|
| `family=8, major=3` | 19 | — | **套餐主项**：`MENÚ INFINITY NOCHE=23.90`、`MENÚ DEL DIA=15.90`、`INFANTIL=12.90`；**混有** `Tenedor`、`Wasabi`、`Suplemento` |
| `family=3/4/5/7/9, major=1` | 305 | **86.9%** | **套餐内可无限点的菜**：Edamame、Sushi、Gyoza |
| `family=1, major=2` | 138 | 17.2% | 饮料酒水甜点：`Agua=2.95`、`Verdejo=13.95`、`Tiramisú=5.95` |
| `family=1/9/8, major=4` | 37 | 92.1% | 冰品甜点 + 备注项 |
| `family=-1, major=-1` | 147 | 87.8% | 配料/做法 |

**关键推论：**

- 「单行 `actual_price = 0`」在这家店是**绝对常态**（套餐内菜品），❌ **不能作为免费餐判据**
- 真正有金额的只有：**套餐主项** + **饮料酒水甜点**
- `major_group` 单独用不足以区分餐费/饮料（`Agua` 与单点菜同为 `major=2`），必须结合 `family_group`
- `family=8, major=3` 组内混有餐具/调料，**不能整组当餐费**

> 🔴 **待补**：需要 `major_group` / `family_group` 两张表的数据（各几十行）以确定分组名称，进而配置"餐费项"白名单。见 `README.md` 待确认事项 #1。

## 5. 索引清单（决定所有查询写法）

### `history_order_head` — **无主键**，仅以下 KEY

```sql
ADD KEY `idx_headcheck`        (`order_head_id`,`check_id`) USING BTREE
ADD KEY `idx_order_end_time`   (`order_end_time`)           USING BTREE   ← 时间范围查询走这里
ADD KEY `ids_order_start_time` (`order_start_time`)
```

### `history_order_detail` — **无主键**，仅以下 KEY

```sql
ADD KEY `idx_detailcheck` (`order_head_id`,`check_id`) USING BTREE   ← 明细单点查走这里
ADD KEY `idx_return_time` (`return_time`)
ADD KEY `idx_order_time`  (`order_time`)
ADD KEY `idx_detail`      (`order_detail_id`)          USING BTREE
ADD KEY `idx_condiment`   (`condiment_belong_item`)    USING BTREE
ADD KEY `idx_kds`         (`is_make`,`order_time`)     USING BTREE
```

### `order_head`（活动表）— ⚠️ **没有任何时间索引**

```sql
ADD PRIMARY KEY (`order_head_id`,`check_id`)
ADD KEY `table_id` (`table_id`) USING BTREE
```

> 🔴 **绝对不要按时间范围查询 `order_head`** —— 必然全表扫描。且已确认归档为"结账即写"，无需读活动表。

### 归档时机结论

`serial_id` 的日期计数器在**午夜整点、营业进行中**就翻页（21:44 开台、00:01 结账的单拿到 `2401270001`）。这是**结账瞬间分配流水号**的行为，而非日结批处理。

**结论：POS 在结账时即写入 `history_order_head`，非日结批量搬运。** 30 分钟窗口查询前提成立。

## 6. MySQL 5.5 特有限制（写代码前必读）

| 限制 | 影响 | 对策 |
|---|---|---|
| **无 `MAX_EXECUTION_TIME`**（5.7.4+ 才有） | 服务端无法掐断慢查询 | 客户端超时兜底：`mysqli` + `MYSQLI_OPT_READ_TIMEOUT`（`PDO::ATTR_TIMEOUT` 只管连接不管查询） |
| 无 CTE、无窗口函数 | 复杂 SQL 写不了 | SQL 保持朴素，聚合逻辑放 `/app` 侧 |
| `bit(1)` 读取返回二进制字节 | `is_return_item` 等判断会出错 | SQL 中写 `is_return_item + 0 AS is_return_item` |
| 字符集 `utf8`（3 字节） | emoji 会截断 | 本地库用 `utf8mb4`；读取时做兼容 |
| `datetime` 无时区 | 跨机器时钟/夏令时偏差 | **时间基准一律用主库 `NOW()`**，绝不用 PHP 服务器时间 |
| `GROUP BY` 易建临时表 | 增加 POS 负担 | 尽量不在 POS 侧聚合，取回后在 `/app` 聚合 |

## 7. 坑位速查表

| # | 坑 | 后果 | 规避 |
|---|---|---|---|
| 1 | `return_amount` 恒为 0 | 依赖它做退单检测 → 永不触发 | 用值比对 |
| 2 | `status` 恒为 2 | 依赖它筛"已结账" → 无字段可依据 | 出现在历史表即已结账 |
| 3 | 明细含 `menu_item_id<0` 伪行 | 金额算错 | 过滤 `menu_item_id > 0` |
| 4 | 明细含 condiment 配料行 | 金额重复计算 | 过滤 `condiment_belong_item = 0` |
| 5 | 退菜行 `actual_price` 不清零 | 净额算高 | 过滤 `is_return_item + 0 = 0` |
| 6 | 套餐内菜品单价恒为 0 | 误判成免费餐 | 免费餐判据用订单级"餐费项合计" |
| 7 | `order_head` 无时间索引 | 全表扫，POS 卡死 | 只读 `history_order_head` |
| 8 | `check_number` 1~999 循环 | 当唯一号 → 撞车 | 不用 |
| 9 | `serial_id` 日期是结账日非营业日 | 营业日统计错位 | 按餐期规则自行计算营业日 |
| 10 | `bit(1)` 读成二进制 | 布尔判断反转 | `+ 0` 转换 |
| 11 | `is_divide` 恒为 0 | 依赖它判分单 → 永远漏 | 靠多 `check_id` 且金额>0 判断 |
| 12 | `history_payment` 无 `order_head_id` | 关联不到单笔订单 | 订单级支付在 `payment` 表；或读明细 `menu_item_id=-4` 行 |
