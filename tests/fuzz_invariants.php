<?php
/**
 * 全系统不变量集 —— 供 tests/fuzz.php（随机操作序列）每一步调用。
 *
 * ── 为什么要有这个文件 ────────────────────────────────────
 *
 * 前几轮排查都是「先想到一个场景，再去测它」，天花板就是想象力。
 * 这里换一条路：让机器随机组合操作（记账/撤销/核销/回收/值比对/
 * 缩水/过期/手工录入/改门槛/换口径/发待发/作废），每一步之后把
 * 下面这十条【无论走哪条路都必须成立】的事实全查一遍。
 *
 * 它抓到过的：多人单在 POS 分阶段改小时，按比例分摊用了流水的
 * 【原始】金额做分子、【当前】已分配额做分母，两者在第一次缩水后
 * 就不一致了 —— 客1 被退成 -77.00、客2 在 €0 的单上还留着 15 分、
 * 订单已分配额 -62.00。三方全输，而任何一个「想出来的场景」都没覆盖到。
 *
 * ★ 这些断言【不复用被测代码的算法】。它们只描述事实之间的关系
 *   （账本合计 == 余额、退回去最多归零、已分配 ≤ 可分…），
 *   所以不会和被测代码一起错、正好抵消（docs/13 §3.6）。
 */
declare(strict_types=1);

