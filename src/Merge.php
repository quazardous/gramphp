<?php

declare(strict_types=1);

namespace Quazardous\GramPHP;

/**
 * How a lane merges a new arrival into the one waiting. A lane's merge may
 * also be `Merge::fn('<name>')`, a function the journal is given — see `Lane`.
 */
enum Merge: string
{
    /** The arrival waiting keeps its ref. */
    case First = 'first';
    /** The new arrival's ref replaces it. */
    case Last = 'last';
    /** Every ref is kept, in the order they came. */
    case All = 'all';
    /** DRIVER CONTRACT ONLY: the stored list of refs is replaced as given. */
    case Set = 'set';

    /** What names a merge function rather than one of the modes above. */
    public const FN = 'fn:';

    /** A merge decided by the function the journal was given as `name`. */
    public static function fn(string $name): string
    {
        return self::FN . $name;
    }
}
