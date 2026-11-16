<?php
declare(strict_types=1);

namespace Source\Services\Talk\Support;

use PDO;

/**
 * Wrapper fino sobre PDO. No ERP pode ser trocado pela camada MovesCode,
 * desde que mantenha estes métodos.
 */
final class Db
{
    public function __construct(private PDO $pdo)
    {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    }

    public static function connect(string $dsn, string $user, string $pass): self
    {
        return new self(new PDO($dsn, $user, $pass, [PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"]));
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    /** @param array<string|int, mixed> $params */
    public function run(string $sql, array $params = []): \PDOStatement
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        return $st;
    }

    /** @return array<string, mixed>|null */
    public function one(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /** @return list<array<string, mixed>> */
    public function all(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll();
    }

    public function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(',', array_map(fn ($c) => "`$c`", $cols)),
            implode(',', array_map(fn ($c) => ":$c", $cols))
        );
        $this->run($sql, $data);
        return (int) $this->pdo->lastInsertId();
    }

    public function transaction(callable $fn): mixed
    {
        if ($this->pdo->inTransaction()) {
            return $fn($this);
        }
        $this->pdo->beginTransaction();
        try {
            $result = $fn($this);
            $this->pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
}
