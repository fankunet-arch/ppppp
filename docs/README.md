# 餐厅 POS 旁路会员积分系统 — 文档索引

> 本目录为**网络不可见**目录，存放全部系统设计文档、接口说明与数据库字典。

## 文档清单

| 文档 | 内容 |
|---|---|
| [00-架构总览.md](./00-架构总览.md) | 旁路监听架构、网络策略、部署拓扑、目录规范 |
| [01-POS主库数据字典.md](./01-POS主库数据字典.md) | 主库字段语义、实测结论、坑位清单 |
| [02-只读接入规范.md](./02-只读接入规范.md) | 性能铁律、SQL 模板、水位线、超时兜底 |
| [03-积分与防刷引擎.md](./03-积分与防刷引擎.md) | 订单定位、AA 分摊、幂等、退单判据、校准任务 |
| [04-本地库Schema.md](./04-本地库Schema.md) | 建表 DDL、多店维度、账本设计 |
| [05-合规与Wallet.md](./05-合规与Wallet.md) | LOPDGDD 落地、双重确认、Wallet 卡分发 |

## 核心前提（不可动摇）

1. **POS 主库完全只读。** 不写入、不改表、不改配置、不装插件。所有交互仅 `SELECT`。
2. **POS 主机性能极度受限。** 任何查询都必须命中索引、限制步长、限定字段。
3. **网络单向。** 门店可访问外网，外网不可访问门店。

## 数据分析基线

本文档中所有"实测"结论，来源于以下三份主库导出：

| 文件 | 来源主机 | 内容 | 规模 |
|---|---|---|---|
| `pdb/192_168_2_40 (1).sql` | `192.168.2.40`（主库） | 主库完整结构（无数据） | 190 张表 |
| `history_order_head.sql`（上传件） | `192.168.58.128`（旧侧系统） | 历史订单头**全量数据** | **88,616 行** |
| `history_order_detail.sql` | `192.168.58.128`（旧侧系统） | 历史订单明细样本 | 100 行 |
| `order_detail.sql` | `192.168.58.128`（旧侧系统） | 菜单 + 活动订单明细样本 | 667 个菜品 |
| `coolroid.sql`（上传件） | `192.168.1.180`（现侧系统） | `major_group` / `family_group` / `history_major_group` | 4 + 9 + 1364 行 |

**数据覆盖期：2024-01-22 ~ 2026-08-13，共 927 个营业日。单店。**

> 📌 **主机说明**：`192.168.2.40` 为 POS 主库；`192.168.1.180` 为当前侧系统，**已替换** `192.168.58.128`。
> 主机地址属可变信息，**全部走配置文件，代码内不硬编码**（见 `00-架构总览.md` §2）。

---

## ⏳ 待确认事项（阻塞项以 🔴 标记）

| # | 事项 | 状态 | 影响文档 |
|---|---|---|---|
| 1 | 🔴 **`actual_price` 是单价还是行小计？** 判断错误会让所有多份合并行算错金额与份数 | **上线前必须验证**（SQL 见下） | 01 §3.3、03 §2.3 |
| 2 | 明细价格字段判据的大样本复验（近 30 天，确认无第五种组合） | 建议上线前执行 | 01 §3.2、03 §3.C.1 |
| 3 | 撤销操作的权限与时间窗（谁能撤销？限当班/24h内？） | 建议值已写入，待确认 | 03、04 |
| 4 | 10送1 核销时收银员在 POS 端的具体动作（整单折扣 / 改价 0 / 删项） | 待确认 | 03（兜底校验精度） |
| 5 | 只读账号能 `SELECT` 的表范围 | 待确认 | 02 |
| 6 | 2024-08 的 6 天数据丢失原因（是否会复发） | 待了解，降级预案已就位 | 01 §5.3、03 §10 |
| 7 | 小票上是否印有可扫的条码/QR | 非阻塞，可选优化 | 03 |

### ✅ 已关闭的事项

