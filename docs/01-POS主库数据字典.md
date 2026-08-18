# 01 · POS 主库数据字典

> 主库：`192.168.2.40:3308` · MySQL **5.5.47** · 库名 `coolroid` · 190 张表 · 字符集 `utf8`（3 字节，非 utf8mb4）
>
> 本文档所有"实测"结论基于 `history_order_head` 全量 **88,616 行**（2024-01-22 ~ 2026-08-13，927 个营业日），
> 明细结论基于 **6,694 行** `history_order_detail`（2024-01 与 2026-08 两个时间窗，已交叉复核一致）。

## 1. 我们只关心的表

| 表 | 用途 | 行数量级 | 读取方式 |
|---|---|---|---|
| `history_order_head` | **主数据源**：已结账订单头 | 88,616 | 时间范围扫 + 主键单点查 |
| `history_order_detail` | 订单明细（AA 点选菜品、免费餐兜底判据） | ~138 万 | **仅**按 `(order_head_id, check_id)` 单点查 |
| `menu_item` | 菜品分类（区分餐费/饮料） | 数千 | 启动时全量缓存到本地，之后不再读 |
| `major_group` / `family_group` | 分组名称字典 | 各几十行 | 启动时全量缓存 |

**其余 186 张表一律不读。**

### 1.1 ⚠️ 易混字段对照（写代码前先看这张表）

订单头（`*_head`）与订单明细（`*_detail`）的金额字段命名相似但**层级完全不同**，混用会直接算错钱：

| 字段 | 所在表 | 层级 | 含义 | 是否需乘 `quantity` |
|---|---|---|---|---|
| `actual_amount` | `history_order_head` | **订单级** | **收款额（含待找零部分）** ⚠️ 见 §2.2 | ❌ 否 |
| `should_amount` | `history_order_head` | **订单级** | 整单应收总额 | ❌ 否 |
| `original_amount` | `history_order_head` | **订单级** | 整单原价 | ❌ 否 |
| `discount_amount` | `history_order_head` | **订单级** | 整单折扣（负值） | ❌ 否 |
| **`actual_price`** | **`history_order_detail`** | **明细行级** | **行小计（已含 `quantity`）** | ❌ **否** ⚠️ 见 §3.3 |
| **`product_price`** | **`history_order_detail`** | **明细行级** | **单价** | （`× quantity` 即得 `actual_price`）|
| **`original_price`** | **`history_order_detail`** | **明细行级** | 单行原价（仅部分折扣记法下填） | — |
| `quantity` | `history_order_detail` | 明细行级 | 该行份数（实测有 2、3、4、5） | 计次用 `SUM(quantity)` |

> 🔴 **`actual_price` 已经是行小计，绝不可再乘 `quantity`** —— 那会让多份行的金额被平方级放大（3 份套餐 71.70 × 3 = 215.10）。见 §3.3 实测结论。

记忆规则：**`_amount` = 订单级（`*_head` 表）；`_price` = 明细行级（`*_detail` 表）。
明细里 `product_price` 是单价，`actual_price` 是行小计。**

> 全库仅 3 张表有 `actual_price`：`history_order_detail`、`order_detail`（活动表，同结构）、`miti`（无关表，不读）。
> 全库仅 4 张表有 `actual_amount`：`history_order_head`、`order_head`、`history_day_end`、`ecocash_order`（后两张不读）。

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
| `actual_amount` | 98.12% 非零 | ⚠️ **收款额，不是净实付**。见下方专节 |
| `tax_amount` | 98.14% 非零 | IVA。`SUM(tax)/SUM(should) = 9.09%` → **IVA 10% 内含**（10/110） |
| `return_amount` | **恒为 0** | ❌ 全表 0 行非零 |
| `service_amount` | **恒为 0** | ❌ 未启用 |
| `tips_amount` | **恒为 0** | ❌ 未启用 |
| `member_discount` | **恒为 0** | ❌ 未启用 |
| `delivery_fee` | **恒为 0** | ❌ 未启用 |
| `second_tax_amount` | **恒为 0** | ❌ 未启用 |

