# 浏览器测试 —— 只有真浏览器能测的那部分

`tests/run.php` 是纯逻辑，`smoke` / `e2e` 是服务端链路。
这里跑的是它们都够不到的东西：**页面在真实浏览器里的行为**。

有三件事必须用真浏览器才能验，读代码判断不了：

1. **原生容器桥接** —— `window.AppBridge` 在不在、设备 ID 取的是
   `native` 还是浏览器兜底
2. **页内弹层** —— 真的弹出来了吗、点确定/取消回什么、Promise 会不会悬挂
3. **物理返回键** —— 历史哨兵有没有正确放置与回收

> ⚠️ 这套依赖 node + Playwright，**门店服务器上不需要、也跑不了**。
> 部署环境保持零依赖，这里只在开发机上跑。

## 跑法

```bash
# 1. 起本地服务（另开一个终端）
php -S 127.0.0.1:8910 -t wwwroot

# 2. 装 Playwright（一次就够，装在仓库外，不污染部署件）
mkdir -p /tmp/pw && cd /tmp/pw && npm init -y && npm i playwright

# 3. 跑
cd /tmp/pw
node /path/to/repo/tests/browser/container.mjs
node /path/to/repo/tests/browser/flow.mjs
node /path/to/repo/tests/browser/back.mjs
```

环境变量：

| 变量 | 默认 | 说明 |
|---|---|---|
| `BASE_URL` | `http://127.0.0.1:8910` | 被测站点 |
| `CHROME_PATH` | 自动查找 | 指定 Chromium 可执行文件 |

> npm 装的 playwright 版本常常和机器上已有的 chromium 构建号对不上
> （它会去找自己那一版，找不到就让你 `npx playwright install`）。
> `_launch.mjs` 会先去 `PLAYWRIGHT_BROWSERS_PATH` / `/opt/pw-browsers`
> 里找一个能用的，都找不到才交回给 playwright。手动指定用 `CHROME_PATH`。

## 三个脚本各测什么

### `container.mjs` —— 容器契约（23 项）

不打开容器也能测：脚本会**注入一个假的 `window.AppBridge`** 来模拟容器。

- 桥接与 `ui.js` 已加载、加载顺序正确
- 无容器时设备 ID 回落到 `PAD-` 前缀，`source` 为 `browser`
- 注入 `AppBridge` 后重载，`source` 变成 `native` 且取到 ANDROID_ID
- 页内弹层：确定/取消/空值校验/密码型/自定义按钮文案
- 后台 viewport 含 `viewport-fit=cover`
- 两个页面零 JS 报错

### `flow.mjs` —— 后台真实链路（11 项）

需要本地库里有 `admin` 账号且 PIN 为 `admin123`（`php bin/init.php seed`）。

登录 → 操作员 → 重置 PIN → 页内输入框 → 短 PIN 被拦 → 二次确认 → 取消。
**验的是真实调用现场串起来对不对**，不是工具函数本身。

### `piiswitch.mjs` —— 实名开关的提醒（11 项）

会临时改后台配置 `member_collect_pii`，跑完还原为关闭。

验的是「不会被忘掉」这件事：开启前弹窗告知短信未接入、取消则复选框复原、
开启后顶栏出现常驻红条、**刷新页面后红条仍在**（会话恢复走的是另一条路，
只在登录处理里渲染会漏）、关掉开关后红条消失。

### `padcard.mjs` —— Pad 扫卡建会员（28 项）

自己造两张库存卡再跑，跑完清理干净，不留残留。

另外守着两条**查询之间不能串味**的规则：先查一张有效卡再查无效卡时，
上一张的提示必须清掉；扫了卡之后改用手机号查找时，待绑定的卡号必须清空 ——
后者不清会真的记错账（点「启用」把上一张卡绑给这个人）。

四种卡状态各验一遍：伪造卡被拒、扫错二维码提示「卡号不完整」、
库存卡自动展开建卡表单并带出卡号、已激活的卡直接认出会员。
另外验**扫码不支持时的降级** —— 无头 Chromium 没有 `BarcodeDetector`，
正好用来确认这种情况下引导手工输入而不是弹一个空取景框卡住。

### `cards.mjs` —— 后台发卡（19 项）

需要 `admin` / `admin123` 能登录后台。

登录 → 生成批次 → 校验印刷清单 → 查卡 → 作废 → 再查确认。
重点验的是**印刷清单的格式**（制表符三列，能直接粘进 Excel）、
**明文 PIN 只显示这一次**的警告确实出现，以及手输把 `0` 打成 `O`
仍能查到同一张卡。

> ⚠️ 这个脚本会在库里留下一个 `PWxxxx` 批次的 5 张卡（其中 1 张已作废）。
> 跑在开发库上无妨，别对着生产库跑。

### `back.mjs` —— 物理返回键（16 项）

容器的返回键走 `WebView.canGoBack()`。Pad 是单页状态机，不写历史的话
`canGoBack()` 恒为 false —— 收银员在记账任何一步按返回，弹的都是
「确认退出应用」，而不是退回上一步。**这个问题只在容器里出现，
在浏览器上完全看不出来**，所以必须专门测。

- 前进三步后连按返回，逐级退回起点
- 回到起点后哨兵被回收（否则会白按一下返回）
- 弹层优先于步骤：开着弹层时按返回只关弹层，不动步骤
- 返回键关掉 UI 弹层时，其 Promise 正常 resolve 成「取消」，不悬挂
