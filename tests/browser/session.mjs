/**
 * 会话在平板上要活得住。
 *
 * ★ 这个文件是补一次现场事故的：**熄屏一会儿再打开就要求重新登录。**
 *
 *   查下来不是有效期问题 —— Cookie 与服务端会话都是 12 小时。
 *   是 Android WebView 的老毛病：**Cookie 默认只在内存里，
 *   要 CookieManager.flush() 才落盘**。熄屏后系统回收 WebView 进程，
 *   没 flush 的 Cookie 就没了。
 *
 *   正经的修法在容器侧（onPause 里 flush），但那要每台平板都装到新版
 *   容器才生效，而 apk/ 已经不在本仓库里。所以 Web 这边自己兜底：
 *   令牌同时存一份进 localStorage，请求时带个头。
 *
 *   下面第 ② 段就是把这次事故复现出来 —— 直接清掉全部 Cookie，
 *   再看会话还在不在。修之前这一条必红。
 */
import { launch, BASE } from './_launch.mjs';
import { execSync } from 'node:child_process';

const REPO = '/home/user/ppppp';
let pass = 0, fail = 0;
const ok = (c, m) => { c ? (pass++, console.log('  \x1b[32m✓\x1b[0m ' + m)) : (fail++, console.log('  \x1b[31m✗\x1b[0m ' + m)); };
const php = (code) => execSync('php', { cwd: REPO, encoding: 'utf8', input: '<?php ' + code }).trim();

const browser = await launch();
const ctx = await browser.newContext({ viewport: { width: 1024, height: 768 } });
const page = await ctx.newPage();
const errs = [];
page.on('pageerror', e => errs.push(String(e)));

const login = async () => {
  await page.goto(BASE + '/', { waitUntil: 'networkidle' });
  if (await page.locator('#view-login.active').count() > 0) {
    await page.fill('#login-name', 'admin');
    await page.fill('#login-pin', 'admin123');
    await page.click('#btn-login');
  }
  await page.waitForSelector('#view-main.active', { timeout: 8000 });
};

console.log('\n【① 登录后令牌同时存了两份】');
await login();
const stored = await page.evaluate(() => { try { return localStorage.getItem('vip_session_token'); } catch { return null; } });
ok(typeof stored === 'string' && stored.length >= 32,
   `★★ localStorage 里有令牌（${stored ? stored.slice(0, 8) + '…' : '没有'}）`);
const cookies = await ctx.cookies();
const sc = cookies.find(c => c.name === 'vip_session');
ok(!!sc, '★ Cookie 也在（它仍然是第一优先）');
ok(sc && sc.httpOnly, '  └ Cookie 仍然是 httpOnly —— 没有为了这个后备路把它降级');
ok(sc && sc.expires > 0, '  └ Cookie 带 expires，不是会话 Cookie');

console.log('\n【② 复现事故：Cookie 全没了，会话还得在】');
/**
 * 这就是熄屏后 WebView 进程被回收、Cookie 没落盘的效果。
 */
await ctx.clearCookies();
ok((await ctx.cookies()).length === 0, 'Cookie 已全部清空（模拟进程被回收）');
await page.goto(BASE + '/', { waitUntil: 'networkidle' });
await page.waitForTimeout(1200);
ok(await page.locator('#view-main.active').count() > 0,
   '★★★ 重新打开仍然是登录状态 —— 这一条修之前必红');
ok(await page.locator('#view-login.active').count() === 0, '  └ 没有退回登录页');
const name = (await page.locator('#op-name').textContent()) || '';
ok(name.trim() !== '', `  └ 顶栏还认得出是谁：「${name.trim()}」`);

console.log('\n【③ 退出要把两份都清掉】');
await page.click('#btn-logout');
await page.waitForSelector('#view-login.active', { timeout: 5000 });
const afterOut = await page.evaluate(() => { try { return localStorage.getItem('vip_session_token'); } catch { return null; } });
ok(afterOut === null,
   '★★ 退出后 localStorage 里的令牌也删了 —— 只作废服务端那份不算退出');
