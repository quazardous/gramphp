<?php

declare(strict_types=1);

namespace Quazardous\GramPHP\Driver;

/**
 * OPTIONAL. The earliest `started_at` of a node's `running` rows and of its
 * `scheduled` rows (its next retry due), keyed by those statuses — a key
 * left out when nothing stands there. `$journal->snapshot()` reads it for its
 * ages; without it, the ages are null and the counts remain.
 */
interface NodeTimes
{
    /** @return array<string, string> status => earliest started_at */
    public function nodeTimes(string $name): array;
}
