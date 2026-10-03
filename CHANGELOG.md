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
- The memory driver and the MariaDB driver (PDO, READ COMMITTED), both
  certified by the shared driver contract, concurrency included.
