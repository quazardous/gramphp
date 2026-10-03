<?php

declare(strict_types=1);

namespace Quazardous\GramPHP;

/**
 * What a history NOTE records, rather than a node's row: a signal received
 * (the status of a `Signal` row), and what became of an arrival in a lane.
 */
enum Outcome: string
{
    case Received = 'received';
    /** Not a note: what `counts()` reports for a lane — the arrivals waiting. */
    case Waiting = 'waiting';
    case Queued = 'queued';
    case Merged = 'merged';
    case Skipped = 'skipped';
    case Dropped = 'dropped';
    case Entered = 'entered';
}
