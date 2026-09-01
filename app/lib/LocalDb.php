<?php
declare(strict_types=1);

namespace Vip;

/**
 * 本地会员库连接（唯一可写的库）。
 *
 * ★ 必须同时兼容 MySQL 与 MariaDB —— 约定见 db/README.md。
 *   本类不做任何版本分支；若将来必须分支，请在 db/README.md 登记原因。
 */
final class LocalDb
{
    private \PDO $pdo;
    private ?string $flavor = null;

    public function __construct(private array $cfg)
    {
        $charset   = (string)($cfg['charset']   ?? 'utf8mb4');
        $collation = (string)($cfg['collation'] ?? 'utf8mb4_unicode_ci');
        // 只允许标识符字符，防止配置值被拼进 SET NAMES
        foreach (['charset' => $charset, 'collation' => $collation] as $k => $v) {
            if (!preg_match('/^[A-Za-z0-9_]+$/', $v)) {
                throw new \InvalidArgumentException("local_db.{$k} 含非法字符：{$v}");
            }
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $cfg['host'], (int)$cfg['port'], $cfg['database'], $charset
        );
        $this->pdo = new \PDO($dsn, $cfg['user'], $cfg['password'], [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES   => false,
            \PDO::ATTR_STRINGIFY_FETCHES  => false,
        ]);

        // 统一 SQL 模式，抹平 MySQL / MariaDB 的默认差异（db/README.md §2.3）
        //   STRICT_ALL_TABLES      金额/数量溢出或截断时报错，不静默截断
        //   NO_ENGINE_SUBSTITUTION 请求 InnoDB 时不被静默换引擎
        //   不含 ONLY_FULL_GROUP_BY：MySQL 5.7+ 默认开、MariaDB 默认关，显式统一
        $this->pdo->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION'");

        // ★ 钉死连接排序规则 —— 不可省略。
        // DSN 的 charset 只设字符集，排序规则会回落到服务器默认，而三家默认各不相同：
        //   MariaDB / MySQL 5.7 → utf8mb4_general_ci，MySQL 8 → utf8mb4_0900_ai_ci
        // 建表用的是 utf8mb4_unicode_ci，一个都对不上。
        // 【用户变量的强制性等级是 IMPLICIT，与列相同】，故 `WHERE col = @var`
        // 两侧同为 IMPLICIT 却排序规则不同 → 报 1267 非法混用而直接失败。
        // （绑定参数是 COERCIBLE，不受影响，所以这个坑只在写 @变量 的 SQL 脚本里显形。）
        $this->pdo->exec("SET NAMES {$charset} COLLATE {$collation}");
    }

    public function pdo(): \PDO
    {
        return $this->pdo;
    }

    /** 'mysql' | 'mariadb' —— 仅供确需分支处使用，目前无调用方 */
    public function serverFlavor(): string
    {
        if ($this->flavor === null) {
            $v = (string)$this->pdo->query('SELECT VERSION()')->fetchColumn();
            $this->flavor = stripos($v, 'mariadb') !== false ? 'mariadb' : 'mysql';
        }
        return $this->flavor;
    }

    /** @return array<int,array<string,mixed>> */
    public function all(string $sql, array $params = []): array
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    public function one(string $sql, array $params = []): ?array
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        $r = $st->fetch();
        return $r === false ? null : $r;
    }

    public function value(string $sql, array $params = []): mixed
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        $v = $st->fetchColumn();
        return $v === false ? null : $v;
    }

    public function exec(string $sql, array $params = []): int
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        return $st->rowCount();
    }

    public function lastInsertId(): int
    {
        return (int)$this->pdo->lastInsertId();
    }

    /**
     * 死锁 / 锁等待超时的重试次数。
     *
     * 2 次足够：死锁是两笔事务【碰巧】交叉，重放一次几乎必然错开。
     * 给得再多也只是在真正拥塞时把收银台拖得更久。
     */
    private const DEADLOCK_RETRIES = 2;

    /** MySQL：1213 = 死锁被选为牺牲者，1205 = 等锁超时。两者都是整笔已回滚 */
    private const RETRYABLE = [1213, 1205];

    /**
     * 事务。死锁与锁等待超时会自动重放。
     *
     * ── 🔴 为什么必须重试 ─────────────────────────────
     *
     * 死锁不是故障，是 MySQL 在两笔事务互相等锁时【主动挑一个牺牲者回滚】。
     * 被挑中的那一笔整个没有发生 —— 一分钱没扣、一条流水没写 ——
     * 所以原样重放一次在业务上是完全安全的。
     *
     * 不重试的后果不是数据错，是【收银员当着客人的面记不进去】，
     * 而且原来那句提示是「本地数据库暂时不可用，请联系管理员」——
     * 库好得很，人被指到了完全没有问题的地方。
     *
     * ★ 重试是第二道，不是第一道。第一道永远是【固定加锁顺序】
     *   （PointsService::grantOne 的 sort($lockIds)、grantMerged 的 sort($serials)）。
     *   靠重试去掩盖乱序加锁，只会把 50% 的失败变成 25%。
     *
     * ★ 只有最外层才重试。嵌套调用时 PDO 根本开不了第二个事务，
     *   这里也就不会走到重放那一支。
     *
     * ⚠️ 闭包必须是【可重放的】—— 只做库内的事。
     *    库外的副作用（发短信、写文件、调外部接口）不能放进来，
     *    重放一次就会做两遍。本仓库的事务闭包目前全部只碰本地库。
     */
    public function transaction(callable $fn): mixed
    {
        for ($attempt = 0; ; $attempt++) {
            $this->pdo->beginTransaction();
            try {
                $r = $fn($this);
                $this->pdo->commit();
                return $r;
            } catch (\Throwable $e) {
                // rollBack 本身也可能抛（连接已断），那时原异常更重要
                try { $this->pdo->rollBack(); } catch (\Throwable $ignored) { }

                if ($attempt >= self::DEADLOCK_RETRIES || !self::isRetryable($e)) {
                    throw $e;
                }
                // 退让一点点再重放，避免两笔事务再次同时冲上去
                usleep(random_int(5000, 25000) * ($attempt + 1));
            }
        }
    }

    /** 这个异常是不是「重放一次就好」的那一类 */
    private static function isRetryable(\Throwable $e): bool
    {
        if (!$e instanceof \PDOException) {
            return false;
        }
        return in_array((int)($e->errorInfo[1] ?? 0), self::RETRYABLE, true);
    }

    public function now(): string
    {
        return date('Y-m-d H:i:s');
    }
}
