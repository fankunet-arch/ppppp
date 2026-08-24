import { launch, BASE } from './_launch.mjs';
import { execSync } from 'node:child_process';

let pass = 0, fail = 0;
const ok = (c, m) => { c ? (pass++, console.log('  \x1b[32m✓\x1b[0m ' + m)) : (fail++, console.log('  \x1b[31m✗\x1b[0m ' + m)); };

// 备两张库存卡（直接走 PHP，绕开界面，专心测 Pad 侧）
const BATCH = 'PAD' + Math.floor(Math.random() * 9000 + 1000);
const out = execSync(
  `php -r 'require "app/bootstrap.php"; $c=require "app/config/config.php";` +
  ` $a=new Vip\\App($c); $b=$a->cards()->generateBatch("${BATCH}",2);` +
  ` echo $b[0]["display"]."|".$b[1]["display"];'`,
  { cwd: '/home/user/ppppp', encoding: 'utf8' }
).trim();
const [CARD_A] = out.split('|');   // 第二张备用，本用例只需一张

const browser = await launch();
const page = await (await browser.newContext({ viewport: { width: 1280, height: 800 } })).newPage();
const errs = [];
page.on('pageerror', e => errs.push(String(e)));

console.log('\n【Pad 扫卡建会员】');
await page.goto(BASE + '/', { waitUntil: 'networkidle' });
await page.fill('#login-name', 'admin');
await page.fill('#login-pin', 'admin123');
await page.click('#btn-login');
await page.waitForSelector('#view-main.active', { timeout: 5000 });
ok(true, '登录成功');

// 直接打开会员弹层（正常路径要先定位订单，这里只测卡片这一段）
await page.evaluate(() => openMemberModal('manual'));
await page.waitForSelector('#member-modal:not([hidden])');
ok(true, '会员弹层已打开');

// 默认就是「卡号」档，扫卡按钮该显示
ok(await page.locator('#btn-scan').isVisible(), '「卡号」档显示扫卡按钮');
await page.click('#search-type button[data-type="phone"]');
ok(await page.locator('#btn-scan').isHidden(), '★ 切到「手机号」档时扫卡按钮隐藏');
await page.click('#search-type button[data-type="card"]');

/**
 * 等一条【新的】toast。
 *
 * 必须先把旧的清掉再等：#toast 是全局共享元素，上一条有 3 秒存活期，
 * 直接等「可见且文本匹配」会命中残留的旧提示，于是读到上一步的结果。
 * （这条测试第一版就栽在这：明明留了手机号，却读到上一次「当场即可使用」。）
 */
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

const lookup = async (v) => {
  await page.fill('#member-input', v);
  await page.click('#btn-member-search');
  await page.waitForTimeout(500);
};

// ── 不是本店的卡 ──
await lookup('TK-99999999-ABC');
let err = await page.locator('#member-err').textContent();
ok(/不是本店发行/.test(err), `伪造卡被拒：「${err}」`);
ok(await page.locator('#member-new').evaluate(el => !el.open), '被拒时不展开建卡表单');

// ── 扫错了别的二维码 ──
await lookup('https://example.com/whatever');
err = await page.locator('#member-err').textContent();
ok(/卡号不完整/.test(err), `扫错二维码：「${err}」`);

// ── 查过一张有效卡之后再查无效的，不能残留 ──
// 现场反馈：先查对的卡（显示「这张卡尚未启用：TK-xxx」）再查错的卡，
// 上一张的提示还挂在错误旁边，收银员不知道该信哪个。
await lookup(CARD_A);
ok((await page.locator('#new-card-hint').textContent()).includes(CARD_A), '先查一张有效的库存卡');
await lookup('TK-99999999-ABC');
ok((await page.locator('#new-card-hint').textContent()).trim() === '',
   '★ 再查无效卡时，上一张卡的提示被清掉');
ok(await page.locator('#member-new').evaluate(el => !el.open), '建卡表单也收起来了');
ok(await page.evaluate(() => S.pendingCard) === null,
   '★ 待绑定的卡号也一并清空（否则点启用会绑错卡）');

// ── 扫了卡之后改用手机号查找，卡不能还留着 ──
// 这条比上面那条危险：pendingCard 残留时点「启用」会把上一张卡绑给这个人
await lookup(CARD_A);
ok(await page.evaluate(() => S.pendingCard) !== null, '扫卡后 pendingCard 已设置');
await page.click('#search-type button[data-type="phone"]');
await page.fill('#member-input', '600000000000');
await page.click('#btn-member-search');
await page.waitForTimeout(600);
ok(await page.evaluate(() => S.pendingCard) === null,
   '★ 改用手机号查找后，上一张卡的 pendingCard 被清空');
