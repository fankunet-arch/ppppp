/* Pad 端逻辑 —— 原生 JS，无构建步骤，直接由本地 HTTP 服务分发 */
'use strict';

const $  = (s, r = document) => r.querySelector(s);
const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));

const API = '/api.php';
const DEVICE = (() => {
  let d = localStorage.getItem('vip_device');
  if (!d) { d = 'PAD-' + Math.random().toString(36).slice(2, 7).toUpperCase(); localStorage.setItem('vip_device', d); }
  return d;
})();

/* ── 状态 ────────────────────────────────────────── */
const S = {
  operator: null,
  order: null,       // 当前选中的订单上下文
  mode: 1,
  people: [],        // [{member, amountCents, portions}]
  picks: {},         // 点选模式：itemIndex -> personIndex
  memberTarget: null,// 会员弹层回调
};

/* ── 工具 ────────────────────────────────────────── */
const cents = (s) => {
  const n = String(s ?? '').trim().replace(',', '.');
  if (!/^-?\d*(\.\d*)?$/.test(n) || n === '' || n === '.') return 0;
  return Math.round(parseFloat(n) * 100);
};
const money = (c) => (c / 100).toFixed(2);

function toast(msg, kind = '') {
  const t = $('#toast');
  t.textContent = msg;
  t.className = 'toast ' + kind;
  t.hidden = false;
  clearTimeout(toast._t);
  toast._t = setTimeout(() => { t.hidden = true; }, 3200);
}

function showErr(el, msg) {
  const e = $(el);
  if (!msg) { e.hidden = true; return; }
  e.textContent = msg;
  e.hidden = false;
}

function step(id) {
  $$('.step').forEach(s => s.classList.toggle('active', s.id === id));
  window.scrollTo(0, 0);
}

