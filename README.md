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

## Limits and groups

What a node uses is protected where the claim is decided:

```php
use Quazardous\GramPHP\Group;
use Quazardous\GramPHP\Per;
use Quazardous\GramPHP\Rate;

new Node('call', rate: [new Rate(100, '1m'), new Rate(1000, '1h')]);   // bands, all apply
new Node('gpu', concurrency: 3);                                         // at most 3 running
new Node('api', concurrency: 1, per: Per::Policy);                       // one budget per policy
new Node('pack', parents: ['sort'], group: new Group(5, maxWait: '1h')); // five of a key, one lease
```

A rate band is a generic cell rate algorithm: `limit` per `period`, spread
evenly, up to `burst` at once. A claim takes no more than the bands and the
cap let through; under contention it takes fewer, never too many — the
driver's `guard` holds the read and the write together. A grouping claim
hands out a whole group of subjects sharing a key (`grampy_key` in SQL,
`Keyed` otherwise) or nothing; past `maxWait` an incomplete group goes as it
is. A lane's door honours its node's `rate` too.

## Lanes: subjects that come back

A subject often comes back — a listing updated again, a file re-uploaded.
A **lane** is the node it comes back through: it waits there, merges with
the version already waiting, and `settle` lets it in once due, archiving the
previous pass in the same write.

```php
use Quazardous\GramPHP\Lane;

$journal = new NodeJournal($driver, new Dag(
    new Node('arrive', lane: Lane::throttle(cooldown: '1h')),
    new Node('scrape', parents: ['arrive']),
    new Node('publish', parents: ['scrape']),
));

$journal->arrive('arrive', [42], ref: 'v7');     // ['queued' => 1, 'merged' => 0, 'skipped' => 0]
$journal->settle($candidates);                   // ['arrive' => ['entered' => 1]] once due
```

| preset | keeps | place | under the names other tools gave it |
|---|---|---|---|
| `Lane::throttle($cooldown)` | the last ref | the first's | throttle |
| `Lane::debounce($delay)` | the last ref | to the back | debounce |
| `Lane::dedupe()` | the first ref | the first's | dedupe |
| `Lane::batch(maxSize: 100)` | every ref, in order | the first's | aggregator |

`maxWait` bounds the wait whatever the rest, `whileRunning: WhileRunning::Skip`
drops an arrival while a pass runs, `urgent: true` skips the cooldown — never
a running pass. A lane may also merge with a function of yours, named in the
graph (`Merge::fn('ends')`) and given to the journal (`mergers: ['ends' => $fn]`).
Every merge, drop and entry is noted in the history.

## Items: speak objects, not ids

The core works on ids and knows nothing about your data. The optional items
layer holds the handlers that read your own objects and turns their answers
into calls the journal already understands:

```php
use Quazardous\GramPHP\Items\Adapter;
use Quazardous\GramPHP\Items\Items;

final class Orders extends Adapter
{
    public function idOf(mixed $candidate): int|string
    {
        return $candidate instanceof Order ? $candidate->id : $candidate;
    }

    public function inflate(array $candidates): iterable   // ids in, orders out, in one call
    {
        $ids = array_filter($candidates, is_int(...));
        return [...array_diff_key($candidates, $ids), ...$this->repository->findByIds($ids)];
    }

    public function policyOf(mixed $order): ?string { return $order->plan; }
    public function branch(mixed $order, string $node): ?string { return $order->digital ? 'mail' : 'ship'; }
    public function applies(mixed $order, string $node): bool { return 'gift-wrap' !== $node || $order->gift; }
}

$items = new Items($journal, new Orders($repository));
$lease = $items->claim('pay', 10, $orders);      // your objects in — or ids, or a Query
foreach ($lease as $order) {                     // your objects out
    // …
}
$items->conclude('pay', $lease);                 // the token travels with the lease
```

Objects handed in travel with the claim and are never loaded twice; ids —
and a driver's `Query` — are loaded with `inflate`, once per call, and an id
nothing loads lands in `$lease->missing`. `applies()` gives an optional node
up (concluded `skipped`) for the items it is not for; `branch()` names the
way out of a choice. The layer translates and stops there: everything it
does is a call the id-based API could have made by hand.

## Monitoring

`$journal->snapshot($candidates)` gives, per node, plain numbers to sample
and alert on: the count per status, `oldest_running` (a stuck worker past
its lease), `next_due` (negative: retries overdue), `ready` (how many a claim
could take now) and `oldest_ready` (starvation).

## Status of the port

Ported so far: the graph and its claim rule, joins (`on`, `need`), choices,
loops, retries, leases, waits and signals, grace, skip, adopt, forget,
release, the history, counts, stages and snapshots, rate limits,
concurrency caps and groups, lanes (throttle, debounce, dedupe, batch, merge
functions) and the items layer — with the
memory and MariaDB drivers, both certified by the shared contract,
concurrency included.

Still to port from grampy: policies (a policy tuning a node's retry, lease,
grace, rate, concurrency or lane), graph versions and migration, and the
diagram.

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
