<?php

declare(strict_types=1);

namespace Quazardous\GramPHP\Driver\Mariadb;

use Doctrine\DBAL\Connection;

/**
 * The MariaDB driver over a Doctrine DBAL connection (`doctrine/dbal` ^4) —
 * the one a Symfony application already has, so that node rows and the
 * application's own writes go in one transaction:
 *
 *     $journal = new NodeJournal(new MariadbDriver(new DbalSql($connection)), $dag);
 *     $connection->transactional(fn() => $journal->claim('ship', 50, $candidates));
 *
 * The connection must run READ COMMITTED (see `MariadbDriver`).
 */
final class DbalSql implements Sql
{
    public function __construct(private readonly Connection $connection) {}

    public function select(string $sql, array $params = []): array
    {
        return $this->connection->executeQuery($sql, $params)->fetchAllNumeric();
    }

    public function selectNamed(string $sql, array $params = []): array
    {
        $rows = $this->connection->executeQuery($sql, $params)->fetchAllAssociative();
        if ([] === $rows) {
            return [[], []];
        }

        return [array_map('strval', array_keys($rows[0])), array_map(array_values(...), $rows)];
    }

    public function execute(string $sql, array $params = []): int
    {
        return (int) $this->connection->executeStatement($sql, $params);
    }

    public function inTransaction(): bool
    {
        return $this->connection->isTransactionActive();
    }
}