#### ⚠️ `actual_amount` 是「收了客人多少钱」，**包含待找零部分**

这不是净实付额。收银员录入客人递过来的金额，找零由 POS 计算后返还，但 `actual_amount` 记的是**收款额**。

实测 **172 行（0.19%）** `actual_amount > should_amount`，差额模式清晰印证这一点：

| `should_amount` | `actual_amount` | 差额 | 解读 |
|---|---|---|---|
| 176.50 | 191.50 | **+15.00** | 多递一张 10 + 一张 5 |
| 105.35 | 115.35 | **+10.00** | 多递一张 10 |
| 124.95 | 134.95 | **+10.00** | 多递一张 10 |
| 47.70 | 52.70 | **+5.00** | 多递一张 5 |
| 31.28 | **40.00** | +8.72 | 递整钞 40 |
| 121.50 | **130.00** | +8.50 | 递整钞 130 |
| 127.85 | **135.00** | +7.15 | 递整钞 135 |

**佐证：**

- 88 个大额差（≥0.10）中 **57 个 `actual_amount` 为整数**（整 10 倍 23 个、整 5 倍 12 个、其他整数 22 个）
- **`actual_amount < should_amount` 全表仅 1 行** —— 系统性地「收款额 ≥ 应收额」，符合收款逻辑
- 另有 84 行差额 < 0.10（如 +0.10、+0.08），属**分币舍入**（西班牙 1/2 分硬币不流通时抹到 5 分位），与找零无关

> 🔴 **因此绝不能用 `actual_amount` 直接作为积分基数** —— 会把找零算成消费额。最大一笔差额 +27.40（`should=27.40 → actual=54.80`，正好 2 倍，疑似重复收款录入），若按 `actual_amount` 积分会多给一倍。
>
> **积分基数取 `LEAST(should_amount, actual_amount)`**：正常单两者相等；有找零时取 `should_amount`（真实消费额）；10送1 免费餐时两者皆为 0。一个表达式覆盖全部场景。

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

### 2.9 小票字段 → 数据库列对照（两张实物小票实测）

样本：2026-08-16 的两张小票，Mesa 49 / Mesa 33。

| 小票字段 | 数据库列 | 唯一性 |
|---|---|---|
| `Factura Simplificada` | **`order_head_id`** | 🟢 **全局唯一**（订单级；分单时多张 check 共用同一个） |
| `Número Ticket` | **`check_number`** | 🔴 **当日会重复** —— 午市/晚市各跑一轮计数 |
| `Mesa` | `table_name` | 不唯一（翻台） |
| `Fecha y Hora` | `order_start_time`（**开台**，非结账） | — |
| `Comensal` | `customer_num` | — |
| `Camarero` | `open_employee_name` | — |

**实测证据**

- `Factura Simplificada 000092518 / 000092521` → 明细表里确有 `order_head_id = 92518 / 92521`，
  首行下单时间 20:24:26 / 20:33:32，与小票印的 20:24:22 / 20:33:24 相差 4~8 秒（先开台后下单）。
  同日下午的 12 单 `order_head_id` 为 92489–92503，晚市涨到 92518/92521，连号吻合。
- `Número Ticket` = `check_number`：全量 4,786 单中 **`serial_id` 与 `check_number` 严格一一对应**（0 例外）。
- 🔴 但 `check_number` **当日不唯一**：63 天里有 11 天重号。
  实例 2026-08-03：票号 323 在 13:05 发过一次（桌 73），21:36 又发一次（Llevar）。
  **故「票号 + 日期」不能作为唯一键**，只能用作校验。

### 2.10 serial_id 的生成规则（已实测确认）

`serial_id` = `YYMMDD` + 4 位当日序号，**在结账时按 `order_end_time` 先后顺序分配**，
堂食与外带共用同一个计数器。

2026-08-16 当天 12 单，序号 0001–0012 与按结账时间排名 **1:1 完全吻合**：

