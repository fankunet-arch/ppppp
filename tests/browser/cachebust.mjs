/**
 * 版本更新怎么落到 Pad 上。
 *
 * 现场原话：「我点了好久的刷新按钮，但是代码还是老旧的」。
 * 原因是 WebView 认为自己已经有 pad.js 了，压根不去取；
 * 而 Pad 上没有地址栏、没有开发者工具，清缓存要进系统设置。
 *
 * 页面改由 PHP 发之后，资源 URL 带上 mtime 版本号 —— 内容变了 URL 就变，
 * 浏览器必然重新下载；没变的 URL 不变，照旧命中缓存，一个字节都不下。
 *
 * 这里验的是【平时不下载、更新时立刻生效】这两件事同时成立，
 * 以及最要紧的一条：**绝不能在收银员干活干到一半时刷新**。
 */
import { launch, BASE } from './_launch.mjs';

let pass = 0, fail = 0;
const ok = (c, m) => { c ? (pass++, console.log('  \x1b[32m✓\x1b[0m ' + m)) : (fail++, console.log('  \x1b[31m✗\x1b[0m ' + m)); };

const browser = await launch();
const ctx  = await browser.newContext({ viewport: { width: 1280, height: 900 } });
const page = await ctx.newPage();
const errs = [];
page.on('pageerror', e => errs.push(String(e)));

// 记下每个资源实际走了网络还是命中缓存
const fetched = [];
page.on('response', r => {
  const u = new URL(r.url());
  if (u.pathname.startsWith('/assets/') || u.pathname.endsWith('.css')) {
    fetched.push({ path: u.pathname, v: u.searchParams.get('v'), fromCache: r.fromServiceWorker() });
  }
});

/**
 * 判断「文档有没有真的重新加载」。
 *
 * ★ 不能用 page.waitForNavigation()。
 *   Pad 的物理返回键靠往历史里放哨兵实现（ui.js 的 pushState / history.back），
 *   每次切步骤都会触发一次 framenavigated —— URL 压根没变，
 *   但 waitForNavigation 照样 resolve。于是「刷新了没有」这件事必然误判。
 *   （第一版就是这么写的，结论完全反了。）
 *
 * 改成在 window 上插一个标记：真刷新会换掉整个 document，标记随之消失。
 */
const mark  = () => page.evaluate(() => { window.__mark = 1; });
const alive = () => page.evaluate(() => window.__mark === 1);
/** 跑一段动作，返回文档是否被重新加载过 */
const didReload = async (action, waitMs = 1500) => {
  await mark();
  await action();
  await page.waitForTimeout(waitMs);
  return !(await alive());
};

console.log('\n【① 资源引用都带版本号】');
await page.goto(BASE + '/', { waitUntil: 'networkidle' });

const srcs = await page.evaluate(() =>
  [...document.querySelectorAll('script[src], link[rel=stylesheet]')]
    .map(el => el.getAttribute('src') || el.getAttribute('href')));
ok(srcs.length >= 5, `页面引了 ${srcs.length} 个资源`);
const bare = srcs.filter(s => !/\?v=\d+/.test(s));
ok(bare.length === 0, `★ 每个引用都带 ?v=（漏掉的：${bare.join(', ') || '无'}）`);

const ver = await page.evaluate(() => window.APP_VERSION);
ok(typeof ver === 'string' && /^\d+$/.test(ver), `页面带出了版本号 ${ver}`);

console.log('\n【② 文档本身不缓存，资源可以缓存】');
const res = await page.goto(BASE + '/', { waitUntil: 'networkidle' });
const cc = (res.headers()['cache-control'] || '');
ok(/no-store/.test(cc), `★ 文档是 no-store（实得：${cc}）`);

// 第二次打开：版本没变 → URL 没变 → 资源不该再走网络
fetched.length = 0;
await page.goto(BASE + '/', { waitUntil: 'networkidle' });
const fromNet = fetched.filter(f => f.v);
ok(fetched.every(f => f.v), '★ 重新打开时资源 URL 仍带着同一个版本号（所以能命中缓存）');

