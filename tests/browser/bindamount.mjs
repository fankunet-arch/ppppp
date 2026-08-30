/**
 * 份数与金额绑在一起：0 元不能只记次数。
 *
 * ── 这是怎么被发现的 ────────────────────────────────
 * 店主自己推演出来的：
 *
 *   一单 € 71.70 / 3 份，A 先把金额【全部】拿走 → 积分 71、计次 1。
 *   B 再提交「金额 € 0、要 1 份」—— 金额没超、份数没超，
 *   守恒校验一路放行，B 白拿一次计次。
 *
 * 次数才是奖励的真正来源（十送一），金额分完之后份数还剩着，
 * 等于把最值钱的那一半白送出去。
 *
 * ── 这个文件守什么 ──────────────────────────────────
 * 服务端 portions_without_amount 才是真正的把关（AllocationTest / smoke ㉕ 覆盖）。
 * 这里守的是【收银员那一侧】：
 *   · 点提交之前就被拦住，并且当场说清楚是哪一位、该怎么改
 *   · 屏幕上出现的卡号是打过码的（屏幕朝着柜台）
 *   · 改好之后照样能提交 —— 挡的是白拿，不是拆分
 *   · 绕开界面直接调接口，服务端照样拒
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
/**
 * ★ 挑单有两个硬条件：
 *   ① 计次份数 ≥ 2 —— AA 拆两人时两边都得分到份数，
 *      否则第二位天生 0 份，这条规则根本触发不到，测出来是个假的绿。
 *   ② 避开 visitcount.mjs 用的那两桌（40 / 52）——
 *      提交会把订单额度分掉，两边会互相干扰。
 *   桌 10 那张大单：87.40、4 份计次套餐，两条都满足。
 */
const ORD = live.find(o => /大单 46 行明细/.test(o.desc || ''));
if (!ORD) {
  console.error('夹具里找不到需要的订单，请重新注入 tests/sim/inject_live.php');
  process.exit(1);
}

const TAG = 'BA' + Math.floor(Math.random() * 9000 + 1000);
const F = JSON.parse(php(`
  require "app/bootstrap.php";
  $c = require "app/config/config.php";
  $a = new Vip\\App($c);
  $b = $a->cards()->generateBatch("${TAG}", 3, date('Y-m-d', strtotime('+2 years')));
  $op = ['id' => 1, 'name' => 'browser-test', 'device' => 'TEST'];
  $r1 = $a->cardService()->bindNewMember($b[0]['display'], null, null, null, $op);
  $r2 = $a->cardService()->bindNewMember($b[1]['display'], null, null, null, $op);
  // C 全程不出现在界面上，只用来做「绕开界面直接调接口」那一条 ——
  // 用 A 或 B 会先撞上 member_already_on_order，测不到这一条规则
  $r3 = $a->cardService()->bindNewMember($b[2]['display'], null, null, null, $op);
  echo json_encode([
    'cardA' => $b[0]['display'], 'midA' => (int)$r1['member']['id'],
    'cardB' => $b[1]['display'], 'midB' => (int)$r2['member']['id'],
    'cardC' => $b[2]['display'], 'midC' => (int)$r3['member']['id'],
  ]);
`));

setCfg('visit_count_mode', 'once_per_period');
setCfg('late_grant_minutes', 0);
setCfg('max_grants_per_period', 0);
/**
 * ★ 把找单窗口放宽到 3 小时。
 *
 * 夹具是按「几分钟前结账」注入的，默认 30 分钟的窗口一过就找不到单，
 * 于是用例会随着「注入后过了多久」时绿时红 —— 而那和代码对不对无关。
 * 窗口本来就是店家可调的配置，这里调大只是让用例不去赌时间。
 * 跑完在末尾恢复原值。
 */
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

/** 找单 → 均摊 AA 两人 → 两位都选上卡 */
const doSplitN = async (n) => {
  await page.fill('#aa-people', String(n));
  await page.click('#btn-aa');
  await page.waitForTimeout(800);
};

const pickMember = async (i, card) => {
  // ★ 只数【可编辑】的行：已绑定那几行排在最前面，而且没有按钮
  await page.locator('#assign-people .person:not(.locked)').nth(i).locator('button').first().click();
  await page.waitForSelector('#member-modal:not([hidden])', { timeout: 5000 });
  await page.fill('#member-input', card);
  await page.click('#btn-member-search');
  await page.waitForTimeout(900);
  await page.locator('#member-result button').first().click();
  await page.waitForTimeout(400);
};

await page.fill('#table-input', String(ORD.table));
await page.click('#btn-locate');
await page.waitForSelector('#step-order.active, #step-mode.active', { timeout: 8000 });
if (await page.locator('#step-order.active').count() > 0) {
  await page.locator('#order-list button').first().click();
}
await page.waitForSelector('#step-mode.active', { timeout: 5000 });
await page.click('.mode[data-mode="2"]');
await page.waitForSelector('#step-assign.active', { timeout: 5000 });
await page.fill('#aa-people', '2');
await page.click('#btn-aa');
await page.waitForTimeout(800);
await pickMember(0, F.cardA);
await pickMember(1, F.cardB);

