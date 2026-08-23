<?php
declare(strict_types=1);

namespace Vip\Service;

/**
 * 出站消息 —— 短信与邮件。
 *
 * 只发，不收。门店网络是单向的（能出去、进不来），所以这里没有任何
 * 回调、webhook 或状态查询，发出去就结束。确认走「现场输码」，
 * 不依赖客人点链接（那需要公网入口，门店没有）。
 *
 * 凭据放 config.php（不入库、不进 git）；用哪个渠道放后台配置
 * （门店自己能改，且不涉及密钥）。
 */
class Messaging
{
    /**
     * 这个类不加 final：测试要用一个替身覆盖 send() / ready()，
     * 否则跑一次冒烟就会真的往 Twilio 发短信、往 SMTP 投递。
     * 生产代码里不要继承它。
     */

    public const CH_SMS   = 'sms';
    public const CH_EMAIL = 'email';

    /** @param array $cfg config.php 的 sms / mail 两段 */
    public function __construct(private array $cfg)
    {
    }

    /** 该渠道是否配齐了可用凭据 */
    public function ready(string $channel): bool
    {
        if ($channel === self::CH_SMS) {
            $s = $this->cfg['sms'] ?? [];
            return ($s['driver'] ?? 'none') === 'twilio'
                && trim((string)($s['sid'] ?? '')) !== ''
                && trim((string)($s['token'] ?? '')) !== ''
                && trim((string)($s['from'] ?? '')) !== '';
        }
        if ($channel === self::CH_EMAIL) {
            $m = $this->cfg['mail'] ?? [];
            return ($m['driver'] ?? 'none') === 'smtp'
                && trim((string)($m['host'] ?? '')) !== ''
                && trim((string)($m['from'] ?? '')) !== '';
        }
        return false;
    }

    /** 配齐凭据的渠道列表 */
    public function readyChannels(): array
    {
        return array_values(array_filter([self::CH_SMS, self::CH_EMAIL],
            fn($c) => $this->ready($c)));
    }

    /**
     * 发一条消息。
     *
     * @return array{ok:bool, error?:string, detail?:string}
     *         失败时 detail 只进日志，不给客户端 —— 里面可能带服务商返回的
     *         账号信息。
     */
    public function send(string $channel, string $to, string $subject, string $text): array
    {
        $to = trim($to);
        if ($to === '') {
            return ['ok' => false, 'error' => 'no_recipient'];
        }
        if (!$this->ready($channel)) {
            return ['ok' => false, 'error' => 'channel_not_configured'];
        }

        try {
            return $channel === self::CH_SMS
                ? $this->sendSms($to, $text)
                : $this->sendMail($to, $subject, $text);
        } catch (\Throwable $e) {
            // 发送失败绝不能把收银流程带崩 —— 上层据此提示「稍后重发」
            return ['ok' => false, 'error' => 'send_failed', 'detail' => $e->getMessage()];
        }
    }

    // ── 短信：Twilio ────────────────────────────────────────

    /**
     * Twilio 的 Messages API：一次 HTTPS POST，Basic Auth。
     * 没有引 SDK —— 就这一个接口，装个 SDK 反而多一堆依赖要跟版本。
     */
    private function sendSms(string $to, string $text): array
    {
        $s   = $this->cfg['sms'];
        $sid = (string)$s['sid'];
        $url = "https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json";

        [$code, $body] = $this->httpPost($url, [
            'To'   => $to,
            'From' => (string)$s['from'],
            'Body' => $text,
        ], $sid . ':' . (string)$s['token']);

        if ($code >= 200 && $code < 300) {
            return ['ok' => true];
        }
        // Twilio 的错误码很具体（21211=号码格式不对，21608=试用账号未验证号码…），
        // 原样留进日志，排障时不用猜
        return ['ok' => false, 'error' => 'send_failed',
                'detail' => "twilio HTTP {$code}: " . substr($body, 0, 300)];
    }

    // ── 邮件：SMTP ──────────────────────────────────────────

    /**
     * 极简 SMTP 客户端：连接 → EHLO → STARTTLS → AUTH LOGIN → 发信。
     *
     * 没用 PHPMailer 之类：门店服务器要保持零 composer 依赖，
     * 而这里只需要发一封纯文本邮件，够用。
     */
    private function sendMail(string $to, string $subject, string $text): array
    {
        $m    = $this->cfg['mail'];
        $host = (string)$m['host'];
        $port = (int)($m['port'] ?? 587);
        $from = (string)$m['from'];

        $fp = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, 10);
        if (!$fp) {
            return ['ok' => false, 'error' => 'send_failed',
                    'detail' => "连接 {$host}:{$port} 失败：{$errstr}"];
        }
        stream_set_timeout($fp, 15);

