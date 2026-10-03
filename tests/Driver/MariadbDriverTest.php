<?php

declare(strict_types=1);

namespace Quazardous\GramPHP\Tests\Driver;

use PHPUnit\Framework\Attributes\Group;
use Quazardous\GramPHP\Tests\Contract\Harness;
use Quazardous\GramPHP\Tests\Contract\JournalContract;

/**
 * The MariaDB driver over PDO passes the contract, concurrency included.
 * Runs when GRAMPHP_TEST_MARIADB_DSN is set (the Docker environment sets it).
 */
#[Group('mariadb')]
final class MariadbDriverTest extends JournalContract
{
    protected function makeHarness(): Harness
    {
        return self::harness('pdo');
    }

    /** @param 'pdo'|'dbal' $mode */
    public static function harness(string $mode): MariadbHarness
    {
        $dsn = getenv('GRAMPHP_TEST_MARIADB_DSN');
        if (false === $dsn || '' === $dsn) {
            self::markTestSkipped('set GRAMPHP_TEST_MARIADB_DSN to run the contract on MariaDB');
        }

        return new MariadbHarness($mode, $dsn, (string) getenv('GRAMPHP_TEST_MARIADB_USER'), (string) getenv('GRAMPHP_TEST_MARIADB_PASSWORD'));
    }
}
