/**
 * 计次口径：一张卡，一个餐期，最多 1 次。
 *
 * 这是「十送一」口径的一次根本改变 —— 从【买 10 份套餐】变成【来 10 趟】。
 *
 * 旧口径（按份数）没法防：一桌 10 个人 10 份套餐，整单记给一个人
 * = 一次 10 次计次，当场就够十送一。也就是【一张小票 = 一顿免费的饭】，
 * 捡到一张就直接换，连攒都不用攒。
 *
 * 这个文件守的是收银员那一侧看到的东西：
 *   · 界面上根本没有「整单记一人」这个选项
 *   · 同一张卡本餐期第二次记账，屏幕上要【明说没计次】——
 *     不说的话客人回头问「怎么没给我盖章」，谁也答不上来
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
/**
 * ★ 必须挑【真有套餐份数】的单。
 *   夹具里桌 30 那一单明细缺套餐行（份数 0），拿它测计次会全程 0 次 ——
 *   而那和「规则生效了」长得一模一样，测出来的是个假的绿。
 *   这里按 desc 里的说明挑：BIG = 10 人 10 份，SMALL = 2 份。
 */
const BIG   = live.find(o => /10 人 10 份/.test(o.desc || ''));
const SMALL = live.find(o => /退菜行/.test(o.desc || ''));      // 桌 52，2 份
if (!BIG || !SMALL) {
  console.error('夹具里找不到需要的订单，请重新注入 tests/sim/inject_live.php');
  process.exit(1);
}

const TAG = 'VC' + Math.floor(Math.random() * 9000 + 1000);
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
    'periods' => count($a->mealPeriods()->all()),
  ]);
`));

setCfg('visit_count_mode', 'once_per_period');
/**
 * ★ 积分口径也要钉死。
 *   下面 ③ 断言的是「不计次，但钱花了照样给分」—— 那是【按金额】口径特有的行为。
 *   按次数口径下同一餐期第二单是【一分都没有】，两者结论正好相反。
 *   不钉的话，这个文件的绿灯就取决于机器上恰好留着哪种口径。
 */
setCfg('points_mode', 'by_amount');
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

const visitsOf = (mid) => parseInt(php(`
  require "app/bootstrap.php";
  $c = require "app/config/config.php";
  $a = new Vip\\App($c);
  echo (int)$a->members()->findById(${mid})['visit_count'];`), 10);

/** 走一遍：找单 → AA n 人 → 给第 i 位选卡 → 提交 */
const grant = async (table, people, cards) => {
  await page.fill('#table-input', String(table));
  await page.click('#btn-locate');
  await page.waitForSelector('#step-order.active, #step-mode.active', { timeout: 8000 });
  if (await page.locator('#step-order.active').count() > 0) {
    await page.locator('#order-list button').first().click();
  }
  await page.waitForSelector('#step-mode.active', { timeout: 5000 });
  await page.click('.mode[data-mode="2"]');
  await page.waitForSelector('#step-assign.active', { timeout: 5000 });
  await page.fill('#aa-people', String(people));
  await page.click('#btn-aa');
  await page.waitForTimeout(800);
  for (let i = 0; i < cards.length; i++) {
    await page.locator('#assign-people .person:not(.locked)').nth(i).locator('button').first().click();
    await page.waitForSelector('#member-modal:not([hidden])', { timeout: 5000 });
    await page.fill('#member-input', cards[i]);
    await page.click('#btn-member-search');
    await page.waitForTimeout(900);
    await page.locator('#member-result button').first().click();
    await page.waitForTimeout(400);
  }
  await page.click('#btn-submit');
  await page.waitForSelector('#step-done.active', { timeout: 10000 });
};

console.log('\n【① 界面上根本没有「整单记一人」】');
await page.fill('#table-input', String(BIG.table));
await page.click('#btn-locate');
await page.waitForSelector('#step-order.active, #step-mode.active', { timeout: 8000 });
if (await page.locator('#step-order.active').count() > 0) {
  await page.locator('#order-list button').first().click();
}
await page.waitForSelector('#step-mode.active', { timeout: 5000 });
ok(await page.locator('.mode[data-mode="1"]').count() === 0,
   '★★★ 「整单记一人」这个按钮不存在 —— 它会把同桌其他人的次数并到一个人名下');
ok(await page.locator('.mode').count() === 2, '  └ 只剩均摊 AA 与点选菜品两种');
const note = await page.locator('[data-i18n-html="mode.noWholeNote"]').textContent();
ok(/一张卡一个餐期只记 1 次/.test(note),
   `★★ 并且把规则写在旁边：「${note.replace(/\s+/g, ' ').trim().slice(0, 50)}…」`);
/**
 * 多桌合并把几桌的【积分】并给一位客人（次数照样每张卡 1 次）。
 * 它长得像「整单记一人」，而整单已经拿掉了 —— 两个入口一去一留
 * 会让人困惑「为什么一桌不能并、三桌反而能并」，所以只给经理看。
 * admin 本身是经理，所以这里应该看得见；断言的是【它跟着身份走】。
 */
const isMgr = await page.evaluate(() => !!(S.operator && S.operator.is_manager));
const mgVisible = await page.locator('#btn-merge-start').isVisible();
ok(isMgr && mgVisible, '★★ 经理身份下多桌合并入口才出现');
ok(await page.evaluate(() => {
     S.operator.is_manager = false; applySettings();
     const h = document.querySelector('#btn-merge-start').hidden;
     S.operator.is_manager = true;  applySettings();
     return h;
   }), '★★★ 换成普通服务员身份，这个入口就消失 —— 前端不给合并的口子');

console.log('\n【② 一桌两位客人两张卡 → 各记 1 次】');
// 退回第一步：step-mode → step-order → step-table，两级
await page.click('#step-mode [data-back="step-order"]');
await page.waitForSelector('#step-order.active', { timeout: 5000 });
await page.click('#step-order [data-back="step-table"]');
await page.waitForSelector('#step-table.active', { timeout: 5000 });
const before = [visitsOf(F.midA), visitsOf(F.midB)];
ok(before[0] === 0 && before[1] === 0, '两张卡都是 0 次起步');
/**
 * ★★★ 这一单是【10 人 10 份套餐】，但只有 2 张卡。
 *
 *   旧口径（按份数）下：AA 拆 2 人 → 各 5 份 → 各记 5 次 → 全桌 10 次，
 *   两张卡当场各自逼近十送一。
 *   新口径下：各记 1 次，全桌一共 2 次。剩下 8 份的次数【就是没有了】，
 *   不会挪给在场的两张卡 —— 那正是这次要禁掉的「计入一个名下」。
 */
await grant(BIG.table, 2, [F.cardA, F.cardB]);
const afterAB = [visitsOf(F.midA), visitsOf(F.midB)];
ok(afterAB[0] === 1 && afterAB[1] === 1,
   `★★★ 10 份套餐、2 张卡 → 各记 1 次（${afterAB.join(' / ')}），全桌共 2 次而不是 10 次`);

console.log('\n【③ 同一餐期再来一单 → 明说没计次】');
/**
 * 这一条是给现场看的：不计次要【说出来】。
 * 屏幕上不写的话，客人回头问「怎么没给我盖章」，收银员答不上来。
 */
await page.click('#btn-new');
await page.waitForSelector('#step-table.active', { timeout: 5000 });
await grant(SMALL.table, 1, [F.cardA]);
ok(visitsOf(F.midA) === 1, '★★★ 同一餐期第二单【不再计次】，还是 1 次');
const done = await page.locator('#done-body').textContent();
ok(/本餐期已记过|只记积分不计次/.test(done),
   `★★★ 结果页明说了为什么：「${done.replace(/\s+/g, ' ').trim().slice(0, 70)}…」`);
ok(/\+0 次/.test(done), '  └ 计次是大字那一行，明写 +0，不是含糊带过');

const ptsA = parseInt(php(`
  require "app/bootstrap.php";
  $c = require "app/config/config.php";
  $a = new Vip\\App($c);
  echo (int)$a->members()->findById(${F.midA})['points_balance'];`), 10);
ok(ptsA > 0, `★★ 但积分照给（${ptsA} 分）—— 钱是真花了的，不给分才是错的`);

console.log('\n【④ 没配餐期会静默把规则改严 —— 后台要看得见】');
/**
 * 查不到餐期时 MealPeriod 退回「同一营业日」这个更粗的口径。
 * 于是「中午来一次、晚上又来一次」被当成同一顿，客人少拿一半次数，
 * 而且没有任何地方会报错。所以后台顶栏要挂一条。
 */
const warn = JSON.parse(php(`
  require "app/bootstrap.php";
  echo json_encode([
    'withPeriods' => \\Vip\\Features::warnings(false, [], true, 2),
    'noPeriods'   => \\Vip\\Features::warnings(false, [], true, 0),
    'otherMode'   => \\Vip\\Features::warnings(false, [], false, 0),
  ]);
