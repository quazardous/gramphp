<?php

declare(strict_types=1);

namespace Quazardous\GramPHP;

/**
 * A RATE LIMIT BAND: at most `limit` per `period`, spread evenly, with up to
 * `burst` at once (default: `limit` — a full period's worth). Several bands
 * on one node all apply: `[new Rate(100, '1m'), new Rate(1000, '1h')]`.
 *
 * Kept as the GENERIC CELL RATE ALGORITHM (ITU-T I.371): one number per
 * band, the theoretical arrival time of the next cell, instead of a counter
 * and a window to refresh.
 */
final readonly class Rate
{
    public function __construct(
        public int $limit,
        public int|float|string $period,
        public ?int $burst = null,
    ) {
        if ($limit < 1) {
            throw new \InvalidArgumentException(\sprintf('a rate needs limit >= 1, got %d', $limit));
        }
        if (Time::seconds($period) <= 0) {
            throw new \InvalidArgumentException(\sprintf('a rate needs a period that lasts, got %s', var_export($period, true)));
        }
        if (null !== $burst && $burst < 1) {
            throw new \InvalidArgumentException(\sprintf('a burst is at least 1, got %d', $burst));
        }
    }

    /** T: the time one cell costs, in seconds. */
    public function interval(): float
    {
        return Time::seconds($this->period) / $this->limit;
    }

    /** τ: how far ahead of schedule the band lets cells run, in seconds. */
    public function tolerance(): float
    {
        return (($this->burst ?? $this->limit) - 1) * $this->interval();
    }

    /**
     * How many of `want` cells the bands let through at `now` (epoch
     * seconds), and each band's new theoretical arrival time once they are
     * taken.
     *
     * Per band, with `lag = max(TAT, now) − now`, the cells that fit are
     * `⌊(τ + T − lag) / T⌋`; the batch takes the smallest count over the
     * bands, and EVERY band advances by that count — a cell refused by one
     * band consumes nothing in the others. A band never used has `TAT = null`.
     *
     * @param list<self>       $bands
     * @param list<float|null> $tats
     *
     * @return array{0: int, 1: list<float>}
     */
    public static function admit(array $bands, array $tats, float $now, int $want): array
    {
        if ($want <= 0 || [] === $bands) {
            return [max($want, 0), array_map(static fn(?float $t): float => $t ?? $now, $tats)];
        }
        $fits = $want;
        foreach ($bands as $i => $band) {
            $lag = max($tats[$i] ?? $now, $now) - $now;
            $fits = min($fits, max(0, (int) floor(($band->tolerance() + $band->interval() - $lag) / $band->interval() + 1e-9)));
        }
        $advanced = [];
        foreach ($bands as $i => $band) {
            $advanced[] = max($tats[$i] ?? $now, $now) + $fits * $band->interval();
        }

        return [$fits, $advanced];
    }
}
