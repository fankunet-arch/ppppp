/**
 * 找订单 —— 收银员每一单都要走的第一步。
 *
 * ★ 这个文件是补上一次现场事故的。
 *
 *   之前这条主流程【一条浏览器测试都没有】：其它脚本要么直接
 *   `openMemberModal()` 跳过找单，要么测的是后台。于是把 index.html 里的
 *   `<span id="win-label">` 删掉、pad.js 里的引用忘了删，谁都没发现，
 *   直到收银员输了个桌号，屏幕上弹出
 *       Cannot set properties of null (setting 'textContent')
 *   而且因为异常被 catch 吃掉，「放宽再找」「改用手工录入」两个降级入口
 *   也一起消失 —— 现场看到的是「按钮不见了」。
 *
 *   静态那一层已经由 tests/cases/DomRefTest.php 挡住；这里守的是
 *   **这条路真的能走通**，以及出错时降级入口真的会出现。
 *
 * 需要先注入模拟活单：
 *   sudo SIM_USER=... php tests/sim/inject_live.php
 */
import { launch, BASE } from './_launch.mjs';
import { readFileSync } from 'node:fs';

let pass = 0, fail = 0;
const ok = (c, m) => { c ? (pass++, console.log('  \x1b[32m✓\x1b[0m ' + m)) : (fail++, console.log('  \x1b[31m✗\x1b[0m ' + m)); };

const live = JSON.parse(readFileSync('/home/user/ppppp/tests/sim/live_orders.json', 'utf8'));
const REAL_TABLE = String(live[0].table);          // 一定查得到的桌号
const EMPTY_TABLE = '77';                           // 不太可能有单的桌号

const browser = await launch();
const page = await (await browser.newContext({ viewport: { width: 1280, height: 900 } })).newPage();
const errs = [];
page.on('pageerror', e => errs.push(String(e)));

const login = async () => {
  await page.fill('#login-name', 'admin');
  await page.fill('#login-pin', 'admin123');
  await page.click('#btn-login');
  await page.waitForSelector('#view-main.active', { timeout: 5000 });
};

console.log('\n【① 默认就停在「桌号」上】');
await page.goto(BASE + '/', { waitUntil: 'networkidle' });
await login();

ok(await page.locator('#tab-table').evaluate(el => el.classList.contains('on')),
   '★ 默认选中的是「桌号」（客人还在桌上，这是常态）');
ok(await page.locator('#pane-table').isVisible(), '桌号那一栏是展开的');
ok(await page.locator('#pane-invoice').isHidden(), '小票号那一栏收着');

console.log('\n【② 登录后那些「由 JS 填」的文字必须已经有内容】');
// 这两处没法用 data-i18n 静态标注（文案里带分钟数），只能 JS 填。
// 漏填的话按钮上一个字都没有，现场看到的是「按钮不见了」。
const hint = (await page.locator('#table-hint').textContent()).trim();
ok(hint.length > 0, `★★ 桌号提示语不是空的：「${hint}」`);
ok(/\d+/.test(hint), '  └ 里面带着分钟数');
const widen = (await page.locator('#btn-widen').textContent()).trim();
ok(widen.length > 0, `★★「放宽再找」按钮上有字：「${widen}」`);
ok(/\d+/.test(widen), '  └ 里面带着分钟数');

console.log('\n【③ 查一个真有单的桌号】');
await page.fill('#table-input', REAL_TABLE);
await page.click('#btn-locate');
await page.waitForSelector('#step-order.active', { timeout: 8000 });
ok(true, `★★ 桌 ${REAL_TABLE} 查得到，进到「选择订单」`);
const cards = await page.locator('#order-list .card').count();
ok(cards > 0, `列出了 ${cards} 张候选订单`);
const first = await page.locator('#order-list .card').first().textContent();
ok(/€/.test(first), `卡片上有金额：「${first.trim().split('\n')[0]}」`);
ok((await page.locator('#locate-err').getAttribute('hidden')) !== null, '没有报错');

