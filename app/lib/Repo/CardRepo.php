<?php
declare(strict_types=1);

namespace Vip\Repo;

use Vip\CardNumber;
use Vip\LocalDb;

/**
 * card —— 实体会员卡库存。
 *
 * 这张表是判定卡片真伪的【唯一权威】：扫到不在表里的号码一律拒绝。
 * 卡号里的随机后缀只让人猜不到，不负责判真伪。
 *
 * 生命周期：
 *   批次生成 → status=0 库存中
 *   收银员扫卡建会员 → status=1 已激活，绑定 member_id
 *   挂失/损坏 → status=2 已作废，member_id 清空（历史留在 audit_log）
 */
final class CardRepo
{
    public const STATUS_STOCK   = 0;   // 库存中，未激活
    public const STATUS_ACTIVE  = 1;   // 已激活，绑定会员
    public const STATUS_VOID    = 2;   // 已作废/挂失

    /**
     * 过期后仍可到店换卡结转的宽限期（月）。不印在卡上 ——
     * 实际比承诺的宽松只有好处。
     *
     * 这是**兜底默认值**，真正生效的是 sys_config.card_grace_months。
     */
    public const GRACE_MONTHS = 6;

    /** 提前提醒换卡的默认天数。真正生效的是 sys_config.card_expiring_soon_days */
    public const EXPIRING_SOON_DAYS = 30;

    /** 卡背 PIN 位数。纯数字：好印、好念，客人报的时候没有字母歧义 */
    public const PIN_LEN = 6;

    /** 连续验错多少次锁定 */
    public const PIN_MAX_FAIL = 5;

    /** 锁定时长（分钟） */
    public const PIN_LOCK_MINUTES = 15;

    public function __construct(
        private LocalDb $db,
        private string $storeCode,
        private CardNumber $cardNo,
    ) {
    }

    // ── 查询 ────────────────────────────────────────────────

    /**
     * 按卡号找卡。输入会先归一化 —— 收银员照着卡面手输时
     * 把 0 读成 O、1 读成 I 是常事。
     */
    public function findByCardNo(string $cardNo): ?array
    {
        $n = CardNumber::normalize($cardNo);
        if ($n === '') {
            return null;
        }
        return $this->db->one(
            'SELECT * FROM card WHERE store_code = ? AND card_no = ? LIMIT 1',
            [$this->storeCode, $n]
        );
    }

    public function findByMemberId(int $memberId): ?array
    {
        return $this->db->one(
            'SELECT * FROM card WHERE store_code = ? AND member_id = ? LIMIT 1',
            [$this->storeCode, $memberId]
        );
    }

    /** 加锁读，用于激活/核销这类要防并发的路径 */
    public function lockByCardNo(string $cardNo): ?array
    {
        return $this->db->one(
            'SELECT * FROM card WHERE store_code = ? AND card_no = ? FOR UPDATE',
            [$this->storeCode, CardNumber::normalize($cardNo)]
        );
    }

    // ── 批次生成 ────────────────────────────────────────────

    /**
     * 生成一批卡。
     *
     * 返回【含明文 PIN】的清单，交给印刷厂。明文只在这一次返回里出现，
     * 库里存的是 password_hash —— 所以调用方拿到后必须立刻导出并销毁，
     * 之后再也取不回来。
     *
     * @return array<int, array{card_no:string, display:string, pin:string, serial:int}>
     */
    /**
     * @param string|null $validTo 有效期至（YYYY-MM-DD，含当天）。
     *                             必须与卡面印刷的日期一致 —— 卡面是唯一的
     *                             告知证据，两边不一致就等于没告知。
     */
    public function generateBatch(string $batchNo, int $count, ?string $validTo = null): array
    {
        $batchNo = strtoupper(trim($batchNo));
        if (!preg_match('/^[A-Z0-9_-]{1,32}$/', $batchNo)) {
            throw new \InvalidArgumentException('批次号只能是字母数字与 - _，最多 32 位');
        }
        if ($count < 1 || $count > 5000) {
            // 上限是为了别让一次误操作生成几十万张废卡；要更多就分批
            throw new \InvalidArgumentException('单批数量需在 1 ~ 5000 之间');
        }
        if ($this->batchExists($batchNo)) {
            throw new \InvalidArgumentException("批次号 {$batchNo} 已存在，换一个");
        }
        if ($validTo !== null && $validTo !== '') {
            $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $validTo);
            if ($d === false || $d->format('Y-m-d') !== $validTo) {
                throw new \InvalidArgumentException('有效期格式应为 YYYY-MM-DD');
            }
            if ($d->format('Y-m-d') <= date('Y-m-d')) {
                // 印一批一发就过期的卡，只会在柜台上制造混乱
                throw new \InvalidArgumentException('有效期必须晚于今天');
            }
        } else {
            $validTo = null;
        }

