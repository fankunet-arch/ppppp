/**
 * 有效期相关的两条【只有真浏览器能验】的路径。
 *
 *   1. 发卡时的拦阻   —— 剩 ≤30 天的库存卡，发出去之前要问一句
 *   2. 换卡           —— 扫到过期卡，当场换一张新的，积分转过去
 *
 * 这两条服务端在 smoke ⑰ 段已经验过了；这里验的是**界面把它们串起来对不对**：
 * 弹层弹没弹、点「换一张」有没有真的把待绑卡号清掉、
 * 换卡弹的是输入框而不是确认框、换完有没有直接把会员选上。
 */
import { launch, BASE } from './_launch.mjs';
import { execSync } from 'node:child_process';

const REPO = '/home/user/ppppp';
let pass = 0, fail = 0;
const ok = (c, m) => { c ? (pass++, console.log('  \x1b[32m✓\x1b[0m ' + m)) : (fail++, console.log('  \x1b[31m✗\x1b[0m ' + m)); };

/** 用管道喂 PHP，省得把整段代码塞进 -r 的引号地狱 */
const php = (code) => execSync('php', { cwd: REPO, encoding: 'utf8', input: '<?php ' + code }).trim();

const TAG = 'X' + Math.floor(Math.random() * 9000 + 1000);

// ── 造数：三种有效期各来一张 ────────────────────────────────
const fixture = JSON.parse(php(`
  require "app/bootstrap.php";
  $c = require "app/config/config.php";
  $a = new Vip\\App($c);
  $far  = date('Y-m-d', strtotime('+3 years'));
  $soon = date('Y-m-d', strtotime('+20 days'));

  $ok   = $a->cards()->generateBatch("${TAG}A", 2, $far);   // 一张建会员，一张当新卡
  $soonB= $a->cards()->generateBatch("${TAG}B", 1, $soon);  // 快到期的库存卡
  $dead = $a->cards()->generateBatch("${TAG}C", 1, $far);   // 待会改成已过期的库存卡

  // 建一名会员并给他攒点分，好验换卡有没有把分带过去
  $op  = ['id' => 1, 'name' => 'browser-test', 'device' => 'TEST'];
  $r   = $a->cardService()->bindNewMember($ok[0]['display'], null, null, null, $op);
  $mid = (int)$r['member']['id'];
  $a->members()->applyDelta($mid, 88, 4, 3000);
  $a->localDb()->exec('INSERT INTO point_ledger
        (store_code, member_id, entry_type, amount, points, counted_visit,
         status, source, manual_reason, created_at)
      VALUES (?,?,?,?,?,?,?,?,?,?)',
    [$c['store_code'], $mid, 6, 30.00, 88, 4, 1, 2, '浏览器测试造数', $a->localDb()->now()]);

  // 把会员那张卡和那张库存卡都改成昨天到期（generateBatch 不许签发过去的日期）
  $past = date('Y-m-d', strtotime('-1 day'));
  foreach ([$ok[0]['display'], $dead[0]['display']] as $d) {
    $a->localDb()->exec('UPDATE card SET valid_to = ? WHERE store_code = ? AND card_no = ?',
      [$past, $c['store_code'], Vip\\CardNumber::normalize($d)]);
  }

  echo json_encode([
    'expiredBound'  => $ok[0]['display'],   // 已绑会员 + 已过期 → 换卡入口
    'fresh'         => $ok[1]['display'],   // 换发用的新卡
    'soon'          => $soonB[0]['display'],// 剩 20 天的库存卡
    'expiredStock'  => $dead[0]['display'], // 从没绑过人就过期了
    'memberId'      => $mid,
    'past'          => $past,
  ]);
`));

const browser = await launch();
const page = await (await browser.newContext({ viewport: { width: 1280, height: 800 } })).newPage();
const errs = [];
page.on('pageerror', e => errs.push(String(e)));

/** 等一条【新的】toast：#toast 是共用的，旧的有 3 秒存活期，不清会读到上一条 */
const waitFreshToast = async (re) => {
  await page.evaluate(() => {
    const t = document.querySelector('#toast');
    if (t) { t.hidden = true; t.textContent = ''; }
  });
  await page.waitForFunction((src) => {
    const t = document.querySelector('#toast');
    return t && !t.hidden && new RegExp(src).test(t.textContent || '');
  }, re.source, { timeout: 8000 });
  return page.locator('#toast').textContent();
};

