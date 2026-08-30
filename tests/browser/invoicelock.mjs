/**
 * 小票号查单不能当探针用。
 *
 * ── 为什么 ──────────────────────────────────────────
 * 小票号就是 order_head_id，一个【连号的整数】。手里有一张自己的小票
 * 就知道号段在哪儿，往前减一个个试，就能把别人的单翻出来。
 *
 * 原来的界面把三种结果分得清清楚楚：
 *   · 查到了                          → 直接给单
 *   · 「这张小票是 8-16 的，超过 7 天」 → 等于确认【这个号是真的】，还附送日期
 *   · 「没找到小票号 xxx」             → 这个号是空的
 * 一个一个试下去，号段和哪天有生意都能摸出来。
 *
 * ── 三层 ────────────────────────────────────────────
 *   函数层（服务端按角色砍字段）—— tests/http_sweep.php ⑨ 打的是接口本身
 *   文案层（源码扫描）           —— tests/cases/I18nTest.php
 *   界面层（这个文件）           —— 收银员看到的和经理看到的确实不一样
 *
 * 需要 seed 出来的两个账号：admin（经理）、cashier1（收银员），PIN 都是 admin123。
 */
import { launch, BASE } from './_launch.mjs';
import { execSync } from 'node:child_process';

const REPO = '/home/user/ppppp';
let pass = 0, fail = 0;
const ok = (c, m) => { c ? (pass++, console.log('  \x1b[32m✓\x1b[0m ' + m)) : (fail++, console.log('  \x1b[31m✗\x1b[0m ' + m)); };

const php = (code) => execSync('php', { cwd: REPO, encoding: 'utf8', input: '<?php ' + code }).trim();

// 一个真实存在、但早已超过回溯天数的小票号；再要一个根本不存在的
const OLD = php(`
  require "app/bootstrap.php";
  $c = require "app/config/config.php";
  $d = $c['pos_db'];
  $m = new mysqli($d['host'], $d['user'], $d['password'], $d['database'], $d['port']);
  $r = $m->query("SELECT order_head_id FROM history_order_head
                   WHERE order_end_time < DATE_SUB(NOW(), INTERVAL 30 DAY)
                   ORDER BY order_end_time DESC LIMIT 1");
  echo (int)($r->fetch_assoc()['order_head_id'] ?? 0);`);
const FAKE = '99999999';
if (!Number(OLD)) {
  console.error('POS 库里找不到一张 30 天前的单，没法测「超时效」这一支');
  process.exit(1);
}

const browser = await launch();

/** 用某个账号登进 Pad，查一个小票号，回传屏幕上那句话 */
const askAs = async (user, invoice) => {
  const ctx  = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  const page = await ctx.newPage();
  const errs = [];
  page.on('pageerror', e => errs.push(String(e)));
  await page.goto(BASE + '/', { waitUntil: 'networkidle' });
  await page.fill('#login-name', user);
  await page.fill('#login-pin', 'admin123');
  await page.click('#btn-login');
  await page.waitForSelector('#view-main.active', { timeout: 8000 });
  await page.click('#tab-invoice');
  await page.fill('#invoice-input', String(invoice));
  await page.click('#btn-locate-invoice');
  await page.waitForTimeout(1200);
  const msg = (await page.locator('#locate-err').textContent() || '').replace(/\s+/g, ' ').trim();
  await ctx.close();
  return { msg, errs };
};

console.log('\n【① 收银员：查不到与超时效必须是同一句话】');
const clerkOld  = await askAs('cashier1', OLD);
const clerkFake = await askAs('cashier1', FAKE);

ok(clerkOld.msg.length > 0, `收银员查一张超时效的真单 → 「${clerkOld.msg}」`);
ok(clerkOld.msg === clerkFake.msg,
   `★★★ 与查一个不存在的号【一字不差】—— 试的人分不出哪个号是真的\n      不存在：「${clerkFake.msg}」`);
ok(/不存在或已超过时效/.test(clerkOld.msg), '★★ 就是那句笼统的「订单不存在或已超过时效，请联系经理处理」');
ok(!/\d{4}-\d{2}-\d{2}/.test(clerkOld.msg),
   '★★★ 句子里没有日期 —— 「这张小票是 8-16 的」本身就等于「这个号是真的，那天有生意」');
ok(!clerkOld.msg.includes(String(OLD)) && !clerkFake.msg.includes(FAKE),
   '★★ 也不把输进去的号回显出来 —— 回显会让一屏截图就是一份枚举记录');

console.log('\n【② 经理：要分得清，不然没法查错】');
const mgrOld  = await askAs('admin', OLD);
const mgrFake = await askAs('admin', FAKE);
ok(mgrOld.msg !== mgrFake.msg,
   `★★★ 经理看到的两句不一样 —— 超时效：「${mgrOld.msg.slice(0, 34)}…」`);
ok(/经理可见/.test(mgrOld.msg) && /经理可见/.test(mgrFake.msg),
   '★★ 两句都标着「经理可见」—— 免得经理照着念给客人听');
/**
 * ★ 经理【也不给具体日期】—— 只给「在期内 / 在期外」这一个二值。
 *
 *   经理账号一旦外泄（借号、PIN 被看到、离职没停用），
 *   泄露的东西不该比收银员账号多。一个日期就足以确认
 *   「这个号是真的、那天有生意」，等于绕一圈又把预言机装回去。
 */
ok(!/\d{4}-\d{2}-\d{2}/.test(mgrOld.msg),
   `★★★ 经理那句里也没有日期：「${mgrOld.msg}」`);
ok(/超出|fuera del plazo/.test(mgrOld.msg),
   '  └ 但说清了是「有单、只是超出受理期」—— 查错要的就是这一个二值');
ok(mgrFake.msg.includes(FAKE), '  └ 不存在那句带号，好核对是不是输错了');

console.log('\n【③ 界面上不举例子】');
const ctx  = await browser.newContext();
const page = await ctx.newPage();
await page.goto(BASE + '/', { waitUntil: 'networkidle' });
await page.fill('#login-name', 'cashier1');
await page.fill('#login-pin', 'admin123');
await page.click('#btn-login');
await page.waitForSelector('#view-main.active', { timeout: 8000 });
await page.click('#tab-invoice');
const hint = (await page.locator('[data-i18n-html="lookup.invoiceHint"]').textContent() || '').trim();
ok(hint.length > 0, `提示语还在：「${hint}」`);
ok(!/\d{5,}/.test(hint),
   '★★★ 提示里没有 5 位以上的数字 —— 举一个号当例子，等于把「长什么样、数到哪儿了」一起印在屏幕上');
ok(/Factura Simplificada/.test(hint),
   '★★ 但仍然指到小票上那一行 —— 收银员要的是「读哪一行」，不是一个号');
await ctx.close();

console.log('\n【JS 错误】');
const allErrs = [...clerkOld.errs, ...clerkFake.errs, ...mgrOld.errs, ...mgrFake.errs];
ok(allErrs.length === 0, allErrs.length ? '有 JS 报错：' + allErrs.join(' | ') : '无 JS 报错');

await browser.close();
console.log('\n──────────────────────────────────────────────────');
console.log(fail ? `\x1b[31m失败 ${fail}\x1b[0m / 共 ${pass + fail} 项` : `\x1b[32m全部通过\x1b[0m  ${pass} 项`);
process.exit(fail ? 1 : 0);