console.log('\n【④ 选一单，走到记账方式】');
await page.locator('#order-list .card').first().click();
await page.waitForSelector('#step-mode.active', { timeout: 5000 });
ok(true, '进到「记账方式」');
const summary = await page.locator('#order-summary').textContent();
ok(/€/.test(summary), `摘要有可分配金额：「${summary.trim().split('\n')[0]}」`);
ok(await page.locator('.mode[data-mode="1"]').isVisible(), '三种记账方式都在');

console.log('\n【⑤ 查一个没单的桌号 → 降级入口要出现】');
await page.click('[data-back="step-order"]');
await page.waitForTimeout(200);
await page.click('[data-back="step-table"]');
await page.waitForSelector('#step-table.active', { timeout: 5000 });
await page.fill('#table-input', EMPTY_TABLE);
await page.click('#btn-locate');
await page.waitForTimeout(1200);

const err = (await page.locator('#locate-err').textContent()).trim();
ok(err.length > 0 && !/null|undefined|Cannot/.test(err),
   `★★ 提示的是业务原因，不是 JS 异常：「${err}」`);
ok(await page.locator('#locate-fallback').isVisible(),
   '★★ 降级入口出现了（这次事故里它俩就是这么消失的）');
ok(await page.locator('#btn-widen').isVisible(), '  └「放宽再找」可见');
ok(await page.locator('#btn-manual').isVisible(), '  └「改用手工录入」可见');

console.log('\n【⑥ 放宽再找，仍然能用】');
await page.click('#btn-widen');
await page.waitForTimeout(1200);
const err2 = (await page.locator('#locate-err').textContent()).trim();
ok(!/null|undefined|Cannot/.test(err2), `★ 放宽之后也没有 JS 异常：「${err2 || '(无错误)'}」`);

console.log('\n【⑦ 切到小票号那条路】');
await page.click('#tab-invoice');
await page.waitForTimeout(200);
ok(await page.locator('#pane-invoice').isVisible(), '小票号那一栏展开');
ok(await page.locator('#pane-table').isHidden(), '桌号那一栏收起');
await page.fill('#invoice-input', '999999999');
await page.click('#btn-locate-invoice');
await page.waitForTimeout(1200);
const err3 = (await page.locator('#locate-err').textContent()).trim();
ok(err3.length > 0 && !/null|undefined|Cannot/.test(err3),
   `★ 小票号查不到时提示的也是业务原因：「${err3}」`);

console.log('\n【⑧ 换成西语，整条路再走一遍】');
await page.click('#lang-main .lang-btn[data-lang=es]');
await page.waitForTimeout(600);
await page.click('#tab-table');
await page.waitForTimeout(200);
const hintEs = (await page.locator('#table-hint').textContent()).trim();
ok(hintEs.length > 0 && !/[一-龥]/.test(hintEs), `★ 提示语换成了西语：「${hintEs}」`);
const widenEs = (await page.locator('#btn-widen').textContent()).trim();
ok(widenEs.length > 0 && !/[一-龥]/.test(widenEs), `★「放宽」按钮也是西语：「${widenEs}」`);
ok(!/«/.test(hintEs + widenEs), '  └ 没有 «key» 这种漏翻译的痕迹');

await page.fill('#table-input', REAL_TABLE);
await page.click('#btn-locate');
await page.waitForSelector('#step-order.active', { timeout: 8000 });
ok(true, '★★ 西语界面下同样查得到单');
const cardEs = await page.locator('#order-list .card').first().textContent();
ok(!/«/.test(cardEs), '订单卡片上没有漏翻译的键');

// 切回中文，别给后面的测试留残留
await page.click('[data-back="step-table"]').catch(() => {});
await page.waitForTimeout(200);
await page.click('#lang-main .lang-btn[data-lang=zh]').catch(() => {});
await page.waitForTimeout(400);

console.log('\n【JS 错误】');
ok(errs.length === 0, errs.length ? '★★ 有报错：' + errs.join(' | ') : '全程无 JS 报错');

await browser.close();
console.log(`\n${'─'.repeat(50)}\n${fail === 0 ? '全部通过' : '失败 ' + fail}  ${pass + fail} 项\n`);
process.exit(fail ? 1 : 0);
