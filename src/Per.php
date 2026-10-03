<?php

declare(strict_types=1);

namespace Quazardous\GramPHP;

/** Whose budget a node's rate and concurrency count. */
enum Per: string
{
    /** One budget, shared by every policy. */
    case All = 'all';
    /** One budget per policy. */
    case Policy = 'policy';
}