| serial | 结账时间 | 桌 |
|---|---|---|
| 2608160001 | 13:41:22 | Llevar |
| 2608160002 | 13:54:31 | 73 |
| 2608160003 | 14:06:26 | 40 |
| …（余略，12/12 全中） | | |

> ⚠️ 注意 `serial_id` 的顺序**不是开台顺序**：上表中 Llevar 单 13:33 开台却最早结账，
> 拿到 0001；而 13:07 开台的 73 桌 13:54 才结账，拿到 0002。

### 2.11 金额与税（小票实测）

小票的行单价**含税**，`TOTAL` = 各行相加；`SubTotal` 与 `IVA 10%` 是倒算出来的申报口径。

| | Mesa 49 | Mesa 33 |
|---|---|---|
| 行相加 | 51.80 + 5.90 = **57.70** | 51.80 + 2.95 + 3.50 = **58.25** |
| 小票 TOTAL | 57.70 ✓ | 58.25 ✓ |
| SubTotal（= TOTAL/1.1） | 52.45 | 52.95 |
| IVA 10% | 5.25 | 5.30 |
| 库内有效明细行合计 | **57.70** ✓ | **58.25** ✓ |

即 **`SUM(actual_price)`（剔除伪行/配料/退菜后）== 小票 TOTAL == 含税额**，
与「积分按含税价」的业务口径一致，无需再做税额换算。

> 📌 **展示差异**：POS 把「2 瓶水」存成两行各 2.95，小票印的是「2 Agua 5.90」。
> Pad 必须按菜品合并展示，否则服务员照小票核对时会看到多出来的行（`03` §2）。

---

### 3.1 必须过滤掉的伪行

明细表里混着大量**非菜品行**，任何金额计算都必须先过滤：

| 条件 | 含义 | 实例 |
|---|---|---|
| `menu_item_id = -2` | **折扣行**：`actual_price` **恒为负** | `'CUPON DE 5 EUROS'`、`'TARJETA 10+1'`、`'Dto%'` |
| `menu_item_id = -3` | 操作留痕行 | `'**999 Enviado 19:16**'`、`'**555 下单 22:09**'` |
| `menu_item_id = -4` | **支付行**：`actual_price` = 收款额。⚠ **不等于实付额**，见下 | `'EFECTIVO'`（现金）、`'VISA'` |
| `menu_item_id < 0`（其他） | 其他系统行 | — |
| `condiment_belong_item != 0` | 配料/做法行 | `S/Pepino`（不要黄瓜）、`无鸡` |
| `is_return_item + 0 = 1` | 已退菜行 | — |

**伪行全量分布**（模拟环境实测，6,794 行明细）：

| id | 行数 | 名称种数 | `actual_price` 范围 |
|---|---|---|---|
| `-4` 支付 | 267 | 3 | −9.00 ~ 222.60（**可为负 = 找零**） |
| `-3` 留痕 | 1,010 | 542 | 恒为 0 |
| `-2` 折扣 | 63 | 4 | **恒为负** −95.60 ~ −4.40 |

**`-2` 折扣行的名称分布**（两批样本，共 5 种名称）：

| 名称 | 2026-07-10 ~ 08-17（209 行） | 更早的 63 行样本 | 是什么 |
|---|---|---|---|
| `CUPON DE 5 EUROS` | **164** | 4 | 「满 50 减 5」纸质券，POS 直接核销，可叠加 |
| **`TARJETA 10+1`** | **34** | 1 | **十送一核销 —— 唯一需要识别的** |
| `Dto%` | 10 | 4 | 普通折扣 |
| `Dto. -15%` | 1 | — | 普通折扣 |
| `Dto. -20%` | — | 54 | 普通折扣，**新样本里已消失** |

🔴 **名称会变。** `Dto. -20%` 已被 `Dto. -15%` 取代 —— 店家调整折扣力度时名称就变了。
所以判定必须走可配置的 `sys_config.redeem_line_patterns`，既不能按「有折扣就算核销」，
也不能把名单写死在代码里。

