<?php
declare(strict_types=1);

/**
 * 后台入口。
 *
 * 与 Pad 端同理：改成 .php 只是为了让资源 URL 能带上版本号，
 * 避免「代码传上去了，浏览器里还是旧页面」。说明见 wwwroot/_assets.php。
 *
 * 后台在普通浏览器里开，Ctrl+F5 还能救；Pad 上没有这个办法，
 * 所以那边更要紧 —— 但两边用同一套机制，省得日后只修一边。
 *
 * 🔴 nginx 要放行本文件，见 docs/06 §5。
 */
require __DIR__ . '/../_assets.php';

vip_no_store();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="referrer" content="same-origin">
<title>会员积分 · 管理后台</title>
<link rel="stylesheet" href="<?= vip_asset('cp/cp.css') ?>">
</head>
<body>

<section id="view-login" class="view active">
  <div class="login-box">
    <h1>管理后台</h1>
    <p class="muted small">仅经理及以上账号可登录</p>
    <label>工号<input id="login-name" type="text" autocomplete="username" autocapitalize="off"></label>
    <label>PIN<input id="login-pin" type="password" autocomplete="current-password"></label>
    <button id="btn-login" class="primary big">登录</button>
    <p id="login-err" class="err" hidden></p>
    <button id="btn-refresh-login" class="link">刷新页面</button>
  </div>
</section>

