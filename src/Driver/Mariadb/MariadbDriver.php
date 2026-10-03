<?php

declare(strict_types=1);

namespace Quazardous\GramPHP\Driver\Mariadb;

use Quazardous\GramPHP\Driver\CoreDriver;
use Quazardous\GramPHP\Driver\NodeTimes;
use Quazardous\GramPHP\Driver\ProgressMany;
use Quazardous\GramPHP\Driver\ReadingDriver;
use Quazardous\GramPHP\Entry;
use Quazardous\GramPHP\Keyed;
use Quazardous\GramPHP\Reason;
use Quazardous\GramPHP\Status;
use Quazardous\GramPHP\Subject;
use Quazardous\GramPHP\Time;

/**
 * THE MARIADB DRIVER — node rows in InnoDB tables the application declares,
 * over a PDO connection. Also runs on MySQL 8.
 *
 *     new MariadbDriver($pdo, subjectType: 'int')
 *
 * THE TABLES ARE THE APPLICATION'S: `schema()` returns the DDL the driver
 * expects — node rows (`(subject, node)` primary key), revisions (with each
 * subject's policy and pinned version), the history (an auto-increment id
 * gives back rows archived in one second in the order they were written),
 * and the limits table, whose rows `guard` locks.
 *
 * READ COMMITTED, IN A TRANSACTION. The driver never commits, and it REFUSES
 * what would void its guarantees:
 *
 *     no transaction    each statement its own transaction: the locks and
 *                       the writes made of several statements hold nothing.
 *     REPEATABLE READ   InnoDB's default. A search that finds nothing takes a
 *                       gap lock there; two claimers of one new subject each
 *                       hold one and block the other's insert — a deadlock,
 *                       on the most ordinary race. Under READ COMMITTED
 *                       searches take no gap lock.
 *
 * Open the connection with `SET SESSION TRANSACTION ISOLATION LEVEL READ
 * COMMITTED` and call `beginTransaction()` around each journal call.
 *
 * HOW `insertIfUnchanged` STAYS HONEST: per subject, the revision row is read
 * with a shared lock (`LOCK IN SHARE MODE`), and the node row is written only
 * if it still carries the revision read. A `forget` raises the revision
 * first, holding the row's exclusive lock to its commit: a claim reaching the
 * revision after waits, then finds it moved; a claim before holds its shared
 * lock until its own commit, and the forget's deletion that follows sees its
 * row.
 *
 * SUBJECTS COME BACK AS GIVEN: `subjectType` is the type of your ids, `'int'`
 * (a BIGINT column) or `'string'` (VARCHAR), and every subject read from the
 * tables is returned in that type.
 */
final class MariadbDriver implements CoreDriver, ReadingDriver, NodeTimes, ProgressMany
{
    /** Values bound per statement, at most. */
    private const CHUNK = 500;

    private const COLUMNS = 'node, status, started_at, finished_at, lease';

    private bool $checked = false;

    /** @param 'int'|'string' $subjectType */
    public function __construct(
        private readonly \PDO $pdo,
        private readonly string $subjectType = 'string',
        private readonly string $table = 'grampy_nodes',
        private readonly string $revisions = 'grampy_revisions',
        private readonly string $history = 'grampy_history',
        private readonly string $limits = 'grampy_limits',
        private readonly string $subject = 'subject',
    ) {
        foreach ([$table, $revisions, $history, $limits, $subject] as $identifier) {
            self::checkIdentifier($identifier);
        }
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    }

