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
      console.warn('[pad] native device id unavailable, falling back', e);
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
  const opt = {
    method,
    // ★ X-Lang 每次都带：服务端据此决定用哪种语言回错误文案。
    //   不带的话会出现「界面西语、报错中文」——前端不翻服务端的错误，
    //   那套文案只在 Api::MESSAGES 里有一份（见 i18n.js 顶部说明）。
    headers: { 'Content-Type': 'application/json', 'X-Lang': I18N.lang },
    credentials: 'same-origin',
  };
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
    throw { error: 'network', message: T('net.down') };
  }
  try {
    raw  = await res.text();
    json = JSON.parse(raw);
  } catch (e) {
    const head = raw.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 160);
    throw {
      error: 'bad_response',
      message: T('net.notJson', { status: res.status, head: head || T('net.emptyBody') }),
    };
  }
  if (!res.ok || json.ok === false) {
    throw { error: json.error || 'server_error', message: json.message || T('net.failed'), detail: json.detail };
  }
  return json.data;
}

/* ── 登录 ────────────────────────────────────────── */
$('#btn-login').onclick = async () => {
  showErr('#login-err', '');
  const name = $('#login-name').value.trim();
  const pin  = $('#login-pin').value;
  if (!name || !pin) return showErr('#login-err', T('login.needBoth'));
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
  /**
   * 退回登录页 = 回到「没有人登录」的状态，语言也该回到【这台平板】的设置，
   * 而不是留着上一个人的。
   *
   * 不这么做的话，登录页的语言会跟着「最后一个用的人」漂 ——
   * 那正是我们在账号这一层特意避开的「谁最后用算谁的」。
   * （下一个人登录时照样会切成他自己的，所以这只影响登录页本身。）
   */
  I18N.set(I18N.initial(S.settings.default_lang || 'zh'), { remember: false });
  renderLangSwitch();
  $('#view-main').classList.remove('active');
  $('#view-login').classList.add('active');
  $('#login-pin').value = '';
};

function enterMain(op, settings) {
  S.operator = op;
  S.settings = settings || {};
  /**
   * 语言以【账号】为准，不是以这台平板为准。
   *
   * 服务端已经把「自己选过的 > 后台默认」算好放在 op.lang 里，
   * 这里直接用就行 —— 回落逻辑放前端的话，登录、会话恢复、切换后
   * 三个入口各写一遍，漏一处就是「换台平板语言变了」。
   *
   * remember:false —— 不要把别人的语言写进这台平板的本地记录，
   * 那是登录页专用的，覆盖了就变成「谁最后登录算谁的」。
   */
  if (op.lang) { I18N.set(op.lang, { remember: false }); }
  applySettings();
  renderLangSwitch();
  renderOpName();
  $('#view-login').classList.remove('active');
  $('#view-main').classList.add('active');
  resetFlow();
  /**
   * 带参数的那几处文字是 JS 填的（「放宽到 N 分钟再找」「查找最近 N 分钟…」），
   * 登录后必须先填一次 —— 漏了的话那个按钮上一个字都没有，
   * 界面上看起来就是「按钮不见了」。
   *
   * refreshDynamicText 里已经带了 checkHealth，不要再单独调一次。
   */
  refreshDynamicText();
}

/**
 * 顶栏那个人名。
 *
 * 名字本身也要跟着语言走 —— 否则会出现「系统管理员 (encargado)」
 * 这种中西混排。两个名字在登录时一并下发（op.names），
 * 所以切语言时就地换掉即可，不用再请求一次服务器。
 *
 * 老账号可能没填西语名，服务端已经在 names 里回落成中文名了，
 * 这里不需要再判一次。
 */
function renderOpName() {
  const el = $('#op-name');
  if (!el) return;
  const op = S.operator;
  if (!op) { el.textContent = ''; return; }
  const name = (op.names && op.names[I18N.lang]) || op.name || '';
  el.textContent = name + (op.is_manager ? T('top.manager') : '');
}

/**
 * 语言切换器 —— 登录页和顶栏各一个。
 *
 * 登录后切换会落库（记在账号上），登录前只存在这台平板本地：
 * 那时还不知道是谁在登录。
 */
function renderLangSwitch() {
  const langs = (S.settings && S.settings.langs) || { zh: '中文', es: 'Español' };
  [['#lang-login', false], ['#lang-main', true]].forEach(([sel, loggedIn]) => {
    const box = $(sel);
    if (!box) return;
    box.innerHTML = Object.keys(langs).map(code =>
      `<button class="lang-btn${code === I18N.lang ? ' on' : ''}" data-lang="${code}">${escapeHtml(langs[code])}</button>`
    ).join('');
    box.querySelectorAll('[data-lang]').forEach(b => {
      b.onclick = () => switchLang(b.getAttribute('data-lang'), loggedIn);
    });
  });
}