`));
ok(warn.withPeriods.length === 0, '配了餐期 → 没有提醒');
ok(warn.noPeriods.some(w => w.key === 'meal_period_missing'),
   '★★ 没配餐期 + 按餐期计次 → 后台挂一条红条');
ok(warn.otherMode.length === 0, '★ 用别的计次口径时不提醒（那种口径本来就不看餐期）');
ok(F.periods >= 1, `  └ 本机确实配了 ${F.periods} 个餐期，所以上面 ②③ 测的是真行为`);

console.log('\n【JS 错误】');
ok(errs.length === 0, errs.length ? '有报错：' + errs.join(' | ') : '无 JS 报错');

await browser.close();

php(`
  require "app/bootstrap.php";
  $c = require "app/config/config.php";
  $a = new Vip\\App($c);
  $db = $a->localDb();
  foreach ([${F.midA}, ${F.midB}] as $id) {
    $db->exec('DELETE FROM point_ledger WHERE member_id = ?', [$id]);
    $db->exec('DELETE FROM coupon WHERE member_id = ?', [$id]);
  }
  $db->exec('UPDATE pos_order SET allocated_amount = 0, allocated_portions = 0 WHERE store_code = ?', [$c['store_code']]);
  $db->exec('DELETE FROM card WHERE batch_no = ?', ['${TAG}']);
  foreach ([${F.midA}, ${F.midB}] as $id) { $db->exec('DELETE FROM member WHERE id = ?', [$id]); }
`);
setCfg('order_lookup_window_min', WIN0);

console.log(`\n${'─'.repeat(50)}\n${fail === 0 ? '全部通过' : '失败 ' + fail}  ${pass + fail} 项\n`);
process.exit(fail ? 1 : 0);
