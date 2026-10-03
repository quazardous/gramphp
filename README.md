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
`Transaction` does exactly that, over PDO or DBAL:

```php
use Quazardous\GramPHP\Driver\Mariadb\Transaction;

$lease = (new Transaction($pdo))->run(fn() => $journal->claim('ship', 50, $candidates));
```

It begins, runs the unit, commits; on a deadlock it runs the unit again from
a fresh transaction, after a short random pause, at most `attempts` times.
Anything else is rolled back and thrown as it came. The unit must be safe to
run again — keep mails and calls after `run` returns.

## Policies: one workflow, several ways of pushing it

Subjects differ in how hard they may be pushed — a slow partner, a bulk
customer. A **policy** changes a node's settings, never its structure:

```php
use Quazardous\GramPHP\Document;
use Quazardous\GramPHP\Graph;

$graph = new Graph(new Document('offers', version: '1'), $dag, policies: [
    'slow-partner' => ['call' => ['retry' => new Retry(5, '1m'), 'lease' => '2h']],
    'bulk' => ['call' => ['rate' => [new Rate(1000, '1h')]]],   // needs per: Per::Policy
]);
$journal = new NodeJournal($driver, $graph);
$journal->enroll($subjects, 'slow-partner');
```

A policy may change `retry`, `lease`, `timeout`, `grace`, `rate`,
`concurrency` and `lane` (tune it, never add or remove one); the graph is
checked under every policy. Each subject is read through its own: its
retries, its lease (`expire`), its timeout and grace (`settle`), its budget
and its lane.

## Versions: a graph that changes under subjects in flight

A journal built on a `Graph` pins each subject, on its first write, to the
graph it started on — the whole `Document::identity()`, `namespace/name@version`.
It never touches a subject pinned elsewhere: version 1 finishes its subjects
while version 2 takes the new ones. To move subjects across, on purpose:

```php
$v2 = new NodeJournal($driver, $graphV2);
$v2->migrate($subjects, $graphV1, ['crop' => 'trim', 'legacy' => null]);   // renamed, dropped
```

A subject moves only if its journal could have been written on the new graph
(every row's parents joined there), and never while a dropped node is held.
All or nothing: one non-compliant subject raises `MigrationError`, naming each
one and why, and nothing moves. Dropped rows go to the history.

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

## The graph as data, and drawn

A graph has one canonical form — grampy's format (`grampy/1`), so the same
file is read and written by the Python and the PHP implementations alike:

```php
file_put_contents('offers.graph.json', $graph->toJson());   // only what differs from a default
$graph = Graph::fromJson(file_get_contents('offers.graph.json'));   // strict: a lie is refused with its path
```

And drawn, every mechanism with a shape of its own, counts overlaid if given:

```php
use Quazardous\GramPHP\Diagram;

echo Diagram::mermaid($graph, Diagram::overlay($journal));   // flowchart, ▶ running ✓ done …
echo Diagram::stateDiagram($graph);                          // the statechart reading
echo Diagram::dot($graph);                                   // Graphviz
```

## Monitoring

`$journal->snapshot($candidates)` gives, per node, plain numbers to sample
and alert on: the count per status, `oldest_running` (a stuck worker past
its lease), `next_due` (negative: retries overdue), `ready` (how many a claim
could take now) and `oldest_ready` (starvation).

## Status of the port

Ported: the graph and its claim rule, joins (`on`, `need`), choices, loops,
retries, leases, waits and signals, grace, skip, adopt, forget, release, the
history, counts, stages and snapshots, rate limits, concurrency caps and
groups, policies, versions and migration, lanes (throttle, debounce, dedupe,
batch, merge functions), the items layer, the graph's JSON form and the
diagrams — with the memory and MariaDB drivers, both certified by the shared
contract, concurrency included.

Every mechanism grampy's graph declares runs here. Not ported: grampy's
SQLite and PostgreSQL drivers, and the optional one-statement fast paths a
driver may offer (`skip_where`, `ready_count`).

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
