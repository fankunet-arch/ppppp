/* 管理后台逻辑 —— 原生 JS，无构建步骤 */
'use strict';

const $  = (s, r = document) => r.querySelector(s);
const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));
const API = '/cp/api.php';

const esc = s => String(s ?? '').replace(/[&<>"']/g, c =>
  ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

function toast(m, k = '') {
  const t = $('#toast'); t.textContent = m; t.className = 'toast ' + k; t.hidden = false;
  clearTimeout(toast._t); toast._t = setTimeout(() => { t.hidden = true; }, 3000);
}

async function api(path, body, method = 'POST') {
  const opt = { method, headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin' };
  if (body !== undefined && method !== 'GET') opt.body = JSON.stringify(body);
  /**
   * ★ 「连不上」与「连上了但没回 JSON」必须分开报。
   *
   * 早先两者共用一句「无法连接服务」，而实测下来这一句至少对应三种
   * 完全不同的故障：服务真没起来、API 路径不对（Web 服务器把 index.html
   * 当成响应回了 200）、以及服务端 PHP 致命错误吐 HTML。
   * 只有第一种才是网络问题，另外两种去查网线永远查不到。
   */
  let res, j, raw = '';
  try {
    res = await fetch(API + path, opt);
  } catch (e) {
    throw { error: 'network', message: '无法连接服务，请检查 Web 服务是否在运行' };
  }
  try {
    raw = await res.text();
    j   = JSON.parse(raw);
  } catch (e) {
    const head = raw.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 160);
    throw {
      error: 'bad_response',
      message: `服务器返回的不是 JSON（HTTP ${res.status}）\n${head || '（响应为空）'}`,
    };
  }
  if (!res.ok || j.ok === false) throw { error: j.error, message: j.message || '操作失败', detail: j.detail };
  return j.data;
}

/* ── 登录 ─────────────────────────────────────────── */
$('#btn-login').onclick = async () => {
  $('#login-err').hidden = true;
  try {
    const d = await api('/auth/login', {
      login_name: $('#login-name').value.trim(), pin: $('#login-pin').value,
    });
    window.SMS_READY = !!d.sms_ready;
    renderWarnings(d.warnings);
    enterMain(d.operator);
  } catch (e) {
    $('#login-err').textContent = e.error === 'forbidden' ? '该账号无后台权限（需经理及以上）' : e.message;
    $('#login-err').hidden = false;
  }
};
$('#login-pin').addEventListener('keydown', e => { if (e.key === 'Enter') $('#btn-login').click(); });

/* 容器无地址栏、无刷新按钮，卡住时只能杀进程 —— 自己提供入口 */
$$('#btn-refresh, #btn-refresh-login').forEach(b => {
  b.onclick = () => location.reload();
});

$('#btn-logout').onclick = async () => {
  try { await api('/auth/logout', {}); } catch {}
  $('#view-main').classList.remove('active');
  $('#view-login').classList.add('active');
};

/**
 * 渲染常驻提醒。
 *
 * 「开了实名但确认短信还没接入」这类问题不会自己暴露 —— 客人收不到确认
 * 链接，积分默默冻结着，等有人来投诉才发现。所以让它一直挂在顶栏下面，
 * 直到问题解决为止。
 */
function renderWarnings(list) {
  const box = $('#cp-warnings');
  if (!box) return;
  box.innerHTML = (list || []).map(w =>
    `<div class="warnbar"><b>⚠ 待处理</b>　${esc(w.text)}</div>`).join('');
}

function enterMain(op) {
  $('#op-name').textContent = op.name + '（' + ({ 2: '经理', 3: '管理员' }[op.role] || '') + '）';
  $('#view-login').classList.remove('active');
  $('#view-main').classList.add('active');
  window.IS_ADMIN = op.role >= 3;
  loadDashboard();
}

/* ── 标签页 ───────────────────────────────────────── */
const LOADERS = {
  dashboard: loadDashboard, alerts: loadAlerts, reviews: loadReviews,
  rules: loadRules, members: () => {}, report: loadReport,
  config: loadConfig, operators: loadOperators, audit: loadAudit, coupons: loadCoupons,
  cards: loadCards,
};
$$('.tab').forEach(t => t.onclick = () => {
  $$('.tab').forEach(x => x.classList.toggle('on', x === t));
  $$('.panel').forEach(p => p.classList.toggle('active', p.id === 'tab-' + t.dataset.tab));
  (LOADERS[t.dataset.tab] || (() => {}))();
});

/* ── 概览 ─────────────────────────────────────────── */
async function loadDashboard() {
  const d = await api('/dashboard', undefined, 'GET');
  const cards = [
    ['今日订单', d.orders_today], ['今日记账笔数', d.granted_today],
    ['今日积分', d.points_today], ['会员总数', d.members_total],
    ['待确认会员', d.members_pending, d.members_pending > 0 ? 'warn' : ''],
    ['待复核', d.reviews_pending, d.reviews_pending > 0 ? 'warn' : ''],
    ['未处理告警', d.alerts_open, d.alerts_open > 0 ? 'warn' : ''],
    ['严重告警', d.alerts_severe, d.alerts_severe > 0 ? 'err' : ''],
  ];
  $('#stats').innerHTML = cards.map(([k, n, cls]) =>
    `<div class="stat ${cls || ''}"><div class="n">${n}</div><div class="k">${k}</div></div>`).join('');

  $('#cursors').innerHTML = d.cursors.length ? `<table><tr><th>任务</th><th>水位线</th><th>滞后</th><th>上次执行</th><th>状态</th></tr>${
    d.cursors.map(c => `<tr class="${c.stale ? 'sev3' : ''}">
      <td>${esc(c.name)}</td><td>${esc(c.watermark)}</td>
      <td class="num">${c.lag_hours} 小时 ${c.stale ? '<span class="tag err">Cron 可能长期未成功</span>' : ''}</td>
      <td>${esc(c.last_run_at || '从未')}</td>
      <td>${{ 1: '<span class="tag on">成功</span>', 2: '<span class="tag warn">部分成功</span>', 3: '<span class="tag err">失败</span>' }[c.last_status] || '-'}
          ${c.last_error ? `<div class="muted small">${esc(c.last_error)}</div>` : ''}</td></tr>`).join('')
  }</table>` : '<div class="empty">尚无同步记录 —— Cron 可能还没跑过</div>';

  badge('#badge-alerts', d.alerts_open);
  badge('#badge-reviews', d.reviews_pending);
}
function badge(sel, n) {
  const b = $(sel); b.textContent = n; b.hidden = !n;
}

/* ── 告警 ─────────────────────────────────────────── */
const SEV = { 1: ['提示', ''], 2: ['警告', 'warn'], 3: ['严重', 'err'] };
async function loadAlerts() {
  const d = await api('/alerts', undefined, 'GET');
  $('#alerts-list').innerHTML = d.alerts.length ? `<table>
    <tr><th>级别</th><th>类型</th><th>内容</th><th>时间</th><th>操作</th></tr>${
    d.alerts.map(a => `<tr class="sev${a.severity}">
      <td><span class="tag ${SEV[a.severity][1]}">${SEV[a.severity][0]}</span></td>
      <td>${esc(a.type)}</td>
      <td>${esc(a.message)}${a.ref_id ? `<div class="muted small">${esc(a.ref_type)} ${esc(a.ref_id)}</div>` : ''}</td>
      <td class="muted small">${esc(a.created_at)}</td>
      <td><button class="tiny" data-ah="${a.id}" data-st="1">已处理</button>
          <button class="tiny" data-ah="${a.id}" data-st="2">忽略</button></td></tr>`).join('')
  }</table>` : '<div class="empty">没有未处理告警</div>';

  $$('[data-ah]').forEach(b => b.onclick = async () => {
    await api('/alerts/handle', { id: +b.dataset.ah, status: +b.dataset.st });
    toast('已更新', 'ok'); loadAlerts(); loadDashboard();
  });
}

/* ── 待复核 ───────────────────────────────────────── */
const MREASON = { system_not_found: '系统未查到订单', network_error: '网络/主库故障', other: '其他' };
async function loadReviews() {
  const d = await api('/reviews', undefined, 'GET');
  $('#reviews-list').innerHTML = d.reviews.length ? `<table>
    <tr><th>时间</th><th>会员</th><th>金额</th><th>积分</th><th>原因</th><th>录入人</th><th>裁决</th></tr>${
    d.reviews.map(r => `<tr>
      <td class="muted small">${esc(r.created_at)}</td>
      <td>#${r.member_id}</td>
      <td class="num">€ ${esc(r.amount)}</td>
      <td class="num">${r.points}</td>
      <td>${esc(MREASON[r.manual_reason] || r.manual_reason || '')}</td>
      <td>${esc(r.operator || '')}<div class="muted small">${esc(r.device || '')}</div></td>
      <td><button class="tiny primary" data-rv="${r.id}" data-ac="1">通过</button>
          <button class="tiny" data-rv="${r.id}" data-ac="0">驳回</button></td></tr>`).join('')
  }</table>` : '<div class="empty">没有待复核的手工录入</div>';

  $$('[data-rv]').forEach(b => b.onclick = async () => {
    const accept = b.dataset.ac === '1';
    let reason = '';
    if (!accept) {
      reason = await UI.input('驳回原因（会追加反向冲正流水，原流水保留）', {
        value: '与小票核对不符', okText: '驳回并冲正', danger: true,
      });
      if (reason === null) return;
    }
    try {
      await api('/reviews/decide', { id: +b.dataset.rv, accept, reason });
      toast(accept ? '已通过' : '已驳回并冲正', 'ok');
      loadReviews(); loadDashboard();
    } catch (e) { toast(e.message, 'err'); }
  });
}

/* ── 套餐规则 ─────────────────────────────────────── */
async function loadRules() {
  const d = await api('/rules', undefined, 'GET');
  $('#rules-list').innerHTML = `<table>
    <tr><th>菜品 ID</th><th>名称</th><th>参考价</th>
        <th title="免费餐兜底判据用">算餐费</th>
        <th title="十送一计次">参与计次</th>
        <th title="金额是否计入积分基数">金额积分</th>
        <th>启用</th><th>更新时间</th></tr>${
    d.rules.map(r => `<tr>
      <td>${r.menu_item_id}</td><td>${esc(r.item_name || '')}</td>
      <td class="num">${r.ref_price ? '€ ' + esc(r.ref_price) : ''}</td>
      ${['is_meal_fee', 'counts_visit', 'earns_points', 'enabled'].map(k =>
        `<td><span class="tag switch ${r[k] ? 'on' : 'off'}" data-rid="${r.menu_item_id}" data-key="${k}">${r[k] ? '是' : '否'}</span></td>`).join('')}
      <td class="muted small">${esc(r.updated_at || '')}</td></tr>`).join('')
  }</table>`;

  if (!window.IS_ADMIN) {
    $$('.switch').forEach(s => { s.style.cursor = 'default'; s.title = '需管理员权限'; });
    return;
  }
  $$('.switch').forEach(s => s.onclick = async () => {
    const row = d.rules.find(x => x.menu_item_id === +s.dataset.rid);
    const patch = { ...row, [s.dataset.key]: !row[s.dataset.key] };
    try {
      await api('/rules/save', patch);
      toast('已保存', 'ok'); loadRules();
    } catch (e) { toast(e.message, 'err'); }
  });
}

$('#btn-add-rule').onclick = async () => {
  const id = +$('#new-rule-id').value;
  if (!id) return toast('请填写菜品 ID', 'err');
  try {
    await api('/rules/save', {
      menu_item_id: id, item_name: $('#new-rule-name').value.trim(),
      ref_price: $('#new-rule-price').value.trim(),
      is_meal_fee: false, counts_visit: false, earns_points: true, enabled: true,
    });
    toast('已添加', 'ok'); loadRules();
  } catch (e) { toast(e.message, 'err'); }
};

/* ── 会员 ─────────────────────────────────────────── */
const ETYPE = { 1: '消费积分', 2: '撤销冲正', 3: '退单冲正', 4: '兑换扣减', 5: '过期清零', 6: '手工调整' };
$('#btn-m-search').onclick = doMemberSearch;
$('#m-value').addEventListener('keydown', e => { if (e.key === 'Enter') doMemberSearch(); });

async function doMemberSearch() {
  const v = $('#m-value').value.trim();
  if (!v) return;
  try {
    const d = await api('/members/search', { type: $('#m-type').value, value: v });
    if (!d.found) { $('#member-detail').innerHTML = '<div class="empty">未找到该会员</div>'; return; }
    const m = d.member;
    const CST = { 0: '<span class="tag warn">待确认</span>', 1: '<span class="tag on">已确认</span>',
                  2: '<span class="tag off">已撤回</span>', 3: '<span class="tag err">已过期</span>' };
    $('#member-detail').innerHTML = `
      <div class="stats">
        <div class="stat"><div class="n">${m.points_balance}</div><div class="k">积分余额</div></div>
        <div class="stat"><div class="n">${m.visit_count}</div><div class="k">累计次数</div></div>
        <div class="stat"><div class="n">€ ${esc(m.total_spent)}</div><div class="k">累计消费</div></div>
      </div>
      <p><b>${esc(m.card_no)}</b> ${CST[m.consent_status] || ''}
        <span class="muted small">　手机 ${esc(m.phone || '—')}　邮箱 ${esc(m.email || '—')}　注册 ${esc(m.created_at)}</span>
        ${window.IS_ADMIN ? `<button class="tiny" id="btn-erase" style="margin-left:10px">删除请求（假名化）</button>` : ''}</p>
      <h3>积分流水</h3>
      <table><tr><th>时间</th><th>类型</th><th>订单</th><th>金额</th><th>积分</th><th>计次</th><th>来源</th><th>操作员</th></tr>${
        d.ledger.map(l => `<tr${l.status === 2 ? ' class="muted"' : ''}>
          <td class="muted small">${esc(l.created_at)}</td>
          <td>${ETYPE[l.entry_type] || l.entry_type}${l.status === 2 ? ' <span class="tag off">已撤销</span>' : ''}</td>
          <td class="muted small">${esc(l.serial_id || '—')}</td>
          <td class="num">€ ${esc(l.amount)}</td>
          <td class="num">${l.points}</td><td class="num">${l.visits}</td>
          <td>${l.source === 2 ? '<span class="tag warn">手工</span>' : 'POS'}</td>
          <td>${esc(l.operator || '')}</td></tr>`).join('')
      }</table>`;
    const eb = $('#btn-erase');
    if (eb) eb.onclick = async () => {
      if (!await UI.confirm('确认执行删除请求？\n\nPII（手机号/邮箱/生日）将被抹除且不可恢复，\n积分流水按会计与税务留存义务保留。',
                            { okText: '确认删除', danger: true })) return;
      try {
        await api('/members/erase', { member_id: m.id, reason: '数据主体删除请求' });
        toast('已假名化，流水保留', 'ok');
        $('#member-detail').innerHTML = '<div class="empty">该会员已假名化</div>';
      } catch (e) { toast(e.message, 'err'); }
    };
  } catch (e) { toast(e.message, 'err'); }
}

/* ── 报表 ─────────────────────────────────────────── */
$('#btn-report').onclick = loadReport;
async function loadReport() {
  const d = await api('/report/daily', { days: +$('#rep-days').value || 14 });
  $('#report-table').innerHTML = d.rows.length ? `<table>
    <tr><th>营业日</th><th>订单数</th><th>已记账</th><th>可积分总额</th><th>已分配</th><th>免费餐</th></tr>${
    d.rows.map(r => `<tr><td>${esc(r.business_date)}</td>
      <td class="num">${r.orders}</td><td class="num">${r.granted_orders}</td>
      <td class="num">€ ${esc(r.total_amount)}</td><td class="num">€ ${esc(r.allocated_amount)}</td>
      <td class="num">${r.free_meals}</td></tr>`).join('')
  }</table>` : '<div class="empty">暂无数据</div>';
}

/* ── 配置 ─────────────────────────────────────────── */
// 配置项的标签与说明由后端 ConfigSchema 提供（app/lib/ConfigSchema.php），
// 前端不再维护第二份 —— 之前两边不同步，界面上还写着早已改名的 by_ledger。
async function loadConfig() {
  const d = await api('/config', undefined, 'GET');
  const box = $('#config-list');
  const ro  = !window.IS_ADMIN;

  // 当前奖励规则用一句人话顶在最上面，店家一眼看到现在是几送一
  const banner = `<div class="rule-banner">当前奖励规则：<b>${esc(d.reward_text)}</b></div>`;

  box.innerHTML = banner + d.groups.map(g => {
    const normal = g.items.filter(i => !i.advanced);
    const adv    = g.items.filter(i => i.advanced);
    if (!normal.length && !adv.length) return '';
    const rows = list => list.map(it => cfgRow(it, ro)).join('');
    return `<section class="cfg-group">
      <h4>${esc(g.title)}</h4>
      ${g.desc ? `<p class="muted small">${esc(g.desc)}</p>` : ''}
      <div class="cfg-items">${rows(normal)}</div>
      ${adv.length ? `<details class="cfg-adv"><summary>技术参数（${adv.length} 项，一般不用动）</summary>
        <div class="cfg-items">${rows(adv)}</div></details>` : ''}
    </section>`;
  }).join('');

  if (ro) return;
  $$('[data-cs]').forEach(b => b.onclick = () => saveCfg(b.dataset.cs));
  // 开关类改完立刻存，不用再点保存
  $$('.cfg-items input[type=checkbox]').forEach(c => c.onchange = () => saveCfg(c.dataset.ck));
  $$('.cfg-items select').forEach(sel => sel.onchange = () => saveCfg(sel.dataset.ck));
}

/** 渲染一项配置。类型决定用什么控件 —— 开关就是开关，别让人填 0/1 */
function cfgRow(it, ro) {
  const dis = ro ? ' disabled' : '';
  let ctrl;
  if (it.type === 'bool') {
    ctrl = `<label class="switch-wrap">
      <input type="checkbox" data-ck="${esc(it.key)}"${it.value === '1' ? ' checked' : ''}${dis}>
      <span>${it.value === '1' ? '已开启' : '已关闭'}</span></label>`;
  } else if (it.type === 'select') {
    ctrl = `<select data-ck="${esc(it.key)}"${dis}>${
      Object.entries(it.options || {}).map(([v, t]) =>
        `<option value="${esc(v)}"${v === it.value ? ' selected' : ''}>${esc(t)}</option>`).join('')
    }</select>`;
  } else {
    const mode = (it.type === 'int' || it.type === 'decimal') ? ' inputmode="decimal"' : '';
    ctrl = `<span class="cfg-input">
      <input data-ck="${esc(it.key)}" value="${esc(it.value)}"${mode}${dis}>
      ${it.unit ? `<em>${esc(it.unit)}</em>` : ''}
      ${ro ? '' : `<button class="tiny primary" data-cs="${esc(it.key)}">保存</button>`}</span>`;
  }
  return `<div class="cfg-item">
    <div class="cfg-label">${esc(it.label)}<code>${esc(it.key)}</code></div>
    <div class="cfg-ctrl">${ctrl}</div>
    <div class="cfg-desc muted small">${esc(it.desc)}</div>
  </div>`;
}

async function saveCfg(key) {
  const el = $(`[data-ck="${key}"]`);
  const val = el.type === 'checkbox' ? (el.checked ? '1' : '0') : el.value;

  /**
   * 开启「收集客人联系方式」而确认短信还没接入 —— 先拦一下。
   *
   * 这种状态不会自己暴露：客人留了手机号却收不到确认链接，积分默默冻结着，
   * 等有人来投诉才发现。所以开启时明确告知，开启之后后台再挂一条常驻红条。
   */
  if (key === 'member_collect_pii' && val === '1' && window.SMS_READY === false) {
    const go = await UI.confirm(
      '确认短信/邮件目前尚未接入。\n\n' +
      '现在开启的话，留了手机号或邮箱的客人【收不到确认链接】，' +
      '他们的积分会一直冻结、无法兑换。\n\n' +
      '确定要开启吗？',
      { okText: '仍然开启', danger: true }
    );
    if (!go) { loadConfig(); return; }   // 取消 → 把复选框状态复原
  }

  try {
    const r = await api('/config/save', { key, value: val });
    if (r && r.warnings) renderWarnings(r.warnings);
    toast('已保存', 'ok');
    loadConfig();          // 重载：奖励规则那句话要跟着变
  } catch (e) {
    toast(e.message + (e.detail?.hint ? '：' + e.detail.hint : ''), 'err');
    loadConfig();          // 存失败就把界面复原，别让人以为改成功了
  }
}

/* ── 奖励券 ───────────────────────────────────────── */
const CSRC = { 1: '满次自动', 2: '满额自动', 3: '手工发放' };
const CST  = { 1: ['可用', 'on'], 2: ['已核销', ''], 3: ['已过期', 'warn'], 4: ['已作废', 'err'] };

async function loadCoupons() {
  const d = await api('/coupons', undefined, 'GET');
  $('#coupon-rule').innerHTML = `当前奖励规则：<b>${esc(d.rule)}</b>`;
  $('#coupon-stats').innerHTML = [
    ['可用', d.stats.active], ['已核销', d.stats.redeemed],
    ['已过期', d.stats.expired, d.stats.expired > 0 ? 'warn' : ''],
    ['已作废', d.stats.void], ['累计发放', d.stats.total],
  ].map(([k, v, cls]) => `<div class="stat ${cls || ''}"><div class="n">${v}</div><div class="k">${k}</div></div>`).join('');

  $('#coupon-list').innerHTML = d.coupons.length ? `<table>
    <tr><th>券码</th><th>会员</th><th>来源</th><th>状态</th><th>有效期至</th><th>核销时间</th><th>备注</th><th></th></tr>${
    d.coupons.map(c => {
      const st = CST[c.status] || ['?', ''];
      return `<tr>
        <td><code>${esc(c.code)}</code></td>
        <td>${esc(c.card_no || '(已删除)')}</td>
        <td>${CSRC[c.source] || c.source}</td>
        <td><span class="tag ${st[1]}">${st[0]}</span></td>
        <td class="muted small">${esc(c.valid_to || '永久')}</td>
        <td class="muted small">${esc(c.redeemed_at || '')}${c.redeemed_serial_id ? '<br>单 ' + esc(c.redeemed_serial_id) : ''}</td>
        <td class="muted small">${esc(c.note || '')}</td>
        <td>${+c.status === 1 ? `<button class="tiny" data-cv="${c.id}" data-cvc="${esc(c.code)}">作废</button>` : ''}</td>
      </tr>`;
    }).join('')}</table>` : '<div class="empty">还没有发出任何券</div>';

  $$('[data-cv]').forEach(b => b.onclick = async () => {
    const why = await UI.input(`作废券 ${b.dataset.cvc} 的原因：`, {
      okText: '确认作废', danger: true,
    });
    if (why === null || !why.trim()) return;
    try { await api('/coupons/void', { id: +b.dataset.cv, reason: why }); toast('已作废', 'ok'); loadCoupons(); }
    catch (e) { toast(e.message, 'err'); }
  });
}

$('#btn-coupon-grant').onclick = async () => {
  const card = $('#cp-grant-card').value.trim(), note = $('#cp-grant-note').value.trim();
  if (!card || !note) return toast('请填卡号与原因', 'err');
  try {
    const m = await api('/members/search', { type: 'card', value: card });
    if (!m.found) return toast('查不到该卡号', 'err');
    await api('/coupons/grant', { member_id: m.member.id, note });
    toast('已发放', 'ok');
    $('#cp-grant-card').value = ''; $('#cp-grant-note').value = '';
    loadCoupons();
  } catch (e) { toast(e.message + (e.detail?.hint ? '：' + e.detail.hint : ''), 'err'); }
};

/* ── 操作员 ───────────────────────────────────────── */
const ROLE = { 1: '服务员', 2: '经理', 3: '管理员' };
async function loadOperators() {
  const d = await api('/operators', undefined, 'GET');
  $('#operators-list').innerHTML = `<table>
    <tr><th>工号</th><th>显示名（中文）</th><th>显示名（西语）</th><th>角色</th><th>状态</th><th>最后登录</th>${window.IS_ADMIN ? '<th></th>' : ''}</tr>${
    d.operators.map(o => `<tr>
      <td>${esc(o.login_name)}</td><td>${esc(o.display_name)}</td>
      <td>${o.display_name_es
            ? esc(o.display_name_es)
            : '<span class="muted small">未填 · 西语界面显示中文名</span>'}</td>
      <td>${ROLE[o.role] || o.role}</td>
      <td>${+o.enabled ? '<span class="tag on">启用</span>' : '<span class="tag off">停用</span>'}
          ${o.locked_until ? '<span class="tag err">锁定中</span>' : ''}
          ${+o.failed_count ? `<span class="muted small">失败 ${o.failed_count} 次</span>` : ''}</td>
      <td class="muted small">${esc(o.last_login_at || '从未')}</td>
      ${window.IS_ADMIN ? `<td>
        <button class="tiny" data-ot="${o.id}">${+o.enabled ? '停用' : '启用'}</button>
        <button class="tiny" data-rn="${o.id}" data-rnz="${esc(o.display_name)}" data-rne="${esc(o.display_name_es || '')}">改名</button>
        <button class="tiny" data-rp="${o.id}" data-rpn="${esc(o.login_name)}">重置 PIN</button>
      </td>` : ''}</tr>`).join('')
  }</table>`;
  $$('[data-ot]').forEach(b => b.onclick = async () => {
    try { await api('/operators/toggle', { id: +b.dataset.ot }); toast('已更新', 'ok'); loadOperators(); }
    catch (e) { toast(e.message, 'err'); }
  });

  /**
   * 改显示名 —— 两种语言各问一次。
   *
   * 这个入口是必需的：功能刚上线时，店里【已有的】账号都没有西语名，
   * 只能在这里补。没有它的话，这个功能对老账号等于不存在。
   */
  $$('[data-rn]').forEach(b => b.onclick = async () => {
    const zh = await UI.input('显示名（中文）：', { value: b.dataset.rnz, okText: '下一步' });
    if (zh === null) return;
    if (!zh.trim()) return toast('中文显示名不能为空', 'err');
    const es = await UI.input('显示名（西语）—— 留空则西语界面下也显示中文名：',
                              { value: b.dataset.rne, okText: '保存', required: false });
    if (es === null) return;
    try {
      await api('/operators/rename', { id: +b.dataset.rn, display_name: zh, display_name_es: es });
      toast('已改名', 'ok');
      loadOperators();
    } catch (e) { toast(e.message, 'err'); }
  });

  // 管理员重置他人 PIN —— 不需要旧 PIN，同时解掉连续失败锁定
  $$('[data-rp]').forEach(b => b.onclick = async () => {
    const who = b.dataset.rpn;
    const pin = await UI.input(`为「${who}」设置新 PIN（至少 6 位）：`, {
      password: true, numeric: true, okText: '下一步',
    });
    if (pin === null) return;
    if (pin.length < 6) return toast('PIN 至少 6 位', 'err');
    if (!await UI.confirm(`确认重置「${who}」的 PIN？\n该账号所有已登录设备都会被踢下线。`,
                          { okText: '确认重置', danger: true })) return;
    try {
      await api('/operators/reset-pin', { id: +b.dataset.rp, new_pin: pin });
      toast('已重置，锁定一并解除', 'ok');
      loadOperators();
    } catch (e) { toast(e.message, 'err'); }
  });
}

/* 改自己的 PIN —— 必须验旧 PIN */
$('#btn-change-pin').onclick = async () => {
  const oldPin = $('#my-old-pin').value;
  const p1 = $('#my-new-pin').value, p2 = $('#my-new-pin2').value;
  if (!oldPin || !p1) return toast('请填写当前 PIN 与新 PIN', 'err');
  if (p1 !== p2)      return toast('两次输入的新 PIN 不一致', 'err');
  if (p1.length < 6)  return toast('新 PIN 至少 6 位', 'err');
  try {
    await api('/auth/change-pin', { old_pin: oldPin, new_pin: p1 });
    toast('PIN 已修改，其他设备上的登录已失效', 'ok');
    $('#my-old-pin').value = ''; $('#my-new-pin').value = ''; $('#my-new-pin2').value = '';
  } catch (e) { toast(e.message, 'err'); }
};

$('#btn-add-op').onclick = async () => {
  try {
    await api('/operators/create', {
      login_name: $('#op-login').value.trim(),
      display_name: $('#op-name-new').value.trim(),
      display_name_es: $('#op-name-es').value.trim(),
      pin: $('#op-pin').value, role: +$('#op-role').value,
    });
    toast('已创建', 'ok');
    $('#op-login').value = ''; $('#op-name-new').value = '';
    $('#op-name-es').value = ''; $('#op-pin').value = '';
    loadOperators();
  } catch (e) { toast(e.message + (e.detail?.hint ? '：' + e.detail.hint : ''), 'err'); }
};


/* ── 实体卡发放 ───────────────────────────────────── */

async function loadCards() {
  const d = await api('/cards/batches', undefined, 'GET');

  const tot = d.batches.reduce((a, b) => ({
    total: a.total + b.total, stock: a.stock + b.stock,
    active: a.active + b.active, void: a.void + b.void,
  }), { total: 0, stock: 0, active: 0, void: 0 });

  $('#card-stats').innerHTML = `
    <div class="stat"><b>${tot.total}</b><span>已印制</span></div>
    <div class="stat"><b>${tot.stock}</b><span>库存待发</span></div>
    <div class="stat"><b>${tot.active}</b><span>已激活</span></div>
    <div class="stat"><b>${tot.void}</b><span>已作废</span></div>
    <div class="stat"><b>${esc(d.prefix)}</b><span>卡号前缀</span></div>
    <div class="stat"><b>${d.next_serial}</b><span>下一个顺序号</span></div>`;

  const today = new Date().toISOString().slice(0, 10);
  $('#card-batches').innerHTML = d.batches.length ? `<table>
    <tr><th>批次</th><th>等级</th><th>有效期至</th><th>顺序号区间</th><th class="num">共</th><th class="num">库存</th>
        <th class="num">已激活</th><th class="num">已作废</th><th>生成时间</th></tr>${
    d.batches.map(b => {
      // 库存里还躺着的过期卡要显眼 —— 发出去客人拿回家就是一张废卡
      const dead = b.valid_to && b.valid_to < today;
      return `<tr>
      <td><b>${esc(b.batch_no)}</b></td>
      <td>${b.tier
            ? `${esc(b.tier.name)}${b.tier.multiplier !== 1 ? ` <span class="muted small">×${b.tier.multiplier}</span>` : ''}`
            : '<span class="muted small">不分级</span>'}</td>
      <td class="${dead ? 'err' : 'muted small'}">${b.valid_to ? esc(b.valid_to) : '不设'}${
        dead && b.stock > 0 ? `　⚠ 库存 ${b.stock} 张已过期` : ''}</td>
      <td class="muted small">${b.serial_from} ~ ${b.serial_to}</td>
      <td class="num">${b.total}</td>
      <td class="num">${b.stock}</td>
      <td class="num">${b.active}</td>
      <td class="num">${b.void || ''}</td>
      <td class="muted small">${esc(b.created_at)}</td></tr>`;
    }).join('')
  }</table>` : '<div class="empty">还没有生成过任何批次</div>';

  // 生成批次是管理员才有的动作 —— 它能一次拿到整批明文 PIN
  const box = $('#cd-gen-box');
  if (box) box.hidden = !window.IS_ADMIN;

  await loadTiers();
}

/* ── 卡片等级 ─────────────────────────────────────
 *
 * 等级属于【卡】不属于会员 —— 它印在卡面上，换卡时跟着新卡走。
 * 整套是可选的：不定义等级，发卡时选「不分级」，界面上就不出现这件事。
 */
async function loadTiers() {
  const d = await api('/tiers', undefined, 'GET');

  // 发卡下拉框：只列启用的（停用的不该再发出去）
  const sel = $('#cd-tier');
  if (sel) {
    const keep = sel.value;
    sel.innerHTML = '<option value="">不分级</option>' + d.tiers
      .filter(t => t.enabled)
      .map(t => `<option value="${esc(t.code)}">${esc(t.name)}${
        t.multiplier !== 1 ? `（${t.multiplier} 倍积分）` : ''}</option>`).join('');
    if (keep) sel.value = keep;
  }

  const list = $('#tier-list');
  if (!list) return;
  list.innerHTML = d.tiers.length ? `<table>
    <tr><th>标识</th><th>名称（中文）</th><th>名称（西语）</th><th class="num">积分倍率</th>
        <th class="num">排序</th><th>状态</th>${window.IS_ADMIN ? '<th></th>' : ''}</tr>${
    d.tiers.map(t => `<tr>
      <td><code>${esc(t.code)}</code></td>
      <td><b>${esc(t.name)}</b></td>
      <td>${t.name_es ? esc(t.name_es) : '<span class="muted small">未填 · 西语界面显示中文名</span>'}</td>
      <td class="num">${t.multiplier === 1 ? '<span class="muted">1.00</span>' : `<b>${t.multiplier.toFixed(2)}</b>`}</td>
      <td class="num muted small">${t.sort_order}</td>
      <td>${t.enabled ? '<span class="tag on">启用</span>' : '<span class="tag off">停用</span>'}</td>
      ${window.IS_ADMIN ? `<td>
        <button class="tiny" data-te="${esc(t.code)}">编辑</button>
        <button class="tiny" data-tt="${esc(t.code)}">${t.enabled ? '停用' : '启用'}</button>
        <button class="tiny" data-td="${esc(t.code)}">删除</button>
      </td>` : ''}</tr>`).join('')
  }</table>` : '<div class="empty">还没有定义等级 —— 不用等级的话，发卡时选「不分级」即可</div>';

  // 编辑：把这一行填回表单，改完用同一个标识保存即可
  $$('[data-te]').forEach(b => b.onclick = () => {
    const t = d.tiers.find(x => x.code === b.dataset.te);
    if (!t) return;
    // ★ 表单在一个折叠的 <details> 里，不展开的话点了「编辑」屏幕上
    //   什么反应都没有 —— 值填进去了但看不见，只会被当成按钮坏了
    const box = $('#tier-code').closest('details');
    if (box) { box.open = true; }
    $('#tier-code').value    = t.code;
    $('#tier-name').value    = t.name;
    $('#tier-name-es').value = t.name_es || '';
    $('#tier-mult').value    = t.multiplier.toFixed(2);
    $('#tier-sort').value    = t.sort_order;
    $('#tier-code').focus();
    toast(`已填入「${t.name}」，改完点保存`, 'ok');
  });

  $$('[data-tt]').forEach(b => b.onclick = async () => {
    const t = d.tiers.find(x => x.code === b.dataset.tt);
    if (!t) return;
    try {
      await api('/tiers/save', {
        code: t.code, name: t.name, name_es: t.name_es,
        points_multiplier: t.multiplier, sort_order: t.sort_order,
        enabled: !t.enabled,
      });
      toast(t.enabled ? '已停用' : '已启用', 'ok');
      loadTiers();
    } catch (e) { toast(e.message, 'err'); }
  });

  $$('[data-td]').forEach(b => b.onclick = async () => {
    const code = b.dataset.td;
    if (!await UI.confirm(`删除等级「${code}」？\n\n已经有卡在用的等级删不掉 —— 那种情况请改用「停用」。`,
                          { okText: '删除', danger: true })) return;
    try {
      await api('/tiers/delete', { code });
      toast('已删除', 'ok');
      loadTiers();
    } catch (e) { toast(e.message + (e.detail?.hint ? '\n' + e.detail.hint : ''), 'err'); }
  });
}

$('#btn-tier-save').onclick = async () => {
  try {
    await api('/tiers/save', {
      code:              $('#tier-code').value.trim(),
      name:              $('#tier-name').value.trim(),
      name_es:           $('#tier-name-es').value.trim(),
      points_multiplier: parseFloat($('#tier-mult').value) || 1,
      sort_order:        +$('#tier-sort').value || 0,
      enabled:           true,
    });
    toast('已保存', 'ok');
    $('#tier-code').value = ''; $('#tier-name').value = '';
    $('#tier-name-es').value = ''; $('#tier-mult').value = '1.00';
    loadTiers();
  } catch (e) { toast(e.message + (e.detail?.hint ? '：' + e.detail.hint : ''), 'err'); }
};

$('#btn-card-look').onclick = async () => {
  const no = $('#cd-look').value.trim();
  if (!no) return toast('请输入卡号', 'err');
  const box = $('#card-look-result');
  try {
    const c = await api('/cards/lookup', { card_no: no });
    const stateText = { stock: '库存中，尚未发给客人', active: '已激活，正常使用中',
                        void: '已作废/挂失' }[c.state] || c.state;
    box.innerHTML = `<table>
      <tr><th>卡号</th><td><b>${esc(c.card_no)}</b></td></tr>
      <tr><th>状态</th><td>${esc(stateText)}</td></tr>
      <tr><th>批次</th><td>${esc(c.batch_no)}　顺序号 ${c.serial}</td></tr>
      <tr><th>等级</th><td>${c.tier
          ? `${esc(c.tier.name)}${c.tier.multiplier !== 1 ? `　<span class="muted small">${c.tier.multiplier} 倍积分</span>` : ''}`
          : '<span class="muted small">不分级</span>'}</td></tr>
      <tr><th>有效期至</th><td class="${c.expired ? 'err' : ''}">${
        c.valid_to ? esc(c.valid_to) + (c.expired ? '　⚠ 已过期，可到店换发新卡（积分结转）' : '') : '不设'}</td></tr>
      ${c.activated_at ? `<tr><th>激活时间</th><td>${esc(c.activated_at)}</td></tr>` : ''}
      ${c.voided_at ? `<tr><th>作废</th><td>${esc(c.voided_at)}　${esc(c.void_reason || '')}</td></tr>` : ''}
      ${c.pin_locked_until ? `<tr><th class="warn">PIN 锁定至</th><td>${esc(c.pin_locked_until)}</td></tr>` : ''}
      ${c.member ? `<tr><th>持卡会员</th><td>#${c.member.id}　${esc(c.member.phone || c.member.email || '')}
        　积分 ${c.member.points_balance}　计次 ${c.member.visit_count}</td></tr>` : ''}
    </table>`;
  } catch (e) {
    box.innerHTML = `<div class="empty">${esc(e.message)}</div>`;
  }
};

$('#btn-card-void').onclick = async () => {
  const no = $('#cd-void').value.trim(), why = $('#cd-void-why').value.trim();
  if (!no || !why) return toast('卡号与原因都必填', 'err');
  if (!await UI.confirm(`确认作废这张卡？\n\n${no}\n\n作废后该会员会暂时没有卡，积分与流水都保留，下次到店扫新卡即可换发。`,
                        { okText: '确认作废', danger: true })) return;
  try {
    const r = await api('/cards/void', { card_no: no, reason: why });
    toast(`${r.card_no} 已作废`, 'ok');
    $('#cd-void').value = ''; $('#cd-void-why').value = '';
    loadCards();
  } catch (e) { toast(e.message, 'err'); }
};

$('#btn-card-gen').onclick = async () => {
  const batch = $('#cd-batch').value.trim();
  const count = +$('#cd-count').value || 0;
  const valid = $('#cd-valid').value;
  if (count < 1) return toast('数量必须大于 0', 'err');
  if (!valid)   return toast('必须填写有效期 —— 它要印在卡面上', 'err');
  if (valid <= new Date().toISOString().slice(0, 10)) {
    return toast('有效期必须晚于今天', 'err');
  }

  /**
   * 有效期单独确认一遍，而且把它放在最前面。
   *
   * 卡面那行日期是唯一的告知证据（客人查不到任何线上信息），
   * 一旦印错，整批卡的合规基础就没了 —— 而且是印完才发现。
   * 多按一次确认，换的是这个。
   */
  if (!await UI.confirm(
    `请再核对一次有效期：\n\n` +
    `        ${valid}\n\n` +
    `这个日期会印在卡面上，也是客人唯一能看到的告知。\n` +
    `与印刷稿不一致的话，整批卡都得重印。`,
    { okText: '日期没错', cancelText: '我再看看' })) return;

  if (!await UI.confirm(
    `生成 ${count} 张新卡（有效期至 ${valid}）？\n\n` +
    `生成后会一次性显示全部卡号与 PIN，这是明文 PIN 唯一出现的时刻 ——\n` +
    `库里只存不可还原的 hash，关掉就再也取不回来，只能作废整批重来。\n\n` +
    `请准备好立刻复制保存。`,
    { okText: '生成并显示清单' })) return;

  try {
    const tier = $('#cd-tier') ? $('#cd-tier').value : '';
    const d = await api('/cards/generate', { batch_no: batch, count, valid_to: valid, tier_code: tier });
    // 制表符分隔：直接粘进 Excel 就是四列，不用做 CSV 转义。
    // 有效期也放进去 —— 给印刷厂的稿子要按这一列排版
    // 制表符分隔：直接粘进 Excel 就是五列，不用做 CSV 转义。
    // 等级也放进去 —— 卡面要按它排版（金卡银卡的版式本来就不同）
    const tierName = d.tier ? d.tier.name : '';
    const lines = ['卡号\t二维码内容\tPIN\t有效期至\t等级']
      .concat(d.rows.map(r => `${r.display}\t${r.card_no}\t${r.pin}\t${r.valid_to || ''}\t${tierName}`));
    $('#card-gen-csv').value = lines.join('\n');
    $('#card-gen-warn').textContent = d.warning;
    $('#card-gen-result').hidden = false;
    $('#card-gen-csv').focus();
    $('#card-gen-csv').select();
    toast(`批次 ${d.batch_no} 已生成 ${d.count} 张`, 'ok');
    loadCards();
  } catch (e) { toast(e.message, 'err'); }
};

/* ── 审计 ─────────────────────────────────────────── */
$('#btn-audit').onclick = loadAudit;
async function loadAudit() {
  const d = await api('/audit', { action: $('#audit-action').value, limit: 100 });
  $('#audit-list').innerHTML = d.logs.length ? `<table>
    <tr><th>时间</th><th>动作</th><th>对象</th><th>操作员</th><th>设备</th><th>详情</th></tr>${
    d.logs.map(l => `<tr>
      <td class="muted small">${esc(l.created_at)}</td><td>${esc(l.action)}</td>
      <td class="muted small">${esc(l.target_type || '')} ${esc(l.target_id || '')}</td>
      <td>${esc(l.operator || '')}</td><td class="muted small">${esc(l.device || '')}</td>
      <td class="muted small">${l.detail ? esc(JSON.stringify(l.detail)).slice(0, 160) : ''}</td></tr>`).join('')
  }</table>` : '<div class="empty">暂无记录</div>';
}

/* 启动 */
(async () => {
  // 会话还在时走这条路恢复。红条与 sms_ready 必须在这里一并处理 ——
  // 只在登录处理里渲染的话，刷新一次页面提醒就没了，等于白提醒。
  try {
    const d = await api('/auth/me', undefined, 'GET');
    window.SMS_READY = !!d.sms_ready;
    renderWarnings(d.warnings);
    enterMain(d.operator);
  } catch {}
})();