    /**
     * The `CREATE TABLE` statements the driver expects, InnoDB.
     *
     * @param 'int'|'string' $subjectType
     *
     * @return list<string>
     */
    public static function schema(
        string $subjectType = 'string',
        string $table = 'grampy_nodes',
        string $revisions = 'grampy_revisions',
        string $history = 'grampy_history',
        string $limits = 'grampy_limits',
        string $subject = 'subject',
    ): array {
        foreach ([$table, $revisions, $history, $limits, $subject] as $identifier) {
            self::checkIdentifier($identifier);
        }
        $s = $subject . ' ' . ('int' === $subjectType ? 'BIGINT' : 'VARCHAR(255)') . ' NOT NULL';
        $time = 'VARCHAR(32)';
        $name = 'VARCHAR(191)';

        return [
            "CREATE TABLE IF NOT EXISTS {$table} ({$s}, node {$name} NOT NULL, status VARCHAR(32) NOT NULL, "
            . "started_at {$time} NOT NULL, finished_at {$time}, lease VARCHAR(191), "
            . "PRIMARY KEY ({$subject}, node), KEY (node, status, started_at)) ENGINE=InnoDB",
            "CREATE TABLE IF NOT EXISTS {$revisions} ({$s} PRIMARY KEY, revision INT NOT NULL, "
            . 'policy VARCHAR(191), version VARCHAR(191)) ENGINE=InnoDB',
            "CREATE TABLE IF NOT EXISTS {$history} (id BIGINT AUTO_INCREMENT PRIMARY KEY, {$s}, "
            . "node {$name} NOT NULL, status VARCHAR(32) NOT NULL, started_at {$time} NOT NULL, "
            . "finished_at {$time}, lease VARCHAR(191), archived_at {$time} NOT NULL, "
            . "reason VARCHAR(32) NOT NULL, KEY ({$subject}, node, reason, archived_at), KEY (archived_at)) ENGINE=InnoDB",
            "CREATE TABLE IF NOT EXISTS {$limits} (`key` VARCHAR(191) NOT NULL PRIMARY KEY, value DOUBLE) ENGINE=InnoDB",
        ];
    }

    public function now(): string
    {
        return self::text($this->run('SELECT DATE_FORMAT(UTC_TIMESTAMP(), ?)', ['%Y-%m-%dT%H:%i:%s+00:00'])->fetchColumn());
    }

    // -- read ------------------------------------------------------------

    /** No pre-filter: the journal judges every candidate. */
    public function scan(mixed $candidates, ?string $name, array $nodes, array $parents, int $page, ?string $now, array $after = []): iterable
    {
        $batch = [];
        foreach ($this->candidates($candidates) as [$subject, $key]) {
            $batch[Subject::key($subject)] = [$subject, $key];
            if (\count($batch) >= $page) {
                yield $this->entries($batch, $nodes);
                $batch = [];
            }
        }
        if ([] !== $batch) {
            yield $this->entries($batch, $nodes);
        }
    }

    public function progress(int|string $subject): array
    {
        return $this->progressMany([$subject])[Subject::key($subject)] ?? [];
    }

    public function progressMany(array $subjects): array
    {
        $out = [];
        foreach (array_chunk($subjects, self::CHUNK) as $chunk) {
            $rows = $this->rows(
                "SELECT {$this->subject}, node, status FROM {$this->table} WHERE {$this->subject} IN (" . self::marks($chunk) . ')',
                $chunk,
            );
            foreach ($rows as [$subject, $node, $status]) {
                $out[Subject::key($this->cast($subject))][self::text($node)] = self::text($status);
            }
        }

        return $out;
    }

    public function history(int|string $subject): array
    {
        $out = [];
        foreach ($this->rows(
            'SELECT ' . self::COLUMNS . ", archived_at, reason FROM {$this->history} "
            . "WHERE {$this->subject} = ? ORDER BY archived_at, node, id",
            [$subject],
        ) as [$node, $status, $started, $ended, $lease, $archived, $reason]) {
            $out[] = [
                'node' => self::text($node),
                'status' => self::text($status),
                'started_at' => self::text($started),
                'finished_at' => self::textOrNull($ended),
                'lease' => self::textOrNull($lease),
                'archived_at' => self::text($archived),
                'reason' => self::text($reason),
            ];
        }

        return $out;
    }

