-- ============================================================
-- 默认操作员账号
--
-- ★ 四个账号的初始 PIN 都是 admin123，【上线前必须全部改掉】。
--   PIN 用 password_hash(bcrypt) 存储，不可逆；下面是 admin123 的密文。
--   改口令走后台「操作员」页，或 php bin/init.php passwd <工号>。
--
-- 角色：1=服务员  2=经理  3=管理员
--
-- 幂等：已存在同名账号时【不覆盖口令】—— 否则重跑 seed 会把店家
--       改过的口令重置回 admin123。要重置请用 passwd 命令。
-- ============================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET @store := 'S001';
SET @now   := NOW();

INSERT INTO `operator`
  (`store_code`,`login_name`,`display_name`,`pin_hash`,`role`,`enabled`,`failed_count`,`created_at`,`updated_at`)
VALUES
(@store, 'admin', '系统管理员', '$2y$12$bFH5NBDQ5TAMEEn.WwBI0Olizn.rnhBJERNh9be.eulwk2JAUNpM2', 3, 1, 0, @now, @now),
(@store, 'manager', '经理', '$2y$12$rmfCpI7JjUjvtCq1xqkwFuX4hLY6R46nG/IPqJBKPxEm6BHq2X4Aq', 2, 1, 0, @now, @now),
(@store, 'cashier1', '收银员1', '$2y$12$xS38VmeieesuCmb7V69BauI.yvqObReBGqHclydS5v7k4ZSQ6iziW', 1, 1, 0, @now, @now),
(@store, 'cashier2', '收银员2', '$2y$12$2mNnGkEMKYaoAE8uLVlIJeo3p.dwg8GUJ8LghSg8bTYOqdb8KgVZG', 1, 1, 0, @now, @now)
ON DUPLICATE KEY UPDATE `updated_at` = VALUES(`updated_at`);
