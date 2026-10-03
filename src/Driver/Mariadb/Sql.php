<?php

declare(strict_types=1);

namespace Quazardous\GramPHP\Driver\Mariadb;

/**
 * WHAT THE MARIADB DRIVER NEEDS OF A CONNECTION: run a statement with
 * positional `?` parameters, read its rows, tell how many rows it changed, and
 * say whether a transaction is open. Nothing else — the driver never begins,
 * commits or rolls back: the application does, around each journal call.
 *
 * Two implementations ship: `PdoSql` (a PDO connection) and `DbalSql` (a
 * Doctrine DBAL connection, to share the one a Symfony application already
 * has). The SQL is the same: one dialect, MariaDB / MySQL 8.
 */
interface Sql
{
    /**
     * The rows of a query, each a list of its columns.
     *
     * @param list<mixed> $params
     *
     * @return list<list<mixed>>
     */
    public function select(string $sql, array $params = []): array;

    /**
     * The rows of a query and the names of its columns — for candidates, whose
     * `grampy_key` column is found by name.
     *
     * @param list<mixed> $params
     *
     * @return array{0: list<string>, 1: list<list<mixed>>}
     */
    public function selectNamed(string $sql, array $params = []): array;

    /**
     * Run a statement; return how many rows it changed — MySQL's affected rows:
     * an `INSERT IGNORE` that inserted nothing, or an `UPDATE` that changed
     * nothing, count 0.
     *
     * @param list<mixed> $params
     */
    public function execute(string $sql, array $params = []): int;

    /** True when a transaction is open on the connection. */
    public function inTransaction(): bool;
}
