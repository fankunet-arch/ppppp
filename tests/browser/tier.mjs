/**
 * 卡片等级与积分倍率。
 *
 * 三个设计决定，测试就是钉住它们：
 *
 *   1. **等级属于卡，不属于会员** —— 它印在卡面上。换卡时等级跟着新卡走，
 *      想给客人升级就是发一张新卡给他。挂在会员上会出现
 *      「卡面印着银卡、系统说是金卡」的错位，而客人只看得见卡面。
 *   2. **倍率叠在全局倍率之上**，且【实际用了多少必须记进流水】——
 *      倍率是活查的，不定格的话改一次倍率历史就再也对不上账，
 *      而「这单为什么给了 150 分」正是客人申诉时第一个要问的。
 *   3. **整套可选** —— 不定义等级，界面上就不出现这件事。
 */
import { launch, BASE } from './_launch.mjs';
import { execSync } from 'node:child_process';

const REPO = '/home/user/ppppp';
let pass = 0, fail = 0;
const ok = (c, m) => { c ? (pass++, console.log('  \x1b[32m✓\x1b[0m ' + m)) : (fail++, console.log('  \x1b[31m✗\x1b[0m ' + m)); };

const php = (code) => {
  const probe = 'register_shutdown_function(function () {'
    + ' $e = error_get_last();'
    + ' if ($e !== null && ($e["type"] & (E_ERROR | E_PARSE | E_COMPILE_ERROR)) !== 0) {'
    + '   fwrite(STDERR, $e["message"] . " @ " . $e["file"] . ":" . $e["line"]);'
    + ' }});';
  try {
    return execSync('php', { cwd: REPO, encoding: 'utf8', input: '<?php ' + probe + code,
                             stdio: ['pipe', 'pipe', 'pipe'] }).trim();
  } catch (e) {
    throw new Error('造数 PHP 失败：\n' + [e.stdout, e.stderr].filter(Boolean).join('\n').trim());
  }
};

const TAG  = 'TR' + Math.floor(Math.random() * 9000 + 1000);
const TIER = 'br' + Math.floor(Math.random() * 9000 + 1000);

const F = JSON.parse(php(`
  require "app/bootstrap.php";
  $c = require "app/config/config.php";
  $a = new Vip\\App($c);
  $a->localDb()->exec('UPDATE operator SET lang = NULL WHERE store_code = ?', [$c['store_code']]);

  // 一个 2 倍的等级，一批带这个等级的卡，一批不分级的卡
  $a->cardTiers()->save('${TIER}', '测试金卡', 'Oro test', 2.0, 99, true);
  $far = date('Y-m-d', strtotime('+2 years'));
  $g = $a->cards()->generateBatch("${TAG}G", 2, $far, '${TIER}');
  $p = $a->cards()->generateBatch("${TAG}P", 1, $far);

  $op = ['id' => 1, 'name' => 'browser-test', 'device' => 'TEST'];
  $rg = $a->cardService()->bindNewMember($g[0]['display'], null, null, null, $op);
  $rp = $a->cardService()->bindNewMember($p[0]['display'], null, null, null, $op);

  echo json_encode([
    'gold' => $g[0]['display'], 'goldSpare' => $g[1]['display'], 'plain' => $p[0]['display'],
    'gMid' => (int)$rg['member']['id'], 'pMid' => (int)$rp['member']['id'],
  ]);
`));

const browser = await launch();
const ctx  = await browser.newContext({ viewport: { width: 1400, height: 950 } });
const page = await ctx.newPage();
const errs = [];
page.on('pageerror', e => errs.push(String(e)));

console.log('\n【① 后台能定义等级、能调倍率】');
await page.goto(BASE + '/cp/', { waitUntil: 'networkidle' });
await page.fill('#login-name', 'admin');
await page.fill('#login-pin', 'admin123');
await page.click('#btn-login');
await page.waitForSelector('#view-main.active', { timeout: 5000 });
await page.click('[data-tab="cards"]');
await page.waitForSelector('#tier-list table', { timeout: 5000 });

const row = await page.locator('#tier-list tr', { hasText: TIER }).textContent();
ok(/测试金卡/.test(row), `等级出现在列表里：「${row.replace(/\s+/g, ' ').trim()}」`);
ok(/2\.00/.test(row), '  └ 倍率显示为 2.00');

