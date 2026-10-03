<?php

declare(strict_types=1);

namespace Quazardous\GramPHP\Tests\Contract;

use Quazardous\GramPHP\NodeJournal;

/** One unit of work on shared storage: a transaction, for a database. */
interface Session
{
    public function journal(): NodeJournal;

    /** @param list<int|string> $subjects */
    public function candidates(array $subjects): mixed;

    public function commit(): void;

    public function rollback(): void;
}
