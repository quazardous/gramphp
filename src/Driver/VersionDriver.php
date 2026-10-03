<?php

declare(strict_types=1);

namespace Quazardous\GramPHP\Driver;

/**
 * NEEDED AS SOON AS THE JOURNAL IS BUILT ON A `Graph`: every write pins its
 * subjects to the graph they started on, and a migration moves them to
 * another.
 */
interface VersionDriver
{
    /**
     * Record `version` for the subjects that have none yet — never overwrite
     * one. Return the count newly pinned.
     *
     * @param list<int|string> $subjects
     */
    public function pin(array $subjects, string $version): int;

    /**
     * The versions of the subjects pinned to one.
     *
     * @param list<int|string> $subjects
     *
     * @return array<string, string> Subject::key() => version
     */
    public function versions(array $subjects): array;

    /**
     * ATOMICALLY for one subject: archive with reason `migrate` and delete the
     * rows of `drop`, rename the rows of `rename` (old => new; the journal
     * guarantees no two land on one name) — the arrivals waiting in those
     * nodes deleted and renamed alike — pin the subject to `version`,
     * overwriting, and raise its revision.
     *
     * @param array<string, string> $rename
     * @param list<string>          $drop
     */
    public function rewrite(int|string $subject, array $rename, array $drop, string $version, string $now): void;
}
