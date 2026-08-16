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
