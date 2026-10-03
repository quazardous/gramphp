<?php

declare(strict_types=1);

namespace Quazardous\GramPHP;

/**
 * Why a row went to the history. `Retry` and `Loop` are COUNTED: they are
 * what a retry limit and a loop bound read, and pruning never removes them.
 */
enum Reason: string
{
    case Forget = 'forget';
    case Release = 'release';
    case Retry = 'retry';
    case Loop = 'loop';
    case Migrate = 'migrate';
    case Arrival = 'arrival';
    case Lane = 'lane';
    case Signal = 'signal';
}
