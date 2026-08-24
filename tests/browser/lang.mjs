/**
 * 多语言 —— 只有真浏览器能验的那部分。
 *
 * 逻辑测试已经守住「词典两种语言齐全」，这里验的是**行为**：
 *
 *   1. 切换真的把界面换掉了（包括 JS 生成的那些，不只是静态标签）
 *   2. 切换【不会吃掉输入框】—— data-i18n 走 textContent，
 *      挂在包着 <input> 的 <label> 上会把输入框整个抹掉，
 *      而这个错误在中文界面下完全看不出来
 *   3. 服务端的报错也跟着换语言（Pad 带 X-Lang，服务端按它回话）
 *   4. **语言记在账号上**：换台平板、重新登录，还是他选的那种；
 *      换个人登录则换成那个人的
 *   5. 切换不会把收银员已经填好的东西冲掉
 */
import { launch, BASE } from './_launch.mjs';
import { execSync } from 'node:child_process';

const REPO = '/home/user/ppppp';
let pass = 0, fail = 0;
const ok = (c, m) => { c ? (pass++, console.log('  \x1b[32m✓\x1b[0m ' + m)) : (fail++, console.log('  \x1b[31m✗\x1b[0m ' + m)); };
/** 等值断言：失败时要看得见实得什么，否则这类顺序问题很难查 */
const eqOk = (actual, expected, m) =>
  ok(actual === expected, m + (actual === expected ? '' : `（期望 ${expected}，实得 ${actual}）`));

const php = (code) => {
  const probe = 'register_shutdown_function(function () {'
    + ' $e = error_get_last();'
    + ' if ($e !== null && ($e["type"] & (E_ERROR | E_PARSE | E_COMPILE_ERROR)) !== 0) {'
    + '   fwrite(STDERR, $e["message"] . " @ " . $e["file"] . ":" . $e["line"]);'
    + ' }});';
  try {
    return execSync('php', { cwd: REPO, encoding: 'utf8', input: '<?php ' + probe + code,
                             stdio: ['pipe', 'pipe', 'pipe'] }).trim();
  } catch (e) {
    throw new Error('造数 PHP 失败：\n' + [e.stdout, e.stderr].filter(Boolean).join('\n').trim());
  }
};

/** 把两个账号的语言偏好清空，跑完还原 */
const resetLangs = () => php(`
  require "app/bootstrap.php";
  $c = require "app/config/config.php";
  $a = new Vip\\App($c);
  $a->localDb()->exec('UPDATE operator SET lang = NULL WHERE store_code = ?', [$c['store_code']]);
`);

resetLangs();

const browser = await launch();
const ctx  = await browser.newContext({ viewport: { width: 1280, height: 900 } });
const page = await ctx.newPage();
const errs = [];
page.on('pageerror', e => errs.push(String(e)));

const login = async (who = 'admin') => {
  await page.fill('#login-name', who);
  await page.fill('#login-pin', 'admin123');
  await page.click('#btn-login');
  await page.waitForSelector('#view-main.active', { timeout: 5000 });
};

console.log('\n【① 登录页就能切】');
await page.goto(BASE + '/', { waitUntil: 'networkidle' });
ok(await page.locator('h1').textContent() === '会员积分', '默认中文');
const btns = await page.locator('#lang-login .lang-btn').allTextContents();
ok(btns.join('|') === '中文|Español', `两个语言按钮，各用自己的语言写：${btns.join(' / ')}`);

await page.click('#lang-login .lang-btn[data-lang=es]');
await page.waitForTimeout(300);
ok(await page.locator('h1').textContent() === 'Puntos de socio', '★ 切到西语，标题跟着变');
ok(await page.locator('#btn-login').textContent() === 'Entrar', '按钮也变了');
ok(await page.evaluate(() => document.documentElement.lang) === 'es',
   '★ <html lang> 跟着变（屏幕朗读与输入法要看它）');

console.log('\n【② 切换不能吃掉输入框】');
// data-i18n 走 textContent，挂在包着 <input> 的 <label> 上会把输入框抹掉。
// 这个错误在中文界面下完全看不出来 —— 初始 HTML 是对的。
for (const sel of ['#login-name', '#login-pin']) {
  ok(await page.locator(sel).count() === 1, `${sel} 切完语言还在`);
}
ok((await page.locator('#view-login label').first().textContent()).trim() === 'Usuario',
   'label 的文字确实换了（说明不是靠没生效蒙混过关）');

console.log('\n【③ 服务端报错也跟着换语言】');
await page.fill('#login-name', 'admin');
await page.fill('#login-pin', '000000');
await page.click('#btn-login');
await page.waitForTimeout(800);
let err = await page.locator('#login-err').textContent();
ok(!/[一-龥]/.test(err) && err.trim() !== '',
   `★ 西语界面下服务端报错也是西语：「${err.trim()}」`);