const SERIAL = await page.evaluate(() => S.order.serial_id);
const shares = await page.evaluate(() => S.people.map(p => ({ amt: p.amountCents, prt: p.portions })));
ok(shares.length === 2 && shares.every(s => s.amt > 0),
   `AA 两人各分到 € ${(shares[0].amt / 100).toFixed(2)} / € ${(shares[1].amt / 100).toFixed(2)}`);
ok(shares.some(s => s.prt > 0), `  └ 并且真的有份数（${shares.map(s => s.prt).join(' + ')} 份），这条规则才触发得到`);

console.log('\n【① 把第二位的金额清成 0，份数留着】');
// 第一位补上全部金额 —— 模拟「钱都算在 A 头上了，B 只是想蹭一次」
await page.fill('[data-amt="0"]', ((shares[0].amt + shares[1].amt) / 100).toFixed(2));
await page.fill('[data-amt="1"]', '0.00');
await page.waitForTimeout(200);

const canSubmit = await page.evaluate(() => !document.querySelector('#btn-submit').disabled);
ok(canSubmit, '提交按钮仍然是可点的 —— 合计金额没变，守恒那一层看不出问题');

await page.click('#btn-submit');
await page.waitForTimeout(600);

const errText = (await page.locator('#assign-err').textContent() || '').trim();
ok(errText.length > 0, `★★★ 点提交被当场拦住：「${errText}」`);
ok(await page.locator('#step-done.active').count() === 0,
   '  └ 没有走到「完成」那一步（既没记积分，也没记次数）');

console.log('\n【② 提示里要说清楚是哪一位，而且卡号打过码】');
const maskB = F.cardB.slice(0, -3) + '•••';
ok(errText.includes(maskB), `★★ 提示里指名了是哪一张卡：${maskB}`);
ok(!errText.includes(F.cardB),
   '★★ 但不是完整卡号 —— 屏幕朝着柜台，末尾 3 位随机码不能露出来');

console.log('\n【③ 反面：有钱却没份数 —— 提醒，但不拦】');
/**
 * ★ 这一面的代价是【客人吃亏】：积分照样进卡、小票照样打，
 *   只是这一次没盖章，而且事后没有任何地方会报出来。
 *   所以只提醒不拦 —— 它常常是对的（只点酒水的客人）。
 *
 * ★ AA 模式下份数框已经锁死（每人固定 1 份），这个形状在这一屏
 *   点不出来了 —— 但它仍然会从【点选菜品】模式来：
 *   只认领了酒水的客人，份数天然是 0。
 *   所以这里直接把那个状态摆出来，测的是「提醒会不会挂出来」，
 *   而不是「怎么走到那个状态」。
 */
await page.click('[data-back="step-mode"]');
await page.waitForSelector('#step-mode.active', { timeout: 5000 });
await page.click('.mode[data-mode="2"]');
await page.waitForSelector('#step-assign.active', { timeout: 5000 });
await doSplitN(2);

ok(await page.locator('#assign-noportion').isHidden(), 'AA 刚拆好时不提醒 —— 两位都分到了份数');

await page.evaluate(() => { S.people[1].portions = 0; updateTotals(); });
await page.waitForTimeout(250);
const hint = (await page.locator('#assign-noportion').textContent() || '').trim();
ok(hint.length > 0 && !(await page.locator('#assign-noportion').isHidden()),
   `★★★ 出现「有金额但 0 份」的人 → 当场挂出提醒：「${hint.slice(0, 46)}…」`);
ok(!(await page.evaluate(() => document.querySelector('#btn-submit').disabled)),
   '  └ 但提交按钮照样能点 —— 这一条是提醒，不是拦截（只点酒水的客人本来就是这样）');
ok(await page.locator('#assign-noportion.hint-warn').count() === 1,
   '  └ 用的是橙色提醒样式，不是红色报错 —— 两者混成一档，几天后两种都没人看');

await page.evaluate(() => { S.people[1].portions = 1; updateTotals(); });
await page.waitForTimeout(250);
ok(await page.locator('#assign-noportion').isHidden(), '  └ 份数补回去，提醒就消失');

console.log('\n【④ 人数封顶：一张单最多记到份数那么多位】');
/**
 * ★ 份数除不尽时怎么摊（[3,3,2,2] 而不是 [4,2,2,2]），
 *   由纯函数用例 AllocationTest 和 smoke ㉕⑦ 端到端钉着 ——
 *   浏览器这一侧测不出新旧差别（4 份 3 人两种写法都给 [2,1,1]）。
 *
 *   这里改测浏览器【独有】的那一半：人数根本填不进超过份数的数字。
 *   不封的话，一张 € 200 的单可以拆给十张卡，
 *   而其中大部分人根本没来过这家店。
 */
