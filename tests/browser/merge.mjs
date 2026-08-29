/**
 * 多桌合并记账（同行分桌）。
 *
 * 场景：一大帮人来吃饭，坐了三桌，分桌计费、最后一起结账，
 * 然后自愿把三桌的积分都记到其中一位的卡上。docs/03 §12.2
 *
 * ★ 这件事以前也做得到 —— 做三次独立记账就行。为什么要专门做一个流程：
 *
 *   ① 正当路径要比作弊路径【更省事】。这个方向永远比加限制有效：
 *      同行分桌一次搞定，而捡小票的人得一张一张来，每一张都撞时限。
 *   ② 三笔盖同一个组号，撤销能整组撤 —— 不然收银员逐条撤，
 *      中间分神就留下一个撤了两桌、剩一桌还挂着的会员。
 *   ③ 风控要能把「一次三桌」和「三次一桌」数成不同的东西。
 *
 * ★ 只有整单一种记法。合并之后再 AA 或点选菜品没有意义 ——
 *   会走到这条路上，本身就意味着「不用再分了，都算一个人的」。
 *
 * 需要先注入模拟活单：
 *   sudo SIM_DSN=... php tests/sim/inject_live.php
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
const dineIn = live.filter(o => o.table !== 'Llevar');
const T1 = dineIn[0], T2 = dineIn[1];              // 最近的两桌，结账时间只差几分钟

// 备一张已激活的卡收分
const TAG = 'MG' + Math.floor(Math.random() * 9000 + 1000);
const F = JSON.parse(php(`
  require "app/bootstrap.php";
  $c = require "app/config/config.php";
  $a = new Vip\\App($c);
  $b = $a->cards()->generateBatch("${TAG}", 1, date('Y-m-d', strtotime('+2 years')));
  $op = ['id' => 1, 'name' => 'browser-test', 'device' => 'TEST'];
  $r = $a->cardService()->bindNewMember($b[0]['display'], null, null, null, $op);
  echo json_encode(['card' => $b[0]['display'], 'mid' => (int)$r['member']['id']]);
`));

// 闸门先摆到「不干扰」的位置，各段自己按需打开
setCfg('late_grant_minutes', 0);
setCfg('max_grants_per_period', 0);
setCfg('merge_span_minutes', 60);
setCfg('alert_grants_per_day', 0);
setCfg('alert_span_hours', 0);

const browser = await launch();
const page = await (await browser.newContext({ viewport: { width: 1280, height: 900 } })).newPage();
const errs = [];
page.on('pageerror', e => errs.push(String(e)));

await page.goto(BASE + '/', { waitUntil: 'networkidle' });
await page.fill('#login-name', 'admin');
await page.fill('#login-pin', 'admin123');
await page.click('#btn-login');
await page.waitForSelector('#view-main.active', { timeout: 8000 });

/** 走一遍找单 → 选订单 */
const findTable = async (table) => {
  await page.fill('#table-input', String(table));
  await page.click('#btn-locate');
  await page.waitForSelector('#step-order.active, #step-merge.active', { timeout: 8000 });
  if (await page.locator('#step-order.active').count() > 0) {
    await page.locator('#order-list button').first().click();
  }
  await page.waitForTimeout(400);
};

console.log('\n【① 从记账方式那一步进入合并】');
await findTable(T1.table);
await page.waitForSelector('#step-mode.active', { timeout: 5000 });
ok(await page.locator('#btn-merge-start').isVisible(), '★ 记账方式页有「还有其他桌，一起记」');
await page.click('#btn-merge-start');
await page.waitForSelector('#step-merge.active', { timeout: 5000 });
ok(await page.locator('#merge-list .lrow').count() === 1,
   '★★ 当前这一桌自动带进列表 —— 收银员是看着它才想起「还有别桌」的');
ok(/1 桌|1 mesas/.test(await page.locator('#merge-count').textContent()), '  └ 计数是 1 桌');

console.log('\n【② 只有一桌不让提交】');
await page.click('#btn-merge-submit');
await page.waitForTimeout(400);
ok(await page.locator('#merge-err').isVisible()
   && /至少要两桌/.test(await page.locator('#merge-err').textContent()),
   `★★ 一桌就提交被挡住：「${(await page.locator('#merge-err').textContent()).trim()}」`);

