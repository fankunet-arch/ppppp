import { launch, BASE } from './_launch.mjs';

let pass = 0, fail = 0;
const ok = (c, m) => { c ? (pass++, console.log('  \x1b[32m✓\x1b[0m ' + m)) : (fail++, console.log('  \x1b[31m✗\x1b[0m ' + m)); };

const browser = await launch();
const ctx  = await browser.newContext({ viewport: { width: 1280, height: 900 } });
const page = await ctx.newPage();
const errs = [];
page.on('pageerror', e => errs.push(String(e)));

const BATCH = 'PW' + Math.floor(Math.random() * 9000 + 1000);

/**
 * 等某条特定内容的 toast 出现。
 *
 * 不能只等「#toast 可见」——它是全局共享的一个元素，上一条提示有 3 秒
 * 存活期，新动作发生时它往往还挂在那里，于是断言读到的是旧文本。
 * （这条测试第一版就栽在这：作废明明成功了，却读到上一步「批次已生成」。）
 */
async function waitToast(re) {
  await page.waitForFunction((src) => {
    const t = document.querySelector('#toast');
    return t && !t.hidden && new RegExp(src).test(t.textContent || '');
  }, re.source, { timeout: 5000 });
  return page.locator('#toast').textContent();
}

/** 清掉当前 toast。必须在触发动作【之前】调用 —— 之后调用会抹掉刚弹出的那条 */
async function clearToast() {
  await page.evaluate(() => {
    const t = document.querySelector('#toast');
    if (t) { t.hidden = true; t.textContent = ''; }
  });
}

console.log('\n【后台发卡：登录 → 生成批次 → 查卡 → 作废】');
await page.goto(BASE + '/cp/', { waitUntil: 'networkidle' });
await page.fill('#login-name', 'admin');
await page.fill('#login-pin', 'admin123');
await page.click('#btn-login');
await page.waitForSelector('#view-main.active', { timeout: 5000 });
ok(true, '登录成功');

await page.click('[data-tab="cards"]');
await page.waitForSelector('#card-stats .stat', { timeout: 5000 });
const prefix = await page.locator('#card-stats .stat b').nth(4).textContent();
ok(prefix === 'TK', `卡号前缀显示为 ${prefix}`);

// ── 有效期是必填，且必须晚于今天 ──
// 卡面那行日期是客人唯一能看到的告知，漏印或印成过去的日期，
// 整批卡的合规基础就没了，而且是印完才发现。所以两道校验都得挡住。
const VALID_TO = new Date(Date.now() + 730 * 864e5).toISOString().slice(0, 10);
await page.click('#cd-gen-box summary');
await page.fill('#cd-batch', BATCH);
await page.fill('#cd-count', '5');

/**
 * ★ 有效期这一格现在【默认填好 2 年后的 12 月 31 日】，
 *   所以要先清空才测得到「不填就不让生成」这条路。
 *   默认值本身在 carddate.mjs 里验。
 */
await page.fill('#cd-valid', '');
await clearToast();
await page.click('#btn-card-gen');
ok(/必须填写有效期/.test(await waitToast(/有效期/)), '★ 不填有效期不让生成');
ok(await page.locator('.ui-ask').evaluate(el => el.hidden).catch(() => true),
   '被挡住时连确认框都不弹');

await page.fill('#cd-valid', new Date(Date.now() - 864e5).toISOString().slice(0, 10));
await clearToast();
await page.click('#btn-card-gen');
ok(/晚于今天/.test(await waitToast(/晚于今天/)), '★ 有效期填成过去的日期也不让生成');

// ── 生成批次 ──
await page.fill('#cd-valid', VALID_TO);
await page.click('#btn-card-gen');

// 两道确认：先核对日期，再警告明文 PIN 只显示这一次
await page.waitForSelector('.ui-ask:not([hidden])', { timeout: 3000 });
const dateConfirm = await page.locator('.ui-ask-msg').textContent();
ok(/核对一次有效期/.test(dateConfirm), '★ 先单独核对一遍有效期（印错要整批重印）');
ok(dateConfirm.includes(VALID_TO), `确认框里回显的是填的日期（${VALID_TO}）`);
await page.click('.ui-ok');

await page.waitForFunction(
  (d) => {
    const el = document.querySelector('.ui-ask-msg');
    return el && !document.querySelector('.ui-ask').hidden && !el.textContent.includes('核对一次有效期');
  }, null, { timeout: 3000 });
