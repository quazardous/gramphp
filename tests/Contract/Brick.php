<?php

declare(strict_types=1);

namespace Quazardous\GramPHP\Tests\Contract;

/** An application's own object, for the items layer. */
final class Brick
{
    public function __construct(
        public readonly int $id,
        public readonly string $crate = 'factory',
        public readonly string $kind = 'plain',
    ) {}
}
