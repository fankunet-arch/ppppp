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
    /**
     * 业务层「没找到」用的状态码。
     *
     * ★ 不要改回 404。现场踩过：nginx 开着 fastcgi_intercept_errors，
     *   配合 error_page 404，会把我们的 JSON 响应体【整个换成它自己的
     *   404 页面】。收银员看到的是「服务器返回的不是 JSON…404 Not Found
     *   nginx」，于是以为系统坏了，而实际上只是卡号打错了。
     *
     *   而且 404 在语义上本来就不对：接口是存在的，不存在的是那张卡。
     *   422（请求格式没问题，但内容处理不了）更贴切，也不在任何常见
     *   error_page 配置的拦截名单里。
     *
     *   这条防线放在应用层而不是靠运维配对 nginx —— 换一台机器、
     *   换一个面板，配置就可能又变回去。
     */
    public const NOT_FOUND = 422;

    /**
     * 本次请求用哪种语言回话。
     *
     * 由 index.php 在分派前按「请求头 → 登录账号 → 后台默认」的顺序定下来。
     * 做成静态量是因为 fail() 到处都在调，逐层传语言会污染每一个签名，
     * 而这个值在单次请求内是恒定的。
     */
    private static string $lang = \Vip\Lang::FALLBACK;

    public static function setLang(?string $lang): void
    {
        self::$lang = \Vip\Lang::normalize($lang);
    }

    public static function lang(): string
    {
        return self::$lang;
    }

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
        'pin_too_short'          => 'PIN 太短，至少 6 位',
        'pin_unchanged'          => '新 PIN 不能与旧 PIN 相同',
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

        // ── 实体卡 ──────────────────────────────────────────
        // 每一条都要让收银员知道【下一步该做什么】，而不是只说「不行」
        'card_malformed'         => '卡号不完整，请重新扫描或核对卡面号码',
        'card_unknown'           => '这不是本店发行的会员卡',
        'card_void'              => '此卡已挂失作废，请换一张新卡',
        'card_taken'             => '此卡已绑定其他会员',
        'card_not_available'     => '这张卡不在库存中，无法发给客人',
        'card_expired'           => '此卡已过有效期，请为客人换发新卡（积分会一并转过去）',
        'grace_over'             => '这张卡已超过换卡宽限期，积分按规则已失效 —— 需经理强制换发',
        'card_expiring_soon'     => '这张卡快到期了',
        'card_member_missing'    => '卡片绑定的会员查不到，请联系管理员',
        'member_has_card'        => '该会员已有一张卡，如需换卡请走挂失换卡',
        'card_required'          => '请先扫描客人的实体会员卡',
        'pin_wrong'              => '卡背 PIN 不正确',
        'pin_locked'             => '卡背 PIN 连续输错多次，已临时锁定',
        'pin_not_set'            => '此卡没有设置 PIN，请联系管理员',
        'pin_required'           => '请让客人刮开卡背并报出 PIN',
        'card_missing'           => '该会员当前没有绑定的卡，请先补发一张',
        'reason_required'        => '强制核销必须填写原因',
        'pii_disabled'           => '本店未开启收集联系方式，请勿向客人索要',
        'no_channel'             => '发不出确认码：短信/邮件未配置，或客人没留对应的联系方式',
        'channel_not_configured' => '发送渠道未配置，请联系管理员',
        'send_failed'            => '确认码发送失败，请稍后重试',
        'no_recipient'           => '没有可用的手机号或邮箱',
        'code_not_sent'          => '还没有发送过确认码',
        'code_wrong'             => '确认码不正确',
        'code_expired'           => '确认码已过期，请重新发送',
        'code_locked'            => '确认码连续输错多次，请重新发送一条',
        'consent_already_done'   => '该会员已完成确认',
        'server_error'           => '系统内部错误，请稍后重试',
    ];


    /**
     * 西班牙语文案。
     *
     * ★ 键必须与 MESSAGES 完全一致 —— 测试里有断言逐个比对，
     *   漏一条就红。漏翻译不会报错、只会在收银台上冒出一句中文，
     *   现场没人会来报这种「小事」，所以必须靠测试守住。
     *
     * 写给收银员看的：短句、直接说下一步该做什么，不用敬语堆砌。
     */
    private const MESSAGES_ES = [
        'unauthorized'           => 'La sesión ha caducado, vuelva a iniciar sesión',
        'forbidden'              => 'Esta cuenta no tiene permiso para esta operación',
        'invalid_credentials'    => 'Usuario o PIN incorrecto',
        'locked'                 => 'Demasiados intentos fallidos, cuenta bloqueada temporalmente',
        'method_not_allowed'     => 'Método de petición incorrecto',
        'not_found'              => 'La ruta no existe',
        'bad_request'            => 'Parámetros de la petición incorrectos',
        'pos_unavailable'        => 'El TPV no responde ahora mismo, puede usar la entrada manual',
        'order_not_found'        => 'No se ha encontrado el ticket',
        'not_dine_in'            => 'Los pedidos para llevar no acumulan puntos',
        'free_meal'              => 'Este ticket está marcado como comida gratuita, no acumula puntos',
        'redeemed'              => 'Este ticket ya se usó para canjear el 10+1: no cuenta visita ni puntos',
        'bad_invoice'            => 'Número de ticket no válido, compruebe la Factura Simplificada',
        'pin_too_short'          => 'El PIN es demasiado corto, mínimo 6 dígitos',
        'pin_unchanged'          => 'El PIN nuevo no puede ser igual al anterior',
        'zero_amount'            => 'El importe del ticket es 0, no acumula puntos',
        'exceeds_total'          => 'El importe asignado supera el total que puede puntuar',
        'exceeds_portions'       => 'Las raciones asignadas superan las del ticket',
        'negative_allocation'    => 'El importe o las raciones no pueden ser negativos',
        'duplicate_member'       => 'El mismo cliente aparece repetido, revíselo',
        'empty_allocation'       => 'Asigne importe al menos a un cliente',
        'invalid_member'         => 'Los datos del cliente están incompletos',
        'member_not_found'       => 'No se ha encontrado ese cliente',
        'already_reversed'       => 'Este apunte ya se anuló antes',
        'not_reversible'         => 'Este apunte no se puede anular',
        'reversal_window_expired'=> 'Fuera del plazo para anular por su cuenta, hace falta un encargado',
        'manual_entry_disabled'  => 'La entrada manual está desactivada',
        'exceeds_manual_limit'   => 'Supera el límite por entrada manual, hace falta un encargado',
        'invalid_amount'         => 'Importe no válido',
        'db_unavailable'         => 'La base de datos local no responde, avise al administrador',

        // ── Tarjeta física ──────────────────────────────────
        'card_malformed'         => 'Número de tarjeta incompleto, vuelva a escanear o compruebe el número',
        'card_unknown'           => 'Esta no es una tarjeta emitida por el restaurante',
        'card_void'              => 'Esta tarjeta está anulada, entregue una nueva',
        'card_taken'             => 'Esta tarjeta ya está asignada a otro cliente',
        'card_not_available'     => 'Esta tarjeta no está en stock, no se puede entregar',
        'card_expired'           => 'Tarjeta caducada: entregue una nueva (los puntos se traspasan)',
        'grace_over'             => 'Esta tarjeta superó el plazo de renovación; los puntos han caducado — hace falta un encargado',
        'card_expiring_soon'     => 'Esta tarjeta caduca pronto',
        'card_member_missing'    => 'No se encuentra el cliente asociado a la tarjeta, avise al administrador',
        'member_has_card'        => 'Este cliente ya tiene tarjeta; para cambiarla use la renovación',
        'card_required'          => 'Escanee primero la tarjeta del cliente',
        'pin_wrong'              => 'El PIN del reverso no es correcto',
        'pin_locked'             => 'Demasiados fallos con el PIN del reverso, bloqueado temporalmente',
        'pin_not_set'            => 'Esta tarjeta no tiene PIN, avise al administrador',
        'pin_required'           => 'Pida al cliente que rasque el reverso y diga el PIN',
        'card_missing'           => 'Este cliente no tiene tarjeta activa, entregue una primero',
        'reason_required'        => 'Para forzar la operación hay que indicar el motivo',
        'pii_disabled'           => 'El restaurante no recoge datos de contacto, no se los pida al cliente',
        'no_channel'             => 'No se puede enviar el código: SMS/email sin configurar, o el cliente no dejó ese dato',
        'channel_not_configured' => 'El canal de envío no está configurado, avise al administrador',
        'send_failed'            => 'No se pudo enviar el código, inténtelo de nuevo',
        'no_recipient'           => 'No hay teléfono ni email disponibles',
        'code_not_sent'          => 'Todavía no se ha enviado ningún código',
        'code_wrong'             => 'El código no es correcto',
        'code_expired'           => 'El código ha caducado, envíe uno nuevo',
        'code_locked'            => 'Demasiados fallos con el código, envíe uno nuevo',
        'consent_already_done'   => 'Este cliente ya completó la confirmación',
        'server_error'           => 'Error interno del sistema, inténtelo de nuevo',
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
            // 这一处保留 404：它确实是「这个 URL 上没有东西」。
            // 业务层的「没找到」用 Api::NOT_FOUND(422)，理由见那里的注释。
            self::fail('not_found', 404);
        }

        try {
            $this->routes[$key]();
        } catch (\Throwable $e) {
            /**
             * ★ 给收银员一个能念出来的错误代码，形如 E202-7F3A21。
             *
             *   前半段 E2xx  是【分类】：一眼看出坏在哪一层（见 classify()）
             *   后半段 7F3A21 是【事件号】：随机 6 位，同时写进日志。
             *
             * 为什么两段都要：只有分类码，能知道「是 POS 侧」却不知道具体哪一句；
             * 只有事件号，则必须能翻日志才有意义。两段一起，收银员拍张照发过来，
             * 排查的人立刻知道方向，再按事件号在日志里精确捞到那一次的完整异常。
             *
             * 客户端只拿到这个代码 —— 堆栈、SQL、连接串一律不外传。
             */
            $code     = self::classify($e);
            $incident = strtoupper(bin2hex(random_bytes(3)));
            $ref      = $code . '-' . $incident;

            error_log(sprintf('[api] %s %s | %s: %s @ %s:%d',
                $ref, $key, get_class($e), $e->getMessage(), $e->getFile(), $e->getLine()));

            // 本地库不可达单独给 503，前端才能提示得准确（其余一律 500）
            $isDb = $e instanceof \PDOException;
            self::fail($isDb ? 'db_unavailable' : 'server_error', $isDb ? 503 : 500, [], $ref);
        }
    }

    /**
     * 把异常归到一个分类码。分类的粒度按【该去查哪里】来定，不是按异常类名。
     *
     *   E1xx 本地库    E2xx POS 主库    E3xx 代码/参数
     *
     * 实测教训：早先只有「本地数据库暂时不可用」与「系统内部错误」两句话，
     * 现场拍照发过来也判断不出方向 —— 缺表、POS 掉线、SQL 引用了不存在的列，
     * 三种完全不同的故障在界面上长得一模一样。
     */
    public static function classify(\Throwable $e): string
    {
        if ($e instanceof \PDOException) {
            $state = (string)($e->errorInfo[0] ?? '');
            if ($state === '42S02') { return 'E102'; }   // 表不存在 → 迁移没跑
            if ($state === '42S22') { return 'E103'; }   // 列不存在 → 迁移漏跑一个
            if ($state === '23000') { return 'E104'; }   // 唯一键/外键冲突

            /**
             * ★ 连接类故障【不能只看 SQLSTATE】。
             *
             *   实测：端口不通、主机不可达、库不存在、口令错 —— 四种需要完全
             *   不同修法的故障，SQLSTATE 全是 HY000，只有驱动错误码能区分。
             *   都归成一个码等于没分类，现场还是得挨个试。
             *
             *   驱动错误码在 errorInfo[1]；连接阶段失败时 errorInfo 可能为空，
             *   那就回落到 getCode()（PDO 连接异常会把它设成驱动码）。
             */
            $drv = (int)($e->errorInfo[1] ?? 0);
            // errorInfo 在连接阶段可能为空；驱动码在消息里一定有，形如 [1045]
            if ($drv === 0 && preg_match('/\[(\d{4})\]/', $e->getMessage(), $m)) {
                $drv = (int)$m[1];
            }
            if ($drv === 0) {
                $drv = (int)$e->getCode();
            }
            if (str_contains($e->getMessage(), 'could not find driver')) {
                return 'E106';   // pdo_mysql 扩展没装/没开
            }
            return match ($drv) {
                2002, 2003, 2006 => 'E101',   // 连不上：服务没起 / 端口错 / 主机不可达
                1045             => 'E105',   // 口令错，或该来源主机没被授权
                1044, 1049       => 'E107',   // 库不存在，或该用户对这个库没权限
                1040, 1203       => 'E108',   // 连接数打满
                default          => str_starts_with($state, '08') ? 'E101' : 'E109',
            };
        }
        // POS 侧：PosUnavailable 继承 RuntimeException，必须先判
        if ($e instanceof \Vip\PosUnavailable) {
            return 'E201';   // 连不上 / 查询超时
        }
        if ($e instanceof \LogicException) {
            return 'E203';   // PosDb 护栏：非 SELECT / 带分号 / 没有 LIMIT
        }
        if ($e instanceof \RuntimeException) {
            // PosDb 的 prepare 失败走这里 —— 多半是 SQL 引用了 POS 上没有的列
            return str_contains($e->getMessage(), 'POS') ? 'E202' : 'E209';
        }
        if ($e instanceof \TypeError || $e instanceof \ValueError) {
            return 'E301';   // 参数类型/取值不对，纯代码问题
        }
        return 'E309';
    }

    /**
     * 启动期（路由注册阶段）的兜底 —— 那时还没进 dispatch。
     * 分类码前缀改成 B，一眼看出是「连路由都没挂上」而不是某个接口出错。
     */
    public static function bootFail(\Throwable $e, string $where = 'api'): never
    {
        $ref = 'B' . substr(self::classify($e), 1) . '-' . strtoupper(bin2hex(random_bytes(3)));
        error_log(sprintf('[%s:boot] %s | %s: %s @ %s:%d',
            $where, $ref, get_class($e), $e->getMessage(), $e->getFile(), $e->getLine()));
        $isDb = $e instanceof \PDOException;
        self::fail($isDb ? 'db_unavailable' : 'server_error', $isDb ? 503 : 500, [], $ref);
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

    /** 按当前语言取文案；缺翻译时回落中文，再缺就把错误码原样吐出去 */
    public static function message(string $code): string
    {
        if (self::$lang === \Vip\Lang::ES) {
            return self::MESSAGES_ES[$code] ?? self::MESSAGES[$code] ?? $code;
        }
        return self::MESSAGES[$code] ?? $code;
    }

    /** 测试用：拿到两张表好逐键比对，漏翻译要能被发现 */
    public static function messageKeys(): array
    {
        return ['zh' => array_keys(self::MESSAGES), 'es' => array_keys(self::MESSAGES_ES)];
    }

    public static function fail(string $code, int $status = 400, array $detail = [], string $ref = ''): never
    {
        $msg = self::message($code);
        if ($ref !== '') {
            // 代码直接拼进提示语 —— 收银员拍照就能把它带出来，不用再教怎么找
            $msg .= "（错误代码 {$ref}）";
        }
        $p = ['ok' => false, 'error' => $code, 'message' => $msg];
        if ($ref !== '') {
            $p['ref'] = $ref;
        }
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
