<?php

declare(strict_types=1);

namespace Quazardous\GramPHP\Driver\Mariadb;

/**
 * Candidates as SQL: the first column is the subject, the `ORDER BY` is the
 * priority; a column named `grampy_key` is the grouping key. Positional `?`
 * placeholders. The query runs on the journal's connection, in the caller's
 * transaction — "eligible" is the application's sentence, read at the same
 * moment as the node rows.
 */
final readonly class Query
{
    /** @param list<mixed> $params */
    public function __construct(
        public string $sql,
        public array $params = [],
    ) {}
}
