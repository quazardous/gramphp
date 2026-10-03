<?php

declare(strict_types=1);

namespace Quazardous\GramPHP;

/** How a retry's wait grows from one attempt to the next. */
enum Backoff: string
{
    /** delay, delay, delay, … */
    case Constant = 'constant';
    /** delay, 2·delay, 3·delay, … */
    case Linear = 'linear';
    /** delay, 2·delay, 4·delay, … */
    case Exponential = 'exponential';
}
