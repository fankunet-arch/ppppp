/**
 * 发卡时的有效期：默认值与「距今多少天」。
 *
 * 卡面上那行日期是唯一的告知证据 —— 印错了整批重印，而且是印完才发现。
 * 但日期本身「对不对」是看不出来的：2028 还是 2029，盯着看也就那样。
 *
 * 换算成天数就不一样了：记错一年，数字差出三四百天，一眼就不对。
 * 这一整套就是给「脑子一时反应错」准备的，不是给系统校验准备的
 * （系统只拦「早于今天」，别的一律放行 —— 店家想印什么日期是店家的事）。
 */
import { launch, BASE } from './_launch.mjs';

let pass = 0, fail = 0;
const ok = (c, m) => { c ? (pass++, console.log('  \x1b[32m✓\x1b[0m ' + m)) : (fail++, console.log('  \x1b[31m✗\x1b[0m ' + m)); };

const browser = await launch();
const page = await (await browser.newContext({ viewport: { width: 1280, height: 900 } })).newPage();
const errs = [];
page.on('pageerror', e => errs.push(String(e)));

await page.goto(BASE + '/cp/', { waitUntil: 'networkidle' });
await page.fill('#login-name', 'admin');
await page.fill('#login-pin', 'admin123');
await page.click('#btn-login');
await page.waitForSelector('#view-main.active', { timeout: 8000 });
await page.click('[data-tab="cards"]');
await page.waitForSelector('#cd-valid', { state: 'attached', timeout: 5000 });
await page.waitForTimeout(800);
// 生成表单收在 <details> 里，展开才点得到
const openGen = () => page.evaluate(() => { document.querySelector('#cd-gen-box').open = true; });
await openGen();
await page.waitForTimeout(200);

console.log('\n【① 默认填 2 年后的 12 月 31 日】');
const thisYear = new Date().getFullYear();
const want = `${thisYear + 2}-12-31`;
ok(await page.locator('#cd-valid').inputValue() === want,
   `★★ 打开发卡页就已经填好 ${want}`);

console.log('\n【② 只是预填，不锁定】');
const el = page.locator('#cd-valid');
ok(await el.isDisabled() === false && await el.getAttribute('readonly') === null,
   '★ 输入框既没禁用也不是只读');
ok(await el.getAttribute('min') === null && await el.getAttribute('max') === null,
   '★★ 没有设 min/max —— 更早的日期照填不误，店家想印什么是店家的事');
// 真的改成一个更早的日期
const soon = new Date(Date.now() + 100 * 86400000).toISOString().slice(0, 10);
await el.fill(soon);
await page.waitForTimeout(200);
ok(await el.inputValue() === soon, `★★ 能改成更早的日期（${soon}）`);

console.log('\n【③ 距今多少天 —— 改一下就跟着变，不用等点生成】');
const hint = page.locator('#cd-valid-hint');
ok(/距今 100 天/.test(await hint.textContent()),
   `★★ 旁边红字跟着改：「${(await hint.textContent()).trim()}」`);
ok(await hint.evaluate(e => getComputedStyle(e).color) !== 'rgb(0, 0, 0)', '  └ 是红的，不是正文黑');

// 年份打错 —— 天数差出好几倍，一眼看得出来
await el.fill(`${thisYear + 2}-12-31`);
await page.waitForTimeout(200);
const h2 = (await hint.textContent()).trim();
await el.fill(`${thisYear + 12}-12-31`);
await page.waitForTimeout(200);
const h3 = (await hint.textContent()).trim();
ok(/约 2 年/.test(h2) && /约 12 年/.test(h3),
   `★★★ 年份打错时那句话完全不同：「${h2}」 vs 「${h3}」—— 这正是它要挡的错`);

console.log('\n【③bis 天数换算的边界】');
/**
 * 直接喂天数给 validityHint，绕开日期输入 —— 这些边界靠点界面凑不出来。
 * 余数四舍五入会凑满 12 个月（725 天 → 1 年 12 个月），必须进位成 2 年，
 * 不然屏幕上会出现「约 1 年 12 个月」这种一看就是程序写错了的东西。
 */