ok((await page.locator('#new-card-hint').textContent()).trim() === '', '卡号提示也清了');
await page.click('#search-type button[data-type="card"]');

// ── 库存卡 → 引导建会员 ──
await lookup(CARD_A);
ok(await page.locator('#member-new').evaluate(el => el.open), '★ 库存卡自动展开建卡表单');
const hint = await page.locator('#new-card-hint').textContent();
ok(hint.includes(CARD_A), `表单里带出卡号：「${hint.trim()}」`);
ok((await page.locator('#member-err').getAttribute('hidden')) !== null, '没有报错');

// 卡片不实名：什么都不填就能启用
// 后台开关 member_collect_pii 默认关闭时，联系方式那一栏整块不在 DOM 里 ——
// 不是 hidden。目的就是让收银员根本没有向客人索要的入口。
ok(await page.locator('#new-contact').count() === 0,
   '★ 关闭收集时联系方式整块从 DOM 移除（不是隐藏）');
ok(await page.locator('#new-phone').count() === 0, '手机号输入框根本不存在');
let t1 = '';
await Promise.all([waitFreshToast(/已绑卡/).then(v => { t1 = v; }).catch(() => {}),
                   page.click('#btn-member-create')]);
ok(/当场即可使用/.test(t1), `★ 不填任何个人信息即可启用，且当场生效（${t1}）`);
await page.waitForSelector('#member-modal', { state: 'hidden', timeout: 5000 });

// ── 再扫同一张 → 认出会员 ──
await page.evaluate(() => openMemberModal('manual'));
await page.waitForSelector('#member-modal:not([hidden])');
await lookup(CARD_A);
const found = await page.locator('#member-result').textContent();
ok(found.includes(CARD_A), `★ 再扫同一张认出会员：「${found.trim().split('\n')[0]}」`);
ok(await page.locator('#btn-use-member').isVisible(), '出现「选用」按钮');
ok(await page.locator('#member-new').evaluate(el => !el.open), '已激活的卡不展开建卡表单');

// ── 手输容错 ──
await page.evaluate(() => openMemberModal('manual'));
await lookup(CARD_A.replace(/0/g, 'O'));
ok((await page.locator('#member-result').textContent()).includes(CARD_A),
   `★ 把 0 打成 O 也能认出（输入 ${CARD_A.replace(/0/g, 'O')}）`);

// ── 扫码降级 ──
// 无头 Chromium 没有 BarcodeDetector，正好验「不支持时不硬撑」
await page.evaluate(() => openMemberModal('manual'));
const hasBD = await page.evaluate(() => typeof window.BarcodeDetector === 'function');
await page.click('#btn-scan');
await page.waitForTimeout(300);
if (hasBD) {
  ok(true, '（本机支持 BarcodeDetector，跳过降级检查）');
} else {
  const msg = await page.locator('#member-err').textContent();
  ok(/手工输入卡号/.test(msg), `★ 不支持扫码时引导手输而不是卡住：「${msg.slice(0, 30)}…」`);
  ok(await page.locator('#scan-modal').isHidden(), '不弹出空的取景框');
}

// ── 返回键要能关掉扫码弹层 ──
await page.evaluate(() => { $('#scan-modal').hidden = false; UI.back.sync(); });
await page.waitForTimeout(200);
await page.goBack();
await page.waitForTimeout(300);
ok(await page.locator('#scan-modal').isHidden(), '★ 返回键关掉扫码弹层');

console.log('\n【JS 错误】');
ok(errs.length === 0, errs.length ? '有报错：' + errs.join(' | ') : '无 JS 报错');

await browser.close();
execSync(`php -r 'require "app/bootstrap.php"; $c=require "app/config/config.php";` +
  ` $l=$c["local_db"]; $p=new PDO("mysql:host={$l["host"]};dbname={$l["database"]};charset=utf8mb4",$l["user"],$l["password"]);` +
  ` $ids=$p->query("SELECT member_id FROM card WHERE batch_no=\\"${BATCH}\\" AND member_id IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);` +
  ` $p->exec("DELETE FROM card WHERE batch_no=\\"${BATCH}\\"");` +
  ` foreach($ids as $i){$p->exec("DELETE FROM member WHERE id=".(int)$i);}'`,
  { cwd: '/home/user/ppppp' });

console.log(`\n${'─'.repeat(50)}\n${fail === 0 ? '全部通过' : '失败 ' + fail}  ${pass + fail} 项\n`);
process.exit(fail ? 1 : 0);
