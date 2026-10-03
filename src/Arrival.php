<?php

declare(strict_types=1);

namespace Quazardous\GramPHP;

/**
 * A subject waiting in a lane: what it brings (`ref`, opaque — what came
 * back, nothing to do with a graph's version), its `place` in the lane, when
 * it FIRST arrived, and whether it is urgent.
 *
 * `refs` holds EVERY ref still waiting, for a lane that keeps them — text the
 * journal encodes and decodes, stored by the driver as it is.
 * `NodeJournal::refs()` gives it back as a list.
 */
final readonly class Arrival
{
    public function __construct(
        public ?string $ref,
        public string $place,
        public string $arrivedAt,
        public bool $urgent = false,
        public ?string $refs = null,
    ) {}
}