<section id="view-main" class="view">
  <header class="topbar">
    <b>会员积分管理后台</b>
    <nav class="tabs">
      <button class="tab on" data-tab="dashboard">概览</button>
      <button class="tab" data-tab="alerts">告警<span id="badge-alerts" class="badge" hidden></span></button>
      <button class="tab" data-tab="reviews">待复核<span id="badge-reviews" class="badge" hidden></span></button>
      <button class="tab" data-tab="rules">套餐规则</button>
      <button class="tab" data-tab="members">会员</button>
      <button class="tab" data-tab="report">报表</button>
      <button class="tab" data-tab="config">配置</button>
      <button class="tab" data-tab="coupons">奖励券</button>
      <button class="tab" data-tab="cards">发卡</button>
      <button class="tab" data-tab="operators">操作员</button>
      <button class="tab" data-tab="audit">审计</button>
    </nav>
    <span id="op-name" class="muted"></span>
    <button id="btn-refresh" class="link" title="重新加载页面">刷新</button>
    <button id="btn-logout" class="link">退出</button>
  </header>

  <!-- 常驻提醒：开了实名但确认短信还没接入之类，长期挂着直到解决 -->
  <div id="cp-warnings"></div>

  <!-- 概览 -->
  <div id="tab-dashboard" class="panel active">
    <div id="stats" class="stats"></div>
    <h3>同步水位线</h3>
    <div id="cursors"></div>
  </div>

  <!-- 告警 -->
  <div id="tab-alerts" class="panel">
    <h3>未处理告警</h3>
    <div id="alerts-list"></div>
  </div>

  <!-- 待复核 -->
  <div id="tab-reviews" class="panel">
    <h3>手工录入待复核</h3>
    <p class="muted small">主库数据缺失或不可达时收银员手工录入的记账，需与纸质小票核对后裁决。
      驳回会追加反向冲正流水，原流水保留。</p>
    <div id="reviews-list"></div>
  </div>

  <!-- 套餐规则 -->
  <div id="tab-rules" class="panel">
    <h3>套餐规则表</h3>
    <p class="muted small">三个开关互相独立。<b>未被本表覆盖的菜品</b>按
      「不算餐费 / 不计次 / 金额正常积分」处理 —— 漏配的后果仅是少计次，不会算错金额。</p>
    <div id="rules-list"></div>
    <details class="add-box">
      <summary>手工添加菜品规则</summary>
      <div class="row">
        <label>菜品 ID<input id="new-rule-id" type="number"></label>
        <label>名称<input id="new-rule-name" type="text"></label>
        <label>参考价<input id="new-rule-price" type="text" placeholder="0.00"></label>
      </div>
      <button id="btn-add-rule" class="primary">添加</button>
    </details>
  </div>

  <!-- 会员 -->
  <div id="tab-members" class="panel">
    <h3>会员查询</h3>
    <div class="row">
      <select id="m-type"><option value="card">卡号</option><option value="phone">手机号</option><option value="email">邮箱</option></select>
      <input id="m-value" type="text" placeholder="输入后回车">
      <button id="btn-m-search" class="primary">查找</button>
    </div>
    <div id="member-detail"></div>
  </div>

  <!-- 报表 -->
  <div id="tab-report" class="panel">
    <h3>营业日报表</h3>
    <div class="row">
      <label>最近<input id="rep-days" type="number" value="14" min="1" max="90" style="width:80px"></label>
      <button id="btn-report" class="primary">查询</button>
    </div>
    <div id="report-table"></div>
  </div>

  <!-- 配置 -->
  <div id="tab-config" class="panel">
    <h3>系统配置</h3>
    <p class="muted small">修改立即生效。改动会记入审计日志。</p>
    <div id="config-list"></div>
  </div>

  <!-- 操作员 -->
  <!-- 奖励券 -->
  <div id="tab-coupons" class="panel">
    <h3>奖励券</h3>
    <div id="coupon-rule" class="rule-banner"></div>
    <div id="coupon-stats" class="stats"></div>
    <details class="add-box">
      <summary>手工发一张券（补偿 / 投诉处理）</summary>
      <div class="row">
        <label>会员卡号<input id="cp-grant-card" type="text" placeholder="卡号"></label>
        <label>原因（必填）<input id="cp-grant-note" type="text" placeholder="如：投诉补偿"></label>
      </div>
      <button id="btn-coupon-grant" class="primary">发放</button>
      <p class="muted small">手工发放不占用客人靠消费攒来的那张，会单独记入审计日志。</p>
    </details>
    <div id="coupon-list"></div>
  </div>

  <div id="tab-cards" class="panel">
    <h3>实体卡发放</h3>
    <div id="card-stats" class="stats"></div>

    <details class="add-box">
      <summary>查一张卡（客人问「我这卡还能用吗」）</summary>
      <div class="row">
        <label>卡号<input id="cd-look" type="text" placeholder="扫码或手输，如 TK-00000123-4Q7"></label>
        <button id="btn-card-look" class="primary">查询</button>
      </div>
      <div id="card-look-result"></div>
    </details>

    <details class="add-box">
      <summary>挂失 / 作废一张卡</summary>
      <div class="row">
        <label>卡号<input id="cd-void" type="text" placeholder="卡号"></label>
        <label>原因（必填）<input id="cd-void-why" type="text" placeholder="如：客人报失"></label>
      </div>
      <button id="btn-card-void" class="primary">作废</button>
      <p class="muted small">
        作废后该会员会暂时没有卡，积分与流水都保留。下次到店扫一张新卡即可换发。
      </p>
    </details>

    <details class="add-box" id="cd-gen-box">
      <summary>生成新批次（交给印刷厂）</summary>
      <div class="row">
        <label>批次号<input id="cd-batch" type="text" placeholder="留空自动按日期生成"></label>
        <label>数量<input id="cd-count" type="number" min="1" max="5000" value="200"></label>
        <label>有效期至（必填）<input id="cd-valid" type="date"></label>
        <label>卡片等级<select id="cd-tier"></select></label>
      </div>
      <button id="btn-card-gen" class="primary">生成</button>
      <p class="err" style="font-weight:600">
        ⚠ 有效期必须与卡面印刷的日期<b>完全一致</b>。
      </p>
      <p class="muted small">
        客人查不到任何线上信息，手里只有一张卡 —— <b>卡面那行日期就是唯一的告知证据</b>。
        库里和卡面对不上，等于没有告知过。<br>
        建议取 <b>3 年后的 12 月 31 日</b>，与印刷稿一起定下来再回来填。
        单批最多 5000 张，顺序号自动接上一批，不会重号。
      </p>
      <p class="muted small">
        <b>卡片等级</b>整批统一 —— 印刷本来就是按批的。等级会印在卡面上，
        客人扫卡时系统就知道这张卡是什么级别。不用等级就选「不分级」。
      </p>
    </details>

    <div id="card-gen-result" hidden>
      <p class="err" id="card-gen-warn"></p>
      <p class="muted small">
        全选下面的内容复制，粘进 Excel 即可（制表符分隔）。
        <b>关掉这一块之后 PIN 就再也取不回来了</b>，只能作废整批重新生成。
      </p>
      <textarea id="card-gen-csv" rows="14" style="width:100%;font-family:monospace;font-size:12px"></textarea>
    </div>

    <h3 style="margin-top:24px">已有批次</h3>
    <div id="card-batches"></div>

    <h3 style="margin-top:24px">卡片等级</h3>
    <div id="tier-list"></div>
    <details class="add-box">
      <summary>新增 / 修改等级</summary>
      <div class="row">
        <label>标识<input id="tier-code" type="text" placeholder="gold" maxlength="20"></label>
        <label>名称（中文）<input id="tier-name" type="text" placeholder="金卡"></label>
        <label>名称（西语）<input id="tier-name-es" type="text" placeholder="Oro"></label>
        <label>积分倍率<input id="tier-mult" type="number" step="0.05" min="0.05" max="10" value="1.00" style="width:6em"></label>
        <label>几次送 1 次<input id="tier-thv" type="number" min="1" placeholder="跟随全局" style="width:7em"></label>
        <label>满额送 1 次<input id="tier-tha" type="text" placeholder="跟随全局" style="width:7em"></label>
        <label>排序<input id="tier-sort" type="number" value="10" style="width:5em"></label>
      </div>
      <button id="btn-tier-save" class="primary">保存</button>
      <p class="muted small">
        <b>标识</b>是给机器认的（小写字母、数字、下划线），定了就别改 ——
        已经发出去的卡是靠它认等级的。改名改的是「名称」，不影响任何已发的卡。<br>
        用同一个标识再保存一次就是修改。<b>停用</b>只是不再出现在上面的发卡下拉框里，
        已发出去的卡照常显示等级。
      </p>
      <p class="muted small">
        <b>积分倍率</b>叠在「积分规则」里那个全局倍率之上：<br>
        积分 = 金额 × 每欧元分数 × 全局倍率 × <b>本等级倍率</b>。1.00 就是与普通卡相同。<br>
        改倍率<b>只影响以后的入账</b> —— 每一笔流水都记着当时实际用的倍率，
        历史一行都不会变，客人来问「上次为什么给这么多分」时查得到。
      </p>
      <p class="muted small">
        <b>送 1 次的门槛</b>两格<b>留空即跟随「奖励规则」里的全局设置</b> ——
        只想优待金卡的话，只填金卡那一格就行，其余等级不用动。<br>
        按次还是按金额，取决于全局的「奖励模式」，这里两格各填各的。<br>
        改门槛<b>会立刻重算</b>：调低（比如升级成金卡）当场补发差额；
        调高<b>不会把已经发出去的券收回来</b> —— 收回已给出去的东西是绝不能做的。
      </p>
    </details>
  </div>

  <div id="tab-operators" class="panel">
    <h3>操作员</h3>
    <div id="operators-list"></div>
    <details class="add-box">
      <summary>新建操作员</summary>
      <div class="row">
        <label>工号<input id="op-login" type="text"></label>
        <label>显示名（中文）<input id="op-name-new" type="text"></label>
        <label>显示名（西语）<input id="op-name-es" type="text" placeholder="留空则用中文名"></label>
        <label>PIN<input id="op-pin" type="password"></label>
        <label>角色<select id="op-role">
          <option value="1">服务员</option><option value="2">经理</option><option value="3">管理员</option>
        </select></label>
      </div>
      <p class="muted small">
        Pad 顶栏按当前语言显示对应的名字 —— 要么全中文、要么全西文。
        西语名留空的话，西语界面下仍显示中文名。
      </p>
      <button id="btn-add-op" class="primary">创建</button>
    </details>

    <details class="add-box">
      <summary>改我自己的 PIN</summary>
      <div class="row">
        <label>当前 PIN<input id="my-old-pin" type="password" autocomplete="current-password"></label>
        <label>新 PIN<input id="my-new-pin" type="password" autocomplete="new-password"></label>
        <label>再输一次<input id="my-new-pin2" type="password" autocomplete="new-password"></label>
      </div>
      <button id="btn-change-pin" class="primary">修改</button>
      <p class="muted small">改完后，你在其他设备上的登录会全部失效，当前这台不受影响。</p>
    </details>
  </div>

  <!-- 审计 -->
  <div id="tab-audit" class="panel">
    <h3>审计日志</h3>
    <div class="row">
      <select id="audit-action">
        <option value="">全部</option>
        <option value="point_grant">发放积分</option>
        <option value="point_reverse">撤销/冲正</option>
        <option value="member_create">新建会员</option>
        <option value="data_erase">数据删除/假名化</option>
        <option value="coupon_redeem">卡券核销</option>
        <option value="config_save">配置修改</option>
        <option value="rule_save">规则修改</option>
        <option value="operator_login">登录</option>
      </select>
      <button id="btn-audit" class="primary">查询</button>
    </div>
    <div id="audit-list"></div>
  </div>
</section>

<div id="toast" class="toast" hidden></div>
<!-- 顺序即依赖：ui.js 必须先于 cp.js 加载 -->
<script src="<?= vip_asset('assets/ui.js') ?>"></script>
<script src="<?= vip_asset('cp/cp.js') ?>"></script>
</body>
</html>
