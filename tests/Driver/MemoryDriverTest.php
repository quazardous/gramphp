<?php

declare(strict_types=1);

namespace Quazardous\GramPHP\Tests\Driver;

use Quazardous\GramPHP\Tests\Contract\Harness;
use Quazardous\GramPHP\Tests\Contract\JournalContract;

/** The memory driver passes the contract — it is the reference. */
final class MemoryDriverTest extends JournalContract
{
    protected function makeHarness(): Harness
    {
        return new MemoryHarness();
    }
}
