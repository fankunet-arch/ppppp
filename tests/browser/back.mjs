import { launch, BASE } from './_launch.mjs';
const B = BASE;
let pass = 0, fail = 0;
const ok = (c, m) => { c ? (pass++, console.log('  ✓ ' + m)) : (fail++, console.log('  ✗ ' + m)); };

const browser = await launch();
const ctx = await browser.newContext({ viewport: { width: 1280, height: 800 } });
const page = await ctx.newPage();
const errs = [];
page.on('pageerror', e => errs.push(String(e)));
await page.goto(B + '/', { waitUntil: 'networkidle' });

const cur    = () => page.evaluate(() => CURRENT_STEP);
const depth  = () => page.evaluate(() => history.length);
const back   = async () => { await page.goBack(); await page.waitForTimeout(150); };

console.log('\n【返回键：逐级后退】');
ok(await cur() === 'step-table', '起点是 step-table');
const h0 = await depth();

// 前进三步
await page.evaluate(() => step('step-order'));  await page.waitForTimeout(100);
await page.evaluate(() => step('step-mode'));   await page.waitForTimeout(100);
await page.evaluate(() => step('step-assign')); await page.waitForTimeout(100);
ok(await cur() === 'step-assign', '前进到 step-assign');
ok(await depth() > h0, `深层时历史里有哨兵（${h0} → ${await depth()}）`);

await back(); ok(await cur() === 'step-mode',   '返回 1 次 → step-mode');
await back(); ok(await cur() === 'step-order',  '返回 2 次 → step-order');
await back(); ok(await cur() === 'step-table',  '返回 3 次 → 回到起点 step-table');

// 回到起点后，哨兵应已收掉 —— 再按返回就轮到容器弹退出确认
await page.waitForTimeout(200);
const canGoBackNow = await page.evaluate(() => history.state && history.state.uiBack ? true : false);
ok(!canGoBackNow, '★ 回到起点后不再留哨兵（此时容器才该弹「确认退出」）');

console.log('\n【返回键：弹层优先于步骤】');
await page.evaluate(() => step('step-mode')); await page.waitForTimeout(100);
await page.evaluate(() => { $('#member-modal').hidden = false; UI.back.sync(); });
await page.waitForTimeout(150);
ok(await page.locator('#member-modal').isVisible(), '会员弹层已打开');
await back();
ok(await page.locator('#member-modal').isHidden(), '返回 → 先关弹层');
ok(await cur() === 'step-mode', '★ 关弹层时不动步骤（弹层排在更上层）');
await back();
ok(await cur() === 'step-order', '再返回 → 才退步骤');

console.log('\n【返回键：UI 弹层也接住】');
await page.evaluate(() => { window._p = UI.confirm('测试'); });
await page.waitForTimeout(150);
ok(await page.locator('.ui-ask').isVisible(), 'UI 弹层已打开');
await back();
await page.waitForTimeout(150);
ok(await page.locator('.ui-ask').isHidden(), '返回 → UI 弹层被关掉');
ok(await page.evaluate(() => window._p) !== undefined, '弹层的 Promise 正常 resolve（不悬挂）');
const v = await page.evaluate(() => window._p);
ok(v === false, '按返回等同于「取消」，返回 false');

console.log('\n【JS 错误】');
ok(errs.length === 0, errs.length ? '有报错：' + errs.join(' | ') : '无 JS 报错');

await browser.close();
console.log(`\n${'─'.repeat(50)}\n${fail === 0 ? '全部通过' : '失败 ' + fail}  ${pass + fail} 项\n`);
process.exit(fail ? 1 : 0);
