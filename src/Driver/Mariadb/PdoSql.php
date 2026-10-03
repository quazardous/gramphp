<?php

declare(strict_types=1);

namespace Quazardous\GramPHP\Driver\Mariadb;

/** The MariaDB driver over a PDO connection (`pdo_mysql`). */
final class PdoSql implements Sql
{
    public function __construct(private readonly \PDO $pdo)
    {
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    }

    public function select(string $sql, array $params = []): array
    {
        return self::rows($this->run($sql, $params));
    }

    public function selectNamed(string $sql, array $params = []): array
    {
        $statement = $this->run($sql, $params);
        $names = [];
        for ($i = 0; $i < $statement->columnCount(); ++$i) {
            $meta = $statement->getColumnMeta($i);
            $names[] = false === $meta ? '' : (string) $meta['name'];
        }

        return [$names, self::rows($statement)];
    }

    public function execute(string $sql, array $params = []): int
    {
        return $this->run($sql, $params)->rowCount();
    }

    public function begin(): void
    {
        $this->pdo->beginTransaction();
    }

    public function commit(): void
    {
        $this->pdo->commit();
    }

    public function rollBack(): void
    {
        $this->pdo->rollBack();
    }

    public function inTransaction(): bool
    {
        return $this->pdo->inTransaction();
    }

    /** @param list<mixed> $params */
    private function run(string $sql, array $params): \PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        return $statement;
    }

    /** @return list<list<mixed>> */
    private static function rows(\PDOStatement $statement): array
    {
        $out = [];
        foreach ($statement->fetchAll(\PDO::FETCH_NUM) as $row) {
            $out[] = \is_array($row) ? array_values($row) : [];
        }

        return $out;
    }
}
