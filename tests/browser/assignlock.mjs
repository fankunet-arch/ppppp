/**
 * 第一界面就把话说清、把数字锁死。
 *
 * ── 为什么需要这个 ──────────────────────────────────
 * 现场截图：一张只剩 1 份的单，「份数」输入框里打进了 4。
 * 服务端当然会拒（exceeds_portions），但那要等到点【提交】才知道 ——
 * 客人就站在柜台前，服务员还得回头一行行找是哪里多了。
 *
 * 另一半是【看得见】：黄框原本只写「TK…••• · € 27.88」，
 * 而客人问的是「我这顿算上了吗」—— 在这套规则里「算上」等于
 * 拿到了一份计次套餐。只写金额的话，服务员得回上一步点开流水才答得出。
 *
 * 两道防线各管一件事，都要有：
 *   · 函数层（服务端）  —— 防有心之人：绕开界面直接调接口照样拒
 *   · 第一界面（Pad）   —— 防正常客人的追问：一眼看到、当场能答
 *
 * 需要先注入模拟活单：
 *   sudo SIM_DSN='mysql:unix_socket=/var/run/mysqld/mysqld.sock;dbname=sim_coolroid;charset=utf8' \
 *        SIM_USER=root SIM_PASS='' php tests/sim/inject_live.php
 */
import { launch, BASE } from './_launch.mjs';
import { readFileSync } from 'node:fs';
import { execSync } from 'node:child_process';

const REPO = '/home/user/ppppp';
let pass = 0, fail = 0;
const ok = (c, m) => { c ? (pass++, console.log('  \x1b[32m✓\x1b[0m ' + m)) : (fail++, console.log('  \x1b[31m✗\x1b[0m ' + m)); };

const php = (code) => execSync('php', { cwd: REPO, encoding: 'utf8', input: '<?php ' + code }).trim();
const setCfg = (k, v) => php(`require "app/bootstrap.php"; $c = require "app/config/config.php";
  (new Vip\\App($c))->cfg()->set(${JSON.stringify(k)}, ${JSON.stringify(String(v))});`);

const live = JSON.parse(readFileSync(REPO + '/tests/sim/live_orders.json', 'utf8'));
// 桌 9：154.75、6 份计次套餐。份数够多，先记掉一半还剩得下 ——
// 而且没有别的脚本用它（抢同一张单会把额度分光，见 README 第 10 条）
const ORD = live.find(o => /成人 2390 \+ 儿童 1490 混点/.test(o.desc || ''));
if (!ORD) {
  console.error('夹具里找不到需要的订单，请重新注入 tests/sim/inject_live.php');
  process.exit(1);
}

const TAG = 'AL' + Math.floor(Math.random() * 9000 + 1000);
const F = JSON.parse(php(`
  require "app/bootstrap.php";
  $c = require "app/config/config.php";
  $a = new Vip\\App($c);
  $b = $a->cards()->generateBatch("${TAG}", 2, date('Y-m-d', strtotime('+2 years')));
  $op = ['id' => 1, 'name' => 'browser-test', 'device' => 'TEST'];
  $r1 = $a->cardService()->bindNewMember($b[0]['display'], null, null, null, $op);
  $r2 = $a->cardService()->bindNewMember($b[1]['display'], null, null, null, $op);
  echo json_encode([
    'cardA' => $b[0]['display'], 'midA' => (int)$r1['member']['id'],
    'cardB' => $b[1]['display'], 'midB' => (int)$r2['member']['id'],
  ]);
`));

setCfg('visit_count_mode', 'once_per_period');
setCfg('late_grant_minutes', 0);
setCfg('max_grants_per_period', 0);
// 夹具按「几分钟前结账」注入，默认 30 分钟窗口一过就找不到单（README 第 10 条）
const WIN0 = php(`require "app/bootstrap.php"; $c = require "app/config/config.php";
  echo (new Vip\\App($c))->cfg()->get('order_lookup_window_min', '30');`);
setCfg('order_lookup_window_min', 180);

const browser = await launch();
const page = await (await browser.newContext({ viewport: { width: 1280, height: 900 } })).newPage();
const errs = [];
page.on('pageerror', e => errs.push(String(e)));