await page.goto(BASE + '/', { waitUntil: 'networkidle' });
await page.waitForTimeout(1000);
ok(await page.locator('#view-login.active').count() > 0, '★ 重新打开是登录页，不会被上一个人的会话带进去');

console.log('\n【④ 服务端否掉的令牌，本地不能一直留着】');
await login();
// 服务端把这个账号的会话全作废（相当于「PIN 改了」「被经理踢下线」）
php(`
  require "app/bootstrap.php";
  $c = require "app/config/config.php";
  $a = new Vip\\App($c);
  $a->localDb()->exec('UPDATE operator_session SET revoked_at = ? WHERE store_code = ?',
                      [date('Y-m-d H:i:s'), $c['store_code']]);
`);
await page.goto(BASE + '/', { waitUntil: 'networkidle' });
await page.waitForTimeout(1200);
ok(await page.locator('#view-login.active').count() > 0, '★ 会话被作废后回到登录页');
const stale = await page.evaluate(() => { try { return localStorage.getItem('vip_session_token'); } catch { return null; } });
ok(stale === null,
   '★★ 失效的令牌已从 localStorage 清掉 —— 留着只会每次请求白带一遍');

console.log('\n【⑤ 滑动续期：一直在用就不该掉线】');
/**
 * 原来是从登录起硬性 12 小时，到点就掉线，哪怕平板正忙。
 * 晚市高峰突然要求重新登录，是现场最不需要的打断。
 */
const slide = JSON.parse(php(`
  require "app/bootstrap.php";
  $c = require "app/config/config.php";
  $a = new Vip\\App($c); $db = $a->localDb();
  $auth = new Vip\\Service\\AuthService($db, $c['store_code'], $a->audit());
  $r = $auth->login('admin', 'admin123', 'SESSTEST', null);
  $h = hash('sha256', $r['token']);
  // 把它改成「只剩 1 小时就到期」
  $db->exec('UPDATE operator_session SET expires_at = ? WHERE token_hash = ?',
            [date('Y-m-d H:i:s', time() + 3600), $h]);
  $before = (string)$db->value('SELECT expires_at FROM operator_session WHERE token_hash = ?', [$h]);
  $ok = $auth->resolve($r['token']) !== null;      // 用一下
  $after = (string)$db->value('SELECT expires_at FROM operator_session WHERE token_hash = ?', [$h]);

  // 再来一个还剩很久的，确认不会每次都写库
  $r2 = $auth->login('admin', 'admin123', 'SESSTEST2', null);
  $h2 = hash('sha256', $r2['token']);
  $b2 = (string)$db->value('SELECT expires_at FROM operator_session WHERE token_hash = ?', [$h2]);
  $auth->resolve($r2['token']);
  $a2 = (string)$db->value('SELECT expires_at FROM operator_session WHERE token_hash = ?', [$h2]);

  $db->exec('DELETE FROM operator_session WHERE device LIKE ?', ['SESSTEST%']);
  echo json_encode(['ok' => $ok, 'before' => $before, 'after' => $after,
                    'freshBefore' => $b2, 'freshAfter' => $a2]);
`));
ok(slide.ok, '会话有效');
ok(slide.after > slide.before,
   `★★★ 快到期时用一下就自动续满（${slide.before.slice(11)} → ${slide.after.slice(11)}）`);
ok(slide.freshAfter === slide.freshBefore,
   '★★ 还剩很久的不写库 —— 一天几千次记账不该变成几千次多余的行更新');

console.log('\n【JS 错误】');
ok(errs.length === 0, errs.length ? '有报错：' + errs.join(' | ') : '无 JS 报错');

await browser.close();
console.log(`\n${'─'.repeat(50)}\n${fail === 0 ? '全部通过' : '失败 ' + fail}  ${pass + fail} 项\n`);
process.exit(fail ? 1 : 0);
