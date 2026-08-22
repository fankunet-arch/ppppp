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
      'justify-content:center;padding:20px;background:rgba(0,0,0,.45);overflow-y:auto}',
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
        showErr('不能为空');
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
      ok.textContent = opts.okText || '确定';
      ok.classList.toggle('ui-danger', !!opts.danger);
      host.querySelector('.ui-cancel').textContent = opts.cancelText || '取消';

      current = {
        resolve: resolve,
        kind: kind,
        required: kind === 'input' && opts.required !== false
      };
      host.hidden = false;

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

  global.UI = { confirm: askConfirm, input: askInput };
})(window);
