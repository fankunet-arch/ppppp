/**
 * 「这一单不计次」必须【在记账之前】说出来。
 *
 * ── 这个文件守的是什么 ──────────────────────────────
 *
 * once_per_period 下一张卡一个餐期最多 1 次。于是同餐期的第二单
 * 照样记得上（金额、积分都进账），但计次是 0。
 *
 * 原来这件事只在【结果页】那行橙字上说 —— 那时账已经记完了，
 * 服务员没法再回头问客人一句「这一单不攒次数，还记吗」。
 *
 * 现实场景：一桌客人吃完结了账，又加点甜点酒水另开一单。
 * 服务员照常拿卡去记，客人以为又攒了一次，回头发现没有 —— 投诉就是这么来的。
 *
 * 所以两处提前告知，缺一不可：
 *   ① 选完会员 → 那一行上挂一条【常驻】提示（服务员要拿着这句话去跟客人说，
 *      而他往往是先选人、再算金额、再回头解释，中间隔着好几步，弹一下就没不行）
 *   ② 提交     → 弹一次【页内】确认（不是系统 confirm），点「返回修改」必须什么都没记
 *
 * ★ 正常单一次都不能打扰 —— 每单都弹的确认框，三天之后就变成闭着眼睛点「继续」。
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
const getCfg = (k) => php(`require "app/bootstrap.php"; $c = require "app/config/config.php";
  echo (new Vip\\App($c))->cfg()->get(${JSON.stringify(k)}, '');`);

const live = JSON.parse(readFileSync(REPO + '/tests/sim/live_orders.json', 'utf8'));
// 两张【真有计次套餐份数】的单：没份数的单全程 0 次，测出来是个假的绿
const A = live.find(o => /10 人 10 份/.test(o.desc || ''));
const B = live.find(o => /退菜行/.test(o.desc || ''));
// ⑥ 换口径之后要再记一单，不能复用前面那两张 —— 它们的额度已经被分掉了
const C = live.find(o => /9 人 5 份/.test(o.desc || ''));
if (!A || !B || !C) {
  console.error('夹具里找不到需要的订单，请重新注入 tests/sim/inject_live.php');
  process.exit(1);
}

const MODE0 = getCfg('points_mode') || 'by_amount';
const WIN0  = getCfg('order_lookup_window_min') || '30';
setCfg('order_lookup_window_min', 180);
setCfg('late_grant_minutes', 0);
setCfg('max_grants_per_period', 0);
setCfg('visit_count_mode', 'once_per_period');
setCfg('points_mode', 'by_amount');

/**
 * ★ 开跑前先把本文件要用的那两张单的已分配额清零。
 *
 *   这个文件中途会【故意】停在确认框上，一旦某次断言失败就走不到末尾的清理，
 *   下一次跑就会撞上「这张单已经分完了」，报出来的错跟真正的问题毫无关系。
 *   只清这两张单，不动同库里别的数据。
 */
php(`
  require "app/bootstrap.php";
  $c = require "app/config/config.php";
  $a  = new Vip\\App($c);
  $db = $a->localDb();
  $sc = $c['store_code'];

  // ① 本文件往次留下的测试会员（卡批次 NV…）连同其流水一并删掉。
  //    只清 allocated_amount 是不够的：流水还在的话，那张单上会多出一行
  //    「已记给 TK…」的锁定行，金额也对不上，下一次记账直接被服务端拒。
  foreach ($db->all("SELECT m.id FROM member m JOIN card c ON c.member_id = m.id
                      WHERE c.store_code = ? AND c.batch_no LIKE 'NV%'", [$sc]) as $m) {
      $db->exec('DELETE FROM point_ledger WHERE member_id = ?', [(int)$m['id']]);
      $db->exec('DELETE FROM coupon       WHERE member_id = ?', [(int)$m['id']]);
      $db->exec('DELETE FROM member       WHERE id = ?',        [(int)$m['id']]);
  }
  $db->exec("DELETE FROM card WHERE store_code = ? AND batch_no LIKE 'NV%'", [$sc]);

  // ② 再把这三张单的已分配额清零
  $db->exec(
    'UPDATE pos_order SET allocated_amount = 0, allocated_portions = 0
      WHERE store_code = ? AND table_name IN (?, ?, ?)',
    [$sc, ${JSON.stringify(String(A.table))}, ${JSON.stringify(String(B.table))},
     ${JSON.stringify(String(C.table))}]);
`);