await page.goto(BASE + '/', { waitUntil: 'networkidle' });
await page.fill('#login-name', 'admin');
await page.fill('#login-pin', 'admin123');
await page.click('#btn-login');
await page.waitForSelector('#view-main.active', { timeout: 8000 });

const pickMember = async (i, card) => {
  await page.locator('#assign-people .person').nth(i).locator('button').first().click();
  await page.waitForSelector('#member-modal:not([hidden])', { timeout: 5000 });
  await page.fill('#member-input', card);
  await page.click('#btn-member-search');
  await page.waitForTimeout(900);
  await page.locator('#member-result button').first().click();
  await page.waitForTimeout(400);
};

/** 找单 → 选「均摊 AA」，停在还没分摊的那一刻 */
const toAssign = async () => {
  // 记完一单会停在「✓ 记账完成」，得先按「下一单」回到找单那一屏
  if (await page.locator('#step-done.active').count() > 0) {
    await page.click('#btn-new');
    await page.waitForTimeout(400);
  }
  await page.fill('#table-input', String(ORD.table));
  await page.click('#btn-locate');
  await page.waitForSelector('#step-order.active, #step-mode.active', { timeout: 8000 });
  if (await page.locator('#step-order.active').count() > 0) {
    await page.locator('#order-list button').first().click();
  }
  await page.waitForSelector('#step-mode.active', { timeout: 5000 });
  await page.click('.mode[data-mode="2"]');
  await page.waitForSelector('#step-assign.active', { timeout: 5000 });
  await page.waitForTimeout(300);
};
const doSplit = async (people) => {
  await page.fill('#aa-people', String(people));
  await page.click('#btn-aa');
  await page.waitForTimeout(800);
};

console.log('\n【① 先记掉一半，让这张单变成「记过一部分」的状态】');
await toAssign();
await doSplit(2);
const before = await page.evaluate(() => ({
  serial: S.order.serial_id,
  port:   S.order.remaining_portions,
  shares: S.people.map(p => ({ amt: p.amountCents, prt: p.portions })),
}));
ok(before.port >= 2 && before.shares[0].prt > 0,
   `这张单还剩 ${before.port} 份计次套餐，够拆成两半（第一位分到 ${before.shares[0].prt} 份）`);
// ★ 跑崩过的上一次会在这张单上留下已分配额，下一次跑就只剩零头 ——
//   看到这条红的先跑一次 README 第 10 条里的清理
ok(before.port === (await page.evaluate(() => Number(S.order.portions_total) || 0)),
   '  └ 这张单是干净的（没有上一次跑崩留下的已分配额）');
await pickMember(0, F.cardA);
// 第二位清空 —— 他没有卡，这一半留着不分
await page.fill('[data-amt="1"]', '0.00');
await page.fill('[data-prt="1"]', '0');
await page.waitForTimeout(250);
await page.click('#btn-submit');
await page.waitForSelector('#step-done.active', { timeout: 10000 });
ok(true, `A 记走 € ${(before.shares[0].amt / 100).toFixed(2)} / ${before.shares[0].prt} 份`);

console.log('\n【② 回到这张单：黄框要直接写出份数，不能只写金额】');
await toAssign();          // ★ 先不分摊 —— ③④ 要看的正是「还没分摊时就该看见」
const box = page.locator('#assign-done');
ok(!(await box.isHidden()), '★ 黄框「这张单已经记给」还在（这个标记是有用的，没被去掉）');
const boxText = (await box.textContent() || '').replace(/\s+/g, ' ').trim();

const maskA = F.cardA.slice(0, -3) + '•••';
ok(boxText.includes(maskA), `★★ 写出是哪张卡（打码）：${maskA}`);
ok(!boxText.includes(F.cardA), '  └ 不是完整卡号 —— 屏幕朝着柜台');
ok(new RegExp(`套餐\\s*${before.shares[0].prt}\\s*份`).test(boxText),
   `★★★ 直接写出【套餐 ${before.shares[0].prt} 份】已经计入这张卡 —— 客人问「我这顿算上了吗」当场能答`);
ok(/已计\s*1\s*次/.test(boxText),
   '★★★ 并且写出【已计 1 次】—— 同餐期第二单是记积分不计次，不写没人解释得清');
