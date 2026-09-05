<?php
declare(strict_types=1);

namespace Vip;

/**
 * POS 主库连接 —— ★ 严格只读 ★
 *
 * MySQL 5.5.47。这套连接层把 docs/02-只读接入规范.md 的铁律固化下来：
 *
 *  1. 只允许 SELECT。本类不提供任何写入方法，且对 SQL 做前缀校验。
 *  2. 必须带 LIMIT，上限 100。
 *  3. 客户端超时兜底 —— MySQL 5.5 没有 MAX_EXECUTION_TIME
 *     （5.7.4 才引入），服务端无法掐断慢查询。
 *     用 mysqli 而非 PDO：PDO::ATTR_TIMEOUT 只管连接不管查询，
 *     只有 mysqli 的 MYSQLI_OPT_READ_TIMEOUT 能限制读取。
 *  4. 用后立即关闭，不做长连接池，避免占用 POS 的连接数。
 *  5. 超时不重试，记录告警留到下个周期。
 */
final class PosDb
{
    public const MAX_LIMIT = 100;

    private ?\mysqli $conn = null;
    /**
     * mysqli 里属于「主库连不上 / 断了 / 超时」的错误码。
     *
     * 2002 连不上（socket/端口）、2003 主机不可达、2006 服务器跑掉了、
     * 2013 查询中途断线、1040 连接数打满、1045 口令错、1044 库没权限。
     * 口令与权限也算在内：那同样是【这台机器现在用不了】，
     * 收银员该做的事一样（走手工录入），而不是看一个内部错误码。
     */
    private const CONN_ERRNOS = [1040, 1044, 1045, 2002, 2003, 2006, 2013];

    private array $stats = ['queries' => 0, 'slow' => 0, 'ms_total' => 0.0];

    public function __construct(private array $cfg)
    {
    }

    private function conn(): \mysqli
    {
        if ($this->conn instanceof \mysqli) {
            return $this->conn;
        }
        $m = mysqli_init();
        if ($m === false) {
            throw new \RuntimeException('mysqli_init 失败');
        }
        $m->options(MYSQLI_OPT_CONNECT_TIMEOUT, (int)($this->cfg['connect_timeout'] ?? 3));
        // ★ 关键：查询读取超时。MySQL 5.5 服务端无超时机制，全靠这里兜底。
        $m->options(MYSQLI_OPT_READ_TIMEOUT, (int)($this->cfg['read_timeout'] ?? 5));

        /**
         * ── 🔴 mysqli 现在是【抛异常】的，不是返回 false ─────────────
         *
         * PHP 8.1 起 mysqli 的默认报错模式是
         * MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT —— 连接失败、查询超时
         * 一律抛 mysqli_sql_exception。而 @ 压得住 warning、压不住异常。
         *
         * 于是原来那句「返回 false 就 throw new PosUnavailable」是【死代码】，
         * 一次都执行不到。后果是一整条链：
         *
         *   POS 断线
         *    → locate() 的 catch (PosUnavailable) 抓不到
         *    → 冒到 Api 顶层 → HTTP 500 server_error E209-xxxx
         *    → Pad 只在 error === 'pos_unavailable' 时才显示手工录入入口
         *    → 【收银员根本看不到那个按钮】
         *
         * 也就是说 POS 一断线，收银台不是「降级」，是整个不能用 ——
         * 而手工录入这条降级通道正是为这一刻准备的（docs/03 §10）。
         * 夜间两条 cron 同样中招：catch (PosUnavailable) 落空，
         * pos_unreachable 告警不会挂、水位线不会 touch，整轮异常退出。
         *
         * ★ 修法选【接住异常】而不是 mysqli_report(MYSQLI_REPORT_OFF)：
         *   关掉报错模式等于把防线钉死在「当前 PHP 的默认值」上，
         *   下一次默认值再变，这条防线又会悄悄失效一次。
         *   接异常则是无论默认值怎么变都成立的写法。
         *
         * ★ 连不上与查不动【都要归到 PosUnavailable】：上层靠它区分
         *   「POS 的事，走降级」和「我们自己的 bug，报错误码」。
         */
        try {
            $ok = @$m->real_connect(
                $this->cfg['host'],
                $this->cfg['user'],
                $this->cfg['password'],
                $this->cfg['database'],
                (int)$this->cfg['port']
            );
            if (!$ok) {
                // 报错模式被外部改成 OFF 时仍然走得到这里
                throw new PosUnavailable('POS 主库连接失败: ' . $m->connect_error);
            }
            // 主库是 3 字节 utf8，不是 utf8mb4
            $m->set_charset($this->cfg['charset'] ?? 'utf8');
        } catch (\mysqli_sql_exception $e) {
            throw new PosUnavailable('POS 主库连接失败: ' . $e->getMessage(), 0, $e);
        }
        $this->conn = $m;
        return $m;
    }