console.log('\n【③ 再加一桌】');
await page.click('#btn-merge-add');
await page.waitForSelector('#step-table.active', { timeout: 5000 });
ok(true, '回到找单页（用的是同一条找单流程，收银员不用再学一套）');
await findTable(T2.table);
await page.waitForSelector('#step-merge.active', { timeout: 5000 });
ok(await page.locator('#merge-list .lrow').count() === 2,
   '★★ 选完直接回到合并页，列表变成 2 桌（不是跳去记账方式）');
const sum2 = await page.locator('#merge-sum').textContent();
ok(parseFloat(sum2) > 0, `  └ 合计跟着累加：€ ${sum2}`);

console.log('\n【④ 同一桌不会重复加进来】');
await page.click('#btn-merge-add');
await page.waitForSelector('#step-table.active', { timeout: 5000 });
await findTable(T2.table);
await page.waitForTimeout(600);
ok(await page.locator('#merge-list .lrow').count() === 2, '★★ 重复的那一桌没有被加两次');
const t = (await page.locator('#toast').textContent()) || '';
ok(/已经加进来/.test(t), `  └ 并且说明了为什么：「${t.trim()}」`);

console.log('\n【⑤ 没选卡不让提交】');
await page.click('#btn-merge-submit');
await page.waitForTimeout(400);
ok(/请先选择收分的会员/.test(await page.locator('#merge-err').textContent()),
   '★★ 没选收分的卡就提交被挡住');

console.log('\n【⑥ 选卡 → 提交】');
await page.click('#btn-merge-pick');
await page.waitForSelector('#member-modal:not([hidden])', { timeout: 5000 });
await page.fill('#member-input', F.card);
await page.click('#btn-member-search');
await page.waitForTimeout(900);
await page.locator('#member-result button').first().click();
await page.waitForTimeout(500);
ok(await page.locator('#merge-member').textContent().then(x => x.includes(F.card)),
   '★ 选中的卡显示在合并页上');

await page.click('#btn-merge-submit');
await page.waitForSelector('.ui-ask:not([hidden])', { timeout: 5000 });
const ask = await page.locator('.ui-ask-msg').textContent();
ok(/2/.test(ask) && ask.includes(F.card),
   `★★ 提交前确认，摆出桌数、金额和收分的卡：「${ask.replace(/\s+/g, ' ').trim().slice(0, 60)}…」`);
await page.click('.ui-ok');
await page.waitForSelector('#step-done.active', { timeout: 8000 });
ok(await page.locator('#done-body .card').count() >= 2,
   '★★ 记账完成，两桌各一张结果卡');

const after = JSON.parse(php(`
  require "app/bootstrap.php";
  $c = require "app/config/config.php";
  $a = new Vip\\App($c);
  $db = $a->localDb();
  $rows = $db->all('SELECT grant_group, points FROM point_ledger
                     WHERE store_code = ? AND member_id = ? AND entry_type = 1 AND status = 1',
                   [$c['store_code'], ${F.mid}]);
  echo json_encode(['rows' => $rows,
    'balance' => (int)$a->members()->findById(${F.mid})['points_balance']]);
`));
const groups = [...new Set(after.rows.map(r => r.grant_group))];
ok(after.rows.length === 2, `★ 库里两笔流水（${after.rows.length}）`);
ok(groups.length === 1 && groups[0], `★★★ 两笔盖同一个组号：${groups[0]} —— 撤销时能整组撤`);
ok(after.balance > 0, `  └ 积分都进了这张卡：${after.balance} 分`);

console.log('\n【⑦ 整组撤销：合并是一次操作，撤销也该是一次】');
const rev = JSON.parse(php(`
  require "app/bootstrap.php";
  $c = require "app/config/config.php";
  $a = new Vip\\App($c);
  $op = ['id' => 1, 'name' => '经理', 'device' => 'TEST', 'role' => 3, 'is_manager' => true];
  $r = $a->points()->reverseGroup(${JSON.stringify(groups[0])}, '浏览器测试 · 整组撤销', $op);
  echo json_encode(['r' => $r, 'balance' => (int)$a->members()->findById(${F.mid})['points_balance']]);
`));
ok(rev.r.ok && rev.r.count === 2, '★★ 两笔一起撤');
ok(rev.balance === 0, `★★ 积分退干净（余额 ${rev.balance}）`);

