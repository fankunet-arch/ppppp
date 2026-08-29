/**
 * 门槛口径决定哪一格能填。
 *
 * 后台「奖励规则」里两格并排：「几次送一次」和「累计消费多少送一次」，
 * 而实际生效的永远只有一格 —— 由上面的「门槛口径」定。
 *
 * 两格都能填的后果不是报错，是【店家以为改了、其实没改】：
 * 口径是「按次数」，他把金额那格从 300 改成 200，保存成功、提示「已保存」，
 * 然后等着看效果 —— 什么都不会发生。等发现时已经过去几个星期。
 *
 * 所以用不上的那一格置灰，并写清楚要先改哪一项。
 *
 * ★ 置灰【不清空】：值照旧存在库里，口径切回去原样还在。
 *   这一条比置灰本身更要紧 —— 悄悄清空等于把店家之前的设置弄没了。
 */
import { launch, BASE } from './_launch.mjs';
import { execSync } from 'node:child_process';

const REPO = '/home/user/ppppp';
let pass = 0, fail = 0;
const ok = (c, m) => { c ? (pass++, console.log('  \x1b[32m✓\x1b[0m ' + m)) : (fail++, console.log('  \x1b[31m✗\x1b[0m ' + m)); };

const php = (code) => execSync('php', { cwd: REPO, encoding: 'utf8', input: '<?php ' + code }).trim();
const setCfg = (k, v) => php(`require "app/bootstrap.php"; $c = require "app/config/config.php";
  (new Vip\\App($c))->cfg()->set(${JSON.stringify(k)}, ${JSON.stringify(v)});`);
const getCfg = (k) => php(`require "app/bootstrap.php"; $c = require "app/config/config.php";
  echo (new Vip\\App($c))->cfg()->get(${JSON.stringify(k)}, '');`);

// 先记下原值，跑完还回去
const orig = { mode: getCfg('reward_mode'), v: getCfg('reward_threshold_visits'), a: getCfg('reward_threshold_amount') };

setCfg('reward_mode', 'visits');
setCfg('reward_threshold_visits', '10');
setCfg('reward_threshold_amount', '300.00');

const browser = await launch();
const page = await (await browser.newContext({ viewport: { width: 1280, height: 900 } })).newPage();
const errs = [];
page.on('pageerror', e => errs.push(String(e)));

await page.goto(BASE + '/cp/', { waitUntil: 'networkidle' });
await page.fill('#login-name', 'admin');
await page.fill('#login-pin', 'admin123');
await page.click('#btn-login');
await page.waitForSelector('#view-main.active', { timeout: 8000 });

const openConfig = async () => {
  await page.click('[data-tab="config"]');
  await page.waitForSelector('[data-ck="reward_mode"]', { timeout: 5000 });
};

console.log('\n【① 按次数：金额那格该是灰的】');
await openConfig();
ok(await page.locator('[data-ck="reward_threshold_visits"]').isDisabled() === false,
   '★ 「几次送一次」可填');
ok(await page.locator('[data-ck="reward_threshold_amount"]').isDisabled() === true,
   '★★ 「累计消费多少送一次」置灰 —— 填了也不起作用的格子不该让人填');
ok(await page.locator('[data-ck="reward_threshold_amount"]').inputValue() === '300.00',
   '★★★ 置灰的格子【值还在】（300.00）—— 置灰是不让改，不是清空');
ok(await page.locator('[data-cs="reward_threshold_amount"]').count() === 0,
   '  └ 连「保存」按钮都不出现（否则点了没反应，更像坏了）');

const why = await page.locator('.cfg-item.cfg-off .cfg-why').first().textContent();
ok(/门槛口径/.test(why) && /按金额/.test(why),
   `★★ 写清了要先改哪一项、改成什么：「${why.trim()}」`);

console.log('\n【② 切成按金额：两格对调】');
// 切口径会先弹一个确认，把「切过去之后门槛是多少」摆出来
await page.selectOption('[data-ck="reward_mode"]', 'amount');
await page.waitForSelector('.ui-ask:not([hidden])', { timeout: 3000 });
const ask = await page.locator('.ui-ask-msg').textContent();
ok(/300/.test(ask), `★★ 切换前先摆出切过去之后的门槛：「${ask.replace(/\s+/g, ' ').trim().slice(0, 60)}…」`);
ok(/收不回来/.test(ask), '  └ 并且说清风险：门槛偏低会立刻补发一批券，而券收不回来');
await page.click('.ui-ok');
await page.waitForTimeout(900);

ok(await page.locator('[data-ck="reward_threshold_amount"]').isDisabled() === false,
   '★★ 现在「累计消费多少送一次」可填了');
ok(await page.locator('[data-ck="reward_threshold_visits"]').isDisabled() === true,
   '★★ 「几次送一次」反过来置灰');
ok(await page.locator('[data-ck="reward_threshold_visits"]').inputValue() === '10',
   '★★★ 次数那格的值也还在（10）—— 来回切不丢设置');
ok(getCfg('reward_threshold_visits') === '10', '  └ 库里也没被动过');

console.log('\n【③ 取消切换 = 什么都不变】');
await page.selectOption('[data-ck="reward_mode"]', 'visits');
await page.waitForSelector('.ui-ask:not([hidden])', { timeout: 3000 });
await page.click('.ui-cancel');
await page.waitForTimeout(900);
ok(getCfg('reward_mode') === 'amount', '★★ 取消后口径没变');
ok(await page.locator('[data-ck="reward_mode"]').inputValue() === 'amount',
   '  └ 下拉框也复原了（否则界面说一套、库里是另一套）');

console.log('\n【④ 发卡页的两格同理 —— 两个页面不能各说各话】');
await page.click('[data-tab="cards"]');
await page.waitForSelector('#tier-th-why', { state: 'attached', timeout: 5000 });
await page.waitForTimeout(800);
// 等级表单收在 <details> 里，展开才看得见
await page.evaluate(() => { document.querySelector('#tier-code').closest('details').open = true; });
await page.waitForTimeout(200);
ok(await page.locator('#tier-th-why').isVisible(), '展开等级表单后那句说明看得见');
ok(await page.locator('#tier-tha').isDisabled() === false,
   '★ 按金额口径下，等级的「满额送 1 次」可填');
ok(await page.locator('#tier-thv').isDisabled() === true,
   '★★ 等级的「几次送 1 次」置灰 —— 和配置页保持一致');
const tw = await page.locator('#tier-th-why').textContent();
ok(/按金额/.test(tw) && /门槛口径/.test(tw),
   `★★ 并且指路到改口径的地方：「${tw.trim().slice(0, 50)}…」`);

console.log('\n【JS 错误】');
ok(errs.length === 0, errs.length ? '有报错：' + errs.join(' | ') : '无 JS 报错');

await browser.close();
setCfg('reward_mode', orig.mode || 'visits');
setCfg('reward_threshold_visits', orig.v || '10');
setCfg('reward_threshold_amount', orig.a || '300.00');

console.log(`\n${'─'.repeat(50)}\n${fail === 0 ? '全部通过' : '失败 ' + fail}  ${pass + fail} 项\n`);
process.exit(fail ? 1 : 0);
