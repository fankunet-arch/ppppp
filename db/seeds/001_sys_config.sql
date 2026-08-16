-- ============================================================
-- 系统配置默认值  —— 对应 docs/04-本地库Schema.md §6.1
-- 门店码 S001，多店部署时按店复制
-- ============================================================

SET NAMES utf8mb4;
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
(@store,'consent_expire_days','30',@now),
  -- 未同意的会员：积分冻结 + PII 假名化的期限
(@store,'pii_retention_years','3',@now)
  -- 末次消费后保留年限

ON DUPLICATE KEY UPDATE
  `config_value` = VALUES(`config_value`),
  `updated_at`   = VALUES(`updated_at`);