console.log('\n【⑧ 补记要经理放行 —— 就地问原因，不用去找经理重做一遍】');
/**
 * 一刀拒绝的代价是柜台当面回绝客人，那正是投诉的来源。
 * 让收银员「去找经理重走一遍找单流程」同样走不通 —— 客人就站在柜台前，
 * 中间任何一步分神这一单就丢了。经理走过来输一句原因才是现场唯一可行的。
 */
/**
 * 把时限调到 1 分钟，让手头这些单全都算「补记」。
 *
 * 不去用真正很旧的那一单：按桌号找单本来就有 30 分钟窗口，
 * 夹具里最老的那桌已经超窗，根本找不出来。而闸门判的是
 * order_end_time 与现在的差值，调阈值和用旧单走的是同一段代码。
 */
setCfg('late_grant_minutes', 1);
await page.click('#btn-new');
await page.waitForSelector('#step-table.active', { timeout: 5000 });
await findTable(T1.table);
await page.waitForSelector('#step-mode.active', { timeout: 5000 });
await page.click('.mode[data-mode="1"]');
await page.waitForSelector('#step-assign.active', { timeout: 5000 });
await page.locator('#assign-people button').first().click();
await page.waitForSelector('#member-modal:not([hidden])', { timeout: 5000 });
await page.fill('#member-input', F.card);
await page.click('#btn-member-search');
await page.waitForTimeout(900);
await page.locator('#member-result button').first().click();
await page.waitForTimeout(400);
await page.click('#btn-submit');
await page.waitForSelector('.ui-ask:not([hidden])', { timeout: 8000 });
const gateMsg = await page.locator('.ui-ask-msg').textContent();
ok(/超出当场记账|分钟了/.test(gateMsg),
   `★★ 撞了补记时限，就地弹出说明：「${gateMsg.replace(/\s+/g, ' ').trim().slice(0, 60)}…」`);
ok(await page.locator('.ui-ask-input').isVisible(), '  └ 并且要求填原因');

// 取消 → 不该记账
await page.click('.ui-cancel');
await page.waitForTimeout(600);
ok(await page.locator('#step-done.active').count() === 0, '★★ 取消放行 → 没有记账');

// 填原因 → 放行
await page.click('#btn-submit');
await page.waitForSelector('.ui-ask:not([hidden])', { timeout: 8000 });
await page.fill('.ui-ask-input', '客人忘带卡，隔天拿小票来补');
await page.click('.ui-ok');
await page.waitForSelector('#step-done.active', { timeout: 8000 });
ok(true, '★★ 写了原因就记上了');

const audit = php(`
  require "app/bootstrap.php";
  $c = require "app/config/config.php";
  $a = new Vip\\App($c);
  echo (string)$a->localDb()->value(
    'SELECT COUNT(*) FROM audit_log WHERE store_code = ? AND action = ? AND detail LIKE ?',
    [$c['store_code'], 'point_grant_forced', '%忘带卡%']);
`);
ok(parseInt(audit, 10) >= 1,
   '★★★ 破例单独记一条 point_grant_forced 带着原因 —— 后台筛一下就是全部破例');

console.log('\n【JS 错误】');
ok(errs.length === 0, errs.length ? '有报错：' + errs.join(' | ') : '无 JS 报错');

await browser.close();

// 复位 + 清理
setCfg('late_grant_minutes', 60);
php(`
  require "app/bootstrap.php";
  $c = require "app/config/config.php";
  $a = new Vip\\App($c);
  $db = $a->localDb();
  $db->exec('DELETE FROM point_ledger WHERE member_id = ?', [${F.mid}]);
  $db->exec('DELETE FROM coupon WHERE member_id = ?', [${F.mid}]);
  $db->exec('UPDATE pos_order SET allocated_amount = 0, allocated_portions = 0 WHERE store_code = ?', [$c['store_code']]);
  $db->exec('DELETE FROM card WHERE batch_no = ?', ['${TAG}']);
  $db->exec('DELETE FROM member WHERE id = ?', [${F.mid}]);
`);

console.log(`\n${'─'.repeat(50)}\n${fail === 0 ? '全部通过' : '失败 ' + fail}  ${pass + fail} 项\n`);
process.exit(fail ? 1 : 0);
