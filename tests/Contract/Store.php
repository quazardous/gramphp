<?php

declare(strict_types=1);

namespace Quazardous\GramPHP\Tests\Contract;

/**
 * Shared storage for the concurrency tests. A session may be opened in a
 * forked process: it opens its own connection.
 */
interface Store
{
    public function session(): Session;

    /**
     * True when the storage reports a transient conflict — a deadlock — that
     * an application answers by retrying its transaction.
     */
    public function retryable(\Throwable $e): bool;

    public function close(): void;
}