await page.click('#lang-login .lang-btn[data-lang=zh]');
await page.waitForTimeout(200);
await page.fill('#login-pin', '000000');
await page.click('#btn-login');
await page.waitForTimeout(800);
err = await page.locator('#login-err').textContent();
ok(/[一-龥]/.test(err), `切回中文，报错也回中文：「${err.trim()}」`);

console.log('\n【④ 语言记在账号上】');
await login('admin');
ok(await page.locator('#btn-logout').textContent() === '退出', '登录后是中文（这个账号还没选过）');

await page.click('#lang-main .lang-btn[data-lang=es]');
await page.waitForTimeout(600);
ok(await page.locator('#btn-logout').textContent() === 'Salir', '★ 顶栏切换生效');
ok((await page.locator('h2').first().textContent()).startsWith('①'), '步骤标题还在');

const saved = php(`
  require "app/bootstrap.php";
  $c = require "app/config/config.php";
  $a = new Vip\\App($c);
  echo (string)$a->localDb()->value(
    'SELECT lang FROM operator WHERE store_code = ? AND login_name = ?', [$c['store_code'], 'admin']);
`);
ok(saved === 'es', `★ 选择落库了（operator.lang = ${saved || '(空)'}）`);

// 刷新：会话恢复走的是 /auth/me，不是登录 —— 那条路也得带上语言
await page.reload({ waitUntil: 'networkidle' });
await page.waitForSelector('#view-main.active', { timeout: 5000 });
ok(await page.locator('#btn-logout').textContent() === 'Salir',
   '★ 刷新后仍是西语（会话恢复那条路也要带语言，最容易漏）');

// 换一台「平板」：新的浏览器上下文 = 全新的 localStorage
const ctx2  = await browser.newContext({ viewport: { width: 1280, height: 900 } });
const page2 = await ctx2.newPage();
await page2.goto(BASE + '/', { waitUntil: 'networkidle' });
ok(await page2.locator('h1').textContent() === '会员积分',
   '新平板的登录页是后台默认语言（中文）');
await page2.fill('#login-name', 'admin');
await page2.fill('#login-pin', 'admin123');
await page2.click('#btn-login');
await page2.waitForSelector('#view-main.active', { timeout: 5000 });
ok(await page2.locator('#btn-logout').textContent() === 'Salir',
   '★★ 换台平板登录，还是他自己选的西语 —— 语言跟着账号走，不跟着平板走');

// 换个人：这台平板上的语言不该被上一个人的选择带偏
await page2.click('#btn-logout');
await page2.waitForTimeout(400);
await page2.fill('#login-name', 'cashier1');
await page2.fill('#login-pin', 'admin123');
await page2.click('#btn-login');
await page2.waitForSelector('#view-main.active', { timeout: 5000 });
ok(await page2.locator('#btn-logout').textContent() === '退出',
   '★★ 同一台平板换个人登录，用的是这个人自己的语言（他没选过 → 后台默认）');
await ctx2.close();

console.log('\n【④bis 两个账号轮流用同一台平板 —— 逐字对着需求走一遍】');

/**
 * 店主原话：
 *   「A 账号选择中文，B 账号选择西文。下次 A 账号登录，无视当前语言，
 *     直接切换为中文，A 退出后 B 登录，自动切换为西文。如果 B 账号下次
 *     切换为中文，再下次 A 账号登录为中文，B 账号登录也是中文。
 *     数据库记录下每个账号最后一次使用的语言。」
 *
 * 关键在最后一句：库里存的是【每个账号各自】的最后一次选择，
 * 不是一个全局值。所以 B 改主意只影响 B。
 * 下面按这段话的顺序一步步走，每一步都同时核对界面和库里的值。
 */
const ctxA = await browser.newContext({ viewport: { width: 1280, height: 900 } });
const pgA  = await ctxA.newPage();
const errsAB = [];
pgA.on('pageerror', e => errsAB.push(String(e)));

const A = 'admin', B = 'cashier1';

const langOf = (who) => php(`
  require "app/bootstrap.php";
  $c = require "app/config/config.php";
  $a = new Vip\\App($c);
  echo (string)$a->localDb()->value(
    'SELECT lang FROM operator WHERE store_code = ? AND login_name = ?', [$c['store_code'], '${who}']);
`);

const signIn = async (who) => {
  await pgA.fill('#login-name', who);
  await pgA.fill('#login-pin', 'admin123');
  await pgA.click('#btn-login');
  await pgA.waitForSelector('#view-main.active', { timeout: 5000 });
  await pgA.waitForTimeout(200);
};
const signOut = async () => {
  await pgA.click('#btn-logout');
  await pgA.waitForSelector('#view-login.active', { timeout: 5000 });
  await pgA.waitForTimeout(200);
};
const pick = async (lang) => {
  await pgA.click(`#lang-main .lang-btn[data-lang=${lang}]`);
  await pgA.waitForTimeout(600);
};
/** 用「退出」按钮的文案判断当前界面语言 —— 它一直在顶栏上 */
const shown = async () => (await pgA.locator('#btn-logout').textContent()).trim();

