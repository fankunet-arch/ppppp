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
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $cfg['host'], (int)$cfg['port'], $cfg['database'], $cfg['charset'] ?? 'utf8mb4'
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

    /** 事务包裹；回调抛异常则回滚 */
    public function transaction(callable $fn): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $r = $fn($this);
            $this->pdo->commit();
            return $r;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function now(): string
    {
        return date('Y-m-d H:i:s');
    }
}
