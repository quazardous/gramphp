<?php

declare(strict_types=1);

namespace Quazardous\GramPHP\Tests\Contract;

/** A clock that only moves when told to. */
final class Clock
{
    public const T0 = '2026-01-01T00:00:00+00:00';

    public function __construct(public string $now = self::T0) {}

    public function __invoke(): string
    {
        return $this->now;
    }
}