async function api(path, body, method = 'POST') {
  const opt = { method, headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin' };
  if (body !== undefined && method !== 'GET') opt.body = JSON.stringify(body);
  let res, json;
  try {
    res = await fetch(API + path, opt);
    json = await res.json();
  } catch (e) {
    // 网络层失败：本地服务不可达。与「POS 不可达」是两回事，文案要分清。
    throw { error: 'network', message: '无法连接本机服务，请检查 Pad 的网络' };
  }
  if (!res.ok || json.ok === false) {
    throw { error: json.error || 'server_error', message: json.message || '操作失败', detail: json.detail };
  }
  return json.data;
}

/* ── 登录 ────────────────────────────────────────── */
$('#btn-login').onclick = async () => {
  showErr('#login-err', '');
  const name = $('#login-name').value.trim();
  const pin  = $('#login-pin').value;
  if (!name || !pin) return showErr('#login-err', '请填写工号与 PIN');
  try {
    const d = await api('/auth/login', { login_name: name, pin, device: DEVICE });
    enterMain(d.operator);
  } catch (e) {
    showErr('#login-err', e.message);
  }
};
$('#login-pin').addEventListener('keydown', e => { if (e.key === 'Enter') $('#btn-login').click(); });

$('#btn-logout').onclick = async () => {
  try { await api('/auth/logout', {}); } catch {}
  S.operator = null;
  $('#view-main').classList.remove('active');
  $('#view-login').classList.add('active');
  $('#login-pin').value = '';
};

function enterMain(op) {
  S.operator = op;
  $('#op-name').textContent = op.name + (op.is_manager ? '（经理）' : '');
  $('#view-login').classList.remove('active');
  $('#view-main').classList.add('active');
  resetFlow();
  checkHealth();
}

async function checkHealth() {
  try {
    const h = await api('/health', undefined, 'GET');
    const pill = $('#pos-status');
    if (!h.pos_db) {
      pill.textContent = 'POS 主库不可用 · 可手工录入';
      pill.hidden = false;
    } else {
      pill.hidden = true;
    }
  } catch {}
}

/* ── 改自己的 PIN ────────────────────────────────── */
$('#btn-my-pin').onclick = () => {
  $('#pin-old').value = ''; $('#pin-new').value = ''; $('#pin-new2').value = '';
  showErr('#pin-err', '');
  $('#pin-modal').hidden = false;
  setTimeout(() => $('#pin-old').focus(), 0);
};
$('#btn-pin-cancel').onclick = () => { $('#pin-modal').hidden = true; };

$('#btn-pin-submit').onclick = async () => {
  const oldPin = $('#pin-old').value, p1 = $('#pin-new').value, p2 = $('#pin-new2').value;
  showErr('#pin-err', '');
  if (!oldPin || !p1) return showErr('#pin-err', '请填写当前 PIN 与新 PIN');
  if (p1 !== p2)      return showErr('#pin-err', '两次输入的新 PIN 不一致');
  if (p1.length < 6)  return showErr('#pin-err', '新 PIN 至少 6 位');
  try {
    await api('/auth/change-pin', { old_pin: oldPin, new_pin: p1 });
    $('#pin-modal').hidden = true;
    toast('PIN 已修改', 'ok');
  } catch (e) { showErr('#pin-err', e.message); }
};

/* ── 步骤 1：桌号 ────────────────────────────────── */
function resetFlow() {
  S.order = null; S.people = []; S.picks = {}; S.mode = 1;
  $('#table-input').value = '';
  $('#invoice-input').value = '';
  showErr('#locate-err', '');
  $('#locate-fallback').hidden = true;
  step('step-table');
  setLookupMode(S.lookupMode || 'invoice');
}

/* 两种找单方式切换：小票号（精确）/ 桌号（客人没拿小票时） */
function setLookupMode(mode) {
  S.lookupMode = mode;
  $$('.lookup-tab').forEach(t => t.classList.toggle('on', t.dataset.mode === mode));
  $('#pane-invoice').hidden = (mode !== 'invoice');
  $('#pane-table').hidden   = (mode !== 'table');
  showErr('#locate-err', '');
  $('#locate-fallback').hidden = true;
  const box = mode === 'invoice' ? $('#invoice-input') : $('#table-input');
  setTimeout(() => box.focus(), 0);
}
$$('.lookup-tab').forEach(t => t.onclick = () => setLookupMode(t.dataset.mode));
$('#btn-new').onclick = resetFlow;
$$('[data-back]').forEach(b => b.onclick = () => step(b.dataset.back));

async function locate(windowMinutes) {
  const table = $('#table-input').value.trim();
  showErr('#locate-err', '');
  if (!table) return showErr('#locate-err', '请输入桌号');
  try {
    const d = await api('/order/locate', { table_name: table, window_minutes: windowMinutes || 0 });
    $('#win-label').textContent = d.window;
    $('#fallback-label').textContent = d.fallback_window;
    if (!d.candidates.length) {
      showErr('#locate-err', `最近 ${d.window} 分钟内没找到 ${table} 桌的已结账订单`);
      $('#locate-fallback').hidden = false;
      return;
    }
    renderOrders(d.candidates);
    step('step-order');
  } catch (e) {
    showErr('#locate-err', e.message);
    // POS 不可达 → 直接给出降级入口
    $('#locate-fallback').hidden = (e.error !== 'pos_unavailable');
  }
}
$('#btn-locate').onclick = () => locate(0);
$('#table-input').addEventListener('keydown', e => { if (e.key === 'Enter') locate(0); });
$('#btn-widen').onclick = () => locate(parseInt($('#fallback-label').textContent, 10) || 60);
$('#btn-manual').onclick = () => { openManual(); };

/**
 * 按小票号找单 —— Factura Simplificada = 全局唯一，不需要时间窗。
 * 前导零可不输，界面上照着小票原样输也认。
 */
async function locateByInvoice() {
  const raw = $('#invoice-input').value.trim();
  showErr('#locate-err', '');
  $('#locate-fallback').hidden = true;
  if (!raw) return showErr('#locate-err', '请输入小票上的 Factura Simplificada 号');
  try {
    const d = await api('/order/locate-invoice', { invoice_no: raw });
    if (!d.candidates.length) {
      if (d.reason === 'too_old') {
        showErr('#locate-err',
          `这张小票是 ${(d.order_end_time || '').slice(0, 10)} 的，超过 ${d.max_days} 天不再受理，请找经理处理`);
      } else {
        showErr('#locate-err', `没找到小票号 ${d.invoice_no} 对应的订单，请核对 Factura Simplificada 那一行`);
      }
      $('#locate-fallback').hidden = false;
      return;
    }
    renderOrders(d.candidates);
    step('step-order');
  } catch (e) {
    showErr('#locate-err', e.message);
    $('#locate-fallback').hidden = (e.error !== 'pos_unavailable');
  }
}
$('#btn-locate-invoice').onclick = () => locateByInvoice();
$('#invoice-input').addEventListener('keydown', e => { if (e.key === 'Enter') locateByInvoice(); });

/* ── 步骤 2：候选订单 ────────────────────────────── */
function renderOrders(list) {
  const box = $('#order-list');
  box.innerHTML = '';
  list.forEach(o => {
    const b = document.createElement('button');
    b.className = 'card' + (o.eligible ? '' : ' disabled');
    const time = o.order_end_time.slice(11, 16);
    const reason = {
      not_dine_in: '外带订单不积分',
      zero_amount: '金额为 0，不积分',
      free_meal:   '已标记免费餐',
      // 明细里有 TARJETA 10+1 折扣行 —— 客人正在兑换奖励，这一餐不计次不积分
      redeemed:    `已用十送一核销 € ${o.redeem_amount || ''}，本餐不计次不积分`,
    }[o.ineligible_reason] || '';
    b.innerHTML = `
      <div class="amount">€ ${o.total}</div>
      <div class="meta">${o.table_name} 桌 · ${o.customer_num || '?'} 人 · ${time} 结账 · 套餐 ${o.portions_counted} 份</div>
      <div class="meta">流水号 ${o.serial_id}${Number(o.allocated_cents) > 0 ? ` · 已记 € ${o.allocated}` : ''}</div>
      ${reason ? `<div class="meta" style="color:var(--warn)">${reason}</div>` : ''}`;
    b.onclick = () => {
      if (!o.eligible) return toast(reason || '该订单不可积分', 'err');
      if (o.remaining_cents <= 0) return toast('该订单已全额记账', 'err');
      selectOrder(o);
    };
    box.appendChild(b);
  });
}

function selectOrder(o) {
  S.order = o;
  S.people = []; S.picks = {};
  $('#order-summary').innerHTML = `
    <div class="amount">€ ${money(o.remaining_cents)} <span class="muted small">可分配</span></div>
    <div class="meta">${o.table_name} 桌 · 流水号 ${o.serial_id} · 套餐 ${o.remaining_portions} 份可计次</div>
    ${Number(o.excluded) > 0 ? `<div class="meta">已扣除不计分项 € ${o.excluded}（外卖产品线等）</div>` : ''}`;

  const lb = $('#existing-ledger');
  if (o.existing_ledger && o.existing_ledger.length) {
    lb.innerHTML = '<b>本单已记账：</b>' + o.existing_ledger.map(l =>
      `<div class="lrow"><span>${l.card_no || '会员'} · € ${l.amount} · ${l.points} 分</span>
       <button class="link" data-rev="${l.id}">撤销</button></div>`).join('');
    lb.hidden = false;
    $$('[data-rev]', lb).forEach(b => b.onclick = () => doReverse(parseInt(b.dataset.rev, 10)));
  } else {
    lb.hidden = true;
  }
  step('step-mode');
}

/* ── 撤销 ────────────────────────────────────────── */
async function doReverse(ledgerId) {
  const reason = prompt('撤销原因（会记入审计日志）', '客人要求改记');
  if (reason === null) return;
  try {
    await api('/points/reverse', { ledger_id: ledgerId, reason });
    toast('已撤销，可重新记账', 'ok');
    await locate(0);
  } catch (e) {
    toast(e.message, 'err');
  }
}

/* ── 步骤 3：记账方式 ────────────────────────────── */
$$('.mode').forEach(b => b.onclick = () => {
  S.mode = parseInt(b.dataset.mode, 10);
  S.people = []; S.picks = {};
  startAssign();
});

$('#btn-free-meal').onclick = async () => {
  if (!confirm('确认把本单标记为免费餐（10送1 核销）？标记后本单不积分、不计次。')) return;
  try {
    await api('/order/free-meal', { serial_id: S.order.serial_id, is_free_meal: true });
    toast('已标记为免费餐', 'ok');
    resetFlow();
  } catch (e) { toast(e.message, 'err'); }
};

/* ── 步骤 4：分配 ────────────────────────────────── */
function startAssign() {
  const o = S.order;
  $('#assign-title').textContent = { 1: '整单记给一位会员', 2: '均摊 AA', 3: '点选菜品' }[S.mode];
  $('#sum-total').textContent = money(o.remaining_cents);
  $('#sum-port-total').textContent = o.remaining_portions;
  showErr('#assign-err', '');
  const body = $('#assign-body');
  body.innerHTML = '';

  if (S.mode === 1) {
    S.people = [{ member: null, amountCents: o.remaining_cents, portions: o.remaining_portions }];
    renderPeople();
  } else if (S.mode === 2) {
    body.innerHTML = `<label>AA 人数
      <input id="aa-people" type="number" inputmode="numeric" min="1" max="50" value="${o.customer_num || 2}"></label>
      <button id="btn-aa" class="primary">按人数分摊</button>`;
    $('#btn-aa').onclick = doSplit;
    $('#assign-people').innerHTML = '';
    updateTotals();
  } else {
    renderPickItems(body);
  }
  step('step-assign');
}

async function doSplit() {
  const n = parseInt($('#aa-people').value, 10);
  if (!n || n < 1) return toast('请输入人数', 'err');
  try {
    const d = await api('/points/split', { serial_id: S.order.serial_id, people: n });
    S.people = d.shares.map(s => ({ member: null, amountCents: cents(s.amount), portions: s.portions }));
    renderPeople();
  } catch (e) { toast(e.message, 'err'); }
}

function renderPickItems(body) {
  const items = S.order.items || [];
  if (!items.length) {
    body.innerHTML = '<p class="muted">该订单没有可认领的收费项，请改用其他方式。</p>';
    return;
  }
  body.innerHTML = `<p class="muted small">先在下方添加要记账的会员，再为每道菜指定认领人。
    套餐内 0 元菜品不显示；被免的项会标注原价。</p>
    <div class="items">${items.map((it, i) => `
      <div class="item">
        <span class="name">${escapeHtml(it.name)}${it.quantity > 1 ? ` ×${it.quantity}` : ''}
          ${it.counts_visit ? '<span class="tag">计次</span>' : ''}
          ${it.is_waived ? `<div class="waived">原价 € ${money(it.unit_cents * (it.quantity || 1))} → 已免</div>` : ''}
        </span>
        <span class="price">€ ${money(it.line_cents)}</span>
        <select data-item="${i}"></select>
      </div>`).join('')}</div>
    <button id="btn-add-person" class="ghost">+ 添加会员</button>`;
  $('#btn-add-person').onclick = addPerson;
  refreshPickSelects();
}

function refreshPickSelects() {
  $$('[data-item]').forEach(sel => {
    const idx = parseInt(sel.dataset.item, 10);
    const cur = S.picks[idx];
    sel.innerHTML = '<option value="">未认领</option>' +
      S.people.map((p, i) => `<option value="${i}"${cur === i ? ' selected' : ''}>${p.member ? p.member.card_no : '会员 ' + (i + 1)}</option>`).join('');
    sel.onchange = () => {
      if (sel.value === '') delete S.picks[idx]; else S.picks[idx] = parseInt(sel.value, 10);
      recomputePicks();
    };
  });
}

function recomputePicks() {
  S.people.forEach(p => { p.amountCents = 0; p.portions = 0; });
  (S.order.items || []).forEach((it, i) => {
    const pi = S.picks[i];
    if (pi === undefined || !S.people[pi]) return;
    S.people[pi].amountCents += it.line_cents;
    if (it.counts_visit) S.people[pi].portions += (it.quantity || 1);
  });
  renderPeople(true);
}

function addPerson() {
  S.people.push({ member: null, amountCents: 0, portions: 0 });
  renderPeople();
  if (S.mode === 3) refreshPickSelects();
}

function renderPeople(keepItems) {
  const box = $('#assign-people');
  box.innerHTML = '';
  S.people.forEach((p, i) => {
    const d = document.createElement('div');
    d.className = 'person';
    d.innerHTML = `
      <div class="who">
        ${p.member
          ? `<b>${escapeHtml(p.member.card_no)}</b><small>${p.member.points_balance} 分 · 已 ${p.member.visit_count} 次${p.member.points_frozen ? ' · 待确认' : ''}</small>`
          : `<button class="link" data-pick="${i}">＋ 选择会员</button>`}
      </div>
      <label class="amt">金额<input type="text" inputmode="decimal" data-amt="${i}" value="${money(p.amountCents)}"${S.mode === 3 ? ' readonly' : ''}></label>
      <label class="prt">份数<input type="number" inputmode="numeric" min="0" data-prt="${i}" value="${p.portions}"${S.mode === 3 ? ' readonly' : ''}></label>
      ${S.people.length > 1 ? `<button class="rm" data-rm="${i}">移除</button>` : ''}`;
    box.appendChild(d);
  });

  if (S.mode !== 1 && !keepItems) {
    const add = document.createElement('button');
    add.className = 'ghost';
    add.textContent = '+ 添加会员';
    add.onclick = addPerson;
    box.appendChild(add);
  }

  $$('[data-pick]', box).forEach(b => b.onclick = () => openMemberModal(parseInt(b.dataset.pick, 10)));
  $$('[data-rm]', box).forEach(b => b.onclick = () => {
    S.people.splice(parseInt(b.dataset.rm, 10), 1);
    S.picks = {};
    renderPeople();
    if (S.mode === 3) refreshPickSelects();
  });
  $$('[data-amt]', box).forEach(inp => inp.oninput = () => {
    S.people[parseInt(inp.dataset.amt, 10)].amountCents = cents(inp.value);
    updateTotals();
  });
  $$('[data-prt]', box).forEach(inp => inp.oninput = () => {
    S.people[parseInt(inp.dataset.prt, 10)].portions = parseInt(inp.value, 10) || 0;
    updateTotals();
  });
  updateTotals();
}

function updateTotals() {
  const a = S.people.reduce((s, p) => s + p.amountCents, 0);
  const q = S.people.reduce((s, p) => s + p.portions, 0);
  $('#sum-alloc').textContent = money(a);
  $('#sum-port').textContent = q;
  const over = a > S.order.remaining_cents || q > S.order.remaining_portions;
  $('.totals').classList.toggle('over', over);
  $('#btn-submit').disabled = over || a <= 0;
}

/* ── 提交 ────────────────────────────────────────── */
$('#btn-submit').onclick = async () => {
  showErr('#assign-err', '');
  const missing = S.people.some(p => !p.member && p.amountCents > 0);
  if (missing) return showErr('#assign-err', '有分配了金额但未选择会员的行');

  const allocations = S.people
    .filter(p => p.member && (p.amountCents > 0 || p.portions > 0))
    .map(p => ({ member_id: p.member.id, amount: money(p.amountCents), portions: p.portions }));
  if (!allocations.length) return showErr('#assign-err', '请至少为一位会员分配金额');

  const btn = $('#btn-submit');
  btn.disabled = true;
  try {
    const d = await api('/points/grant', { serial_id: S.order.serial_id, mode: S.mode, allocations });
    $('#done-body').innerHTML = d.entries.map(e => `
      <div class="card"><div class="amount">+${e.points} 分</div>
      <div class="meta">${escapeHtml(e.card_no)} · € ${e.amount} · 计次 +${e.visits}</div></div>`).join('');
    step('step-done');
  } catch (e) {
    showErr('#assign-err', e.message + (e.detail && e.detail.total ? `（可分配 € ${e.detail.total}，已分配 € ${e.detail.allocated}）` : ''));
  } finally {
    btn.disabled = false;
  }
};

/* ── 会员弹层 ────────────────────────────────────── */
function openMemberModal(personIndex) {
  S.memberTarget = personIndex;
  $('#member-input').value = '';
  $('#member-result').innerHTML = '';
  showErr('#member-err', '');
  $('#member-modal').hidden = false;
  setTimeout(() => $('#member-input').focus(), 50);
}
$('#btn-member-close').onclick = () => { $('#member-modal').hidden = true; };

$$('#search-type button').forEach(b => b.onclick = () => {
  $$('#search-type button').forEach(x => x.classList.toggle('on', x === b));
  const t = b.dataset.type;
  const inp = $('#member-input');
  inp.placeholder = { card: '会员卡号', phone: '完整手机号', email: '邮箱地址' }[t];
  inp.inputMode = t === 'phone' ? 'tel' : 'text';
  inp.focus();
});

$('#btn-member-search').onclick = doMemberSearch;
$('#member-input').addEventListener('keydown', e => { if (e.key === 'Enter') doMemberSearch(); });

async function doMemberSearch() {
  showErr('#member-err', '');
  const type = $('#search-type button.on').dataset.type;
  const value = $('#member-input').value.trim();
  if (!value) return showErr('#member-err', '请输入查询内容');
  try {
    const d = await api('/member/search', { type, value });
    if (!d.found) {
      $('#member-result').innerHTML = '<p class="muted">未找到该会员，可在下方新建。</p>';
      $('#member-new').open = true;
      return;
    }
    const m = d.member;
    $('#member-result').innerHTML = `
      <div class="found"><b>${escapeHtml(m.card_no)}</b>
        <div class="muted small">${m.points_balance} 分 · 已消费 ${m.visit_count} 次 · 累计 € ${m.total_spent}</div>
        ${m.points_frozen ? '<div class="frozen">该会员尚未完成确认，积分照常入账但暂不可兑换</div>' : ''}
        <button class="primary" id="btn-use-member" style="margin-top:10px">选用</button></div>`;
    $('#btn-use-member').onclick = () => useMember(m);
  } catch (e) { showErr('#member-err', e.message); }
}

$('#btn-member-create').onclick = async () => {
  showErr('#member-err', '');
  const phone = $('#new-phone').value.trim();
  const email = $('#new-email').value.trim();
  if (!phone && !email) return showErr('#member-err', '手机号与邮箱至少填一项');
  try {
    const d = await api('/member/create', { phone, email, birthday: $('#new-birthday').value || null });
    toast('已创建，确认消息已发送', 'ok');
    $('#new-phone').value = ''; $('#new-email').value = ''; $('#new-birthday').value = '';
    useMember(d.member);
  } catch (e) { showErr('#member-err', e.message); }
};

function useMember(m) {
  if (S.memberTarget === 'manual') {
    S.manualMember = m;
    $('#manual-member').innerHTML = `<div class="found"><b>${escapeHtml(m.card_no)}</b>
      <div class="muted small">${m.points_balance} 分</div></div>`;
  } else if (S.people.some((p, i) => p.member && p.member.id === m.id && i !== S.memberTarget)) {
    return showErr('#member-err', '该会员已在本单中，不能重复');
  } else {
    S.people[S.memberTarget].member = m;
    renderPeople(S.mode === 3);
    if (S.mode === 3) refreshPickSelects();
  }
  $('#member-modal').hidden = true;
}

/* ── 手工录入 ────────────────────────────────────── */
function openManual() {
  S.manualMember = null;
  $('#manual-member').innerHTML = '<button class="link" id="btn-manual-pick">＋ 选择会员</button>';
  $('#btn-manual-pick').onclick = () => openMemberModal('manual');
  $('#manual-amount').value = '';
  showErr('#manual-err', '');
  step('step-manual');
}

$('#btn-manual-submit').onclick = async () => {
  showErr('#manual-err', '');
  if (!S.manualMember) return showErr('#manual-err', '请先选择会员');
  const amt = $('#manual-amount').value.trim();
  if (cents(amt) <= 0) return showErr('#manual-err', '请填写正确金额');
  try {
    const d = await api('/points/manual', {
      member_id: S.manualMember.id, amount: amt, reason_code: $('#manual-reason').value,
    });
    toast(`已录入 +${d.points} 分，等待后台复核`, 'ok');
    resetFlow();
  } catch (e) { showErr('#manual-err', e.message); }
};

/* ── 杂项 ────────────────────────────────────────── */
function escapeHtml(s) {
  return String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

/* 启动：若已有有效会话直接进主界面 */
(async () => {
  try {
    const d = await api('/auth/me', undefined, 'GET');
    enterMain(d.operator);
  } catch {
    try {
      const h = await api('/health', undefined, 'GET');
      if (!h.local_db) $('#health-note').textContent = '本地数据库连接异常，请联系管理员';
    } catch {
      $('#health-note').textContent = '无法连接本机服务';
    }
  }
})();
