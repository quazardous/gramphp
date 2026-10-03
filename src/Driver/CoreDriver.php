<?php

declare(strict_types=1);

namespace Quazardous\GramPHP\Driver;

use Quazardous\GramPHP\Entry;

/**
 * WHAT EVERY JOURNAL NEEDS: node rows, revisions, the history, the clock and
 * the guard. Every method works on node rows — `(subject, node) → status,
 * started_at, finished_at, lease`, one row per pair — and on one REVISION per
 * subject, 0 for a subject never forgotten.
 *
 * A SUBJECT IS THE APPLICATION'S ID, an `int` or a `string`, stored and
 * returned exactly as given. Maps keyed by subject are keyed by
 * `Subject::key()`, which keeps the type.
 *
 * A driver NEVER validates against the graph, never decides what is
 * claimable — the journal does both — and never commits: the application
 * opens and ends the transaction around each journal call.
 *
 * Methods are EXACT — they do what their docblock says, no more, no less —
 * except `scan`, a pre-filter that may return a superset.
 */
interface CoreDriver
{
    /**
     * The candidates, IN THEIR ORDER, page by page — each with its revision,
     * its rows on `nodes`, and when its `scheduled` rows are due.
     *
     * A PRE-FILTER, NEVER A DECISION: the driver MAY leave out a candidate
     * that holds a row for `name` — other than a `scheduled` row due by `now`
     * — or one of whose `parents` has no satisfying row, or one holding a row
     * for a node of `after`. It must not leave out anything else, nor change
     * the order. A driver that pre-filters nothing is correct, only slower.
     *
     * @param list<string> $nodes
     * @param list<string> $parents
     * @param list<string> $after
     *
     * @return iterable<list<Entry>>
     */
    public function scan(mixed $candidates, ?string $name, array $nodes, array $parents, int $page, ?string $now, array $after = []): iterable;

    /**
     * ATOMICALLY, for each `[subject, revision]`: write a `status` row for
     * `name`, holding `lease`, started at `now`, if the subject's revision is
     * still `revision` AND it has no row for `name` — or only a `scheduled`
     * row due by `now`, which the new row replaces. Any row but a `running`
     * one is finished at `now`. Return the subjects written.
     *
     * @param list<array{0: int|string, 1: int}> $entries
     *
     * @return list<int|string>
     */
    public function insertIfUnchanged(string $name, array $entries, string $status, string $now, ?string $lease): array;

    /**
     * Set `status` and `finished_at` on RUNNING rows only — and, unless
     * `lease` is null, only on rows holding that lease. For every subject
     * concluded, ATOMICALLY with it: insert an `omitted` row finished at `now`
     * for each node of `omit` that has no row; then ARCHIVE with reason `loop`
     * and delete the rows of every node of `reset` — the concluded row
     * included when it is among them — raising the subject's revision as
     * `forget` does; or, when `reschedule` is given, ARCHIVE the concluded row
     * with reason `retry` and replace it with a `scheduled` row started at
     * `reschedule`, its due time. Return the count concluded.
     *
     * @param list<int|string> $subjects
     * @param list<string>     $omit
     * @param list<string>     $reset
     */
    public function conclude(string $name, array $subjects, string $status, string $now, ?string $lease, array $omit, array $reset, ?string $reschedule): int;

    /**
     * Insert `done` rows, never overwriting an existing row. Return the count written.
     *
     * @param list<int|string> $subjects
     */
    public function adopt(string $name, array $subjects, string $now): int;

    /**
     * ARCHIVE with reason `forget` and delete the rows, AND raise each
     * subject's revision, atomically: no `insertIfUnchanged` that read the old
     * revision may succeed afterwards. Return the count deleted.
     *
     * @param list<int|string> $subjects
     */
    public function forget(string $name, array $subjects, string $now): int;

    /**
     * ARCHIVE with reason `release` and delete the RUNNING rows started before
     * `olderThan` — of subjects whose policy is in `only` when given, and not
     * in `exclude` (a subject without policy is never in either) — and, when
     * `version` is given, of subjects pinned to it or to none.
     *
     * @param list<string>|null $only
     * @param list<string>      $exclude
     */
    public function release(string $name, string $olderThan, string $now, ?array $only = null, array $exclude = [], ?string $version = null): int;

    /**
     * Record each subject's policy, creating its registry entry when needed.
     * Return the count written.
     *
     * @param list<int|string> $subjects
     */
    public function enroll(array $subjects, ?string $policy): int;

    /**
     * The policy of the subjects that have one.
     *
     * @param list<int|string> $subjects
     *
     * @return array<string, string> Subject::key() => policy
     */
    public function policies(array $subjects): array;

    /**
     * The archived rows of one subject, oldest archive first (ties by node,
     * then in the order written).
     *
     * @return list<array{node: string, status: string, started_at: string, finished_at: ?string, lease: ?string, archived_at: string, reason: string}>
     */
    public function history(int|string $subject): array;

    /**
     * How many rows of `name` were archived with `reason`, per subject;
     * subjects without any left out.
     *
     * @param list<int|string> $subjects
     *
     * @return array<string, int> Subject::key() => count
     */
    public function archived(array $subjects, string $name, string $reason): array;

    /**
     * The latest `archived_at` of the history rows of `name` — with `reason`
     * when given, any reason otherwise; subjects without any left out.
     *
     * @param list<int|string> $subjects
     *
     * @return array<string, string> Subject::key() => archived_at
     */
    public function latest(array $subjects, string $name, ?string $reason): array;

    /**
     * Append to each subject's history a row `node = name`, `status`,
     * `reason`, started, finished and archived at `now`, `ref` in `lease`.
     * Return the count written.
     *
     * @param list<int|string> $subjects
     */
    public function note(array $subjects, string $name, string $status, string $reason, string $now, ?string $ref): int;

    /**
     * Delete the history rows archived before `before` whose reason is not in
     * `keep`. Return the count deleted.
     *
     * @param list<string> $keep
     */
    public function pruneHistory(string $before, array $keep): int;

    /** The storage's clock, in the journal's format: ONE time for every process. */
    public function now(): string;

    /**
     * SERIALISE writers on `keys` — sorted — from entering `block` until the
     * caller's transaction ends (for a storage without transactions, until the
     * block ends). Return what `block` returns.
     *
     * @template T
     *
     * @param list<string>  $keys
     * @param callable(): T $block
     *
     * @return T
     */
    public function guard(array $keys, callable $block): mixed;

    /**
     * `[node => status]` for one subject.
     *
     * @return array<string, string>
     */
    public function progress(int|string $subject): array;
}