const warnText = await page.locator('.ui-ask-msg').textContent();
ok(/唯一出现的时刻|再也取不回来/.test(warnText), '★ 生成前明确警告 PIN 只显示这一次');
ok(warnText.includes(VALID_TO), '第二道确认里也带着有效期');
await page.click('.ui-ok');

await page.waitForSelector('#card-gen-result:not([hidden])', { timeout: 5000 });
const csv = await page.locator('#card-gen-csv').inputValue();
const lines = csv.trim().split('\n');
ok(lines.length === 6, `清单含表头 + 5 行（实得 ${lines.length}）`);
ok(lines[0] === '卡号\t二维码内容\tPIN\t有效期至\t等级',
   '表头是五列，制表符分隔（可直接粘进 Excel）');

const [display, qr, pin, validCol, tierCol] = lines[1].split('\t');
ok(/^TK-\d{8}-[0-9A-Z]{3}$/.test(display), `卡号是印刷分组形式（${display}）`);
ok(qr === display.replace(/-/g, ''), '二维码内容是去掉连字符的纯卡号');
ok(/^\d{6}$/.test(pin), `PIN 是 6 位数字（${pin}）`);
ok(validCol === VALID_TO, '★ 清单里每一行都带着有效期 —— 印刷稿按这一列排版');
ok(lines.slice(1).every(l => l.split('\t')[3] === VALID_TO), '整批 5 张的有效期一致');
// 这一批没选等级 —— 等级列该是空的，而不是「不分级」三个字：
// 印刷厂拿到的是排版依据，空就是空，别让他们照着印上去
ok(tierCol === '', '★ 没选等级时那一列是空的（印刷厂按这一列排版，别印上多余的字）');

const pins = lines.slice(1).map(l => l.split('\t')[2]);
ok(new Set(pins).size === pins.length, '5 张卡的 PIN 互不相同');

const warnBox = await page.locator('#card-gen-warn').textContent();
ok(/总钥匙|销毁/.test(warnBox), '★ 清单上方有「印完销毁」的警告');

// ── 批次列表 ──
const batchRow = await page.locator('#card-batches table tr', { hasText: BATCH }).textContent();
ok(batchRow.includes('5'), `批次 ${BATCH} 出现在列表里，共 5 张`);
ok(batchRow.includes(VALID_TO), '★ 批次列表里能看到这批卡印的是哪个有效期');

// ── 查卡 ──
await page.click('details:has(#cd-look) summary');
await page.fill('#cd-look', display);
await page.click('#btn-card-look');
await page.waitForSelector('#card-look-result table', { timeout: 5000 });
let look = await page.locator('#card-look-result').textContent();
ok(/库存中/.test(look), '新卡状态显示「库存中，尚未发给客人」');
ok(look.includes(BATCH), '显示所属批次');
ok(look.includes(VALID_TO), '查卡结果里带着有效期（客人来问时照着念）');

// 手输把 0 打成 O 也要能查到
await page.fill('#cd-look', display.replace(/0/g, 'O'));
await page.click('#btn-card-look');
await page.waitForTimeout(400);
look = await page.locator('#card-look-result').textContent();
ok(look.includes(display), '★ 手输把 0 打成 O 也能查到同一张卡');

// ── 作废 ──
await page.click('details:has(#cd-void) summary');
await page.fill('#cd-void', display);
await page.fill('#cd-void-why', '联调测试');
await clearToast();
await page.click('#btn-card-void');
await page.waitForSelector('.ui-ask:not([hidden])', { timeout: 3000 });
ok(await page.locator('.ui-ok').evaluate(el => el.classList.contains('ui-danger')), '作废是危险操作，红色按钮');
await page.click('.ui-ok');
let toastTxt = '';
try { toastTxt = await waitToast(/已作废/); } catch (e) { toastTxt = '(超时未出现)'; }
ok(/已作废/.test(toastTxt), `作废成功（toast: ${toastTxt}）`);

await page.fill('#cd-look', display);
await page.click('#btn-card-look');
await page.waitForTimeout(400);
look = await page.locator('#card-look-result').textContent();
ok(/已作废|挂失/.test(look), '再查该卡显示已作废');
ok(/联调测试/.test(look), '显示作废原因');

console.log('\n【JS 错误】');
ok(errs.length === 0, errs.length ? '有报错：' + errs.join(' | ') : '无 JS 报错');

await browser.close();
console.log(`\n${'─'.repeat(50)}\n${fail === 0 ? '全部通过' : '失败 ' + fail}  ${pass + fail} 项`);
console.log(`（本次生成的批次：${BATCH}）\n`);
process.exit(fail ? 1 : 0);
