<?php

declare(strict_types=1);

namespace Quazardous\GramPHP;

/**
 * A CANDIDATE CARRYING ITS GROUPING KEY, for a node that groups, in a plain
 * iterable of candidates. In SQL, the key is the column named `grampy_key`.
 * A bare array is never read as one.
 */
final readonly class Keyed
{
    public function __construct(
        public int|string $subject,
        public ?string $key,
    ) {}
}
