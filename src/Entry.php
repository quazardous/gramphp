<?php

declare(strict_types=1);

namespace Quazardous\GramPHP;

/**
 * A candidate as a driver read it: its revision, and its rows on the nodes
 * the decision needs — `[node => status]`, absent nodes left out.
 */
final readonly class Entry
{
    /**
     * @param array<string, string> $rows     node => status
     * @param array<string, string> $due      node => due time, for the `scheduled` rows
     * @param array<string, string> $finished node => finished_at, for the concluded rows
     */
    public function __construct(
        public int|string $subject,
        public int $revision,
        public array $rows,
        public array $due = [],
        public array $finished = [],
        /** The subject's policy, null when it has none. */
        public ?string $policy = null,
        /** The graph the subject is pinned to, null before its first write. */
        public ?string $version = null,
        /** What the candidates said to group this subject by; compared, never read. */
        public ?string $key = null,
    ) {}
}