const cap = await page.evaluate(() => Number(S.order.portions_counted) || 0);
ok(await page.locator('#aa-people').getAttribute('max') === String(cap),
   `★★★ AA 人数框的 max 就是这张单的计次份数（${cap}）—— 原来写死 50`);

await page.fill('#aa-people', String(cap + 6));
await page.waitForTimeout(300);
ok(await page.locator('#aa-people').inputValue() === String(cap),
   `★★★ 打进 ${cap + 6} → 当场改回 ${cap}，服务员想拆也拆不出来`);

await doSplitN(cap);
ok(await page.evaluate(() => S.people.length) === cap, `按 ${cap} 人分摊出 ${cap} 行`);
const prts = await page.evaluate(() => S.people.map(p => p.portions));
ok(prts.every(n => n > 0),
   `★★ ${cap} 份 ${cap} 人 → [${prts.join(',')}]，每一位都分到了份数（没人白吃）`);

console.log('\n【⑤ 把餐费分给他，就能正常提交】');
// 回到两人 AA。★ 重拆一次 S.people 会被整个重建，会员选择也跟着没了 ——
//   所以这里要重新选一遍卡，不能沿用 ①② 那次的选择
await doSplitN(2);
await pickMember(0, F.cardA);
await pickMember(1, F.cardB);
await page.waitForTimeout(200);
await page.click('#btn-submit');
await page.waitForSelector('#step-done.active', { timeout: 10000 });
ok(true, '★★ 改好之后照常提交成功 —— 挡的是白拿，不是拆分');

const after = JSON.parse(php(`
  require "app/bootstrap.php";
  $c = require "app/config/config.php";
  $a = new Vip\\App($c);
  echo json_encode([
    'a' => (int)$a->members()->findById(${F.midA})['visit_count'],
    'b' => (int)$a->members()->findById(${F.midB})['visit_count'],
  ]);`));
ok(after.a === 1 && after.b === 1,
   `★★ 两位各记 1 次（A=${after.a}、B=${after.b}）—— 一人一份钱，一人一次`);

console.log('\n【⑥ 绕开界面直接调接口，服务端照样拒】');
/**
 * ★ 前面拦的都是界面。界面拦得住手滑，拦不住有心人 ——
 *   Pad 是一台放在柜台上的安卓平板，浏览器地址栏、开发者工具都够得着。
 *   真正的把关必须在服务端，这一条就是钉住它。
 */
const direct = await page.evaluate(async (mid) => {
  try {
    return await api('/points/grant', {
      serial_id: S.order.serial_id, mode: 2,
      allocations: [{ member_id: mid, amount: '0.00', portions: 1 }],
    });
  } catch (e) { return { thrown: true, error: e.error || '', message: e.message || String(e) }; }
}, F.midC);
ok(direct && direct.error === 'portions_without_amount',
   `★★★ 服务端拒了，错误码 portions_without_amount（实际：${direct && (direct.error || 'ok?!')}）`);
ok(!/SELECT|INSERT|Exception|Stack trace|\.php/i.test(String(direct && direct.message)),
   `  └ 回给客户端的只有人话，没有 SQL 也没有堆栈：「${String(direct && direct.message).slice(0, 40)}」`);

const after2 = parseInt(php(`
  require "app/bootstrap.php";
  $c = require "app/config/config.php";
  $a = new Vip\\App($c);
  echo (int)$a->members()->findById(${F.midC})['visit_count'];`), 10);
ok(after2 === 0, '  └ 次数没有被加上去（C 还是 0 次）');

console.log('\n【JS 错误】');
ok(errs.length === 0, errs.length ? '有 JS 报错：' + errs.join(' | ') : '无 JS 报错');

// 清理：撤掉本次流水、把这张单的已分配额退回去、删掉测试卡
php(`
  require "app/bootstrap.php";
  $c = require "app/config/config.php";
  $a = new Vip\\App($c);
  $db = $a->localDb();
  $sc = $c['store_code'];
  foreach ([${F.midA}, ${F.midB}, ${F.midC}] as $id) {
    $db->exec('DELETE FROM point_ledger WHERE member_id = ?', [$id]);
    $db->exec('DELETE FROM coupon       WHERE member_id = ?', [$id]);
  }
  // 只退这一张单 —— 别的用例可能正拿着自己的单在跑
  $db->exec('UPDATE pos_order SET allocated_amount = 0, allocated_portions = 0
              WHERE store_code = ? AND serial_id = ?', [$sc, '${SERIAL}']);
  $db->exec('DELETE FROM card WHERE batch_no = ?', ['${TAG}']);
  foreach ([${F.midA}, ${F.midB}, ${F.midC}] as $id) { $db->exec('DELETE FROM member WHERE id = ?', [$id]); }
`);
setCfg('order_lookup_window_min', WIN0);

await browser.close();
console.log('\n──────────────────────────────────────────────────');
console.log(fail ? `\x1b[31m失败 ${fail}\x1b[0m / 共 ${pass + fail} 项` : `\x1b[32m全部通过\x1b[0m  ${pass} 项`);
process.exit(fail ? 1 : 0);