    /**
     * 执行只读查询。
     *
     * @param string $sql   必须以 SELECT 开头且含 LIMIT
     * @param array  $params 位置参数
     * @param string $types  mysqli 类型串，如 'sid'；留空则全部按字符串绑定
     * @return array<int,array<string,mixed>>
     */
    public function select(string $sql, array $params = [], string $types = ''): array
    {
        $this->assertReadOnly($sql);

        $m  = $this->conn();
        $t0 = microtime(true);

        /**
         * ★ 同 conn()：execute 的读超时在 PHP 8.1+ 是【抛异常】的，
         *   原来那句 `if (!$st->execute())` 里的 throw 同样是死代码。
         *
         * ★ prepare 失败要和「主库不可用」分开：SQL 里引用了主库上
         *   没有的列，那是我们自己的 bug（E202），不该让收银员看到
         *   「POS 暂时连不上，请手工录入」——方向指反了比不给码更糟。
         *   判据用 mysqli 的错误码：1044/1045/2002/2003/2006/2013
         *   这一类是连接/权限/断线，其余归我们自己。
         */
        try {
            $st = $m->prepare($sql);
            if ($st === false) {
                throw new \RuntimeException('POS 查询 prepare 失败: ' . $m->error);
            }
            if ($params) {
                $st->bind_param($types !== '' ? $types : str_repeat('s', count($params)), ...$params);
            }
            if (!$st->execute()) {
                $err = $st->error;
                $st->close();
                // 读超时会在这里抛出，视为主库暂不可用 → 走降级
                throw new PosUnavailable('POS 查询失败/超时: ' . $err);
            }
            $res  = $st->get_result();
            $rows = $res === false ? [] : $res->fetch_all(MYSQLI_ASSOC);
            $st->close();
        } catch (\mysqli_sql_exception $e) {
            if (in_array((int)$e->getCode(), self::CONN_ERRNOS, true)) {
                throw new PosUnavailable('POS 查询失败/超时: ' . $e->getMessage(), 0, $e);
            }
            // 列不存在、语法错 —— 这是我们自己的 SQL 写坏了，别冒充 POS 断线
            throw new \RuntimeException('POS 查询失败: ' . $e->getMessage(), 0, $e);
        }

        $ms = (microtime(true) - $t0) * 1000;
        $this->stats['queries']++;
        $this->stats['ms_total'] += $ms;
        if ($ms > 2000) {                       // 监控阈值：单次 > 2 秒
            $this->stats['slow']++;
        }
        return $rows;
    }

    /**
     * 只读校验 —— 防止任何写入语句流到主库。
     * 这是最后一道防线，业务代码本就不该构造写语句。
     */
    private function assertReadOnly(string $sql): void
    {
        $s = ltrim($sql);
        if (stripos($s, 'SELECT') !== 0) {
            throw new \LogicException('POS 主库只读：仅允许 SELECT，收到: ' . substr($s, 0, 40));
        }
        // 分号会打开多语句执行的口子
        if (str_contains(rtrim($s, "; \n\r\t"), ';')) {
            throw new \LogicException('POS 查询不得包含分号');
        }
        if (!preg_match('/\bLIMIT\s+\d+/i', $s)) {
            throw new \LogicException('POS 查询必须带 LIMIT（铁律 2）');
        }
        if (preg_match('/\bLIMIT\s+(\d+)/i', $s, $mm) && (int)$mm[1] > self::MAX_LIMIT) {
            throw new \LogicException('POS 查询 LIMIT 超过上限 ' . self::MAX_LIMIT);
        }
    }

    /** 主库当前时间 —— 时间基准必须取自主库，不用 PHP 服务器时间 */
    public function now(): string
    {
        $r = $this->select('SELECT NOW() AS n LIMIT 1');
        return (string)($r[0]['n'] ?? date('Y-m-d H:i:s'));
    }

    public function stats(): array
    {
        return $this->stats;
    }

    public function close(): void
    {
        if ($this->conn instanceof \mysqli) {
            @$this->conn->close();
            $this->conn = null;
        }
    }

    public function __destruct()
    {
        $this->close();
    }
}

/** 主库暂不可用（连接失败 / 查询超时）→ 上层应走降级，不得阻塞收银流程 */
final class PosUnavailable extends \RuntimeException
{
}
