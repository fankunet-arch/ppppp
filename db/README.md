# 本地库 —— MySQL / MariaDB 双兼容约定

本地会员库必须能同时跑在 **MySQL** 和 **MariaDB** 上。
两者语法大面积重合，但有若干处会导致「一边能建、另一边报错」或「行为不一致」。
本文件列出所有约定，**写 DDL 与应用层 SQL 时必须遵守**。

**最低版本：MySQL 5.7+ / MariaDB 10.2+**

---

## 1. DDL 层约定

### 1.1 显式指定排序规则

```sql
DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
```

| 版本 | 默认排序规则 | 问题 |
|---|---|---|
| MySQL 8.0 | `utf8mb4_0900_ai_ci` | **MariaDB 中不存在**，照搬建表会报错 |
| MySQL 5.7 | `utf8mb4_general_ci` | — |
| MariaDB 10.x | `utf8mb4_general_ci` | — |

`utf8mb4_unicode_ci` 在两边所有目标版本中都存在，是唯一安全的显式选择。

> ⚠️ 不要依赖默认值。同一份 DDL 在 MySQL 8 和 MariaDB 上会得到不同排序规则，
> 导致 `JOIN` 时报 `Illegal mix of collations`。

### 1.2 显式指定行格式

```sql
ENGINE=InnoDB ROW_FORMAT=DYNAMIC
```

`DYNAMIC` 保证单个索引键最长 **3072 字节**；旧版默认的 `COMPACT` 只有 **767 字节**。

### 1.3 索引键长仍控制在 767 字节内

即使已声明 `ROW_FORMAT=DYNAMIC`，仍按 767 字节设计，避免部署在配置了
`innodb_large_prefix=OFF` 的老实例上建表失败。

`utf8mb4` 每字符按 **4 字节**计算：

| 写法 | 键长 | 判断 |
|---|---|---|
| `KEY (store_code, email)` email 为 `VARCHAR(200)` | (20+200)×4 = 880 | ❌ 超 767 |
| `email` 改为 `VARCHAR(190)` + 前缀索引 `email(100)` | (20+100)×4 = 480 | ✅ |
| `UNIQUE KEY (store_code, card_no)` | (20+32)×4 = 208 | ✅ |
| `UNIQUE KEY (consent_token)` `VARCHAR(64)` | 64×4 = 256 | ✅ |

本库中 `member.email` 已定为 `VARCHAR(190)` 并使用前缀索引 `email(100)`。

### 1.4 禁用的类型与特性

| 特性 | 原因 |
|---|---|
| **`JSON` 列类型** | MySQL 5.7+ 是原生类型并做校验；MariaDB 中只是 `LONGTEXT` 别名 + `CHECK`。行为与函数支持不一致 → **一律用 `TEXT` 存 JSON 文本** |
| **`CHECK` 约束** | MySQL 5.7 **静默忽略**，MariaDB 10.2+ **强制执行**。同一份 DDL 两边行为不同 → 约束一律放应用层 |
| **`DEFAULT CURRENT_TIMESTAMP`** | 两边都支持，但会让时间口径落到数据库服务器时区。本系统时间口径需统一 → **所有时间字段由应用层显式写入** |
| **生成列（`GENERATED ALWAYS AS`）** | 语法差异与索引支持不一致 |
| **`utf8mb4_0900_*` 系列排序规则** | MariaDB 中不存在 |

---

## 2. 应用层 SQL 约定

### 2.1 禁用的语法

| 语法 | 支持情况 | 替代 |
|---|---|---|
| `SELECT ... FOR UPDATE SKIP LOCKED` | MySQL 8.0+ / MariaDB 10.6+ | 用普通 `FOR UPDATE` |
| `SELECT ... FOR UPDATE NOWAIT` | 同上 | 同上 |
| 窗口函数（`ROW_NUMBER()` 等） | MySQL 8.0+ / MariaDB 10.2+ | 应用层计算 |
| CTE（`WITH ... AS`） | MySQL 8.0+ / MariaDB 10.2+ | 子查询或应用层拆分 |
| `JSON_TABLE()` | 仅 MySQL 8.0 | 应用层解析 |
| `RETURNING` | 仅 MariaDB 10.5+ | `lastInsertId()` |
| `INSERT ... ON DUPLICATE KEY UPDATE` | ✅ **两边都支持，可用** | — |

### 2.2 版本探测

`LocalDb::serverFlavor()` 在连接后返回 `mysql` 或 `mariadb`，
供确实需要分支的地方使用。**目前代码中没有任何分支** —— 若将来出现，
必须在此文档登记原因。

### 2.3 SQL 模式

应用层连接后统一设置：

```sql
SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION'
```

