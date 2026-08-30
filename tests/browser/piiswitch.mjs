import { launch, BASE } from './_launch.mjs';
import { execSync } from 'node:child_process';

let pass = 0, fail = 0;
const ok = (c, m) => { c ? (pass++, console.log('  \x1b[32m✓\x1b[0m ' + m)) : (fail++, console.log('  \x1b[31m✗\x1b[0m ' + m)); };
const setPii = (v) => execSync(
  `php -r 'require "app/bootstrap.php"; $c=require "app/config/config.php";` +
  ` (new Vip\\App($c))->cfg()->set("member_collect_pii","${v}");'`,
  { cwd: '/home/user/ppppp' });

const browser = await launch();
const page = await (await browser.newContext({ viewport: { width: 1280, height: 900 } })).newPage();
const errs = [];
page.on('pageerror', e => errs.push(String(e)));

const login = async () => {
  await page.goto(BASE + '/cp/', { waitUntil: 'networkidle' });
  await page.fill('#login-name', 'admin');
  await page.fill('#login-pin', 'admin123');
  await page.click('#btn-login');
  await page.waitForSelector('#view-main.active', { timeout: 5000 });
};

console.log('\n【实名开关 · 未接短信时的提醒】');

setPii(0);
await login();
ok(await page.locator('#cp-warnings .warnbar').count() === 0, '关闭状态：没有红条');

// ── 开启时应先弹窗拦一下 ──
await page.click('[data-tab="config"]');
await page.waitForSelector('[data-ck="member_collect_pii"]', { timeout: 5000 });
await page.click('[data-ck="member_collect_pii"]');
await page.waitForSelector('.ui-ask:not([hidden])', { timeout: 3000 });
const msg = await page.locator('.ui-ask-msg').textContent();
ok(/尚未接入|未配置/.test(msg), '★ 开启前弹窗告知发送渠道未就绪');
ok(/积分会一直冻结/.test(msg), '说清后果：客人积分会一直冻结');
ok(await page.locator('.ui-ok').evaluate(el => el.classList.contains('ui-danger')), '按钮是危险色');

// 取消 → 不应开启
await page.click('.ui-cancel');
await page.waitForTimeout(600);
ok(await page.locator('[data-ck="member_collect_pii"]').isChecked() === false,
   '★ 取消后复选框复原，没有被开启');
ok(await page.locator('#cp-warnings .warnbar').count() === 0, '取消后仍无红条');

// 确认 → 开启并立刻出现红条
await page.click('[data-ck="member_collect_pii"]');
await page.waitForSelector('.ui-ask:not([hidden])', { timeout: 3000 });
await page.click('.ui-ok');
await page.waitForFunction(() => document.querySelectorAll('#cp-warnings .warnbar').length > 0,
                           null, { timeout: 5000 }).catch(() => {});
const bar = await page.locator('#cp-warnings .warnbar').textContent().catch(() => '');
ok(/积分会一直冻结/.test(bar), `★ 开启后立刻出现常驻红条：「${bar.trim().slice(0, 40)}…」`);

// ── 重新登录，红条仍在（不是一次性提示）──
await page.reload({ waitUntil: 'networkidle' });
await page.waitForSelector('#view-main.active', { timeout: 5000 });
const bar2 = await page.locator('#cp-warnings .warnbar').textContent().catch(() => '');
ok(/积分会一直冻结/.test(bar2),
   '★ 刷新页面后红条仍在（走的是会话恢复那条路，只在登录处理里渲染会漏）');

// ── 关掉开关 → 红条消失 ──
await page.click('[data-tab="config"]');
await page.waitForSelector('[data-ck="member_collect_pii"]', { timeout: 5000 });
await page.click('[data-ck="member_collect_pii"]');   // 关闭不拦截
await page.waitForFunction(() => document.querySelectorAll('#cp-warnings .warnbar').length === 0,
                           null, { timeout: 5000 }).catch(() => {});
ok(await page.locator('#cp-warnings .warnbar').count() === 0, '关掉开关后红条消失');
ok(await page.locator('[data-ck="member_collect_pii"]').isChecked() === false, '开关确实关掉了');

console.log('\n【前台：关掉之后不该再问客人要手机号】');
/**
 * 客人来问「我这卡还能用吗」「帮我记一下分」，收银员打开「选择会员」——
 * 上面三档卡号 / 手机号 / 邮箱。后台已经决定不登记联系方式了，
 * 那这两档就不该点得动：点得动就意味着收银员会开口问，
 * 客人报了、系统又存不进去（后端拒收），双方都白费一趟。
 *
 * ★ 这里是【禁用】不是【删掉】，和「建卡时的联系方式栏」不一样：
 *   那一栏是采集入口，存在本身就是在邀请人去要信息，所以整块移除；
 *   这里是查找入口，灰着至少能让人看懂「不是坏了，是本店不这么做」。
 */
