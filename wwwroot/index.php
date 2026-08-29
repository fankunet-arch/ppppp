<?php
declare(strict_types=1);

/**
 * Pad 前端入口。
 *
 * ★ 这里是 .php 而不是 .html，唯一的原因是【缓存】。
 *
 *   静态 HTML 没法告诉浏览器「我换了」，于是代码传上去了、Pad 上
 *   还是旧页面 —— 而 Pad 没有地址栏、没有开发者工具，
 *   连点「刷新」都没用（WebView 认为自己已经有 pad.js 了，不会再去取）。
 *
 *   由 PHP 发出来之后：
 *     · 页面本身 no-store，每次都重新取（就几 KB）
 *     · 资源 URL 带上 ?v=<文件改动时间>，没改的 URL 不变、照旧命中缓存，
 *       一个字节都不下；改了的 URL 变了，浏览器必然重新下载
 *
 *   所以「平时不下载、更新时立刻生效」这两件事是同时成立的。
 *
 * 🔴 nginx 要放行本文件，见 docs/06 §5。
 *    文档里那份配置有一条 `location ~ \.php$ { return 404; }`，
 *    不改的话这个页面会 404。
 */
require __DIR__ . '/_assets.php';

vip_no_store();

// 版本号取这几个文件里最新的 mtime；客户端拿它和 /health 比对
$appVersion = vip_app_version([
    'index.php', 'assets/pad.js', 'assets/pad.css',
    'assets/ui.js', 'assets/i18n.js', 'assets/sushivip-bridge.js',
]);
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="referrer" content="same-origin">
<title>会员积分 · Pad</title>
<link rel="stylesheet" href="<?= vip_asset('assets/pad.css') ?>">
</head>
<body>

<!-- ══ 登录 ══════════════════════════════════════════════ -->
<section id="view-login" class="view active">
  <div class="login-box">
    <h1 data-i18n="login.title">会员积分</h1>
    <label><span data-i18n="login.name">工号</span>
      <input id="login-name" type="text" inputmode="text" autocomplete="username" autocapitalize="off">
    </label>
    <label><span data-i18n="login.pin">PIN</span>
      <input id="login-pin" type="password" inputmode="numeric" autocomplete="current-password">
    </label>
    <button id="btn-login" class="primary big" data-i18n="login.submit">登录</button>
    <p id="login-err" class="err" hidden></p>
    <p class="muted small" id="health-note"></p>
    <button id="btn-refresh-login" class="link" data-i18n="login.refresh">刷新页面</button>
    <!-- 登录页的语言只存在这台平板上：这时还不知道是谁在登录。
         登录成功后一律以账号上的设置为准。 -->
    <div id="lang-login" class="lang-switch"></div>
  </div>
</section>

