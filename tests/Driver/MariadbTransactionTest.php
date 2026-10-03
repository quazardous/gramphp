<?php

declare(strict_types=1);

namespace Quazardous\GramPHP\Tests\Driver;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Quazardous\GramPHP\Driver\Mariadb\Sql;
use Quazardous\GramPHP\Driver\Mariadb\Transaction;

/** The deadlock-retry helper, on a real connection, over PDO and Doctrine DBAL. */
#[Group('mariadb')]
final class MariadbTransactionTest extends TestCase
{
    private ?MariadbHarness $harness = null;

    private string $table = '';

    /** @return iterable<string, array{'pdo'|'dbal'}> */
    public static function modes(): iterable
    {
        yield 'pdo' => ['pdo'];
        yield 'dbal' => ['dbal'];
    }

    protected function tearDown(): void
    {
        if ('' !== $this->table) {
            $this->harness?->connect()->exec("DROP TABLE IF EXISTS {$this->table}");
        }
    }

    /** @param 'pdo'|'dbal' $mode */
    private function sql(string $mode): Sql
    {
        $this->harness = MariadbDriverTest::harness($mode);
        $this->table = \sprintf('tx%d_%s', getmypid(), $mode);
        $this->harness->connect()->exec("CREATE TABLE IF NOT EXISTS {$this->table} (n INT) ENGINE=InnoDB");
        $this->harness->connect()->exec("DELETE FROM {$this->table}");

        return $this->harness->connect()->sql();
    }

    private function rows(): int
    {
        $sql = $this->harness?->connect()->sql() ?? throw new \LogicException('no harness');
        $count = $sql->select("SELECT COUNT(*) FROM {$this->table}")[0][0] ?? null;

        return is_numeric($count) ? (int) $count : -1;
    }

    /** What `work` threw, or null. */
    private static function thrown(callable $work): ?\Throwable
    {
        try {
            $work();
        } catch (\Throwable $e) {
            return $e;
        }

        return null;
    }

    /** What InnoDB throws on a deadlock, as PDO reports it. */
    private static function deadlock(): \PDOException
    {
        $e = new \PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock');
        (new \ReflectionProperty(\Exception::class, 'code'))->setValue($e, '40001');
        $e->errorInfo = ['40001', 1213, 'Deadlock found when trying to get lock'];

        return $e;
    }

    /** @param 'pdo'|'dbal' $mode */
    #[DataProvider('modes')]
    public function testAUnitIsCommitted(string $mode): void
    {
        $sql = $this->sql($mode);
        $result = (new Transaction($sql))->run(fn(): int => $sql->execute("INSERT INTO {$this->table} VALUES (1)"));
        self::assertSame(1, $result, 'what the unit returns comes back');
        self::assertFalse($sql->inTransaction());
        self::assertSame(1, $this->rows(), 'seen from another connection: committed');
    }

    /** @param 'pdo'|'dbal' $mode */
    #[DataProvider('modes')]
    public function testADeadlockedUnitRunsAgainFromAFreshTransaction(string $mode): void
    {
        $sql = $this->sql($mode);
        $runs = new \ArrayObject(['n' => 0]);
        (new Transaction($sql, maxPauseMicro: 1_000))->run(function () use ($sql, $runs): void {
            ++$runs['n'];
            $sql->execute("INSERT INTO {$this->table} VALUES (?)", [$runs['n']]);
            if ($runs['n'] < 3) {
                throw self::deadlock();
            }
        });
        self::assertSame(3, $runs['n']);
        self::assertSame(1, $this->rows(), 'the deadlocked attempts left nothing behind');
    }

    /** @param 'pdo'|'dbal' $mode */
    #[DataProvider('modes')]
    public function testAnythingElseIsRolledBackAndThrownAsItCame(string $mode): void
    {
        $sql = $this->sql($mode);
        $runs = new \ArrayObject(['n' => 0]);
        $caught = self::thrown(fn() => (new Transaction($sql))->run(function () use ($sql, $runs): void {
            ++$runs['n'];
            $sql->execute("INSERT INTO {$this->table} VALUES (1)");

            throw new \RuntimeException('the application failed');
        }));
        self::assertInstanceOf(\RuntimeException::class, $caught);
        self::assertSame('the application failed', $caught->getMessage(), 'the failure comes out as it came');
        self::assertSame(1, $runs['n'], 'not retried');
        self::assertSame(0, $this->rows(), 'rolled back');
    }

    /** @param 'pdo'|'dbal' $mode */
    #[DataProvider('modes')]
    public function testTheAttemptsAreBounded(string $mode): void
    {
        $sql = $this->sql($mode);
        $runs = new \ArrayObject(['n' => 0]);
        $caught = self::thrown(static fn() => (new Transaction($sql, attempts: 3, maxPauseMicro: 1_000))->run(static function () use ($runs): void {
            ++$runs['n'];

            throw self::deadlock();
        }));
        self::assertInstanceOf(\PDOException::class, $caught, 'past its attempts the deadlock comes out');
        self::assertTrue(Transaction::isRetryable($caught));
        self::assertSame(3, $runs['n']);
    }

    /** @param 'pdo'|'dbal' $mode */
    #[DataProvider('modes')]
    public function testAnOpenTransactionIsRefused(string $mode): void
    {
        $sql = $this->sql($mode);
        $sql->begin();
        try {
            $this->expectException(\LogicException::class);
            $this->expectExceptionMessage('already open');
            (new Transaction($sql))->run(static fn(): int => 1);
        } finally {
            $sql->rollBack();
        }
    }

    public function testADeadlockIsFoundAnywhereInTheChain(): void
    {
        self::assertTrue(Transaction::isRetryable(self::deadlock()));
        self::assertTrue(Transaction::isRetryable(new \RuntimeException('wrapped', 0, self::deadlock())));
        self::assertFalse(Transaction::isRetryable(new \PDOException('a syntax error')));
        self::assertFalse(Transaction::isRetryable(new \RuntimeException('nothing to do with the database')));
    }
}
