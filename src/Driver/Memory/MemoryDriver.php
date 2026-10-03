<?php

declare(strict_types=1);

namespace Quazardous\GramPHP\Driver\Memory;

use Quazardous\GramPHP\Arrival;
use Quazardous\GramPHP\Driver\CoreDriver;
use Quazardous\GramPHP\Driver\LaneDriver;
use Quazardous\GramPHP\Driver\LimitDriver;
use Quazardous\GramPHP\Driver\NodeTimes;
use Quazardous\GramPHP\Driver\ReadingDriver;
use Quazardous\GramPHP\Entry;
use Quazardous\GramPHP\Keyed;
use Quazardous\GramPHP\Merge;
use Quazardous\GramPHP\Outcome;
use Quazardous\GramPHP\Position;
use Quazardous\GramPHP\Reason;
use Quazardous\GramPHP\Status;
use Quazardous\GramPHP\Subject;
use Quazardous\GramPHP\Time;

/**
 * THE MEMORY DRIVER — node rows in arrays, for tests and single-process use.
 *
 * Deterministic, no dependency. It is the reference the other drivers are
 * confronted with in the shared contract, so it is written to read like the
 * rule, not to be fast. One PHP process, one storage: every call is atomic
 * by construction, and `guard` has nothing to serialise.
 *
 * `candidates` is an ordered iterable of subjects (or `Keyed`): the order is
 * the priority, the first ones are taken first.
 */
final class MemoryDriver implements CoreDriver, ReadingDriver, NodeTimes, LaneDriver, LimitDriver
{
    /** @var array<string, array<string, Row>> Subject::key() => node => row, in the order written */
    private array $rows = [];

    /** @var array<string, int> */
    private array $revisions = [];

    /** @var array<string, string> */
    private array $policyOf = [];

    /** @var list<array{0: string, 1: array{node: string, status: string, started_at: string, finished_at: ?string, lease: ?string, archived_at: string, reason: string}}> */
    private array $archive = [];

    /** @var array<string, array<string, Arrival>> Subject::key() => lane => arrival waiting */
    private array $waiting = [];

    /** @var array<string, float> limiter state */
    private array $limits = [];

    public function now(): string
    {
        return Time::utcNow();
    }

    /** No pre-filter: every candidate is read, the journal decides. */
    public function scan(mixed $candidates, ?string $name, array $nodes, array $parents, int $page, ?string $now, array $after = []): iterable
    {
        if (!is_iterable($candidates)) {
            throw new \InvalidArgumentException('the memory driver takes candidates as an ordered iterable of subjects');
        }
        $batch = [];
        foreach ($candidates as $candidate) {
            $batch[] = $this->entry($candidate, $nodes);
            if (\count($batch) >= $page) {
                yield $batch;
                $batch = [];
            }
        }
        if ([] !== $batch) {
            yield $batch;
        }
    }

    public function insertIfUnchanged(string $name, array $entries, string $status, string $now, ?string $lease): array
    {
        $taken = [];
        foreach ($entries as [$subject, $revision]) {
            $key = Subject::key($subject);
            $current = $this->rows[$key][$name] ?? null;
            if (null !== $current && !(Status::Scheduled->value === $current->status && strcmp($current->startedAt, $now) <= 0)) {
                continue;
            }
            if (($this->revisions[$key] ?? 0) !== $revision) {
                continue;
            }
            $this->rows[$key][$name] = new Row($status, $now, Status::Running->value === $status ? null : $now, $lease);
            $taken[] = $subject;
        }

        return $taken;
    }

    public function conclude(string $name, array $subjects, string $status, string $now, ?string $lease, array $omit, array $reset, ?string $reschedule): int
    {
        $count = 0;
        foreach ($subjects as $subject) {
            $key = Subject::key($subject);
            $row = $this->rows[$key][$name] ?? null;
            if (null === $row || Status::Running->value !== $row->status) {
                continue;
            }
            if (null !== $lease && $row->lease !== $lease) {
                continue;
            }
            $row->status = $status;
            $row->finishedAt = $now;
            foreach ($omit as $other) {
                $this->rows[$key][$other] ??= new Row(Status::Omitted->value, $now, $now);
            }
            if ([] !== $reset) {
                $this->revisions[$key] = ($this->revisions[$key] ?? 0) + 1;
                foreach ($reset as $other) {
                    $this->takeAway($key, $other, $now, Reason::Loop->value);
                }
            }
            if (null !== $reschedule) {
                $this->takeAway($key, $name, $now, Reason::Retry->value);
                $this->rows[$key][$name] = new Row(Status::Scheduled->value, $reschedule);
            }
            ++$count;
        }

        return $count;
    }

