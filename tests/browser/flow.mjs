import { launch, BASE } from './_launch.mjs';
const B = BASE;
let pass = 0, fail = 0;
const ok = (c, m) => { c ? (pass++, console.log('  ✓ ' + m)) : (fail++, console.log('  ✗ ' + m)); };

const browser = await launch();
const ctx = await browser.newContext({ viewport: { width: 1280, height: 800 } });
const page = await ctx.newPage();
const errs = [];
page.on('pageerror', e => errs.push(String(e)));

console.log('\n【后台真实链路：登录 → 操作员 → 重置 PIN】');
await page.goto(B + '/cp/', { waitUntil: 'networkidle' });
await page.fill('#login-name', 'admin');
await page.fill('#login-pin', 'admin123');
await page.click('#btn-login');
await page.waitForSelector('#view-main.active', { timeout: 5000 });
ok(true, '登录成功，进入主界面');
ok(await page.locator('#btn-refresh').isVisible(), '主界面顶栏有刷新按钮');

await page.click('[data-tab="operators"]');
await page.waitForSelector('[data-rp]', { timeout: 5000 });
const n = await page.locator('[data-rp]').count();
ok(n > 0, `操作员列表渲染出 ${n} 个重置按钮`);

// 点重置 —— 应该弹出页内输入框，而不是系统 prompt
await page.locator('[data-rp]').first().click();
await page.waitForSelector('.ui-ask:not([hidden])', { timeout: 3000 });
const msg = await page.locator('.ui-ask-msg').textContent();
ok(/设置新 PIN/.test(msg), `弹出的是页内输入框：「${msg}」`);
ok(await page.locator('.ui-ask-input').getAttribute('type') === 'password', '输入框是密码型');

// 输入过短的 PIN，走 toast 校验分支
await page.fill('.ui-ask-input', '123');
await page.click('.ui-ok');
await page.waitForSelector('#toast:not([hidden])', { timeout: 3000 });
ok(/至少 6 位/.test(await page.locator('#toast').textContent()), '短 PIN 被拦下并提示');

// 再来一次，输合法 PIN → 应进入二次确认
await page.locator('[data-rp]').first().click();
await page.waitForSelector('.ui-ask:not([hidden])', { timeout: 3000 });
await page.fill('.ui-ask-input', '654321');
await page.click('.ui-ok');
await page.waitForTimeout(200);
const msg2 = await page.locator('.ui-ask-msg').textContent();
ok(/确认重置/.test(msg2), `串到二次确认框：「${msg2.replace(/\n/g, ' ')}」`);
ok(await page.locator('.ui-ok').textContent() === '确认重置', '确认按钮文案正确');
ok(await page.locator('.ui-ok').evaluate(el => el.classList.contains('ui-danger')), '危险操作用红色按钮');

// 取消 —— 不真的改密码
await page.click('.ui-cancel');
await page.waitForTimeout(200);
ok(await page.locator('.ui-ask').isHidden(), '取消后弹层关闭，未执行重置');

console.log('\n【JS 错误】');
ok(errs.length === 0, errs.length ? '有报错：' + errs.join(' | ') : '无 JS 报错');

await browser.close();
console.log(`\n${'─'.repeat(50)}\n${fail === 0 ? '全部通过' : '失败 ' + fail}  ${pass + fail} 项\n`);
process.exit(fail ? 1 : 0);