/** 等页内弹层弹出来，返回它的正文 */
const waitDialog = async () => {
  await page.waitForFunction(() => {
    const h = document.querySelector('.ui-ask');
    return h && !h.hidden;
  }, null, { timeout: 8000 });
  return (await page.locator('.ui-ask-msg').textContent()) || '';
};
const clickOk     = () => page.click('.ui-ok');
const clickCancel = () => page.click('.ui-cancel');

const lookup = async (v) => {
  await page.fill('#member-input', v);
  await page.click('#btn-member-search');
};

console.log('\n【登录】');
await page.goto(BASE + '/', { waitUntil: 'networkidle' });
await page.fill('#login-name', 'admin');
await page.fill('#login-pin', 'admin123');
await page.click('#btn-login');
await page.waitForSelector('#view-main.active', { timeout: 5000 });
ok(true, '登录成功');

await page.evaluate(() => openMemberModal('manual'));
await page.waitForSelector('#member-modal:not([hidden])');

// ── ① 快到期的库存卡：发之前要问 ──────────────────────────
console.log('\n【① 发卡前的拦阻】');
await lookup(fixture.soon);
let msg = await waitDialog();
ok(/只剩 \d+ 天就到期/.test(msg), `剩 20 天的卡会拦一下：「${msg.split('\n')[0]}」`);
ok(/20 天/.test(msg), '天数算对了');
ok(await page.locator('.ui-cancel').textContent() === '换一张', '取消按钮写的是「换一张」，不是干巴巴的「取消」');
ok(await page.locator('.ui-ok').textContent()     === '仍然发这张', '确定按钮写明后果');

await clickCancel();
await page.waitForTimeout(300);
ok(await page.evaluate(() => S.pendingCard) === null,
   '★ 点「换一张」后待绑卡号被清空（残留会导致下一步把这张快到期的卡绑出去）');
ok(await page.locator('#member-new').evaluate(el => !el.open), '建卡表单没有展开');
ok(await page.evaluate(() => document.querySelector('#member-input').value) === '',
   '输入框也清空了，可以直接扫下一张');

await lookup(fixture.soon);
await waitDialog();
await clickOk();
await page.waitForTimeout(300);
ok(await page.locator('#member-new').evaluate(el => el.open), '★ 点「仍然发这张」才放行，表单展开');
ok((await page.locator('#new-card-hint').textContent()).includes(fixture.soon), '表单里带出的是这张卡');
ok(/有效期至/.test(await page.locator('#new-card-hint').textContent()), '提示里带着有效期');

// ── ② 从没绑过人的过期库存卡：这张卡废了 ──────────────────
console.log('\n【② 库存卡放到过期】');
await lookup(fixture.expiredStock);
await page.waitForTimeout(500);
let err = await page.locator('#member-err').textContent();
ok(/从未启用过/.test(err), `直接说这张废了，让收银员换一张：「${err.trim()}」`);
ok(await page.locator('#member-new').evaluate(el => !el.open), '不展开建卡表单');
ok(await page.evaluate(() => S.pendingCard) === null, '不会把过期卡挂成待绑');

// ── ③ 绑着会员的过期卡：换卡 ──────────────────────────────
console.log('\n【③ 换卡】');
await lookup(fixture.expiredBound);
msg = await waitDialog();
ok(/已于 .* 过期/.test(msg), `认出是过期卡：「${msg.split('\n')[0]}」`);
ok(msg.includes('88 分'), '★ 弹层里直接带出这张卡的积分，收银员不用再查一遍');
ok(msg.includes('已消费 4 次'), '计次也带出来了');
ok(await page.locator('.ui-ok').textContent() === '换发新卡', '主按钮是「换发新卡」');

// 先验「暂不处理」
await clickCancel();
await page.waitForTimeout(300);
err = await page.locator('#member-err').textContent();
ok(/可随时到店换发新卡/.test(err), `点「暂不处理」后留一句话，不是死路：「${err.trim()}」`);