    public function adopt(string $name, array $subjects, string $now): int
    {
        $count = 0;
        foreach ($subjects as $subject) {
            $key = Subject::key($subject);
            if (isset($this->rows[$key][$name])) {
                continue;
            }
            $this->rows[$key][$name] = new Row(Status::Done->value, $now, $now);
            ++$count;
        }

        return $count;
    }

    public function forget(string $name, array $subjects, string $now): int
    {
        $count = 0;
        foreach ($subjects as $subject) {
            $key = Subject::key($subject);
            $this->revisions[$key] = ($this->revisions[$key] ?? 0) + 1;
            if ($this->takeAway($key, $name, $now, Reason::Forget->value)) {
                ++$count;
            }
        }

        return $count;
    }

    public function release(string $name, string $olderThan, string $now, ?array $only = null, array $exclude = [], ?string $version = null): int
    {
        $stale = [];
        foreach ($this->rows as $key => $nodes) {
            $row = $nodes[$name] ?? null;
            if (null === $row || Status::Running->value !== $row->status || strcmp($row->startedAt, $olderThan) >= 0) {
                continue;
            }
            $policy = $this->policyOf[$key] ?? null;
            if (null !== $only && (null === $policy || !\in_array($policy, $only, true))) {
                continue;
            }
            if (null !== $policy && \in_array($policy, $exclude, true)) {
                continue;
            }
            $stale[] = $key;
        }
        foreach ($stale as $key) {
            $this->takeAway($key, $name, $now, Reason::Release->value);
        }

        return \count($stale);
    }

    public function enroll(array $subjects, ?string $policy): int
    {
        foreach ($subjects as $subject) {
            $key = Subject::key($subject);
            if (null === $policy) {
                unset($this->policyOf[$key]);
            } else {
                $this->policyOf[$key] = $policy;
            }
        }

        return \count($subjects);
    }

    public function policies(array $subjects): array
    {
        $out = [];
        foreach ($subjects as $subject) {
            $key = Subject::key($subject);
            if (isset($this->policyOf[$key])) {
                $out[$key] = $this->policyOf[$key];
            }
        }

        return $out;
    }

    public function history(int|string $subject): array
    {
        $key = Subject::key($subject);
        $rows = [];
        foreach ($this->archive as $order => [$owner, $row]) {
            if ($owner === $key) {
                $rows[] = [$row, $order];
            }
        }
        usort($rows, static fn(array $a, array $b): int => [$a[0]['archived_at'], $a[0]['node'], $a[1]] <=> [$b[0]['archived_at'], $b[0]['node'], $b[1]]);

        return array_map(static fn(array $pair): array => $pair[0], $rows);
    }

    public function archived(array $subjects, string $name, string $reason): array
    {
        $wanted = array_fill_keys(array_map(Subject::key(...), $subjects), true);
        $counts = [];
        foreach ($this->archive as [$owner, $row]) {
            if (isset($wanted[$owner]) && $row['node'] === $name && $row['reason'] === $reason) {
                $counts[$owner] = ($counts[$owner] ?? 0) + 1;
            }
        }

        return $counts;
    }

    public function latest(array $subjects, string $name, ?string $reason): array
    {
        $wanted = array_fill_keys(array_map(Subject::key(...), $subjects), true);
        $out = [];
        foreach ($this->archive as [$owner, $row]) {
            if (isset($wanted[$owner]) && $row['node'] === $name && (null === $reason || $row['reason'] === $reason)
                && strcmp($row['archived_at'], $out[$owner] ?? '') > 0) {
                $out[$owner] = $row['archived_at'];
            }
        }

        return $out;
    }

    public function note(array $subjects, string $name, string $status, string $reason, string $now, ?string $ref): int
    {
        foreach ($subjects as $subject) {
            $this->archive[] = [Subject::key($subject), [
                'node' => $name, 'status' => $status, 'started_at' => $now, 'finished_at' => $now,
                'lease' => $ref, 'archived_at' => $now, 'reason' => $reason,
            ]];
        }

        return \count($subjects);
    }