console.log('\n【③ 服务端版本变了，Pad 会自己更新】');
await page.evaluate(() => {
  document.querySelector('#login-name').value = 'admin';
  document.querySelector('#login-pin').value  = 'admin123';
});
await page.click('#btn-login');
await page.waitForSelector('#view-main.active', { timeout: 5000 });
ok(true, '登录进主界面');

/**
 * 装作服务端发布了新版本：伪造 /health 的 app_version。
 *
 * ★ 这个假版本号必须是【固定的】。第一版写成 'NEWER-' + Date.now()，
 *   每次请求都是新值，于是刷新之后还是对不上 → 再刷 → 无限刷新循环。
 *   那次翻车反倒暴露了产品里一个真问题：同一个版本必须只刷一次，
 *   否则收银机会陷进去 —— 已经加了 sessionStorage 兜底（见 pad.js）。
 */
const FAKE_VERSION = 'NEWER-0001';
await page.route('**/api.php/health', async route => {
  const r = await route.fetch();
  const j = await r.json();
  if (j.data) { j.data.app_version = FAKE_VERSION; }
  await route.fulfill({ response: r, body: JSON.stringify(j) });
});

ok(await didReload(() => page.evaluate(() => checkHealth()), 2500),
   '★★ 版本对不上 → 停在第一步时自己刷新，不用收银员去点刷新按钮');
await page.waitForSelector('#view-main.active', { timeout: 8000 });
ok(true, '刷新后会话还在，直接回到主界面（不用重新登录）');

console.log('\n【④ 干活干到一半时绝不刷新】');
// 这条比上面那条重要：刷掉的话，已经填好的金额和选好的会员会当场清空
await page.evaluate(() => {
  S.order = { serial_id: 1, table_name: '9', remaining_cents: 5000, remaining_portions: 2,
              excluded: 0, existing_ledger: [], customer_num: 2 };
  S.mode = 1;
  startAssign();
});
await page.waitForTimeout(300);
await page.fill('[data-amt="0"]', '42.00');
await page.waitForTimeout(200);
ok(await page.evaluate(() => S.people[0].amountCents) === 4200, '先在分配页填一个金额');

ok(await didReload(() => page.evaluate(() => checkHealth())) === false,
   '★★ 正在分配时不刷新 —— 收银员填了一半的东西不能被冲掉');
ok(await page.evaluate(() => S.people[0].amountCents) === 4200, '金额确实还在');
ok(await page.evaluate(() => typeof pendingUpdate !== 'undefined' && pendingUpdate === true),
   '★ 但更新被记下来了，等空了再说');

// 回到第一步 = 安全时机 —— 但这一轮已经为这个版本刷过一次了，不该再刷
ok(await didReload(() => page.evaluate(() => { resetFlow(); applyUpdateIfIdle(); })) === false,
   '★★ 同一个版本刷过一次就不再刷 —— 否则版本号一直对不上时收银机会无限刷新');
ok(await page.evaluate(() => sessionStorage.getItem('vip_reload_for')) === FAKE_VERSION,
   '★ 刷过哪个版本记在 sessionStorage 里（跨得过刷新，应用被划掉就清空）');

console.log('\n【⑤ 换一个新版本，还是会更新】');
// 上一条守的是「别死循环」，但不能守过头变成「以后都不更新了」
await page.unroute('**/api.php/health');
await page.route('**/api.php/health', async route => {
  const r = await route.fetch();
  const j = await r.json();
  if (j.data) { j.data.app_version = 'NEWER-0002'; }
  await route.fulfill({ response: r, body: JSON.stringify(j) });
});
ok(await didReload(() => page.evaluate(() => checkHealth()), 2500),
   '★★ 又发了一版（版本号不同）→ 照常自动更新');

console.log('\n【JS 错误】');
ok(errs.length === 0, errs.length ? '有报错：' + errs.join(' | ') : '无 JS 报错');

await browser.close();
console.log(`\n${'─'.repeat(50)}\n${fail === 0 ? '全部通过' : '失败 ' + fail}  ${pass + fail} 项\n`);
process.exit(fail ? 1 : 0);
