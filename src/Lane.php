<?php

declare(strict_types=1);

namespace Quazardous\GramPHP;

/**
 * A DECLARED WAY IN: the subject comes back — a new version of the same
 * thing — and waits here before it runs through the graph again.
 *
 * An arrival (`$journal->arrive()`) waits in the lane; `$journal->settle()`
 * lets it in once it is due, and the pass that went before — this node and
 * everything after it — is archived in the same write (reason `arrival`).
 *
 *     merge         first: the arrival waiting keeps its `ref`, later ones are
 *                   dropped; last: the latest `ref` wins; all: every `ref` is
 *                   kept, in the order they arrived, at most `maxSize` of
 *                   them; `Merge::fn('<name>')`: a function decides, given to
 *                   the journal as `mergers: ['<name>' => $fn]`. A `ref` NAMES
 *                   WHAT CAME BACK — opaque to gramphp.
 *     maxSize       with `all`, how many refs are kept — beyond it the OLDEST
 *                   is let go, and noted in the history
 *     position      first: a merged arrival keeps the place of the first;
 *                   last: it goes to the back, as if it had just arrived
 *     cooldown      not before this long after the previous pass ended — the
 *                   same subject is not run twice within the window
 *     delay         not before this long after its place — quiet time
 *     maxWait       whatever the above, not later than this long after the
 *                   first arrival: a subject that keeps coming back still
 *                   gets through
 *     whileRunning  queue: an arrival during a running pass waits for it to
 *                   end; skip: it is dropped, and noted in the history
 *
 * Presets under the names other tools gave them:
 *
 *     Lane::throttle($cooldown)   last ref, place of the first
 *     Lane::debounce($delay)      last ref, to the back
 *     Lane::dedupe()              first ref, the rest dropped
 *     Lane::batch()               every ref, in order (an aggregator)
 */
final readonly class Lane
{
    public string $merge;

    public function __construct(
        Merge|string $merge = Merge::Last,
        public Position $position = Position::First,
        public int|float|string|null $cooldown = null,
        public int|float|string|null $delay = null,
        public int|float|string|null $maxWait = null,
        public WhileRunning $whileRunning = WhileRunning::Queue,
        public int $maxSize = 100,
    ) {
        $this->merge = $merge instanceof Merge ? $merge->value : $merge;
    }

    public static function throttle(int|float|string $cooldown, int|float|string|null $maxWait = null): self
    {
        return new self(Merge::Last, Position::First, cooldown: $cooldown, maxWait: $maxWait);
    }

    public static function debounce(int|float|string $delay, int|float|string|null $maxWait = null): self
    {
        return new self(Merge::Last, Position::Last, delay: $delay, maxWait: $maxWait);
    }

    public static function dedupe(int|float|string|null $cooldown = null): self
    {
        return new self(Merge::First, Position::First, cooldown: $cooldown);
    }

    public static function batch(int|float|string|null $cooldown = null, int $maxSize = 100, int|float|string|null $maxWait = null): self
    {
        return new self(Merge::All, Position::First, cooldown: $cooldown, maxWait: $maxWait, maxSize: $maxSize);
    }

    /** The name of the function this lane merges with, if it does. */
    public function merger(): ?string
    {
        return str_starts_with($this->merge, Merge::FN) ? substr($this->merge, \strlen(Merge::FN)) : null;
    }

    /**
     * True when a merge has to read what waits before writing: the journal
     * does those under the driver's guard.
     */
    public function keepsEveryRef(): bool
    {
        return Merge::All->value === $this->merge || null !== $this->merger();
    }
}
