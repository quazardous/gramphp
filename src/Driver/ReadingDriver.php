<?php

declare(strict_types=1);

namespace Quazardous\GramPHP\Driver;

/**
 * READS FOR THE APPLICATION, never on a write path: `counts`, `stages`,
 * `snapshot` and `parentsConcluded` need them, nothing else does. The
 * journal checks for this capability when one of those is called.
 */
interface ReadingDriver
{
    /**
     * `[status => count]` for one node.
     *
     * @return array<string, int>
     */
    public function statusCounts(string $name): array;

    /**
     * `[subject => [[node, ended, seconds, status], …]]` — subjects as text,
     * skipped, omitted and scheduled rows excluded, ordered by (ended, node);
     * a running row ends `at`.
     *
     * @param list<int|string> $subjects
     *
     * @return array<string, list<array{0: string, 1: string, 2: float, 3: string}>>
     */
    public function stages(array $subjects, string $at): array;

    /**
     * "Every parent has a satisfying row", in the driver's terms: a boolean
     * for the memory driver, an SQL condition for a database one.
     *
     * @param list<string> $parents
     */
    public function parentsConcluded(array $parents, int|string $subject): mixed;
}
