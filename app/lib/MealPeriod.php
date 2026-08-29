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
        $pa = $this->of($endA);
        $pb = $this->of($endB);
        if ($pa === null || $pb === null) {
            // 没配餐期时退回「同一营业日」这个更粗的口径，而不是一律判否 ——
            // 判否会让合并功能在没配餐期的店里完全不能用
            return $bd->of($endA) === $bd->of($endB);
        }
        return $pa['id'] === $pb['id'] && $bd->of($endA) === $bd->of($endB);
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