async function switchLang(lang, persist) {
  if (lang === I18N.lang) return;
  // 先切界面：落库失败也不该让人卡在切不动的语言上
  I18N.set(lang, { remember: !persist });
  refreshDynamicText();
  renderLangSwitch();
  if (persist && S.operator) {
    try { await api('/auth/lang', { lang }); } catch (e) { toast(e.message, 'err'); }
  }
}

/**
 * 切语言后，把【JS 生成的】那些文字重画一遍。
 *
 * `I18N.applyDom()` 只管带 data-i18n 的静态节点；订单卡片、分配行、
 * 结果页这些是拼出来的 HTML，得各自重画。当前停在哪一步就画哪一步 ——
 * 全量重画会把收银员正在填的东西冲掉。
 */
function refreshDynamicText() {
  $('#table-hint').textContent = T('lookup.tableHint', { min: S.window || 30 });
  $('#btn-widen').textContent  = T('lookup.widen', { min: S.widenTo || 60 });
  renderOpName();
  if (S.orders && S.orders.length) { renderOrders(S.orders); }
  if (S.order) {
    renderSummary(S.order);
    renderPortionBreakdown(S.order);
    const title = $('#assign-title');
    if (title) {
      title.textContent = { 1: T('assign.mode1'), 2: T('assign.mode2'), 3: T('assign.mode3') }[S.mode];
    }
  }
  /**
   * ★ 这里【不能】调 startAssign() 重画分配步骤。
   *
   * 它会把 S.people 重置掉（模式 1 直接整个重建），收银员已经填好的
   * 金额和选好的会员会当场清空 —— 只是切个语言而已，不该有这种代价。
   *
   * renderPeople() 是安全的：金额与份数每敲一下就同步进 S.people 了
   * （见 data-amt / data-prt 的 oninput），从状态重画不会丢东西。
   */
  if (S.people && S.people.length) { renderPeople(true); }
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
      pill.textContent = T('top.posDown');
      pill.hidden = false;
    } else {
      pill.hidden = true;
    }
    noteVersion(h.app_version);
  } catch {}
}

/**
 * 代码更新的自动落地。
 *
 * 现场的痛点：代码传上去了，Pad 上还是旧页面 —— 而 Pad 没有地址栏，
 * 点「刷新」也没用（WebView 认为自己已经有 pad.js 了，压根不去取）。
 *
 * 页面改成 index.php + 资源带版本号之后，重新加载一次就必然拿到新代码。
 * 这里负责的是「什么时候重新加载」：服务端报的版本和手里这份对不上，
 * 就在【安全的时机】自己刷掉，不用人去按那个按钮。
 *
 * ★ 绝不能在收银员干活干到一半时刷新 —— 已经填好的金额、选好的会员
 *   会当场清空，比看到旧界面严重得多。所以只在两种时刻动手：
 *     · 还停在第一步、什么都没开始
 *     · 一单做完点「下一单」的那一刻
 *   都不满足就先记下来，等下一次到达安全点再说。
 */
let pendingUpdate = false;
let targetVersion = null;

function noteVersion(serverVersion) {
  if (!serverVersion || !window.APP_VERSION) return;   // 老页面没有版本号，跳过
  if (String(serverVersion) === String(window.APP_VERSION)) return;
  targetVersion = String(serverVersion);
  pendingUpdate = true;
  applyUpdateIfIdle();
}

/**
 * 🔴 同一个版本只刷一次。
 *
 * 刷完之后版本还是对不上，说明刷新解决不了问题（比如两处的版本号
 * 口径不一致、或者中间有个绕不开的缓存）。这时候【必须停手】——
 * 收银机陷入无限刷新，比看到旧界面严重得多，那是整台机器没法用。
 *
 * 记在 sessionStorage：它跨得过刷新，又会随应用被划掉而清空 ——
 * 正好是「这一轮别再试了，下次重开可以再试」的语义。
 */
function alreadyTried(version) {
  try {
    if (sessionStorage.getItem('vip_reload_for') === version) { return true; }
    sessionStorage.setItem('vip_reload_for', version);
  } catch (e) { /* 隐私模式读不到，那就允许刷这一次 */ }
  return false;
}

