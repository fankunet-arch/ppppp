<?php
declare(strict_types=1);

namespace Vip\Service;

use Vip\LocalDb;
use Vip\MealRules;
use Vip\Money;
use Vip\PointsEngine as PE;
use Vip\PosSource;
use Vip\PosUnavailable;
use Vip\Repo\AlertRepo;
use Vip\Repo\AuditRepo;
use Vip\Repo\ConfigRepo;
use Vip\Repo\LedgerRepo;
use Vip\Repo\MemberRepo;
use Vip\Repo\OrderRepo;

/**
 * 值比对冲正 —— 防刷分的正确做法。
 *
 * ★ 绝对不要按 return_time 增量扫描来触发扣分。
 *   实测三条证据（docs/01 §3.4）：
 *     1. return_amount 全表 88,616 行恒为 0，依赖它永不触发；
 *     2. 明细样本的退菜行 return_time 100% 早于 order_end_time；
 *     3. 触发器「明细被 UPDATE → head.status 置 1」在 927 天里从未触发
 *        （status 恒为 2），说明历史明细归档后从不被修改。
 *   结论：历史表中的 return_time 全是「结账前已发生」的退菜，其金额
 *   已经从 should_amount 中扣除。按 return_time 扫到就扣分，等于对早已
 *   扣过的退菜【再扣一次】。
 *
 * ★ 正确做法：把发分时的金额快照存本地，之后按 serial_id 回读主库当前
 *   金额做值比对。这不依赖任何数据库自增 ID，且覆盖面更广 —— 不仅覆盖
 *   退菜，也覆盖 POS 的「修改历史账单」功能（实测 edit_time 显示 2.9%
 *   的订单在结账后被修改过，其中 1,144 单晚于结账 30 分钟以上）。
 */
final class ReconcileService
{
    public function __construct(
        private LocalDb    $db,
        private PosSource  $pos,
        private ConfigRepo $cfg,
        private OrderRepo  $orders,
        private MemberRepo $members,
        private LedgerRepo $ledger,
        private AlertRepo  $alerts,
        private AuditRepo  $audit,
        private MealRules  $rules,
        /**
         * ★ 缩水/作废之后要把「已经不再算挣到」的券收回来。
         *   与 PointsService::reverseInTx 走同一个方法 ——
         *   两条路对同一件事必须给出同一个结果。
         */
        private RewardService $rewards,
    ) {
    }

    /**
     * 回读保护期内已发分订单的金额，不一致则冲正。
     *
     * 开销：主键单点查（idx_headcheck），比扫 138 万行的 idx_return_time
     * 轻得多。保护期 30 天 × 日均 95.6 单 ≈ 2,870 单，分 29 批约 1 分钟跑完。
     *
     * ★ 保护期内每张单会被反复比（verify_recheck_hours，默认 7 天一轮），
     *   不是一生只比一次 —— POS 的改单实测 2.9% 发生在结账之后，
     *   其中 1,144 单晚于结账 30 分钟以上，只比一次等于这条防线是空的
     *   （审计 F1，见 OrderRepo::pendingVerify）。
     *   稳态下每轮只是一次金额回读 + 整数比较，不读明细。
     */
    public function verifyAmounts(?callable $log = null): array
    {
        $log ??= static fn(string $m) => null;

        $protectDays = $this->cfg->int('verify_protect_days', 30);
        $batchSize   = min($this->cfg->int('sync_batch_size', 100), 100);
        $sleepMs     = $this->cfg->int('sync_batch_sleep_ms', 2000);
        $maxBatches  = $this->cfg->int('sync_max_batches', 200);
        // 同一张单多久复查一次。调小 → 抓改单更快、压主库更重；
        // 调到 0 会被 pendingVerify 夹到 1 小时（不允许每轮都全量重扫）
        $recheckHrs  = $this->cfg->int('verify_recheck_hours', 168);

        $checked = 0; $changed = 0; $batches = 0;

        while ($batches < $maxBatches) {
            $page = $this->orders->pendingVerify($protectDays, $batchSize, 0, $recheckHrs);
            if (!$page) {
                break;
            }
            $batches++;

            foreach ($page as $o) {
                try {
                    $r = $this->verifyOne($o);
                } catch (PosUnavailable $e) {
                    $log('POS 超时，值比对中止：' . $e->getMessage());
                    return ['ok' => false, 'reason' => 'pos_timeout',
                            'checked' => $checked, 'changed' => $changed];
                }
                $checked++;
                if ($r) {
                    $changed++;
                }
            }
            if ($sleepMs > 0) {
                usleep($sleepMs * 1000);
            }
        }

        return ['ok' => true, 'checked' => $checked, 'changed' => $changed, 'batches' => $batches];
    }

