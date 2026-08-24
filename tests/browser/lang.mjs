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
