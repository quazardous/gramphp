# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project
follows [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added

- The graph (`Dag`, `Node`) and its pure claim rule: parents concluded,
  nobody holding, no descendant started; `check()` refuses a graph that does
  not hold together.
- Joins as data: `on` (a failure edge), `need` (k of n), exclusive `choice`,
  bounded `Loop`.
- The node journal: `claim` with a fencing token, `conclude`, `fail`,
  `skip`, `adopt`, `forget`, `release`, `expire`, `enroll`, `signal`,
  `settle`, `history`, `pruneHistory`, `progress`, `counts`, `stages`,
  `snapshot`.
- Declared retries (`Retry`: constant, linear or exponential backoff, cap,
  jitter), leases, waits on signals with a timeout, grace on optional nodes.
- The memory driver and the MariaDB driver (READ COMMITTED), both
  certified by the shared driver contract, concurrency included.
- The MariaDB driver runs over PDO or over a Doctrine DBAL connection
  (`DbalSql`), so a Symfony application can share its own connection and
  transaction; the contract runs on both.
- Versions (`VersionDriver`): a journal on a `Graph` pins each subject to
  its `Document::identity()` on its first write and never touches a subject
  pinned elsewhere; `pinned()`; `migrate()` moves subjects to another version,
  renaming and dropping nodes (arrivals included), all or nothing
  (`MigrationError`).
- Policies: `Graph` (a `Document`, the nodes, and per-policy settings,
  checked under every policy) and `Graph::variant()`. A policy changes a
  node's `retry`, `lease`, `timeout`, `grace`, `rate`, `concurrency` or
  `lane`; the journal reads each subject's through its policy
  (`NodeJournal::settings()`), `expire` releasing a policy's lease on its own
  clock.
- Rate limits (`Rate` bands, a generic cell rate algorithm), concurrency
  caps and `Per::Policy` budgets (`LimitDriver`), decided by the journal under
  the driver's guard; groups (`Group`): a claim hands out a whole group of
  subjects sharing a key, or nothing, `maxWait` letting a short one go. A
  lane's door honours its node's rate. The items layer gains `groupOf`.
- Lanes (`Lane`, `Node::$lane`, `LaneDriver`): `arrive`, `arrival`, `refs`,
  and `settle` letting due arrivals in, the previous pass archived in the same
  write; throttle, debounce, dedupe and batch presets, `maxWait`,
  `whileRunning`, urgent arrivals, merge functions given to the journal
  (`mergers`); `counts` and `snapshot` report `waiting` and `oldest_waiting`.
  The MariaDB driver gains an arrivals table (`schema()`, `arrivals:`).
- The items layer (`Items\Items`, `Items\Adapter`, `Items\ItemLease`):
  claim, conclude and pass-through calls in the application's own objects;
  ids and a driver's `Query` are loaded with `inflate`, once per call;
  `applies` gives an optional node up, `branch` names a choice's way out;
  `arrive` sends items into a lane with their `refOf`.
