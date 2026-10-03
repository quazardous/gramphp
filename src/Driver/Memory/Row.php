<?php

declare(strict_types=1);

namespace Quazardous\GramPHP\Driver\Memory;

/** One subject's passage through one node. */
final class Row
{
    public function __construct(
        public string $status,
        public string $startedAt,
        public ?string $finishedAt = null,
        public ?string $lease = null,
    ) {}
}
