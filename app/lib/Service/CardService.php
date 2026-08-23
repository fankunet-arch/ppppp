<?php
declare(strict_types=1);

namespace Vip\Service;

use Vip\CardNumber;
use Vip\LocalDb;
use Vip\Repo\AuditRepo;
use Vip\Repo\CardRepo;
use Vip\Repo\MemberRepo;

/**
 * 实体卡的业务动作 —— 扫卡、绑卡、挂失换卡。
 *
 * Pad 端的交互是「扫一下，系统决定下一步」，所以 lookup() 要把四种
 * 情况分得清清楚楚，每种给一句收银员能照着做的话：
 *
 *   不在库存里 → 这不是本店发行的卡（**防伪造的真正防线在这里**）
 *   已挂失作废 → 此卡已作废，请换一张
 *   已绑定会员 → 直接进入该会员，正常使用
 *   库存中未激活 → 进入建卡流程
 */
final class CardService
{
    public function __construct(
        private LocalDb $db,
        private CardRepo $cards,
        private MemberRepo $members,
        private CardNumber $cardNo,
        private AuditRepo $audit,
        private string $storeCode,
    ) {
    }

    /**
     * 扫卡/输卡号后的第一步：这张卡现在是什么状态、下一步该做什么。
     *
     * @return array{ok:bool, state:string, error?:string, card?:array, member?:array}
     *         state: unknown | void | active | stock
     */
    public function lookup(string $raw): array
    {
        $n = CardNumber::normalize($raw);

        // 结构都不对：多半是扫错了别的二维码，或手输少打了一位。
        // 这一层不查库，只为把提示语说得更准 —— 说「卡号不完整」
        // 比说「查无此卡」有用得多，后者会让收银员去翻库存。
        if (!$this->cardNo->isWellFormed($n)) {
            return ['ok' => false, 'state' => 'unknown', 'error' => 'card_malformed'];
        }

        $card = $this->cards->findByCardNo($n);
        if ($card === null) {
            // ★ 防伪造就靠这一条：卡号不在库存里，一律拒绝。
            //   卡号里的随机后缀只让人猜不到，判真伪的是这张表。
            return ['ok' => false, 'state' => 'unknown', 'error' => 'card_unknown'];
        }

        $status = (int)$card['status'];
        if ($status === CardRepo::STATUS_VOID) {
            return ['ok' => false, 'state' => 'void', 'error' => 'card_void', 'card' => $card];
        }

        if ($status === CardRepo::STATUS_ACTIVE) {
            $member = $card['member_id'] !== null
                ? $this->members->findById((int)$card['member_id'])
                : null;
            if ($member === null) {
                // 卡说自己绑了人，人却查不到（已假名化，或数据被外力改过）。
                // 不要装作没事继续用，报出来让人查。
                return ['ok' => false, 'state' => 'active', 'error' => 'card_member_missing',
                        'card' => $card];
            }
            return ['ok' => true, 'state' => 'active', 'card' => $card, 'member' => $member];
        }

        return ['ok' => true, 'state' => 'stock', 'card' => $card];
    }