        $now   = $this->db->now();
        $start = $this->nextSerial();
        $out   = [];

        $this->db->transaction(function () use ($batchNo, $count, $now, $start, $validTo, &$out): void {
            for ($i = 0; $i < $count; $i++) {
                $serial = $start + $i;
                // 后缀随机，理论上可能与同一顺序号的历史值重复，但顺序号本身
                // 唯一，所以整体卡号不会撞。uk_serial 是最后一道保险。
                $no  = $this->cardNo->make($serial);
                $pin = $this->randomPin();

                $this->db->exec(
                    'INSERT INTO card
                       (store_code, card_no, serial, batch_no, valid_to, status,
                        pin_hash, created_at, updated_at)
                     VALUES (?,?,?,?,?,?,?,?,?)',
                    [$this->storeCode, $no, $serial, $batchNo, $validTo, self::STATUS_STOCK,
                     password_hash($pin, PASSWORD_BCRYPT), $now, $now]
                );

                $out[] = [
                    'serial'   => $serial,
                    'card_no'  => $no,
                    'display'  => $this->cardNo->format($no),
                    'pin'      => $pin,
                    'valid_to' => $validTo,
                ];
            }
        });

        return $out;
    }

    public function batchExists(string $batchNo): bool
    {
        return (bool)$this->db->value(
            'SELECT 1 FROM card WHERE store_code = ? AND batch_no = ? LIMIT 1',
            [$this->storeCode, strtoupper(trim($batchNo))]
        );
    }

    /** 下一个可用顺序号 */
    public function nextSerial(): int
    {
        $max = $this->db->value(
            'SELECT MAX(serial) FROM card WHERE store_code = ?',
            [$this->storeCode]
        );
        return $max === null ? 1 : (int)$max + 1;
    }

    /** @return array<int, array{batch_no:string, total:int, stock:int, active:int, void:int, created_at:string}> */
    public function batches(): array
    {
        return $this->db->all(
            'SELECT batch_no,
                    COUNT(*)                                        AS total,
                    SUM(status = ?)                                 AS stock,
                    SUM(status = ?)                                 AS active,
                    SUM(status = ?)                                 AS void_cnt,
                    MIN(serial)                                     AS serial_from,
                    MAX(serial)                                     AS serial_to,
                    MIN(created_at)                                 AS created_at,
                    MAX(valid_to)                                   AS valid_to
               FROM card
              WHERE store_code = ?
           GROUP BY batch_no
           ORDER BY MIN(serial) DESC',
            [self::STATUS_STOCK, self::STATUS_ACTIVE, self::STATUS_VOID, $this->storeCode]
        );
    }

    // ── 有效期 ──────────────────────────────────────────────

    /** 该卡是否已过有效期（valid_to 为空视为不设有效期） */
    public static function isExpired(array $card, ?string $today = null): bool
    {
        $v = $card['valid_to'] ?? null;
        return $v !== null && (string)$v < ($today ?? date('Y-m-d'));
    }

    /** 距到期还有几天；不设有效期返回 null，已过期返回负数 */
    public static function daysLeft(array $card, ?string $today = null): ?int
    {
        $v = $card['valid_to'] ?? null;
        if ($v === null) {
            return null;
        }
        $a = new \DateTimeImmutable($today ?? date('Y-m-d'));
        $b = new \DateTimeImmutable((string)$v);
        return (int)$a->diff($b)->format('%r%a');
    }

    /**
     * 过期且已超过宽限期 —— 前台到这一步就换不了卡了，得经理强制换发。
     *
     * ★ $graceMonths 是【必填】的，故意不给默认值。
     *   宽限期是后台可调的（sys_config.card_grace_months），
     *   给个默认值就等于允许调用方悄悄绕过后台设置 ——
     *   那样后台改了数字而现场行为不变，是最难查的一类 bug。
     *   要拿这个值请走 CardService，它会从配置里读。
     */
    public static function graceOver(array $card, int $graceMonths, ?string $today = null): bool
    {
        $v = $card['valid_to'] ?? null;
        if ($v === null) {
            return false;
        }
        $cut = (new \DateTimeImmutable((string)$v))
            ->modify('+' . $graceMonths . ' months')->format('Y-m-d');
        return ($today ?? date('Y-m-d')) > $cut;
    }

    // ── 激活与作废 ──────────────────────────────────────────

    /** 绑定到会员。调用方需已在事务内并对卡加过锁。 */
    public function activate(int $cardId, int $memberId, ?int $operatorId): void
    {
        $now = $this->db->now();
        $n = $this->db->exec(
            'UPDATE card
                SET status = ?, member_id = ?, activated_at = ?, activated_by = ?, updated_at = ?
              WHERE store_code = ? AND id = ? AND status = ?',
            [self::STATUS_ACTIVE, $memberId, $now, $operatorId, $now,
             $this->storeCode, $cardId, self::STATUS_STOCK]
        );
        if ($n !== 1) {
            // 状态被别的请求抢先改过 —— 宁可报错，也不要覆盖出一张双主的卡
            throw new \RuntimeException('卡状态已变化，激活未生效');
        }
    }

    /**
     * 作废/挂失。
     *
     * ★ member_id 必须清空，否则 uk_member 唯一键会挡住这位会员绑新卡。
     *   绑定历史留在 audit_log 里，不靠这张表回溯。
     */
    public function void(int $cardId, string $reason): void
    {
        $now = $this->db->now();
        $this->db->exec(
            'UPDATE card
                SET status = ?, member_id = NULL, voided_at = ?, void_reason = ?, updated_at = ?
              WHERE store_code = ? AND id = ?',
            [self::STATUS_VOID, $now, $this->clip($reason, 190), $now, $this->storeCode, $cardId]
        );
    }

    // ── 卡背 PIN ────────────────────────────────────────────

    /**
     * 校验卡背 PIN。
     *
     * @return array{ok:bool, error?:string, locked_until?:string}
     */
    public function verifyPin(array $card, string $pin): array
    {
        if (($card['pin_locked_until'] ?? null) !== null
            && strtotime((string)$card['pin_locked_until']) > time()) {
            return ['ok' => false, 'error' => 'pin_locked',
                    'locked_until' => (string)$card['pin_locked_until']];
        }
        if (($card['pin_hash'] ?? null) === null) {
            return ['ok' => false, 'error' => 'pin_not_set'];
        }

        if (password_verify(trim($pin), (string)$card['pin_hash'])) {
            $this->resetPinFail((int)$card['id']);
            return ['ok' => true];
        }

        $fail = (int)$card['pin_fail'] + 1;
        $now  = $this->db->now();
        if ($fail >= self::PIN_MAX_FAIL) {
            $until = date('Y-m-d H:i:s', time() + self::PIN_LOCK_MINUTES * 60);
            $this->db->exec(
                'UPDATE card SET pin_fail = ?, pin_locked_until = ?, updated_at = ?
                  WHERE store_code = ? AND id = ?',
                [$fail, $until, $now, $this->storeCode, (int)$card['id']]
            );
            return ['ok' => false, 'error' => 'pin_locked', 'locked_until' => $until];
        }
        $this->db->exec(
            'UPDATE card SET pin_fail = ?, updated_at = ? WHERE store_code = ? AND id = ?',
            [$fail, $now, $this->storeCode, (int)$card['id']]
        );
        return ['ok' => false, 'error' => 'pin_wrong'];
    }

    public function resetPinFail(int $cardId): void
    {
        $this->db->exec(
            'UPDATE card SET pin_fail = 0, pin_locked_until = NULL, updated_at = ?
              WHERE store_code = ? AND id = ?',
            [$this->db->now(), $this->storeCode, $cardId]
        );
    }

    /** 6 位数字。用 random_int：可预测的 PIN 等于没有 PIN */
    public function randomPin(): string
    {
        $out = '';
        for ($i = 0; $i < self::PIN_LEN; $i++) {
            $out .= (string)random_int(0, 9);
        }
        return $out;
    }

    /**
     * 按字节截断，并退到完整的 UTF-8 字符边界。
     *
     * 不用 mb_substr —— 现场踩过 mbstring 没装（PointsEngine 里的
     * mb_strtoupper 曾让整个核销路径挂掉）。直接 substr 又会把多字节
     * 字符切成半个，塞进库轻则乱码、重则被服务端拒绝。
     */
    private function clip(string $s, int $maxBytes): string
    {
        $s = trim($s);
        if (strlen($s) <= $maxBytes) {
            return $s;
        }
        $s = substr($s, 0, $maxBytes);
        // 先退掉续字节（10xxxxxx）
        while ($s !== '' && (ord($s[strlen($s) - 1]) & 0xC0) === 0x80) {
            $s = substr($s, 0, -1);
        }
        // 若结尾停在某个多字节字符的首字节上，它也不完整，一并去掉
        if ($s !== '' && (ord($s[strlen($s) - 1]) & 0xC0) === 0xC0) {
            $s = substr($s, 0, -1);
        }
        return $s;
    }
}
