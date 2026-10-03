<?php

declare(strict_types=1);

namespace Quazardous\GramPHP\Tests\Contract;

use Quazardous\GramPHP\Dag;
use Quazardous\GramPHP\Graph;
use Quazardous\GramPHP\NodeJournal;
use Quazardous\GramPHP\Status;

/**
 * What a driver's test provides to run the shared contract.
 */
interface Harness
{
    /**
     * A journal on EMPTY storage, whose subjects are `'string'` or `'int'`
     * (the storage's subject column typed accordingly).
     *
     * @param callable(): (string|\DateTimeInterface)                    $clock
     * @param 'int'|'string'                                             $subjectType
     * @param array<string, callable(list<string>, ?string): iterable<string>> $mergers
     */
    public function journal(Graph|Dag $dag, callable $clock, string $subjectType = 'string', array $mergers = []): NodeJournal;

    /**
     * The driver's candidates, in this order.
     *
     * @param list<int|string> $subjects
     */
    public function candidates(array $subjects): mixed;

    /**
     * The driver's candidates carrying a grouping key, in this order.
     *
     * @param list<array{0: int|string, 1: ?string}> $pairs [subject, key]
     */
    public function keyed(array $pairs): mixed;

    /**
     * Write rows directly, bypassing the API — progresses the API could not reach included.
     *
     * @param array<string, Status> $progress
     */
    public function seed(NodeJournal $journal, int|string $subject, array $progress): void;

    public function parentsConcluded(NodeJournal $journal, string $name, int|string $subject): bool;

    /**
     * SHARED storage for the concurrency tests, or null when the driver lives
     * in one process (its concurrency tests are then skipped).
     *
     * @param callable(): (string|\DateTimeInterface) $clock
     */
    public function store(Graph|Dag $dag, callable $clock): ?Store;

    /** Drop what the harness created. */
    public function close(): void;
}