<!-- ══ 主界面 ════════════════════════════════════════════ -->
<section id="view-main" class="view">
  <header class="topbar">
    <span id="op-name" class="op"></span>
    <span id="pos-status" class="pill" hidden></span>
    <div id="lang-main" class="lang-switch"></div>
    <button id="btn-my-pin" class="link" data-i18n="top.changePin">改 PIN</button>
    <button id="btn-refresh" class="link" data-i18n="top.refresh" data-i18n-title="top.refreshTitle">刷新</button>
    <button id="btn-logout" class="link" data-i18n="top.logout">退出</button>
  </header>

  <!-- 步骤 1：找订单。两条路并存：有小票就输票号（精确），没有就输桌号 -->
  <div id="step-table" class="step active">
    <h2 data-i18n="step1.title">① 找订单</h2>

    <div class="lookup-tabs">
      <button id="tab-table"   class="lookup-tab on"  data-mode="table"   data-i18n="lookup.table">桌号</button>
      <button id="tab-invoice" class="lookup-tab"     data-mode="invoice" data-i18n="lookup.invoice">小票号</button>
    </div>

    <!-- 按桌号：默认路径。客人还在桌上，收银员手边就是桌号，最快 -->
    <div id="pane-table" class="lookup-pane">
      <div class="row">
        <input id="table-input" type="text" inputmode="numeric" data-i18n-ph="lookup.table" autocomplete="off">
        <button id="btn-locate" class="primary" data-i18n="lookup.find">查找订单</button>
      </div>
      <p class="muted small" id="table-hint"></p>
    </div>

    <!-- 按小票号：补救路径。客人拿着小票折返、或桌号对不上时用。
         Factura Simplificada = 全局唯一，不受 30 分钟时间窗限制 -->
    <div id="pane-invoice" class="lookup-pane" hidden>
      <div class="row">
        <input id="invoice-input" type="text" inputmode="numeric" data-i18n-ph="lookup.invoice" autocomplete="off">
        <button id="btn-locate-invoice" class="primary" data-i18n="lookup.find">查找订单</button>
      </div>
      <p class="muted small" data-i18n-html="lookup.invoiceHint"></p>
    </div>

    <p id="locate-err" class="err" hidden></p>
    <div id="locate-fallback" hidden>
      <button id="btn-widen" class="ghost"></button>
      <button id="btn-manual" class="ghost warn" data-i18n="lookup.useManual">改用手工录入</button>
    </div>

    <!-- 客人当面问「我这卡还能用吗」。放在第一步：这种问题都发生在两单之间，
         服务员不该为了回一句话去打断记账流程，更不该转给经理。 -->
    <button id="btn-ask-card" class="link" data-i18n="ask.entry">查一张卡</button>
  </div>

  <!-- 步骤 2：选订单 -->
  <div id="step-order" class="step">
    <h2 data-i18n="step2.title">② 选择订单</h2>
    <div id="order-list" class="cards"></div>
    <button class="ghost" data-back="step-table" data-i18n="common.back">返回</button>
  </div>

  <!-- 步骤 3：选记账方式 -->
  <div id="step-mode" class="step">
    <h2 data-i18n="step3.title">③ 记账方式</h2>
    <div id="order-summary" class="summary"></div>
    <div id="existing-ledger" class="ledger-box" hidden></div>
    <div class="modes">
      <button class="mode" data-mode="1"><b data-i18n="mode1.title"></b><span data-i18n="mode1.desc"></span></button>
      <button class="mode" data-mode="2"><b data-i18n="mode2.title"></b><span data-i18n="mode2.desc"></span></button>
      <button class="mode" data-mode="3"><b data-i18n="mode3.title"></b><span data-i18n="mode3.desc"></span></button>
    </div>
    <!-- 同行分桌：一大帮人坐了几桌、一起结账，积分都记给其中一位 -->
    <button id="btn-merge-start" class="ghost" data-i18n="merge.start">还有其他桌，一起记</button>
    <button id="btn-free-meal" class="ghost warn" data-i18n="freeMeal.btn">标记为免费餐</button>
    <button class="ghost" data-back="step-order" data-i18n="common.back">返回</button>
  </div>

  <!-- 步骤 3bis：多桌合并（只有整单一种记法，见 docs/03 §12.2） -->
  <div id="step-merge" class="step">
    <h2 data-i18n="merge.title">③ 多桌一起记</h2>
    <p class="muted small" data-i18n="merge.note"></p>
    <div id="merge-list" class="ledger-box"></div>
    <div class="totals">
      <span><span data-i18n="merge.sum">合计</span> <b id="merge-sum">0.00</b>
            · <span id="merge-count" class="muted small"></span></span>
    </div>
    <button id="btn-merge-add" class="ghost" data-i18n="merge.add">再加一桌</button>
    <div id="merge-member" class="ledger-box"></div>
    <button id="btn-merge-pick" class="primary" data-i18n="merge.pick">选择收分的会员</button>
    <p id="merge-err" class="err" hidden></p>
    <button id="btn-merge-submit" class="primary big" data-i18n="merge.submit">全部记给这张卡</button>
    <button id="btn-merge-cancel" class="ghost" data-i18n="common.cancel">取消</button>
  </div>

  <!-- 步骤 4：分配 -->
  <div id="step-assign" class="step">
    <h2>④ <span id="assign-title"></span></h2>
    <!-- 份数明细：买单人数 / 付费套餐 / 免费套餐，全部读自 POS 明细 -->
    <div id="portion-detail" class="portion-detail" hidden></div>
    <div id="assign-body"></div>
    <div id="assign-people" class="people"></div>
    <div class="totals">
      <span><span data-i18n="assign.allocated">已分配</span> <b id="sum-alloc">0.00</b>
            / <span data-i18n="assign.total">可分配</span> <b id="sum-total">0.00</b></span>
      <span><span data-i18n="assign.portions">份数</span> <b id="sum-port">0</b> / <b id="sum-port-total">0</b></span>
    </div>
    <button id="btn-submit" class="primary big" data-i18n="assign.submit">提交积分</button>
    <p id="assign-err" class="err" hidden></p>
    <button class="ghost" data-back="step-mode" data-i18n="common.back">返回</button>
  </div>

  <!-- 步骤 5：结果 -->
  <div id="step-done" class="step">
    <h2 data-i18n="done.title">✓ 记账完成</h2>
    <div id="done-body" class="cards"></div>
    <button id="btn-new" class="primary big" data-i18n="done.next">下一单</button>
  </div>

  <!-- 手工录入 -->
  <div id="step-manual" class="step">
    <h2 data-i18n="manual.title">手工录入（降级）</h2>
    <p class="muted small" data-i18n="manual.note"></p>
    <div id="manual-member"></div>
    <label><span data-i18n="manual.amount">金额（欧元）</span><input id="manual-amount" type="text" inputmode="decimal" placeholder="0.00"></label>
    <label><span data-i18n="manual.reason">原因</span>
      <select id="manual-reason">
        <option value="system_not_found" data-i18n="manual.rNotFound">系统未查到订单</option>
        <option value="network_error"    data-i18n="manual.rNetwork">网络/主库故障</option>
        <option value="other"            data-i18n="manual.rOther">其他</option>
      </select>
    </label>
    <button id="btn-manual-submit" class="primary big" data-i18n="common.submit">提交</button>
    <p id="manual-err" class="err" hidden></p>
    <button class="ghost" data-back="step-table" data-i18n="common.back">返回</button>
  </div>
