-- ============================================================
-- 系统配置默认值  —— 对应 docs/04-本地库Schema.md §6.1
-- 门店码 S001，多店部署时按店复制
-- ============================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET @store := 'S001';
SET @now   := NOW();

INSERT INTO `sys_config` (`store_code`,`config_key`,`config_value`,`updated_at`) VALUES

-- ── 订单定位 ──────────────────────────────────────────────
(@store,'order_lookup_window_min','30',@now),
  -- 收银员输桌号后往前查多少分钟。实测按 order_head_id 聚合后
  -- 30 分钟窗口歧义率仅 0.02%（docs/03 §1.2）
(@store,'lookup_fallback_window_min','60',@now),
  -- 首次查不到时的放宽窗口（降级路径，docs/03 §10.2）

-- ── 积分规则 ──────────────────────────────────────────────
(@store,'points_per_euro','1',@now),
(@store,'points_multiplier','1.0',@now),
  -- 1.0 = 不启用倍率
(@store,'points_include_tax','1',@now),
  -- 已确认：积分按含税价（IVA 10% 内含）
(@store,'free_meal_extra_earns','0',@now),
  -- 免费餐当次的额外消费（饮料甜品）是否计金额积分

-- ── 计次 ──────────────────────────────────────────────────
(@store,'visit_count_mode','by_portion',@now),
  -- by_portion = 按 counts_visit=1 菜品的 SUM(quantity) 计次（已确认采用）
  -- by_ledger  = 每笔流水最多计 1 次（备用口径）

-- ── 套餐规则巡检 ──────────────────────────────────────────
(@store,'meal_item_alert_price','8.00',@now),
  -- 全表扫 price_1 >= 此值且未被 meal_item_rule 覆盖的项 → 告警。
  -- 不可按 major_group 过滤：BOX/COMBO 在 major_group=1 而非 3。
  -- 取 8.00 而非 10.00：BOX 1 在 2024 年售价 9.00。

-- ── 营业日 ────────────────────────────────────────────────
(@store,'business_day_cutoff','02:00',@now),
  -- 已用 POS 自身数据验证：324/332 天完全一致（docs/01 §5.2）

-- ── 校准与防刷 ────────────────────────────────────────────
(@store,'sync_window_hours','48',@now),
  -- 滚动校准窗口。系统无法保证 24 小时开机，48 小时窗口
  -- 意味着漏掉一天也能自动补齐。
(@store,'verify_protect_days','30',@now),
  -- 值比对保护期
(@store,'sync_batch_size','100',@now),
  -- 每批 LIMIT，铁律上限 100
(@store,'sync_batch_sleep_ms','2000',@now),
  -- 批次间强制停顿
(@store,'sync_max_batches','200',@now),
  -- 单次任务批次数上限，超出记告警留到下次

-- ── 降级（数据缺失时的手工录入）──────────────────────────
(@store,'manual_entry_enabled','1',@now),
(@store,'manual_entry_limit','200.00',@now),
(@store,'manual_entry_daily_alert','5',@now),

-- ── 撤销 ──────────────────────────────────────────────────
(@store,'reversal_window_hours','24',@now),
  -- 自由撤销时间窗，超出需经理权限

-- ── 合规 ──────────────────────────────────────────────────
(@store,'member_collect_pii','0',@now),
  -- 默认【关闭】：卡片不实名，凭卡号 + 卡背 PIN 即可积分与兑换。
  -- 关闭时 Pad 上完全看不到手机号/邮箱/生日的输入框，后端也拒收 ——
  -- 系统在技术上就收不了个人信息，既不给收银员向客人索要的机会，
  -- 也不必为一个根本没在用的采集表单去应付合规检查。
  -- 注意：本种子是 ON DUPLICATE KEY UPDATE config_value，重跑 seed 会把它
  -- 重置回 0。方向是安全的（回到不收集），但门店若已开启需重新打开。
(@store,'consent_channel','auto',@now),
  -- 确认码渠道：auto = 有手机号发短信，否则发邮件。凭据在 config.php
(@store,'privacy_policy_url','',@now),
  -- 附在确认码消息里的隐私政策网址。留空则不附，但现场仍须口头告知
(@store,'consent_expire_days','30',@now),
  -- 未同意的会员：积分冻结 + PII 假名化的期限
(@store,'pii_retention_years','3',@now),
  -- 末次消费后保留年限

-- ── 奖励（N 送 1）★ 核心业务规则 ──────────────────────────
(@store,'reward_enabled','1',@now),
  -- 总开关。关掉后不再发券，已发的仍可核销。
(@store,'reward_mode','visits',@now),
  -- 门槛口径：visits=按次（集满 N 次送 1 次） / amount=按金额（累计消费满 X 元送 1 次）
(@store,'reward_threshold_visits','10',@now),
  -- 按次口径下的 N。改成 8 就是「八送一」，改完历史进度会自动重算。
(@store,'reward_threshold_amount','300.00',@now),
  -- 按金额口径下的 X（欧元）。
(@store,'reward_auto_grant','1',@now),
  -- 1=达标自动发券  0=只在后台提示，由人工发（适合想先人工把关的门店）
(@store,'coupon_valid_days','90',@now),
  -- 券有效期天数，0 = 永久有效。

-- ── 按小票号查单 ──────────────────────────────────────────
(@store,'invoice_lookup_max_days','7',@now),
  -- 小票 Factura Simplificada 号可回溯的最大天数（0 = 不限）。
  -- 小票可以隔天补记，但不该让人拿半年前的小票来领分。

-- ── 十送一核销识别 ────────────────────────────────────────
(@store,'redeem_line_patterns','TARJETA 10+1,10+1',@now)
  -- 明细里 menu_item_id = -2 的折扣行，名称命中这些子串（忽略大小写）
  -- 即判定为「十送一核销」，该单不计次不积分。逗号分隔，留空用内置默认。
  -- 实测 -2 折扣行共 4 种名称，只有 TARJETA 10+1 是核销；
  -- CUPON DE 5 EUROS（满50减5 纸质券）/ Dto% / Dto. -15% 都是普通折扣，绝不能误判。
  -- 名称会随店家调整而变（Dto. -20% 已被 Dto. -15% 取代），所以做成可配置。

-- ── 实体卡有效期 ──────────────────────────────────────────
,(@store,'card_expiring_soon_days','30',@now)
  -- 卡剩多少天到期时开始提醒收银员换卡。发卡时也用这个天数拦一道。
,(@store,'card_grace_months','6',@now)
  -- 过期后还能换卡结转积分的窗口。超过之后前台换不了，需经理强制换发。
  -- ⚠️ 宽限期内卡本身【不能用】—— 不能积分、不能兑换，只能换卡。

ON DUPLICATE KEY UPDATE
  `config_value` = VALUES(`config_value`),
  `updated_at`   = VALUES(`updated_at`);