    /** @return bool 是否发生了冲正 */
    private function verifyOne(array $o): bool
    {
        $serial   = (string)$o['serial_id'];
        $headId   = (int)$o['order_head_id'];
        $checkIds = array_filter(array_map('intval', explode(',', (string)$o['check_ids'])));

        // 逐张 check 回读并汇总
        $nowOriginal = 0; $nowShould = 0; $nowActual = 0; $nowTax = 0; $found = false;
        foreach ($checkIds as $cid) {
            $r = $this->pos->reloadAmounts($headId, $cid);
            if ($r === null) {
                continue;
            }
            $found = true;
            $nowOriginal += Money::toCents((string)$r['original_amount']);
            $nowShould   += Money::toCents((string)$r['should_amount']);
            $nowActual   += Money::toCents((string)$r['actual_amount']);
            // ★ 税额也要回读。拿本地镜像里那个【旧】税额去算新总额是错的：
            //   金额都改了，税额不可能没改（实测 100.00/税 9.09 退成 75.00/税 6.82，
            //   用旧税算出来是 65.91，正确是 68.18 —— 反过来多退了 3 分）
            $nowTax      += Money::toCents((string)($r['tax_amount'] ?? '0'));
        }

        if (!$found) {
            // 订单在主库中消失了 —— 极罕见，需人工核实，不自动扣分
            $this->alerts->raiseOnce('order_vanished', 'order', $serial,
                sprintf('订单 %s 已发分，但主库中查不到了，需人工核实', $serial),
                ['severity' => 3]);
            $this->orders->markVerified($serial, 3);
            return false;
        }

        /**
         * ── 🔴 基准只认 verify_base_*，不认 should/actual ─────────
         *
         * should_amount / actual_amount 是【主库当前值的镜像】，
         * buildContext（每次 locate）与 storeOrder（每轮同步）都在刷它。
         * 拿它当基准就是拿新值跟新值比：永远相等，永远判「一致」——
         * 「值比对冲正」整条防线是空的（审计 F2，实测见 initVerifyBase）。
         *
         * verify_base_* 只有发分与冲正两条路会写，同步与 locate 碰不到。
         * 本次迁移之前的老数据为 NULL，回落到镜像值（＝修之前的行为），
         * 由下面那道不变量兜底。
         */
        $oldShould = $o['verify_base_should'] !== null
            ? Money::toCents((string)$o['verify_base_should'])
            : Money::toCents((string)$o['should_amount']);
        $oldActual = $o['verify_base_actual'] !== null
            ? Money::toCents((string)$o['verify_base_actual'])
            : Money::toCents((string)$o['actual_amount']);

        /**
         * ── 独立于任何基准的一道网 ──────────────────────────
         *
         * 「已分配额不能超过这一单可能值的钱」是不需要参照物的事实。
         * 而重算出来的可积分总额只会【小于等于】LEAST(应收, 收款)
         * ——排除项、免税折算都只往下减，不会往上加。
         *
         * 所以只要 allocated > LEAST(nowShould, nowActual)，
         * 这一单就一定超分了，不管基准是什么、有没有被谁刷过。
         *
         * 这道网存在的意义是：基准那条线【已经错过一次了】。
         * 再给它配一个不依赖它的判据，下次基准若又被谁写坏，
         * 钱还能追回来（docs/13 §3.1）。
         */
        $allocated = Money::toCents((string)$o['allocated_amount']);
        $ceiling   = min($nowShould, $nowActual);
        $overAlloc = $allocated > $ceiling;

        if ($nowShould === $oldShould && $nowActual === $oldActual && !$overAlloc) {
            // 基准没变过就顺手补一份（老数据首次比对时定格），下一轮才有得比
            if ($o['verify_base_at'] === null) {
                $this->orders->resyncVerifyBase($serial, $nowShould, $nowActual);
            }
            $this->orders->markVerified($serial, 1);
            return false;
        }
        if ($overAlloc && $nowShould === $oldShould && $nowActual === $oldActual) {
            // 金额没变却超分了 —— 基准或镜像被写坏过，记一条，照样往下冲正
            $this->alerts->raiseOnce('over_allocated', 'order', $serial,
                sprintf('订单 %s 金额未变，但已分配 € %s 超过可积分上限 € %s，按当前金额冲正',
                    $serial, Money::toStr($allocated), Money::toStr($ceiling)),
                ['severity' => 2]);
        }

        /**
         * 金额变了 —— 重算可积分总额。此时才读明细（罕见路径，开销可接受）。
         *
         * ── 🔴 必须和记账路径用【同一套】算法 ─────────────
         *
         * 原来这里少传了两个东西，与 PointsService::buildContext() 分叉：
         *
         * ① redeemPatterns 没传 → 退回硬编码的 ['TARJETA 10+1','10+1']。
         *    而 PointsEngine::REDEEM_PATTERNS 的注释承诺「名称会变……
         *    改后台 sys_config 即可，无需改代码」——
         *    店家真改了 POS 里的名称：Pad 认得出，夜间校准认不出。
         *
         * ② taxCents 没传 → 恒按含税算。points_include_tax = 0 时
         *    落库的 total_amount 是【不含税】的，这里重算出来是【含税】的，
         *    两数天然不等且 newTotal 恒 ≥ oldTotal → 每一单都走
         *    「金额变大，不自动补分」分支，推一条 amount_changed 告警。
         *    本该自动冲正的单被挂成人工待办，同时污染告警队列。
         *
         * 两项当前恰好都没发作（含税开关是 1、核销名称等于硬编码默认值），
         * 但那是配置碰巧对上了，不是代码对。
         */
        $detail   = $this->pos->fetchDetailForChecks($headId, $checkIds);
        $analysis = PE::analyzeDetail($detail, $this->rules,
            PE::redeemPatternsFrom($this->cfg->get('redeem_line_patterns', '')));
        /**
         * ★ 用【回读到的】税额，不是本地镜像里那一份。
         *
         *   这里连着栽过两次：
         *   ① 一开始压根没传 taxCents —— points_include_tax=0 时
         *      newTotal 恒 ≥ oldTotal，每张改过金额的单都挂成人工待办。
         *   ② 补上之后读的是 $o['tax_amount']，而 pendingVerify() 的 SELECT
         *      根本没取那一列 —— PHP 静默求值成 null → 0，修了等于没修，
         *      冲正比应退的少一个税额（实测多留 6 分）。
         *   ③ 把列补进 SELECT 之后，读到的是【下单时】的旧税额 ——
         *      金额都改了税额不可能没改，于是又反过来多退了 3 分。
         *
         *   三次都是同一个毛病：算钱时拿了一个「看起来对」的近似值。
         *   现在只认 reloadAmounts() 当下回读的那一份。
         */
        $taxCents = $this->cfg->get('points_include_tax', '1') === '1' ? 0 : $nowTax;
        $newTotal = PE::pointsBaseCents($nowShould, $nowActual, $nowOriginal,
                                        $analysis['excluded_cents'], $taxCents);
        // ★ 镜像里的可积分总额。它同样是 buildContext 每次 locate 都会重写的列，
        //   所以【只配当告警文案】，不配当判据（下面那段说明）。
        $oldTotal = Money::toCents((string)$o['total_amount']);

        /**
         * ── 🔴 判「要不要退」只看不变量：已分配 > 新总额 ────────────
         *
         * 原来的判据是 `newTotal >= oldTotal`（金额变大就不补分）。
         * 而 oldTotal 取自 total_amount —— 这是 F2 的另一半：
         * 那一列和 should/actual 一样由 buildContext 每次 locate 重写。
         *
         * 实测：71.70 的单发满分 → POS 改成 0.00 → 收银员再 locate 一次
         *      （给同桌第二位客人查单，每天都在发生）→ 镜像 total 被刷成 0.00
         *      → newTotal(0) >= oldTotal(0) 成立 → 走进「金额变大，不补分」
         *      → 挂成人工待办、一分钱没退，
         *        而 allocated 71.70 就挂在一张 0 元的单上。
         *
         * 「已分配额不能超过这一单的可积分总额」是不需要参照物的事实，
         * 拿它当判据，谁刷过镜像都不影响结论。
         */
        if ($allocated > $newTotal) {
            $this->applyShrink($serial, $oldTotal, $newTotal, $nowShould, $nowActual);
            return true;
        }

        if ($newTotal > $oldTotal) {
            // 金额变大且没超分：不自动补分，避免被利用；记告警等人工判断
            $this->alerts->raiseOnce('amount_changed', 'order', $serial,
                sprintf('订单 %s 金额由 € %s 变为 € %s（变大），不自动补分，请人工复核',
                    $serial, Money::toStr($oldTotal), Money::toStr($newTotal)),
                ['severity' => 2, 'detail' => ['old' => Money::toStr($oldTotal), 'new' => Money::toStr($newTotal)]]);
            // 变大不冲正，但基准要跟上 —— 否则下一轮复查还会再读一遍明细，
            // 每晚给 POS 主机做一次无用功，并把同一条告警重推一遍。
            $this->orders->resyncVerifyBase($serial, $nowShould, $nowActual);
            $this->orders->markVerified($serial, 3);
            return true;
        }

        /**
         * 金额变小但没超分（多人单里只记了一部分）—— 没有钱要退，
         * 但镜像的可积分总额必须跟上：否则同桌下一位来记账时，
         * 「还剩多少可分」还是按旧的大数算，等于把已经不存在的钱又分出去。
         */
        $this->orders->setTotalAmount($serial, $newTotal);
        $this->orders->resyncVerifyBase($serial, $nowShould, $nowActual);
        $this->orders->markVerified($serial, 1);
        $this->alerts->raiseOnce('amount_changed', 'order', $serial,
            sprintf('订单 %s 金额由 € %s 变为 € %s，已分配 € %s 未超出新总额，无需冲正',
                $serial, Money::toStr($oldTotal), Money::toStr($newTotal), Money::toStr($allocated)),
            ['severity' => 1]);
        return true;
    }

