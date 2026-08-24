<?php
declare(strict_types=1);

namespace Vip\Service;

use Vip\LocalDb;
use Vip\Repo\AuditRepo;
use Vip\Repo\ConfigRepo;
use Vip\Repo\MemberRepo;

/**
 * 双重确认 —— 现场输码版。
 *
 * 原方案是「客人点短信里的链接」，那需要一个公网可达的端点来接收点击，
 * 而门店网络是单向的（能出去、进不来）。这条路走不通。
 *
 * 改成：短信/邮件只发一个 6 位码（纯出站），客人当场报给收银员，
 * Pad 里输入即完成确认。举证靠审计日志 —— 发送时间、发到哪个渠道、
 * 校验通过时间、经手的操作员，链条是完整的。
 *
 * 代价：客人必须当场完成。走了之后可以下次到店重发，重发会换一个新码。
 */
final class ConsentService
{
    public const CODE_LEN  = 6;
    /** 码是当场用的，30 分钟足够，长了徒增被人看到的窗口 */
    public const TTL_MIN   = 30;
    public const MAX_FAIL  = 5;

    public function __construct(
        private LocalDb $db,
        private MemberRepo $members,
        private Messaging $msg,
        private ConfigRepo $cfg,
        private AuditRepo $audit,
        private string $storeCode,
        private string $storeName,
    ) {
    }

    /**
     * 这位会员该走哪个渠道。
     * auto：有手机号就发短信（客人当场报码最方便），否则发邮件。
     */
    public function channelFor(array $member): ?string
    {
        $pref  = $this->cfg->get('consent_channel', 'auto');
        $phone = trim((string)($member['phone'] ?? ''));
        $email = trim((string)($member['email'] ?? ''));

        $wants = match ($pref) {
            'sms'   => [Messaging::CH_SMS],
            'email' => [Messaging::CH_EMAIL],
            default => [Messaging::CH_SMS, Messaging::CH_EMAIL],
        };
        foreach ($wants as $c) {
            $has = $c === Messaging::CH_SMS ? $phone !== '' : $email !== '';
            if ($has && $this->msg->ready($c)) {
                return $c;
            }
        }
        return null;
    }

    /**
     * 生成并发送确认码。
     *
     * @return array{ok:bool, error?:string, channel?:string, expires_at?:string}
     */
    public function sendCode(int $memberId, array $operator): array
    {
        $m = $this->members->findById($memberId);
        if ($m === null) {
            return ['ok' => false, 'error' => 'member_not_found'];
        }
        if ((int)$m['consent_status'] === MemberRepo::CONSENT_ACTIVE) {
            return ['ok' => false, 'error' => 'consent_already_done'];
        }

        $channel = $this->channelFor($m);
        if ($channel === null) {
            // 说清是「没配渠道」还是「客人没留对应的联系方式」
            return ['ok' => false, 'error' => 'no_channel'];
        }

        $code    = $this->randomCode();
        $expires = date('Y-m-d H:i:s', time() + self::TTL_MIN * 60);
        $to      = $channel === Messaging::CH_SMS ? (string)$m['phone'] : (string)$m['email'];

        $r = $this->msg->send($channel, $to, $this->subject(), $this->body($code));
        if (!$r['ok']) {
            // 明细只进日志：里面可能带服务商返回的账号信息
            error_log(sprintf('[consent] 发送失败 member=%d channel=%s: %s',
                $memberId, $channel, $r['detail'] ?? ($r['error'] ?? '?')));
            return ['ok' => false, 'error' => (string)$r['error']];
        }

        // 码只存 hash，与卡背 PIN 同一套做法 —— 库被拖走也拿不到
        $this->db->exec(
            'UPDATE member
                SET consent_code_hash = ?, consent_code_sent_at = ?, consent_code_expires = ?,
                    consent_code_fail = 0, consent_channel = ?, updated_at = ?
              WHERE store_code = ? AND id = ?',
            [password_hash($code, PASSWORD_BCRYPT), $this->db->now(), $expires,
             $channel, $this->db->now(), $this->storeCode, $memberId]
        );

        $this->audit->log('consent_code_sent', [
            'target_type' => 'member', 'target_id' => (string)$memberId,
            'operator_id' => $operator['id'] ?? null,
            'operator_name' => $operator['name'] ?? null,
            'device' => $operator['device'] ?? null,
            // 收件人做掩码：审计日志本身也不该成为一份联系方式清单
            'detail' => ['channel' => $channel, 'to' => $this->mask($to)],
        ]);

        return ['ok' => true, 'channel' => $channel, 'expires_at' => $expires];
    }