    public function archived(array $subjects, string $name, string $reason): array
    {
        $out = [];
        foreach (array_chunk($subjects, self::CHUNK) as $chunk) {
            $rows = $this->rows(
                "SELECT {$this->subject}, COUNT(*) FROM {$this->history} WHERE node = ? AND reason = ? "
                . "AND {$this->subject} IN (" . self::marks($chunk) . ") GROUP BY {$this->subject}",
                [$name, $reason, ...$chunk],
            );
            foreach ($rows as [$subject, $count]) {
                $out[Subject::key($this->cast($subject))] = self::integer($count);
            }
        }

        return $out;
    }

    public function latest(array $subjects, string $name, ?string $reason): array
    {
        $out = [];
        foreach (array_chunk($subjects, self::CHUNK) as $chunk) {
            $sql = "SELECT {$this->subject}, MAX(archived_at) FROM {$this->history} WHERE node = ? "
                . "AND {$this->subject} IN (" . self::marks($chunk) . ')';
            $params = [$name, ...$chunk];
            if (null !== $reason) {
                $sql .= ' AND reason = ?';
                $params[] = $reason;
            }
            foreach ($this->rows($sql . " GROUP BY {$this->subject}", $params) as [$subject, $at]) {
                $out[Subject::key($this->cast($subject))] = self::text($at);
            }
        }

        return $out;
    }

    public function policies(array $subjects): array
    {
        $out = [];
        foreach (array_chunk($subjects, self::CHUNK) as $chunk) {
            $rows = $this->rows(
                "SELECT {$this->subject}, policy FROM {$this->revisions} WHERE {$this->subject} IN ("
                . self::marks($chunk) . ') AND policy IS NOT NULL',
                $chunk,
            );
            foreach ($rows as [$subject, $policy]) {
                $out[Subject::key($this->cast($subject))] = self::text($policy);
            }
        }

        return $out;
    }

    public function statusCounts(string $name): array
    {
        $out = [];
        foreach ($this->rows("SELECT status, COUNT(*) FROM {$this->table} WHERE node = ? GROUP BY status", [$name]) as [$status, $count]) {
            $out[self::text($status)] = self::integer($count);
        }

        return $out;
    }

    public function nodeTimes(string $name): array
    {
        $out = [];
        $rows = $this->rows(
            "SELECT status, MIN(started_at) FROM {$this->table} WHERE node = ? AND status IN (?, ?) GROUP BY status",
            [$name, Status::Running->value, Status::Scheduled->value],
        );
        foreach ($rows as [$status, $at]) {
            $out[self::text($status)] = self::text($at);
        }

        return $out;
    }

