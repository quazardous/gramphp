<?php

declare(strict_types=1);

namespace Quazardous\GramPHP\Driver;

use Quazardous\GramPHP\Arrival;

/**
 * NEEDED BY A NODE WITH A `lane`. The journal says, when it is built, that a
 * driver without it cannot run the graph (`MissingCapability`).
 */
interface LaneDriver
{
    /**
     * ATOMICALLY per subject, the arrival of `name`: when none waits, store
     * one — `ref`, place and first arrival at `now`, `urgent`; when one waits,
     * merge — `ref` replaced when `merge` is `last` or `set`, place moved to
     * `now` when `position` is `last`, `urgent` kept once set; `set` replaces
     * `refs` as given.
     *
     * @param list<int|string> $subjects
     *
     * @return array<string, string> Subject::key() => `queued` | `merged`
     */
    public function arrive(string $name, array $subjects, ?string $ref, string $now, string $merge, string $position, bool $urgent, ?string $refs = null): array;

    /**
     * The arrivals waiting in `name`, for those of these subjects that have one.
     *
     * @param list<int|string> $subjects
     *
     * @return array<string, Arrival> Subject::key() => arrival
     */
    public function arrivals(array $subjects, string $name): array;

    /**
     * ATOMICALLY, for each `[subject, revision]` whose revision is still
     * `revision`, that has an arrival waiting in `name`, and none of whose
     * rows of `archive` is `running` or `scheduled`: archive with reason
     * `arrival` and delete its rows of `archive`, raising its revision as
     * `forget` does; append to its history the arrival — `node = name`,
     * `status = entered`, started at its first arrival, finished and archived
     * at `now`, its `ref` in `lease`, reason `lane` — and delete it; insert a
     * `done` row for `name`, started at the first arrival and finished at
     * `now`. Return the subjects that entered.
     *
     * @param list<array{0: int|string, 1: int}> $entries
     * @param list<string>                       $archive
     *
     * @return list<int|string>
     */
    public function enter(string $name, array $entries, array $archive, string $now): array;

    /** How many arrivals wait in `name`. */
    public function queued(string $name): int;

    /** When the oldest arrival waiting in `name` first arrived; null when none waits. */
    public function waitingSince(string $name): ?string;
}
