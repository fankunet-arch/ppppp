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
  let res, j;
  try { res = await fetch(API + path, opt); j = await res.json(); }
  catch { throw { error: 'network', message: '无法连接服务' }; }
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
    enterMain(d.operator);
  } catch (e) {
    $('#login-err').textContent = e.error === 'forbidden' ? '该账号无后台权限（需经理及以上）' : e.message;
    $('#login-err').hidden = false;
  }
};
$('#login-pin').addEventListener('keydown', e => { if (e.key === 'Enter') $('#btn-login').click(); });

$('#btn-logout').onclick = async () => {
  try { await api('/auth/logout', {}); } catch {}
  $('#view-main').classList.remove('active');
  $('#view-login').classList.add('active');
};

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
  config: loadConfig, operators: loadOperators, audit: loadAudit,
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
      reason = prompt('驳回原因（会追加反向冲正流水，原流水保留）', '与小票核对不符');
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
      if (!confirm('确认执行删除请求？\n\nPII（手机号/邮箱/生日）将被抹除且不可恢复，\n积分流水按会计与税务留存义务保留。')) return;
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
const CFG_DESC = {
  order_lookup_window_min: '订单查找窗口（分钟）',
  lookup_fallback_window_min: '查不到时的放宽窗口（分钟）',
  points_per_euro: '每欧元积分数', points_multiplier: '积分倍率（1.0=不启用）',
  points_include_tax: '积分按含税价', free_meal_extra_earns: '免费餐当次的额外消费是否积分',
  visit_count_mode: '计次口径 by_portion=按套餐份数 / by_ledger=每笔最多1次',
  meal_item_alert_price: '规则表巡检价格阈值（全表扫，不按分组过滤）',
  business_day_cutoff: '营业日切点（已用 POS 数据验证=02:00）',
  sync_window_hours: '滚动校准窗口（小时）', verify_protect_days: '值比对保护期（天）',
  sync_batch_size: '每批 LIMIT（上限 100）', sync_batch_sleep_ms: '批次间停顿（毫秒）',
  sync_max_batches: '单次任务批次上限',
  manual_entry_enabled: '是否允许手工录入降级', manual_entry_limit: '手工录入单笔限额',
  manual_entry_daily_alert: '手工录入日频次告警阈值',
  reversal_window_hours: '自由撤销时限（小时）',
  consent_expire_days: '未确认会员的冻结期限（天）', pii_retention_years: 'PII 留存年限',
};
async function loadConfig() {
  const d = await api('/config', undefined, 'GET');
  const keys = Object.keys(d.config).sort();
  $('#config-list').innerHTML = `<table><tr><th>配置项</th><th>说明</th><th>值</th>${window.IS_ADMIN ? '<th></th>' : ''}</tr>${
    keys.map(k => `<tr><td><code>${esc(k)}</code></td>
      <td class="muted small">${esc(CFG_DESC[k] || '')}</td>
      <td><input data-ck="${esc(k)}" value="${esc(d.config[k])}" style="min-width:200px"${window.IS_ADMIN ? '' : ' disabled'}></td>
      ${window.IS_ADMIN ? `<td><button class="tiny primary" data-cs="${esc(k)}">保存</button></td>` : ''}</tr>`).join('')
  }</table>`;
  $$('[data-cs]').forEach(b => b.onclick = async () => {
    const k = b.dataset.cs;
    try {
      await api('/config/save', { key: k, value: $(`[data-ck="${k}"]`).value });
      toast('已保存', 'ok');
    } catch (e) { toast(e.message, 'err'); }
  });
}

/* ── 操作员 ───────────────────────────────────────── */
const ROLE = { 1: '服务员', 2: '经理', 3: '管理员' };
async function loadOperators() {
  const d = await api('/operators', undefined, 'GET');
  $('#operators-list').innerHTML = `<table>
    <tr><th>工号</th><th>显示名</th><th>角色</th><th>状态</th><th>最后登录</th>${window.IS_ADMIN ? '<th></th>' : ''}</tr>${
    d.operators.map(o => `<tr>
      <td>${esc(o.login_name)}</td><td>${esc(o.display_name)}</td>
      <td>${ROLE[o.role] || o.role}</td>
      <td>${+o.enabled ? '<span class="tag on">启用</span>' : '<span class="tag off">停用</span>'}
          ${o.locked_until ? '<span class="tag err">锁定中</span>' : ''}
          ${+o.failed_count ? `<span class="muted small">失败 ${o.failed_count} 次</span>` : ''}</td>
      <td class="muted small">${esc(o.last_login_at || '从未')}</td>
      ${window.IS_ADMIN ? `<td><button class="tiny" data-ot="${o.id}">${+o.enabled ? '停用' : '启用'}</button></td>` : ''}</tr>`).join('')
  }</table>`;
  $$('[data-ot]').forEach(b => b.onclick = async () => {
    try { await api('/operators/toggle', { id: +b.dataset.ot }); toast('已更新', 'ok'); loadOperators(); }
    catch (e) { toast(e.message, 'err'); }
  });
}

$('#btn-add-op').onclick = async () => {
  try {
    await api('/operators/create', {
      login_name: $('#op-login').value.trim(), display_name: $('#op-name-new').value.trim(),
      pin: $('#op-pin').value, role: +$('#op-role').value,
    });
    toast('已创建', 'ok');
    $('#op-login').value = ''; $('#op-name-new').value = ''; $('#op-pin').value = '';
    loadOperators();
  } catch (e) { toast(e.message + (e.detail?.hint ? '：' + e.detail.hint : ''), 'err'); }
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
  try { enterMain((await api('/auth/me', undefined, 'GET')).operator); } catch {}
})();
