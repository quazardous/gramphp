# gramphp — rules for agents

A PHP port of grampy (Python). Meant to be public (MIT): anything committed
here is read by strangers, so the rules below are about keeping it readable
cold.

## English everywhere

Code, comments, docblocks, README, CHANGELOG, commit messages and PR
descriptions are in English, whatever language the conversation is in.

## Nothing from a host application

gramphp must stay generic. Never commit hostnames, secrets, dev-machine
paths (`/home/...`), private tracker IDs or the vocabulary of an application
that uses it. Use the generic terms: subject, node, run. Context about that
application belongs in `CLAUDE.local.md` (not versioned).

## Follow grampy

grampy is the reference. A behaviour ported here keeps grampy's semantics
and its names (in PHP casing); a deliberate divergence is said in the
docblock and in the CHANGELOG. Port a feature together with its contract
tests.

## Drivers follow the contract

The claim rule lives in the core (`Dag`, applied by `NodeJournal`); drivers
store rows and offer atomic operations, they never decide. No guarantee may
depend on a storage-specific mechanism: it is defined in the driver
interfaces and proven by the shared contract
(`tests/Contract/JournalContract.php`), concurrency tests included (forked
processes, each with its own connection). A change to the journal or a
driver must pass the contract on every driver — MariaDB included.

## Subjects keep their type

A subject is an `int` or a `string`, returned exactly as given. PHP turns
integer-like array keys into integers, so subjects are never used as raw
array keys: index them by `Subject::key()`.

## Quality gates, from the first commit

PHPStan at max level (src and tests), PHP-CS-Fixer (PER-CS 2.0, strict
types) and PHPUnit run in CI and block a merge. No baseline: fix, do not
silence. Run `make check` before committing — it is what CI runs.

## Changelog

Every user-visible change adds a line to `CHANGELOG.md` under
`[Unreleased]`, written for a user.

## Commands

```bash
make build install   # once
make test            # unit, memory and MariaDB contract
make lint            # PHPStan, PHP-CS-Fixer, composer validate
make check           # lint + test: what CI runs
```
