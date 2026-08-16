# 模拟环境 —— 全功能测试

用真实的 MySQL/MariaDB 服务端同时模拟【POS 主库】与【侧系统本地库】，
把整套系统在真链路上跑一遍。

`tests/smoke.php` 注入 `FakePosSource`，不需要 POS 可达；
本目录解决的是它测不到的那一半：**mysqli → PosDb → PosReader 的真实链路**、
**数据库层的只读权限**、**索引命中**、以及**真实脏数据**。

---

## 1. 为什么必须用真服务端

`PosDb` 走的是 **mysqli**（不是 PDO）—— 因为 POS 主库是 MySQL 5.5，
服务端没有 `MAX_EXECUTION_TIME`，只有 `MYSQLI_OPT_READ_TIMEOUT` 能限制查询时间。
所以 SQLite 之类替代不了，必须起一个真的 MySQL/MariaDB。

## 2. 搭建

```bash
# 2.1 起服务端（容器内无 systemd 时手工拉起）
mariadbd --user=mysql --socket=/var/run/mysqld/mysqld.sock \
         --bind-address=127.0.0.1 --port=3306 &

# 2.2 时区必须与主库一致，否则营业日与时间窗全错
mariadb-tzinfo-to-sql /usr/share/zoneinfo | mariadb -uroot mysql
mariadb -uroot -e "SET GLOBAL time_zone='Europe/Madrid';"
```

```sql
-- 2.3 两个库 + 两个账号
-- 主库字符集必须还原成 utf8(3字节)，与真实主库一致
CREATE DATABASE sim_coolroid DEFAULT CHARACTER SET utf8   COLLATE utf8_general_ci;
CREATE DATABASE sim_vip      DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- ★ 只读账号只给 SELECT —— 让「主库不可写」成为数据库层的硬保证，
--   而不是靠应用层自觉
CREATE USER 'pos_ro'@'127.0.0.1'  IDENTIFIED BY '...';
GRANT SELECT ON sim_coolroid.* TO 'pos_ro'@'127.0.0.1';
CREATE USER 'vip_app'@'127.0.0.1' IDENTIFIED BY '...';
GRANT ALL ON sim_vip.* TO 'vip_app'@'127.0.0.1';

-- 模拟 POS 写单用的管理员账号（旁路系统永远不用它）
CREATE USER 'sim_admin'@'127.0.0.1' IDENTIFIED BY '...';
GRANT ALL ON sim_coolroid.* TO 'sim_admin'@'127.0.0.1';
```

### 2.4 灌真实数据

主库结构取自 `pdb/192_168_2_40 (1).sql`（190 张表，剥掉
`DROP/CREATE DATABASE`、`USE` 三行后导入 `sim_coolroid`）。
数据只取 `INSERT` 语句，**保留主库的权威表结构**（已核对：
导出件与主库的 `history_order_head` / `history_order_detail` 定义逐字节相同）。

| 表 | 行数 |
|---|---|
| `history_order_head` | 88,616（去重后；两份导出有 4,886 行完全重复） |
| `history_order_detail` | 6,794 |
| `history_major_group` | 1,368 |
| `menu_item` | 666 |
| `major_group` / `family_group` | 4 / 9 |

> ⚠️ 导出件的两个坑：
> · `8b18ff39-coolroid.sql` 的 `history_major_group` INSERT **被截断**，
>   最后一行以逗号结尾、没有分号，需手工补 `;`
> · `history_order_detail` 是**抽样导出**：2026 批次 1,694 行只覆盖
>   `order_detail_id` 区间的 76%，因此少数订单缺行、
>   `SUM(actual_price)` 对不上 `original_amount`（详见 `docs/01` §3.3.1）

## 3. 注入活单

真实数据最新到 2026-08-13，而 Pad 只找近 30 分钟的单，
所以要把历史真单**克隆到当下**：

```bash
SIM_USER=sim_admin SIM_PASS=... php tests/sim/inject_live.php
php tests/sim/inject_live.php --clean     # 清除克隆件
```

克隆而不是造假数据，是因为真单自带全部脏东西：伪行（-2/-3/-4）、
配料行、退菜行、套餐内 0 元菜、外带 `eat_type=3`、订单级折扣、分单多 check。
手写夹具永远想不全。

当前 10 张覆盖的场景见 `inject_live.php` 里的 `$plan`，包括
十送一核销（`TARJETA 10+1`）、4 张 check 的 AA 分单、10 份套餐计次、
成人+儿童混点、外带、退菜、折扣。

> 克隆件的 `order_head_id` 从 900000 起，与真实数据（最大 ~92xxx）不重叠，
> 清理时按 `order_head_id >= 900000` 整批删，不会碰到真实数据。

### 写入用的是管理员账号，不是只读账号
`inject_live.php` 模拟的是 **POS 自己在写单**，属于「环境搭建」而非被测系统。
旁路系统自身全程只有 `pos_ro`，写权限在数据库层被拒 —— 这一点由
`tests/e2e_pos.php` ① 段实测保证。

## 4. 跑测试

```bash
php bin/init.php check          # 环境自检（含主库时钟偏差）
php bin/init.php migrate        # 增量迁移，已应用的不重跑
php bin/init.php seed
php bin/init.php admin admin 管理员

php tests/run.php               # 纯逻辑，208 项断言，不需要数据库
php tests/smoke.php --fresh     # 真本地库 + FakePos，100 项断言
php tests/e2e_pos.php           # 真 POS + 真本地库，75 项断言
php bin/cron.php nightly -v     # 夜间全套跑在真实数据上
```

`e2e_pos.php` 用独立门店码 `E2E`（可用 `E2E_STORE` 覆盖），
跑前会把套餐规则与配置从当前门店复制一份过去 ——
否则 `MealRules` 全部回落到安全默认（`counts_visit=false`），
计次恒为 0，测不出真实行为。跑完自动清理。

## 5. 全量补抓的实测数据

把水位线设到 2024-01-01 跑一次全量：

| 项 | 实测 |
|---|---|
| 处理 | 82,009 单 / 1,139 批 / 480 个窗口 |
| 耗时 | 60.9 秒（`sync_batch_sleep_ms=0`） |
| 落地 | 81,918 单（堂食 71,415） |
| 漏抓 | 0（唯一一条差异是 `order_head_id=25379` 的双 serial，金额已完整计入） |

生产配置是 100 单/批 + 每批停 2 秒，故**上线首次全量补抓约需 30 分钟**，
应安排在 03:00–05:00 窗口（实测该时段 0 单）。