        try {
            $this->smtpExpect($fp, 220);
            $this->smtpCmd($fp, 'EHLO ' . $this->smtpHelo(), 250);

            // 587 走 STARTTLS；465 是隐式 TLS，这里不支持（配 587 即可）
            if ($port !== 25) {
                $this->smtpCmd($fp, 'STARTTLS', 220);
                if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new \RuntimeException('STARTTLS 握手失败');
                }
                $this->smtpCmd($fp, 'EHLO ' . $this->smtpHelo(), 250);
            }

            $user = trim((string)($m['user'] ?? ''));
            if ($user !== '') {
                $this->smtpCmd($fp, 'AUTH LOGIN', 334);
                $this->smtpCmd($fp, base64_encode($user), 334);
                $this->smtpCmd($fp, base64_encode((string)($m['password'] ?? '')), 235);
            }

            $this->smtpCmd($fp, "MAIL FROM:<{$from}>", 250);
            $this->smtpCmd($fp, "RCPT TO:<{$to}>", 250);
            $this->smtpCmd($fp, 'DATA', 354);

            $headers = [
                'From: ' . $from,
                'To: ' . $to,
                // 主题按 RFC 2047 编码，否则中文标题在多数客户端里是乱码
                'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=',
                'MIME-Version: 1.0',
                'Content-Type: text/plain; charset=UTF-8',
                'Content-Transfer-Encoding: base64',
                'Date: ' . date('r'),
            ];
            $body = chunk_split(base64_encode($text), 76, "\r\n");
            // 正文里以点开头的行要转义成两个点，否则会被当成结束标记
            $data = implode("\r\n", $headers) . "\r\n\r\n" . $body;
            $data = preg_replace('/^\./m', '..', $data) ?? $data;

            fwrite($fp, $data . "\r\n.\r\n");
            $this->smtpExpect($fp, 250);
            $this->smtpCmd($fp, 'QUIT', 221, false);
            fclose($fp);
            return ['ok' => true];
        } catch (\Throwable $e) {
            @fclose($fp);
            return ['ok' => false, 'error' => 'send_failed', 'detail' => $e->getMessage()];
        }
    }

    private function smtpHelo(): string
    {
        $h = (string)($this->cfg['mail']['helo'] ?? '');
        return $h !== '' ? $h : 'localhost';
    }

    /** @param resource $fp */
    private function smtpCmd($fp, string $cmd, int $expect, bool $check = true): void
    {
        fwrite($fp, $cmd . "\r\n");
        if ($check) {
            $this->smtpExpect($fp, $expect);
        }
    }

    /** @param resource $fp */
    private function smtpExpect($fp, int $expect): void
    {
        $line = '';
        // 多行响应：250-xxx continues，250 xxx 结束
        while (($l = fgets($fp, 1024)) !== false) {
            $line = $l;
            if (strlen($l) < 4 || $l[3] !== '-') {
                break;
            }
        }
        $code = (int)substr(trim($line), 0, 3);
        if ($code !== $expect) {
            throw new \RuntimeException("SMTP 期望 {$expect}，实得：" . trim($line));
        }
    }

    // ── HTTP ────────────────────────────────────────────────

    /**
     * @return array{0:int, 1:string} [HTTP 状态码, 响应体]
     *
     * 优先用 cURL；没装时回落到 stream，因为宝塔常把 allow_url_fopen
     * 关掉，两条路都留着才不至于在现场卡住。
     */
    private function httpPost(string $url, array $form, string $basicAuth): array
    {
        $payload = http_build_query($form);

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $payload,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_USERPWD        => $basicAuth,
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_CONNECTTIMEOUT => 8,
            ]);
            $body = (string)curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err  = curl_error($ch);
            curl_close($ch);
            if ($code === 0) {
                throw new \RuntimeException('cURL 失败：' . $err);
            }
            return [$code, $body];
        }

        $ctx = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => "Content-Type: application/x-www-form-urlencoded\r\n"
                             . 'Authorization: Basic ' . base64_encode($basicAuth) . "\r\n",
            'content'       => $payload,
            'timeout'       => 15,
            'ignore_errors' => true,
        ]]);
        $body = @file_get_contents($url, false, $ctx);
        if ($body === false) {
            throw new \RuntimeException('出站请求失败（cURL 未装且 allow_url_fopen 可能被关闭）');
        }
        $code = 0;
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $mm)) {
                $code = (int)$mm[1];
            }
        }
        return [$code, (string)$body];
    }
}
