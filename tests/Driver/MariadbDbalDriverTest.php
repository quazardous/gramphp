<?php

declare(strict_types=1);

namespace Quazardous\GramPHP\Tests\Driver;

use PHPUnit\Framework\Attributes\Group;
use Quazardous\GramPHP\Tests\Contract\Harness;
use Quazardous\GramPHP\Tests\Contract\JournalContract;

/**
 * The MariaDB driver over a Doctrine DBAL connection (`DbalSql`) passes the
 * same contract, concurrency included.
 */
#[Group('mariadb')]
final class MariadbDbalDriverTest extends JournalContract
{
    protected function makeHarness(): Harness
    {
        return MariadbDriverTest::harness('dbal');
    }
}
