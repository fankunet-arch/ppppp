<?php
declare(strict_types=1);

namespace Vip\Http;

/**
 * 极简 JSON API 工具 —— 请求解析、响应、路由。
 *
 * 统一响应形态：
 *   成功 { "ok": true,  "data": ... }
 *   失败 { "ok": false, "error": "错误码", "message": "给收银员看的中文", "detail": {...} }
 *
 * 错误码是稳定的机器可读标识，message 才是给人看的 —— 前端按错误码分支，
 * 不要去匹配中文文案。
 */
final class Api
{
    public const COOKIE = 'vip_session';

    /** 错误码 → 收银员能看懂的中文。前端也有一份，这里是兜底。 */
    private const MESSAGES = [
        'unauthorized'           => '登录已过期，请重新登录',
        'forbidden'              => '当前账号没有此操作权限',
        'invalid_credentials'    => '工号或 PIN 不正确',
        'locked'                 => '连续输错多次，账号已临时锁定',
        'method_not_allowed'     => '请求方式不正确',
        'not_found'              => '接口不存在',
        'bad_request'            => '请求参数有误',
        'pos_unavailable'        => 'POS 主库暂时无法访问，可改用手工录入',
        'order_not_found'        => '未找到该订单',
        'not_dine_in'            => '外带订单不参与积分',
        'free_meal'              => '该订单已标记为免费餐，不积分',
        'redeemed'               => '该订单已使用十送一核销，本餐不计次不积分',
        'bad_invoice'            => '小票号无效，请核对 Factura Simplificada',
        'zero_amount'            => '该订单金额为 0，不积分',
        'exceeds_total'          => '分配金额超过订单可积分总额',
        'exceeds_portions'       => '分配份数超过订单套餐份数',
        'negative_allocation'    => '金额或份数不能为负',
        'duplicate_member'       => '同一会员重复出现，请检查',
        'empty_allocation'       => '请至少为一位会员分配金额',
        'invalid_member'         => '会员信息不完整',
        'member_not_found'       => '未找到该会员',
        'already_reversed'       => '该笔记账已经撤销过了',
        'not_reversible'         => '该笔流水不支持撤销',
        'reversal_window_expired'=> '超出自由撤销时限，需经理授权',
        'manual_entry_disabled'  => '手工录入功能已关闭',
        'exceeds_manual_limit'   => '超过手工录入单笔限额，需经理授权',
        'invalid_amount'         => '金额不合法',
        'db_unavailable'         => '本地数据库暂时不可用，请联系管理员',
        'server_error'           => '系统内部错误，请稍后重试',
    ];

    /** @var array<string,callable> 'METHOD /path' => handler */
    private array $routes = [];

    public function on(string $method, string $path, callable $h): void
    {
        $this->routes[strtoupper($method) . ' ' . $path] = $h;
    }

    public function dispatch(string $method, string $path): void
    {
        $method = strtoupper($method);
        $key    = $method . ' ' . $path;

        if (!isset($this->routes[$key])) {
            // 路径存在但方法不对 → 405，便于排错
            foreach (array_keys($this->routes) as $r) {
                if (str_ends_with($r, ' ' . $path)) {
                    self::fail('method_not_allowed', 405);
                }
            }
            self::fail('not_found', 404);
        }

        try {
            $this->routes[$key]();
        } catch (\PDOException $e) {
            // 本地库不可达单独给码，前端才能提示得准确
            error_log('[api] db: ' . $e->getMessage());
            self::fail('db_unavailable', 503);
        } catch (\Throwable $e) {
            error_log('[api] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            // 绝不把堆栈或连接串吐给客户端
            self::fail('server_error', 500);
        }
    }

    /** 解析 JSON 请求体 */
    public static function body(): array
    {
        $raw = file_get_contents('php://input') ?: '';
        if ($raw === '') {
            return [];
        }
        $d = json_decode($raw, true);
        return is_array($d) ? $d : [];
    }

    public static function str(array $b, string $k, ?string $default = null): ?string
    {
        $v = $b[$k] ?? $default;
        return $v === null ? null : trim((string)$v);
    }

    public static function int(array $b, string $k, int $default = 0): int
    {
        return isset($b[$k]) ? (int)$b[$k] : $default;
    }

    public static function ok(mixed $data = null): never
    {
        self::emit(200, ['ok' => true, 'data' => $data]);
    }

    public static function fail(string $code, int $status = 400, array $detail = []): never
    {
        $p = ['ok' => false, 'error' => $code, 'message' => self::MESSAGES[$code] ?? $code];
        if ($detail) {
            $p['detail'] = $detail;
        }
        self::emit($status, $p);
    }

    /** 把服务层返回的 ['ok'=>false,'error'=>...] 直接转成 HTTP 响应 */
    public static function fromResult(array $r, mixed $okData = null): never
    {
        if (($r['ok'] ?? false) === true) {
            self::ok($okData ?? $r);
        }
        $code   = (string)($r['error'] ?? 'bad_request');
        $status = match ($code) {
            'unauthorized'            => 401,
            'forbidden',
            'reversal_window_expired' => 403,
            'order_not_found',
            'member_not_found'        => 404,
            'pos_unavailable'         => 503,
            default                   => 400,
        };
        self::fail($code, $status, $r['detail'] ?? []);
    }

    private static function emit(int $status, array $payload): never
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            header('X-Content-Type-Options: nosniff');
            header('Referrer-Policy: same-origin');
        }
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    // ── 会话 Cookie ────────────────────────────────────────

    public static function readToken(): ?string
    {
        $t = $_COOKIE[self::COOKIE] ?? null;
        return is_string($t) && $t !== '' ? $t : null;
    }

    public static function setToken(string $token, int $ttlSeconds): void
    {
        setcookie(self::COOKIE, $token, [
            'expires'  => time() + $ttlSeconds,
            'path'     => '/',
            'httponly' => true,          // JS 读不到，降低 XSS 风险
            'samesite' => 'Strict',      // 同站限定，挡住跨站请求
            'secure'   => self::isHttps(),
        ]);
    }

    public static function clearToken(): void
    {
        setcookie(self::COOKIE, '', [
            'expires' => time() - 3600, 'path' => '/',
            'httponly' => true, 'samesite' => 'Strict', 'secure' => self::isHttps(),
        ]);
    }

    public static function isHttps(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ($_SERVER['SERVER_PORT'] ?? '') === '443'
            || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    }

    public static function clientIp(): ?string
    {
        return $_SERVER['REMOTE_ADDR'] ?? null;
    }
}
