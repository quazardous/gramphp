<?php

declare(strict_types=1);

namespace Quazardous\GramPHP\Driver;

/**
 * NEEDED BY A NODE WITH A `rate` OR A `concurrency`. The journal decides and
 * holds the driver's `guard` around the read and the write; the driver stores
 * the limiter state and counts.
 */
interface LimitDriver
{
    /**
     * The stored limiter state, keys never set left out.
     *
     * @param list<string> $keys
     *
     * @return array<string, float>
     */
    public function limits(array $keys): array;

    /**
     * Store limiter state, creating the keys as needed.
     *
     * @param array<string, float> $values
     */
    public function setLimits(array $values): void;

    /**
     * How many rows of `name` are RUNNING — of subjects whose policy is in
     * `policies` (null in it stands for "no policy"), or of all subjects when
     * `policies` is null.
     *
     * @param list<?string>|null $policies
     */
    public function running(string $name, ?array $policies): int;
}