| 事项 | 结论 |
|---|---|
| `major_group` / `family_group` 数据 | **已提供**。`major_group` = 1 Comida / 2 Bebida / **3 Menú** / 4 Postres；餐费项确定为 7 项，见 `01` §4.2 |
| `MENÚ DEL DIA` 规则 | **不参与十送一**（`counts_visit = 0`）；金额积分可后台开关 |
| 儿童套餐（`1490`/`1290`） | **后台可自由设置**是否参与十送一，默认参与。规则改为可维护表 `meal_item_rule`（`04` §6.2） |
| 计次口径 | **`by_portion` 已确认采用**：按 `SUM(quantity)` 计次；整单记一人 3 份 = +3 次，商业影响已知悉并接受 |
| AA 计次规则 | 由「按套餐份数计次」统一解决（`03` §3.2），AA 与混点场景自洽 |
| `192.168.1.180` 身份 | **侧系统**，已替换 `192.168.58.128`。单店，规则无需按店分化。IP 全部走配置文件 |
| 免费餐判据 | 双保险：价格字段判据（`01` §3.2）+ 餐费项规则表（`03` §5.3） |
| 营业日切点 | **已用 POS 自身数据验证** = 02:00，口径为 `original_amount`，324/332 天完全一致（`01` §5.2） |

### 🔴 事项 1 的验证 SQL（头号验证项）

明细表有 `quantity` 字段（实测有 2、3 的行），但 100 行样本中**没有「`quantity > 1` 且 `actual_price > 0`」的行**，无法确定 `actual_price` 是单价还是行小计。

已知 `product_price` 是单价（3 杯 Agua 的 `product_price = 2.80` 而非 8.40），推断 `actual_price` 同为单价，但必须证实。

```sql
-- 取一批含多份行的真实订单，两种口径分别与订单头金额比对
SELECT h.serial_id,
       h.should_amount                                   AS 订单应收,
       SUM(d.actual_price * d.quantity)                  AS 按单价累乘,
       SUM(d.actual_price)                               AS 按行小计,
       SUM(d.quantity)                                   AS 总份数,
       COUNT(*)                                          AS 明细行数
FROM history_order_head h
JOIN history_order_detail d
  ON d.order_head_id = h.order_head_id AND d.check_id = h.check_id
WHERE h.order_end_time >= DATE_SUB(NOW(), INTERVAL 7 DAY)   -- 走 idx_order_end_time
  AND h.should_amount > 0
  AND h.discount_amount = 0                                 -- 排除折扣干扰
  AND d.menu_item_id > 0
  AND d.condiment_belong_item = 0
  AND d.is_return_item + 0 = 0
GROUP BY h.serial_id, h.should_amount
HAVING 明细行数 < 总份数                                     -- 只看含多份行的订单
LIMIT 30;
```

**判读**：哪一列等于「订单应收」，就用哪种口径。

- `按单价累乘` == 订单应收 → `actual_price` 是**单价**，行金额 = `actual_price × quantity`（预期结果）
- `按行小计` == 订单应收 → `actual_price` 已是**行小计**，直接求和，但**计次仍用 `SUM(quantity)`**

> 该查询限定 7 天且排除折扣单，扫描量小，可在营业时段外任意时间执行。

### 事项 2 的复验 SQL
```sql
-- 确认价格字段只有已知的 4 种组合，没有第五种
SELECT
  CASE WHEN product_price  > 0 THEN '>0' ELSE '=0'  END AS product_price,
  CASE WHEN original_price IS NULL THEN 'NULL'
       WHEN original_price > 0 THEN '>0' ELSE '=0'  END AS original_price,
  CASE WHEN actual_price   > 0 THEN '>0' ELSE '=0'  END AS actual_price,
  COUNT(*) AS cnt
FROM history_order_detail
WHERE order_time >= DATE_SUB(NOW(), INTERVAL 30 DAY)   -- 走 idx_order_time
  AND menu_item_id > 0
  AND condiment_belong_item = 0
GROUP BY 1,2,3;
```

> ⚠️ 这条查询会扫近 30 天明细（约 8 万行），**必须在 03:00–05:00 窗口执行**，且仅作为一次性复验，不进入常规任务。
