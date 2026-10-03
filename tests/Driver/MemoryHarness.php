<?php

declare(strict_types=1);

namespace Quazardous\GramPHP\Tests\Driver;

use Quazardous\GramPHP\Dag;
use Quazardous\GramPHP\Driver\Memory\MemoryDriver;
use Quazardous\GramPHP\Driver\Memory\Row;
use Quazardous\GramPHP\Keyed;
use Quazardous\GramPHP\NodeJournal;
use Quazardous\GramPHP\Status;
use Quazardous\GramPHP\Tests\Contract\Clock;
use Quazardous\GramPHP\Tests\Contract\Harness;
use Quazardous\GramPHP\Tests\Contract\Store;

final class MemoryHarness implements Harness
{
    public function journal(Dag $dag, callable $clock, string $subjectType = 'string', array $mergers = []): NodeJournal
    {
        return new NodeJournal(new MemoryDriver(), $dag, $clock, mergers: $mergers);
    }

    public function candidates(array $subjects): mixed
    {
        return $subjects;
    }

    public function keyed(array $pairs): mixed
    {
        return array_map(static fn(array $pair): Keyed => new Keyed($pair[0], $pair[1]), $pairs);
    }

    public function seed(NodeJournal $journal, int|string $subject, array $progress): void
    {
        $driver = $journal->driver;
        \assert($driver instanceof MemoryDriver);
        foreach ($progress as $name => $status) {
            $driver->seed($subject, (string) $name, new Row($status->value, Clock::T0, Status::Running === $status ? null : Clock::T0));
        }
    }

    public function parentsConcluded(NodeJournal $journal, string $name, int|string $subject): bool
    {
        return true === $journal->parentsConcluded($name, $subject);
    }

    public function store(Dag $dag, callable $clock): ?Store
    {
        return null;            // one process, one storage
    }

    public function close(): void {}
}
