<?php
declare(strict_types=1);

namespace Vip\Repo;

use Vip\LocalDb;
use Vip\Money;

/**
 * member —— 会员。
 *
 * PII 数据最小化：仅手机号、邮箱、生日，无姓名（docs/05 §1）。
 * 删除 = 假名化，保留流水（会计与税务留存义务，docs/05 §4）。
 */
final class MemberRepo
{
    public const CONSENT_PENDING   = 0;
    public const CONSENT_ACTIVE    = 1;
    public const CONSENT_WITHDRAWN = 2;
    public const CONSENT_EXPIRED   = 3;

    public function __construct(private LocalDb $db, private string $storeCode)
    {
    }

    /**
     * 三选一检索 —— Pad 端明确选择输入类型，不做跨字段模糊搜索。
     * @param string $type card|phone|email
     */
    public function findBy(string $type, string $value): ?array
    {
        $col = match ($type) {
            'card'  => 'card_no',
            'phone' => 'phone',
            'email' => 'email',
            default => throw new \InvalidArgumentException('检索类型只能是 card/phone/email'),
        };
        return $this->db->one(
            "SELECT * FROM member WHERE store_code = ? AND {$col} = ? AND pseudonymized = 0 LIMIT 1",
            [$this->storeCode, $value]
        );
    }

    public function findById(int $id): ?array
    {
        return $this->db->one(
            'SELECT * FROM member WHERE store_code = ? AND id = ?',
            [$this->storeCode, $id]
        );
    }

    public function lockById(int $id): ?array
    {
        return $this->db->one(
            'SELECT * FROM member WHERE store_code = ? AND id = ? FOR UPDATE',
            [$this->storeCode, $id]
        );
    }

    /**
     * 内联新建会员（积分流程中直接创建）。
     *
     * 合规路径（docs/03 §4.4、docs/05 §2）：
     *   状态 pending → 积分【当场入账】但冻结不可兑换、不可营销推送
     *   → 发 double opt-in → 客人点同意后转 active 解冻
     *   → N 天未同意则积分冻结 + PII 假名化
     */
    /**
     * @param string $cardNo 实体卡号。必须是 card 库存表里真实存在、
     *                       且尚未绑定的一张 —— 校验与绑定由 CardService
     *                       在同一个事务里完成，本方法只负责写 member 行。
     */
    public function create(string $cardNo, ?string $phone, ?string $email, ?string $birthday): array
    {
        if (($phone === null || $phone === '') && ($email === null || $email === '')) {
            throw new \InvalidArgumentException('手机号与邮箱至少填一项，否则无法发送双重确认');
        }
        $card = \Vip\CardNumber::normalize($cardNo);
        if ($card === '') {
            throw new \InvalidArgumentException('必须提供实体卡号');
        }
        $now   = $this->db->now();
        $token = bin2hex(random_bytes(24));

        $this->db->exec(
            'INSERT INTO member
               (store_code, card_no, phone, email, birthday,
                consent_status, consent_token, created_at, updated_at)
             VALUES (?,?,?,?,?,?,?,?,?)',
            [$this->storeCode, $card, $phone ?: null, $email ?: null, $birthday ?: null,
             self::CONSENT_PENDING, $token, $now, $now]
        );
        $id = $this->db->lastInsertId();
        return $this->findById($id) ?? throw new \RuntimeException('会员创建后读取失败');
    }

    /**
     * 换卡时把会员行上的 card_no 同步过去。
     *
     * card_no 在 member 上是冗余的（权威在 card 表），留着是为了按卡号
     * 查会员时不用多join一次 —— 那是收银台上最高频的一次查询。
     * 冗余就必须有人负责同步，换卡是唯一会变的时刻。
     */
    public function updateCardNo(int $memberId, string $cardNo): void
    {
        $this->db->exec(
            'UPDATE member SET card_no = ?, updated_at = ? WHERE store_code = ? AND id = ?',
            [\Vip\CardNumber::normalize($cardNo), $this->db->now(), $this->storeCode, $memberId]
        );
    }

    /**
     * 应用积分变动。允许负余额（撤销已兑换积分的场景），
     * 不阻断撤销，仅标记，下次消费优先抵扣。docs/03 §4.3
     */
    public function applyDelta(int $memberId, int $points, int $visits, int $amountCents): void
    {
        $this->db->exec(
            'UPDATE member
                SET points_balance = points_balance + ?,
                    visit_count    = visit_count    + ?,
                    total_spent    = total_spent    + ?,
                    updated_at     = ?
              WHERE store_code = ? AND id = ?',
            [$points, $visits, Money::toStr($amountCents), $this->db->now(), $this->storeCode, $memberId]
        );
    }

    public function activateByToken(string $token, string $ip): ?array
    {
        $m = $this->db->one(
            'SELECT * FROM member WHERE consent_token = ? AND store_code = ?',
            [$token, $this->storeCode]
        );
        if ($m === null) {
            return null;
        }
        $this->db->exec(
            'UPDATE member SET consent_status = ?, consent_at = ?, consent_ip = ?, updated_at = ?
              WHERE id = ?',
            [self::CONSENT_ACTIVE, $this->db->now(), $ip, $this->db->now(), $m['id']]
        );
        return $this->findById((int)$m['id']);
    }

    /**
     * 假名化 —— 删除请求的落地方式。
     * 抹除 PII，保留全部流水（会计/税务留存义务）。
     */
    public function pseudonymize(int $memberId): void
    {
        $this->db->exec(
            'UPDATE member
                SET phone = NULL, email = NULL, birthday = NULL, consent_ip = NULL,
                    consent_token = NULL,
                    card_no = CONCAT("ANON-", SHA1(CONCAT(card_no, ?))),
                    pseudonymized = 1,
                    updated_at = ?
              WHERE store_code = ? AND id = ?',
            [bin2hex(random_bytes(8)), $this->db->now(), $this->storeCode, $memberId]
        );
    }

    /** 超期未同意的会员，供定时任务冻结与假名化 */
    public function expiredPending(int $days, int $limit = 100): array
    {
        $cut = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        return $this->db->all(
            'SELECT id, card_no FROM member
              WHERE store_code = ? AND consent_status = ? AND created_at < ? AND pseudonymized = 0
              LIMIT ' . max(1, min($limit, 100)),
            [$this->storeCode, self::CONSENT_PENDING, $cut]
        );
    }

    /**
     * 末次消费超过 N 年、且尚未假名化的会员（LOPDGDD 存储限制原则）。
     * 「末次消费」取账本里最后一条流水的时间，从没消费过的用建档时间。
     */
    public function staleForPii(int $years, int $limit = 100): array
    {
        $cut = date('Y-m-d H:i:s', strtotime("-{$years} years"));
        return $this->db->all(
            'SELECT m.id, m.card_no,
                    COALESCE(MAX(l.created_at), m.created_at) AS last_activity
               FROM member m
               LEFT JOIN point_ledger l
                      ON l.member_id = m.id AND l.store_code = m.store_code
              WHERE m.store_code = ? AND m.pseudonymized = 0
              GROUP BY m.id, m.card_no, m.created_at
             HAVING last_activity < ?
              ORDER BY last_activity ASC
              LIMIT ' . max(1, min($limit, 100)),
            [$this->storeCode, $cut]
        );
    }

    public function markConsentExpired(int $memberId): void
    {
        $this->db->exec(
            'UPDATE member SET consent_status = ?, updated_at = ? WHERE store_code = ? AND id = ?',
            [self::CONSENT_EXPIRED, $this->db->now(), $this->storeCode, $memberId]
        );
    }
}
