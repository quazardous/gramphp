<?php

declare(strict_types=1);

namespace Quazardous\GramPHP;

/**
 * A DECLARED WAY BACK. When the node carrying it concludes with a status of
 * `on`, the subject returns to `to` — an ancestor, or the node itself: `to`
 * and everything after it are archived and become claimable again, in the
 * same write as the conclusion. At most `max` times per subject; after that
 * the conclusion stands, and a failure edge can take over.
 *
 *     new Node('review', parents: ['draft'], loop: new Loop(to: 'draft', max: 3))
 */
final readonly class Loop
{
    /** @var list<string> */
    public array $on;

    /** @param array<Status|string> $on */
    public function __construct(
        public string $to,
        public int $max,
        array $on = [Status::Failed],
    ) {
        $this->on = array_values(array_map(Status::valueOf(...), $on));
    }
}