const TAG = 'NV' + Math.floor(Math.random() * 9000 + 1000);
const F = JSON.parse(php(`
  require "app/bootstrap.php";
  $c = require "app/config/config.php";
  $a = new Vip\\App($c);
  $b = $a->cards()->generateBatch("${TAG}", 1, date('Y-m-d', strtotime('+2 years')));
  $r = $a->cardService()->bindNewMember($b[0]['display'], null, null, null,
        ['id' => 1, 'name' => 'browser-test', 'device' => 'TEST']);
  echo json_encode(['card' => $b[0]['display'], 'mid' => (int)$r['member']['id']]);
`));

const ledgerRows = () => parseInt(php(`
  require "app/bootstrap.php";
  $c = require "app/config/config.php";
  $a = new Vip\\App($c);
  echo (int)$a->localDb()->value('SELECT COUNT(*) FROM point_ledger WHERE member_id = ?', [${F.mid}]);`), 10);

const browser = await launch();
const page = await (await browser.newContext({ viewport: { width: 900, height: 820 } })).newPage();
const errs = [];
page.on('pageerror', e => errs.push(String(e)));

await page.goto(BASE + '/', { waitUntil: 'networkidle' });
await page.fill('#login-name', 'admin');
await page.fill('#login-pin', 'admin123');
await page.click('#btn-login');
await page.waitForSelector('#view-main.active', { timeout: 8000 });

/** 走到「选完会员、等着提交」那一步 */
const upToSubmit = async (table) => {
  await page.fill('#table-input', String(table));
  await page.click('#btn-locate');
  await page.waitForSelector('#step-order.active, #step-mode.active', { timeout: 8000 });
  if (await page.locator('#step-order.active').count() > 0) {
    await page.locator('#order-list button').first().click();
  }
  await page.waitForSelector('#step-mode.active', { timeout: 5000 });
  await page.click('.mode[data-mode="2"]');
  await page.waitForSelector('#step-assign.active', { timeout: 5000 });
  await page.fill('#aa-people', '1');
  await page.click('#btn-aa');
  await page.waitForTimeout(700);
  await page.locator('#assign-people .person:not(.locked)').first().locator('button').first().click();
  await page.waitForSelector('#member-modal:not([hidden])', { timeout: 5000 });
  await page.fill('#member-input', F.card);
  await page.click('#btn-member-search');
  await page.waitForTimeout(900);
  await page.locator('#member-result button').first().click();
  await page.waitForTimeout(500);
};

console.log('\n【① 正常的第一单 —— 一次都不能打扰】');
await upToSubmit(A.table);
ok((await page.locator('#assign-people .no-visit').count()) === 0,
   '★★ 会计次的单，行上没有任何提示');
await page.click('#btn-submit');
await page.waitForSelector('#step-done.active', { timeout: 10000 });
ok(true, '  └ 也没有确认框，直接记完（每单都弹等于没弹）');

console.log('\n【② 同餐期第二单 —— 选完人就要看得见】');
await page.click('#btn-new');
await page.waitForSelector('#step-table.active', { timeout: 5000 });
await upToSubmit(B.table);
const inline = (await page.locator('#assign-people .no-visit').allTextContents()).map(t => t.trim());
ok(inline.length === 1, '★★★ 那一行上挂出了提示');
ok(/不计次/.test(inline[0] || ''), `  └ 明说不计次：「${inline[0] || ''}」`);
ok(/积分照常/.test(inline[0] || ''), '  └ 并且说清积分照常给（按金额口径下这是对的）');
ok(await page.locator('#assign-people .no-visit').first().isVisible(),
   '  └ 是【常驻】在屏幕上的，不是弹一下就没');