await pgA.goto(BASE + '/', { waitUntil: 'networkidle' });

// ── 第 1 步：A 选中文，B 选西文 ──
await signIn(A);
await pick('zh');
eqOk(await shown(), '退出', 'A 选了中文');
eqOk(langOf(A), 'zh', 'A 的选择进了库');
await signOut();

await signIn(B);
await pick('es');
eqOk(await shown(), 'Salir', 'B 选了西文');
eqOk(langOf(B), 'es', 'B 的选择进了库');
eqOk(langOf(A), 'zh', '★ B 的选择没有动到 A —— 库里是【每个账号各一条】，不是一个全局值');
await signOut();

// ── 第 2 步：A 登录，无视当前语言，直接切回中文 ──
// 先把【登录页】显式切成西文，这样「无视当前语言」才是真的在验东西：
// 不这么摆，页面本来就是中文，A 登录后是中文也说明不了什么。
await pgA.click('#lang-login .lang-btn[data-lang=es]');
await pgA.waitForTimeout(300);
eqOk(await pgA.locator('h1').textContent(), 'Puntos de socio',
     '（先把登录页摆成西文，下面这条才有意义）');
await signIn(A);
eqOk(await shown(), '退出', '★★ A 登录 → 无视当前的西文，直接切成 A 的中文');
await signOut();

eqOk(await pgA.locator('h1').textContent(), 'Puntos de socio',
     '★ 退出后登录页回到【这台平板】记住的语言（刚才切成了西文），不是留着上一个人的');

// ── 第 3 步：A 退出后 B 登录，自动切成西文 ──
await signIn(B);
eqOk(await shown(), 'Salir', '★★ 同一台平板换 B 登录 → 自动切成 B 的西文');

// ── 第 4 步：B 改主意，切成中文 ──
await pick('zh');
eqOk(await shown(), '退出', 'B 改选中文');
eqOk(langOf(B), 'zh', '★ 库里记的是 B【最后一次】的选择，覆盖掉之前的 es');
eqOk(langOf(A), 'zh', 'A 的记录仍然是它自己的');
await signOut();

// ── 第 5 步：此后 A 登录是中文，B 登录也是中文 ──
await signIn(A);
eqOk(await shown(), '退出', '★ A 登录 → 中文（A 自己的选择）');
await signOut();
await signIn(B);
eqOk(await shown(), '退出', '★★ B 登录 → 也是中文（B 改过之后的选择，不是回到 es）');
await signOut();

// ── 再验一次「互不干扰」：A 改成西文，B 不受影响 ──
await signIn(A);
await pick('es');
eqOk(langOf(A), 'es', 'A 改成西文');
eqOk(langOf(B), 'zh', '★★ A 改自己的，B 还是中文 —— 两条记录彼此独立');
await signOut();
await signIn(B);
eqOk(await shown(), '退出', 'B 登录仍是中文');
await signOut();

ok(errsAB.length === 0, errsAB.length ? 'A/B 轮换过程中有 JS 报错：' + errsAB.join(' | ') : 'A/B 轮换全程无 JS 报错');
await ctxA.close();

console.log('\n【⑤ 切换不能冲掉填了一半的东西】');
await page.click('#lang-main .lang-btn[data-lang=zh]');
await page.waitForTimeout(500);
// 直接进到分配步骤造一个「填了一半」的现场
await page.evaluate(() => {
  S.order = { serial_id: 1, table_name: '9', remaining_cents: 5000, remaining_portions: 2,
              excluded: 0, existing_ledger: [], customer_num: 2 };
  S.mode = 1;
  startAssign();
});
await page.waitForTimeout(300);
await page.fill('[data-amt="0"]', '33.50');
await page.waitForTimeout(200);
ok(await page.evaluate(() => S.people[0].amountCents) === 3350, '先填一个金额');

await page.click('#lang-main .lang-btn[data-lang=es]');
await page.waitForTimeout(500);
ok(await page.evaluate(() => S.people[0].amountCents) === 3350,
   '★★ 切语言之后金额还在（重画分配步骤时不能调 startAssign，那会重置 S.people）');
ok(await page.locator('[data-amt="0"]').inputValue() === '33.50', '输入框里的值也还在');
ok((await page.locator('#assign-title').textContent()).trim() === 'Todo el ticket a un socio',
   '★ JS 生成的标题也换成了西语（不只是静态标签）');
ok((await page.locator('.person .prt').first().textContent()).includes('Menús'),
   '分配行里的标签也是西语');

console.log('\n【JS 错误】');
ok(errs.length === 0, errs.length ? '有报错：' + errs.join(' | ') : '无 JS 报错');

await browser.close();
resetLangs();

console.log(`\n${'─'.repeat(50)}\n${fail === 0 ? '全部通过' : '失败 ' + fail}  ${pass + fail} 项\n`);
process.exit(fail ? 1 : 0);