    public function pruneHistory(string $before, array $keep): int
    {
        $kept = array_values(array_filter(
            $this->archive,
            static fn(array $entry): bool => strcmp($entry[1]['archived_at'], $before) >= 0 || \in_array($entry[1]['reason'], $keep, true),
        ));
        $pruned = \count($this->archive) - \count($kept);
        $this->archive = $kept;

        return $pruned;
    }

    public function guard(array $keys, callable $block): mixed
    {
        return $block();             // one process: nothing to serialise
    }

    public function progress(int|string $subject): array
    {
        return array_map(static fn(Row $row): string => $row->status, $this->rows[Subject::key($subject)] ?? []);
    }

    // -- limits ----------------------------------------------------------

    public function limits(array $keys): array
    {
        return array_intersect_key($this->limits, array_flip($keys));
    }

    public function setLimits(array $values): void
    {
        $this->limits = $values + $this->limits;
    }

    public function running(string $name, ?array $policies): int
    {
        $count = 0;
        foreach ($this->rows as $key => $nodes) {
            if (Status::Running->value !== ($nodes[$name] ?? null)?->status) {
                continue;
            }
            if (null === $policies || \in_array($this->policyOf[$key] ?? null, $policies, true)) {
                ++$count;
            }
        }

        return $count;
    }

    // -- lanes -----------------------------------------------------------

    public function arrive(string $name, array $subjects, ?string $ref, string $now, string $merge, string $position, bool $urgent, ?string $refs = null): array
    {
        $out = [];
        foreach ($subjects as $subject) {
            $key = Subject::key($subject);
            $current = $this->waiting[$key][$name] ?? null;
            if (null === $current) {
                $this->waiting[$key][$name] = new Arrival($ref, $now, $now, $urgent, $refs);
                $out[$key] = Outcome::Queued->value;

                continue;
            }
            // `set`: the journal worked out ref and refs under the guard.
            $this->waiting[$key][$name] = new Arrival(
                \in_array($merge, [Merge::Last->value, Merge::Set->value], true) ? $ref : $current->ref,
                Position::Last->value === $position ? $now : $current->place,
                $current->arrivedAt,
                $current->urgent || $urgent,
                Merge::Set->value === $merge ? $refs : $current->refs,
            );
            $out[$key] = Outcome::Merged->value;
        }

        return $out;
    }

    public function arrivals(array $subjects, string $name): array
    {
        $out = [];
        foreach ($subjects as $subject) {
            $key = Subject::key($subject);
            if (isset($this->waiting[$key][$name])) {
                $out[$key] = $this->waiting[$key][$name];
            }
        }

        return $out;
    }

    public function enter(string $name, array $entries, array $archive, string $now): array
    {
        $entered = [];
        foreach ($entries as [$subject, $revision]) {
            $key = Subject::key($subject);
            $arrival = $this->waiting[$key][$name] ?? null;
            if (null === $arrival || ($this->revisions[$key] ?? 0) !== $revision) {
                continue;
            }
            foreach ($archive as $x) {
                $status = ($this->rows[$key][$x] ?? null)?->status;
                if (Status::Running->value === $status || Status::Scheduled->value === $status) {
                    continue 2;
                }
            }
            $this->revisions[$key] = $revision + 1;
            foreach ($archive as $x) {
                $this->takeAway($key, $x, $now, Reason::Arrival->value);
            }
            unset($this->waiting[$key][$name]);
            $this->archive[] = [$key, [
                'node' => $name, 'status' => Outcome::Entered->value, 'started_at' => $arrival->arrivedAt,
                'finished_at' => $now, 'lease' => $arrival->ref, 'archived_at' => $now, 'reason' => Reason::Lane->value,
            ]];
            $this->rows[$key][$name] = new Row(Status::Done->value, $arrival->arrivedAt, $now);
            $entered[] = $subject;
        }

        return $entered;
    }

    public function queued(string $name): int
    {
        return \count(array_filter($this->waiting, static fn(array $lanes): bool => isset($lanes[$name])));
    }

    public function waitingSince(string $name): ?string
    {
        $first = null;
        foreach ($this->waiting as $lanes) {
            $at = isset($lanes[$name]) ? $lanes[$name]->arrivedAt : null;
            if (null !== $at && (null === $first || strcmp($at, $first) < 0)) {
                $first = $at;
            }
        }

        return $first;
    }

