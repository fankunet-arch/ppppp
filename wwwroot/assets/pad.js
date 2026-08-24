/* Pad 端逻辑 —— 原生 JS，无构建步骤，直接由本地 HTTP 服务分发 */
'use strict';

const $  = (s, r = document) => r.querySelector(s);
const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));

const API = '/api.php';
/**
 * 设备标识 —— 优先取原生容器的 ANDROID_ID。
 *
 * 落到 operator_session 里做审计与「踢下线」，所以要尽量稳定。
 * localStorage 那份一清应用数据就变，换台平板更是全新的；
 * 原生 ID 至少在同一台设备 + 同一签名下是稳定的。
 *
 * ⚠️ ANDROID_ID 按「应用签名 + 用户 + 设备」派生 —— debug 包与 release 包
 *    在同一台平板上取到的值不同。正式建档必须用 release 包采集。
 *
 * 只接受 source === 'native'：桥接自带的浏览器兜底（web- 前缀）在这里没有
 * 额外价值，反而会让本机已有的 vip_device 换一个值、把历史会话记录割裂。
 * 桥接不在时（PC 浏览器调试）沿用原来的 PAD- 随机串，前缀一望即知不是设备身份。
 */
const DEV = (() => {
  const b = window.SushiVIP;
  if (b && typeof b.getDeviceId === 'function') {
    try {
      const r = b.getDeviceId();
      if (r && r.id && r.source === 'native') return { id: String(r.id), source: 'native' };
    } catch (e) {
      // 桥接在但调用失败：不能让登录页整个崩掉，继续走兜底
      console.warn('[pad] 原生设备 ID 获取失败，回落本地标识', e);
    }
  }
  let d = localStorage.getItem('vip_device');
  if (!d) { d = 'PAD-' + Math.random().toString(36).slice(2, 7).toUpperCase(); localStorage.setItem('vip_device', d); }
  return { id: d, source: 'browser' };
})();
const DEVICE = DEV.id;