    /**
     * 把一张库存卡绑给新建的会员。
     *
     * 整个过程在一个事务里：卡加锁 → 建会员 → 激活绑定。
     * 任何一步失败都整体回滚，不会留下「会员建好了但卡没绑上」的半成品。
     *
     * @return array{ok:bool, error?:string, hint?:string, member?:array, card?:array}
     */
    public function bindNewMember(
        string $raw,
        ?string $phone,
        ?string $email,
        ?string $birthday,
        array $operator,
    ): array {
        $n = CardNumber::normalize($raw);
        if (!$this->cardNo->isWellFormed($n)) {
            return ['ok' => false, 'error' => 'card_malformed'];
        }

        try {
            return $this->db->transaction(function () use ($n, $phone, $email, $birthday, $operator): array {
                // FOR UPDATE：两台 Pad 同时扫同一张卡时，只有一台能绑成功
                $card = $this->cards->lockByCardNo($n);
                if ($card === null) {
                    return ['ok' => false, 'error' => 'card_unknown'];
                }
                $status = (int)$card['status'];
                if ($status === CardRepo::STATUS_VOID) {
                    return ['ok' => false, 'error' => 'card_void'];
                }
                if ($status === CardRepo::STATUS_ACTIVE) {
                    // 已经是别人的卡了 —— 该走「直接进入该会员」，不是建新的
                    return ['ok' => false, 'error' => 'card_taken'];
                }

                try {
                    $member = $this->members->create($n, $phone, $email, $birthday);
                } catch (\InvalidArgumentException $e) {
                    return ['ok' => false, 'error' => 'bad_request', 'hint' => $e->getMessage()];
                }

                $this->cards->activate((int)$card['id'], (int)$member['id'],
                    isset($operator['id']) ? (int)$operator['id'] : null);

                $this->audit->log('card_bind', [
                    'target_type' => 'card', 'target_id' => $n,
                    'operator_id' => $operator['id'] ?? null,
                    'operator_name' => $operator['name'] ?? null,
                    'device' => $operator['device'] ?? null,
                    'detail' => ['member_id' => (int)$member['id'], 'serial' => (int)$card['serial']],
                ]);

                return ['ok' => true, 'member' => $member,
                        'card' => $this->cards->findByCardNo($n)];
            });
        } catch (\PDOException $e) {
            // uk_member 撞了：这位会员已经有卡了。让它走到这里而不是提前查，
            // 是因为「先查再写」在并发下仍会漏 —— 唯一键才是真正的守门人。
            if (str_contains($e->getMessage(), 'uk_member')) {
                return ['ok' => false, 'error' => 'member_has_card'];
            }
            throw $e;
        }
    }

    /**
     * 挂失/损坏换卡：作废旧卡，把会员绑到新卡上。
     *
     * 旧卡的 member_id 会被清空（否则 uk_member 唯一键挡住新卡），
     * 绑定历史留在 audit_log 里。
     *
     * @return array{ok:bool, error?:string, card?:array}
     */
    public function replaceCard(int $memberId, string $newRaw, string $reason, array $operator): array
    {
        $n = CardNumber::normalize($newRaw);
        if (!$this->cardNo->isWellFormed($n)) {
            return ['ok' => false, 'error' => 'card_malformed'];
        }

        return $this->db->transaction(function () use ($memberId, $n, $reason, $operator): array {
            $member = $this->members->findById($memberId);
            if ($member === null) {
                return ['ok' => false, 'error' => 'member_not_found'];
            }

            $newCard = $this->cards->lockByCardNo($n);
            if ($newCard === null) {
                return ['ok' => false, 'error' => 'card_unknown'];
            }
            if ((int)$newCard['status'] !== CardRepo::STATUS_STOCK) {
                return ['ok' => false, 'error' => 'card_not_available'];
            }

            $old = $this->cards->findByMemberId($memberId);
            if ($old !== null) {
                // 必须先解绑再绑新的，顺序反了会撞唯一键
                $this->cards->void((int)$old['id'], $reason);
            }

            $this->cards->activate((int)$newCard['id'], $memberId,
                isset($operator['id']) ? (int)$operator['id'] : null);
            $this->members->updateCardNo($memberId, $n);

            $this->audit->log('card_replace', [
                'target_type' => 'card', 'target_id' => $n,
                'operator_id' => $operator['id'] ?? null,
                'operator_name' => $operator['name'] ?? null,
                'device' => $operator['device'] ?? null,
                'detail' => [
                    'member_id' => $memberId,
                    'old_card'  => $old['card_no'] ?? null,
                    'reason'    => $reason,
                ],
            ]);

            return ['ok' => true, 'card' => $this->cards->findByCardNo($n)];
        });
    }
}