- `STRICT_ALL_TABLES` —— 金额/数量字段溢出或截断时报错而非静默截断
- `NO_ENGINE_SUBSTITUTION` —— 请求 InnoDB 时不会被静默换成别的引擎
- **不使用** `ONLY_FULL_GROUP_BY`：MySQL 5.7+ 默认开启而 MariaDB 默认关闭，
  显式统一可避免同一条 `GROUP BY` 一边通过一边报错

---

## 3. 与 POS 主库的区别

不要把本文件的约定用到 POS 主库上 —— 那是**另一套完全不同的限制**：

| | POS 主库 | 本地库 |
|---|---|---|
| 版本 | MySQL **5.5.47**（固定） | MySQL 5.7+ / MariaDB 10.2+ |
| 权限 | **只读**（仅 `SELECT`） | 读写 |
| 字符集 | `utf8`（3 字节） | `utf8mb4` |
| 查询超时 | **无** `MAX_EXECUTION_TIME`，须客户端兜底 | 有 |
| CTE / 窗口函数 | **完全不支持** | 支持（但本项目仍不用） |
| 约定文档 | `docs/02-只读接入规范.md` | 本文件 |

---

## 4. 目录说明

```
db/
  README.md                       ← 本文件
  migrations/
    001_init.sql                  ← 建表
  seeds/
    001_sys_config.sql            ← 系统配置默认值
    002_meal_period.sql           ← 餐期
    003_meal_item_rule.sql        ← 套餐规则（含 BOX/COMBO 22 项）
```

执行顺序：`migrations/` 按编号升序，然后 `seeds/` 按编号升序。

```bash
mysql -u<user> -p <db> < db/migrations/001_init.sql
for f in db/seeds/*.sql; do mysql -u<user> -p <db> < "$f"; done
```

---

## 5. 真库冒烟测试

`tests/run.php` 是纯逻辑测试，不连数据库。DDL 与事务必须在真实数据库上验证，
用 `tests/smoke.php`，**MySQL 与 MariaDB 各跑一遍**。

```bash
# 首次：建表 + 跑完整业务流程
SMOKE_DB_HOST=127.0.0.1 SMOKE_DB_PORT=3306 \
SMOKE_DB_NAME=vip_smoke SMOKE_DB_USER=root SMOKE_DB_PASS=xxx \
php tests/smoke.php --fresh

# 之后：复用已有表，只跑流程
php tests/smoke.php

# 跑完保留数据供人工查看（store_code = SMOKE）
php tests/smoke.php --fresh --keep
```

未设置环境变量时，回落到 `app/config/config.php` 的 `local_db`。

### 安全设计

| 措施 | 说明 |
|---|---|
| 独立门店码 `SMOKE` | 全程只写 `store_code = 'SMOKE'` 的行，绝不触碰生产数据 |
| `--fresh` 前置闸门 | `001_init.sql` 含 `DROP TABLE`。脚本先扫描各表，若存在 `store_code <> 'SMOKE'` 的任何一行就**拒绝执行并退出** |
| 自动清理 | 开始与结束各清一次 SMOKE 数据；`--keep` 可保留 |
| 不依赖 POS | 注入 `FakePosSource`，无需门店内网可达即可跑通全流程 |

### 覆盖的流程

1. 连接并识别 MySQL / MariaDB，打印版本
2. 执行 `001_init.sql`（DDL 能否在该数据库上建成）
3. 写入配置与套餐规则
4. **订单定位** —— 桌号 42（3 份套餐）与桌号 32（含 BOX 且有找零）
5. 幂等 —— 重复定位不产生重复订单
6. **建会员** —— 三选一检索、double opt-in 令牌
7. **整单记账** —— 积分、计次、订单状态
8. **超额拒绝** —— 已全额分配后再提交
9. **撤销** —— 反向冲正、原流水保留、会员与订单回退
10. **AA 分摊** —— 撤销后改记三人，含现场新建会员
11. **降级路径** —— 手工录入、待复核队列、单笔限额
12. **Cron 流程** —— 增量补抓与幂等、值比对冲正（含舍入残差与部分分配两个边界）、完整性监控
13. **不变量总校验**：
    - 没有订单的 `allocated_amount > total_amount`
    - 每张订单的 `allocated_amount` 与其账本流水合计一致
    - 每名会员的 `points_balance` 与其流水合计一致
14. 清理

### 关键断言示例

```
✓ 可积分总额 26.85 ＝ LEAST(79.85,80.00) 排除找零，再按比例扣掉 BOX 的 53.00
✓ 计次份数 3（SUM(quantity)，不是行数 1）
✓ 展示列表仅 1 项（0 元菜品/伪行/配料/退菜均已过滤）
✓ 账本共 2 行（原始 + 冲正），只增不删
✓ 金额守恒：已分配回到 71.70，分毫不差
✓ 每名会员的积分余额与其流水合计一致
✓ 冲正合计恰好 23.90 —— 舍入残差由最后一条吸收，不多退也不少退
✓ 新总额仍高于已分配额 → 一分都不退（若按缩水额去退就退多了）
```