注意 `CUPON DE 5 EUROS` 的出现频率是十送一的 **5 倍** —— 一旦把它误判成核销，
正常付费的客人会不计次不积分，而且天天在发生。

> 🟢 **`menu_item_id = -2` 是十送一核销的判据。** 实测订单 92293：明细含
> `-2 / 'TARJETA 10+1' / -95.60`，对应头部 `discount_amount = -95.60`
> （正好 4 份 23.90 的套餐），`original 125.40 → should/actual 29.80`。
> 这一行**回答了「10送1 核销时收银员在 POS 端做了什么」**：既不是整单折扣，
> 也不是改价 0，而是加一条 `-2` 折扣行。
> 系统据此把该单标记为核销餐（`pos_order.is_redeemed`），**被券抵掉的那几份不计次不积分**
> —— 否则客人「拿奖励的同时又攒一次」。
>
> ⚠️ 抵掉的是【几份】而不是【整单】：核销额 ÷ 套餐单价即可反推
> （`-95.60 ÷ 23.90 = 4 份`）。同桌其余正常付费的份数照常计次积分，
> 详见 `03` §5.5 —— 实测 25 张核销单里 15 张是这种混合单。
>
> 判定按**可配置的名称模式**（`sys_config.redeem_line_patterns`），
> 因为 `CUPON DE 5 EUROS`（满50减5 纸质券）/ `Dto%` 都是普通折扣，
> 误判会让正常付费的客人拿不到积分 —— 而纸质券的出现频率是十送一的 5 倍。
>
> 💡 `menu_item_id = -4` 的行**免费提供了订单支付方式**。若后台规则需要"现金不积分/刷卡加倍"，数据现成可用。
>
> 🔴 **但 `-4` 行的金额不能当作实付额。** 一张单可能有多条 `-4`：实测订单 92600
> 有 `EFECTIVO 101.50` 与 `EFECTIVO 5.90` 两条，而该单真实应付是 **5.90**
> （`order_info_cctv.should_amount` 佐证；101.50 是折扣前的数）。
> 系统把所有 `-4` 行排除在金额计算之外，金额一律取订单头的 `should/actual`，口径正确。
>
> 🔴 **但该行的 `actual_price` 是收款额（含待找零），绝不可混入明细金额合计** —— 否则订单金额会被重复计算且虚高。标准过滤条件的 `menu_item_id > 0` 已排除此行，**不要为了取支付方式而放宽这个条件**；需要支付方式时另起一次查询。

**标准过滤条件**：

```sql
WHERE order_head_id = ? AND check_id = ?
  AND menu_item_id > 0
  AND condiment_belong_item = 0
  AND is_return_item + 0 = 0
```

### 3.2 三个价格字段（区分两种「0 元」）

| 字段 | 含义 |
|---|---|
| `product_price` | 下单时的商品价格 |
| `original_price` | 原价（部分折扣记法下才填） |
| `actual_price` | 实际计入订单的价格 |

**实测组合分布**（100 行明细样本，已排除伪行与配料行）：

| `product_price` | `original_price` | `actual_price` | 行数 | 含义 |
|---|---|---|---|---|
| `0` | `NULL` | `0` | 68 | **套餐内菜品**（本来就免费，正常营业数据） |
| `>0` | `NULL` | `>0` | 6 | 正常收费项 |
| `>0` | `NULL` | **`0`** | 4 | **被免的收费项**，伴随 `is_discount = 1` |
| `0` | **`>0`** | `0` | 2 | **被免的收费项**（另一种记法） |

实测被免实例：`Agua` 原价 2.80 实收 0、`Verdejo` 原价 13.95 实收 0、`32-Rollo de esparrágos` 原价 1.00 实收 0。

**关键判据**：

```
套餐内本来免费的菜  ←→  三个价格字段全为 0 / NULL
被免掉的收费项      ←→  product_price 或 original_price > 0，但 actual_price = 0
```