const cases = await page.evaluate(() => {
  const fromDays = (n) => {
    const d = new Date();
    d.setDate(d.getDate() + n);
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
  };
  return [0, 1, 100, 364, 365, 725, 730, 1095].map(n => [n, validityHint(fromDays(n))]);
});
const say = Object.fromEntries(cases);
ok(/就是今天/.test(say[0]), `★ 0 天说「就是今天」而不是「距今 0 天」：「${say[0]}」`);
ok(say[100] === '距今 100 天', `★ 不满一年就只说天数：「${say[100]}」`);
ok(!/12 个月/.test(say[725]) && /约 2 年/.test(say[725]),
   `★★ 725 天进位成「约 2 年」，不出现「1 年 12 个月」：「${say[725]}」`);
ok(/约 1 年/.test(say[365]) && /约 3 年/.test(say[1095]),
   `★ 整年数对得上：365→「${say[365]}」　1095→「${say[1095]}」`);
ok(cases.every(([, t]) => !/NaN|undefined|Infinity/.test(t)),
   '★★ 没有一条冒出 NaN / undefined（日期算错时最常见的样子）');

console.log('\n【④ 核对弹窗里也有，而且是红色单独一行】');
await el.fill(`${thisYear + 2}-12-31`);
await page.waitForTimeout(200);
await page.fill('#cd-count', '1');
await page.click('#btn-card-gen');
await page.waitForSelector('.ui-ask:not([hidden])', { timeout: 3000 });
const msg = await page.locator('.ui-ask-msg').textContent();
const hi  = await page.locator('.ui-ask-hi');
ok(new RegExp(`${thisYear + 2}-12-31`).test(msg), '★ 弹窗里摆出日期');
ok(/整批卡都得重印/.test(msg), '  └ 并且说清印错的代价');
ok(await hi.isVisible() && /距今 \d+ 天/.test(await hi.textContent()),
   `★★ 距今天数单独一行：「${(await hi.textContent()).trim()}」`);
ok(await hi.evaluate(e => getComputedStyle(e).color) !== 'rgb(0, 0, 0)', '  └ 红色的');
const order = await page.evaluate(() => {
  const box = document.querySelector('.ui-ask-box');
  const kids = Array.from(box.children);
  return kids.indexOf(box.querySelector('.ui-ask-hi')) > kids.indexOf(box.querySelector('.ui-ask-msg'));
});
ok(order, '★★ 红字排在日期【下面】—— 紧挨着，不用在整段话里找');

// 取消，别真生成
await page.click('.ui-cancel');
await page.waitForTimeout(400);
ok(await page.locator('#card-gen-result').isHidden(), '取消后没有生成');

console.log('\n【⑤ 已经填过就别动它】');
/**
 * 切个标签页回来，把人家改好的日期冲掉，是最招人烦的那种「智能」。
 */
await el.fill(`${thisYear + 5}-06-15`);
await page.click('[data-tab="dashboard"]');
await page.waitForTimeout(400);
await page.click('[data-tab="cards"]');
await page.waitForTimeout(900);
await openGen();
ok(await page.locator('#cd-valid').inputValue() === `${thisYear + 5}-06-15`,
   '★★ 回到发卡页，自己改的日期还在（默认值只在空着时才填）');

console.log('\n【⑥ 早于今天的日期：红字直说，服务端也不收】');
const past = new Date(Date.now() - 5 * 86400000).toISOString().slice(0, 10);
await page.locator('#cd-valid').fill(past);
await page.waitForTimeout(200);
ok(/已经过期/.test(await hint.textContent()),
   `★ 红字直说过期了：「${(await hint.textContent()).trim()}」`);
await page.click('#btn-card-gen');
await page.waitForTimeout(500);
const t = (await page.locator('#toast').textContent()) || '';
ok(/晚于今天/.test(t), `★★ 而且拦住不让生成：「${t.trim()}」`);

console.log('\n【JS 错误】');
ok(errs.length === 0, errs.length ? '有报错：' + errs.join(' | ') : '无 JS 报错');

await browser.close();
console.log(`\n${'─'.repeat(50)}\n${fail === 0 ? '全部通过' : '失败 ' + fail}  ${pass + fail} 项\n`);
process.exit(fail ? 1 : 0);