const padCtx = await browser.newContext({ viewport: { width: 1024, height: 1366 } });
const pad = await padCtx.newPage();
pad.on('pageerror', e => errs.push(String(e)));

const padLogin = async () => {
  await pad.goto(BASE + '/', { waitUntil: 'networkidle' });
  if (await pad.locator('#view-login.active').count() > 0) {
    await pad.fill('#login-name', 'admin');
    await pad.fill('#login-pin', 'admin123');
    await pad.click('#btn-login');
  }
  await pad.waitForSelector('#view-main.active', { timeout: 8000 });
};

setPii(0);
await padLogin();
await pad.click('#btn-ask-card').catch(() => {});
await pad.click('#btn-ask-close').catch(() => {});

// 直接打开会员弹层（不必先走完找单流程）
await pad.evaluate(() => { document.querySelector('#member-modal').hidden = false; });
await pad.waitForTimeout(300);

ok(await pad.locator('#search-type button[data-type=card]').isDisabled() === false,
   '★ 卡号那一档始终可用');
ok(await pad.locator('#search-type button[data-type=phone]').isDisabled() === true,
   '★★ 关闭时「手机号」点不动');
ok(await pad.locator('#search-type button[data-type=email]').isDisabled() === true,
   '★★ 关闭时「邮箱」点不动');
ok(await pad.locator('#search-type button[data-type=card]').evaluate(el => el.classList.contains('on')),
   '  └ 当前停在「卡号」档上');
const noteOff = await pad.locator('#pii-off-note');
ok(await noteOff.isVisible() && /不登记/.test(await noteOff.textContent()),
   `★★ 并且写明为什么，不是让人以为坏了：「${(await noteOff.textContent()).trim()}」`);

// 点一下被禁用的档 —— 不该切过去
await pad.locator('#search-type button[data-type=phone]').click({ force: true }).catch(() => {});
await pad.waitForTimeout(200);
ok(await pad.locator('#search-type button[data-type=card]').evaluate(el => el.classList.contains('on')),
   '★★ 硬点被禁用的档也切不过去（force click 都不行）');

// ── 西语界面下那句说明也要跟着换 ──
// 语言按钮在主界面上，会员弹层盖着它 —— 先收起弹层再切
await pad.click('#btn-member-close');
await pad.waitForTimeout(200);
await pad.click('#lang-main .lang-btn[data-lang=es]');
await pad.waitForTimeout(600);
await pad.evaluate(() => { document.querySelector('#member-modal').hidden = false; });
await pad.waitForTimeout(200);
const noteEs = (await pad.locator('#pii-off-note').textContent()).trim();
ok(/no registra/i.test(noteEs), `★★ 西语界面下这句话也换了：「${noteEs}」`);
ok(!/不登记/.test(noteEs), '  └ 中文没有漏出来');
ok(await pad.locator('#search-type button[data-type=phone]').isDisabled() === true,
   '  └ 切了语言之后仍然是禁用的（重画时别把 disabled 弄丢了）');
await pad.click('#btn-member-close');
await pad.waitForTimeout(200);
await pad.click('#lang-main .lang-btn[data-lang=zh]');
await pad.waitForTimeout(400);

// ── 打开开关 → 三档都回来 ──
setPii(1);
await padLogin();                       // 开关随登录下发，要重登一次才生效
await pad.evaluate(() => { document.querySelector('#member-modal').hidden = false; });
await pad.waitForTimeout(300);
ok(await pad.locator('#search-type button[data-type=phone]').isDisabled() === false,
   '★★ 打开开关后「手机号」恢复可点');
ok(await pad.locator('#search-type button[data-type=email]').isDisabled() === false,
   '  └「邮箱」也恢复');
ok(await pad.locator('#pii-off-note').isVisible() === false, '  └ 那句说明收起来了');
await pad.locator('#search-type button[data-type=phone]').click();
await pad.waitForTimeout(200);
ok(await pad.locator('#search-type button[data-type=phone]').evaluate(el => el.classList.contains('on')),
   '★ 这时才真的切得过去');
// 切过去之后「扫卡」按钮要收起来 —— 手机号档扫卡没有意义
ok(await pad.locator('#btn-scan').isHidden(), '★ 手机号档下扫卡按钮隐藏');
await pad.locator('#search-type button[data-type=card]').click();
await pad.waitForTimeout(200);
ok(await pad.locator('#btn-scan').isVisible(), '  └ 切回卡号档又出现');

setPii(0);

console.log('\n【JS 错误】');
ok(errs.length === 0, errs.length ? '有报错：' + errs.join(' | ') : '无 JS 报错');

await browser.close();
setPii(0);
console.log(`\n${'─'.repeat(50)}\n${fail === 0 ? '全部通过' : '失败 ' + fail}  ${pass + fail} 项\n`);
process.exit(fail ? 1 : 0);
