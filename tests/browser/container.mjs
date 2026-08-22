import { launch, BASE } from './_launch.mjs';

const B = BASE;
let pass = 0, fail = 0;
const ok  = (c, m) => { c ? (pass++, console.log('  ✓ ' + m)) : (fail++, console.log('  ✗ ' + m)); };

const browser = await launch();
// 平板横屏实际形态
const ctx  = await browser.newContext({ viewport: { width: 1280, height: 800 } });
const page = await ctx.newPage();

const errors = [];
page.on('pageerror', e => errors.push(String(e)));
page.on('console', m => { if (m.type() === 'error' && !/Failed to load resource/.test(m.text())) errors.push('console: ' + m.text()); });

console.log('\n【Pad 端】');
await page.goto(B + '/', { waitUntil: 'networkidle' });

ok(await page.evaluate(() => typeof window.SushiVIP === 'object'), '桥接已加载 (window.SushiVIP)');
ok(await page.evaluate(() => typeof window.UI?.confirm === 'function'), '通用 UI 已加载 (UI.confirm)');
ok(await page.evaluate(() => typeof window.UI?.input === 'function'), 'UI.input 可用');

// 设备 ID：桥接在、但不在容器内 → 必须回落到 PAD- 前缀，且不能崩
const dev = await page.evaluate(() => ({ id: DEVICE, src: DEV.source }));
ok(dev.src === 'browser', `无容器时 source=browser（实得 ${dev.src}）`);
ok(/^PAD-/.test(dev.id || ''), `回落值保留 PAD- 前缀（实得 ${dev.id}）`);

// 模拟容器：注入 AppBridge 后重载，必须取到 native
await ctx.addInitScript(() => { window.AppBridge = { getDeviceId: () => 'a1b2c3d4e5f60718' }; });
await page.reload({ waitUntil: 'networkidle' });
const dev2 = await page.evaluate(() => ({ id: DEVICE, src: DEV.source }));
ok(dev2.src === 'native', `容器内 source=native（实得 ${dev2.src}）`);
ok(dev2.id === 'a1b2c3d4e5f60718', `容器内取到 ANDROID_ID（实得 ${dev2.id}）`);

ok(await page.locator('#btn-refresh-login').isVisible(), '登录页有刷新入口');

// 页内对话框：真的弹出、真的返回值
const confirmResult = await page.evaluate(async () => {
  const p = window.UI.confirm('测试确认框', { okText: '好的' });
  await new Promise(r => setTimeout(r, 50));
  const visible = !document.querySelector('.ui-ask').hidden;
  const text    = document.querySelector('.ui-ask-msg').textContent;
  const okText  = document.querySelector('.ui-ok').textContent;
  document.querySelector('.ui-ok').click();
  return { visible, text, okText, value: await p };
});
ok(confirmResult.visible, '确认框会真的弹出来');
ok(confirmResult.text === '测试确认框', '消息文本正确');
ok(confirmResult.okText === '好的', '按钮文案可自定义');
ok(confirmResult.value === true, '点确定返回 true');

const cancelResult = await page.evaluate(async () => {
  const p = window.UI.confirm('测试取消');
  await new Promise(r => setTimeout(r, 50));
  document.querySelector('.ui-cancel').click();
  return await p;
});
ok(cancelResult === false, '点取消返回 false（与原生 confirm 语义一致）');

const inputResult = await page.evaluate(async () => {
  const p = window.UI.input('测试输入', { value: '默认值' });
  await new Promise(r => setTimeout(r, 50));
  const el = document.querySelector('.ui-ask-input');
  const pre = el.value;
  el.value = '改过的值';
  document.querySelector('.ui-ok').click();
  return { pre, value: await p };
});
ok(inputResult.pre === '默认值', '输入框带出默认值');
ok(inputResult.value === '改过的值', '返回用户输入');

