<?php
declare(strict_types=1);

namespace Vip;

/**
 * 餐期归属：一个结账时刻落在哪个餐期里。
 *
 * ★ meal_period 表从 001_init 就建好了、种子也灌了，但在这之前
 *   【没有任何代码读它】—— 营业日一直是按固定的 02:00 切点算的。
 *   这个类是第一个用它的地方。
 *
 * 为什么现在需要：风控要按「同一餐期」限次（docs/03 §12）。
 * 一天两个餐期，中午来一次晚上来一次是完全正常的，
 * 按天限次会误伤这种客人；按餐期限才对得上真实的用餐行为。
 *
 * ── 归属规则 ──────────────────────────────────────────
 *
 * 餐期用「当地时钟时间」定义（11:00–18:00、19:30–02:00），
 * 判定只看结账时刻的时分，不看日期 —— 跨零点的那个餐期除外。
 *
 * cross_midnight = 1 表示 end_time 属于次日。晚市 19:30–02:00：
 *   · 23:00 结账 → 在区间内（>= 19:30）
 *   · 00:30 结账 → 也在区间内（< 02:00，属前一天的晚市）
 * 这与 BusinessDay 的 02:00 切点是一致的，两者必须一致，
 * 否则会出现「营业日算成昨天、餐期算成今天」的错位。
 *
 * ── 落在餐期之外怎么办 ──────────────────────────────
 *
 * 返回 null，调用方按【不限次】处理。
 *
 * 这是有意的：餐期是店家自己配的，配漏了一段时间（比如中午
 * 18:00–19:30 那个空档真有客人结账）不该导致这些单记不了账。
 * 风控宁可漏判，也不能把正常生意挡在门外 —— 挡住的代价是
 * 柜台当面回绝客人，而漏判的代价只是少拦一次，还有告警兜着。
 */
final class MealPeriod
{
    /** @var array<int,array{id:int,name:string,start:string,end:string,cross:bool}>|null */
    private ?array $cache = null;

    public function __construct(private LocalDb $db, private string $storeCode)
    {
    }

