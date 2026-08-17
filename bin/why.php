<?php
declare(strict_types=1);

/**
 * ════════════════════════════════════════════════════════════════
 * 「为什么 Pad 上找不到这张单」——现场诊断
 *
 * 用法：
 *   php bin/why.php 30           查桌号 30
 *   php bin/why.php 30 240       把回溯范围放宽到 240 分钟再看
 *
 * Pad 的定位条件有四条，任何一条不满足就查不到，而界面上一律显示
 * 「未找到」，看不出是被哪一条挡的。本脚本逐条检验并直说结论：
 *
 *   ① 单必须已经【结账】—— 未结账的活单在 order_head，不在 history_order_head
 *   ② order_end_time 必须落在时间窗内（order_lookup_window_min，默认 30 分钟）
 *   ③ eat_type 必须是 0（堂食）—— 外带/自取按设计就不该在 Pad 上发分
 *   ④ table_name 必须完全相等 —— 大小写、空格、前缀都算数
 *
 * ★ 全程只读，只发 SELECT，且都带 LIMIT。
 * ════════════════════════════════════════════════════════════════
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$table = $argv[1] ?? '';
if ($table === '') {
    exit("用法：php bin/why.php <桌号> [回溯分钟数]\n例：php bin/why.php 30\n");
}
$look = isset($argv[2]) && ctype_digit($argv[2]) ? (int)$argv[2] : 0;

$config = require __DIR__ . '/../app/bootstrap.php';

use Vip\App;

$app = new App($config);
$db  = $app->posDb();
// 时间窗取自后台配置；本地库连不上时回落到默认值，不让诊断本身挂掉
try {
    $win = $look > 0 ? $look : $app->cfg()->int('order_lookup_window_min', 30);
} catch (Throwable $e) {
    $win = $look > 0 ? $look : 30;
    echo "\033[33m（读不到后台配置，时间窗按默认 30 分钟算：{$e->getMessage()}）\033[0m\n";
}

function h(string $t): void { echo "\n\033[1m{$t}\033[0m\n"; }
function ok_(string $m): void { echo "  \033[32m✓\033[0m {$m}\n"; }
function no_(string $m): void { echo "  \033[31m✗\033[0m {$m}\n"; }
function tip(string $m): void { echo "    \033[33m→ {$m}\033[0m\n"; }

try {
    // ── 时钟 ─────────────────────────────────────────────
    h('时钟');
    $t = $db->select('SELECT NOW() AS n, @@global.time_zone AS gz, @@session.time_zone AS sz LIMIT 1')[0];
    printf("  POS 服务器时间 %s（时区 %s / 会话 %s）\n", $t['n'], $t['gz'], $t['sz']);
    printf("  本机 PHP 时间   %s（%s）\n", date('Y-m-d H:i:s'), date_default_timezone_get());
    $skew = abs(strtotime($t['n']) - time());
    if ($skew > 120) {
        no_(sprintf('两边相差 %d 分钟', (int)round($skew / 60)));
        tip('定位比的是 POS 服务器自己的 NOW() 与它自己存的 order_end_time，本机时间不参与，');
        tip('所以这个差本身不一定致命 —— 但它说明有一台机器时间没校准，值得顺手修。');
    } else {
        ok_('两边时间一致（相差 ' . $skew . ' 秒内）');
    }

    /**
     * ★ 真正会让「最近的单查不到」的，是 POS 库内部的时间不自洽：
     *   NOW() 比 POS 应用写进 order_end_time 的时间【跑得快】。
     *   那样刚结的账看起来就像很久以前，直接落到时间窗外面。
     *   （反过来 NOW() 慢则只是窗口变宽，会多查出来，不会少。）
     *   拿库里最新一单和 NOW() 比一下就知道。
     */
    $newest = $db->select(
        'SELECT MAX(order_end_time) AS t FROM history_order_head
          WHERE order_end_time >= NOW() - INTERVAL 1440 MINUTE LIMIT 1')[0]['t'] ?? null;
    if ($newest !== null) {
        $lag = (int)round((strtotime($t['n']) - strtotime((string)$newest)) / 60);
        printf("  库里最新一单 %s（%d 分钟前）\n", $newest, $lag);
        if ($lag < 0) {
            no_('最新订单的时间比 NOW() 还晚 —— POS 库内部时间不自洽');
            tip('这种情况下时间窗会算错，最近的单可能整批查不到');
        }
    } else {
        no_('近 24 小时库里一张单都没有 —— 要么今天没营业，要么连错了库');
    }

    // ── ① 活单还是历史单 ──────────────────────────────────
    h("① 这张单结账了吗（桌号 {$table}）");
    // order_head 是活单表，只存未结账的单，行数极少，全扫无妨
    $live = $db->select(
        'SELECT order_head_id, check_id, table_name, order_start_time
           FROM order_head WHERE table_name = ? LIMIT 20', [$table], 's');
    if ($live) {
        no_(sprintf('桌 %s 有 %d 张【未结账】的活单', $table, count($live)));
        foreach ($live as $l) {
            printf("      单号 %s · check %s · %s 开台\n",
                $l['order_head_id'], $l['check_id'], $l['order_start_time'] ?? '-');
        }
        tip('未结账的单不会出现在 Pad 上 —— 发分要按最终金额，必须等客人结完账');
    } else {
        ok_("桌 {$table} 没有未结账的活单");
    }

    // ── ②③④ 历史单逐条过筛 ───────────────────────────────
    h("② 最近的历史单（不加任何过滤，先看有没有）");
    $rows = $db->select(
        'SELECT order_head_id, check_id, table_name, eat_type, order_end_time, actual_amount
           FROM history_order_head
          WHERE table_name = ? AND order_end_time >= NOW() - INTERVAL ? MINUTE
          ORDER BY order_end_time DESC LIMIT 20',
        [$table, max($win, 1440)], 'si');

    if (!$rows) {
        no_(sprintf('近 %d 分钟内，桌 %s 一张历史单都没有', max($win, 1440), $table));
        tip('桌号必须完全相等。下面列出近期真实出现过的桌号，对照看是不是写法不一样');
        $names = $db->select(
            'SELECT table_name, COUNT(*) n, MAX(order_end_time) t
               FROM history_order_head
              WHERE order_end_time >= NOW() - INTERVAL 1440 MINUTE
              GROUP BY table_name ORDER BY t DESC LIMIT 20');
        foreach ($names as $n) {
            printf("      「%s」 %s 单，最近 %s\n", $n['table_name'], $n['n'], $n['t']);
        }
    } else {
        ok_(sprintf('找到 %d 张历史单，逐张看为什么没进 Pad：', count($rows)));
        $nowRow = $db->select('SELECT NOW() AS n LIMIT 1')[0]['n'];
        foreach ($rows as $r) {
            $age  = (int)round((strtotime($nowRow) - strtotime((string)$r['order_end_time'])) / 60);
            $bad  = [];
            if ($age > $win)               { $bad[] = "超出时间窗（{$age} 分钟前 > {$win} 分钟）"; }
            if ((int)$r['eat_type'] !== 0) { $bad[] = '不是堂食（eat_type=' . $r['eat_type'] . '）'; }
            printf("      单号 %-8s %s  € %-8s %s\n",
                $r['order_head_id'], $r['order_end_time'], $r['actual_amount'],
                $bad ? "\033[31m✗ " . implode('；', $bad) . "\033[0m" : "\033[32m✓ 应该能查到\033[0m");
        }
        $tooOld = array_filter($rows, static fn($r) =>
            (strtotime($nowRow) - strtotime((string)$r['order_end_time'])) / 60 > $win);
        if (count($tooOld) === count($rows)) {
            tip("全部超出时间窗。要么现在就发分，要么到后台把「订单查找 → 回溯时间窗」从 {$win} 分钟调大");
            tip('也可以让收银员改用小票上的 Factura Simplificada 号查单 —— 那条路不受时间窗限制');
        }
    }

    // ── ③ 真跑一遍 Pad 的调用链 ────────────────────────────
    h('③ 按 Pad 的真实路径查一次（把被「系统内部错误」盖住的异常挖出来）');
    echo "  注意：这一步和 Pad 上点查询做的事完全一样，会把订单写进本地镜像。\n";
    try {
        $r = $app->points()->locate($table, $win);
        if (($r['ok'] ?? false) === false) {
            no_('查询失败：' . ($r['reason'] ?? '未知'));
        } elseif (!$r['candidates']) {
            no_('查询成功但没有候选单（reason=' . ($r['reason'] ?? '-') . '）');
        } else {
            ok_(sprintf('查到 %d 张，Pad 上应该正常显示', count($r['candidates'])));
            foreach ($r['candidates'] as $c) {
                printf("      流水号 %s · %s · € %s · %s 份 · %s\n",
                    $c['serial_id'], $c['order_end_time'], $c['total'],
                    $c['portions_counted'], $c['eligible'] ? '可发分' : ('不可发分：' . $c['ineligible_reason']));
            }
        }
    } catch (Throwable $e) {
        no_('★ 抛异常了 —— 这就是 Pad 上「系统内部错误」的真实原因：');
        echo "\n      \033[31m" . get_class($e) . ': ' . $e->getMessage() . "\033[0m\n";
        echo "      位置 " . $e->getFile() . ':' . $e->getLine() . "\n\n";
        foreach (array_slice(explode("\n", $e->getTraceAsString()), 0, 6) as $l) {
            echo "      {$l}\n";
        }
        tip('把上面这段发出来即可定位；同样的内容也在 PHP 错误日志里，前缀 [api]');
    }

    /**
     * ── ③bis 明细到底在哪张表 ─────────────────────────────
     *
     * 份数是数明细行数出来的，明细没有 → 份数必然是 0。
     * 而这家 POS 有两套表：order_detail 是活单明细，
     * history_order_detail 是归档后的。如果归档有延迟，
     * 刚结账的单在历史表里就是「有头无明细」——
     * 表现正是「订单查得到、套餐 0 份」。
     */
    h('③bis 这几张单的明细在哪张表');
    $ids = [];
    foreach ($rows ?? [] as $r) { $ids[(int)$r['order_head_id']] = true; }
    $ids = array_slice(array_keys($ids), 0, 5);
    if (!$ids) {
        echo "  （上面没查到单，跳过）\n";
    } else {
        printf("  %-10s %-22s %s\n", '订单号', 'history_order_detail', 'order_detail（活单）');
        foreach ($ids as $id) {
            $h1 = $db->select('SELECT COUNT(*) AS c FROM history_order_detail WHERE order_head_id = ? LIMIT 1',
                [$id], 'i')[0]['c'] ?? 0;
            $l1 = $db->select('SELECT COUNT(*) AS c FROM order_detail WHERE order_head_id = ? LIMIT 1',
                [$id], 'i')[0]['c'] ?? 0;
            printf("  %-10s %-22s %s%s\n", $id, $h1, $l1,
                (int)$h1 === 0 ? "   \033[31m← 历史表无明细，份数必然为 0\033[0m" : '');
        }
        $none = array_filter($ids, static fn($id) =>
            (int)($db->select('SELECT COUNT(*) AS c FROM history_order_detail WHERE order_head_id = ? LIMIT 1',
                [$id], 'i')[0]['c'] ?? 0) === 0);
        if ($none) {
            no_('有订单在 history_order_detail 里没有任何明细行');
            tip('若明细还在 order_detail（活单表），说明归档有延迟 —— 那就不是配置问题，');
            tip('而是要等归档，或者改成也读活单明细。把这张表的数字发出来即可判断。');
        }
    }

    // ── ④ 套餐规则是否对得上门店码 ────────────────────────
    h('④ 套餐规则表 —— 「套餐 0 份」几乎都是这里出的问题');
    try {
        $ldb   = $app->localDb();
        $store = $app->storeCode();
        $mine  = (int)$ldb->value('SELECT COUNT(*) FROM meal_item_rule WHERE store_code = ?', [$store]);
        $all   = (int)$ldb->value('SELECT COUNT(*) FROM meal_item_rule');
        printf("  当前门店码 %s：%d 条规则（全表共 %d 条）\n", $store, $mine, $all);

        if ($mine === 0 && $all > 0) {
            no_('规则表里有数据，但【没有一条属于当前门店码】');
            $others = $ldb->all('SELECT store_code, COUNT(*) n FROM meal_item_rule GROUP BY store_code');
            foreach ($others as $o) {
                printf("      门店码「%s」下有 %d 条\n", $o['store_code'], $o['n']);
            }
            /**
             * 这是最容易踩的部署坑：db/seeds/*.sql 里门店码写死成 S001，
             * 用 phpMyAdmin 手工导入就会原样落成 S001；而应用读的是
             * config.php 的 store_code。两者不一致 → 一条规则都读不到
             * → countsVisit() 全部回落 false → 所有订单都显示「套餐 0 份」。
             * php bin/init.php seed 会按当前门店码替换后再灌，是幂等的。
             */
            tip('种子文件里门店码写死为 S001，手工导入 SQL 就会落成 S001。');
            tip('改用 php bin/init.php seed 重灌（幂等，会自动替换成当前门店码）');
        } elseif ($mine === 0) {
            no_('规则表是空的 —— 所有菜品都会按安全默认「不计次」处理，份数恒为 0');
            tip('跑 php bin/init.php seed');
        } else {
            ok_("规则表已覆盖当前门店（{$mine} 条）");
            $cv = (int)$ldb->value(
                'SELECT COUNT(*) FROM meal_item_rule WHERE store_code = ? AND counts_visit = 1', [$store]);
            if ($cv === 0) {
                no_('但没有任何一条 counts_visit = 1 —— 份数照样恒为 0');
                tip('到后台「套餐规则」页把套餐项的「计次」打开');
            } else {
                ok_("其中 {$cv} 条参与计次");
            }
        }
    } catch (Throwable $e) {
        no_('读不到本地库的规则表：' . $e->getMessage());
    }

    h('结论');
    echo "  Pad 只显示【已结账 + 堂食 + 在时间窗内 + 桌号完全相等】的单。\n";
    echo "  上面哪一条打了 ✗，就是它；③ 若抛异常，异常本身就是答案。\n";

} catch (Throwable $e) {
    fwrite(STDERR, "诊断失败：" . $e->getMessage() . "\n");
    fwrite(STDERR, "若是连不上 POS，先跑 php bin/diag.php\n");
    exit(1);
}