/* ── 状态 ────────────────────────────────────────── */
const S = {
  operator: null,
  order: null,       // 当前选中的订单上下文
  mode: 1,
  people: [],        // [{member, amountCents, portions}]
  picks: {},         // 点选模式：itemIndex -> personIndex
  memberTarget: null,// 会员弹层回调
  pendingCard: null, // 扫到的库存卡，等着绑给新建的会员
  settings: {},      // 后台开关，随登录下发
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

/**
 * 步骤的「上一步」是什么 —— 显式写死，不用下标算。
 * step-done 特意退回起点而不是重进分配：账已经记完了，
 * 再回到分配界面只会让人以为还能改。
 * step-manual 是从起点分出去的旁支。
 */
const STEP_BACK = {
  'step-order':  'step-table',
  'step-mode':   'step-order',
  'step-assign': 'step-mode',
  'step-done':   'step-table',
  'step-manual': 'step-table',
};

let CURRENT_STEP = 'step-table';

function step(id) {
  $$('.step').forEach(s => s.classList.toggle('active', s.id === id));
  window.scrollTo(0, 0);
  CURRENT_STEP = id;
  if (window.UI && UI.back) UI.back.sync();
}

async function api(path, body, method = 'POST') {
  const opt = { method, headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin' };
  if (body !== undefined && method !== 'GET') opt.body = JSON.stringify(body);
  /**
   * ★ 必须把「连不上」和「连上了但没回 JSON」分开报。
   *
   * 早先两者共用一句「无法连接本机服务，请检查 Pad 的网络」——
   * 而实际上服务器往往好好地答了，只是吐的是 PHP 致命错误页（HTML），
   * res.json() 一解析就抛，掉进同一个 catch。
   * 结果是去查网线和路由器，真正的原因（服务端 fatal）却看不见。
   *
   * 现在：fetch 抛 → 真的连不上；json 解析失败 → 带上 HTTP 状态码与
   * 响应正文开头，那几十个字符通常就写着 Fatal error: ... 在哪一行。
   */
  let res, json, raw = '';
  try {
    res = await fetch(API + path, opt);
  } catch (e) {
    throw { error: 'network', message: '无法连接本机服务，请检查 Pad 的网络与 Web 服务是否在运行' };
  }
  try {
    raw  = await res.text();
    json = JSON.parse(raw);
  } catch (e) {
    const head = raw.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 160);
    throw {
      error: 'bad_response',
      message: `服务器返回的不是 JSON（HTTP ${res.status}）\n${head || '（响应为空）'}`,
    };
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
    enterMain(d.operator, d.settings);
  } catch (e) {
    showErr('#login-err', e.message);
  }
};
$('#login-pin').addEventListener('keydown', e => { if (e.key === 'Enter') $('#btn-login').click(); });

/* 容器是沉浸式全屏，没有地址栏、没有刷新按钮 —— 页面卡住时
   收银员唯一的办法是杀进程重启，所以必须自己提供入口 */
$$('#btn-refresh, #btn-refresh-login').forEach(b => {
  b.onclick = () => location.reload();
});

$('#btn-logout').onclick = async () => {
  try { await api('/auth/logout', {}); } catch {}
  S.operator = null;
  $('#view-main').classList.remove('active');
  $('#view-login').classList.add('active');
  $('#login-pin').value = '';
};

function enterMain(op, settings) {
  S.operator = op;
  S.settings = settings || {};
  applySettings();
  $('#op-name').textContent = op.name + (op.is_manager ? '（经理）' : '');
  $('#view-login').classList.remove('active');
  $('#view-main').classList.add('active');
  resetFlow();
  checkHealth();
}

/**
 * 把后台开关落到界面上。
 *
 * collect_pii 关闭时，联系方式那一栏【整块从 DOM 里移除】，
 * 而不是 hidden —— 目的就是让它在界面上根本不存在：
 * 既不给收银员向客人索要的机会，也不必为一个根本没在用的采集表单
 * 去应付「你们收了个人信息，保护措施呢」这类检查。
 *
 * 服务端同时拒收（见 /member/create），两边都做才站得住 ——
 * 只藏前端的话，字段还在、接口照收，说不清楚。
 */
function applySettings() {
  const box = $('#new-contact');
  if (!box) return;
  if (S.settings.collect_pii) return;      // 开启时保持原样
  box.remove();
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
  UI.back.sync();
  setTimeout(() => $('#pin-old').focus(), 0);
};
$('#btn-pin-cancel').onclick = () => { $('#pin-modal').hidden = true; UI.back.sync(); };

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
  const reason = await UI.input('撤销原因（会记入审计日志）', {
    value: '客人要求改记', okText: '确认撤销', danger: true,
  });
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
  if (!await UI.confirm('确认把本单标记为免费餐（10送1 核销）？\n标记后本单不积分、不计次。',
                        { okText: '标记为免费餐', danger: true })) return;
  try {
    await api('/order/free-meal', { serial_id: S.order.serial_id, is_free_meal: true });
    toast('已标记为免费餐', 'ok');
    resetFlow();
  } catch (e) { toast(e.message, 'err'); }
};

/**
 * 份数明细 —— 全部从 POS 明细读出来，收银员不用自己数、更不用手填。
 *
 *   买单人数   = POS 的 customer_num
 *   付费套餐   = counts_visit 的套餐行里【行合计 > 0】的份数
 *   免费套餐   = 同上但【行合计 = 0】的份数（整行免单）
 *
 * ★ 规则表没收录的菜品会被安全默认当成「不计次」，份数吞成 0。
 *   那种 0 和「本来就没点套餐」在界面上长得一模一样，收银员没法判断
 *   该不该手工补 —— 所以这里必须把菜品名点出来，明说是漏配。
 */