> 💡 这条判据**不依赖 `major_group` / `family_group` 白名单**，可直接用于 AA 点选菜品的显示过滤与免费餐的第一层兜底校验。见 `03-积分与防刷引擎.md` §3.C.1 与 §5.2。
>
> ✅ **已用 2026-08-13 的 1,694 行明细复核**：仅出现 3 种组合（`product=0/original=NULL/actual=0` 1,127 行、`product>0/original=NULL/actual>0` 184 行、`product>0/original=NULL/actual=0` 4 行），**无第五种组合**，判据成立。

### 3.3 ✅ `quantity` 与「单价 vs 行小计」（已用 5,000 行明细证实）

> 本节字段均属 **`history_order_detail`**（明细行级）。切勿与 `history_order_head.actual_amount`（订单级**收款额**，含待找零）混淆。

`quantity` 为 `float`，实测取值 1~5 —— 收银员会把多份相同菜品录成**一行**。

**结论（基于 5,000 行明细、160 个订单，2024-01-25 ~ 2024-01-27）：**

| 字段 | 语义 |
|---|---|
| `product_price` | **单价** |
| **`actual_price`** | **行小计 = `product_price × quantity`** |
| `quantity` | 份数 |

**证据一 —— 242 行 `quantity > 1` 且 `actual_price > 0` 的行，100% 满足 `actual_price = product_price × quantity`，0 行满足 `actual_price = product_price`：**

| 菜品 | `product_price` | `actual_price` | `quantity` | `actual_price / quantity` |
|---|---|---|---|---|
| MENÚ INFINITY NOCHE | 23.90 | **71.70** | 3 | 23.90 |
| MENÚ INFINITY NOCHE | 23.90 | **119.50** | 5 | 23.90 |
| MENÚ INFINITY NOCHE | 23.90 | **95.60** | 4 | 23.90 |
| MENÚ INFINITY MEDIODIA | 17.90 | **71.60** | 4 | 17.90 |
| Cerveza | 3.30 | **13.20** | 4 | 3.30 |
| Agua | 2.80 | **5.60** | 2 | 2.80 |

**证据二 —— 订单级对账 153/153 = 100%：**

```
SUM(history_order_detail.actual_price)  ==  history_order_head.original_amount
```

（过滤条件：`menu_item_id > 0` + `condiment_belong_item = 0` + `is_return_item + 0 = 0`；排除明细被截断的首尾两个订单）

**因此：**

```
行金额   = history_order_detail.actual_price        ← 直接用，不要乘 quantity
计次份数 = SUM(history_order_detail.quantity)        ← 仍用 quantity 累加
```

### 3.3.1 ⚠️ 明细合计对应的是 `original_amount`（折扣前），不是 `should_amount`

对账实测：明细合计等于 **`original_amount`**（折扣前原价），**订单级折扣不体现在明细行上**，只记在 `history_order_head.discount_amount`。

实例：

| order_head_id | `original_amount` | `discount_amount` | `should_amount` | 明细 `SUM(actual_price)` |
|---|---|---|---|---|
| 9975 | 53.40 | -9.56 | 43.84 | **53.40** ← 等于 original |
| 9982 | 27.40 | -4.78 | 22.62 | **27.40** |
| 9977 | 114.10 | -14.34 | 99.76 | **114.10** |
| 9967 | 131.50 | -19.12 | 112.38 | **131.50** |

> 这正是「不计积分项的金额扣除必须按比例、不能直接相减」的原因 —— 见 `03-积分与防刷引擎.md` §2.3。
>
> 💡 由于 `SUM(actual_price) == original_amount` 恒成立，按比例扣除时**分母可直接取订单头的 `original_amount`**，无需在 `/app` 侧再累加一遍明细，也更稳健（明细偶发不全时不会失真）。

> ✅ 另一个副产品：过滤退菜行后仍 100% 匹配，说明 **`original_amount` 本身已是扣除退菜后的净额**，与 §3.4 的退菜结论一致。

### 3.4 退菜机制（关键）

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

### 4.1 分组名称字典（已确认）