    /**
     * 校验确认码。通过则积分解冻。
     *
     * @return array{ok:bool, error?:string, left?:int}
     */
    public function verifyCode(int $memberId, string $code, array $operator, ?string $ip = null): array
    {
        return $this->db->transaction(function () use ($memberId, $code, $operator, $ip) {
            $m = $this->members->lockById($memberId);
            if ($m === null) {
                return ['ok' => false, 'error' => 'member_not_found'];
            }
            if ((int)$m['consent_status'] === MemberRepo::CONSENT_ACTIVE) {
                return ['ok' => true];   // 幂等：重复确认不报错
            }
            if (($m['consent_code_hash'] ?? null) === null) {
                return ['ok' => false, 'error' => 'code_not_sent'];
            }
            if ((int)$m['consent_code_fail'] >= self::MAX_FAIL) {
                return ['ok' => false, 'error' => 'code_locked'];
            }
            if (($m['consent_code_expires'] ?? null) !== null
                && strtotime((string)$m['consent_code_expires']) < time()) {
                return ['ok' => false, 'error' => 'code_expired'];
            }

            if (!password_verify(trim($code), (string)$m['consent_code_hash'])) {
                $fail = (int)$m['consent_code_fail'] + 1;
                $this->db->exec(
                    'UPDATE member SET consent_code_fail = ?, updated_at = ?
                      WHERE store_code = ? AND id = ?',
                    [$fail, $this->db->now(), $this->storeCode, $memberId]
                );
                return ['ok' => false, 'error' => 'code_wrong',
                        'left' => max(0, self::MAX_FAIL - $fail)];
            }

            // 通过：置为已同意，清掉码，积分解冻
            $this->db->exec(
                'UPDATE member
                    SET consent_status = ?, consent_at = ?, consent_ip = ?,
                        consent_code_hash = NULL, consent_code_expires = NULL,
                        consent_code_fail = 0, updated_at = ?
                  WHERE store_code = ? AND id = ?',
                [MemberRepo::CONSENT_ACTIVE, $this->db->now(), $ip, $this->db->now(),
                 $this->storeCode, $memberId]
            );

            $this->audit->log('consent_confirmed', [
                'target_type' => 'member', 'target_id' => (string)$memberId,
                'operator_id' => $operator['id'] ?? null,
                'operator_name' => $operator['name'] ?? null,
                'device' => $operator['device'] ?? null,
                'detail' => ['channel' => $m['consent_channel'], 'ip' => $ip],
            ]);

            return ['ok' => true];
        });
    }

    /** 6 位数字。用 random_int：可预测的码等于没有码 */
    private function randomCode(): string
    {
        $out = '';
        for ($i = 0; $i < self::CODE_LEN; $i++) {
            $out .= (string)random_int(0, 9);
        }
        return $out;
    }

    private function subject(): string
    {
        return $this->storeName . ' 会员确认码';
    }

    private function body(string $code): string
    {
        $policy = trim((string)$this->cfg->get('privacy_policy_url', ''));
        $lines = [
            "【{$this->storeName}】会员确认码：{$code}",
            '',
            '请把这个码告诉收银员以完成登记。' . self::TTL_MIN . ' 分钟内有效。',
            '登记后我们仅保存你留下的联系方式用于会员积分服务。',
        ];
        if ($policy !== '') {
            $lines[] = '隐私政策：' . $policy;
        }
        $lines[] = '若非本人操作，请忽略本条消息。';
        return implode("\n", $lines);
    }

    /** 掩码：600***888 / ab***@example.com */
    private function mask(string $s): string
    {
        if (str_contains($s, '@')) {
            [$u, $d] = explode('@', $s, 2);
            return substr($u, 0, 2) . '***@' . $d;
        }
        return strlen($s) <= 5 ? '***' : substr($s, 0, 3) . '***' . substr($s, -3);
    }
}