ok(/还剩/.test(boxText) && /份可分/.test(boxText),
   `★★ 黄框里就写着还剩多少可分：「${(boxText.match(/这张单还剩[^。]*/) || [''])[0]}」`);

const head = (await page.locator('#portion-detail').textContent() || '').replace(/\s+/g, ' ');
ok(/已分配/.test(head) && /还剩/.test(head),
   `★★ 顶上那行也带了「已分配 … 还剩 …」：「${head.trim().slice(0, 40)}」`);

console.log('\n【③ 名单里直接分出「能选人」和「已绑定」两种行】');
/**
 * ★ 这是这次改动的正题：服务员在这一屏要做的判断是
 *   「这一行我还能不能选人」，而不是「这张单发生过什么」。
 *   名单里全是清一色的「+ 选择会员」时，他只能靠自己数
 *   （一共 2 份、已分配 1 份、所以只剩 1 个位子……），忙起来就会数错。
 */
const locked = page.locator('#assign-people .person.locked');
ok(await locked.count() === 1, `★★★ 名单最上面有 1 行是【已绑定】的（灰底虚线，不是「+ 选择会员」）`);
const lockText = (await locked.first().textContent() || '').replace(/\s+/g, ' ').trim();
ok(lockText.includes(maskA), `★★★ 那一行直接写着绑的是哪张卡：${maskA}`);
ok(!lockText.includes(F.cardA), '  └ 依然是打码的卡号');
ok(new RegExp(`套餐\\s*${before.shares[0].prt}\\s*份`).test(lockText),
   `  └ 连份数一起写在行里（套餐 ${before.shares[0].prt} 份）`);
ok(await locked.first().locator('[data-pick]').count() === 0,
   '★★★ 这一行【没有「选择会员」按钮】—— 点不了，也就不会点错');
ok(await locked.first().locator('input').count() === 0,
   '  └ 金额和份数也不给输入框 —— 已经记掉的账不在这一屏改（要改回上一步撤销）');
ok((await locked.first().locator('.lockmark').textContent() || '').includes('已记入'),
   '  └ 并且挂一个「已记入 · 不可更改」的标记，不用猜为什么点不动');

ok(await page.locator('#assign-people .person:not(.locked)').count() === 0,
   '★★ 而且这一切在【按分摊之前】就看得见 —— 原来名单要先分摊一次才有东西');

console.log('\n【④ AA 默认人数要扣掉已经记掉的那几位】');
const aaVal = await page.locator('#aa-people').inputValue();
const cnum  = await page.evaluate(() => Number(S.order.customer_num) || 0);
ok(Number(aaVal) === Math.max(1, cnum - 1),
   `★★★ 买单 ${cnum} 人、已记掉 1 位 → 默认填 ${aaVal}（不是 ${cnum}）—— 否则一按分摊就多出一行用不上的`);

console.log('\n【⑤ 分摊之后：两种行并排，一眼分得开】');
await doSplit(1);
const pickable = page.locator('#assign-people .person:not(.locked)');
ok(await pickable.count() === 1, `★★ 分摊出 ${await pickable.count()} 行可选的`);
ok(await pickable.first().locator('[data-pick]').count() === 1,
   '★★★ 可选的那行有「+ 选择会员」，已绑定的那行没有 —— 服务员不用数，看一眼就知道点哪个');
ok(await locked.count() === 1, '  └ 已绑定那行还在，没被分摊冲掉');

console.log('\n【⑥ 份数输入框：打不进超额的数字】');
const left = await page.evaluate(() => Number(S.order.remaining_portions));
const maxAttr = await page.locator('[data-prt="0"]').getAttribute('max');
ok(String(left) === maxAttr, `★★ 输入框的 max 就是这张单剩余份数（${maxAttr}）`);

await page.fill('[data-prt="0"]', '9');
await page.waitForTimeout(300);
const after9 = await page.evaluate(() => S.people[0].portions);
ok(after9 === left,
   `★★★ 只剩 ${left} 份时打进 9 → 当场被改回 ${after9} —— 现场截图里就是这个（只剩 1 份却填了 4）`);
