/**
 * 统一的浏览器启动入口。
 *
 * 两个坑都在这里处理掉：
 *
 * 1. ESM 的 import 按【脚本所在目录】解析依赖，而 playwright 是装在仓库外的
 *    （部署件保持零 node 依赖），所以要按 cwd 解析。
 * 2. npm 装的 playwright 版本与机器上已有的 chromium 版本常常对不上
 *    （它会去找自己那一版的构建号，找不到就让你 npx playwright install）。
 *    这里自动去常见位置找一个能用的，实在找不到再交给 playwright 自己。
 */
import { createRequire } from 'node:module';
import { existsSync, readdirSync } from 'node:fs';

const require = createRequire(process.cwd() + '/');
const { chromium } = require('playwright');

function findChrome() {
  if (process.env.CHROME_PATH) return process.env.CHROME_PATH;

  const roots = [process.env.PLAYWRIGHT_BROWSERS_PATH, '/opt/pw-browsers'].filter(Boolean);
  for (const root of roots) {
    if (!existsSync(root)) continue;
    // 优先完整版 chromium，其次 headless shell
    const dirs = readdirSync(root).filter(d => d.startsWith('chromium'));
    dirs.sort().reverse();
    for (const d of dirs) {
      for (const rel of ['chrome-linux/chrome', 'chrome-linux/headless_shell']) {
        const p = `${root}/${d}/${rel}`;
        if (existsSync(p)) return p;
      }
    }
  }
  return undefined;      // 交给 playwright 用它自带的
}

export const BASE = process.env.BASE_URL || 'http://127.0.0.1:8910';

export function launch() {
  return chromium.launch({ executablePath: findChrome() });
}

/** 极简断言，风格与 tests/run.php 保持一致 */
export function makeT() {
  const st = { pass: 0, fail: 0 };
  const ok = (c, m) => {
    if (c) { st.pass++; console.log('  \x1b[32m✓\x1b[0m ' + m); }
    else   { st.fail++; console.log('  \x1b[31m✗\x1b[0m ' + m); }
  };
  const summary = () => {
    const t = st.pass + st.fail;
    console.log('\n' + '─'.repeat(50));
    console.log(st.fail === 0 ? `\x1b[32m全部通过\x1b[0m  ${t} 项`
                              : `\x1b[31m失败 ${st.fail}\x1b[0m / 共 ${t} 项`);
    return st.fail ? 1 : 0;
  };
  return { ok, summary };
}
