# pdb —— POS 主库的参考资料

> 🔴 **这里的东西只是【参考】，系统运行不依赖它们。**
> 侧系统对 POS 主库全程只读，也从不导入这些文件。

| 文件 | 是什么 | 用途 |
|---|---|---|
| `pos_schema.sql` | POS 主库 `coolroid` 的**完整结构**，190 张表，**无数据** | 搭模拟环境时照它建库（见 `tests/sim/README.md`） |
| `menu_item.sql` | 菜单表 753 行 + 若干订单表结构 | `db/seeds/003_meal_item_rule.sql` 的依据 —— 哪些 `menu_item_id` 算套餐、单价多少 |
| `history_order_detail.sql` | 订单明细样本 100 行 | 早期分析伪行（-2/-3/-4）与配料行时用的样本 |

## 要不要传到服务器

**不用。** 生产部署只需要 `wwwroot` / `app` / `bin` / `db`,
这个目录是开发与排查时的资料（见 `docs/07` §6 部署清单）。

## 数据是真实的

`menu_item.sql` 与 `history_order_detail.sql` 含真实经营数据。
仓库若要对外分享，先确认是否合适。