const inputCancel = await page.evaluate(async () => {
  const p = window.UI.input('测试输入取消');
  await new Promise(r => setTimeout(r, 50));
  document.querySelector('.ui-cancel').click();
  return await p;
});
ok(inputCancel === null, '输入框取消返回 null（与原生 prompt 语义一致）');

// 必填校验：空值不能提交
const req = await page.evaluate(async () => {
  const p = window.UI.input('不能为空');
  await new Promise(r => setTimeout(r, 50));
  document.querySelector('.ui-ok').click();
  await new Promise(r => setTimeout(r, 30));
  const errShown = !document.querySelector('.ui-ask-err').hidden;
  const stillOpen = !document.querySelector('.ui-ask').hidden;
  document.querySelector('.ui-cancel').click();
  await p;
  return { errShown, stillOpen };
});
ok(req.errShown && req.stillOpen, '空输入被拦下且弹层不关闭');

// 密码型
const pw = await page.evaluate(async () => {
  const p = window.UI.input('新 PIN', { password: true, numeric: true });
  await new Promise(r => setTimeout(r, 50));
  const el = document.querySelector('.ui-ask-input');
  const t = el.type, im = el.getAttribute('inputmode');
  document.querySelector('.ui-cancel').click();
  await p;
  return { t, im };
});
ok(pw.t === 'password', 'PIN 输入是 password 型（不明文显示）');
ok(pw.im === 'numeric', 'PIN 输入唤起数字键盘');

console.log('\n【安全区避让】');
// 容器是边到边沉浸式（setDecorFitsSystemWindows(false) + 隐藏系统栏），
// 页面又声明了 viewport-fit=cover —— 内容会铺到屏幕物理边缘。
// 平板若有挖孔/圆角，不做避让就会被压住。横屏关注 left/right。
const padOf = () => page.evaluate(() => {
  const c = getComputedStyle(document.querySelector('#view-main'));
  return { l: c.paddingLeft, r: c.paddingRight };
});
const p0 = await padOf();
ok(p0.l === '16px' && p0.r === '16px',
   `无挖孔时退回普通内边距（${p0.l} / ${p0.r}）—— 不支持 env() 的浏览器也是这个值`);

const cdp = await ctx.newCDPSession(page);
try {
  await cdp.send('Emulation.setSafeAreaInsetsOverride',
                 { insets: { top: 0, left: 48, bottom: 0, right: 24 } });
  await page.waitForTimeout(200);
  const p1 = await padOf();
  ok(p1.l === '64px' && p1.r === '40px',
     `★ 模拟挖孔后自动避让（16+48=${p1.l} / 16+24=${p1.r}）`);
  await cdp.send('Emulation.setSafeAreaInsetsOverride',
                 { insets: { top: 0, left: 0, bottom: 0, right: 0 } });
} catch (e) {
  console.log('  – 跳过：本版 Chromium 不支持安全区模拟');
}

console.log('\n【后台】');
const page2 = await ctx.newPage();
page2.on('pageerror', e => errors.push('cp: ' + String(e)));
page2.on('console', m => { if (m.type() === 'error' && !/Failed to load resource/.test(m.text())) errors.push('cp console: ' + m.text()); });
await page2.goto(B + '/cp/', { waitUntil: 'networkidle' });
ok(await page2.evaluate(() => typeof window.UI?.confirm === 'function'), '后台也加载了通用 UI');
ok(await page2.locator('#btn-refresh-login').isVisible(), '后台登录页有刷新入口');
const vp = await page2.evaluate(() => document.querySelector('meta[name=viewport]').content);
ok(vp.includes('viewport-fit=cover'), `后台 viewport 含 viewport-fit=cover（${vp}）`);

console.log('\n【JS 错误】');
ok(errors.length === 0, errors.length ? '有报错：\n      ' + errors.join('\n      ') : '两个页面均无 JS 报错');

await browser.close();
console.log(`\n${'─'.repeat(50)}\n${fail === 0 ? '全部通过' : '失败 ' + fail}  ${pass + fail} 项\n`);
process.exit(fail ? 1 : 0);
