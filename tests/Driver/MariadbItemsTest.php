<?php

declare(strict_types=1);

namespace Quazardous\GramPHP\Tests\Driver;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Quazardous\GramPHP\Driver\Mariadb\Query;
use Quazardous\GramPHP\Items\Adapter;
use Quazardous\GramPHP\Items\Items;
use Quazardous\GramPHP\NodeJournal;
use Quazardous\GramPHP\Tests\Contract\Brick;
use Quazardous\GramPHP\Tests\Contract\Bricks;

/**
 * The items layer on a driver's query: the one place ids are unavoidable —
 * the storage itself produces the candidates, so the lease is loaded after.
 */
#[Group('mariadb')]
final class MariadbItemsTest extends TestCase
{
    private ?MariadbHarness $harness = null;

    private TestDb $db;

    private NodeJournal $journal;

    private string $docs;

    protected function setUp(): void
    {
        $this->harness = MariadbDriverTest::harness('pdo');
        $tables = $this->harness->createTables('int');
        $this->docs = $tables['table'] . '_docs';
        $this->db = $this->harness->connect();
        $this->db->exec("CREATE TABLE {$this->docs} (id INT PRIMARY KEY)");
        $this->db->exec("INSERT INTO {$this->docs} VALUES (1), (2), (3)");
        $this->db->begin();
        $this->journal = new NodeJournal(MariadbHarness::driver($this->db, $tables, 'int'), Bricks::graph());
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->inTransaction()) {
            $this->db->rollback();
        }
        if (isset($this->docs)) {
            $this->harness?->connect()->exec("DROP TABLE IF EXISTS {$this->docs}");
        }
        $this->harness?->close();
    }

    public function testADriverQueryIsHandedOverUntouchedAndTheLeaseIsLoaded(): void
    {
        $adapter = new Bricks([new Brick(1), new Brick(2), new Brick(3)]);
        $lease = (new Items($this->journal, $adapter))->claim('scan', 10, $this->query());
        self::assertSame([1, 2, 3], array_map(static fn(mixed $b): int => Bricks::brick($b)->id, $lease->items));
        self::assertSame(['inflate([1, 2, 3])'], $adapter->called('inflate'), 'one load for the batch');
    }

    public function testAnItemThatNoLongerLoadsDoesNotLoseTheClaim(): void
    {
        $adapter = new Bricks([new Brick(1), new Brick(3)]);   // brick 2 deleted between the claim and the load
        $items = new Items($this->journal, $adapter);
        $lease = $items->claim('scan', 10, $this->query());
        self::assertSame([1, 3], array_map(static fn(mixed $b): int => Bricks::brick($b)->id, $lease->items));
        self::assertSame([2], $lease->missing, 'named, not silently dropped');
        self::assertSame(2, $items->conclude('scan', $lease), 'the others still conclude');
    }

    public function testInflateIsOnlyNeededWhenAQueryNamesTheCandidates(): void
    {
        $noLoad = new class extends Adapter {
            public function idOf(mixed $candidate): int
            {
                return Bricks::brick($candidate)->id;
            }
        };
        $items = new Items($this->journal, $noLoad);
        self::assertCount(2, $items->claim('scan', 10, [new Brick(1), new Brick(2)]), 'items travel with the claim');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/needs `inflate`/');
        $items->claim('scan', 10, $this->query());
    }

    private function query(): Query
    {
        return new Query("SELECT id FROM {$this->docs} ORDER BY id");
    }
}
