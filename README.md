# 餐厅 POS 旁路会员积分系统

给已有 POS（`coolroid`,MySQL 5.5）挂一套会员积分与「N 送 1」系统。
**POS 主库全程只读** —— 不写入、不改表、不装插件，所有交互仅 `SELECT`。

---

## 从零开始

```bash
# 1. 配置
cp app/config/config.example.php app/config/config.php
#    填 store_code、本地库、POS 只读账号

# 2. 建库（迁移里没有 CREATE DATABASE，必须先手工建）
#    CREATE DATABASE vip_local CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

# 3. 一条命令搞定其余
php bin/init.php repair
```

`repair` 会查扩展、连库、建表、灌配置与套餐规则，最后核验能不能正常算份数，
缺什么直接说。默认账号 `admin` / `admin123`（**上线前务必改掉**）。

装机遇到问题看 **[docs/07 环境配置手册](./docs/07-环境配置手册.md)** ——
Windows / Ubuntu / 宝塔面板各自要设什么，以及现场真踩过的坑一览。

## 出问题时

```bash
php bin/init.php repair              # 环境与配置，一次查完并尽量自修
php bin/why.php <桌号>                # Pad 上找不到订单
php bin/why.php --invoice <小票号>    # 按小票上的 Factura Simplificada 查
php bin/why.php --ref E202-7F3A21    # 按界面上的错误代码翻日志拿完整异常
```

## 目录

| 目录 | 说明 |
|---|---|
| `wwwroot/` | **唯一网络可见** —— Pad 前端与两个 API 入口 |
| `app/` | 业务逻辑；`app/config/config.php` 含口令，不入库 |
| `bin/` | 命令行：初始化、诊断、定时任务 |
| `db/` | 迁移与种子 —— **部署时别漏了它** |
| `docs/` | 设计与运维文档 |
| `tests/` | 测试；`tests/sim/` 是模拟环境搭建说明 |
| `pdb/` | POS 主库的参考资料，**不必部署** |

> 🔴 `app` `bin` `db` `docs` `tests` 必须在 Web 文档根**之外**。

## 测试

```bash
php tests/run.php                 # 816 项，不需要数据库
php tests/smoke.php --fresh       # 430 项，需要一个【专用空库】
php tests/e2e_pos.php             # 95 项，需要 POS 可达
php tests/http_sweep.php          # 53 项，对着跑起来的站点打全部接口
node tests/browser/*.mjs          # 446 项，真浏览器，仅开发机
```

> 🔴 `smoke.php --fresh` 会 `DROP TABLE`，**务必给它一个专用空库**
> （`SMOKE_DB_NAME=vip_smoke`）。里面有一道闸门会拒绝在有非 SMOKE 数据的库上跑 ——
> 别绕过它。

## 文档

从 **[docs/README.md](./docs/README.md)** 进，那里有完整索引。

| | |
|---|---|
| [00 架构总览](./docs/00-架构总览.md) | 整体设计与边界 |
| [01 POS 主库数据字典](./docs/01-POS主库数据字典.md) | 主库字段语义（全部实测得出） |
| [02 只读接入规范](./docs/02-只读接入规范.md) | 怎么读才不拖垮 POS |
| [03 积分与防刷引擎](./docs/03-积分与防刷引擎.md) | 计次、积分、十送一、混合核销单 |
| [04 本地库 Schema](./docs/04-本地库Schema.md) | 侧系统表结构 |
| [05 合规与 Wallet](./docs/05-合规与Wallet.md) | GDPR / LOPDGDD |
| [06 部署手册](./docs/06-部署手册.md) | 安装、后台配置、故障速查、错误代码表 |
| [07 环境配置手册](./docs/07-环境配置手册.md) | 🔴 装机先看这份 |
