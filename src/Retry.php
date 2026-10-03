<?php

declare(strict_types=1);

namespace Quazardous\GramPHP;

/**
 * A DECLARED RETRY. When the node fails, the failure is archived and the node
 * is scheduled again, at most `limit` times per subject, after `delay` grown
 * by `backoff`:
 *
 *     constant     delay, delay, delay, …
 *     linear       delay, 2·delay, 3·delay, …
 *     exponential  delay, 2·delay, 4·delay, …
 *
 * capped by `maxDelay`, then spread by `jitter` — a fraction: 0.1 moves each
 * wait by up to ±10 %, so that subjects failing together do not all come back
 * in the same second. Past the limit, the failure stands.
 */
final readonly class Retry
{
    public function __construct(
        public int $limit,
        public int|float|string $delay = 0,
        public Backoff $backoff = Backoff::Exponential,
        public int|float|string|null $maxDelay = null,
        public float $jitter = 0.0,
    ) {
        if ($limit < 1) {
            throw new \InvalidArgumentException(\sprintf('a retry needs limit >= 1, got %d', $limit));
        }
        if ($jitter < 0 || $jitter >= 1) {
            throw new \InvalidArgumentException(\sprintf('jitter is a fraction in [0, 1), got %s', $jitter));
        }
        Time::seconds($delay);
        if (null !== $maxDelay) {
            Time::seconds($maxDelay);
        }
    }

    /** Seconds to wait before retry number `attempt` (1 for the first). */
    public function wait(int $attempt, ?\Random\Randomizer $rng = null): float
    {
        $base = Time::seconds($this->delay);
        $base *= match ($this->backoff) {
            Backoff::Constant => 1,
            Backoff::Linear => $attempt,
            Backoff::Exponential => 2 ** ($attempt - 1),
        };
        if (null !== $this->maxDelay) {
            $base = min($base, Time::seconds($this->maxDelay));
        }
        if ($this->jitter > 0) {
            $rng ??= new \Random\Randomizer();
            $unit = $rng->getInt(0, \PHP_INT_MAX) / \PHP_INT_MAX;     // [0, 1]
            $base *= 1 + (2 * $unit - 1) * $this->jitter;
        }

        return max($base, 0.0);
    }
}
