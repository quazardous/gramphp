<?php

declare(strict_types=1);

namespace Quazardous\GramPHP;

/**
 * Subjects that cannot move to the new version — `problems` says why,
 * subject by subject. Nothing was written.
 */
final class MigrationError extends \InvalidArgumentException
{
    /** @param array<string, string> $problems Subject::key() => why */
    public function __construct(public readonly array $problems)
    {
        $lines = [];
        foreach (\array_slice($problems, 0, 10, true) as $key => $why) {
            $lines[] = substr((string) $key, 2) . ": {$why}";
        }
        $more = \count($problems) > 10 ? \sprintf(' (+%d more)', \count($problems) - 10) : '';
        parent::__construct(\sprintf('%d subject(s) not compliant — %s%s', \count($problems), implode('; ', $lines), $more));
    }
}