</section>

<!-- ══ 改自己的 PIN ══════════════════════════════════════ -->
<div id="pin-modal" class="modal" hidden>
  <div class="modal-box">
    <h3 data-i18n="pin.title">修改我的 PIN</h3>
    <label><span data-i18n="pin.old">当前 PIN</span>
      <input id="pin-old" type="password" inputmode="numeric" autocomplete="current-password"></label>
    <label><span data-i18n="pin.new">新 PIN（至少 6 位）</span>
      <input id="pin-new" type="password" inputmode="numeric" autocomplete="new-password"></label>
    <label><span data-i18n="pin.new2">再输一次</span>
      <input id="pin-new2" type="password" inputmode="numeric" autocomplete="new-password"></label>
    <p id="pin-err" class="err" hidden></p>
    <p class="muted small" data-i18n="pin.note"></p>
    <button id="btn-pin-submit" class="primary big" data-i18n="pin.submit">确认修改</button>
    <button id="btn-pin-cancel" class="ghost" data-i18n="common.cancel">取消</button>
  </div>
</div>

<!-- ══ 会员选择弹层 ══════════════════════════════════════ -->
<div id="member-modal" class="modal" hidden>
  <div class="modal-box">
    <h3 data-i18n="member.title">选择会员</h3>
    <div class="seg" id="search-type">
      <button class="on" data-type="card"  data-i18n="member.byCard">卡号</button>
      <button data-type="phone" data-i18n="member.byPhone">手机号</button>
      <button data-type="email" data-i18n="member.byEmail">邮箱</button>
    </div>
    <!-- 后台关掉「允许收集客人联系方式」时，上面两档置灰，这一句说明为什么 -->
    <p class="muted small" id="pii-off-note" hidden></p>
    <div class="row">
      <input id="member-input" type="text" autocomplete="off" data-i18n-ph="member.inputPh">
      <button id="btn-scan" class="primary" data-i18n="member.scan">扫卡</button>
      <button id="btn-member-search" class="primary" data-i18n="member.search">查找</button>
    </div>
    <p class="muted small" id="scan-note" data-i18n-html="member.scanNote"></p>
    <div id="member-result"></div>
    <hr>
    <details id="member-new">
      <summary data-i18n="member.newSummary">用这张卡建新会员</summary>
      <p class="muted small" id="new-card-hint"></p>
      <p class="muted small" data-i18n="member.anonNote"></p>
      <button id="btn-member-create" class="primary big" data-i18n="member.activate">启用这张卡</button>

      <details id="new-contact">
        <summary data-i18n="contact.summary">选填：留联系方式</summary>
        <p class="muted small" data-i18n-html="contact.note"></p>
        <label><span data-i18n="contact.phone">手机号</span><input id="new-phone" type="tel" inputmode="tel" autocomplete="off"></label>
        <label><span data-i18n="contact.email">邮箱</span><input id="new-email" type="email" inputmode="email" autocomplete="off"></label>
        <label><span data-i18n="contact.birthday">生日</span><input id="new-birthday" type="date"></label>
      </details>
    </details>
    <p id="member-err" class="err" hidden></p>
    <button id="btn-member-close" class="ghost" data-i18n="common.cancel">取消</button>
  </div>