function inv_all(\Vip\LocalDb $db, string $ST, int $thrVisits, int $thrAmountCents, string $mode, bool $stableRules = true): array
{
    $bad = [];
    $q = fn(string $sql, array $p=[]) => $db->all($sql, $p);

    // ① 会员三个余额 == 其全部流水合计（追加式账本的定义）
    foreach ($q("SELECT m.id, m.visit_count, m.points_balance, m.total_spent,
                        COALESCE(SUM(l.counted_visit),0) v, COALESCE(SUM(l.points),0) p,
                        COALESCE(SUM(l.amount),0) a
                   FROM member m LEFT JOIN point_ledger l
                     ON l.member_id=m.id AND l.store_code=m.store_code
                  WHERE m.store_code=? GROUP BY m.id,m.visit_count,m.points_balance,m.total_spent",[$ST]) as $r) {
        if ((int)$r['visit_count'] !== (int)$r['v'])
            $bad[] = "①计次不平: 会员{$r['id']} 表{$r['visit_count']} vs 流水{$r['v']}";
        if ((int)$r['points_balance'] !== (int)$r['p'])
            $bad[] = "①积分不平: 会员{$r['id']} 表{$r['points_balance']} vs 流水{$r['p']}";
        if (abs((float)$r['total_spent'] - (float)$r['a']) > 0.004)
            $bad[] = "①消费额不平: 会员{$r['id']} 表{$r['total_spent']} vs 流水{$r['a']}";
    }

    // ② 一顿饭退回去最多归零，不可能倒欠（按 会员×订单 的净额）
    foreach ($q("SELECT member_id,serial_id,SUM(counted_visit) v,SUM(amount) a,SUM(points) p
                   FROM point_ledger WHERE store_code=? AND serial_id IS NOT NULL
                  GROUP BY member_id,serial_id
                 HAVING SUM(counted_visit)<0 OR SUM(amount)<-0.004 OR SUM(points)<0",[$ST]) as $r)
        $bad[] = "②单笔净额为负: 会员{$r['member_id']} 单{$r['serial_id']} 次{$r['v']} 额{$r['a']} 分{$r['p']}";

    // ③ 会员余额不能为负（冲正不该把人扣穿）
    foreach ($q("SELECT id,visit_count,points_balance,total_spent FROM member
                  WHERE store_code=? AND (visit_count<0 OR points_balance<0 OR total_spent<-0.004)",[$ST]) as $r)
        $bad[] = "③会员余额为负: #{$r['id']} 次{$r['visit_count']} 分{$r['points_balance']} 额{$r['total_spent']}";

    // ④ 订单已分配额不能超过可积分总额
    foreach ($q("SELECT serial_id,total_amount,allocated_amount FROM pos_order
                  WHERE store_code=? AND allocated_amount>total_amount+0.004",[$ST]) as $r)
        $bad[] = "④超分: 单{$r['serial_id']} 已分{$r['allocated_amount']}>可分{$r['total_amount']}";

    // ⑤ 订单已分配额 == 该单全部流水金额合计
    foreach ($q("SELECT o.serial_id,o.allocated_amount,COALESCE(SUM(l.amount),0) s
                   FROM pos_order o LEFT JOIN point_ledger l
                     ON l.serial_id=o.serial_id AND l.store_code=o.store_code
                  WHERE o.store_code=? GROUP BY o.serial_id,o.allocated_amount
                 HAVING ABS(o.allocated_amount-COALESCE(SUM(l.amount),0))>0.004",[$ST]) as $r)
        $bad[] = "⑤订单已分配与流水不平: 单{$r['serial_id']} 订单{$r['allocated_amount']} vs 流水{$r['s']}";

    /**
     * ⑥ 客人手上的有效券不能超过当前进度应发数。
     *
     * ★ 只在【规则没变过】时才成立。系统有两条故意不遵守它的规则：
     *   · 门槛调高【不追回】已发的券（业主明确要求）
     *   · 换发券口径时不跨口径判（F3：progress_at_grant 不带单位，
     *     按金额发的券不该拿次数进度去判）
     *   所以改过门槛/口径之后，这一条会被合法地打破。
     */
    $thr = $mode==='amount' ? max(1,$thrAmountCents) : max(1,$thrVisits);
    if ($stableRules)
    foreach ($q("SELECT m.id, m.visit_count, m.total_spent,
                   (SELECT COUNT(*) FROM coupon c WHERE c.store_code=m.store_code AND c.member_id=m.id
                      AND c.status=1 AND c.source IN(1,2)) act
                   FROM member m WHERE m.store_code=?",[$ST]) as $r) {
        $prog = $mode==='amount' ? (int)round((float)$r['total_spent']*100) : (int)$r['visit_count'];
        $earn = intdiv(max(0,$prog), $thr);
        if ((int)$r['act'] > $earn)
            $bad[] = "⑥有效券超发: 会员{$r['id']} 有效{$r['act']}>应发{$earn}(进度{$prog}/门槛{$thr})";
    }

    // ⑥a 【任何时候都成立】：券绝不该发在「还没够到门槛」的进度上
    foreach ($q("SELECT id,progress_at_grant,threshold_used,source FROM coupon
                  WHERE store_code=? AND source IN(1,2) AND threshold_used IS NOT NULL
                    AND progress_at_grant < threshold_used",[$ST]) as $r)
        $bad[] = "⑥a券发早了: #{$r['id']} 发放时进度{$r['progress_at_grant']}<当时门槛{$r['threshold_used']}";

    // ⑦ rewards_issued 不能低于「客人手上+已用掉」的消费券（低了将来会重复发）
    //    也不能高于历史发出的消费券总数（高了客人少拿）
    foreach ($q("SELECT m.id,m.rewards_issued,
                   (SELECT COUNT(*) FROM coupon c WHERE c.store_code=m.store_code AND c.member_id=m.id
                      AND c.source IN(1,2) AND c.status IN(1,2)) held,
                   (SELECT COUNT(*) FROM coupon c WHERE c.store_code=m.store_code AND c.member_id=m.id
                      AND c.source IN(1,2)) total
                   FROM member m WHERE m.store_code=?",[$ST]) as $r) {
        if ((int)$r['rewards_issued'] < (int)$r['held'])
            $bad[] = "⑦issued偏低: 会员{$r['id']} issued{$r['rewards_issued']}<持有+已用{$r['held']}";
        if ((int)$r['rewards_issued'] > (int)$r['total'])
            $bad[] = "⑦issued偏高: 会员{$r['id']} issued{$r['rewards_issued']}>历史总数{$r['total']}";
    }

    // ⑧ 券的状态只能是 1/2/3/4；已核销的必须有核销时间
    foreach ($q("SELECT id,status,redeemed_at FROM coupon WHERE store_code=? AND
                 (status NOT IN(1,2,3,4) OR (status=2 AND redeemed_at IS NULL))",[$ST]) as $r)
        $bad[] = "⑧券状态异常: #{$r['id']} status={$r['status']} redeemed_at=".($r['redeemed_at']??'NULL');

    // ⑨ 一张券只能核销在一张单上，且不能被两个会员持有
    foreach ($q("SELECT redeemed_serial_id,COUNT(DISTINCT id) n FROM coupon
                  WHERE store_code=? AND status=2 AND redeemed_serial_id IS NOT NULL
                  GROUP BY redeemed_serial_id HAVING COUNT(DISTINCT id)>50",[$ST]) as $r)
        $bad[] = "⑨同一单核销了{$r['n']}张券: {$r['redeemed_serial_id']}";

    /**
     * ⑨a 【白送的正面判据】整单免费的饭，不该给任何人留下计次。
     *
     * ①–⑧ 查的都是【内部一致性】—— 账本自洽、余额对得上。
     * 但「白送」可以在完全自洽的情况下发生：一顿全免的饭照样给客人
     * 记了一次，账面处处正确，只是那一次不该有。计次直接换免费餐，
     * 多留一次就是往下一顿白送。
     *
     * ★ 判据是【净份数为 0】，不是「券数 ≥ 净份数」。
     *   portions_counted 是【净】份数（券抵掉的已经扣过了），
     *   拿它和券数比会误伤混合单：2 人单里 1 人用券之后
     *   净份数=1、券数=1，条件成立 —— 而另一位付了钱的客人
     *   那一次本来就该留着（docs/03 §5.5「静默少给」）。
     *   第一版就是这么写错的，被 W2 场景当场证伪。
     */
    foreach ($q("SELECT o.serial_id,
                   (SELECT COUNT(*) FROM coupon c
                     WHERE c.store_code=o.store_code AND c.redeemed_serial_id=o.serial_id
                       AND c.status=2) redeemed
                   FROM pos_order o
                  WHERE o.store_code=? AND o.portions_counted = 0",[$ST]) as $o) {
        if ((int)$o['redeemed'] <= 0) { continue; }   // 没核销过券的 0 份单不在此列
        foreach ($q("SELECT member_id, SUM(counted_visit) v FROM point_ledger
                      WHERE store_code=? AND serial_id=? GROUP BY member_id
                     HAVING SUM(counted_visit) > 0",[$ST,$o['serial_id']]) as $r)
            $bad[] = "⑨a白送: 单{$o['serial_id']} 整单被 {$o['redeemed']} 张券盖满(净份数0)，"
                   . "会员{$r['member_id']} 仍留着 {$r['v']} 次";
    }

    /**
     * ⑨b 【白送的完整版】按份计次时，一单的净计次总和不得超过净份数。
     *
     * ⑨a 只查「整单全免」这一个极端（净份数 = 0）。可白送是有中间态的：
     *   3 份的单记了 3 次，客人用掉 1 张券 → 净份数 2，
     *   而三次里只该留两次 —— 少退的那一次照样是往下一顿白送，
     *   ⑨a 却一声不响，因为净份数不是 0。
     *
     * by_portion 口径下「一份 = 一次」是定义本身，所以
     * SUM(净计次) ≤ 净份数 是这个口径下的硬约束，可以直接当判据。
     *
     * ★ 只在 by_portion 下成立。另外两种口径是「一个人一单最多 1 次」，
     *   与份数没有关系 —— 1 份的单两个人分着记，两次一份，
     *   在那两种口径下是【对的】，拿这条去查会误伤。
     */
    $vmode = (string)($q("SELECT config_value v FROM sys_config
                           WHERE store_code=? AND config_key='visit_count_mode'",[$ST])[0]['v'] ?? 'once_per_period');
    if ($vmode === 'by_portion') {
        foreach ($q("SELECT o.serial_id, o.portions_counted,
                       (SELECT COALESCE(SUM(l.counted_visit),0) FROM point_ledger l
                         WHERE l.store_code=o.store_code AND l.serial_id=o.serial_id) nv
                       FROM pos_order o WHERE o.store_code=?",[$ST]) as $o) {
            if ((int)$o['nv'] > (int)$o['portions_counted']) {
                $bad[] = "⑨b白送: 单{$o['serial_id']} 净份数{$o['portions_counted']}，"
                       . "却记着 {$o['nv']} 次（按份计次下一份就是一次）";
            }
        }
    }

    // ⑩ 被标记已冲正的流水必须指向一条冲正流水
    foreach ($q("SELECT id,status,reversed_by_id FROM point_ledger
                  WHERE store_code=? AND status=2 AND reversed_by_id IS NULL",[$ST]) as $r)
        $bad[] = "⑩流水标了已冲正却没有冲正记录: #{$r['id']}";

    return $bad;
}
