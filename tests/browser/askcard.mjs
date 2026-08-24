/**
 * 「查一张卡」—— 客人当面问「我这卡还能用吗」。
 *
 * 后台也有同样的功能，两个都留：客人问的是【服务员】，
 * 让服务员转告经理再回话，既麻烦又没必要；而经理仍然需要在后台查
 * （对账、处理投诉、看作废原因）。
 *
 * 这里守四件事：
 *   1. 四种卡态各给一句【服务员能照着念】的话，且都说清下一步怎么办
 *   2. 只读 —— 查完卡的状态、会员的积分一个字节都没变
 *   3. 不显示手机号/邮箱 —— 卡本来就不实名，服务员没理由看客人的联系方式
 *   4. 西语界面下整条都是西语，包括服务端生成的进度那句
 *      （上线时就是这句中文漏进了西语界面）
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

const TAG = 'ASK' + Math.floor(Math.random() * 9000 + 1000);

// 四种卡态各造一张
const C = JSON.parse(php(`
  require "app/bootstrap.php";
  $c = require "app/config/config.php";
  $a = new Vip\\App($c);
  /**
   * ★ 先把语言重置掉。
   *
   *   语言是跟着【账号】持久化的（operator.lang），所以上一次跑崩、
   *   清理没执行的话，这次一进来就是西语，而下面断言的是中文 ——
   *   于是一片红，看起来像功能坏了，其实是测试没做隔离。
   *   凡是断言具体文案的测试，都要自己先把语言摆正，不能靠上次跑完的状态。
   */
  $a->localDb()->exec('UPDATE operator SET lang = NULL WHERE store_code = ?', [$c['store_code']]);

  $far = date('Y-m-d', strtotime('+2 years'));
  $b   = $a->cards()->generateBatch("${TAG}", 4, $far);
  $op  = ['id' => 1, 'name' => 'browser-test', 'device' => 'TEST'];

  $r2 = $a->cardService()->bindNewMember($b[1]['display'], null, null, null, $op);
  $m2 = (int)$r2['member']['id'];
  $a->members()->applyDelta($m2, 120, 7, 5000);
  $a->localDb()->exec('INSERT INTO point_ledger
        (store_code, member_id, entry_type, amount, points, counted_visit,
         status, source, manual_reason, created_at)
      VALUES (?,?,?,?,?,?,?,?,?,?)',
    [$c['store_code'], $m2, 6, 50.00, 120, 7, 1, 2, '浏览器测试造数', $a->localDb()->now()]);

  $r3 = $a->cardService()->bindNewMember($b[2]['display'], null, null, null, $op);
  $m3 = (int)$r3['member']['id'];
  $a->localDb()->exec('UPDATE card SET valid_to = ? WHERE store_code = ? AND card_no = ?',
    [date('Y-m-d', strtotime('-10 days')), $c['store_code'],
     Vip\\CardNumber::normalize($b[2]['display'])]);

  $c4 = $a->cards()->findByCardNo($b[3]['display']);
  $a->cards()->void((int)$c4['id'], '客人报失');

  echo json_encode(['stock' => $b[0]['display'], 'active' => $b[1]['display'],
                    'expired' => $b[2]['display'], 'void' => $b[3]['display'],
                    'm2' => $m2, 'm3' => $m3]);
`));

const browser = await launch();
const page = await (await browser.newContext({ viewport: { width: 1280, height: 900 } })).newPage();
const errs = [];
page.on('pageerror', e => errs.push(String(e)));

const askFor = async (no) => {
  await page.fill('#ask-input', no);
  await page.click('#btn-ask-go');
  await page.waitForTimeout(700);
  return {
    res: (await page.locator('#ask-result').textContent()).replace(/\s+/g, ' ').trim(),
    err: (await page.locator('#ask-err').textContent()).trim(),
  };
};

console.log('\n【① 入口在第一步 —— 两单之间就能查，不用打断记账】');
await page.goto(BASE + '/', { waitUntil: 'networkidle' });
await page.fill('#login-name', 'admin');
await page.fill('#login-pin', 'admin123');
await page.click('#btn-login');
await page.waitForSelector('#view-main.active', { timeout: 5000 });

// 双保险：库里重置过了，界面上再点一次，确保从中文开始
await page.click('#lang-main .lang-btn[data-lang=zh]').catch(() => {});
await page.waitForTimeout(400);
ok((await page.locator('#btn-logout').textContent()).trim() === '退出', '从中文界面开始');

ok(await page.locator('#btn-ask-card').isVisible(), '★ 找订单那一步就有「查一张卡」的入口');
await page.click('#btn-ask-card');
await page.waitForSelector('#ask-modal:not([hidden])', { timeout: 5000 });
ok(true, '弹层打开');
ok(await page.locator('#ask-input').count() === 1, '有输入框');
ok(await page.locator('#btn-ask-scan').isVisible(), '也能扫卡');

console.log('\n【② 四种卡态各给一句能照着念的话】');

let r = await askFor(C.active);
ok(/可以正常使用/.test(r.res), `在用的卡：「${r.res.slice(0, 60)}…」`);
ok(/120 分/.test(r.res), '  └ 带出积分');
ok(/已消费 7 次/.test(r.res), '  └ 带出消费次数');
ok(/券/.test(r.res), '  └ 说了有没有可用券（客人最想问的就是这个）');
ok(/有效期至/.test(r.res), '  └ 带出有效期');

r = await askFor(C.stock);
ok(/还没启用/.test(r.res), `库存卡：「${r.res.slice(0, 50)}…」`);
ok(/扫一下就能开通/.test(r.res), '  └ 说清下一步怎么办，不是只说「不行」');

r = await askFor(C.expired);
ok(/已过期/.test(r.res), `过期卡：「${r.res.slice(0, 60)}…」`);
ok(/还来得及|换一张/.test(r.res), '★ 还在宽限期内 → 说「现在换一张还来得及」，而不是「不能用」');

r = await askFor(C.void);
ok(/已作废/.test(r.res), `作废卡：「${r.res.slice(0, 40)}…」`);
ok(/客人报失/.test(r.res), '  └ 带出作废原因');

r = await askFor('TK-99999999-ZZZ');
ok(/不是本店发行/.test(r.err), `不存在的卡：「${r.err}」`);
ok(r.res === '', '  └ 上一张的结果被清掉了（不能让服务员看着旧结果回话）');

console.log('\n【③ 只读 —— 查完什么都不能变】');
const before = php(`
  require "app/bootstrap.php";
  $c = require "app/config/config.php";
  $a = new Vip\\App($c);
  $m = $a->members()->findById(${C.m2});
  $k = $a->cards()->findByCardNo('${C.active}');
  echo json_encode([$m['points_balance'], $m['visit_count'], $k['status'], $k['valid_to']]);
