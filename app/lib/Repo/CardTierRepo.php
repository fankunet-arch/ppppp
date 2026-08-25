<?php
declare(strict_types=1);

namespace Vip\Repo;

use Vip\Lang;
use Vip\LocalDb;

/**
 * 卡片等级（普卡 / 银卡 / 金卡 …）。
 *
 * ★ 等级属于【卡】，不属于会员 —— 它印在卡面上，客人手里那张卡长什么样，
 *   他就是什么级别。想升级就是发一张新卡给他。
 *
 * ★ 整套是【可选】的：店家不定义等级，界面上就完全不出现这件事。
 *
 * 本类只管「有哪些等级、叫什么」。等级带来什么差别（积分倍率、
 * 兑换门槛）目前【没有做】—— 那会动到积分引擎，而且卡面上得写清楚，
 * 属于另一个决定。
 */
final class CardTierRepo
{
    public function __construct(
        private LocalDb $db,
        private string $storeCode,
    ) {
    }

    /**
     * 全部等级，按 sort_order 排。
     *
     * @param bool $onlyEnabled true 时只给启用的 —— 发卡下拉框用这个；
     *                          显示老卡的等级时要用 false，
     *                          否则停用之后已经发出去的卡会显示不出等级名
     */
    public function all(bool $onlyEnabled = false): array
    {
        $sql = 'SELECT code, name, name_es, points_multiplier, sort_order, enabled
                  FROM card_tier WHERE store_code = ?';
        if ($onlyEnabled) { $sql .= ' AND enabled = 1'; }
        $sql .= ' ORDER BY sort_order ASC, code ASC';
        return $this->db->all($sql, [$this->storeCode]);
    }

    public function find(?string $code): ?array
    {
        if ($code === null || trim($code) === '') { return null; }
        return $this->db->one(
            'SELECT code, name, name_es, points_multiplier, sort_order, enabled FROM card_tier
              WHERE store_code = ? AND code = ?',
            [$this->storeCode, trim($code)]
        );
    }

    /** 这个等级码存在且启用 —— 发卡前校验 */
    public function isUsable(?string $code): bool
    {
        $t = $this->find($code);
        return $t !== null && (int)$t['enabled'] === 1;
    }

    /**
     * 新增或改一个等级。
     *
     * code 是机器标识，定了就别改 —— 已经发出去的卡是靠它认等级的。
     * 改名改的是 name / name_es，不影响任何已发的卡。
     */
    public function save(
        string $code,
        string $name,
        ?string $nameEs,
        float $multiplier,
        int $sort,
        bool $enabled,
    ): bool {
        $code = strtolower(trim($code));
        $name = trim($name);
        if ($code === '' || $name === '' || !preg_match('/^[a-z0-9_]{1,20}$/', $code)) {
            return false;
        }
        // 倍率兜个底：0 或负数意味着「消费了反而不给分」，那不是等级，是 bug；
        // 上限 10 倍纯粹是防手滑多打一个零
        if ($multiplier <= 0 || $multiplier > 10) {
            return false;
        }
        $es = trim((string)$nameEs);
        $this->db->exec(
            'INSERT INTO card_tier
               (store_code, code, name, name_es, points_multiplier, sort_order, enabled, created_at, updated_at)
             VALUES (?,?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE
               name = VALUES(name), name_es = VALUES(name_es),
               points_multiplier = VALUES(points_multiplier),
               sort_order = VALUES(sort_order), enabled = VALUES(enabled),
               updated_at = VALUES(updated_at)',
            [$this->storeCode, $code, $name, $es !== '' ? $es : null,
             number_format($multiplier, 2, '.', ''),
             $sort, $enabled ? 1 : 0, $this->db->now(), $this->db->now()]
        );
        return true;
    }

    /**
     * 某个会员当前该套用的等级与倍率 —— 看他手里那张卡。
     *
     * 等级属于卡：换卡时等级跟着新卡走，所以这里查的是 card 而不是 member。
     * 没卡、不分级、等级被删掉，一律回落成 1.00（照常积分，只是没有加成）。
     *
     * @return array{code:?string, multiplier:float}
     */
    public function forMember(int $memberId): array
    {
        $row = $this->db->one(
            'SELECT t.code, t.points_multiplier
               FROM card c
               JOIN card_tier t ON t.store_code = c.store_code AND t.code = c.tier_code
              WHERE c.store_code = ? AND c.member_id = ?',
            [$this->storeCode, $memberId]
        );
        if ($row === null) {
            return ['code' => null, 'multiplier' => 1.0];
        }
        return ['code' => (string)$row['code'], 'multiplier' => (float)$row['points_multiplier']];
    }

    /**
     * 删一个等级。
     *
     * ★ 已经有卡在用的等级【不给删】—— 删了那些卡就成了指向不存在等级的孤儿，
     *   界面上显示不出等级名，而卡面上明明印着。要停用请用 enabled=0：
     *   停用只是不再出现在发卡下拉框里，老卡照常显示。
     *
     * @return array{ok:bool, error?:string, in_use?:int}
     */
    public function delete(string $code): array
    {
        $n = (int)$this->db->value(
            'SELECT COUNT(*) FROM card WHERE store_code = ? AND tier_code = ?',
            [$this->storeCode, $code]
        );
        if ($n > 0) {
            return ['ok' => false, 'error' => 'tier_in_use', 'in_use' => $n];
        }
        $this->db->exec('DELETE FROM card_tier WHERE store_code = ? AND code = ?',
            [$this->storeCode, $code]);
        return ['ok' => true];
    }

    /**
     * 一张卡的等级，已经按语言取好名字，可直接显示。
     * 不分级或等级已被删掉时返回 null —— 调用方据此不显示等级这一栏。
     *
     * @return array{code:string,name:string,names:array{zh:string,es:string}}|null
     */
    public function describe(?string $code): ?array
    {
        $t = $this->find($code);
        if ($t === null) { return null; }
        $names = self::names($t);
        return [
            'code'       => (string)$t['code'],
            'name'       => $names[\Vip\Http\Api::lang()] ?? $names[Lang::ZH],
            'names'      => $names,
            'multiplier' => (float)($t['points_multiplier'] ?? 1.0),
        ];
    }

    /** 两种语言的等级名；西语为空回落中文（同操作员显示名的处理） */
    public static function names(array $tier): array
    {
        $zh = (string)($tier['name'] ?? '');
        $es = trim((string)($tier['name_es'] ?? ''));
        return [Lang::ZH => $zh, Lang::ES => $es !== '' ? $es : $zh];
    }
}