    /** @return array<int,array{id:int,name:string,start:string,end:string,cross:bool}> */
    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }
        $rows = $this->db->all(
            'SELECT id, period_name, start_time, end_time, cross_midnight
               FROM meal_period WHERE store_code = ? ORDER BY sort_order, id',
            [$this->storeCode]
        );
        return $this->cache = array_map(static fn(array $r): array => [
            'id'    => (int)$r['id'],
            'name'  => (string)$r['period_name'],
            'start' => substr((string)$r['start_time'], 0, 5),
            'end'   => substr((string)$r['end_time'], 0, 5),
            'cross' => (int)$r['cross_midnight'] === 1,
        ], $rows);
    }

    /**
     * 这个结账时刻属于哪个餐期。
     *
     * @param string $endTime 'Y-m-d H:i:s'
     * @return array{id:int,name:string,start:string,end:string,cross:bool}|null
     *         null = 没有配餐期，或这个时刻落在所有餐期之外
     */
    public function of(string $endTime): ?array
    {
        $hm = self::minutes(substr($endTime, 11, 5));
        if ($hm === null) {
            return null;
        }
        foreach ($this->all() as $p) {
            if (self::covers($p, $hm)) {
                return $p;
            }
        }
        return null;
    }

    /**
     * 两个结账时刻是不是同一个餐期的同一天。
     *
     * ★ 光比餐期 id 不够。周一中午和周二中午都是「白天」，
     *   但显然不是同一顿饭 —— 必须连营业日一起比。
     */
    public function sameSitting(string $endA, string $endB, BusinessDay $bd): bool
    {
        if ($bd->of($endA) !== $bd->of($endB)) {
            return false;   // 不同营业日一定不是同一顿，配没配餐期都成立
        }
        if ($this->all() === []) {
            // 压根没配餐期：只剩营业日这一个口径
            return true;
        }
        return $this->bucketOf($endA, $bd) === $this->bucketOf($endB, $bd);
    }

    /**
     * 合并口径 —— 比风控口径宽：只有【明确分属两个不同餐期】才算不同顿。
     *
     * ── 🔴 为什么不能和风控用同一个判据 ────────────────────
     *
     * 两者方向相反：
     *   风控：判成同一顿 = 【不给】计次 → 拿不准时要放宽（判不同顿）
     *   合并：判成同一顿 = 【允许】合并 → 拿不准时也要放宽（判同一顿）
     *
     * 同行分桌的两桌，一桌 19:29 结账（落在 18:00–19:30 空档）、
     * 一桌 19:31 结账（晚市）—— 按风控那个严格口径是两格，合并会被拒，
     * 而客人们正站在柜台前等着把三桌的分记到一张卡上。
     *
     * 挡「攒一把小票一起来兑」的承重墙是 merge_span_minutes（出厂 60 分钟），
     * 不是这一条：真正的午晚两餐至少隔着 90 分钟的空档，跨度那一关就过不去。
     */
    public function couldBeSameSitting(string $endA, string $endB, BusinessDay $bd): bool
    {
        if ($bd->of($endA) !== $bd->of($endB)) {
            return false;
        }
        $pa = $this->of($endA);
        $pb = $this->of($endB);
        // 有一方落在空档里 → 判不出，交给跨度那一关
        return $pa === null || $pb === null || $pa['id'] === $pb['id'];
    }

    /**
     * 这个时刻落在「哪一格」里 —— 餐期是格子，餐期之间的空档也是格子。
     *
     * ── 🔴 为什么空档也要有自己的格子 ──────────────────────
     *
     * 出厂餐期 11:00–18:00 与 19:30–02:00，中间 18:00–19:30 是空档。
     * 原来「落在空档」和「落在另一个餐期」被当成同一件事处理（退回
     * 按营业日比），于是中午 13:00 那顿和傍晚 19:00 那顿被判成同一顿，
     * 客人第二次白来。
     *
     * 但反过来一律判「不是同一顿」也不行：18:10 和 19:20 都在同一个空档里，
     * 那显然是同一顿饭的两张单，放行等于开了个重复计次的口子。
     * 店家只配一个餐期时这个口子还会变得很大。
     *
     * 所以给空档也编上格子：同一个空档里的两笔算同一顿，
     * 跨格子的（餐期↔空档、空档↔另一个空档）算不同顿。
     *
     * ── 怎么摊平跨零点 ──────────────────────────────────
     *
     * 以【营业日切点】为原点把一天拉成一条 0–1440 的直线：
     * 切点 02:00 时，晚市 19:30–02:00 变成 [1050, 1440)，
     * 午市 11:00–18:00 变成 [540, 960)，两个空档是 [0,540) 与 [960,1050)。
     * 这条轴上没有跨零点，也就没有「00:30 算哪天」的歧义 ——
     * 那件事已经由外层的营业日比较管掉了。
     *
     * @return string 稳定的格子标识（'p:<餐期id>' 或 'gap:<起>-<止>'）
     */
    private function bucketOf(string $endTime, BusinessDay $bd): string
    {
        $hm = self::minutes(substr($endTime, 11, 5));
        if ($hm === null) {
            return 'bad';
        }
        foreach ($this->all() as $p) {
            if (self::covers($p, $hm)) {
                return 'p:' . $p['id'];
            }
        }

        // 落在空档里 —— 找出它两侧的边界，同一个空档的两笔会得到同一对边界
        $cut  = self::minutes(substr($bd->cutoff() . ':00', 0, 5)) ?? 120;
        $axis = static fn(int $m): int => ($m - $cut + 1440) % 1440;

        $lo = 0; $hi = 1440;                       // 空档在轴上的 [起, 止)
        $x  = $axis($hm);
        foreach ($this->all() as $p) {
            $s = self::minutes($p['start']);
            $e = self::minutes($p['end']);
            if ($s === null || $e === null) {
                continue;
            }
            $ps = $axis($s);
            $pe = $axis($e);
            if ($pe <= $ps) {
                $pe += 1440;                       // 餐期在轴上恰好收尾于终点
            }
            if ($pe <= $x && $pe > $lo) { $lo = $pe; }   // 最近的左边界
            if ($ps >  $x && $ps < $hi) { $hi = $ps; }   // 最近的右边界
        }
        return 'gap:' . $lo . '-' . $hi;
    }

    /** 'HH:MM' → 当天第几分钟；格式不对返回 null */
    private static function minutes(string $hhmm): ?int
    {
        if (!preg_match('/^(\d{1,2}):(\d{2})$/', $hhmm, $m)) {
            return null;
        }
        $h = (int)$m[1];
        $i = (int)$m[2];
        if ($h > 23 || $i > 59) {
            return null;
        }
        return $h * 60 + $i;
    }

    /**
     * @param array{start:string,end:string,cross:bool} $p
     */
    private static function covers(array $p, int $hm): bool
    {
        $s = self::minutes($p['start']);
        $e = self::minutes($p['end']);
        if ($s === null || $e === null) {
            return false;
        }
        // 跨零点：19:30–02:00 拆成 [19:30, 24:00) ∪ [00:00, 02:00)
        if ($p['cross'] || $e <= $s) {
            return $hm >= $s || $hm < $e;
        }
        return $hm >= $s && $hm < $e;
    }
}