    // -- reading ---------------------------------------------------------

    public function statusCounts(string $name): array
    {
        $counts = [];
        foreach ($this->rows as $nodes) {
            if (isset($nodes[$name])) {
                $status = $nodes[$name]->status;
                $counts[$status] = ($counts[$status] ?? 0) + 1;
            }
        }

        return $counts;
    }

    public function stages(array $subjects, string $at): array
    {
        $lines = [];
        foreach ($subjects as $subject) {
            foreach ($this->rows[Subject::key($subject)] ?? [] as $node => $row) {
                if (\in_array($row->status, [Status::Skipped->value, Status::Omitted->value, Status::Scheduled->value], true)) {
                    continue;
                }
                $lines[] = [$row->finishedAt ?? $at, (string) $node, (string) $subject, $row];
            }
        }
        usort($lines, static fn(array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        $out = [];
        foreach ($lines as [$ended, $node, $subject, $row]) {
            $seconds = round(Time::epoch($ended) - Time::epoch($row->startedAt), 1);
            $out[$subject][] = [$node, $ended, $seconds, $row->status];
        }

        return $out;
    }

    public function parentsConcluded(array $parents, int|string $subject): bool
    {
        $nodes = $this->rows[Subject::key($subject)] ?? [];
        foreach ($parents as $parent) {
            if (!isset($nodes[$parent]) || !\in_array($nodes[$parent]->status, Status::satisfying(), true)) {
                return false;
            }
        }

        return true;
    }

    public function nodeTimes(string $name): array
    {
        $out = [];
        foreach ($this->rows as $nodes) {
            $row = $nodes[$name] ?? null;
            if (null === $row || !\in_array($row->status, [Status::Running->value, Status::Scheduled->value], true)) {
                continue;
            }
            if (!isset($out[$row->status]) || strcmp($row->startedAt, $out[$row->status]) < 0) {
                $out[$row->status] = $row->startedAt;
            }
        }

        return $out;
    }

    // -- for a test harness ----------------------------------------------

    /** Write a row directly, bypassing the journal — for tests only. */
    public function seed(int|string $subject, string $name, Row $row): void
    {
        $this->rows[Subject::key($subject)][$name] = $row;
    }

    // -- inside ----------------------------------------------------------

    /** @param list<string> $nodes */
    private function entry(mixed $candidate, array $nodes): Entry
    {
        [$subject, $groupKey] = self::split($candidate);
        $key = Subject::key($subject);
        $rows = $due = $finished = [];
        foreach ($nodes as $node) {
            $row = $this->rows[$key][$node] ?? null;
            if (null === $row) {
                continue;
            }
            $rows[$node] = $row->status;
            if (Status::Scheduled->value === $row->status) {
                $due[$node] = $row->startedAt;
            }
            if (null !== $row->finishedAt) {
                $finished[$node] = $row->finishedAt;
            }
        }

        return new Entry($subject, $this->revisions[$key] ?? 0, $rows, $due, $finished, $this->policyOf[$key] ?? null, null, $groupKey);
    }

    /** @return array{0: int|string, 1: ?string} */
    private static function split(mixed $candidate): array
    {
        if ($candidate instanceof Keyed) {
            return [$candidate->subject, $candidate->key];
        }
        if (\is_int($candidate) || \is_string($candidate)) {
            return [$candidate, null];
        }
        throw new \InvalidArgumentException(\sprintf(
            'candidate %s: a subject is an int or a string; a grouping key comes as new Keyed(subject, key)',
            get_debug_type($candidate),
        ));
    }

    /** Move a row to the archive. */
    private function takeAway(string $key, string $name, string $now, string $reason): bool
    {
        $row = $this->rows[$key][$name] ?? null;
        if (null === $row) {
            return false;
        }
        unset($this->rows[$key][$name]);
        if ([] === $this->rows[$key]) {
            unset($this->rows[$key]);
        }
        $this->archive[] = [$key, [
            'node' => $name, 'status' => $row->status, 'started_at' => $row->startedAt,
            'finished_at' => $row->finishedAt, 'lease' => $row->lease, 'archived_at' => $now, 'reason' => $reason,
        ]];

        return true;
    }
}