// 再走一遍，这次真换
await lookup(fixture.expiredBound);
await waitDialog();
await clickOk();
msg = await waitDialog();
ok(/新卡/.test(msg), '★ 接着弹的是输入框，让收银员扫新卡');
ok(await page.locator('.ui-ask-input').isVisible(), '确实是输入型弹层，不是确认框');

// 先故意扫一张不存在的，验它会让你重扫而不是直接放弃
await page.fill('.ui-ask-input', 'TK-99999999-ABC');
let t = '';
await Promise.all([waitFreshToast(/不是本店发行|卡号/).then(v => { t = v; }).catch(() => {}), clickOk()]);
ok(t !== '', `扫错卡有提示：「${String(t).trim()}」`);
await page.waitForTimeout(300);
ok(await page.locator('.ui-ask-input').isVisible(),
   '★ 扫错一张之后输入框还在，可以接着扫下一张（不用从头再来）');

// 换成真卡
await page.fill('.ui-ask-input', fixture.fresh);
t = '';
await Promise.all([waitFreshToast(/已换发/).then(v => { t = v; }).catch(() => {}), clickOk()]);
ok(/已换发/.test(t) && t.includes(fixture.fresh), `★ 换发成功：「${String(t).trim()}」`);
ok(/积分已转移/.test(t), '提示里点明积分转过去了');

await page.waitForSelector('#member-modal', { state: 'hidden', timeout: 5000 });
ok(true, '★ 换完直接把这位会员选上并关闭弹层（收银员不用再查一遍）');

// ── ④ 换完之后，账要对得上 ────────────────────────────────
console.log('\n【④ 换完之后】');
const after = JSON.parse(php(`
  require "app/bootstrap.php";
  $c = require "app/config/config.php";
  $a = new Vip\\App($c);
  $m = $a->members()->findById(${fixture.memberId});
  echo json_encode(['card' => $m['card_no'], 'points' => (int)$m['points_balance'], 'visits' => (int)$m['visit_count']]);
`));
ok(after.card === fixture.fresh.replace(/-/g, ''), `会员行已指向新卡（${after.card}）`);
ok(after.points === 88, '★ 88 分一分没少');
ok(after.visits === 4,  '★ 消费次数也带过来了');

// 新卡再扫一次，应当直接认出会员
await page.evaluate(() => openMemberModal('manual'));
await page.waitForSelector('#member-modal:not([hidden])');
await lookup(fixture.fresh);
await page.waitForTimeout(600);
const found = await page.locator('#member-result').textContent();
ok(found.includes(fixture.fresh), `新卡扫得出这位会员：「${found.trim().split('\n')[0]}」`);
ok(/88 分/.test(found), '积分显示的是结转后的 88 分');

// 旧卡再扫，不该还能换第二次
await lookup(fixture.expiredBound);
await page.waitForTimeout(600);
const stillThere = await page.locator('.ui-ask').evaluate(el => !el.hidden).catch(() => false);
if (stillThere) {
  msg = await page.locator('.ui-ask-msg').textContent();
  ok(!/88 分/.test(msg), '★ 旧卡已作废，不会再带着积分弹一次换卡');
  await clickCancel();
} else {
  err = await page.locator('#member-err').textContent();
  ok(err.trim() !== '', `★ 旧卡换过之后就认不出了：「${err.trim()}」`);
}

console.log('\n【JS 错误】');
ok(errs.length === 0, errs.length ? '有报错：' + errs.join(' | ') : '无 JS 报错');

await browser.close();

// ── 清理：卡、会员、流水一个不留 ──────────────────────────
php(`
  require "app/bootstrap.php";
  $c = require "app/config/config.php";
  $a = new Vip\\App($c);
  $a->localDb()->exec('DELETE FROM point_ledger WHERE member_id = ?', [${fixture.memberId}]);
  $a->localDb()->exec('DELETE FROM card WHERE batch_no LIKE ?', ['${TAG}%']);
  $a->localDb()->exec('DELETE FROM member WHERE id = ?', [${fixture.memberId}]);
`);

console.log(`\n${'─'.repeat(50)}\n${fail === 0 ? '全部通过' : '失败 ' + fail}  ${pass + fail} 项\n`);
process.exit(fail ? 1 : 0);
