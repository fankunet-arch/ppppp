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
  merge: null,       // 多桌合并：{ orders: [...], member: {...} }
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
  // 合并页按返回回到记账方式：合并是从那一步进来的
  'step-merge':  'step-mode',
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

/**
 * 会话令牌的本地备份。
 *
 * ★ 这一段是补一次现场事故的：**平板熄屏一会儿再打开就要求重新登录。**
 *
 *   查下来不是有效期问题（Cookie 与服务端会话都是 12 小时），
 *   是 Android WebView 的老毛病：**Cookie 默认只存在内存里，
 *   要 CookieManager.flush() 才落盘**。熄屏后系统把 WebView 进程回收，
 *   没 flush 的 Cookie 就跟着没了。
 *
 *   正经的修法在容器侧（onPause() 里调 flush()），但那要每台平板都
 *   装到新版容器才生效，而 apk/ 已经不在本仓库里。所以 Web 这边自己兜底：
 *   登录时把令牌存进 localStorage，之后每个请求带一个头。
 *   localStorage 的落盘时机和 Cookie 不同，进程被杀也还在。
 *
 * ★ Cookie 仍然是第一优先，这里只是后备（服务端 readToken 先看 Cookie）。
 *
 * ★ 每一处读写都包在 try/catch 里：容器可能禁掉 localStorage，
 *   隐私模式下直接 throw。取不到就退回只靠 Cookie —— 那是原来的行为，
 *   不会更糟，但绝不能因为存不了令牌就整个登录不了。
 */
const Session = {
  KEY: 'vip_session_token',
  token() {
    try { return localStorage.getItem(this.KEY) || null; } catch { return null; }
  },
  save(t) {
    if (!t) return;
    try { localStorage.setItem(this.KEY, t); } catch {}
  },
  clear() {
    try { localStorage.removeItem(this.KEY); } catch {}
  },
};

async function api(path, body, method = 'POST') {
  const opt = {
    method,
    // ★ X-Lang 每次都带：服务端据此决定用哪种语言回错误文案。
    //   不带的话会出现「界面西语、报错中文」——前端不翻服务端的错误，
    //   那套文案只在 Api::MESSAGES 里有一份（见 i18n.js 顶部说明）。
    headers: { 'Content-Type': 'application/json', 'X-Lang': I18N.lang },
    credentials: 'same-origin',
  };
  // Cookie 丢了之后的后备通道 —— 说明见 Session 那一节
  const tk = Session.token();
  if (tk) { opt.headers['X-Session-Token'] = tk; }
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
    /**
     * 令牌被服务端否掉了（过期、被踢、库重建过）——
     * 本地那份就没有意义了，留着只会每次请求都白带一遍，
     * 而且下一个人开机时会先被拒一次才回到登录页，看着像出错。
     */
    if (json.error === 'unauthorized') { Session.clear(); }
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
    Session.save(d.session_token);     // Cookie 丢了还能靠它回来
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
  /**
   * ★ 本地那份也要删干净。
   *   只让服务端作废、本地留着的话，下一个人开机会带着一个已作废的令牌
   *   去请求，被拒之后才回到登录页 —— 中间那一下白等，而且看着像出错了。
   *   而万一服务端那次作废没发出去（断网），本地还留着就等于没退出。
   */
  Session.clear();
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
  // 这一句是 JS 填的，切语言时得跟着换（data-i18n 管不到它）
  applyPiiTabs();
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
  applyPiiTabs();
  /**
   * 多桌合并把几桌的【积分】并给一位客人 —— 次数不并（每张卡本餐期仍只 1 次）。
   * 但它长得像「整单记一人」，而整单已经从界面上拿掉了。
   * 两个入口一去一留会让人困惑：「为什么一桌不能并、三桌反而能并？」
   * 所以只给经理看。普通服务员的路径就是 AA / 点选两种，干净。
   */
  const mb = $('#btn-merge-start');
  if (mb) { mb.hidden = !(S.operator && S.operator.is_manager); }

  /**
   * ★ 卡片功能整体停用时，顶栏挂一条常驻红条。
   *
   *   card_prefix 配错时，查卡/建卡/激活/换卡都做不了（服务端 cards_ok=false）。
   *   记账不受影响 —— 那是刻意的降级，不能让收银台整个停摆。
   *   但【必须说出来】：不说的话现场看到的是「扫卡没反应」，
   *   而那和「卡坏了」「扫码头脏了」长得一模一样，能查半天。
   */
  const cw = $('#cards-warn');
  if (cw) {
    const bad = S.settings.cards_ok === false;
    cw.hidden = !bad;
    if (bad) {
      cw.textContent = T('warn.cardsOff', { why: S.settings.cards_error || '' });
    }
  }

  const box = $('#new-contact');
  if (!box) return;
  if (S.settings.collect_pii) return;      // 开启时保持原样
  box.remove();
}

/**
 * 关闭时把「手机号 / 邮箱」两档【禁用】，只留「卡号」。
 *
 * 为什么这里是禁用而不是像上面那样整块删掉：
 *   · 删掉的是【采集】入口 —— 它一存在就等于在邀请收银员向客人要信息，
 *     所以必须让它在界面上根本不存在。
 *   · 这里是【查找】入口。开关关掉之前建的会员，联系方式还在库里，
 *     留着这两档灰着，至少能让人看懂「不是坏了，是本店不这么做」。
 *     真要按手机号找，先去后台把开关打开。
 *
 * 禁用的按钮点不动，所以 doMemberSearch 拿不到这两档；那边还有一道
 * 兜底（见 currentSearchType），防的是「开关切换时弹层正开着」这种缝。
 */