    /**
     * 金额变小 → 冲正。
     *
     * 两个容易算错的地方：
     *
     * ① 冲正的基数是【已分配额超出新总额的部分】，不是缩水额本身。
     *    订单可能只被部分分配（AA 时只有部分客人有卡）。
     *    例：总额 100 只记了 50，缩水到 60 —— 新总额 60 仍 ≥ 已分配 50，
     *    一分都不该退。若按缩水额 40 去退就退多了。
     *
     * ② 按比例分摊会有舍入残差。三条等额流水各退 1/3 时
     *    scale() 三次的和可能比应退多 1 分。最后一条用「应退总额 − 已退」
     *    兜底，保证分毫不差。
     *
     * 冲正积分向上取整（对商家有利）。若会员积分不足，允许负余额并标记，
     * 不阻断、也不静默丢弃，下次消费优先抵扣。docs/03 §6.4
     */
    private function applyShrink(string $serial, int $oldTotal, int $newTotal,
                                int $nowShould, int $nowActual): void
    {
        $this->db->transaction(function () use ($serial, $oldTotal, $newTotal,
                                                $nowShould, $nowActual): void {
            $order = $this->orders->lockBySerial($serial);
            if ($order === null) {
                // ★ 一定要留下 markVerified —— verifyAmounts() 的 while 循环
                //   不翻页，靠 last_verified_at 推进。这里静默 return 会死循环。
                $this->orders->markVerified($serial, 3);
                return;
            }
            /**
             * ★ 冲正完成后基准要跟着挪到【刚回读到的值】。
             *   不挪的话下一轮复查还会看到「基准 ≠ 当前」，
             *   于是每一晚都去读一遍明细重算 —— 结果永远是
             *   excess ≤ 0（已经退过了），白白压 POS 主机。
             *   放在事务里、且不管走哪个分支都要做，所以搁在最前面。
             */
            $this->orders->resyncVerifyBase($serial, $nowShould, $nowActual);
            $allocated = Money::toCents((string)$order['allocated_amount']);

            // ① 只退「已分配额超出新总额」的部分
            $excess = $allocated - $newTotal;
            if ($excess <= 0) {
                $this->orders->setTotalAmount($serial, $newTotal);
                $this->orders->markVerified($serial, 1);
                $this->alerts->raiseOnce('amount_changed', 'order', $serial,
                    sprintf('订单 %s 金额由 € %s 变为 € %s，但已分配 € %s 未超出新总额，无需冲正',
                        $serial, Money::toStr($oldTotal), Money::toStr($newTotal), Money::toStr($allocated)),
                    ['severity' => 1]);
                return;
            }

            $earns = [];
            foreach ($this->ledger->activeBySerial($serial) as $e) {
                if ((int)$e['entry_type'] === LedgerRepo::T_EARN && Money::toCents((string)$e['amount']) > 0) {
                    $earns[] = $e;
                }
            }
            if (!$earns) {
                $this->orders->markVerified($serial, 3);
                return;
            }

            $n = count($earns);
            $acc = 0;
            $totalBack = 0;

            foreach ($earns as $i => $e) {
                $amt = Money::toCents((string)$e['amount']);
                // ② 最后一条吃掉舍入残差，保证 SUM(退款) 恰好等于 excess
                $backAmt = ($i === $n - 1)
                    ? $excess - $acc
                    : Money::scale($excess, $amt, max($allocated, 1));
                $backAmt = max(0, min($backAmt, $amt));   // 不能退超过该条本身
                $acc += $backAmt;
                if ($backAmt === 0) {
                    continue;
                }

                $pts = (int)$e['points'];
                /**
                 * ★ 退多少分，看积分口径。
                 *
                 *   by_amount：分是从金额来的，按退掉的金额比例退，向上取整（对商家有利）。
                 *     ★ 整数取整除，不走浮点：`ceil($pts * ($backAmt / $amt))` 里
                 *       那个除法一旦落在整数上方一点点（如 5 → 5.000000000000001），
                 *       ceil 就多扣一分，而这个方向是【对客人不利】的。
                 *       与 PointsEngine::fixedFloor 同一类问题，同一种解法。
                 *
                 *   by_visit ：分是从【来过一次】来的，跟金额没有比例关系。
                 *     照搬比例公式的话，`ceil(1 × 任意正比例)` 恒等于 1 ——
                 *     哪怕只退了 5 分钱，那一次的分也整个没了，而客人确实来过。
                 *     所以只在【整条被退干净】时才收回；部分缩水不动分。
                 */
                $backPts = $this->cfg->get('points_mode', 'by_amount') === 'by_visit'
                    ? ($backAmt >= $amt ? $pts : 0)
                    : intdiv($pts * $backAmt + $amt - 1, max($amt, 1));   // 向上取整

                /**
                 * ★ 计次：只在【整单归零】时收回，部分缩水一律不动。
                 *
                 *   部分缩水不动是对的 —— 退掉一杯酒不改变「吃了几份套餐」。
                 *
                 *   但整单归零不一样：那顿饭在 POS 上已经不存在了，
                 *   而计次直接换免费餐（docs/03 §5）。原来这里恒为 0，于是
                 *   一张归零的单，分退了、【十送一那个格子还留着】——
                 *   免费餐才是真正花钱的东西，等于漏在外面。
                 *   手工撤销 /points/reverse 一直是分和次都退干净的，
                 *   两条路对同一件事给出不同结果，本身也说不通。
                 *
                 *   只认「整单归零」这一个信号：某一位的份额恰好被退光
                 *   （多人单里退掉一人的量）不算 —— 那顿饭还是发生了，
                 *   他也确实在场，判不出该不该收回，就不收。
                 */
                $backVisits = $newTotal === 0 ? (int)$e['counted_visit'] : 0;

                $this->members->lockById((int)$e['member_id']);
                $refundId = $this->ledger->insert([
                    'member_id'     => (int)$e['member_id'],
                    'serial_id'     => $serial,
                    'entry_type'    => LedgerRepo::T_REFUND,
                    'amount_cents'  => -$backAmt,
                    'points'        => -$backPts,
                    'counted_visit' => -$backVisits,
                    'reverses_id'   => (int)$e['id'],
                    'reason'        => sprintf('值比对发现订单金额由 %s 变为 %s',
                        Money::toStr($oldTotal), Money::toStr($newTotal)),
                ]);
                $this->members->applyDelta((int)$e['member_id'], -$backPts, -$backVisits, -$backAmt);
                $totalBack += $backAmt;

                /**
                 * ★ 整单归零时，原始流水要标记成【已冲正】，不能留在有效流水里。
                 *
                 *   否则会漏一个洞：countedThisSitting() 判「这张卡本餐期记过没有」
                 *   走的是 earnedInRange()，那个查询只看 entry_type=消费 且 status=有效 ——
                 *   作废单的原始流水还挂在那里，就一直占着「已经记过了」这个位置。
                 *
                 *   于是收银员打错单、作废、同一餐期重打一张的时候：
                 *     作废把那一次退了 → 重打的这一单又因为「本餐期已记过」不计次
                 *     → 客人真吃了一顿，最后一次都没有。
                 *
                 *   标记成已冲正之后这一条就退出风控视野，重打的那一单正常计次。
                 *   ★ 只在整单归零时做。部分缩水的原始流水必须留着 ——
                 *     那顿饭确实发生了，它得继续占着这一餐期的位置。
                 */
                if ($newTotal === 0) {
                    $this->ledger->markReversed((int)$e['id'], $refundId);
                }
            }

            /**
             * ── 🔴 券也要跟着退 —— 这条路原来完全没做 ──────────
             *
             * 撤销记账（reverseInTx）会收回「已经不再算挣到」的券，
             * 但【值比对】这条路原来只退计次/积分/消费额，一个字没碰券。
             * 于是同一件事在两条路上结果不同：
             *
             *   收银员记错卡 → 经理手工撤销 → 券收回        ✓
             *   POS 侧整单作废 → 夜间值比对退回 → 【券还在，且能核销】 🔴
             *
             * 实测两种口径都漏：
             *   按次数：记满 3 次发券 → 第 3 单作废 → 计次退回 2，券状态照旧可用
             *   按金额：消费 191.20 发券 → 退到 95.60（门槛 100）→ 该发 0 张、
             *           已发 1 张，券还在客人手上
             *
             * 后者更隐蔽：不需要整单归零，任何一次让进度跌破门槛的缩水都会漏。
             * 而 POS 侧改单/作废是店里每天都在发生的事。
             *
             * ★ 放在 applyAllocation 之前、applyDelta 之后：收几张是按
             *   【退完之后的进度】重算的。
             * ★ 在同一笔事务里：分开就又回到「进度退了、券没退」。
             */
            foreach (array_unique(array_map(
                        static fn(array $e): int => (int)$e['member_id'], $earns)) as $mid) {
                $claw = $this->rewards->clawBackOverIssued(
                    (int)$mid, ['id' => null, 'name' => '值比对'],
                    sprintf('值比对：订单 %s 金额由 %s 变为 %s',
                        $serial, Money::toStr($oldTotal), Money::toStr($newTotal)));
                if (($claw['unrecoverable'] ?? 0) > 0) {
                    /**
                     * 已经吃掉的券收不回来。那顿饭是拿一笔【后来被 POS 退掉的】
                     * 消费换的 —— 店里实打实亏了一顿，必须有人知道。
                     */
                    $this->alerts->raiseOnce(
                        // ★ 去重键要带上订单号：只按会员去重的话，同一位客人
                        //   身上第二次白送的那顿饭会被当成重复告警吞掉（F13）
                        'reward_on_shrunk_order', 'member', $mid . '@' . $serial,
                        sprintf('订单 %s 金额缩水后，由它带出的 %d 张免费餐券【已经被核销】，收不回来了。'
                              . '请人工核对这位客人的奖励进度', $serial, (int)$claw['unrecoverable']),
                        ['severity' => 2, 'detail' => ['serial_id' => $serial,
                                                       'voided' => $claw['codes'] ?? []]]);
                }
            }

            $this->orders->applyAllocation($serial, -$totalBack, 0);
            $this->orders->setTotalAmount($serial, $newTotal);
            $this->orders->markVerified($serial, 2);

            $this->audit->log('point_reverse', [
                'target_type' => 'order', 'target_id' => $serial,
                'detail' => ['auto' => true, 'kind' => 'amount_shrink',
                             'old' => Money::toStr($oldTotal), 'new' => Money::toStr($newTotal),
                             'reversed' => Money::toStr($totalBack)],
            ]);

            $this->alerts->raise('amount_changed',
                sprintf('订单 %s 金额由 € %s 缩至 € %s，已自动冲正 € %s',
                    $serial, Money::toStr($oldTotal), Money::toStr($newTotal), Money::toStr($totalBack)),
                ['severity' => 2, 'ref_type' => 'order', 'ref_id' => $serial]);
        });
    }
}
