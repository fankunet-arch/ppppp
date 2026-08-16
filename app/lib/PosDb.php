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

        $ok = @$m->real_connect(
            $this->cfg['host'],
            $this->cfg['user'],
            $this->cfg['password'],
            $this->cfg['database'],
            (int)$this->cfg['port']
        );
        if (!$ok) {
            throw new PosUnavailable('POS 主库连接失败: ' . $m->connect_error);
        }
        // 主库是 3 字节 utf8，不是 utf8mb4
        $m->set_charset($this->cfg['charset'] ?? 'utf8');
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
