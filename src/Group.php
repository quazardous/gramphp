<?php

declare(strict_types=1);

namespace Quazardous\GramPHP;

/**
 * SUBJECTS WORKED TOGETHER: the claim hands out a whole group or none.
 *
 * A node that groups is not claimed one subject at a time. The claim gathers
 * `size` subjects sharing a key and hands them back under ONE lease, so the
 * worker does one thing with all of them — a batch of a colour, a feed file,
 * one call to a service that charges per call.
 *
 *     size      how many go together
 *     maxWait   past this long after the OLDEST member became ready, an
 *               incomplete group goes anyway. Without it a rare key waits for
 *               as long as it takes to fill — sometimes what you want,
 *               sometimes starvation
 *     perKey    false gathers any subjects, whatever their key
 *
 * THE KEY IS NOT GRAMPHP'S. It travels with the candidates — a `grampy_key`
 * column of your query, a `Keyed(subject, key)` in a plain iterable — and is
 * compared, never read.
 *
 * Nothing is stored while a group fills: a node whose group is short is
 * simply not claimable. There is no half-gathered state to clean up.
 */
final readonly class Group
{
    public function __construct(
        public int $size,
        public int|float|string|null $maxWait = null,
        public bool $perKey = true,
    ) {}
}
