<?php

declare(strict_types=1);

namespace Quazardous\GramPHP\Tests\Driver;

use Quazardous\GramPHP\Dag;
use Quazardous\GramPHP\Driver\Mariadb\MariadbDriver;
use Quazardous\GramPHP\Driver\Mariadb\Query;
use Quazardous\GramPHP\NodeJournal;
use Quazardous\GramPHP\Status;
use Quazardous\GramPHP\Tests\Contract\Clock;
use Quazardous\GramPHP\Tests\Contract\Harness;
use Quazardous\GramPHP\Tests\Contract\Session;
use Quazardous\GramPHP\Tests\Contract\Store;

/**
 * Each journal gets tables of its own — names unique per process and test —
 * dropped when the harness closes. Every connection runs READ COMMITTED,
 * inside a transaction, over PDO or Doctrine DBAL.
 */
final class MariadbHarness implements Harness
{
    private static int $counter = 0;

    /** @var list<TestDb> */
    private array $open = [];

    /** @var list<array<string, string>> table sets to drop */
    private array $tables = [];

    /** @var \WeakMap<NodeJournal, array{db: TestDb, tables: array<string, string>}> */
    private \WeakMap $of;

    /** @param 'pdo'|'dbal' $mode */
    public function __construct(
        private readonly string $mode,
        private readonly string $dsn,
        private readonly string $user,
        private readonly string $password,
    ) {
        $this->of = new \WeakMap();
    }

    public function connect(): TestDb
    {
        return TestDb::connect($this->mode, $this->dsn, $this->user, $this->password);
    }

    /**
     * @param 'int'|'string' $subjectType
     *
     * @return array<string, string>
     */
    public function createTables(string $subjectType): array
    {
        $prefix = \sprintf('g%d_%d', getmypid(), ++self::$counter);
        $tables = ['table' => "{$prefix}_nodes", 'revisions' => "{$prefix}_rev", 'history' => "{$prefix}_hist", 'limits' => "{$prefix}_lim", 'arrivals' => "{$prefix}_arr"];
        $db = $this->connect();
        foreach (MariadbDriver::schema($subjectType, ...$tables) as $statement) {
            $db->exec($statement);
        }
        $this->tables[] = $tables;

        return $tables;
    }

    /**
     * @param array<string, string> $tables
     * @param 'int'|'string'        $subjectType
     */
    public static function driver(TestDb $db, array $tables, string $subjectType): MariadbDriver
    {
        return new MariadbDriver($db->sql(), $subjectType, $tables['table'], $tables['revisions'], $tables['history'], $tables['limits'], arrivals: $tables['arrivals']);
    }

    public function journal(Dag $dag, callable $clock, string $subjectType = 'string', array $mergers = []): NodeJournal
    {
        $tables = $this->createTables($subjectType);
        $db = $this->connect();
        $db->begin();
        $this->open[] = $db;
        $journal = new NodeJournal(self::driver($db, $tables, $subjectType), $dag, $clock, mergers: $mergers);
        $this->of[$journal] = ['db' => $db, 'tables' => $tables];

        return $journal;
    }

    public function candidates(array $subjects): mixed
    {
        return self::ordered($subjects);
    }

    /**
     * An ordered SELECT over literal values: the first column the subject.
     *
     * @param list<int|string> $subjects
     */
    public static function ordered(array $subjects): Query
    {
        if ([] === $subjects) {
            return new Query('SELECT 1 FROM DUAL WHERE FALSE');
        }
        $rows = implode(' UNION ALL ', array_fill(0, \count($subjects), 'SELECT ? AS s, ? AS o'));
        $params = [];
        foreach ($subjects as $i => $subject) {
            array_push($params, $subject, $i);
        }

        return new Query("SELECT s FROM ({$rows}) c ORDER BY o", $params);
    }

    public function seed(NodeJournal $journal, int|string $subject, array $progress): void
    {
        ['db' => $db, 'tables' => $tables] = $this->of[$journal] ?? throw new \LogicException('not a journal of this harness');
        foreach ($progress as $name => $status) {
            $db->exec(
                "INSERT INTO {$tables['table']} (subject, node, status, started_at, finished_at) VALUES (?, ?, ?, ?, ?)",
                [$subject, (string) $name, $status->value, Clock::T0, Status::Running === $status ? null : Clock::T0],
            );
        }
    }

    public function parentsConcluded(NodeJournal $journal, string $name, int|string $subject): bool
    {
        return true === $journal->parentsConcluded($name, $subject);
    }

    public function store(Dag $dag, callable $clock): Store
    {
        $harness = $this;
        $tables = $this->createTables('string');

        return new class ($harness, $tables, $dag, $clock) implements Store {
            /**
             * @param array<string, string>                   $tables
             * @param callable(): (string|\DateTimeInterface) $clock
             */
            public function __construct(
                private readonly MariadbHarness $harness,
                private readonly array $tables,
                private readonly Dag $dag,
                private $clock,
            ) {}

            public function session(): Session
            {
                $db = $this->harness->connect();
                $db->begin();
                $journal = new NodeJournal(MariadbHarness::driver($db, $this->tables, 'string'), $this->dag, $this->clock);

                return new class ($db, $journal) implements Session {
                    private bool $ended = false;

                    public function __construct(private readonly TestDb $db, private readonly NodeJournal $journal) {}

                    public function journal(): NodeJournal
                    {
                        return $this->journal;
                    }

                    public function candidates(array $subjects): mixed
                    {
                        return MariadbHarness::ordered($subjects);
                    }

                    public function commit(): void
                    {
                        if (!$this->ended) {
                            $this->db->commit();
                            $this->ended = true;
                        }
                    }

                    public function rollback(): void
                    {
                        if (!$this->ended) {
                            $this->db->rollback();
                            $this->ended = true;
                        }
                    }
                };
            }

            public function retryable(\Throwable $e): bool
            {
                return MariadbHarness::isDeadlock($e);
            }

            public function close(): void {}
        };
    }

    /** A deadlock or lock conflict InnoDB asks to retry: SQLSTATE 40001, error 1213. */
    public static function isDeadlock(\Throwable $e): bool
    {
        for ($cause = $e; null !== $cause; $cause = $cause->getPrevious()) {
            if ($cause instanceof \Doctrine\DBAL\Exception\RetryableException) {
                return true;
            }
            if ($cause instanceof \PDOException && ('40001' === (string) $cause->getCode() || 1213 === ($cause->errorInfo[1] ?? null))) {
                return true;
            }
        }

        return false;
    }

    public function close(): void
    {
        foreach ($this->open as $db) {
            try {
                if ($db->inTransaction()) {
                    $db->rollback();
                }
            } catch (\Throwable) {
                // a connection the test broke
            }
        }
        $this->open = [];
        if ([] !== $this->tables) {
            $db = $this->connect();
            foreach ($this->tables as $tables) {
                $db->exec('DROP TABLE IF EXISTS ' . implode(', ', $tables));
            }
            $this->tables = [];
        }
    }
}