function renderPortionBreakdown(o) {
  const el = $('#portion-detail');
  if (!el) return;
  const bits = [];
  if (o.customer_num) bits.push(`买单 ${o.customer_num} 人`);
  if (o.portions_paid)  bits.push(`付费套餐 <b>${o.portions_paid}</b> 份`);
  if (o.portions_free)  bits.push(`免费套餐 <b>${o.portions_free}</b> 份`);
  if (o.allocated_portions) bits.push(`已分配 ${o.allocated_portions} 份`);

  let html = bits.length ? `<div class="port-bits">${bits.join(' · ')}</div>` : '';

  // 明细还没归档过来 —— 和「客人没点套餐」长得一样，必须说破
  if (o.detail_missing) {
    html += `<div class="port-warn">⚠ 这一单的<b>菜品明细还没同步过来</b>，所以份数显示 0。
             <br>这不是没点套餐，也不是规则没配 —— 过几分钟再查一次即可。
             <br>若急着发分，份数请按实际用餐人数手工填写。</div>`;
  }

  const unknown = o.unknown_items || [];
  if (unknown.length) {
    html += `<div class="port-warn">⚠ 这些菜品不在「套餐规则」里，份数按 0 计：
             ${unknown.map(escapeHtml).join('、')}<br>
             如果它们属于套餐，请让经理到后台补规则，别在这里手工凑数字。</div>`;
  }
  el.innerHTML = html;
  el.hidden = !html;
}

