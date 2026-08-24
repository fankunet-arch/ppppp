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

console.log('\n【JS 错误】');
ok(errs.length === 0, errs.length ? '有报错：' + errs.join(' | ') : '无 JS 报错');

await browser.close();
setPii(0);
console.log(`\n${'─'.repeat(50)}\n${fail === 0 ? '全部通过' : '失败 ' + fail}  ${pass + fail} 项\n`);
process.exit(fail ? 1 : 0);