**`major_group`**（4 个）：

| id | 名称 | 含义 | 菜品数 | 有价数 | 全期销售占比 |
|---|---|---|---|---|---|
| 1 | `Comida` | 食物 | 305 | 40 | 6.04% |
| 2 | `Bebida` | 饮料 | 157 | 130 | 13.61% |
| **3** | **`Menú`** | **套餐** | 19 | 11 | **78.55%** |
| 4 | `Postres` | 甜点 | 38 | 3 | 1.80% |
| -1 | （未分组） | 配料/做法 | 147 | 18 | — |

**`family_group`**（9 个）：

| id | 名称 | id | 名称 | id | 名称 |
|---|---|---|---|---|---|
| 1 | `Bebidas` | 4 | `Especiales` | 7 | `Sushis` |
| 2 | `Postres` | 5 | `Fritos` | **8** | **`Menus`** |
| 3 | `Entrantes` | 6 | `Donburi` | 9 | `Carta Noche` |

> ⚠️ **分组数据有录入不一致**：13 项甜点（`305-Fuij helado de cerezas`、`321-Delicias heladas makis`、`326-Mochi de fresa`）被归为 `major=Postres` 但 `family=Bebidas`。
> **优先信任 `major_group`，不要单独依赖 `family_group`。**

### 4.2 「餐费项」白名单（已确定）

`major_group = 3 (Menú)` 共 19 项，但**不能整组当餐费** —— 组内混有餐具、调料、外送费。逐项拆分如下：

**✅ 计入餐费（7 项）。三个开关（`is_meal_fee` / `counts_visit` / `earns_points`）存于后台可维护的 `meal_item_rule` 表，见 `04-本地库Schema.md` §6.2：**

**参与十送一（`counts_visit = 1`）**

| item_id | 价格 | 名称 |
|---|---|---|
| `2590` | 25.90 | MENÚ INFINITY VIERNES NOCHE-FIN DE SEMANA-FESTIVOS |
| `25900` | 25.90 | TAKE WAY |
| `2390` | 23.90 | MENÚ INFINITY NOCHE LUNES A JUEVES-ADULTOS |
| `1890` | 18.90 | MENÚ INFINITY MEDIODIA - ADULTOS |
| `1490` | 14.90 | MENU INFANTIL NOCHE FINDE SEMANA |
| `1290` | 12.90 | MENÚ INFINITY - INFANTIL MEDIODIA |

> ⚙️ **儿童套餐 `1490` / `1290`**：`counts_visit` 默认为 `1`（参与），**后台可自由关闭**。

**不参与十送一（`counts_visit = 0`）**

| item_id | 价格 | 名称 | 说明 |
|---|---|---|---|
| `1590` | 15.90 | MENÚ DEL DIA (Lunes - Jueves) | **不计次**（`counts_visit = 0`）；金额是否计入积分由 `earns_points` 开关控制，默认计入 |

**实测分布佐证**（按订单金额反推，仅供参考）：`15.90` 的整数倍金额在午市匹配到 1,776 单、晚市仅 17 单，符合 `MENÚ DEL DIA` 作为工作日午市套餐的定位。

> ⚠️ **占比无法从订单头精确量化** —— 午市最常见金额为 41.70（4,586 单）= 2×20.85，而 20.85 ≈ 18.90 套餐 + 1.95 饮料，绝大多数订单都含酒水，金额反推不可靠。**如需精确占比，须取明细样本**：
>
> ```sql
> SELECT menu_item_id, menu_item_name, SUM(quantity) AS 份数, COUNT(*) AS 行数
> FROM history_order_detail
> WHERE order_time >= DATE_SUB(NOW(), INTERVAL 30 DAY)   -- 走 idx_order_time
>   AND menu_item_id IN (2590,25900,2390,1890,1590,1490,1290)
> GROUP BY menu_item_id, menu_item_name;
> ```
> 该查询会扫近 30 天明细，**必须在 03:00–05:00 执行**。

**❌ 不计入餐费（12 项，同在 `major_group=3`）：**

