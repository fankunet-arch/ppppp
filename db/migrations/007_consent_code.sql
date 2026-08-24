-- ============================================================
-- 007 · 现场确认码
--
-- 原方案的双重确认是「客人点短信里的链接」——那需要一个公网可达的端点
-- 来接收点击，而门店网络是【单向】的：门店能出去，外网进不来。
-- 这条路走不通，而且这个矛盾在原设计里没被发现。
--
-- 改成现场确认码：短信/邮件只发一个 6 位码（纯出站），客人当场报给
-- 收银员，Pad 里输入即完成确认。举证靠审计日志：发送时间、校验通过
-- 时间、经手的操作员，链条完整。
--
-- 代价：客人必须当场完成，走了就补不了（可让他下次到店时重发）。
--
-- ★ 兼容性同 001_init.sql，见 db/README.md
-- ============================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

ALTER TABLE `member`
  ADD COLUMN `consent_code_hash` VARCHAR(255) DEFAULT NULL
      COMMENT '现场确认码的 password_hash；绝不存明文，与卡背 PIN 同一套做法'
      AFTER `consent_token`,
  ADD COLUMN `consent_code_sent_at` DATETIME DEFAULT NULL
      COMMENT '确认码发出时间，举证用'
      AFTER `consent_code_hash`,
  ADD COLUMN `consent_code_expires` DATETIME DEFAULT NULL
      COMMENT '过期时间。码是当场用的，给足 30 分钟即可'
      AFTER `consent_code_sent_at`,
  ADD COLUMN `consent_code_fail` TINYINT NOT NULL DEFAULT 0
      COMMENT '连续输错次数，防穷举 6 位码'
      AFTER `consent_code_expires`,
  ADD COLUMN `consent_channel` VARCHAR(10) DEFAULT NULL
      COMMENT '确认码走的渠道：sms / email，举证时要说清发到哪里'
      AFTER `consent_code_fail`;