console.log('\n【③ 提交时弹页内确认 —— 不是系统 confirm】');
await page.click('#btn-submit');
await page.waitForSelector('.ui-ask:not([hidden])', { timeout: 5000 });
ok(true, '★★★ 提交被拦下，弹出确认');
const msg = ((await page.locator('.ui-ask-msg').textContent()) || '').trim();
ok(/不会计入次数/.test(msg), `  └ 正文说清了后果：「${msg.replace(/\s+/g, ' ').slice(0, 46)}…」`);
ok(/告知客人/.test(msg), '★★ 并且明确要求【先告知客人】—— 这一条就是为了少一次投诉');
const btns = await page.locator('.ui-ask-btns button').allTextContents();
ok(btns.length === 2 && /仍然记账/.test(btns.join('')) && /返回修改/.test(btns.join('')),
   `  └ 两个按钮分得清：「${btns.join(' / ')}」`);

console.log('\n【④ 点「返回修改」必须什么都没记】');
const before = ledgerRows();
await page.locator('.ui-ask .ui-cancel').click();
await page.waitForTimeout(400);
ok(await page.locator('#step-assign.active').count() > 0, '还停在分配页，没往下走');
ok(ledgerRows() === before, `★★★ 账本没有多出流水（仍是 ${before} 条）—— 取消就是真的没记`);

console.log('\n【⑤ 点「仍然记账」照常记 —— 拦的是不知情，不是记账本身】');
await page.click('#btn-submit');
await page.waitForSelector('.ui-ask:not([hidden])', { timeout: 5000 });
await page.locator('.ui-ask .ui-ok').click();
await page.waitForSelector('#step-done.active', { timeout: 10000 });
ok(ledgerRows() === before + 1, '★★ 确认之后账记上了');
const done = ((await page.locator('#done-body').textContent()) || '').replace(/\s+/g, ' ');
ok(/\+0 次/.test(done), '  └ 结果页大字仍是 +0 次，前后说法一致');

console.log('\n【⑥ 按次数口径下措辞要换 —— 那时不计次 = 一分都没有】');
setCfg('points_mode', 'by_visit');
await page.click('#btn-new');
await page.waitForSelector('#step-table.active', { timeout: 5000 });
// 让 Pad 重新取一次 settings —— 会话还在，刷新后直接回到主界面，不用再登录
await page.reload({ waitUntil: 'networkidle' });
await page.waitForSelector('#view-main.active', { timeout: 8000 });
await upToSubmit(C.table);
const inline2 = ((await page.locator('#assign-people .no-visit').first().textContent()) || '').trim();
ok(/不加分|不计次/.test(inline2), `★★★ 行内提示换了措辞：「${inline2}」`);
ok(/不加分/.test(inline2), '  └ 说了「也不加分」—— 照着按金额那句念「积分照常」就是骗人');
await page.click('#btn-submit');
await page.waitForSelector('.ui-ask:not([hidden])', { timeout: 5000 });
const msg2 = ((await page.locator('.ui-ask-msg').textContent()) || '').trim();
ok(/不会加分/.test(msg2), `  └ 确认框同样换了措辞：「${msg2.replace(/\s+/g, ' ').slice(0, 46)}…」`);
await page.locator('.ui-ask .ui-cancel').click();

console.log('\n【JS 错误】');
ok(errs.length === 0, errs.length ? '有报错：' + errs.join(' | ') : '无 JS 报错');

await browser.close();

php(`
  require "app/bootstrap.php";
  $c = require "app/config/config.php";
  $a = new Vip\\App($c);
  $db = $a->localDb();
  $db->exec('DELETE FROM point_ledger WHERE member_id = ?', [${F.mid}]);
  $db->exec('DELETE FROM coupon       WHERE member_id = ?', [${F.mid}]);
  $db->exec('UPDATE pos_order SET allocated_amount = 0, allocated_portions = 0 WHERE store_code = ?', [$c['store_code']]);
  $db->exec('DELETE FROM card   WHERE batch_no = ?', ['${TAG}']);
  $db->exec('DELETE FROM member WHERE id = ?', [${F.mid}]);
`);
setCfg('points_mode', MODE0);
setCfg('order_lookup_window_min', WIN0);

console.log(`\n${'─'.repeat(50)}\n${fail === 0 ? '全部通过' : '失败 ' + fail}  ${pass + fail} 项\n`);
process.exit(fail ? 1 : 0);