| item_id | 价格 | 名称 | 类别 |
|---|---|---|---|
| `6046` | 3.00 | PORTES | 外送费 |
| `1200` / `1100` | 2.00 / 1.00 | Suplemento | 补充费 |
| `43435` | 2.00 | Palillos | 筷子 |
| `555` | 0 | Tenedor | 叉子 |
| `666` / `777` | 0 | Wasabi y Jenjibre / Wasabi | 调料 |
| `43445` | 0 | Jengibre | 姜 |
| `43447` | 0 | Vaso y Hielo | 杯与冰 |
| `43457` / `43458` | 0 | Cuchara / Cuchillo | 勺/刀 |
| `888` | 0 | Temporal | 临时项 |

> 💡 **命名规律**：套餐的 `item_id` = 价格 × 100（`2590`→25.90、`1890`→18.90）。**仅供识别，不要在代码中依赖** —— 改价时 `item_id` 不会跟着变。

> ⚠️ **菜单会变，白名单需要维护机制。** 实测：2024-01 时 `item_id=2390` 名为 `MENÚ INFINITY NOCHE-FESTIVO-FIN DE SEMANA-ADULTOS`（23.90），现已改为 `NOCHE LUNES A JUEVES-ADULTOS`，周末套餐被拆成新项 `2590` 并涨价至 25.90。
>
> 好消息：`history_order_detail.menu_item_name` 保存的是**下单时的名称快照**，历史数据不受菜单变更影响。

## 5. `history_major_group`（日销售汇总，有额外价值）

```sql
CREATE TABLE `history_major_group` (
  `history_major_id` int(11) NOT NULL,
  `day`              date    NOT NULL,      -- ★ 营业日（非结账自然日）
  `rvc_center_id`    int(11) NOT NULL,
  `period_id`        int(11) NOT NULL,
  `major_group_id`   int(11) DEFAULT NULL,
  `amount`           decimal(11,2) DEFAULT NULL
)
```

按「营业日 × 营业点 × 班次 × 大类」汇总，每天 4 行。

### 5.1 ⚠️ 2025 年起停止写入

**数据仅覆盖 2024-01-22 ~ 2024-12-31（341 天）。** 2025 年之后该表不再有新数据。

> 说明 POS 的部分功能会**静默停止工作**。这是"不能盲信 POS 表、必须用实测数据验证"的又一佐证。**不可作为常规对账工具。**

### 5.2 它验证出的两个结论

**结论 A —— 营业日切点在 02:00 之后，口径为 `original_amount`（折扣前）**

用 2024 年 332 个有效日（排除 §5.3 的缺口期）逐日比对：

| 归属口径 | 金额字段 | 完全一致天数 | 日均差额 |
|---|---|---|---|
| 结账自然日 | `actual_amount` | 48 / 332 | 174.89 |
| 结账自然日 | `original_amount` | 235 / 332 | 134.39 |
| **营业日（02:00 切点）** | `actual_amount` | 57 / 332 | 64.47 |
| **营业日（02:00 切点）** | **`original_amount`** | **324 / 332** | **0.49** |

```
SUM(history_order_head.original_amount) 按营业日  ==  SUM(history_major_group.amount)
```

全年总额差 -509.50 / 1,566,305.52 = **-0.03%**。

切点设为 02:00 / 04:00 / 06:00 结果完全相同 —— 说明 **02:00–06:00 无任何营业**，切点在此区间任意取值皆可。这与配置的餐期「晚上 19:30 – 次日 02:00」完全吻合。

> ✅ 这直接证实了 `01` §2.1 性质 B：`serial_id` 的日期是**结账自然日**，不是营业日。营业日必须按餐期规则重算。

**结论 B —— `history_order_head` 曾发生数据丢失**（详见 §5.3）

### 5.3 🔴 已发生的数据丢失事件（2024-08）