</div>

<!-- ══ 查一张卡（只读，不改动任何东西） ══════════════════ -->
<div id="ask-modal" class="modal" hidden>
  <div class="modal-box">
    <h3 data-i18n="ask.title">这张卡还能用吗</h3>
    <div class="row">
      <input id="ask-input" type="text" autocomplete="off" data-i18n-ph="member.phCard">
      <button id="btn-ask-scan" class="primary" data-i18n="member.scan">扫卡</button>
      <button id="btn-ask-go" class="primary" data-i18n="ask.query">查询</button>
    </div>
    <p class="muted small" data-i18n="ask.hint"></p>
    <div id="ask-result"></div>
    <p id="ask-err" class="err" hidden></p>
    <button id="btn-ask-close" class="ghost" data-i18n="common.cancel">取消</button>
  </div>
</div>

<!-- ══ 扫码弹层 ══════════════════════════════════════════ -->
<div id="scan-modal" class="modal" hidden>
  <div class="modal-box">
    <h3 data-i18n="scan.title">扫描会员卡</h3>
    <!-- 这三个属性缺任意一个，部分场景下取景框不起播（容器文档 2.3） -->
    <video id="scan-video" autoplay playsinline muted
           style="width:100%;border-radius:10px;background:#000;aspect-ratio:4/3"></video>
    <p id="scan-msg" class="muted small" data-i18n="scan.aim"></p>
    <p id="scan-err" class="err" hidden></p>
    <button id="btn-scan-cancel" class="ghost" data-i18n="common.cancel">取消</button>
  </div>
</div>

<div id="toast" class="toast" hidden></div>

<!-- 版本号：pad.js 用它和 /health 返回的值比对，不一致说明手里这份是旧的 -->
<script>window.APP_VERSION = <?= json_encode($appVersion) ?>;</script>

<!-- 顺序即依赖：桥接、通用 UI、词典都必须先于 pad.js 加载（都是同步脚本） -->
<script src="<?= vip_asset('assets/sushivip-bridge.js') ?>"></script>
<script src="<?= vip_asset('assets/ui.js') ?>"></script>
<script src="<?= vip_asset('assets/i18n.js') ?>"></script>
<script src="<?= vip_asset('assets/pad.js') ?>"></script>
</body>
</html>
