<?php

declare(strict_types=1);

namespace Quazardous\GramPHP\Tests\Driver;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Quazardous\GramPHP\Driver\Mariadb\DbalSql;
use Quazardous\GramPHP\Driver\Mariadb\PdoSql;
use Quazardous\GramPHP\Driver\Mariadb\Sql;

/**
 * A test connection, over PDO or Doctrine DBAL: what the harness needs to
 * create tables, seed rows and drive transactions — and the `Sql` the driver
 * runs on.
 */
final class TestDb
{
    private function __construct(
        private readonly ?\PDO $pdo,
        private readonly ?Connection $dbal,
    ) {}

    /** @param 'pdo'|'dbal' $mode */
    public static function connect(string $mode, string $dsn, string $user, string $password): self
    {
        if ('dbal' === $mode) {
            $params = ['driver' => 'pdo_mysql', 'user' => $user, 'password' => $password];
            foreach (explode(';', (string) preg_replace('/^mysql:/', '', $dsn)) as $part) {
                [$name, $value] = array_pad(explode('=', $part, 2), 2, '');
                match ($name) {
                    'host' => $params['host'] = $value,
                    'port' => $params['port'] = (int) $value,
                    'dbname' => $params['dbname'] = $value,
                    'charset' => $params['charset'] = $value,
                    default => null,
                };
            }
            $db = new self(null, DriverManager::getConnection($params));
        } else {
            $db = new self(new \PDO($dsn, $user, $password, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]), null);
        }
        $db->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');

        return $db;
    }

    public function sql(): Sql
    {
        return null !== $this->dbal ? new DbalSql($this->dbal) : new PdoSql($this->pdo ?? throw new \LogicException('no connection'));
    }

    /** @param list<mixed> $params */
    public function exec(string $sql, array $params = []): void
    {
        if (null !== $this->dbal) {
            $this->dbal->executeStatement($sql, $params);

            return;
        }
        $statement = ($this->pdo ?? throw new \LogicException('no connection'))->prepare($sql);
        $statement->execute($params);
    }

    public function begin(): void
    {
        null !== $this->dbal ? $this->dbal->beginTransaction() : $this->pdo?->beginTransaction();
    }

    public function commit(): void
    {
        null !== $this->dbal ? $this->dbal->commit() : $this->pdo?->commit();
    }

    public function rollback(): void
    {
        null !== $this->dbal ? $this->dbal->rollBack() : $this->pdo?->rollBack();
    }

    public function inTransaction(): bool
    {
        return null !== $this->dbal ? $this->dbal->isTransactionActive() : (bool) $this->pdo?->inTransaction();
    }
}