| 营业日 | `history_major_group` 记录 | `history_order_head` 实有订单 | 实有金额 |
|---|---|---|---|
| 2024-08-11 | 3,281.05 | 61 单 | 3,221.05 |
| **2024-08-12** | **4,327.23** | **34 单** | **1,460.70** |
| **2024-08-13** | **5,090.35** | **0 单** | **0.00** |
| **2024-08-14** | **5,721.70** | **0 单** | **0.00** |
| **2024-08-15** | **4,622.60** | **0 单** | **0.00** |
| **2024-08-16** | **4,550.20** | **1 单** | **45.20** |
| **2024-08-17** | **3,170.85** | **0 单** | **0.00** |
| **2024-08-18** | **3,621.05** | **5 单** | **364.55** |
| 2024-08-19 | 4,481.34 | 77 单 | 4,391.40 |

`order_head_id` 缺口佐证：

```
缺 23741~24064（324 个）  缺口前最后一单 2024-08-12 15:50:08  缺口后第一单 2024-08-16 16:39:40
缺 24066~24219（154 个）  缺口前最后一单 2024-08-16 16:39:40  缺口后第一单 2024-08-18 23:40:09
```

**丢失约 6 天、478 个订单号、29,233.53 欧的交易记录。**

> 🔴 **对本系统的影响**：`history_order_head` **不是 100% 可靠的数据源**。
>
> - 丢失期间收银员输桌号将**查不到订单**，无法正常积分
> - **校准任务无法自愈** —— 数据根本不在主库里，补抓也抓不到
> - 必须有降级预案：见 `03-积分与防刷引擎.md` §10

## 6. 索引清单（决定所有查询写法）

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

## 7. MySQL 5.5 特有限制（写代码前必读）

| 限制 | 影响 | 对策 |
|---|---|---|
| **无 `MAX_EXECUTION_TIME`**（5.7.4+ 才有） | 服务端无法掐断慢查询 | 客户端超时兜底：`mysqli` + `MYSQLI_OPT_READ_TIMEOUT`（`PDO::ATTR_TIMEOUT` 只管连接不管查询） |
| 无 CTE、无窗口函数 | 复杂 SQL 写不了 | SQL 保持朴素，聚合逻辑放 `/app` 侧 |
| `bit(1)` 读取返回二进制字节 | `is_return_item` 等判断会出错 | SQL 中写 `is_return_item + 0 AS is_return_item` |
| 字符集 `utf8`（3 字节） | emoji 会截断 | 本地库用 `utf8mb4`；读取时做兼容 |
| `datetime` 无时区 | 跨机器时钟/夏令时偏差 | **时间基准一律用主库 `NOW()`**，绝不用 PHP 服务器时间 |
| `GROUP BY` 易建临时表 | 增加 POS 负担 | 尽量不在 POS 侧聚合，取回后在 `/app` 聚合 |

## 8. 坑位速查表

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
| 13 | **`actual_amount` 是收款额含待找零** | 直接当积分基数 → 把找零算成消费额，最坏多给一倍 | 用 `LEAST(should_amount, actual_amount)`（§2.2） |
| 14 | 支付行 `-4` 的 `actual_price` 也是收款额 | 混入明细合计 → 金额重复且虚高 | 保持 `menu_item_id > 0` 过滤，取支付方式另起查询（§3.1） |
| 15 | **`actual_price` 已是行小计** | 再乘 `quantity` → 多份行金额平方级放大 | 直接用 `actual_price`；份数另用 `SUM(quantity)`（§3.3） |
| 16 | 明细合计 = `original_amount` 非 `should_amount` | 订单级折扣不在明细上，直接相减会算错 | 扣除按比例，分母用 `original_amount`（§3.3.1） |
| 17 | **BOX/COMBO 套餐在 `major_group=1` 不在 `3`** | 白名单巡检若按 `major_group=3` 过滤 → 漏掉 22 项 | 巡检按**价格阈值扫全表**（`03` §5.4） |
| 18 | 菜单会涨价 | 白名单里的参考价会过期 | 参考价仅供后台显示，逻辑只认 `item_id`（§4.2） |