`);
for (const no of [C.active, C.expired, C.void, C.stock]) { await askFor(no); }
const after = php(`
  require "app/bootstrap.php";
  $c = require "app/config/config.php";
  $a = new Vip\\App($c);
  $m = $a->members()->findById(${C.m2});
  $k = $a->cards()->findByCardNo('${C.active}');
  echo json_encode([$m['points_balance'], $m['visit_count'], $k['status'], $k['valid_to']]);
`);
ok(before === after, `★★ 反复查过之后，积分/次数/卡态一个字节都没变（${before}）`);

console.log('\n【④ 不显示客人的联系方式】');
// 卡本来就不实名；服务员查卡是为了回答「还能用吗」，不是翻客人的档案。
// 后台那个查卡会显示手机号邮箱，前台这个【故意不显示】。
const withPii = php(`
  require "app/bootstrap.php";
  $c = require "app/config/config.php";
  $a = new Vip\\App($c);
  $a->localDb()->exec('UPDATE member SET phone = ?, email = ? WHERE id = ?',
    ['600111222', 'cliente@example.com', ${C.m2}]);
  echo 'done';
`);
ok(withPii === 'done', '给这位会员填上手机号和邮箱');
r = await askFor(C.active);
ok(!/600111222/.test(r.res), '★★ 结果里没有手机号');
ok(!/cliente@example\.com/.test(r.res), '★★ 结果里没有邮箱');

console.log('\n【⑤ 西语界面下整条都是西语】');
await page.click('#btn-ask-close');
await page.waitForTimeout(200);
await page.click('#lang-main .lang-btn[data-lang=es]');
await page.waitForTimeout(600);
await page.click('#btn-ask-card');
await page.waitForSelector('#ask-modal:not([hidden])', { timeout: 5000 });
r = await askFor(C.active);
ok(!/[一-龥]/.test(r.res),
   `★★ 一个中文字都没有：「${r.res.slice(0, 70)}…」`);
ok(!/«/.test(r.res), '  └ 也没有 «key» 这种漏翻译的痕迹');
ok(/Lleva \d+ visitas/.test(r.res),
   '★ 进度那句也是西语 —— 它是【服务端生成】的，上线时就是这句漏了');

r = await askFor(C.void);
ok(!/[一-龥]/.test(r.res.replace(/客人报失/, '')),
   '作废卡的结论也是西语（作废原因是当初存进去的原文，不翻）');

// 切语言前必须先关弹层 —— 它盖在顶栏上面，挡着语言按钮点不到
await page.click('#btn-ask-close');
await page.waitForTimeout(200);
await page.click('#lang-main .lang-btn[data-lang=zh]');
await page.waitForTimeout(500);

console.log('\n【⑥ 物理返回键要能关掉它】');
await page.click('#btn-ask-card');
await page.waitForSelector('#ask-modal:not([hidden])', { timeout: 5000 });
await page.goBack();
await page.waitForTimeout(400);
ok(await page.locator('#ask-modal').isHidden(),
   '★ 按返回键关掉查卡弹层，而不是弹「确认退出应用」');

console.log('\n【JS 错误】');
ok(errs.length === 0, errs.length ? '有报错：' + errs.join(' | ') : '无 JS 报错');

await browser.close();

// 清理
php(`
  require "app/bootstrap.php";
  $c = require "app/config/config.php";
  $a = new Vip\\App($c);
  foreach ([${C.m2}, ${C.m3}] as $id) {
    $a->localDb()->exec('DELETE FROM point_ledger WHERE member_id = ?', [$id]);
  }
  $a->localDb()->exec('DELETE FROM card WHERE batch_no = ?', ['${TAG}']);
  foreach ([${C.m2}, ${C.m3}] as $id) {
    $a->localDb()->exec('DELETE FROM member WHERE id = ?', [$id]);
  }
  $a->localDb()->exec('UPDATE operator SET lang = NULL WHERE store_code = ?', [$c['store_code']]);
`);

console.log(`\n${'─'.repeat(50)}\n${fail === 0 ? '全部通过' : '失败 ' + fail}  ${pass + fail} 项\n`);
process.exit(fail ? 1 : 0);
