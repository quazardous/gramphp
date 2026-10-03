<?php

declare(strict_types=1);

namespace Quazardous\GramPHP;

/** Where a merged arrival stands in its lane. */
enum Position: string
{
    /** It keeps the place of the first. */
    case First = 'first';
    /** It goes to the back, as if it had just arrived. */
    case Last = 'last';
}