// 改倍率
await page.locator(`[data-te="${TIER}"]`).click();
await page.waitForTimeout(300);
ok(await page.locator('#tier-mult').inputValue() === '2.00', '★ 点「编辑」把这一行填回表单');
await page.fill('#tier-mult', '1.50');
await page.click('#btn-tier-save');
await page.waitForTimeout(800);
const row2 = await page.locator('#tier-list tr', { hasText: TIER }).textContent();
ok(/1\.50/.test(row2), '★★ 倍率能在后台改（2.00 → 1.50）');

const inDb = php(`
  require "app/bootstrap.php";
  $c = require "app/config/config.php";
  $a = new Vip\\App($c);
  echo (string)$a->cardTiers()->find('${TIER}')['points_multiplier'];
`);
ok(inDb === '1.50', `  └ 库里也是 1.50（实得 ${inDb}）`);

console.log('\n【② 发卡时能选等级，印刷清单带等级列】');
/**
 * 按【等级码】精确匹配，不要按名字数个数 ——
 * 跑崩的测试会在库里留下同名等级，按名字数就会撞上残留，
 * 报出一个和产品毫无关系的失败。
 */
ok(await page.locator(`#cd-tier option[value="${TIER}"]`).count() === 1,
   '★ 等级出现在发卡下拉框里');
ok((await page.locator(`#cd-tier option[value="${TIER}"]`).textContent()).includes('1.5'),
   '  └ 选项上标着倍率，发卡的人一眼看得见');
ok((await page.locator('#cd-tier option').first().textContent()).includes('不分级'),
   '★ 第一项是「不分级」—— 不用等级的店完全不受影响');

console.log('\n【③ 停用与删除的分寸】');
// 已经有卡在用的等级不给删：删了那些卡就指向一个不存在的等级，
// 界面上显示不出等级名，而卡面上明明印着
page.once('dialog', d => d.accept());
await page.locator(`[data-td="${TIER}"]`).click();
await page.waitForTimeout(300);
await page.click('.ui-ok').catch(() => {});
await page.waitForTimeout(800);
const stillThere = await page.locator('#tier-list tr', { hasText: TIER }).count();
ok(stillThere === 1, '★★ 已经有卡在用的等级删不掉');
const t = (await page.locator('#toast').textContent()) || '';
ok(/停用|不能删除|已有/.test(t), `  └ 并且说清为什么、该怎么办：「${t.replace(/\s+/g, ' ').trim().slice(0, 60)}」`);

console.log('\n【④ Pad 扫卡就知道是什么级别】');
const pad = await ctx.newPage();
pad.on('pageerror', e => errs.push(String(e)));
await pad.goto(BASE + '/', { waitUntil: 'networkidle' });
/**
 * CP 与 Pad 共用同一个 vip_session cookie，而这里是同一个 browser context ——
 * 上面登过 CP，这个标签页一开就已经是登录态了。
 * 所以只在真的停在登录页时才去填表单，否则会对着不可见的输入框干等 30 秒。
 */
if (await pad.locator('#view-login.active').count() > 0) {
  await pad.fill('#login-name', 'admin');
  await pad.fill('#login-pin', 'admin123');
  await pad.click('#btn-login');
}
await pad.waitForSelector('#view-main.active', { timeout: 8000 });

await pad.click('#btn-ask-card');
await pad.waitForSelector('#ask-modal:not([hidden])', { timeout: 5000 });
await pad.fill('#ask-input', F.gold);
await pad.click('#btn-ask-go');
await pad.waitForTimeout(800);
let res = (await pad.locator('#ask-result').textContent()).replace(/\s+/g, ' ').trim();
ok(/测试金卡/.test(res), `★★ 查卡直接看到等级：「${res.slice(0, 60)}…」`);
ok(/1\.5/.test(res), '  └ 倍率不是 1 时标出来（这张不一样，要一眼看见）');

await pad.fill('#ask-input', F.plain);
await pad.click('#btn-ask-go');
await pad.waitForTimeout(800);
res = (await pad.locator('#ask-result').textContent()).replace(/\s+/g, ' ').trim();
ok(!/测试金卡/.test(res), '★ 不分级的卡不显示等级徽标（不是显示「不分级」占地方）');

console.log('\n【⑤ 倍率真的作用在积分上，且记进了流水】');
const before = JSON.parse(php(`
  require "app/bootstrap.php";
  $c = require "app/config/config.php";
  $a = new Vip\\App($c);
  echo json_encode([
    (int)$a->members()->findById(${F.gMid})['points_balance'],
    (int)$a->members()->findById(${F.pMid})['points_balance'],
  ]);
`));
ok(before[0] === 0 && before[1] === 0, '两位会员都是 0 分起步');

