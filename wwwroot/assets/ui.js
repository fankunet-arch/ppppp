/**
 * 平板容器通用 UI —— Pad 端与后台共用。
 * ---------------------------------------------------------------------------
 * 解决两件在原生容器里才暴露的问题：
 *
 * 1. 页内对话框替代 alert/confirm/prompt
 *    系统弹窗在 WebView 里会把页面来源地址显示在标题上，很难看；
 *    更要命的是 prompt —— 若容器没实现 onJsPrompt，它直接返回 null，
 *    表现为「点了没反应」，属于最难排查的那类静默失败。
 *    后台有一处是用 prompt 输新 PIN 的，走系统弹窗本来就不合适。
 *
 * 2. 软键盘遮挡
 *    平板锁横屏，键盘弹起来占掉接近一半高度，输入框会被顶出视口。
 *
 * 样式自带，不依赖 pad.css / cp.css —— 后台那份没有 .modal，
 * 而且两边变量名虽然一致但不完全重合（cp.css 没有 --radius）。
 * 因此这里一律用带 fallback 的 var()，跟着宿主主题走又不会缺样式。
 */
(function (global) {
  'use strict';

  var doc = global.document;
  var STYLE_ID = 'ui-ask-style';
  var host = null;      // 弹层根节点，懒建
  var current = null;   // 当前打开的那个 {resolve, kind}

  function injectStyle() {
    if (doc.getElementById(STYLE_ID)) return;
    var s = doc.createElement('style');
    s.id = STYLE_ID;
    s.textContent = [
      '.ui-ask{position:fixed;inset:0;z-index:100;display:flex;align-items:center;',
      'justify-content:center;padding:20px;background:rgba(0,0,0,.45);overflow-y:auto;',
      // 容器是边到边沉浸式，横屏下要避开挖孔/圆角
      'padding-left:calc(20px + env(safe-area-inset-left,0px));',
      'padding-right:calc(20px + env(safe-area-inset-right,0px))}',
      '.ui-ask[hidden]{display:none}',
      '.ui-ask-box{background:var(--card,#fff);color:var(--ink,#1c1f23);',
      'border-radius:var(--radius,10px);padding:22px;width:100%;max-width:460px;',
      'box-shadow:0 10px 40px rgba(0,0,0,.25)}',
      '.ui-ask-msg{white-space:pre-wrap;line-height:1.6;margin:0 0 16px}',
      '.ui-ask-box input{width:100%;box-sizing:border-box;font-size:17px;padding:10px 12px;',
      'border:1px solid var(--line,#d9dce1);border-radius:var(--radius,10px);',
      'background:#fff;color:var(--ink,#1c1f23)}',
      '.ui-ask-err{color:var(--err,#c0392b);margin:8px 0 0;font-size:14px}',
      '.ui-ask-err[hidden]{display:none}',
      '.ui-ask-btns{display:flex;gap:10px;margin-top:18px}',
      '.ui-ask-btns button{flex:1;font-size:16px;padding:12px 14px;cursor:pointer;',
      'border-radius:var(--radius,10px);border:1px solid var(--line,#d9dce1);background:#fff;',
      'color:var(--ink,#1c1f23)}',
      '.ui-ask-btns .ui-ok{background:var(--primary,#1f6feb);border-color:var(--primary,#1f6feb);',
      'color:#fff;font-weight:600}',
      '.ui-ask-btns .ui-ok.ui-danger{background:var(--err,#c0392b);border-color:var(--err,#c0392b)}'
    ].join('');
    doc.head.appendChild(s);
  }

  /**
   * 取一条文案，但【不硬依赖】i18n.js。
   *
   * ui.js 在 i18n.js 之前加载，而且后台（cp/）只引 ui.js 不引词典。
   * 所以这里永远只是「有就用、没有就回落中文」，不能直接引用 I18N。
   */
  function tr(key, fallback) {
    var i = global.I18N;
    if (!i || typeof i.t !== 'function') { return fallback; }
    var v = i.t(key);
    // 找不到键时 I18N.t 会返回 «key»，那种情况回落到内置中文
    return (v && v.charAt(0) !== '\u00AB') ? v : fallback;
  }

  function build() {
    injectStyle();
    host = doc.createElement('div');
    host.className = 'ui-ask';
    host.hidden = true;
    host.innerHTML =
      '<div class="ui-ask-box" role="dialog" aria-modal="true">' +
        '<p class="ui-ask-msg"></p>' +
        '<input class="ui-ask-input" hidden>' +
        '<p class="ui-ask-err" hidden></p>' +
        '<div class="ui-ask-btns">' +
          '<button type="button" class="ui-cancel"></button>' +
          '<button type="button" class="ui-ok"></button>' +
        '</div>' +
      '</div>';
    doc.body.appendChild(host);

    host.querySelector('.ui-cancel').addEventListener('click', function () { done(null); });
    host.querySelector('.ui-ok').addEventListener('click', function () { submit(); });
    host.querySelector('.ui-ask-input').addEventListener('keydown', function (e) {
      if (e.key === 'Enter') { e.preventDefault(); submit(); }
    });
    // 点遮罩关闭；点内容区不关
    host.addEventListener('mousedown', function (e) { if (e.target === host) done(null); });
    doc.addEventListener('keydown', function (e) {
      if (current && e.key === 'Escape') { e.preventDefault(); done(null); }
    });
  }

  function submit() {
    if (!current) return;
    if (current.kind === 'input') {
      var el = host.querySelector('.ui-ask-input');
      var v  = el.value;
      if (current.required && v.trim() === '') {
        showErr(tr('ui.required', '不能为空'));
        el.focus();
        return;
      }
      done(v);
      return;
    }
    done(true);
  }

  function showErr(msg) {
    var e = host.querySelector('.ui-ask-err');
    if (!msg) { e.hidden = true; return; }
    e.textContent = msg;
    e.hidden = false;
  }

  function done(value) {
    if (!current) return;
    var r = current.resolve;
    current = null;
    host.hidden = true;
    setTimeout(sync, 0);      // 弹层关了，可能已回到最外层
    // confirm 取消给 false，input 取消给 null —— 与原生 confirm/prompt 语义一致，
    // 调用点的 `=== null` / `if (!ok)` 判断都不用改写法
    r(value);
  }

  function open(kind, message, opts) {
    opts = opts || {};
    if (!host) build();
    return new Promise(function (resolve) {
      // 同一时刻只允许一个：后来的直接取消掉前一个，避免 resolve 悬挂
      if (current) { done(current.kind === 'input' ? null : false); }

      host.querySelector('.ui-ask-msg').textContent = String(message == null ? '' : message);
      var input = host.querySelector('.ui-ask-input');
      input.hidden = kind !== 'input';
      if (kind === 'input') {
        input.type        = opts.password ? 'password' : 'text';
        input.value       = opts.value || '';
        input.placeholder = opts.placeholder || '';
        if (opts.numeric) { input.setAttribute('inputmode', 'numeric'); }
        else { input.removeAttribute('inputmode'); }
      }
      showErr('');

      var ok = host.querySelector('.ui-ok');
      // 默认按钮文案跟着界面语言走。ui.js 比 i18n.js 先加载，
      // 所以只能在用的时候才去问，不能在加载时取。
      ok.textContent = opts.okText || tr('common.confirm', '确定');
      ok.classList.toggle('ui-danger', !!opts.danger);
      host.querySelector('.ui-cancel').textContent = opts.cancelText || tr('common.cancel', '取消');

      current = {
        resolve: resolve,
        kind: kind,
        required: kind === 'input' && opts.required !== false
      };
      host.hidden = false;
      sync();                 // 弹层打开 = 进入深层，放哨兵接住返回键

      if (kind === 'input') {
        setTimeout(function () { input.focus(); input.select(); }, 0);
      } else {
        setTimeout(function () { ok.focus(); }, 0);
      }
    });
  }

  /** 替代 confirm()：resolve(true) 或 resolve(false) */
  function askConfirm(message, opts) {
    return open('confirm', message, opts).then(function (v) { return v === true; });
  }

  /** 替代 prompt()：resolve(字符串) 或 resolve(null)（取消） */
  function askInput(message, opts) {
    return open('input', message, opts);
  }

  /* ── 软键盘遮挡兜底 ────────────────────────────────────
   * 用事件委托而不是逐个绑定：后台大量表格与弹层里的 input 是动态生成的，
   * 逐个绑定必然漏。
   * 延迟 300ms 是在等键盘弹出动画结束 —— 立刻算出来的位置是键盘出现前的，
   * 滚了等于没滚。
   */
  doc.addEventListener('focusin', function (e) {
    var el = e.target;
    if (!el || !el.tagName) return;
    if (!/^(INPUT|TEXTAREA|SELECT)$/.test(el.tagName)) return;
    if (el.type === 'hidden') return;
    setTimeout(function () {
      if (doc.activeElement !== el) return;     // 已经失焦就别乱滚
      try { el.scrollIntoView({ block: 'center', behavior: 'smooth' }); }
      catch (_) { el.scrollIntoView(); }
    }, 300);
  });

  /* ── 物理返回键 ────────────────────────────────────────
   * 容器的返回键走 WebView.canGoBack()：有历史就后退，没有就弹「确认退出」。
   * 而这两个页面都是单页状态机，从不写历史 —— 于是 canGoBack() 恒为 false，
   * 收银员在记账任何一步按返回，得到的都是「要退出应用吗」，
   * 而不是退回上一步。这是容器里才暴露的问题，浏览器上根本看不出来。
   *
   * 做法：处于「深层」（弹层打开、或不在第一步）时，往历史里放一条哨兵。
   * 返回键消费掉它 → popstate → 关掉最上面那一层 → 若仍在深层就再放一条。
   * 一路退到最外层后不再放哨兵，此时 canGoBack() 才是 false，
   * 容器弹退出确认才是合理的。
   *
   * 只用一条哨兵而不是维护一个历史栈：栈要和 UI 状态两头对齐，
   * 中途任何一次跳转（比如记完账直接回起点）都会让两边错位；
   * 哨兵法每次都从「当前真实 UI 状态」重新判断，不存在对不齐的问题。
   */
  var armed = false;
  var suppress = 0;          // 我们自己调 history.back() 时，跳过一次 popstate
  var layers = [];           // 页面注册的层级：{ deep: ()=>bool, back: ()=>bool }

  function isDeep() {
    if (current) return true;                       // 本模块的弹层
    for (var i = 0; i < layers.length; i++) {
      try { if (layers[i].deep()) return true; } catch (e) { /* 忽略 */ }
    }
    return false;
  }

  /** 关掉最上面一层，返回是否真的处理了 */
  function backOneLevel() {
    if (current) { done(current.kind === 'input' ? null : false); return true; }
    for (var i = layers.length - 1; i >= 0; i--) {
      try { if (layers[i].back()) return true; } catch (e) { /* 忽略 */ }
    }
    return false;
  }

  function sync() {
    if (isDeep()) {
      if (!armed) { history.pushState({ uiBack: 1 }, ''); armed = true; }
    } else if (armed) {
      // 自己回到最外层了，把残留的哨兵收掉，
      // 否则下一次按返回会「看起来没反应」（那一下只是在消费哨兵）
      armed = false; suppress++; history.back();
    }
  }

  global.addEventListener('popstate', function () {
    if (suppress > 0) { suppress--; return; }
    armed = false;                       // 这条哨兵已被消费
    if (backOneLevel()) sync();          // 还在深层就补一条；到底了就什么都不做
  });                                    // ——此时 canGoBack() 为 false，容器弹退出确认

  global.UI = {
    confirm: askConfirm,
    input:   askInput,
    /** 供页面注册自己的层级；ui.js 自己的弹层永远排在最上面 */
    back: {
      register: function (layer) { layers.push(layer); sync(); },
      sync: sync
    }
  };
})(window);
