<?php
declare(strict_types=1);

namespace Vip\Repo;

use Vip\LocalDb;
use Vip\Money;

/**
 * pos_order —— POS 已结账订单在本地的镜像，是积分分配的容器。
 *
 * ★ 幂等主键 (store_code, serial_id)。
 *   serial_id 是 POS 生成的业务流水号（YYMMDD+4 位），非自增代理键，
 *   数据库迁移/重建不受影响。order_head_id 保留但不参与任何唯一约束。
 *
 * ★ 主库只读 → 无法在 POS 上打「已积分」标记 → 本地库是幂等的唯一来源。
 *   丢失即导致历史订单重复发分，备份要求见 docs/04 §9。
 */
final class OrderRepo
{
    public function __construct(private LocalDb $db, private string $storeCode)
    {
    }

    /**
     * 落库或更新订单镜像。
     *
     * ── 🔴 两条调用路径，权限【不一样】 ──────────────────
     *
     * | 调用方 | $computed | 能写什么 |
     * |---|---|---|
     * | `PointsService::buildContext()`（读了明细，算得出真值） | `true` | 全部列 |
     * | `SyncService::storeOrder()`（补抓，没读明细） | `false` | 只写订单头与原始金额 |
     *
     * ── 为什么必须分开 ──────────────────────────────────
     *
     * 补抓阶段不读明细（读了请求数翻倍），所以它对
     * total_amount / excluded / portions / is_redeemed 这些
     * 【要靠明细才算得出来的列】只有占位值：total 按「无排除项」估，
     * 其余一律 0。
     *
     * 这些占位值放进【新行】是对的（后台报表与完整性监控要有个数）；
     * 但原来那份 ON DUPLICATE KEY UPDATE 把它们一并写进了【已存在的行】,
     * 于是每 20 分钟一轮的 Cron 会把 Pad 刚算好的真值冲掉：
     *
     *     locate 之后   total=71.70  excl=18.30  份数=3   is_redeemed=1
     *     cron 之后     total=90.00  excl=0.00   份数=0   is_redeemed=0
     *
     * 而 allocated_amount / allocated_portions 不在更新列表里、不会被冲，
     * 镜像因此进入「总额是毛额、已分配是净额、份数为 0」的自相矛盾状态。
     * 后果：报表金额永久失真；「这一单当初是不是用券免的」这条审计线索
     * 被抹掉；收银员在 locate 与 submit 之间撞上一轮 Cron 时，
     * 提交会被 exceeds_portions 拦下来（客人正站在柜台前）。
     *
     * ★ 加新列时想清楚它属于哪一类：POS 直接给的（should/actual/tax…）
     *   放进两边都写的那份；要算的（份数、排除项、核销…）只放 computed 那份。
     */
    public function upsert(array $o, bool $computed = true): int
    {
        $now = $this->db->now();
        // POS 直接给的列 —— 两条路径都拿得到真值，都可以写
        $setRaw = [
            'order_head_id', 'check_ids', 'table_name', 'eat_type', 'customer_num',
            'order_end_time', 'business_date',
            'original_amount', 'should_amount', 'actual_amount', 'tax_amount',
        ];
        // 要读明细才算得出来的列 —— 只有 buildContext 有权写
        $setComputed = [
            'total_amount', 'excluded_amount',
            'portions_counted', 'portions_gross', 'portions_uncounted',
            'is_redeemed', 'redeem_amount',
        ];
        $cols = $computed ? array_merge($setRaw, $setComputed) : $setRaw;
        $updates = implode(",\n               ",
            array_map(static fn(string $c): string => "{$c} = VALUES({$c})", $cols));

        $this->db->exec(
            'INSERT INTO pos_order
               (store_code, serial_id, order_head_id, check_ids, table_name, eat_type,
                customer_num, order_end_time, business_date,
                original_amount, should_amount, actual_amount, tax_amount, total_amount,
                excluded_amount, portions_counted, portions_gross, portions_uncounted,
                is_redeemed, redeem_amount,
                created_at, updated_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE
               ' . $updates . ',
               updated_at         = VALUES(updated_at)',
            [
                $this->storeCode,
                $o['serial_id'],
                $o['order_head_id'],
                implode(',', $o['check_ids'] ?? []),
                $o['table_name'] ?? null,
                $o['eat_type'] ?? 0,
                $o['customer_num'] ?? null,
                $o['order_end_time'],
                $o['business_date'],
                Money::toStr($o['original_cents'] ?? 0),
                Money::toStr($o['should_cents'] ?? 0),
                Money::toStr($o['actual_cents'] ?? 0),
                Money::toStr($o['tax_cents'] ?? 0),
                Money::toStr($o['total_cents'] ?? 0),
                Money::toStr($o['excluded_cents'] ?? 0),
                $o['portions_counted'] ?? 0,
                $o['portions_gross'] ?? ($o['portions_counted'] ?? 0),
                $o['portions_uncounted'] ?? 0,
                !empty($o['is_redeemed']) ? 1 : 0,
                Money::toStr($o['redeem_cents'] ?? 0),
                $now, $now,
            ]
        );
        return (int)$this->db->value(
            'SELECT id FROM pos_order WHERE store_code = ? AND serial_id = ?',
            [$this->storeCode, $o['serial_id']]
        );
    }

    public function findBySerial(string $serialId): ?array
    {
        return $this->db->one(
            'SELECT * FROM pos_order WHERE store_code = ? AND serial_id = ?',
            [$this->storeCode, $serialId]
        );
    }

    /**
     * 取行锁。
     * ★ 用普通 FOR UPDATE，不用 SKIP LOCKED / NOWAIT
     *   —— 后者需 MySQL 8.0+ / MariaDB 10.6+，不满足双兼容要求（db/README.md §2.1）。
     * 必须在事务内调用。
     */
    public function lockBySerial(string $serialId): ?array
    {
        return $this->db->one(
            'SELECT * FROM pos_order WHERE store_code = ? AND serial_id = ? FOR UPDATE',
            [$this->storeCode, $serialId]
        );
    }

    /**
     * 分配后回写已分配金额/份数与状态。
     *
     * ── 🔴 CASE 里【不能再写 + ?】 ───────────────────────
     *
     * MySQL / MariaDB 的 UPDATE 赋值是【从左到右求值的，后面的表达式
     * 看到的是前面已经更新过的值】（这一点与标准 SQL 不同）。
     * 所以走到 CASE 时，allocated_amount 已经是加过本次的新值了。
     *
     * 原来写成 `WHEN allocated_amount + ? >= total_amount`，
     * 等于判「旧值 + 2×本次 >= 总额」—— 实测 100.00 的单：
     *
     *     分 25 → 1     分到 50 → 1
     *     分到 75 → 2   ← 还剩 25.00 就被标成「已全额分配」
     *
     * 四人 AA 的第二个人一提交，整单就被标成记完了。
     *
     * 当前影响有限（全仓库只有 /report/daily 读这一列，且只判 > 0），
     * 但任何后续代码只要按 alloc_status = 2 判「这单记完了」就会立刻出错。
     */
    public function applyAllocation(string $serialId, int $deltaCents, int $deltaPortions): void
    {
        $this->db->exec(
            'UPDATE pos_order
                SET allocated_amount   = allocated_amount   + ?,
                    allocated_portions = allocated_portions + ?,
                    alloc_status = CASE
                        WHEN allocated_amount >= total_amount AND total_amount > 0 THEN 2
                        WHEN allocated_amount > 0 THEN 1
                        ELSE 0 END,
                    updated_at = ?
              WHERE store_code = ? AND serial_id = ?',
            [
                Money::toStr($deltaCents), $deltaPortions,
                $this->db->now(), $this->storeCode, $serialId,
            ]
        );
    }

    /**
     * 定格值比对的基准 —— 只在【第一次发分】时写一次。
     *
     * ── 🔴 为什么不能拿 should_amount / actual_amount 当基准 ──────
     *
     * 那两列是【主库当前值的镜像】，有两条写入路径抢着刷：
     *   buildContext()（收银员每次 locate）与 storeOrder()（每轮同步）。
     * 发分之后只要再 locate 一次，基准就被刷成 POS 的新值，
     * 值比对于是拿新值跟新值比 —— 永远相等，永远判「一致」。
     *
     * 实测：100.00 的单发 71.70 分 → POS 改成 0.00 → 再 locate 一次 →
     *       比对 changed=0，积分照旧 71，镜像 total=0.00 / allocated=71.70。
     *       「已分配额 > 可积分总额」当场破掉，而全流程零告警。
     *
     * ── 为什么是「只写一次」 ──────────────────────────────
     *
     * 基准的语义是「我们发这些分时，POS 说这一单值多少钱」。
     * AA 分几次记，第二个人提交时不能把基准挪到当下 ——
     * 那样第一个人那笔就失去了参照。缩水后自愈靠的是
     * applyShrink 的 `excess = allocated - newTotal`（与基准无关），
     * 基准只负责回答「要不要去读明细重算」这一个问题。
     *
     * 冲正完成后由 resyncVerifyBase() 重新定格 —— 那时点分与金额
     * 重新对上了，下一轮才能便宜地判「一致」。
     */
    public function initVerifyBase(string $serialId): void
    {
        $this->db->exec(
            'UPDATE pos_order
                SET verify_base_should = should_amount,
                    verify_base_actual = actual_amount,
                    verify_base_at     = ?
              WHERE store_code = ? AND serial_id = ? AND verify_base_at IS NULL',
            [$this->db->now(), $this->storeCode, $serialId]
        );
    }

    /**
     * 值比对重算之后把镜像的可积分总额改过来。
     *
     * ★ 不走 upsert()：那条路会连带重写 should/actual/份数等一整排列，
     *   而这里只知道「总额变成多少」这一件事。
     */
    public function setTotalAmount(string $serialId, int $cents): void
    {
        $this->db->exec(
            'UPDATE pos_order SET total_amount = ?, updated_at = ?
              WHERE store_code = ? AND serial_id = ?',
            [Money::toStr($cents), $this->db->now(), $this->storeCode, $serialId]
        );
    }

    /** 冲正之后重新定格基准（用刚从主库回读到的值，不是镜像里那份） */
    public function resyncVerifyBase(string $serialId, int $shouldCents, int $actualCents): void
    {
        $this->db->exec(
            'UPDATE pos_order
                SET verify_base_should = ?, verify_base_actual = ?, verify_base_at = ?
              WHERE store_code = ? AND serial_id = ?',
            [Money::toStr($shouldCents), Money::toStr($actualCents),
             $this->db->now(), $this->storeCode, $serialId]
        );
    }

    /**
     * App 自己核销了一张券在这一单上 —— 把这个【确知的事实】写回镜像。
     *
     * ── 🔴 为什么必须有这一步 ─────────────────────────────
     *
     * 「这一餐是不是用券吃的」原来【唯一】的判据是拿 redeem_line_patterns
     * 去匹配 POS 折扣行的名称。那是一份后台可改的自由文本，而名称是会变的
     * （实测 `Dto. -20%` 已经换成 `-15%`）。匹配串一旦对不上：
     *
     *   免费餐那一单 is_redeemed = 0 → 服务员照常记账 → 【又攒一次】
     *   于是免费餐自己在为下一顿免费餐攒进度：
     *   门槛 10 时变成「9 顿付费就送 1 顿」，发放量约多 11%，
     *   而且这不是一次性事故，是【每一位常客、每个兑换周期都在漏】。
     *   账面上完全看不出异常 —— 计次是真的、订单是真的、金额是真的。
     *
     * 而 App 自己一直知道答案：redeem() 里存了 redeemed_serial_id。
     * 只是从来没拿出来用过。这一步把它用上，
     * 让匹配串从「唯一判据」降级成「补充判据」
     * （客人先吃后核销、或核销时没填单号的场景仍然靠它）。
     *
     * ── 为什么份数只在 is_redeemed 原本为 0 时才扣 ────────
     *
     * 原本就是 1，说明匹配串已经认出来了、份数也已经扣过 —— 再扣就是扣两次。
     * ★ MySQL 的 UPDATE 赋值是【从左到右】求值的，后面的表达式看得到
     *   前面已改的值。所以 portions_counted 必须排在 is_redeemed 前面，
     *   它才读得到【旧的】is_redeemed。（与 applyAllocation 那个坑同源。）
     *
     * ⚠️ 一单上核销两张券、而匹配串两张都没认出、且中间没有重新 locate 时，
     *    这里只扣得掉一份。那时会少扣一份 —— 与修之前的行为一样，不是新增退化；
     *    重新 locate 时 buildContext 会按券的【张数】一次性对齐。
     */
    /**
     * 前台点「核销」时把这一单的净份数减下去。
     *
     * ── 🔴 is_redeemed 是布尔，不能拿来当计数 ────────────────
     *
     * 原来写的是
     *   portions_counted = GREATEST(0, portions_counted - IF(is_redeemed = 1, 0, 1))
     * ——【只有第一张券扣得动】。一桌两张券（家庭桌里两位都攒够了，
     * 这不是边角料）就少扣一份：
     *
     *   实测 4 份的桌、甲乙各一张券：
     *     先记账后核销 → 记进去 4 次，两次核销只退回 1 次，
     *                    实付 2 份却留着 3 次 —— 白送一顿饭的进度；
     *     先核销后记账 → 净份数停在 3，四人 AA 被 exceeds_portions
     *                    直接拒掉，收银员当场记不进去。
     *
     * 而 buildContext()（locate / 同步那条路）一直是对的：
     * 净 = 总份数 − max(匹配串反推的份数, App 核销张数)。
     * 同一个数两个人算，只有一个算对 —— docs/13 §3.1bis。
     *
     * ★ 为什么不能简单改成「每次都减 1」：
     *   POS 上已经有折扣行、匹配串认出来过一次时，buildContext 已经替
     *   那张券扣过了；再减一次就是把同一份免费餐扣两遍，
     *   而那个方向是【对客人不利】的。
     *
     * 所以按地面真值重算，并且【只减不加】：
     *   净 = LEAST(现在的净份数, 总份数 − App 已核销张数)
     *   取 LEAST 是为了不撤销「匹配串比 App 多认出来」的那部分扣减。
     *
     * ★ portions_gross = 0 是迁移 019 之前的老数据，那一档退回原来的
     *   「减 1」写法 —— 总份数不知道时，宁可少扣也不能把净份数清零。
     */
    public function markRedeemedByApp(string $serialId): void
    {
        $this->db->exec(
            'UPDATE pos_order
                SET portions_counted = IF(portions_gross > 0,
                        LEAST(portions_counted,
                              GREATEST(0, portions_gross - (
                                  SELECT COUNT(*) FROM coupon c
                                   WHERE c.store_code = pos_order.store_code
                                     AND c.redeemed_serial_id = pos_order.serial_id
                                     AND c.status = 2))),
                        GREATEST(0, portions_counted - IF(is_redeemed = 1, 0, 1))),
                    is_redeemed      = 1,
                    updated_at       = ?
              WHERE store_code = ? AND serial_id = ?',
            [$this->db->now(), $this->storeCode, $serialId]
        );
    }

    /** 这一单上，App 自己核销掉了几张券（地面真值，与 POS 折扣行名称无关） */
    public function appRedeemedCount(string $serialId): int
    {
        return (int)$this->db->value(
            'SELECT COUNT(*) FROM coupon
              WHERE store_code = ? AND redeemed_serial_id = ? AND status = 2',
            [$this->storeCode, $serialId]
        );
    }

    public function markFreeMeal(string $serialId, bool $isFree): void
    {
        $this->db->exec(
            'UPDATE pos_order SET is_free_meal = ?, updated_at = ? WHERE store_code = ? AND serial_id = ?',
            [$isFree ? 1 : 0, $this->db->now(), $this->storeCode, $serialId]
        );
    }

    /** 值比对：更新核对状态 */
    public function markVerified(string $serialId, int $status): void
    {
        /**
         * ★ status = 0 的语义是「从没比对过」，所以要把时间戳一并清空。
         *
         *   pendingVerify() 现在按 last_verified_at 排队（保护期内反复比，
         *   见那个方法的说明）。若这里给 status=0 也盖上「刚比过」的时间戳，
         *   「重置以便立刻再比一次」这个动作就变成了【把它推迟一整轮】——
         *   意思正好相反。
         */
        $this->db->exec(
            'UPDATE pos_order SET verify_status = ?, last_verified_at = ?, updated_at = ?
              WHERE store_code = ? AND serial_id = ?',
            [$status, $status === 0 ? null : $this->db->now(),
             $this->db->now(), $this->storeCode, $serialId]
        );
    }

    /**
     * 取保护期内待值比对的订单（已发过分的才需要比对）。
     *
     * ── 🔴 保护期内要【反复比】，不是一生比一次 ──────────────
     *
     * 原来的条件是 `verify_status = 0`，而全仓库没有任何一处把它改回 0。
     * 也就是说：每张单只在发分当晚比一次，判「一致」之后
     * 就永久退出视野了。而 POS 的改单实测【2.9% 发生在结账之后，
     * 其中 1,144 单晚于结账 30 分钟以上】（docs/01 §3.4）——
     * 绝大多数改单发生在那唯一一次比对【之后】。
     *
     * 实测：第 1 晚判一致 → POS 把 50.00 改成 10.00 → 第 2 晚 checked=0，
     *       一分钱没退。「值比对冲正」这条防线整条是空的。
     *
     * 现在：保护期内、离上次比对超过 $recheckHours 的都会再比一遍。
     *
     * ── 为什么四种状态都要复查 ────────────────────────────
     *
     *   0 从没比过           1 上次判一致       2 已冲正过     3 待人工
     *
     * 后三种都还会再变：改过一次的单会被再改一次；挂着人工待办的单
     * 也可能在人处理之前又缩水。而复查本身是【自愈】的
     * （applyShrink 按 `allocated - newTotal` 算，重跑不会重复扣），
     * 所以多比一次的代价只是一次主键单点查。
     *
     * ── 主库负载 ──────────────────────────────────────
     *
     * 冲正之后基准会重新定格（resyncVerifyBase），所以稳态下每张单
     * 每次复查都只是一次 reloadAmounts + 一次整数比较，不读明细。
     * 保护期 30 天 × 日均 95.6 单 ≈ 2,870 单，复查间隔 168 小时（7 天）
     * 时每晚约 410 单 + 当天新单 ≈ 500 单，比原来预算的
     * 「2,870 单 29 批约 1 分钟」还轻。
     *
     * ★ 调用方是 while 循环且【不翻页】—— 靠 markVerified() 更新
     *   last_verified_at 把已比过的挤出结果集来推进。所以 verifyOne()
     *   的每一条返回路径都必须落一次 markVerified，否则死循环。
     */
    public function pendingVerify(int $protectDays, int $limit, int $offset = 0,
                                  int $recheckHours = 168): array
    {
        $limit   = max(1, min($limit, 100));
        $since   = date('Y-m-d H:i:s', strtotime("-{$protectDays} days"));
        $reCut   = date('Y-m-d H:i:s', time() - max(1, $recheckHours) * 3600);
        return $this->db->all(
            // ★ 这里【故意不取 tax_amount】：值比对要的是「当下的」税额，
            //   由 reloadAmounts() 从主库回读（见 ReconcileService::verifyOne）。
            //   镜像里那一份是下单时的旧值，金额改过之后它一定是错的。
            'SELECT serial_id, order_head_id, check_ids, original_amount, should_amount,
                    actual_amount, total_amount, allocated_amount,
                    verify_base_should, verify_base_actual, verify_base_at
               FROM pos_order
              WHERE store_code = ?
                AND order_end_time >= ? AND allocated_amount > 0
                AND (last_verified_at IS NULL OR last_verified_at < ?)
              ORDER BY last_verified_at IS NULL DESC, order_end_time ASC
              LIMIT ' . $limit . ' OFFSET ' . max(0, $offset),
            [$this->storeCode, $since, $reCut]
        );
    }

    /** 数据完整性监控：本地某营业日的订单数 */
    public function countByBusinessDate(string $date): int
    {
        return (int)$this->db->value(
            'SELECT COUNT(*) FROM pos_order WHERE store_code = ? AND business_date = ?',
            [$this->storeCode, $date]
        );
    }

    /**
     * 近期【App 自己核销掉的券】里，有多少张所在的订单没被标成核销。
     *
     * ★ 守的是与 redeemShareSince 相反的方向。
     *   那一条防「匹配串太宽」（把普通折扣也算成核销 → 误伤客人）；
     *   这一条防「匹配串太窄或失配」（一张都认不出 → 免费餐又攒一次 → 餐厅赔钱）。
     *   同一份自由文本，两个方向的失效 —— 原来只堵了不花钱的那一边。
     *
     *   两份数据都在本地库里，不用打 POS。
     */
    public function redeemedButUnflagged(string $since): array
    {
        /**
         * ★ 用 INNER JOIN：订单【不在镜像里】不算漏。
         *   没有订单行就不可能有人在它上面记账，也就不会「又攒一次」。
         *   （核销时填了一个本地没有的单号，是另一回事，不该混进这条告警里
         *   —— 混进来只会让它天天叫，然后没人再看。）
         */
        $r = $this->db->one(
            'SELECT COUNT(*) AS total,
                    SUM(CASE WHEN o.is_redeemed = 1 THEN 0 ELSE 1 END) AS unflagged
               FROM coupon c
               JOIN pos_order o
                 ON o.store_code = c.store_code AND o.serial_id = c.redeemed_serial_id
              WHERE c.store_code = ? AND c.status = 2
                AND c.redeemed_serial_id IS NOT NULL
                AND c.redeemed_at >= ?',
            [$this->storeCode, $since]
        );
        return ['total' => (int)($r['total'] ?? 0), 'unflagged' => (int)($r['unflagged'] ?? 0)];
    }

    /**
     * 近期有多少单被判成「十送一核销」。
     *
     * 给 SyncService::checkIntegrity() 用：核销识别靠一份后台可改的
     * 自由文本（redeem_line_patterns）匹配 POS 折扣行名称，填错一次
     * 就会把所有用普通折扣的客人判成"在用券"，计次与积分一并没收。
     * 十送一是稀有事件，占比异常高就是配置出了问题。
     *
     * 一次查询两个数，避免扫两遍。只读本地镜像，不打 POS。
     *
     * @return array{total:int, redeemed:int}
     */
    public function redeemShareSince(string $businessDate): array
    {
        $r = $this->db->one(
            'SELECT COUNT(*) AS total, SUM(is_redeemed = 1) AS redeemed
               FROM pos_order WHERE store_code = ? AND business_date >= ?',
            [$this->storeCode, $businessDate]
        );
        return ['total' => (int)($r['total'] ?? 0), 'redeemed' => (int)($r['redeemed'] ?? 0)];
    }
}