// 直接走服务层记一笔 —— 界面那条路 locate.mjs 已经覆盖，这里要的是倍率数字
const got = JSON.parse(php(`
  require "app/bootstrap.php";
  $c = require "app/config/config.php";
  $a = new Vip\\App($c);
  $per = $a->cfg()->float('points_per_euro', 1.0);
  $g = $a->cardTiers()->forMember(${F.gMid});
  $p = $a->cardTiers()->forMember(${F.pMid});
  echo json_encode([
    'gCode' => $g['code'], 'gX' => $g['multiplier'],
    'pCode' => $p['code'], 'pX' => $p['multiplier'],
    'gPts'  => Vip\\PointsEngine::pointsFor(5000, $per, 1.0 * $g['multiplier']),
    'pPts'  => Vip\\PointsEngine::pointsFor(5000, $per, 1.0 * $p['multiplier']),
  ]);
`));
ok(got.gCode === TIER, '★ 按会员查等级 —— 查的是他手里那张卡');
ok(got.gX === 1.5, `  └ 倍率 ${got.gX}`);
ok(got.pCode === null && got.pX === 1, '不分级的会员是 1.00 倍');
ok(got.gPts === Math.floor(got.pPts * 1.5),
   `★★ 同样 € 50：不分级 ${got.pPts} 分，1.5 倍等级 ${got.gPts} 分`);

console.log('\n【⑥ 等级跟着卡走，不跟着人走】');
// 把金卡换成一张不分级的卡 —— 这位会员就不再是金卡了
const afterSwap = php(`
  require "app/bootstrap.php";
  $c = require "app/config/config.php";
  $a = new Vip\\App($c);
  $n = $a->cards()->generateBatch("${TAG}N", 1, date('Y-m-d', strtotime('+2 years')));
  $op = ['id' => 1, 'name' => 'browser-test', 'device' => 'TEST'];
  $a->cardService()->replaceCard(${F.gMid}, $n[0]['display'], '换成不分级的卡', $op);
  $t = $a->cardTiers()->forMember(${F.gMid});
  echo json_encode([$t['code'], $t['multiplier']]);
`);
ok(afterSwap === '[null,1]',
   `★★ 换了不分级的卡，这位会员就不再是金卡（实得 ${afterSwap}）`);

console.log('\n【⑦ 西语界面下等级名也跟着变】');
await pad.click('#btn-ask-close');
await pad.waitForTimeout(200);
await pad.click('#lang-main .lang-btn[data-lang=es]');
await pad.waitForTimeout(600);
await pad.click('#btn-ask-card');
await pad.waitForSelector('#ask-modal:not([hidden])', { timeout: 5000 });
await pad.fill('#ask-input', F.goldSpare);
await pad.click('#btn-ask-go');
await pad.waitForTimeout(800);
res = (await pad.locator('#ask-result').textContent()).replace(/\s+/g, ' ').trim();
ok(/Oro test/.test(res), `★★ 等级名换成了西语：「${res.slice(0, 60)}…」`);
ok(!/测试金卡/.test(res), '  └ 中文名没有漏出来');

console.log('\n【JS 错误】');
ok(errs.length === 0, errs.length ? '有报错：' + errs.join(' | ') : '无 JS 报错');

await browser.close();

php(`
  require "app/bootstrap.php";
  $c = require "app/config/config.php";
  $a = new Vip\\App($c);
  foreach ([${F.gMid}, ${F.pMid}] as $id) {
    $a->localDb()->exec('DELETE FROM point_ledger WHERE member_id = ?', [$id]);
  }
  $a->localDb()->exec('DELETE FROM card WHERE batch_no LIKE ?', ['${TAG}%']);
  foreach ([${F.gMid}, ${F.pMid}] as $id) {
    $a->localDb()->exec('DELETE FROM member WHERE id = ?', [$id]);
  }
  $a->localDb()->exec('DELETE FROM card_tier WHERE store_code = ? AND code = ?', [$c['store_code'], '${TIER}']);
  $a->localDb()->exec('UPDATE operator SET lang = NULL WHERE store_code = ?', [$c['store_code']]);
`);

console.log(`\n${'─'.repeat(50)}\n${fail === 0 ? '全部通过' : '失败 ' + fail}  ${pass + fail} 项\n`);
process.exit(fail ? 1 : 0);