function applyPiiTabs() {
  const on = !!S.settings.collect_pii;
  $$('#search-type button').forEach(b => {
    if (b.dataset.type === 'card') return;
    b.disabled = !on;
    // 关掉时如果正停在这一档上，拨回卡号 —— 否则会停在一个点不动的档位上
    if (!on && b.classList.contains('on')) {
      b.classList.remove('on');
      const card = $('#search-type button[data-type=card]');
      if (card) { card.classList.add('on'); card.click(); }
    }
  });
  const note = $('#pii-off-note');
  if (note) {
    note.textContent = T('member.piiOff');
    note.hidden = on;
  }
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
  const busy = S.merge !== null
            || CURRENT_STEP !== 'step-table'
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
  // ★ 默认模式是 2（均摊 AA）而不是 1（整单）—— 整单已从界面移除，
  //   留成 1 的话，一进分配页就是个界面上根本没有的模式（docs/03 §13）
  S.order = null; S.people = []; S.picks = {}; S.mode = 2;
  S.merge = null;
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
      /**
       * ★ 普通收银员看到的永远是【同一句话】，不管是查不到还是过了时效。
       *
       *   分开说的话，「这张小票是 8 月 12 号的、超过 7 天」这句本身
       *   就等于告诉对方：这个号是真的。小票号是连号的整数，
       *   一个一个往前试，就能把号段和哪天有生意都摸出来。
       *
       *   服务端已经把 reason 按角色砍过了（见 api/routes.php），
       *   这里只是照着说 —— 前端不做判断，也就没有「前端漏改一处」这回事。
       *
       *   ★ 结账日期【谁都拿不到】，经理也一样：他要分的只是
       *     「没这张单」还是「有单但太旧了」，具体是哪天不需要。
       *     经理账号一旦外泄，泄露面不该比收银员大。
       */
      if (d.reason === 'too_old') {
        // ★ 不带日期 —— 服务端也不再发了（见 api/routes.php 与 locateByInvoice）
        showErr('#locate-err', T('lookup.tooOldMgr', { days: d.max_days }));
      } else if (d.reason === 'not_found') {
        showErr('#locate-err', T('lookup.invoiceNoneMgr', { no: d.invoice_no }));
      } else {
        showErr('#locate-err', T('lookup.invoiceUnavailable'));
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
    // 能点的给主色，不能点的保持灰 —— 一屏里哪张该点，不用读字就看得出
    b.className = 'card ' + (o.eligible ? 'pickable' : 'disabled');
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
  /**
   * 正在合并的话，选中的这一单是「再加一桌」，不是「开始新的一单」——
   * 直接进队列，回到合并页，不要跳去记账方式（那一步在合并里不存在）。
   */
  if (S.merge) {
    if (S.merge.orders.some(x => x.serial_id === o.serial_id)) {
      /**
       * 重复的那一桌不加，但【要回到合并页】。
       * 只弹个提示就把人留在选订单页，收银员会以为点击没生效，
       * 于是再点一次、再点一次 —— 而列表就在另一页上，他看不见。
       */
      toast(T('merge.dup'), 'err');
      return step('step-merge');
    }
    S.merge.orders.push(o);
    renderMerge();
    return step('step-merge');
  }
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
      `<div class="lrow"><span>${l.card_no ? escapeHtml(maskCard(l.card_no)) : T('common.member')} · € ${escapeHtml(l.amount)} · ${l.points} ${T('common.points')}</span>
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
  // ★ 分过一部分之后，「还剩几份」比「共几份」有用得多 ——
  //   服务员要填的就是这个数。没分过时不写，那时它等于总份数，纯噪音
  if (o.allocated_portions) {
    bits.push(T('assign.portionsDone', { n: o.allocated_portions }));
    bits.push(T('assign.portionsLeft', { n: o.remaining_portions }));
  }

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

/**
 * 「这张单一份付费套餐都没有」的告知。
 *
 * ★ 用页内层（UI.notice）而不是系统 alert：容器里的原生 alert 要么被吞掉，
 *   要么长得像浏览器警告 —— 屏幕朝着客人，那一下很难解释。
 *
 * ★ 同一张单只弹一次。收银员在这一屏和上一屏之间来回是常事，
 *   每回都弹的话，他会养成「闭着眼点确定」的习惯，
 *   那这个提示就等于不存在了。
 */
let noMenuTold = '';
function tellNoMenu(o) {
  if ((Number(o.portions_counted) || 0) > 0) { return; }
  if (noMenuTold === o.serial_id) { return; }
  noMenuTold = o.serial_id;
  try {
    UI.notice(T('assign.noMenuBody'), { highlight: T('assign.noMenuHi'), okText: T('common.ok') });
  } catch (e) { /* 弹层挂了也不能挡住记账 */ }
}

/* ── 步骤 4：分配 ────────────────────────────────── */
function startAssign() {
  const o = S.order;
  $('#assign-title').textContent = { 1: T('assign.mode1'), 2: T('assign.mode2'), 3: T('assign.mode3') }[S.mode];
  $('#sum-total').textContent = money(o.remaining_cents);
  $('#sum-port-total').textContent = o.remaining_portions;
  renderPortionBreakdown(o);
  tellNoMenu(o);
  showErr('#assign-err', '');
  const body = $('#assign-body');
  body.innerHTML = '';

  if (S.mode === 1) {
    S.people = [{ member: null, amountCents: o.remaining_cents, portions: o.remaining_portions }];
    renderPeople();
  } else if (S.mode === 2) {
    /**
     * ★ 默认人数要【扣掉已经记掉的那几位】。
     *
     *   买单 2 人、已经记给 1 张卡，默认还填 2 的话，
     *   服务员一按「按人数分摊」就得到两行，其中一行注定用不上 ——
     *   剩下的钱和份数还被摊成了两半，两边都不对。
     *   扣完至少留 1，不然按钮按下去什么都没有。
     */
    const taken  = alreadyOnOrder().length;
    // ★ 人数上限也是份数 —— 见 memberCap()。原来写死 50，
    //   等于「随便填，提交时再说」
    const aaMax  = Math.max(1, memberCap() - taken);
    const aaInit = Math.min(aaMax, Math.max(1, (o.customer_num || 2) - taken));
    body.innerHTML = `<label>${T('assign.aaCount')}
      <input id="aa-people" type="number" inputmode="numeric" min="1" max="${aaMax}" value="${aaInit}"></label>
      <button id="btn-aa" class="primary">${T('assign.aaSplit')}</button>`;
    $('#aa-people').oninput = (e) => {
      const raw = parseInt(e.target.value, 10) || 0;
      if (raw > aaMax) {
        e.target.value = String(aaMax);
        toast(T('assign.cappedPeople', { n: aaMax }), 'err');
      }
    };
    $('#btn-aa').onclick = doSplit;
    /**
     * ★ 还没按「按人数分摊」之前就要把【已记掉的那几行】画出来。
     *
     *   原来这里是 innerHTML = ''，名单一片空白，服务员得先分摊一次
     *   才看得到「哦，已经有一位记过了」—— 而那时候人数已经填错了。
     */
    S.people = [];
    renderPeople();
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
  /**
   * ★ 每人最多 1 份 —— 与均摊 AA 那一屏保持同一个口径。
   *
   *   once_per_period 下，一个人点了 2 份套餐照样只记 1 次。
   *   不封的话，同一位客人在「点选菜品」里显示 2 份、在 AA 里显示 1 份，
   *   收银员会以为两边算法不一样。
   */
  const fixed = (S.order || {}).portions_per_person;
  if (fixed !== null && fixed !== undefined) {
    const cap = Number(fixed) || 0;
    S.people.forEach(p => { if (p.portions > cap) p.portions = cap; });
  }
  renderPeople(true);
}

function addPerson() {
  /**
   * ★ 新加的一位要不要预置份数，【看模式】。
   *
   *   均摊 AA：要。份数框在 once_per_period 下是锁死的，
   *     不预置的话加进来的人永远拿不到次数，而且他改不了 ——
   *     界面上看不出为什么别人有次数他没有。
   *
   *   点选菜品：★ 不能预置。那个模式下一个人的金额来自他认领的菜，
   *     刚加进来必然是 0 元；再预置 1 份就成了「0 元 + 1 份」——
   *     正是服务端硬拒的 portions_without_amount，而且按
   *     validateAllocations 的规则是【整笔拒绝】：只要服务员先把人加好、
   *     还没给他点菜就提交，连已经点好菜的那几位也一起记不上。
   *     份数框在这个模式下也是只读的，收银员改不掉。
   *
   *     实测（真浏览器）：点选菜品模式下「添加会员」是收银员的
   *     【第一个动作】（S.people 一开始是空的，不加人就没法认领），
   *     所以这不是边角情况。
   *
   *     这个模式下份数本来就由 recomputePicks() 从认领的菜算出来，
   *     给 0 是对的。
   */
  const fixed = (S.order || {}).portions_per_person;
  const preset = (S.mode === 3 || fixed === null || fixed === undefined)
    ? 0
    : Number(fixed) || 0;
  S.people.push({ member: null, amountCents: 0, portions: preset });
  renderPeople();
  if (S.mode === 3) refreshPickSelects();
}

/**
 * 把「这张单已经记给谁了」摆在分配页最上面。
 *
 * 之前这个信息只在上一屏（记账方式）有，到了这一屏就看不见了 ——
 * 于是现场出现「屏幕显示 +27 分，同时又说本餐期已记过 1 次」，
 * 收银员完全没法判断这是正常的下半单，还是自己点重了。
 */
function renderAlreadyOnOrder() {
  const box = $('#assign-done');
  if (!box) return;
  const rows = alreadyOnOrder();
  if (!rows.length) { box.hidden = true; box.innerHTML = ''; return; }
  const o = S.order || {};
  /**
   * ★ 每一行都要把【份数】写出来，不能只写金额。
   *
   *   金额说明不了问题：客人问的是「我这顿算上了吗」，
   *   而「算上」在这套规则里等于【拿到了一份计次套餐】。
   *   只写 € 27.88 的话，服务员还得回上一步、点开流水才答得出来 ——
   *   客人就站在柜台前，那几下点击就是投诉的来源。
   *
   *   顺带写上「已计 N 次」：同一餐期第二单是【记积分不计次】的，
   *   这时候份数有、次数是 0，不写出来没人解释得清。
   */
  const lines = rows.map(r => {
    const bits = [`€ ${escapeHtml(r.amount)}`];
    if (r.portions > 0) { bits.push(T('assign.donePortions', { n: r.portions })); }
    bits.push(r.visits > 0 ? T('assign.doneVisits', { n: r.visits }) : T('assign.doneNoVisit'));
    return `<div class="lrow"><span><b>${escapeHtml(maskCard(r.card))}</b> · ${bits.join(' · ')}</span></div>`;
  }).join('');
  // 「还剩多少可分」放在这里而不是只放在合计栏 —— 服务员的视线是从上往下的，
  // 等他看到最底下的合计时，金额和份数已经填完了
  const left = `<div class="lrow left"><span>${T('assign.doneLeft', {
    money: money(o.remaining_cents || 0), n: Number(o.remaining_portions) || 0,
  })}</span></div>`;
  box.innerHTML = `<b>${T('assign.doneTitle')}</b>` + lines + left
    + `<div class="muted small">${T('assign.doneNote')}</div>`;
  box.hidden = false;
}

/**
 * 卡号打码：TK-00000123-4Q7 → TK-00000123-•••
 *
 * 藏掉的是末尾那 3 位随机码 —— 它正是防猜卡号的那一段。
 * 留下的顺序号足够收银员认出「哦，是刚才那张」，
 * 而屏幕被人瞄一眼也拼不出一个能用的完整卡号。
 */
function maskCard(no) {
  const s = String(no || '');
  return s.length <= 3 ? s : s.slice(0, -3) + '•••';
}

/**
 * 这张订单已经记给过哪些卡。
 *
 * ★ 同一张卡不能在同一张单上记两次 —— 服务端会拒（member_already_on_order）。
 *   但不能等到点了「提交积分」才报错：那时收银员已经填完金额、选完人，
 *   还要退回来重做。所以在【选会员】这一步就挡住。
 */
function alreadyOnOrder() {
  const rows = (S.order && S.order.existing_ledger) || [];
  /**
   * ★ 只认【消费流水】（entry_type = 1）。
   *
   *   这里第一版写成「counted_visit >= 0」，结果把【撤销流水】也算进来了 ——
   *   撤销那一笔 entry_type = 2、status 仍然是有效，counted_visit 是负数或 0，
   *   于是「0」那条溜了进去，把一张已经撤销干净的卡也锁死了。
   *   浏览器测试当场撞出来的：撤销整组之后再记同一张卡，会员弹层打不开。
   *
   *   判定口径必须和服务端那条守卫一致（见 PointsService::grantOne）。
   */
  return rows.filter(l => Number(l.entry_type) === 1 && l.member_id)
             .map(l => ({
               id:       Number(l.member_id),
               card:     l.card_no || '',
               amount:   l.amount,
               portions: Number(l.portions_counted) || 0,
               visits:   Number(l.counted_visit) || 0,
             }));
}

/**
 * 这张单最多能记几位会员。
 *
 *   有计次套餐 → 【份数】就是上限（一份餐对一位客人）
 *   一份都没有 → 只允许 1 位
 *
 * ★ 为什么要有上限：不封的话，一张 € 200 的单可以拆给十张卡，
 *   每张都拿一份积分 —— 而这十个人里只有几个真的在这儿吃过饭。
 *   份数是这张单上「有几个人吃了饭」唯一可信的凭据，
 *   所以人数就以它为准。
 *
 * ★ 0 份的单为什么还留 1 位：纯酒水单是正常生意，钱是真花的，
 *   该给积分。只是它证明不了「几个人吃了饭」，所以不给拆。
 */
function memberCap() {
  const o = S.order || {};
  return Math.max(1, Number(o.portions_counted) || 0);
}

/** 这一屏还能再选几位（扣掉已经记掉的那几位） */
function seatsLeft() {
  return Math.max(0, memberCap() - alreadyOnOrder().length);
}

function renderPeople(keepItems) {
  renderAlreadyOnOrder();
  const box = $('#assign-people');
  box.innerHTML = '';

  /**
   * ★ 已经记掉的那几份，就摆在名单最上面，长得像一行但【不能点】。
   *
   *   之前这些信息只在上面的黄框里。黄框回答的是「发生过什么」，
   *   而服务员在这一屏要做的判断是「这一行我还能不能选人」——
   *   两件事不一样。名单里全是清一色的「+ 选择会员」时，
   *   他只能靠自己数：一共 2 份、已分配 1 份、所以只剩 1 个位子……
   *   忙起来就会两行都选上人，然后撞服务端的 member_already_on_order
   *   或 exceeds_portions，白填一遍。
   *
   *   现在直接把「这一位已经是 TK…•••」画出来：能选的和不能选的
   *   一眼分得开，不用点开任何东西，也不用自己算。
   */
  alreadyOnOrder().forEach(r => {
    const d = document.createElement('div');
    d.className = 'person locked';
    /**
     * ★ 与可编辑行用【同一个骨架】：卡号在左，金额与份数是两个灰掉的框。
     *
     *   原来这一行是「卡号 + 一串 <b> 包着的小字 + 一个圆角标签」，
     *   在 .who 那一列里会折成好几行，看着像坏掉了，
     *   而且和下面几行对不上列 —— 服务员要横着扫一眼才能比出差别。
     *
     *   现在两种行长得一模一样，唯一的差别就是【框是灰的、点不动】，
     *   那正是要传达的信息：这个位子有人了。
     */
    d.innerHTML = `
      <div class="who"><b>${escapeHtml(maskCard(r.card))}</b></div>
      <label class="amt">${T('assign.amount')}
        <input type="text" value="${escapeHtml(r.amount)}" disabled></label>
      <label class="prt">${T('assign.portions')}
        <input type="text" value="${r.portions}" disabled></label>`;
    box.appendChild(d);
  });

  // 单行上限 = 这张单还剩的份数。跨行合计超了由合计栏那条红线管 ——
  // 逐行去减「别人已占的」会让「从这行挪一份到那行」必须先减后加，
  // 柜台上多一步就是多一次出错
  const maxPort = Number((S.order || {}).remaining_portions) || 0;
  /**
   * ★ 每人固定几份 —— 由服务端的计次口径决定（见 buildContext 的
   *   portions_per_person）。once_per_period 下是 1，且【输入框锁死】。
   *
   *   在那个口径下「份数」已经不是「吃了几份」，而是「这个人有没有吃
   *   计次套餐」这个是非题：填 3 和填 1 最后都只记 1 次。
   *   既然多填没有任何用处，那个框就只剩下填错的可能 ——
   *   现场截图里就是一张只剩 1 份的单，框里填进了 4。
   */
  const fixedPort = (S.order || {}).portions_per_person;
  const lockPort  = fixedPort !== null && fixedPort !== undefined;
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
      <label class="prt">${T('assign.portions')}<input type="number" inputmode="numeric" min="0" max="${maxPort}" data-prt="${i}" value="${p.portions}"${(lockPort || S.mode === 3) ? ' readonly' : ''}></label>
      ${S.people.length > 1 ? `<button class="rm" data-rm="${i}">${T('assign.remove')}</button>` : ''}`;
    box.appendChild(d);
  });

  if (S.mode !== 1 && !keepItems) {
    // ★ 到上限就不再给「添加会员」——「按钮在那儿但一点就报错」
    //   等于让人先做完再挨骂，不如干脆不给
    const add = document.createElement('button');
    add.className = 'ghost';
    add.textContent = T('assign.addMember');
    add.onclick = addPerson;
    if (S.people.length >= seatsLeft()) {
      add.disabled = true;
      add.textContent = T('assign.addMemberFull', { n: memberCap() });
    }
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
  $$('[data-amt]', box).forEach(inp => {
    inp.oninput = () => {
      const p = S.people[parseInt(inp.dataset.amt, 10)];
      if (!p) { return; }                      // 同上：这一行可能已经被重建掉了
      p.amountCents = cents(inp.value);
      updateTotals();
    };
    /**
     * ★ 金额只在【失焦】时封顶，不在每次按键时封顶。
     *
     *   按键时封顶会把小数敲不出来：想输 13.93，敲到 "13." 的中间态
     *   在某些顺序下会被判超、被改写，人就再也对不准了。
     *   份数是整数没这个问题，所以那一侧才敢边打边封。
     */
    inp.onblur = () => {
      const i    = parseInt(inp.dataset.amt, 10);
      /**
       * ★ 这一行可能已经不在了。
       *
       *   blur 是在元素【被移走时】也会触发的：重新分摊、改人数、
       *   甚至换一张单，都会把整个名单 innerHTML = '' 重建一遍，
       *   而这个回调还挂在那个已经离开文档的 input 上。
       *   不判空的话就是一句 Cannot read properties of undefined —— 
       *   cachebust.mjs 的「零 JS 报错」那条当场就红了。
       */
      const p = S.people[i];
      if (!p) { return; }
      const cap = Number((S.order || {}).remaining_cents) || 0;
      if (p.amountCents > cap) {
        p.amountCents = cap;
        inp.value = money(cap);
        toast(T('assign.cappedAmount', { money: money(cap) }), 'err');
      }
      updateTotals();
    };
  });
  $$('[data-prt]', box).forEach(inp => inp.oninput = () => {
    const i   = parseInt(inp.dataset.prt, 10);
    if (!S.people[i]) { return; }              // 同上
    const raw = parseInt(inp.value, 10) || 0;
    /**
     * ★ 份数【封死】在订单剩余份数以内。
     *
     *   现场截图里就是这个：这张单只剩 1 份，输入框里却打进了 4。
     *   服务端当然会拒（exceeds_portions），但那要等到点提交才知道 ——
     *   客人已经站在那儿等了，服务员还得回头一行行找是哪里多了。
     *
     *   份数是整数、量又小，边打边封不会有小数那种中间态问题。
     */
    const val = Math.max(0, Math.min(raw, maxPort));
    S.people[i].portions = val;
    if (val !== raw) {
      inp.value = String(val);
      toast(T('assign.cappedPortions', { n: maxPort }), 'err');
    }
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
  noPortionHint();
}

/**
 * 「付了钱但没份数」的提醒。
 *
 * ★ 这是 portions_without_amount 的【反面】，两个方向都要管：
 *     有份没钱 → 白拿一次计次    → 硬拒（服务端）
 *     有钱没份 → 这一次白吃了    → 提醒（这里）
 *
 *   为什么这一面只提醒不拒绝：它常常是对的 —— 只点酒水没点套餐的客人
 *   本来就该 0 份，点选菜品模式下更是天天出现。
 *
 *   但更多时候是【份数填漏了】，而漏掉的次数事后【没有任何地方会报出来】：
 *   积分照样进卡、小票照样打，客人要等到攒够十次那天才发现少了一次，
 *   那时候已经没法查了。所以宁可在柜台前多说一句。
 *
 *   ★ 只在这张单【确实还有份数可分】时才提醒。整单 0 份（纯酒水单）
 *     全场都是 0 份，这时候提醒等于每单都弹，几天就没人看了。
 */
function noPortionHint() {
  const box = $('#assign-noportion');
  if (!box) { return; }
  const left = Number(S.order && S.order.remaining_portions) || 0;
  const rows = left > 0
    ? S.people.filter(p => p.amountCents > 0 && p.portions <= 0)
    : [];
  if (!rows.length) { box.hidden = true; box.textContent = ''; return; }
  box.hidden = false;
  box.textContent = T('assign.noPortionHint', { n: rows.length, left });
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

  // ★ 份数与金额绑定：0 元不能只记次数。
  //   服务端 portions_without_amount 才是真正的把关（前端拦不住直接调接口的人），
  //   这里先拦一道只是为了让收银员当场看到【是哪一位、该怎么改】——
  //   等提交回来再报错，他还得回头一行行找。
  const noAmount = S.people.find(p => p.member && p.portions > 0 && p.amountCents <= 0);
  if (noAmount) {
    return showErr('#assign-err',
      T('assign.portionsNoAmount', { card: maskCard(noAmount.member.card_no) }));
  }

  const allocations = S.people
    .filter(p => p.member && (p.amountCents > 0 || p.portions > 0))
    .map(p => ({ member_id: p.member.id, amount: money(p.amountCents), portions: p.portions }));
  if (!allocations.length) return showErr('#assign-err', T('assign.needOne'));

  const btn = $('#btn-submit');
  btn.disabled = true;
  try {
    const d = await submitWithGate('/points/grant',
      { serial_id: S.order.serial_id, mode: S.mode, allocations });
    if (d === null) return;                 // 经理放行那一步取消了
    renderDone(d);
    step('step-done');
  } catch (e) {
    showErr('#assign-err', e.message + (e.detail && e.detail.total ? T('assign.overflow', { total: e.detail.total, allocated: e.detail.allocated }) : ''));
  } finally {
    btn.disabled = false;
  }
};

/**
 * 记账结果。单桌与多桌合并共用 —— 两条路的返回结构本来就一样，
 * 各写一份的话，改一处忘一处，现场表现是「合并记账不显示发券提示」。
 */
function renderDone(d) {
  $('#done-body').innerHTML = (d.entries || []).map(e => `
    <div class="card"><div class="amount">${T('done.points', { points: e.points })}</div>
           <div class="meta">${T('done.meta', { card: escapeHtml(e.card_no), amount: e.amount, visits: e.visits })}</div>
           ${e.visits === 0 ? `<div class="meta warn-line">${T('done.noVisit')}</div>` : ''}</div>`).join('')
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
}

/* ── 多桌合并（同行分桌）────────────────────────────
 *
 * 场景：一大帮人坐了三桌，分桌计费、最后一起结账，
 * 然后自愿把三桌的积分都记到其中一位的卡上。docs/03 §12.2
 *
 * ★ 只有【整单】一种记法。合并之后再 AA 或点选菜品是没有意义的 ——
 *   会走到这条路上，本身就意味着「不用再分了，都算一个人的」。
 *   所以这一步没有记账方式可选，加桌、选卡、提交，三下完事。
 *
 * ★ 加桌走的是同一条找单流程（桌号 / 小票号），不另做一套 ——
 *   收银员已经会用那个了，再学一个只会出错。
 */
function mergeStart() {
  // 把当前这一单作为第一桌带进来 —— 收银员是在看着它的时候才想起「还有别桌」的
  S.merge = { orders: S.order ? [S.order] : [], member: null };
  renderMerge();
  step('step-merge');
}

function mergeRow(o) {
  return T('merge.row', {
    table: escapeHtml(o.table_name || o.serial_id),
    amount: money(o.remaining_cents),
    portions: o.remaining_portions,
  });
}

function renderMerge() {
  const m = S.merge || { orders: [], member: null };
  const box = $('#merge-list');
  box.innerHTML = m.orders.map((o, i) =>
    `<div class="lrow"><span>${mergeRow(o)}</span>
       <button class="link" data-mrm="${i}">${T('merge.remove')}</button></div>`).join('')
    || `<div class="empty">${T('merge.needTwo')}</div>`;
  $$('[data-mrm]', box).forEach(b => b.onclick = () => {
    S.merge.orders.splice(parseInt(b.dataset.mrm, 10), 1);
    renderMerge();
  });

  const sum = m.orders.reduce((a, o) => a + o.remaining_cents, 0);
  $('#merge-sum').textContent = money(sum);
  $('#merge-count').textContent = T('merge.count', { n: m.orders.length });

  $('#merge-member').innerHTML = m.member
    ? `<div class="lrow"><span><b>${escapeHtml(m.member.card_no || '')}</b> · ${
         T('member.statsShort', { points: m.member.points_balance, visits: m.member.visit_count })}</span></div>`
    : '';
  showErr('#merge-err', '');
}

$('#btn-merge-start').onclick = mergeStart;

$('#btn-merge-add').onclick = () => {
  /**
   * 回到第一步再找一单。不清 S.merge —— 那是整个功能的状态；
   * 但要清掉 S.order，否则回来时 selectOrder 会拿旧的那一单去比。
   */
  S.order = null;
  $('#table-input').value = '';
  $('#invoice-input').value = '';
  showErr('#locate-err', '');
  $('#locate-fallback').hidden = true;
  setLookupMode('table');
  step('step-table');
};

$('#btn-merge-pick').onclick = () => openMemberModal('merge');

$('#btn-merge-cancel').onclick = () => {
  S.merge = null;
  step(S.order ? 'step-mode' : 'step-table');
};

$('#btn-merge-submit').onclick = async () => {
  const m = S.merge || { orders: [], member: null };
  if (m.orders.length < 2) return showErr('#merge-err', T('merge.needTwo'));
  if (!m.member)           return showErr('#merge-err', T('merge.needMember'));

  const sum = m.orders.reduce((a, o) => a + o.remaining_cents, 0);
  if (!await UI.confirm(T('merge.confirm', {
        n: m.orders.length, amount: money(sum), card: m.member.card_no }))) return;

  const btn = $('#btn-merge-submit');
  btn.disabled = true;
  try {
    const body = { serial_ids: m.orders.map(o => o.serial_id), member_id: m.member.id };
    const d = await submitWithGate('/points/grant-merged', body);
    if (d === null) return;                 // 用户在经理放行那一步取消了
    renderDone(d);
    S.merge = null;
    step('step-done');
  } catch (e) {
    showErr('#merge-err', e.message + (e.detail && e.detail.hint ? '\n' + e.detail.hint : ''));
  } finally {
    btn.disabled = false;
  }
};

/**
 * 提交；撞了防刷闸门就地问经理要原因，再带着原因重试一次。
 *
 * ★ 为什么在前台就地处理，而不是让收银员「去找经理重做一遍」：
 *   客人就站在柜台前。让他等着、收银员跑去找经理、回来从头再走一遍
 *   找单流程 —— 这中间任何一步分神，这一单就丢了。
 *   经理走过来输一句原因，是现场唯一走得通的做法。
 *
 * ★ 只重试一次。第二次还被拦说明不是权限问题（比如经理账号本身没权限），
 *   再问一遍只是让人反复输原因。
 */
async function submitWithGate(path, body) {
  try {
    return await api(path, body);
  } catch (e) {
    if (e.error !== 'manager_required') throw e;

    const gates = (e.detail && e.detail.gates) || [];
    const why = gates.map(g =>
      g.gate === 'late_grant' ? T('gate.late', { min: g.minutes })
      : g.gate === 'period_cap' ? T('gate.cap', { used: g.used, limit: g.limit })
      : '').filter(Boolean).join('\n');

    const reason = await UI.input(why + '\n\n' + T('gate.askReason'), {
      placeholder: T('gate.reasonPh'), okText: T('gate.ok'), danger: true,
    });
    if (reason === null || !reason.trim()) return null;
    return await api(path, Object.assign({}, body, { override_reason: reason.trim() }));
  }
}

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

/**
 * 当前选的是哪一档。
 *
 * 兜底：关掉「允许收集客人联系方式」之后，手机号/邮箱两档是禁用的，
 * 正常点不到。但按钮的 disabled 与 .on 是两件事 —— 只要有一条路径
 * 让「已选中」和「已禁用」同时成立（比如开关切换时弹层正开着），
 * 这里就会按手机号去查。所以按开关判一次，不信 DOM 的 class。
 */
function currentSearchType() {
  const t = ($('#search-type button.on') || {}).dataset?.type || 'card';
  if ((t === 'phone' || t === 'email') && !S.settings.collect_pii) { return 'card'; }
  return t;
}

async function doMemberSearch() {
  resetLookupState();
  const type = currentSearchType();
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
        <div class="found"><b>${escapeHtml(m.card_no)}</b> ${tierBadge(d.tier)}
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
      + (d.tier ? '　' + tierBadge(d.tier) : '')
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

/**
 * 打开取景框扫码。
 *
 * 两处在用：会员弹层里的「扫卡」，和「查一张卡」里的「扫卡」。
 * 所以把目标参数化 —— 扫到之后往哪个输入框写、报错往哪儿显示、
 * 拿到码之后做什么，都由调用方给。
 *
 * 不支持扫码时【不硬撑】：直接引导手工输入（卡面本来就印着人可读号码），
 * 而不是弹一个空取景框卡在那里。
 */
async function launchScan(errSel, onCode) {
  showErr(errSel, '');
  showErr('#scan-err', '');

  if (typeof window.BarcodeDetector !== 'function') {
    return showErr(errSel, T('scan.unsupported'));
  }
  if (!window.SushiVIP || !SushiVIP.cameraSupported()) {
    return showErr(errSel,
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
    return showErr(errSel, T('scan.failed', { err: (e && e.message ? e.message : e) }));
  }

  const det = new BarcodeDetector({ formats: ['qr_code'] });
  const tick = async () => {
    if (!scanStream) return;                    // 已取消
    try {
      const codes = await det.detect($('#scan-video'));
      if (codes && codes.length) {
        const raw = String(codes[0].rawValue || '').trim();
        stopScan();
        // 扫到什么就交给调用方，是不是本店的卡由服务端判断
        return onCode(raw);
      }
    } catch (e) { /* 单帧识别失败无所谓，下一帧继续 */ }
    scanTimer = setTimeout(tick, 200);
  };
  tick();
}

$('#btn-scan').onclick = () => launchScan('#member-err', (raw) => {
  $('#member-input').value = raw;
  return doCardLookup(raw);
});

/**
 * 等级徽标。
 *
 * 倍率为 1 时【不显示倍率】—— 大多数卡都是 1 倍，全都标上只会变成噪音，
 * 真正需要一眼看见的是「这张不一样」。
 * 不分级（tier 为 null）时整个徽标不出现。
 *
 * 名字按当前语言取：服务端已经按 X-Lang 选好了 name，
 * 但 names 两种都给了，切语言时不用重新请求。
 */
function tierBadge(tier) {
  if (!tier) return '';
  const name = (tier.names && tier.names[I18N.lang]) || tier.name || '';
  if (!name) return '';
  const x = Number(tier.multiplier);
  const mult = (x && x !== 1) ? ` <span class="muted small">${escapeHtml(T('tier.multiplier', { x: x.toFixed(2).replace(/\.00$/, '') }))}</span>` : '';
  return `<span class="tag">${escapeHtml(name)}</span>${mult}`;
}

/* ── 查一张卡：客人当面问「我这卡还能用吗」 ──────────
 *
 * 后台也有同样的功能，两个都留：客人问的是【服务员】，
 * 让服务员转告经理再回话，既麻烦又没必要；而经理仍然需要在后台查
 * （对账、处理投诉、看作废原因）。
 *
 * 这里只读：不写库、不留痕、不要卡背 PIN。
 * 防线加在会掉钱的地方（核销），不是加在所有地方。
 */
function openAskCard() {
  $('#ask-input').value = '';
  $('#ask-result').innerHTML = '';
  showErr('#ask-err', '');
  $('#ask-modal').hidden = false;
  UI.back.sync();
  setTimeout(() => $('#ask-input').focus(), 50);
}

function closeAskCard() {
  $('#ask-modal').hidden = true;
  UI.back.sync();
}

$('#btn-ask-card').onclick   = openAskCard;
$('#btn-ask-close').onclick  = closeAskCard;
$('#btn-ask-go').onclick     = () => doAskCard($('#ask-input').value.trim());
$('#ask-input').addEventListener('keydown', e => {
  if (e.key === 'Enter') { doAskCard($('#ask-input').value.trim()); }
});
$('#btn-ask-scan').onclick = () => launchScan('#ask-err', (raw) => {
  $('#ask-modal').hidden = false;      // 扫码弹层盖在上面，关掉后要回到这里
  $('#ask-input').value = raw;
  return doAskCard(raw);
});

async function doAskCard(value) {
  showErr('#ask-err', '');
  $('#ask-result').innerHTML = '';
  if (!value) { return showErr('#ask-err', T('member.needCard')); }

  let d;
  try {
    d = await api('/card/status', { card_no: value });
  } catch (e) {
    return showErr('#ask-err', e.message);
  }
  $('#ask-result').innerHTML = askVerdict(d);
}

/**
 * 把服务端的状态翻成一句【服务员能照着念给客人听】的话。
 *
 * 顺序即优先级：作废 > 过期 > 未启用 > 快到期 > 正常。
 * 每一句都要说清「下一步该怎么办」，不能只说「不行」。
 */
function askVerdict(d) {
  const m = d.member;
  let headline, cls = 'found';

  if (d.status === 2) {                                  // 已作废
    headline = T('ask.void');
    cls = 'frozen';
  } else if (d.expired && !m) {
    headline = T('ask.expiredUnused', { date: d.valid_to });
    cls = 'frozen';
  } else if (d.expired && d.grace_over) {
    headline = T('ask.expiredTooLate', { date: d.valid_to });
    cls = 'frozen';
  } else if (d.expired) {
    headline = T('ask.expiredCanRenew', { date: d.valid_to });
    cls = 'frozen';
  } else if (d.state === 'stock') {
    headline = T('ask.notActivated');
  } else if (d.days_left !== null && d.days_left <= expiringSoonDays()) {
    headline = T('ask.okButSoon', { days: d.days_left });
  } else {
    headline = T('ask.okUse');
  }

  const lines = [];
  if (m) {
    lines.push(T('ask.points',  { points: m.points_balance }));
    lines.push(T('ask.visits',  { n: m.visit_count }));
    lines.push(m.coupons > 0 ? T('ask.coupons', { n: m.coupons }) : T('ask.noCoupons'));
  }
  lines.push(d.valid_to ? T('ask.validTo', { date: d.valid_to }) : T('ask.noExpiry'));

  return `
    <div class="${cls}"><b>${escapeHtml(d.card_no)}</b> ${tierBadge(d.tier)}
      <div style="margin:8px 0">${escapeHtml(headline)}</div>
      <div class="muted small">${lines.map(escapeHtml).join(' · ')}</div>
      ${m && m.progress ? `<div class="muted small">${escapeHtml(m.progress.text || '')}</div>` : ''}
      ${m && m.points_frozen ? `<div class="frozen">${T('member.frozen')}</div>` : ''}
      ${d.status === 2 && d.void_reason
          ? `<div class="muted small">${escapeHtml(T('ask.voidWhy', { reason: d.void_reason }))}</div>` : ''}
    </div>`;
}

function useMember(m) {
  /**
   * ★ 这张卡已经在这张单上记过了 —— 当场拦住，别等提交时才报错。
   *   服务端也会拒（member_already_on_order），这里只是把话提前说清楚：
   *   收银员已经填完金额、选完人再被退回来重做，是最招人烦的。
   */
  if (typeof S.memberTarget === 'number') {
    const dup = alreadyOnOrder().find(x => x.id === m.id);
    if (dup) {
      return showErr('#member-err', T('member.alreadyOnOrder', { card: maskCard(dup.card) }));
    }
  }
  if (S.memberTarget === 'merge') {
    S.merge.member = m;
    renderMerge();
  } else if (S.memberTarget === 'manual') {
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
            || !$('#ask-modal').hidden
            || CURRENT_STEP !== 'step-table',
  back: () => {
    // 扫码弹层排在最上面，且必须走 stopScan —— 直接隐藏会把相机留着不关
    if (!$('#scan-modal').hidden) { stopScan(); return true; }
    if (!$('#member-modal').hidden) { $('#member-modal').hidden = true; return true; }
    if (!$('#ask-modal').hidden)    { $('#ask-modal').hidden    = true; return true; }
    if (!$('#pin-modal').hidden)    { $('#pin-modal').hidden    = true; return true; }
    const prev = STEP_BACK[CURRENT_STEP];
    if (prev) { step(prev); return true; }
    return false;
  },
});