    public function stages(array $subjects, string $at): array
    {
        $lines = [];
        foreach (array_chunk($subjects, self::CHUNK) as $chunk) {
            $rows = $this->rows(
                "SELECT COALESCE(finished_at, ?), node, {$this->subject}, started_at, status FROM {$this->table} "
                . "WHERE status NOT IN (?, ?, ?) AND {$this->subject} IN (" . self::marks($chunk) . ')',
                [$at, Status::Skipped->value, Status::Omitted->value, Status::Scheduled->value, ...$chunk],
            );
            foreach ($rows as [$ended, $node, $subject, $started, $status]) {
                $lines[] = [self::text($ended), self::text($node), (string) $this->cast($subject), self::text($started), self::text($status)];
            }
        }
        usort($lines, static fn(array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        $out = [];
        foreach ($lines as [$ended, $node, $subject, $started, $status]) {
            $out[$subject][] = [$node, $ended, round(Time::epoch($ended) - Time::epoch($started), 1), $status];
        }

        return $out;
    }

    public function parentsConcluded(array $parents, int|string $subject): bool
    {
        if ([] === $parents) {
            return true;
        }
        $satisfying = Status::satisfying();
        $found = $this->run(
            "SELECT COUNT(*) FROM {$this->table} WHERE {$this->subject} = ? AND node IN (" . self::marks($parents)
            . ') AND status IN (' . self::marks($satisfying) . ')',
            [$subject, ...$parents, ...$satisfying],
        )->fetchColumn();

        return self::integer($found) === \count($parents);
    }

    // -- write -----------------------------------------------------------

    /** Per subject, in one order: the revision read with a shared lock, then the row written. */
    public function insertIfUnchanged(string $name, array $entries, string $status, string $now, ?string $lease): array
    {
        $this->requireTransaction();
        $finished = Status::Running->value === $status ? null : $now;
        usort($entries, static fn(array $a, array $b): int => strcmp((string) $a[0], (string) $b[0]));
        $taken = [];
        foreach ($entries as [$subject, $revision]) {
            $this->run("INSERT IGNORE INTO {$this->revisions} ({$this->subject}, revision) VALUES (?, 0)", [$subject]);
            $current = $this->run(
                "SELECT revision FROM {$this->revisions} WHERE {$this->subject} = ? LOCK IN SHARE MODE",
                [$subject],
            )->fetchColumn();
            if (self::integer($current) !== $revision) {
                continue;
            }
            // The due retry first: an UPDATE locks the row it changes and
            // re-reads it; a row that is not a due retry is left untouched.
            $updated = $this->run(
                "UPDATE {$this->table} SET status = ?, started_at = ?, finished_at = ?, lease = ? "
                . "WHERE {$this->subject} = ? AND node = ? AND status = ? AND started_at <= ?",
                [$status, $now, $finished, $lease, $subject, $name, Status::Scheduled->value, $now],
            )->rowCount();
            if (1 === $updated) {
                $taken[] = $subject;
                continue;
            }
            $inserted = $this->run(
                "INSERT IGNORE INTO {$this->table} ({$this->subject}, " . self::COLUMNS . ') VALUES (?, ?, ?, ?, ?, ?)',
                [$subject, $name, $status, $now, $finished, $lease],
            )->rowCount();
            if (1 === $inserted) {
                $taken[] = $subject;
            }
        }

        return $taken;
    }

    public function conclude(string $name, array $subjects, string $status, string $now, ?string $lease, array $omit, array $reset, ?string $reschedule): int
    {
        $this->requireTransaction();
        $count = 0;
        foreach ($subjects as $subject) {
            $sql = "UPDATE {$this->table} SET status = ?, finished_at = ? WHERE node = ? AND status = ? AND {$this->subject} = ?";
            $params = [$status, $now, $name, Status::Running->value, $subject];
            if (null !== $lease) {
                $sql .= ' AND lease = ?';
                $params[] = $lease;
            }
            if (1 !== $this->run($sql, $params)->rowCount()) {
                continue;
            }
            ++$count;
            foreach ($omit as $other) {
                $this->run(
                    "INSERT IGNORE INTO {$this->table} ({$this->subject}, node, status, started_at, finished_at) VALUES (?, ?, ?, ?, ?)",
                    [$subject, $other, Status::Omitted->value, $now, $now],
                );
            }
            if ([] !== $reset) {
                $this->raiseRevision($subject);
                foreach ($reset as $other) {
                    $this->takeAway("node = ? AND {$this->subject} = ?", [$other, $subject], $now, Reason::Loop->value);
                }
            }
            if (null !== $reschedule) {
                $this->takeAway("node = ? AND {$this->subject} = ?", [$name, $subject], $now, Reason::Retry->value);
                $this->run(
                    "INSERT INTO {$this->table} ({$this->subject}, node, status, started_at) VALUES (?, ?, ?, ?)",
                    [$subject, $name, Status::Scheduled->value, $reschedule],
                );
            }
        }

        return $count;
    }

    public function adopt(string $name, array $subjects, string $now): int
    {
        $this->requireTransaction();
        $count = 0;
        foreach ($subjects as $subject) {
            $count += $this->run(
                "INSERT IGNORE INTO {$this->table} ({$this->subject}, node, status, started_at, finished_at) VALUES (?, ?, ?, ?, ?)",
                [$subject, $name, Status::Done->value, $now, $now],
            )->rowCount();
        }

        return $count;
    }

    /** Revision first — its exclusive lock held to the commit — then the rows. */
    public function forget(string $name, array $subjects, string $now): int
    {
        $this->requireTransaction();
        usort($subjects, static fn(int|string $a, int|string $b): int => strcmp((string) $a, (string) $b));
        $count = 0;
        foreach ($subjects as $subject) {
            $this->raiseRevision($subject);
            $count += $this->takeAway("node = ? AND {$this->subject} = ?", [$name, $subject], $now, Reason::Forget->value);
        }

        return $count;
    }

    public function release(string $name, string $olderThan, string $now, ?array $only = null, array $exclude = [], ?string $version = null): int
    {
        $this->requireTransaction();
        $where = 'node = ? AND status = ? AND started_at < ?';
        $params = [$name, Status::Running->value, $olderThan];
        if (null !== $only) {
            $where .= " AND {$this->subject} IN (SELECT {$this->subject} FROM {$this->revisions} WHERE policy IN ("
                . ([] === $only ? 'NULL' : self::marks($only)) . '))';
            array_push($params, ...$only);
        }
        if ([] !== $exclude) {
            $where .= " AND {$this->subject} NOT IN (SELECT {$this->subject} FROM {$this->revisions} WHERE policy IN ("
                . self::marks($exclude) . '))';
            array_push($params, ...$exclude);
        }
        if (null !== $version) {
            $where .= " AND {$this->subject} NOT IN (SELECT {$this->subject} FROM {$this->revisions} "
                . 'WHERE version IS NOT NULL AND version != ?)';
            $params[] = $version;
        }

        return $this->takeAway($where, $params, $now, Reason::Release->value);
    }

    public function enroll(array $subjects, ?string $policy): int
    {
        $this->requireTransaction();
        foreach ($subjects as $subject) {
            $this->run(
                "INSERT INTO {$this->revisions} ({$this->subject}, revision, policy) VALUES (?, 0, ?) ON DUPLICATE KEY UPDATE policy = ?",
                [$subject, $policy, $policy],
            );
        }

        return \count($subjects);
    }

    public function note(array $subjects, string $name, string $status, string $reason, string $now, ?string $ref): int
    {
        $this->requireTransaction();
        foreach ($subjects as $subject) {
            $this->run(
                "INSERT INTO {$this->history} ({$this->subject}, " . self::COLUMNS . ', archived_at, reason) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [$subject, $name, $status, $now, $now, $ref, $now, $reason],
            );
        }

        return \count($subjects);
    }

    public function pruneHistory(string $before, array $keep): int
    {
        $this->requireTransaction();

        return $this->run(
            "DELETE FROM {$this->history} WHERE archived_at < ? AND reason NOT IN (" . ([] === $keep ? 'NULL' : self::marks($keep)) . ')',
            [$before, ...$keep],
        )->rowCount();
    }

    /**
     * One row per key in the limits table, locked `FOR UPDATE` in one order:
     * held to the end of the caller's transaction, as a transaction-scoped
     * lock is (`GET_LOCK` would live with the session instead).
     */
    public function guard(array $keys, callable $block): mixed
    {
        $this->requireTransaction();
        $wanted = array_values(array_unique($keys));
        sort($wanted, \SORT_STRING);
        if ([] !== $wanted) {
            foreach ($wanted as $key) {
                $this->run("INSERT IGNORE INTO {$this->limits} (`key`, value) VALUES (?, NULL)", [$key]);
            }
            $this->run(
                "SELECT `key` FROM {$this->limits} WHERE `key` IN (" . self::marks($wanted) . ') ORDER BY `key` FOR UPDATE',
                $wanted,
            )->fetchAll();
        }

        return $block();
    }

    // -- inside ----------------------------------------------------------

    /** @return iterable<array{0: int|string, 1: ?string}> */
    private function candidates(mixed $candidates): iterable
    {
        if ($candidates instanceof Query) {
            $statement = $this->run($candidates->sql, $candidates->params);
            $keyAt = null;
            for ($i = 0; $i < $statement->columnCount(); ++$i) {
                $meta = $statement->getColumnMeta($i);
                if (false !== $meta && 'grampy_key' === $meta['name']) {
                    $keyAt = $i;
                }
            }
            foreach (self::listRows($statement) as $row) {
                $key = null === $keyAt || null === $row[$keyAt] ? null : self::text($row[$keyAt]);
                yield [$this->cast($row[0]), $key];
            }

            return;
        }
        if (!is_iterable($candidates)) {
            throw new \InvalidArgumentException('candidates: a Query, or an ordered iterable of subjects');
        }
        foreach ($candidates as $candidate) {
            if ($candidate instanceof Keyed) {
                yield [$candidate->subject, $candidate->key];
            } elseif (\is_int($candidate) || \is_string($candidate)) {
                yield [$candidate, null];
            } else {
                throw new \InvalidArgumentException(\sprintf(
                    'candidate %s: a subject is an int or a string; a grouping key comes as new Keyed(subject, key)',
                    get_debug_type($candidate),
                ));
            }
        }
    }

    /**
     * @param array<string, array{0: int|string, 1: ?string}> $batch Subject::key() => [subject, grouping key]
     * @param list<string>                                    $nodes
     *
     * @return list<Entry>
     */
    private function entries(array $batch, array $nodes): array
    {
        $rows = $due = $finished = $revisions = $policies = $versions = [];
        $subjects = array_values(array_map(static fn(array $pair): int|string => $pair[0], $batch));
        foreach (array_chunk($subjects, self::CHUNK) as $chunk) {
            // THE REVISION BEFORE THE ROWS: a forget committing between the two
            // reads leaves rows the claim refuses, or a revision its write does —
            // never a parent since forgotten paired with the revision raised by
            // forgetting it.
            $read = $this->rows(
                "SELECT {$this->subject}, revision, policy, version FROM {$this->revisions} WHERE {$this->subject} IN (" . self::marks($chunk) . ')',
                $chunk,
            );
            foreach ($read as [$subject, $revision, $policy, $version]) {
                $key = Subject::key($this->cast($subject));
                $revisions[$key] = self::integer($revision);
                $policies[$key] = self::textOrNull($policy);
                $versions[$key] = self::textOrNull($version);
            }
            $read = $this->rows(
                "SELECT {$this->subject}, node, status, started_at, finished_at FROM {$this->table} "
                . "WHERE {$this->subject} IN (" . self::marks($chunk) . ') AND node IN (' . self::marks($nodes) . ')',
                [...$chunk, ...$nodes],
            );
            foreach ($read as [$subject, $node, $status, $started, $ended]) {
                $key = Subject::key($this->cast($subject));
                $node = self::text($node);
                $rows[$key][$node] = self::text($status);
                if (Status::Scheduled->value === $status) {
                    $due[$key][$node] = self::text($started);
                }
                if (null !== $ended) {
                    $finished[$key][$node] = self::text($ended);
                }
            }
        }
        $out = [];
        foreach ($batch as $key => [$subject, $groupKey]) {
            $out[] = new Entry(
                $subject,
                $revisions[$key] ?? 0,
                $rows[$key] ?? [],
                $due[$key] ?? [],
                $finished[$key] ?? [],
                $policies[$key] ?? null,
                $versions[$key] ?? null,
                $groupKey,
            );
        }

        return $out;
    }

    private function raiseRevision(int|string $subject): void
    {
        $this->run(
            "INSERT INTO {$this->revisions} ({$this->subject}, revision) VALUES (?, 1) ON DUPLICATE KEY UPDATE revision = revision + 1",
            [$subject],
        );
    }

    /**
     * Archive, then delete: the rows locked by the first statement (`FOR
     * UPDATE`), so the second deletes exactly what was archived.
     *
     * @param list<mixed> $params
     */
    private function takeAway(string $where, array $params, string $now, string $reason): int
    {
        $rows = $this->rows(
            "SELECT {$this->subject}, " . self::COLUMNS . " FROM {$this->table} WHERE {$where} FOR UPDATE",
            $params,
        );
        if ([] === $rows) {
            return 0;
        }
        $insert = $this->pdo->prepare(
            "INSERT INTO {$this->history} ({$this->subject}, " . self::COLUMNS . ', archived_at, reason) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        );
        foreach ($rows as $row) {
            $insert->execute([...$row, $now, $reason]);
        }

        return $this->run("DELETE FROM {$this->table} WHERE {$where}", $params)->rowCount();
    }

    /** Checked once per driver, before its first write. */
    private function requireTransaction(): void
    {
        if ($this->checked) {
            return;
        }
        if (!$this->pdo->inTransaction()) {
            throw new \LogicException(
                'the journal runs outside a transaction: each statement would be its own transaction, '
                . 'which voids every lock and every write of several statements it relies on. '
                . 'Call beginTransaction() around the journal call.',
            );
        }
        try {
            $level = self::text($this->run('SELECT @@SESSION.transaction_isolation')->fetchColumn());
        } catch (\PDOException) {
            $level = self::text($this->run('SELECT @@SESSION.tx_isolation')->fetchColumn());
        }
        if ('READ-COMMITTED' !== strtoupper(str_replace('_', '-', $level))) {
            throw new \LogicException(\sprintf(
                'the journal needs READ COMMITTED, and this session runs %s: under REPEATABLE READ, '
                . "InnoDB's gap locks deadlock two claimers of one new subject. Run "
                . '"SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED" when the connection opens.',
                $level,
            ));
        }
        $this->checked = true;
    }

    /**
     * The rows of a statement, each a list of its columns.
     *
     * @param list<mixed> $params
     *
     * @return list<list<mixed>>
     */
    private function rows(string $sql, array $params = []): array
    {
        return self::listRows($this->run($sql, $params));
    }

    /** @return list<list<mixed>> */
    private static function listRows(\PDOStatement $statement): array
    {
        $out = [];
        foreach ($statement->fetchAll(\PDO::FETCH_NUM) as $row) {
            $out[] = \is_array($row) ? array_values($row) : [];
        }

        return $out;
    }

    private static function text(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : throw new \UnexpectedValueException('a non-scalar value read from the database');
    }

    private static function textOrNull(mixed $value): ?string
    {
        return null === $value ? null : self::text($value);
    }

    private static function integer(mixed $value): int
    {
        return \is_numeric($value) ? (int) $value : throw new \UnexpectedValueException('a non-numeric value read from the database');
    }

    /** @param list<mixed> $params */
    private function run(string $sql, array $params = []): \PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        return $statement;
    }

    private function cast(mixed $subject): int|string
    {
        if ('int' === $this->subjectType) {
            return \is_numeric($subject) ? (int) $subject : throw new \UnexpectedValueException('a non-integer subject in an int subject column');
        }

        return \is_scalar($subject) ? (string) $subject : throw new \UnexpectedValueException('a non-scalar subject');
    }

    /** @param array<mixed> $items */
    private static function marks(array $items): string
    {
        return implode(', ', array_fill(0, \count($items), '?'));
    }

    private static function checkIdentifier(string $name): void
    {
        if (1 !== preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
            throw new \InvalidArgumentException(\sprintf('not a plain SQL identifier: %s', var_export($name, true)));
        }
    }
}
