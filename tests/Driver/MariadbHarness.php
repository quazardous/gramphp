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
 * inside a transaction.
 */
final class MariadbHarness implements Harness
{
    private static int $counter = 0;

    /** @var list<\PDO> */
    private array $open = [];

    /** @var list<array<string, string>> table sets to drop */
    private array $tables = [];

    /** @var \WeakMap<NodeJournal, array{pdo: \PDO, tables: array<string, string>}> */
    private \WeakMap $of;

    public function __construct(
        private readonly string $dsn,
        private readonly string $user,
        private readonly string $password,
    ) {
        $this->of = new \WeakMap();
    }

    public function connect(): \PDO
    {
        $pdo = new \PDO($this->dsn, $this->user, $this->password, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');

        return $pdo;
    }

    /**
     * @param 'int'|'string' $subjectType
     *
     * @return array<string, string>
     */
    public function createTables(string $subjectType): array
    {
        $prefix = \sprintf('g%d_%d', getmypid(), ++self::$counter);
        $tables = ['table' => "{$prefix}_nodes", 'revisions' => "{$prefix}_rev", 'history' => "{$prefix}_hist", 'limits' => "{$prefix}_lim"];
        $pdo = $this->connect();
        foreach (MariadbDriver::schema($subjectType, ...$tables) as $statement) {
            $pdo->exec($statement);
        }
        $this->tables[] = $tables;

        return $tables;
    }

    /**
     * @param array<string, string> $tables
     * @param 'int'|'string'        $subjectType
     */
    public static function driver(\PDO $pdo, array $tables, string $subjectType): MariadbDriver
    {
        return new MariadbDriver($pdo, $subjectType, $tables['table'], $tables['revisions'], $tables['history'], $tables['limits']);
    }

    public function journal(Dag $dag, callable $clock, string $subjectType = 'string'): NodeJournal
    {
        $tables = $this->createTables($subjectType);
        $pdo = $this->connect();
        $pdo->beginTransaction();
        $this->open[] = $pdo;
        $journal = new NodeJournal(self::driver($pdo, $tables, $subjectType), $dag, $clock);
        $this->of[$journal] = ['pdo' => $pdo, 'tables' => $tables];

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
        ['pdo' => $pdo, 'tables' => $tables] = $this->of[$journal] ?? throw new \LogicException('not a journal of this harness');
        $insert = $pdo->prepare("INSERT INTO {$tables['table']} (subject, node, status, started_at, finished_at) VALUES (?, ?, ?, ?, ?)");
        foreach ($progress as $name => $status) {
            $insert->execute([$subject, (string) $name, $status->value, Clock::T0, Status::Running === $status ? null : Clock::T0]);
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
                $pdo = $this->harness->connect();
                $pdo->beginTransaction();
                $journal = new NodeJournal(MariadbHarness::driver($pdo, $this->tables, 'string'), $this->dag, $this->clock);

                return new class ($pdo, $journal) implements Session {
                    public function __construct(private ?\PDO $pdo, private readonly NodeJournal $journal) {}

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
                        $this->pdo?->commit();
                        $this->pdo = null;
                    }

                    public function rollback(): void
                    {
                        $this->pdo?->rollBack();
                        $this->pdo = null;
                    }
                };
            }

            public function close(): void {}
        };
    }

    public function close(): void
    {
        foreach ($this->open as $pdo) {
            try {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            } catch (\PDOException) {
                // a connection the test broke
            }
        }
        $this->open = [];
        if ([] !== $this->tables) {
            $pdo = $this->connect();
            foreach ($this->tables as $tables) {
                $pdo->exec('DROP TABLE IF EXISTS ' . implode(', ', $tables));
            }
            $this->tables = [];
        }
    }
}
