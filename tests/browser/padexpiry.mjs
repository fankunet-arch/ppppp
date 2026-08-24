/**
 * 有效期相关的四条【只有真浏览器能验】的路径。
 *
 *   1. 发卡时的拦阻   —— 快到期的库存卡，发出去之前要问一句
 *   2. 换卡           —— 扫到过期卡，当场换一张新的，积分转过去
 *   3. 到期前的提醒   —— 在用的卡快到期时每次扫都提醒，可以「稍后再说」
 *   4. 超出宽限期     —— 前台换不了，必须经理填原因强制换发
 *
 * 服务端那几段在 smoke ⑰ 已经验过了；这里验的是**界面把它们串起来对不对**：
 * 弹层弹没弹、点「换一张」有没有真的把待绑卡号清掉、
 * 换卡弹的是输入框而不是确认框、换完有没有直接把会员选上。
 *
 * 第 3 条最值得盯：它必须是【每次都提醒】。做成「提醒过一次就记下不再问」
 * 看着更清爽，但最后一次提醒之后就再没人提，卡直接过期 —— 而收银员
 * 当时跳过的理由（忙、新卡没到、客人不想换）下次根本不成立。
 */
import { launch, BASE } from './_launch.mjs';
import { execSync } from 'node:child_process';

const REPO = '/home/user/ppppp';
let pass = 0, fail = 0;
const ok = (c, m) => { c ? (pass++, console.log('  \x1b[32m✓\x1b[0m ' + m)) : (fail++, console.log('  \x1b[31m✗\x1b[0m ' + m)); };

/**
 * 用管道喂 PHP，省得把整段代码塞进 -r 的引号地狱。
 *
 * 出错时必须把 PHP 的报错原样抛出来 —— 默认的 execSync 只给一句
 * 「Command failed: php」，造数失败时等于什么线索都没有。
 */