const tst = (await page.locator('#toast').textContent() || '').trim();
ok(/只剩/.test(tst), `  └ 并且说清为什么改的：「${tst}」`);

console.log('\n【⑦ 金额输入框：失焦时封顶（按键时不封，否则小数敲不出来）】');
await page.fill('[data-amt="0"]', '13.9');
await page.waitForTimeout(200);
ok(await page.evaluate(() => S.people[0].amountCents) === 1390,
   '★★ 敲小数的中间态不会被改写 —— 13.9 原样留着');

const capCents = await page.evaluate(() => Number(S.order.remaining_cents));
await page.fill('[data-amt="0"]', '999.00');
await page.locator('[data-prt="0"]').focus();      // 失焦
await page.waitForTimeout(300);
ok(await page.evaluate(() => S.people[0].amountCents) === capCents,
   `★★★ 打进 € 999 → 失焦时封回这张单的剩余额 € ${(capCents / 100).toFixed(2)}`);

console.log('\n【⑧ 函数层才是真的把关：绕开界面照样拒】');
/**
 * ★ 界面拦得住手滑，拦不住有心人 —— Pad 是柜台上一台安卓平板。
 *   上面那三道都是给正常客人和正常服务员省事的，
 *   真正的锁必须在服务端，这一条钉住它。
 */
const direct = await page.evaluate(async (mid) => {
  try {
    return await api('/points/grant', {
      serial_id: S.order.serial_id, mode: 2,
      allocations: [{ member_id: mid, amount: '1.00', portions: 99 }],
    });
  } catch (e) { return { thrown: true, error: e.error || '', message: e.message || String(e) }; }
}, F.midB);
ok(direct && direct.error === 'exceeds_portions',
   `★★★ 直接调接口要 99 份 → 服务端拒（${direct && (direct.error || 'ok?!')}）`);

const dup = await page.evaluate(async (mid) => {
  try {
    return await api('/points/grant', {
      serial_id: S.order.serial_id, mode: 2,
      allocations: [{ member_id: mid, amount: '1.00', portions: 1 }],
    });
  } catch (e) { return { thrown: true, error: e.error || '', message: e.message || String(e) }; }
}, F.midA);
ok(dup && dup.error === 'member_already_on_order',
   `★★★ 同一张卡再记一次 → 服务端拒（${dup && (dup.error || 'ok?!')}）`);

console.log('\n【⑨ 会员数上限：不能无限添加】');
/**
 * ★ 一张单最多记几位 = 计次套餐的份数。
 *   不封的话，一张 € 200 的单可以拆给十张卡 ——
 *   而其中大部分人根本没来过。份数是这张单上
 *   「有几个人在这儿吃了饭」唯一可信的凭据。
 */
const cap = await page.evaluate(() => Number(S.order.portions_counted) || 0);
const aaMax = await page.locator('#aa-people').getAttribute('max');
ok(Number(aaMax) === cap - 1,
   `★★★ AA 人数框的 max 是 ${aaMax}（份数 ${cap} 减掉已记掉的 1 位）—— 原来写死 50`);

await page.fill('#aa-people', '40');
await page.waitForTimeout(300);
ok(await page.locator('#aa-people').inputValue() === String(cap - 1),
   `★★★ 打进 40 → 当场改回 ${cap - 1}`);

await doSplit(cap - 1);
const addBtn = page.locator('#assign-people > button.ghost');
ok(await addBtn.isDisabled(),
   '★★★ 已经排满时「添加会员」变灰点不动 —— 按钮在那儿但一点就报错，等于让人做完再挨骂');
ok(/最多记/.test((await addBtn.textContent()) || ''),
   `  └ 并且按钮上直接写出上限：「${(await addBtn.textContent() || '').trim()}」`);

await doSplit(1);
ok(!(await addBtn.isDisabled()), '  └ 只排 1 位时还能继续添加（没排满就不该拦）');

console.log('\n【⑩ 没有付费套餐的单：只准 1 位，并且要说清为什么】');
/**
 * ★ 用页内层而不是系统 alert —— 容器里的原生 alert 要么被吞掉，
 *   要么长得像浏览器警告，而屏幕正朝着客人。
 */