/* ── 步骤 4：分配 ────────────────────────────────── */
function startAssign() {
  const o = S.order;
  $('#assign-title').textContent = { 1: '整单记给一位会员', 2: '均摊 AA', 3: '点选菜品' }[S.mode];
  $('#sum-total').textContent = money(o.remaining_cents);
  $('#sum-port-total').textContent = o.remaining_portions;
  renderPortionBreakdown(o);
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
          ? `<b>${escapeHtml(p.member.card_no)}</b><small>${p.member.points_balance} 分 · 已 ${p.member.visit_count} 次${p.member.points_frozen ? ' · 待确认' : ''}</small>
             <div class="reward-slot" data-rw="${i}"></div>`
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

  // 每位已选会员异步补上「奖励进度 / 可用券」
  S.people.forEach((p, i) => { if (p.member) loadRewardSlot(i, p.member.id); });

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

/* ── 奖励券 ──────────────────────────────────────── */
/**
 * 显示该会员的攒次进度与可用券。
 * ★ 核销只改侧系统的券状态；收银员还要在 POS 上打对应折扣 ——
 *   两边通过 serial_id 对账，界面上要把这句写清楚，别让人以为点一下就打折了。
 */
async function loadRewardSlot(personIndex, memberId) {
  const slot = $(`[data-rw="${personIndex}"]`);
  if (!slot) return;
  try {
    const d = await api('/member/rewards', { member_id: memberId });
    const n = d.available.length;
    slot.innerHTML = n
      ? `<div class="reward-has">🎁 有 <b>${n}</b> 张可用券
           <button class="link" data-redeem="${personIndex}">核销一张</button>
         </div>
         <div class="reward-progress muted">${escapeHtml(d.progress.text)}</div>`
      : `<div class="reward-progress muted">${escapeHtml(d.progress.text)}</div>`;
    slot._coupons = d.available;

    const btn = $(`[data-redeem="${personIndex}"]`, slot);
    if (btn) btn.onclick = () => redeemCoupon(personIndex, memberId);
  } catch {
    slot.innerHTML = '';   // 查不到就不显示，别打断收银
  }
}

async function redeemCoupon(personIndex, memberId) {
  const slot = $(`[data-rw="${personIndex}"]`);
  const list = (slot && slot._coupons) || [];
  if (!list.length) return;
  const c = list[0];   // 最早到期的那张（服务端已排好序）
  if (!await UI.confirm(
    `核销券 ${c.code}？\n\n` +
    `有效期至 ${c.valid_to || '永久'}\n\n` +
    `★ 核销后请记得在 POS 上打对应的折扣，两边才对得上账。`,
    { okText: '下一步：验 PIN' }
  )) return;

  /**
   * 核销必须验卡背 PIN —— 这是整条链路上唯一真正会造成损失的一步。
   * 二维码印在卡正面可被拍照复制，PIN 藏在刮开层下，只有真正拿到卡的
   * 人知道。积分入账那一侧不验：被人抄卡去攒分，店家没有损失。
   */
  const pin = await UI.input(
    `请让客人刮开卡背，报出 6 位 PIN`,
    { password: true, numeric: true, okText: '核销' }
  );
  if (pin === null) return offerForceRedeem(c, personIndex, memberId);

  try {
    await api('/coupon/redeem', {
      coupon_id: c.id, serial_id: S.order ? S.order.serial_id : null, pin,
    });
    toast(`券 ${c.code} 已核销，请到 POS 打折`, 'ok');
    loadRewardSlot(personIndex, memberId);
  } catch (e) {
    toast(e.message, 'err');
    // PIN 这一类失败才提议强制核销；券本身的问题（过期、已用）提议也没用
    if (['pin_wrong', 'pin_locked', 'pin_required', 'card_missing', 'pin_not_set'].includes(e.error)) {
      offerForceRedeem(c, personIndex, memberId);
    }
  }
}

/**
 * 经理强制核销。
 *
 * PIN 用 bcrypt 存、不可还原 —— 客人忘了、或卡背磨花了，谁也查不出来。
 * 不留这条路，客人就得白跑一趟；把 PIN 存成可解密的又等于库一丢全部泄露。
 * 所以：留后门，但要经理权限、必须填原因、单独记一条审计事件。
 */
async function offerForceRedeem(c, personIndex, memberId) {
  if (!S.operator || !S.operator.is_manager) {
    return;   // 收银员没这个权限，连提都不提，免得白按
  }
  if (!await UI.confirm(
    `客人报不出 PIN？\n\n` +
    `经理可以强制核销这张券。此操作会单独记入审计日志。`,
    { okText: '强制核销', danger: true }
  )) return;

  const reason = await UI.input('强制核销原因（会记入审计日志）', {
    value: '客人忘记卡背 PIN', okText: '确认强制核销', danger: true,
  });
  if (reason === null) return;

  try {
    await api('/coupon/redeem', {
      coupon_id: c.id, serial_id: S.order ? S.order.serial_id : null,
      force: true, reason,
    });
    toast(`券 ${c.code} 已强制核销，请到 POS 打折`, 'ok');
    loadRewardSlot(personIndex, memberId);
  } catch (e) { toast(e.message, 'err'); }
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
      <div class="meta">${escapeHtml(e.card_no)} · € ${e.amount} · 计次 +${e.visits}</div></div>`).join('')
      // 本次达标发了新券就大字提示 —— 服务员要当场告诉客人
      + (d.rewards || []).map(r => r.granted > 0
        ? `<div class="card reward-card">
             <div class="amount">🎁 +${r.granted} 张免费券</div>
             <div class="meta">${escapeHtml(r.card_no)} 已达标，请告知客人下次可用</div>
             <div class="meta">券码 ${r.coupons.map(c => escapeHtml(c.code)).join('、')}</div>
           </div>`
        : `<div class="card reward-card">
             <div class="amount">🎁 已达标 ${r.pending} 次</div>
             <div class="meta">${escapeHtml(r.card_no)} 达到门槛，但后台设为「人工发券」，请经理在后台发放</div>
           </div>`).join('');
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
  resetLookupState();                    // 里面已经清了错误提示
  const contact = $('#new-contact');
  if (contact) { contact.open = false; }
  $('#member-modal').hidden = false;
  UI.back.sync();
  setTimeout(() => $('#member-input').focus(), 50);
}
$('#btn-member-close').onclick = () => { $('#member-modal').hidden = true; UI.back.sync(); };

$$('#search-type button').forEach(b => b.onclick = () => {
  $$('#search-type button').forEach(x => x.classList.toggle('on', x === b));
  const t = b.dataset.type;
  const inp = $('#member-input');
  inp.placeholder = { card: '卡面号码，如 TK-00000123-4Q7', phone: '完整手机号', email: '邮箱地址' }[t];
  inp.inputMode = t === 'phone' ? 'tel' : 'text';
  $('#btn-scan').hidden  = t !== 'card';
  $('#scan-note').hidden = t !== 'card';
  inp.focus();
});

$('#btn-member-search').onclick = doMemberSearch;
$('#member-input').addEventListener('keydown', e => { if (e.key === 'Enter') doMemberSearch(); });

/**
 * 每次查询前把上一次的痕迹清干净。
 *
 * 不清会出两种问题，第二种是真的会记错账：
 *
 *   ① 先查一张有效的库存卡（显示「这张卡尚未启用：TK-xxx」），
 *      再查一个不存在的卡号 —— 错误提示出来了，可上一张卡的提示还挂在
 *      旁边，收银员同时看到「卡号错误」和一个卡号，不知道该信哪个。
 *
 *   ② 扫了卡 A 之后切到手机号档查找，没找到 → 建卡表单展开，
 *      而 S.pendingCard 还是卡 A —— 点「启用」就把卡 A 绑给了这个人。
 *      收银员此刻根本没在想卡 A。
 *
 * 所以：每条查询路径开头都调它，最后一次操作说了算。
 */
function resetLookupState() {
  showErr('#member-err', '');
  S.pendingCard = null;
  $('#member-result').innerHTML = '';
  $('#new-card-hint').innerHTML = '';
  $('#member-new').open = false;
}

async function doMemberSearch() {
  resetLookupState();
  const type = $('#search-type button.on').dataset.type;
  const value = $('#member-input').value.trim();
  if (!value) return showErr('#member-err', '请输入查询内容');

  // 卡号走 /card/lookup 而不是 /member/search：实体卡有四种状态，
  // 「查无此人」这一种答案不够用 —— 库存卡要引导去建会员，
  // 作废卡要说清楚换一张，不是本店的卡要当场拒绝。
  if (type === 'card') { return doCardLookup(value); }

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

/**
 * 扫卡/输卡号之后：系统判断这张卡是什么状态，界面据此决定下一步。
 *
 *   active → 认出会员，直接选用
 *   stock  → 展开建卡表单，把卡号带上
 *   其它   → 报错（不是本店的卡 / 已作废 / 卡号不完整）
 */
async function doCardLookup(value) {
  resetLookupState();
  try {
    const d = await api('/card/lookup', { card_no: value });

    /**
     * 过期卡 —— 不是死路，是换卡的入口。
     *
     * 卡面印着有效期，那是客人唯一能看到的告知；到期前到店换新卡则
     * 积分、计次、未兑换的券全部结转。所以这里直接引导收银员换卡，
     * 而不是丢一句「此卡已过期」让客人白跑。
     */
    if (d.state === 'expired') {
      return handleExpiredCard(d);
    }

    if (d.state === 'active') {
      const m = d.member;
      const soon = d.days_left !== null && d.days_left <= EXPIRING_SOON_DAYS;
      $('#member-result').innerHTML = `
        <div class="found"><b>${escapeHtml(m.card_no)}</b>
          <div class="muted small">${m.points_balance} 分 · 已消费 ${m.visit_count} 次${
            d.valid_to ? ' · 有效期至 ' + escapeHtml(d.valid_to) : ''}</div>
          ${soon ? `<div class="frozen">这张卡还有 ${d.days_left} 天到期，可以现在就为客人换一张（积分会转过去）</div>` : ''}
          ${m.points_frozen ? '<div class="frozen">该会员尚未完成确认，积分照常入账但暂不可兑换</div>' : ''}
          <button class="primary" id="btn-use-member" style="margin-top:10px">选用</button></div>`;
      $('#btn-use-member').onclick = () => useMember(m);
      return;
    }

    /**
     * 库存卡 —— 这张卡是新的，引导建会员。
     *
     * 但先看它还能用多久：快到期的卡发出去，客人拿回家没多久就得再跑一趟。
     * 阈值 30 天，超过就直接放行不打扰。
     */
    if (d.days_left !== null && d.days_left <= EXPIRING_SOON_DAYS) {
      const go = await UI.confirm(
        `这张卡只剩 ${d.days_left} 天就到期了（${d.valid_to}）。\n\n` +
        `发给客人的话，他很快就得回来换卡。\n` +
        `建议换一张有效期更长的。确定还要发这张吗？`,
        { okText: '仍然发这张', cancelText: '换一张', danger: true }
      );
      if (!go) {
        resetLookupState();
        $('#member-input').value = '';
        $('#member-input').focus();
        return;
      }
    }

    S.pendingCard = d.card_no;
    $('#new-card-hint').innerHTML =
      `这张卡尚未启用：<b>${escapeHtml(d.card_no)}</b>`
      + (d.valid_to ? `　<span class="muted">有效期至 ${escapeHtml(d.valid_to)}</span>` : '');
    $('#member-new').open = true;
    setTimeout(() => $('#btn-member-create').focus(), 50);
  } catch (e) {
    // 复位已在开头做过，这里只负责报错
    showErr('#member-err', e.message);
  }
}

$('#btn-member-create').onclick = async () => {
  showErr('#member-err', '');
  if (!S.pendingCard) {
    // 没有卡就建不了会员 —— 与其让服务端报错，不如在这里说清楚该做什么
    return showErr('#member-err', '请先扫描或输入客人的实体卡号');
  }
  /**
   * 后台关闭「允许收集客人联系方式」时，这几个输入框已经从 DOM 里移除，
   * 请求里也一个字段都不带 —— 服务端此时会拒收非空值。
   * 开启时才走双重确认那一套（积分先冻结），不填则当场生效。
   */
  const body = { card_no: S.pendingCard };
  if (S.settings.collect_pii) {
    body.phone    = $('#new-phone').value.trim();
    body.email    = $('#new-email').value.trim();
    body.birthday = $('#new-birthday').value || null;
  }
  try {
    const d = await api('/member/create', body);
    toast(d.consent_pending ? '已绑卡，等客人确认后可兑换' : '已绑卡，当场即可使用', 'ok');
    // 这几个输入框在关闭收集时【已从 DOM 移除】，必须判空 ——
    // 否则 null.value 抛异常，后面的 useMember 永远执行不到，
    // 表现是「提示说成功了，但弹层不关、会员也没选中」
    ['#new-phone', '#new-email', '#new-birthday'].forEach(sel => {
      const el = $(sel);
      if (el) { el.value = ''; }
    });
    S.pendingCard = null;
    if (d.consent_pending) {
      await runConsentFlow(d.member, d.consent_code);
    }
    useMember(d.member);
  } catch (e) { showErr('#member-err', e.message); }
};

/** 剩多少天算「快到期」。发卡与用卡两处都按它提醒 */
const EXPIRING_SOON_DAYS = 30;

/**
 * 过期卡的处理 —— 引导换发新卡，积分结转。
 *
 * 分两种情况：
 *   · 卡没绑过人（库存里躺过期了）→ 这张卡废了，让收银员换一张发
 *   · 卡绑着会员 → 走换卡：扫一张新卡，积分/计次/未用的券全部转过去
 */
async function handleExpiredCard(d) {
  if (!d.member) {
    showErr('#member-err',
      `此卡已于 ${d.valid_to} 过期，且从未启用过。请另取一张卡发给客人。`);
    return;
  }

  const who = `${d.card_no}　${d.member.points_balance} 分 · 已消费 ${d.member.visit_count} 次`;
  const go = await UI.confirm(
    `此卡已于 ${d.valid_to} 过期。\n\n${who}\n\n` +
    `现在可以为客人换一张新卡，积分与未用的券会全部转过去。\n` +
    `要换吗？`,
    { okText: '换发新卡', cancelText: '暂不处理' }
  );
  if (!go) {
    showErr('#member-err', `此卡已于 ${d.valid_to} 过期，可随时到店换发新卡`);
    return;
  }

  for (;;) {
    const raw = await UI.input(
      '请扫描或输入要发给客人的【新卡】卡号',
      { okText: '换发', cancelText: '取消' }
    );
    if (raw === null) {
      showErr('#member-err', `此卡已于 ${d.valid_to} 过期，可随时到店换发新卡`);
      return;
    }
    try {
      const r = await api('/card/replace', {
        member_id: d.member.id, card_no: raw,
        reason: `原卡 ${d.card_no} 于 ${d.valid_to} 到期`,
      });
      toast(`已换发 ${r.card_no}，积分已转移`, 'ok');
      resetLookupState();
      $('#member-input').value = '';
      // 换完直接选用这位会员，收银员不用再查一遍
      if (r.member) { useMember(r.member); }
      return;
    } catch (e) {
      toast(e.message, 'err');
      // 新卡本身有问题（已被占用、也过期了…）时让他再扫一张
      if (!['card_unknown', 'card_malformed', 'card_expired', 'card_not_available'].includes(e.error)) {
        return;
      }
    }
  }
}

/**
 * 现场确认码。
 *
 * 客人留了联系方式 → 系统发一个 6 位码到他手机/邮箱 → 他当场报给收银员
 * → 这里输入即完成确认，积分解冻。
 *
 * 不用「点短信里的链接」是因为那需要一个公网可达的端点接收点击，
 * 而门店网络是单向的（能出去、进不来）。
 *
 * 任何一步都可以跳过：卡已经绑好了，积分照常入账，只是暂时冻结不可兑换，
 * 客人下次到店再补确认即可。绝不为了这一步卡住收银。
 */
async function runConsentFlow(member, sent) {
  if (!sent || sent.error) {
    toast('确认码没发出去，积分会先冻结（可稍后在会员处重发）', 'err');
    return;
  }

  const where = sent.channel === 'sms' ? '手机短信' : '邮箱';
  let tip = `确认码已发到客人的${where}。\n请让客人报出 6 位数字。`;

  for (;;) {
    const code = await UI.input(tip, {
      numeric: true, okText: '确认', cancelText: '稍后再说',
    });
    if (code === null) {
      toast('未确认，积分先冻结', 'err');
      return;
    }

    try {
      await api('/consent/verify', { member_id: member.id, code });
      member.consent_status = 1;
      member.points_frozen  = false;
      toast('已确认，积分可以兑换了', 'ok');
      return;
    } catch (e) {
      // 码错了还能再试；过期或锁死就只能重发
      if (e.error === 'code_wrong') {
        const left = e.detail && e.detail.left;
        tip = `确认码不正确${left ? `（还可以试 ${left} 次）` : ''}。\n请客人再报一次。`;
        continue;
      }
      if (e.error === 'code_expired' || e.error === 'code_locked') {
        if (!await UI.confirm(`${e.message}\n\n要重新发一条吗？`, { okText: '重新发送' })) {
          toast('未确认，积分先冻结', 'err');
          return;
        }
        try {
          const r = await api('/consent/send', { member_id: member.id });
          const w = r.channel === 'sms' ? '手机短信' : '邮箱';
          tip = `新的确认码已发到客人的${w}。\n请让客人报出 6 位数字。`;
          continue;
        } catch (e2) {
          toast(e2.message, 'err');
          return;
        }
      }
      toast(e.message, 'err');
      return;
    }
  }
}

/* ── 扫码 ─────────────────────────────────────────
 * 相机走容器桥接，二维码识别用 Chromium 自带的 BarcodeDetector ——
 * 不额外引第三方库：门店内网装不了 CDN，自带一份又要长期跟版本。
 * 平台不支持时不硬撑，直接引导手工输入（卡面本来就印着人可读号码）。
 */
let scanStream = null;
let scanTimer  = null;

function stopScan() {
  if (scanTimer) { clearTimeout(scanTimer); scanTimer = null; }
  if (scanStream) {
    // 不关流的话相机指示灯常亮，后续再取流也会失败
    scanStream.getTracks().forEach(t => t.stop());
    scanStream = null;
  }
  $('#scan-video').srcObject = null;
  $('#scan-modal').hidden = true;
  UI.back.sync();
}

$('#btn-scan-cancel').onclick = stopScan;

$('#btn-scan').onclick = async () => {
  showErr('#member-err', '');
  showErr('#scan-err', '');

  if (typeof window.BarcodeDetector !== 'function') {
    return showErr('#member-err',
      '本设备不支持扫码，请照着卡面手工输入卡号。'
    + '不用纠结哪个是字母哪个是数字，看着像什么就输什么，系统会自动纠正');
  }
  if (!window.SushiVIP || !SushiVIP.cameraSupported()) {
    return showErr('#member-err',
      window.isSecureContext === false
        ? '页面不在 HTTPS 下，相机不可用；请手工输入卡号'
        : '相机不可用，请手工输入卡号');
  }

  $('#scan-modal').hidden = false;
  UI.back.sync();
  $('#scan-msg').textContent = '正在打开相机…';

  try {
    scanStream = await SushiVIP.openCamera();
    const v = $('#scan-video');
    v.srcObject = scanStream;
    await v.play();
    $('#scan-msg').textContent = '把卡面的二维码对准取景框';
  } catch (e) {
    stopScan();
    return showErr('#member-err', '打开相机失败：' + (e && e.message ? e.message : e));
  }

  const det = new BarcodeDetector({ formats: ['qr_code'] });
  const tick = async () => {
    if (!scanStream) return;                    // 已取消
    try {
      const codes = await det.detect($('#scan-video'));
      if (codes && codes.length) {
        const raw = String(codes[0].rawValue || '').trim();
        stopScan();
        $('#member-input').value = raw;
        // 扫到什么就查什么，交给服务端判断是不是本店的卡
        return doCardLookup(raw);
      }
    } catch (e) { /* 单帧识别失败无所谓，下一帧继续 */ }
    scanTimer = setTimeout(tick, 200);
  };
  tick();
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
  UI.back.sync();
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
    enterMain(d.operator, d.settings);
  } catch {
    try {
      const h = await api('/health', undefined, 'GET');
      if (!h.local_db) $('#health-note').textContent = '本地数据库连接异常，请联系管理员';
    } catch {
      $('#health-note').textContent = '无法连接本机服务';
    }
  }
})();

/* ── 物理返回键：注册 Pad 的层级 ────────────────────
 * 容器的返回键先问 canGoBack()。Pad 是单页状态机，不写历史就恒为 false，
 * 于是收银员在记账任何一步按返回，弹的都是「确认退出应用」。
 * 注册之后：弹层 → 步骤 → 逐级后退，退到第一步才轮到容器弹退出确认。
 * ui.js 自己的弹层永远排在最上面，这里只管步骤与两个模态框。
 */
UI.back.register({
  deep: () => !$('#scan-modal').hidden
            || !$('#pin-modal').hidden
            || !$('#member-modal').hidden
            || CURRENT_STEP !== 'step-table',
  back: () => {
    // 扫码弹层排在最上面，且必须走 stopScan —— 直接隐藏会把相机留着不关
    if (!$('#scan-modal').hidden) { stopScan(); return true; }
    if (!$('#member-modal').hidden) { $('#member-modal').hidden = true; return true; }
    if (!$('#pin-modal').hidden)    { $('#pin-modal').hidden    = true; return true; }
    const prev = STEP_BACK[CURRENT_STEP];
    if (prev) { step(prev); return true; }
    return false;
  },
});