function applyUpdateIfIdle() {
  if (!pendingUpdate) return;
  const busy = CURRENT_STEP !== 'step-table'
            || (S.people && S.people.length > 0)
            || S.order !== null
            || !$('#member-modal').hidden
            || !$('#scan-modal').hidden
            || !$('#pin-modal').hidden;
  if (busy) return;
  pendingUpdate = false;
  if (alreadyTried(targetVersion)) { return; }
  // 版本号带在 URL 上：连文档本身也绕开任何中间缓存，不只靠 no-store
  location.replace(location.pathname + '?v=' + encodeURIComponent(String(Date.now())));
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
  if (!oldPin || !p1) return showErr('#pin-err', T('pin.needBoth'));
  if (p1 !== p2)      return showErr('#pin-err', T('pin.mismatch'));
  if (p1.length < 6)  return showErr('#pin-err', T('pin.tooShort'));
  try {
    await api('/auth/change-pin', { old_pin: oldPin, new_pin: p1 });
    $('#pin-modal').hidden = true;
    toast(T('pin.changed'), 'ok');
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
  /**
   * 每一单都从【桌号】开始。
   *
   * 客人还在桌上、收银员手边就是桌号，这是绝大多数情况。
   * 小票号是补救路径：客人拿着小票折返，或者桌号对不上。
   *
   * 这里【不】沿用上一单选过的那种 —— 沿用的话，偶尔办一次小票号的单，
   * 之后每一单都停在小票号上，收银员得反复切回来。
   * 「默认」就该是每单都回到默认。
   */
  setLookupMode('table');
}

/* 两种找单方式切换：桌号（默认，最常用）/ 小票号（精确，补救用） */
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
$('#btn-new').onclick = () => { resetFlow(); applyUpdateIfIdle(); };
$$('[data-back]').forEach(b => b.onclick = () => step(b.dataset.back));

async function locate(windowMinutes) {
  const table = $('#table-input').value.trim();
  showErr('#locate-err', '');
  if (!table) return showErr('#locate-err', T('lookup.needTable'));
  try {
    const d = await api('/order/locate', { table_name: table, window_minutes: windowMinutes || 0 });
    S.window  = d.window;
    S.widenTo = d.fallback_window;
    $('#table-hint').textContent = T('lookup.tableHint', { min: d.window });
    $('#btn-widen').textContent  = T('lookup.widen', { min: d.fallback_window });
    if (!d.candidates.length) {
      showErr('#locate-err', T('lookup.noneInWindow', { min: d.window, table }));
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
$('#btn-widen').onclick = () => locate(S.widenTo || 60);
$('#btn-manual').onclick = () => { openManual(); };

/**
 * 按小票号找单 —— Factura Simplificada = 全局唯一，不需要时间窗。
 * 前导零可不输，界面上照着小票原样输也认。
 */
async function locateByInvoice() {
  const raw = $('#invoice-input').value.trim();
  showErr('#locate-err', '');
  $('#locate-fallback').hidden = true;
  if (!raw) return showErr('#locate-err', T('lookup.needInvoice'));
  try {
    const d = await api('/order/locate-invoice', { invoice_no: raw });
    if (!d.candidates.length) {
      if (d.reason === 'too_old') {
        showErr('#locate-err',
          T('lookup.tooOld', { date: (d.order_end_time || '').slice(0, 10), days: d.max_days }));
      } else {
        showErr('#locate-err', T('lookup.invoiceNone', { no: d.invoice_no }));
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
  S.orders = list;
  const box = $('#order-list');
  box.innerHTML = '';
  list.forEach(o => {
    const b = document.createElement('button');
    b.className = 'card' + (o.eligible ? '' : ' disabled');
    const time = o.order_end_time.slice(11, 16);
    const reason = {
      not_dine_in: T('order.notDineIn'),
      zero_amount: T('order.zero'),
      free_meal:   T('order.freeMeal'),
      // 明细里有 TARJETA 10+1 折扣行 —— 客人正在兑换奖励，这一餐不计次不积分
      redeemed:    T('order.redeemed', { amount: o.redeem_amount || '' }),
    }[o.ineligible_reason] || '';
    b.innerHTML = `
      <div class="amount">€ ${o.total}</div>
      <div class="meta">${T('order.meta', { table: o.table_name, people: o.customer_num || '?', time, portions: o.portions_counted })}</div>
      <div class="meta">${T('order.serial', { serial: o.serial_id })}${Number(o.allocated_cents) > 0 ? ' · ' + T('order.already', { amount: o.allocated }) : ''}</div>
      ${reason ? `<div class="meta" style="color:var(--warn)">${reason}</div>` : ''}`;
    b.onclick = () => {
      if (!o.eligible) return toast(reason || T('order.notEligible'), 'err');
      if (o.remaining_cents <= 0) return toast(T('order.fullyDone'), 'err');
      selectOrder(o);
    };
    box.appendChild(b);
  });
}

function selectOrder(o) {
  S.order = o;
  S.people = []; S.picks = {};
  renderSummary(o);
  step('step-mode');
}

function renderSummary(o) {
  $('#order-summary').innerHTML = `
    <div class="amount">€ ${money(o.remaining_cents)} <span class="muted small">${T('order.avail')}</span></div>
    <div class="meta">${T('order.summaryMeta', { table: o.table_name, serial: o.serial_id, portions: o.remaining_portions })}</div>
    ${Number(o.excluded) > 0 ? `<div class="meta">${T('order.excluded', { amount: o.excluded })}</div>` : ''}`;

  const lb = $('#existing-ledger');
  if (o.existing_ledger && o.existing_ledger.length) {
    lb.innerHTML = `<b>${T('ledger.title')}</b>` + o.existing_ledger.map(l =>
      `<div class="lrow"><span>${l.card_no || T('common.member')} · € ${l.amount} · ${l.points} ${T('common.points')}</span>
       <button class="link" data-rev="${l.id}">${T('ledger.reverse')}</button></div>`).join('');
    lb.hidden = false;
    $$('[data-rev]', lb).forEach(b => b.onclick = () => doReverse(parseInt(b.dataset.rev, 10)));
  } else {
    lb.hidden = true;
  }
}

/* ── 撤销 ────────────────────────────────────────── */
async function doReverse(ledgerId) {
  const reason = await UI.input(T('reverse.ask'), {
    value: T('reverse.default'), okText: T('reverse.ok'), danger: true,
  });
  if (reason === null) return;
  try {
    await api('/points/reverse', { ledger_id: ledgerId, reason });
    toast(T('reverse.done'), 'ok');
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
  if (!await UI.confirm(T('freeMeal.ask'), { okText: T('freeMeal.ok'), danger: true })) return;
  try {
    await api('/order/free-meal', { serial_id: S.order.serial_id, is_free_meal: true });
    toast(T('freeMeal.done'), 'ok');
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
  if (o.customer_num) bits.push(T('assign.paidBy', { n: o.customer_num }));
  if (o.portions_paid)  bits.push(T('assign.portionsPaid', { n: o.portions_paid }));
  if (o.portions_free)  bits.push(T('assign.portionsFree', { n: o.portions_free }));
  if (o.allocated_portions) bits.push(T('assign.portionsDone', { n: o.allocated_portions }));

  let html = bits.length ? `<div class="port-bits">${bits.join(' · ')}</div>` : '';

  // 明细还没归档过来 —— 和「客人没点套餐」长得一样，必须说破
  if (o.detail_missing) {
    html += `<div class="port-warn">${T('assign.noDetail')}</div>`;
  }

  const unknown = o.unknown_items || [];
  if (unknown.length) {
    html += `<div class="port-warn">${T('assign.noRule')}
             ${unknown.map(escapeHtml).join('、')}<br>
      ${T('assign.noRuleTail')}</div>`;
  }
  el.innerHTML = html;
  el.hidden = !html;
}

/* ── 步骤 4：分配 ────────────────────────────────── */
function startAssign() {
  const o = S.order;
  $('#assign-title').textContent = { 1: T('assign.mode1'), 2: T('assign.mode2'), 3: T('assign.mode3') }[S.mode];
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
    body.innerHTML = `<label>${T('assign.aaCount')}
      <input id="aa-people" type="number" inputmode="numeric" min="1" max="50" value="${o.customer_num || 2}"></label>
      <button id="btn-aa" class="primary">${T('assign.aaSplit')}</button>`;
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
  if (!n || n < 1) return toast(T('assign.needCount'), 'err');
  try {
    const d = await api('/points/split', { serial_id: S.order.serial_id, people: n });
    S.people = d.shares.map(s => ({ member: null, amountCents: cents(s.amount), portions: s.portions }));
    renderPeople();
  } catch (e) { toast(e.message, 'err'); }
}

function renderPickItems(body) {
  const items = S.order.items || [];
  if (!items.length) {
    body.innerHTML = `<p class="muted">${T('assign.noItems')}</p>`;
    return;
  }
  body.innerHTML = `<p class="muted small">${T('assign.itemsHelp')}</p>
    <div class="items">${items.map((it, i) => `
      <div class="item">
        <span class="name">${escapeHtml(it.name)}${it.quantity > 1 ? ` ×${it.quantity}` : ''}
          ${it.counts_visit ? `<span class="tag">${T('assign.countsVisit')}</span>` : ''}
          ${it.is_waived ? `<div class="waived">${T('assign.waived', { amount: money(it.unit_cents * (it.quantity || 1)) })}</div>` : ''}
        </span>
        <span class="price">€ ${money(it.line_cents)}</span>
        <select data-item="${i}"></select>
      </div>`).join('')}</div>
    <button id="btn-add-person" class="ghost">${T('assign.addMember')}</button>`;
  $('#btn-add-person').onclick = addPerson;
  refreshPickSelects();
}

function refreshPickSelects() {
  $$('[data-item]').forEach(sel => {
    const idx = parseInt(sel.dataset.item, 10);
    const cur = S.picks[idx];
    sel.innerHTML = `<option value="">${T('assign.unclaimed')}</option>` +
      S.people.map((p, i) => `<option value="${i}"${cur === i ? ' selected' : ''}>${p.member ? p.member.card_no : T('assign.memberN', { n: i + 1 })}</option>`).join('');
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
          ? `<b>${escapeHtml(p.member.card_no)}</b><small>${p.member.points_balance} ${T('common.points')} · ${T('assign.visits', { n: p.member.visit_count })}${p.member.points_frozen ? ' · ' + T('assign.pendingTag') : ''}</small>
             <div class="reward-slot" data-rw="${i}"></div>`
          : `<button class="link" data-pick="${i}">${T('assign.pickMember')}</button>`}
      </div>
      <label class="amt">${T('assign.amount')}<input type="text" inputmode="decimal" data-amt="${i}" value="${money(p.amountCents)}"${S.mode === 3 ? ' readonly' : ''}></label>
      <label class="prt">${T('assign.portions')}<input type="number" inputmode="numeric" min="0" data-prt="${i}" value="${p.portions}"${S.mode === 3 ? ' readonly' : ''}></label>
      ${S.people.length > 1 ? `<button class="rm" data-rm="${i}">${T('assign.remove')}</button>` : ''}`;
    box.appendChild(d);
  });

  if (S.mode !== 1 && !keepItems) {
    const add = document.createElement('button');
    add.className = 'ghost';
    add.textContent = T('assign.addMember');
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
      ? `<div class="reward-has">${T('reward.has', { n })}
           <button class="link" data-redeem="${personIndex}">${T('reward.redeemOne')}</button>
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
    T('reward.ask', { code: c.code, validTo: c.valid_to || T('common.forever') }),
    { okText: T('reward.next') }
  )) return;

  /**
   * 核销必须验卡背 PIN —— 这是整条链路上唯一真正会造成损失的一步。
   * 二维码印在卡正面可被拍照复制，PIN 藏在刮开层下，只有真正拿到卡的
   * 人知道。积分入账那一侧不验：被人抄卡去攒分，店家没有损失。
   */
  const pin = await UI.input(
    T('reward.askPin'),
    { password: true, numeric: true, okText: T('reward.redeem') }
  );
  if (pin === null) return offerForceRedeem(c, personIndex, memberId);

  try {
    await api('/coupon/redeem', {
      coupon_id: c.id, serial_id: S.order ? S.order.serial_id : null, pin,
    });
    toast(T('reward.done', { code: c.code }), 'ok');
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
    T('reward.forceAsk'),
    { okText: T('reward.forceOk'), danger: true }
  )) return;

  const reason = await UI.input(T('reward.forceWhy'), {
    value: T('reward.forceWhyDef'), okText: T('reward.forceConfirm'), danger: true,
  });
  if (reason === null) return;

  try {
    await api('/coupon/redeem', {
      coupon_id: c.id, serial_id: S.order ? S.order.serial_id : null,
      force: true, reason,
    });
    toast(T('reward.forceDone', { code: c.code }), 'ok');
    loadRewardSlot(personIndex, memberId);
  } catch (e) { toast(e.message, 'err'); }
}

/* ── 提交 ────────────────────────────────────────── */
$('#btn-submit').onclick = async () => {
  showErr('#assign-err', '');
  const missing = S.people.some(p => !p.member && p.amountCents > 0);
  if (missing) return showErr('#assign-err', T('assign.missingMember'));

  const allocations = S.people
    .filter(p => p.member && (p.amountCents > 0 || p.portions > 0))
    .map(p => ({ member_id: p.member.id, amount: money(p.amountCents), portions: p.portions }));
  if (!allocations.length) return showErr('#assign-err', T('assign.needOne'));

  const btn = $('#btn-submit');
  btn.disabled = true;
  try {
    const d = await api('/points/grant', { serial_id: S.order.serial_id, mode: S.mode, allocations });
    $('#done-body').innerHTML = d.entries.map(e => `
      <div class="card"><div class="amount">${T('done.points', { points: e.points })}</div>
             <div class="meta">${T('done.meta', { card: escapeHtml(e.card_no), amount: e.amount, visits: e.visits })}</div></div>`).join('')
      // 本次达标发了新券就大字提示 —— 服务员要当场告诉客人
      + (d.rewards || []).map(r => r.granted > 0
        ? `<div class="card reward-card">
             <div class="amount">${T('done.granted', { n: r.granted })}</div>
             <div class="meta">${T('done.grantedMeta', { card: escapeHtml(r.card_no) })}</div>
             <div class="meta">${T('done.grantedCodes', { codes: r.coupons.map(c => escapeHtml(c.code)).join('、') })}</div>
           </div>`
        : `<div class="card reward-card">
             <div class="amount">${T('done.pending', { n: r.pending })}</div>
             <div class="meta">${T('done.pendingMeta', { card: escapeHtml(r.card_no) })}</div>
           </div>`).join('');
    step('step-done');
  } catch (e) {
    showErr('#assign-err', e.message + (e.detail && e.detail.total ? T('assign.overflow', { total: e.detail.total, allocated: e.detail.allocated }) : ''));
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
  inp.placeholder = { card: T('member.phCard'), phone: T('member.phPhone'), email: T('member.phEmail') }[t];
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
  if (!value) return showErr('#member-err', T('member.needInput'));

  // 卡号走 /card/lookup 而不是 /member/search：实体卡有四种状态，
  // 「查无此人」这一种答案不够用 —— 库存卡要引导去建会员，
  // 作废卡要说清楚换一张，不是本店的卡要当场拒绝。
  if (type === 'card') { return doCardLookup(value); }

  try {
    const d = await api('/member/search', { type, value });
    if (!d.found) {
      $('#member-result').innerHTML = `<p class="muted">${T('member.none')}</p>`;
      $('#member-new').open = true;
      return;
    }
    const m = d.member;
    $('#member-result').innerHTML = `
      <div class="found"><b>${escapeHtml(m.card_no)}</b>
        <div class="muted small">${T('member.stats', { points: m.points_balance, visits: m.visit_count, spent: m.total_spent })}</div>
        ${m.points_frozen ? `<div class="frozen">${T('member.frozen')}</div>` : ''}
        <button class="primary" id="btn-use-member" style="margin-top:10px">${T('member.use')}</button></div>`;
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
      const soon = d.days_left !== null && d.days_left <= expiringSoonDays();
      $('#member-result').innerHTML = `
        <div class="found"><b>${escapeHtml(m.card_no)}</b>
          <div class="muted small">${T('member.statsShort', { points: m.points_balance, visits: m.visit_count })}${
            d.valid_to ? ' · ' + T('card.validTo', { date: escapeHtml(d.valid_to) }) : ''}</div>
          ${soon ? `<div class="frozen">${T('card.soonInline', { days: d.days_left })}</div>` : ''}
          ${m.points_frozen ? `<div class="frozen">${T('member.frozen')}</div>` : ''}
          <button class="primary" id="btn-use-member" style="margin-top:10px">${T('member.use')}</button></div>`;
      $('#btn-use-member').onclick = () => useMember(m);

      /**
       * 快到期：每次扫到都问一遍，直到换了卡或者卡真的过期。
       *
       * 故意做成「每次都问」而不是「提醒一次就记下不再问」——
       * 当时忙不过来、新卡还没到、客人不想换，都是常态，
       * 而这些理由下次就不成立了。系统不替他们记「已读」，
       * 因为一旦记了，最后一次提醒之后就再没人提，卡直接过期。
       *
       * 但它只是提醒：点「稍后再说」照常用卡，什么都不耽误。
       */
      if (soon) { offerRenewSoon(d, m); }
      return;
    }

    /**
     * 库存卡 —— 这张卡是新的，引导建会员。
     *
     * 但先看它还能用多久：快到期的卡发出去，客人拿回家没多久就得再跑一趟。
     * 阈值 30 天，超过就直接放行不打扰。
     */
    if (d.days_left !== null && d.days_left <= expiringSoonDays()) {
      const go = await UI.confirm(
        T('card.issueSoonAsk', { days: d.days_left, date: d.valid_to }),
        { okText: T('card.issueAnyway'), cancelText: T('card.takeAnother'), danger: true }
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
      T('card.notActive', { card: escapeHtml(d.card_no) })
      + (d.valid_to ? `　<span class="muted">${T('card.validTo', { date: escapeHtml(d.valid_to) })}</span>` : '');
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
    return showErr('#member-err', T('member.needCard'));
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
    toast(T(d.consent_pending ? 'member.boundPending' : 'member.bound'), 'ok');
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

/**
 * 剩多少天算「快到期」—— 后台可调（配置 → 实体卡有效期）。
 * 发卡与用卡两处都按它提醒。登录/恢复会话时随 settings 一起下发。
 */
function expiringSoonDays() {
  const v = S.settings && S.settings.expiring_soon_days;
  return typeof v === 'number' && v >= 0 ? v : 30;
}

/** 过期后还能换卡的宽限期（月），后台可调。只用于把话说清楚，判定在服务端 */
function graceMonths() {
  const v = S.settings && S.settings.grace_months;
  return typeof v === 'number' && v >= 0 ? v : 6;
}

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
      T('card.expiredStock', { date: d.valid_to }));
    return;
  }

  const who = T('card.expiredWho', { card: d.card_no, points: d.member.points_balance, visits: d.member.visit_count });
  const go = await UI.confirm(
    T('card.expiredAsk', { date: d.valid_to, who }),
    { okText: T('card.replaceOk'), cancelText: T('card.replaceLater') }
  );
  if (!go) {
    showErr('#member-err', T('card.expiredHint', { date: d.valid_to }));
    return;
  }

  await runReplaceLoop(d.member.id, T('card.reasonExpired', { card: d.card_no, date: d.valid_to }),
    () => showErr('#member-err', T('card.expiredHint', { date: d.valid_to })));
}

/**
 * 快到期的卡：提醒收银员现在就能换。
 *
 * 与过期卡的区别是「不换也没关系」—— 所以默认按钮是「稍后再说」，
 * 不要做成必须处理掉才能继续，那会拖慢收银台。
 */
async function offerRenewSoon(d, m) {
  const go = await UI.confirm(
    T('card.renewSoonAsk', { days: d.days_left, date: d.valid_to,
                             points: m.points_balance, visits: m.visit_count }),
    { okText: T('card.renewNow'), cancelText: T('common.later') }
  );
  if (!go) return;

  await runReplaceLoop(m.id, T('card.reasonEarly', { card: d.card_no, date: d.valid_to }), null);
}

/**
 * 换卡的输入循环 —— 过期换卡与提前换卡共用。
 *
 * 扫错一张不该让人从头再来，所以出错时输入框留着继续扫；
 * 只有「不是卡的问题」才退出。
 *
 * @param onGiveUp 取消时的收尾（过期卡要留一句话，提前换卡则什么都不用做）
 */
async function runReplaceLoop(memberId, reason, onGiveUp) {
  for (;;) {
    const raw = await UI.input(
      T('card.askNewNo'),
      { okText: T('card.replaceGo'), cancelText: T('common.cancel') }
    );
    if (raw === null) {
      if (onGiveUp) { onGiveUp(); }
      return;
    }
    try {
      const r = await api('/card/replace', { member_id: memberId, card_no: raw, reason });
      toast(T('card.replaced', { card: r.card_no }), 'ok');
      resetLookupState();
      $('#member-input').value = '';
      // 换完直接选用这位会员，收银员不用再查一遍
      if (r.member) { useMember(r.member); }
      return;
    } catch (e) {
      // 超过宽限期 —— 不是卡的问题，是这一步需要经理
      if (e.error === 'grace_over') {
        if (await forceReplace(memberId, reason, raw, e)) { return; }
        return;
      }
      toast(e.message, 'err');
      // 新卡本身有问题（已被占用、也过期了…）时让他再扫一张
      if (!['card_unknown', 'card_malformed', 'card_expired', 'card_not_available'].includes(e.error)) {
        return;
      }
    }
  }
}

/**
 * 超过宽限期的强制换发 —— 经理专属，必须填原因。
 *
 * 为什么不干脆一刀拒绝：柜台当面回绝客人正是投诉的来源。
 * 留一个带原因、带留痕的口子，规则守住了（收银员破不了例），
 * 店里也能按具体情况处理。与「经理强制核销」是同一套做法。
 *
 * @return 是否已经处理完（成功换发或经理主动放弃）
 */
async function forceReplace(memberId, reason, newCardNo, err) {
  // api() 把服务端的 detail 原样挂在 err.detail 上（不是 data）
  const months    = (err.detail && err.detail.grace_months) || graceMonths();
  const expiredOn = (err.detail && err.detail.old_valid_to) || '';
  const head = T('card.graceHead', { months })
             + (expiredOn ? T('card.graceOn', { date: expiredOn }) : '');

  if (!S.operator || !S.operator.is_manager) {
    // 收银员没这个权限。把话说清楚：不是系统坏了，是要找经理
    showErr('#member-err', T('card.graceClerk', { head }));
    return true;
  }

  if (!await UI.confirm(
    T('card.graceAsk', { head }),
    { okText: T('card.graceForce'), cancelText: T('card.graceRefuse'), danger: true }
  )) {
    showErr('#member-err', T('card.graceRefused', { head }));
    return true;
  }

  const why = await UI.input(T('card.graceWhy'), {
    value: T('card.graceWhyDef'),
    okText: T('card.graceConfirm'), danger: true,
  });
  if (why === null) return true;

  try {
    const r = await api('/card/replace', {
      member_id: memberId, card_no: newCardNo, reason, force_reason: why,
    });
    toast(T('card.graceDone', { card: r.card_no }), 'ok');
    resetLookupState();
    $('#member-input').value = '';
    if (r.member) { useMember(r.member); }
  } catch (e) { toast(e.message, 'err'); }
  return true;
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
    toast(T('consent.notSent'), 'err');
    return;
  }

  const where = T(sent.channel === 'sms' ? 'consent.viaSms' : 'consent.viaEmail');
  let tip = T('consent.sent', { where });

  for (;;) {
    const code = await UI.input(tip, {
      numeric: true, okText: T('common.confirm'), cancelText: T('common.later'),
    });
    if (code === null) {
      toast(T('consent.notDone'), 'err');
      return;
    }

    try {
      await api('/consent/verify', { member_id: member.id, code });
      member.consent_status = 1;
      member.points_frozen  = false;
      toast(T('consent.done'), 'ok');
      return;
    } catch (e) {
      // 码错了还能再试；过期或锁死就只能重发
      if (e.error === 'code_wrong') {
        const left = e.detail && e.detail.left;
        tip = T('consent.wrong', { left: left ? T('consent.left', { n: left }) : '' });
        continue;
      }
      if (e.error === 'code_expired' || e.error === 'code_locked') {
        if (!await UI.confirm(T('consent.resendAsk', { message: e.message }), { okText: T('consent.resend') })) {
          toast(T('consent.notDone'), 'err');
          return;
        }
        try {
          const r = await api('/consent/send', { member_id: member.id });
          const w = T(r.channel === 'sms' ? 'consent.viaSms' : 'consent.viaEmail');
          tip = T('consent.resent', { where: w });
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
      T('scan.unsupported'));
  }
  if (!window.SushiVIP || !SushiVIP.cameraSupported()) {
    return showErr('#member-err',
      window.isSecureContext === false
        ? T('scan.needHttps')
      : T('scan.noCamera'));
  }

  $('#scan-modal').hidden = false;
  UI.back.sync();
  $('#scan-msg').textContent = T('scan.opening');

  try {
    scanStream = await SushiVIP.openCamera();
    const v = $('#scan-video');
    v.srcObject = scanStream;
    await v.play();
    $('#scan-msg').textContent = T('scan.aim');
  } catch (e) {
    stopScan();
    return showErr('#member-err', T('scan.failed', { err: (e && e.message ? e.message : e) }));
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
      <div class="muted small">${m.points_balance} ${T('common.points')}</div></div>`;
  } else if (S.people.some((p, i) => p.member && p.member.id === m.id && i !== S.memberTarget)) {
    return showErr('#member-err', T('member.duplicate'));
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
  $('#manual-member').innerHTML = `<button class="link" id="btn-manual-pick">${T('assign.pickMember')}</button>`;
  $('#btn-manual-pick').onclick = () => openMemberModal('manual');
  $('#manual-amount').value = '';
  showErr('#manual-err', '');
  step('step-manual');
}

$('#btn-manual-submit').onclick = async () => {
  showErr('#manual-err', '');
  if (!S.manualMember) return showErr('#manual-err', T('manual.needMember'));
  const amt = $('#manual-amount').value.trim();
  if (cents(amt) <= 0) return showErr('#manual-err', T('manual.needAmount'));
  try {
    const d = await api('/points/manual', {
      member_id: S.manualMember.id, amount: amt, reason_code: $('#manual-reason').value,
    });
    toast(T('manual.done', { points: d.points }), 'ok');
    resetFlow();
  } catch (e) { showErr('#manual-err', e.message); }
};

/* ── 杂项 ────────────────────────────────────────── */
function escapeHtml(s) {
  return String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

/**
 * 启动。
 *
 * 语言要在【第一次请求之前】定好：api() 每次都带 X-Lang，
 * 定晚了第一条错误提示就会是另一种语言。
 * 这时还不知道是谁在登录，所以用这台平板上次用的那种；
 * 登录成功后 enterMain() 会按账号覆盖掉。
 */
I18N.set(I18N.initial('zh'), { remember: false });
renderLangSwitch();

(async () => {
  try {
    const d = await api('/auth/me', undefined, 'GET');
    enterMain(d.operator, d.settings);
  } catch {
    try {
      const h = await api('/health', undefined, 'GET');
      if (!h.local_db) $('#health-note').textContent = T('health.dbDown');
      // 这台平板还没人切过语言时，跟后台配的默认走
      if (h.default_lang && !I18N.remembered()) {
        I18N.set(h.default_lang, { remember: false });
        renderLangSwitch();
      }
    } catch {
      $('#health-note').textContent = T('health.noService');
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
