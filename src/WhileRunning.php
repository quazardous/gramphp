<?php

declare(strict_types=1);

namespace Quazardous\GramPHP;

/** What an arrival does while a pass of its subject is still running. */
enum WhileRunning: string
{
    /** It waits for the pass to end. */
    case Queue = 'queue';
    /** It is dropped, and noted in the history. */
    case Skip = 'skip';
}