const NOMENU = live.find(o => /缺套餐行/.test(o.desc || ''));
ok(!!NOMENU, `夹具里有一张没有计次套餐的单（桌 ${NOMENU && NOMENU.table}）`);
// 回到找单那一屏。这里不在「记账完成」上，所以按 #btn-new 是点不到的 ——
// 顺着「返回」一路退回去，跟收银员实际会做的一样
while (await page.locator('#step-table.active').count() === 0) {
  const back = page.locator('.step.active [data-back]').first();
  if (await back.count() === 0) { break; }
  await back.click();
  await page.waitForTimeout(250);
}
await page.waitForSelector('#step-table.active', { timeout: 5000 });
await page.fill('#table-input', String(NOMENU.table));
await page.click('#btn-locate');
await page.waitForSelector('#step-order.active, #step-mode.active', { timeout: 8000 });
if (await page.locator('#step-order.active').count() > 0) {
  await page.locator('#order-list button').first().click();
}
await page.waitForSelector('#step-mode.active', { timeout: 5000 });
await page.click('.mode[data-mode="2"]');
await page.waitForSelector('#step-assign.active', { timeout: 5000 });
await page.waitForTimeout(500);

const ask = page.locator('.ui-ask');
ok(await ask.count() === 1 && !(await ask.isHidden()),
   '★★★ 弹出的是【页内层】.ui-ask，不是系统 alert（系统弹窗在容器里会被吞掉）');
const askHi = (await page.locator('.ui-ask-hi').textContent() || '').trim();
ok(/没有付费套餐/.test(askHi), `★★★ 一句话说清：「${askHi}」`);
const askMsg = (await page.locator('.ui-ask-msg').textContent() || '').trim();
ok(/只能记 1 位/.test(askMsg) && /不计次/.test(askMsg),
   `  └ 正文写明只准 1 位、这次不计次：「${askMsg.slice(0, 40)}…」`);
ok(await page.locator('.ui-ask .ui-cancel').isHidden(),
   '★★ 只有一个「知道了」，没有「取消」—— 点了等于没点的按钮比没有更糟');

await page.click('.ui-ask .ui-ok');
await page.waitForTimeout(300);
ok(await ask.isHidden(), '  └ 点掉之后关上');

const cap0 = await page.locator('#aa-people').getAttribute('max');
ok(cap0 === '1', `★★★ 0 份的单 AA 人数上限就是 1（实际 ${cap0}）`);

// 再进一次不该重复弹 —— 每回都弹，收银员会养成闭眼点确定的习惯
await page.click('[data-back="step-mode"]');
await page.waitForTimeout(200);
await page.click('.mode[data-mode="2"]');
await page.waitForTimeout(500);
ok(await ask.isHidden(), '★★ 同一张单来回切不重复弹 —— 每回都弹就会被闭着眼点掉');

console.log('\n【JS 错误】');
ok(errs.length === 0, errs.length ? '有 JS 报错：' + errs.join(' | ') : '无 JS 报错');

php(`
  require "app/bootstrap.php";
  $c = require "app/config/config.php";
  $a = new Vip\\App($c);
  $db = $a->localDb();
  foreach ([${F.midA}, ${F.midB}] as $id) {
    $db->exec('DELETE FROM point_ledger WHERE member_id = ?', [$id]);
    $db->exec('DELETE FROM coupon       WHERE member_id = ?', [$id]);
  }
  $db->exec('UPDATE pos_order SET allocated_amount = 0, allocated_portions = 0
              WHERE store_code = ? AND serial_id = ?', [$c['store_code'], '${before.serial}']);
  $db->exec('DELETE FROM card WHERE batch_no = ?', ['${TAG}']);
  foreach ([${F.midA}, ${F.midB}] as $id) { $db->exec('DELETE FROM member WHERE id = ?', [$id]); }
`);
setCfg('order_lookup_window_min', WIN0);

await browser.close();
console.log('\n──────────────────────────────────────────────────');
console.log(fail ? `\x1b[31m失败 ${fail}\x1b[0m / 共 ${pass + fail} 项` : `\x1b[32m全部通过\x1b[0m  ${pass} 项`);
process.exit(fail ? 1 : 0);
