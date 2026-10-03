# gramphp

A small workflow graph for work queues that already live in a storage — a
database, or plain memory: the storage is a driver.

A PHP port of [grampy](https://github.com/quazardous/grampy).

```bash
composer require quazardous/gramphp
```

You declare a DAG of nodes. Each *subject* (a job, an order, a feed…) goes
through the nodes; a **node journal** records, per subject and per node,
whether the node is `running`, `scheduled`, `done`, `skipped`, `failed` or
`omitted`. Workers **claim** a node for eligible subjects, **conclude** it,
and the graph decides what becomes claimable next — forks run in parallel,
joins wait for their parents.

```php
use Quazardous\GramPHP\Dag;
use Quazardous\GramPHP\Driver\Memory\MemoryDriver;
use Quazardous\GramPHP\Node;
use Quazardous\GramPHP\NodeJournal;
use Quazardous\GramPHP\Retry;
use Quazardous\GramPHP\Status;

$journal = new NodeJournal(new MemoryDriver(), new Dag(
    new Node('pay'),
    new Node('ship', parents: ['pay'], retry: new Retry(limit: 3, delay: '30s')),
));

$lease = $journal->claim('pay', 10, candidates: [1, 2, 3]);   // your subjects, in priority order
foreach ($lease as $order) {
    // … pay the order …
}
$journal->conclude('pay', $lease, $lease->token);

$journal->progress(1);                                        // ['pay' => Status::Done]
$journal->claim('ship', 10, candidates: [1, 2, 3]);           // pay is done: ship is next
```

## Why not a status column?

A `status` column and a few `UPDATE`s hold one line of steps, one worker at a
time. What gramphp adds, each proven by the shared driver contract:

- **Two workers, one subject.** A claim is atomic and returns a token; a slow
  worker whose lease went to another writes nothing (a fencing token).
- **A graph, not a line.** Forks run in parallel, joins wait for their
  parents, optional steps and exclusive choices do not block what follows,
  a failure edge can run a compensation.
- **Never backwards.** A node is never taken again once a descendant has
  started; going back is `forget`, and nothing is lost: every row taken away
  is archived in the history.
- **Time is declared.** Retries with backoff and jitter, leases released by a
  janitor (`expire`), waits on signals received even before the wait began,
  optional nodes skipped after a grace delay.
- **One rule, one place.** What is claimable is decided by the journal, in
  PHP, on rows the driver read — never re-expressed in SQL. A driver stores
  rows and offers a few atomic operations.

## Drivers

| driver | storage | concurrency |
|---|---|---|
| `Driver\Memory\MemoryDriver` | arrays, one process | the reference; for tests and single-process use |
| `Driver\Mariadb\MariadbDriver` | InnoDB tables over PDO or Doctrine DBAL (MariaDB, MySQL 8) | READ COMMITTED, in the caller's transaction |

```php
use Quazardous\GramPHP\Driver\Mariadb\MariadbDriver;
use Quazardous\GramPHP\Driver\Mariadb\Query;

foreach (MariadbDriver::schema(subjectType: 'int') as $ddl) {
    $pdo->exec($ddl);                             // the tables are yours
}
$pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
$journal = new NodeJournal(new MariadbDriver($pdo, subjectType: 'int'), $dag);

$pdo->beginTransaction();
$lease = $journal->claim('ship', 50, new Query(
    'SELECT id FROM orders WHERE paid = 1 ORDER BY priority DESC, id',
));
$pdo->commit();
```

The journal never commits: the application opens and ends the transaction
around each call, so a node row and the application's own writes can go in
one transaction.

With Doctrine, share the application's DBAL connection:

```php
use Quazardous\GramPHP\Driver\Mariadb\DbalSql;

$journal = new NodeJournal(new MariadbDriver(new DbalSql($connection), subjectType: 'int'), $dag);
$lease = $connection->transactional(fn() => $journal->claim('ship', 50, $candidates));
```

A transaction mixing several claims and forgets on the same subjects can
meet a deadlock: InnoDB reports it (error 1213, SQLSTATE 40001), rolls the
transaction back, and the caller retries it — nothing is ever half-written.

## Monitoring

`$journal->snapshot($candidates)` gives, per node, plain numbers to sample
and alert on: the count per status, `oldest_running` (a stuck worker past
its lease), `next_due` (negative: retries overdue), `ready` (how many a claim
could take now) and `oldest_ready` (starvation).

## Status of the port

Ported so far: the graph and its claim rule, joins (`on`, `need`), choices,
loops, retries, leases, waits and signals, grace, skip, adopt, forget,
release, the history, counts, stages and snapshots — with the memory and
MariaDB drivers, both certified by the shared contract, concurrency included.

Still to port from grampy: groups, rate limits and concurrency caps,
policies, lanes (throttle, debounce, dedupe, batch), graph versions and
migration, the items layer, and the diagram.

## Development

Everything runs in Docker:

```bash
make build install
make test      # unit, memory and MariaDB contract (concurrency included)
make lint      # PHPStan (max level), PHP-CS-Fixer, composer validate
make check     # what CI runs
```

## License

MIT.
