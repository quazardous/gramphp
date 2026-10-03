<?php

declare(strict_types=1);

namespace Quazardous\GramPHP;

/**
 * A SUBJECT IS THE APPLICATION'S ID: unique, stable, an `int` or a `string`,
 * stored and returned exactly as given — never converted, built or split.
 *
 * PHP turns an array key that looks like an integer into an integer: the
 * subjects `7` and `"7"` would share one key, and `"7"` would come back as
 * `7`. So whenever subjects are indexed, they are indexed by `Subject::key()`
 * — the type is part of the key — and the value itself is kept beside it.
 */
final class Subject
{
    /** The array key of a subject, its type included: `i:7` and `s:7` differ. */
    public static function key(int|string $subject): string
    {
        return (\is_int($subject) ? 'i:' : 's:') . $subject;
    }

    /**
     * The subjects once each, in their order.
     *
     * @param iterable<int|string> $subjects
     *
     * @return list<int|string>
     */
    public static function unique(iterable $subjects): array
    {
        $seen = [];
        foreach ($subjects as $subject) {
            $seen[self::key($subject)] ??= $subject;
        }

        return array_values($seen);
    }
}