const php = (code) => {
  /**
   * ★ 这段 shutdown 钩子不能省。
   *
   *   脚本从 stdin 喂进去时，PHP 的致命错误【一个字都不打】——
   *   stdout、stderr 全空，只留一个 255 退出码。`-d display_errors=stderr`
   *   也救不回来。于是造数一失败就完全没有线索，只能靠猜。
   *   自己挂一个 shutdown 钩子把 error_get_last() 打到 stderr，
   *   才问得出到底哪一句炸了。
   */
  const probe = 'register_shutdown_function(function () {'
    + ' $e = error_get_last();'
    + ' if ($e !== null && ($e["type"] & (E_ERROR | E_PARSE | E_COMPILE_ERROR)) !== 0) {'
    + '   fwrite(STDERR, $e["message"] . "\n  @ " . $e["file"] . ":" . $e["line"] . "\n");'
    + ' }});';
  try {
    return execSync('php', {
      cwd: REPO, encoding: 'utf8', input: '<?php ' + probe + code,
      stdio: ['pipe', 'pipe', 'pipe'],
    }).trim();
  } catch (e) {
    const detail = [e.stdout, e.stderr].filter(Boolean).join('\n').trim();
    throw new Error('造数 PHP 失败：\n' + (detail || '(PHP 没有输出)'));
  }
};

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
  $warn = $a->cards()->generateBatch("${TAG}D", 2, $far);   // 一张改成「快到期」的在用卡 + 换发用
  $over = $a->cards()->generateBatch("${TAG}E", 2, $far);   // 一张改成「超出宽限期」+ 换发用

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

  // ── 在用但快到期的卡：验「每次扫都提醒」 ──
  $rw   = $a->cardService()->bindNewMember($warn[0]['display'], null, null, null, $op);
  $wMid = (int)$rw['member']['id'];
  $a->localDb()->exec('UPDATE card SET valid_to = ? WHERE store_code = ? AND card_no = ?',
    [$soon, $c['store_code'], Vip\\CardNumber::normalize($warn[0]['display'])]);

  // ── 超出宽限期的卡：验「必须经理」 ──
  $ro   = $a->cardService()->bindNewMember($over[0]['display'], null, null, null, $op);
  $oMid = (int)$ro['member']['id'];
  $grace= $a->cardService()->graceMonths();
  $long = date('Y-m-d', strtotime('-' . ($grace + 3) . ' months'));
  $a->localDb()->exec('UPDATE card SET valid_to = ? WHERE store_code = ? AND card_no = ?',
    [$long, $c['store_code'], Vip\\CardNumber::normalize($over[0]['display'])]);

  echo json_encode([
    'expiredBound'  => $ok[0]['display'],   // 已绑会员 + 已过期 → 换卡入口
    'fresh'         => $ok[1]['display'],   // 换发用的新卡
    'soon'          => $soonB[0]['display'],// 剩 20 天的库存卡
    'expiredStock'  => $dead[0]['display'], // 从没绑过人就过期了
    'warnCard'      => $warn[0]['display'], // 在用，剩 20 天
    'warnFresh'     => $warn[1]['display'],
    'overCard'      => $over[0]['display'], // 在用，过期超出宽限期
    'overFresh'     => $over[1]['display'],
    'memberId'      => $mid,
    'warnMemberId'  => $wMid,
    'overMemberId'  => $oMid,
    'graceMonths'   => $grace,
    'longAgo'       => $long,
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

// ── ⑤ 在用但快到期：每次扫都提醒，可跳过 ──────────────
console.log('\n【⑤ 到期前的反复提醒】');
await page.evaluate(() => openMemberModal('manual'));
await page.waitForSelector('#member-modal:not([hidden])');

await lookup(fixture.warnCard);
msg = await waitDialog();
ok(/还有 \d+ 天到期/.test(msg), `在用的卡快到期时会主动提醒：「${msg.split('\n')[0]}」`);
ok(await page.locator('.ui-cancel').textContent() === '稍后再说',
   '★ 默认给的是「稍后再说」—— 不换也不该拖慢收银台');
ok(await page.locator('.ui-ok').textContent() === '现在换卡', '要换的话按钮就在这儿');
ok(/不影响这次消费/.test(msg), '明说不换也不耽误这一单');

await clickCancel();
await page.waitForTimeout(300);
ok(await page.locator('#btn-use-member').isVisible(),
   '★ 点「稍后再说」之后这张卡照常能用（会员结果还在，能直接选用）');

// 关键：再扫一次还要再提醒 —— 系统不替他们记「已读」
await lookup(fixture.warnCard);
msg = await waitDialog();
ok(/还有 \d+ 天到期/.test(msg),
   '★★ 再扫一次【还是】会提醒 —— 忙过去了、新卡到了，下次就该换了');
await clickCancel();
await page.waitForTimeout(300);

// 这次真换
await lookup(fixture.warnCard);
await waitDialog();
await clickOk();
msg = await waitDialog();
ok(/新卡/.test(msg), '点「现在换卡」进的是同一套换卡流程');
await page.fill('.ui-ask-input', fixture.warnFresh);
t = '';
await Promise.all([waitFreshToast(/已换发/).then(v => { t = v; }).catch(() => {}), clickOk()]);
ok(/已换发/.test(t), `★ 提前换卡成功：「${String(t).trim()}」`);

await page.waitForSelector('#member-modal', { state: 'hidden', timeout: 5000 });
const warnAfter = JSON.parse(php(`
  require "app/bootstrap.php";
  $c = require "app/config/config.php";
  $a = new Vip\\App($c);
  $m = $a->members()->findById(${fixture.warnMemberId});
  echo json_encode(['card' => $m['card_no']]);
`));
ok(warnAfter.card === fixture.warnFresh.replace(/-/g, ''), '会员行已指向新卡');

// ── ⑥ 超出宽限期：普通账号换不了，必须经理 ──────────────
console.log('\n【⑥ 超出宽限期】');
await page.evaluate(() => openMemberModal('manual'));
await page.waitForSelector('#member-modal:not([hidden])');
await lookup(fixture.overCard);
msg = await waitDialog();
ok(/已于 .* 过期/.test(msg), '超期卡照样认得出来');
await clickOk();                       // 换发新卡
await waitDialog();                    // 输入新卡号
await page.fill('.ui-ask-input', fixture.overFresh);
await clickOk();
msg = await waitDialog();
ok(/超过 \d+ 个月的宽限期/.test(msg),
   `★ 超出宽限期时拦下来并说清原因：「${msg.split('\n')[0]}」`);
ok(msg.includes(fixture.longAgo), '把过期日期一并说出来');
ok(await page.locator('.ui-cancel').textContent() === '按规则拒绝',
   '★ 取消按钮写的是「按规则拒绝」—— 让经理明白这一按是什么意思');

await clickCancel();
await page.waitForTimeout(400);
err = await page.locator('#member-err').textContent();
ok(/已按规则失效/.test(err), `拒绝后留一句话：「${err.trim()}」`);

const overMid = JSON.parse(php(`
  require "app/bootstrap.php";
  $c = require "app/config/config.php";
  $a = new Vip\\App($c);
  $m = $a->members()->findById(${fixture.overMemberId});
  echo json_encode(['card' => $m['card_no']]);
`));
ok(overMid.card === fixture.overCard.replace(/-/g, ''),
   '★ 被拒之后旧卡没被动过（不能拒了还把卡作废）');

// 走一遍强制换发
await lookup(fixture.overCard);
await waitDialog();
await clickOk();
await waitDialog();
await page.fill('.ui-ask-input', fixture.overFresh);
await clickOk();
await waitDialog();
ok(await page.locator('.ui-ok').evaluate(el => el.classList.contains('ui-danger')),
   '强制换发是危险操作，红色按钮');
await clickOk();
msg = await waitDialog();
ok(/原因/.test(msg), '★ 必须填原因才能强制换发');
ok((await page.locator('.ui-ask-input').inputValue()).length > 0, '给了一句默认原因，省得现打字');

// 空原因不该放行
await page.fill('.ui-ask-input', '   ');
await clickOk();
await page.waitForTimeout(300);
ok(await page.locator('.ui-ask').evaluate(el => !el.hidden), '★ 原因填空白时弹层不关');

await page.fill('.ui-ask-input', '老客户，经理同意保留积分');
t = '';
await Promise.all([waitFreshToast(/已强制换发/).then(v => { t = v; }).catch(() => {}), clickOk()]);
ok(/已强制换发/.test(t) && /积分已保留/.test(t), `★ 经理强制换发成功：「${String(t).trim()}」`);

const forced = JSON.parse(php(`
  require "app/bootstrap.php";
  $c = require "app/config/config.php";
  $a = new Vip\\App($c);
  $m = $a->members()->findById(${fixture.overMemberId});
  $l = $a->localDb()->one('SELECT detail FROM audit_log WHERE store_code=? AND action=? ORDER BY id DESC',
        [$c['store_code'], 'card_replace_forced']);
  echo json_encode(['card' => $m['card_no'], 'detail' => (string)($l['detail'] ?? '')]);
`));
ok(forced.card === fixture.overFresh.replace(/-/g, ''), '会员行指向新卡');
ok(forced.detail.includes('经理同意保留'), '★ 原因记进了 card_replace_forced 审计事件');

console.log('\n【JS 错误】');
ok(errs.length === 0, errs.length ? '有报错：' + errs.join(' | ') : '无 JS 报错');

await browser.close();

// ── 清理：卡、会员、流水一个不留 ──────────────────────────
php(`
  require "app/bootstrap.php";
  $c = require "app/config/config.php";
  $a = new Vip\\App($c);
  foreach ([${fixture.memberId}, ${fixture.warnMemberId}, ${fixture.overMemberId}] as $id) {
    $a->localDb()->exec('DELETE FROM point_ledger WHERE member_id = ?', [$id]);
  }
  $a->localDb()->exec('DELETE FROM card WHERE batch_no LIKE ?', ['${TAG}%']);
  foreach ([${fixture.memberId}, ${fixture.warnMemberId}, ${fixture.overMemberId}] as $id) {
    $a->localDb()->exec('DELETE FROM member WHERE id = ?', [$id]);
  }
`);

console.log(`\n${'─'.repeat(50)}\n${fail === 0 ? '全部通过' : '失败 ' + fail}  ${pass + fail} 项\n`);
process.exit(fail ? 1 : 0);
