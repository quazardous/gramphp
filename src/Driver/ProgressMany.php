<?php

declare(strict_types=1);

namespace Quazardous\GramPHP\Driver;

/**
 * OPTIONAL. `progress` for many subjects in one read — a subject without rows
 * may be left out. Without it, the journal reads subject by subject: the same
 * result, a round trip each.
 */
interface ProgressMany
{
    /**
     * @param list<int|string> $subjects
     *
     * @return array<string, array<string, string>> Subject::key() => [node => status]
     */
    public function progressMany(array $subjects): array;
}
