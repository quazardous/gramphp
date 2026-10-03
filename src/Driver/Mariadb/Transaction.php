<?php

declare(strict_types=1);

namespace Quazardous\GramPHP\Driver\Mariadb;

/**
 * ONE UNIT OF WORK IN ONE TRANSACTION, RETRIED WHEN INNODB ASKS.
 *
 *     $tx = new Transaction($sql);        // PdoSql, DbalSql, or a PDO
 *     $lease = $tx->run(fn() => $journal->claim('ship', 50, $candidates));
 *
 * Begin, run the unit, commit. A transaction mixing claims and forgets on the
 * same subjects can meet a deadlock: InnoDB reports it (error 1213, SQLSTATE
 * 40001) and has already rolled the transaction back — nothing is ever
 * half-written. The unit then runs again, from the start, after a short
 * random pause, at most `attempts` times. Anything else is rolled back and
 * thrown as it came.
 *
 * THE UNIT MUST BE SAFE TO RUN AGAIN: everything it does in the database is
 * undone by the rollback, but what it does outside — a mail, a call — is not.
 * Keep those after `run` returns.
 *
 * A transaction already open is refused: retrying an inner unit cannot undo
 * the outer work InnoDB rolled back with it.
 */
final class Transaction
{
    private readonly Sql $sql;

    /**
     * @param int $attempts      how many times the unit may run, at most
     * @param int $maxPauseMicro the longest random pause before a retry
     */
    public function __construct(
        \PDO|Sql $connection,
        private readonly int $attempts = 5,
        private readonly int $maxPauseMicro = 50_000,
    ) {
        if ($attempts < 1) {
            throw new \InvalidArgumentException(\sprintf('a transaction runs at least once, got %d attempts', $attempts));
        }
        $this->sql = $connection instanceof Sql ? $connection : new PdoSql($connection);
    }

    /**
     * Run `unit` in a transaction, committed; retried on a deadlock.
     *
     * @template T
     *
     * @param callable(): T $unit
     *
     * @return T
     */
    public function run(callable $unit): mixed
    {
        if ($this->sql->inTransaction()) {
            throw new \LogicException('a transaction is already open: a unit retried inside it could not undo the outer work a deadlock rolls back');
        }
        for ($attempt = 1; ; ++$attempt) {
            $this->sql->begin();
            try {
                $result = $unit();
                $this->sql->commit();

                return $result;
            } catch (\Throwable $e) {
                if ($this->sql->inTransaction()) {
                    try {
                        $this->sql->rollBack();
                    } catch (\Throwable) {
                        // the server already rolled it back
                    }
                }
                if ($attempt >= $this->attempts || !self::isRetryable($e)) {
                    throw $e;
                }
                usleep(random_int(1_000, max(1_000, $this->maxPauseMicro)));
            }
        }
    }

    /**
     * A conflict InnoDB asks to retry — a deadlock, SQLSTATE 40001 / error
     * 1213 — found anywhere in the chain of causes, from PDO or Doctrine DBAL.
     */
    public static function isRetryable(\Throwable $e): bool
    {
        for ($cause = $e; null !== $cause; $cause = $cause->getPrevious()) {
            if (is_a($cause, 'Doctrine\DBAL\Exception\RetryableException')) {
                return true;
            }
            if ($cause instanceof \PDOException && ('40001' === (string) $cause->getCode() || 1213 === ($cause->errorInfo[1] ?? null))) {
                return true;
            }
        }

        return false;
    }
}
