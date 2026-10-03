<?php

declare(strict_types=1);

namespace Quazardous\GramPHP;

use Quazardous\GramPHP\Driver\CoreDriver;
use Quazardous\GramPHP\Driver\LaneDriver;
use Quazardous\GramPHP\Driver\LimitDriver;
use Quazardous\GramPHP\Driver\MissingCapability;
use Quazardous\GramPHP\Driver\NodeTimes;
use Quazardous\GramPHP\Driver\ProgressMany;
use Quazardous\GramPHP\Driver\ReadingDriver;
use Quazardous\GramPHP\Driver\VersionDriver;

/**
 * THE NODE JOURNAL — who started what, and where it stands.
 *
 *     claim     take a node for eligible subjects — a `Lease`, with its token
 *     conclude  finish it, or declare it failed — with the lease's token
 *     skip      give up an optional node
 *     adopt     record work already done that the journal does not know
 *     forget    erase it — "never started", the initial state
 *     migrate   move subjects pinned to another version of the graph onto this one
 *     release   give back the leases of a dead worker
 *     expire    give back every lease held longer than its node allows
 *     signal    record that an awaited event happened, for subjects
 *     arrive    a subject comes back: it waits in a lane before running again
 *     settle    conclude waits, let due arrivals through their lane, and skip
 *               optional nodes past their grace
 *     history   every row forget, release, a retry or a loop took away
 *     progress  what is recorded for ONE subject
 *     snapshot  where each node stands, for monitoring
 *
 * THE LOGIC HERE, THE STORAGE IN A DRIVER. The journal validates against the
 * graph and DECIDES: what a claim may take is `Dag::claimable()`, evaluated
 * here, in PHP, on rows the driver read. The driver stores rows and offers a
 * few atomic operations; it never decides. One rule, one place.
 *
 * READ, DECIDE, WRITE — AND THE REVISION CLOSES THE GAP. Something may happen
 * between the read and the write. The one thing that can turn a yes into a no
 * is a row DISAPPEARING (`forget`), so every subject carries a REVISION that
 * `forget` raises; a claim writes only if the revision is still the one read.
 *
 * A CONCLUSION PROVES IT HOLDS THE LEASE. Every claim issues a token stored
 * on the rows it took; `conclude` and `fail` touch only the rows holding the
 * token they bring. `token: null` is the operator's override, written on
 * purpose, never a default.
 *
 * NOTHING IS LOST: a row taken away (`forget`, `release`, a retry, a loop) is
 * archived in the history, with when and why.
 *
 * ELIGIBILITY COMES FROM OUTSIDE: the candidates are an opaque value in the
 * driver's own terms — an ordered iterable for the memory driver, a `Query`
 * for a database one — and the journal does not read them.
 *
 * IT NEVER COMMITS. The application opens and ends the transaction around
 * each call, because it knows what else it put in it.
 */
final class NodeJournal
{
    /** How many candidates a claim reads at once, at least. */
    public const PAGE = 200;

    /** How many candidates a snapshot reads at once: it reads them all. */
    public const SNAPSHOT_PAGE = 5000;

    /** The history rows a bound counts — pruning never touches them. */
    public const COUNTED = ['retry', 'loop'];

    public readonly Dag $dag;

    /** The graph the journal was given, when it was given one: its policies. */
    public readonly ?Graph $graph;

    /**
     * SUBJECTS ARE PINNED TO THE GRAPH THEY STARTED ON: a journal on a `Graph`
     * takes only subjects pinned to it, or not yet pinned, and pins them on
     * their first write — once, never again. It is the whole
     * `Document::identity()`, so two workflows sharing a version string stay
     * strangers. Null for a bare `Dag`.
     */
    public readonly ?string $version;

    /** @var (\Closure(): (string|\DateTimeInterface))|null */
    private readonly ?\Closure $clock;

    private readonly \Random\Randomizer $rng;

    /** @var array<string, \Closure(list<string>, ?string): iterable<string>> */
    private readonly array $mergers;

    /**
     * @param Graph|Dag|list<Node>                                        $dag     checked when the journal is built
     * @param (callable(): (string|\DateTimeInterface))|null              $clock   defaults to the driver's: one time for every process
     * @param array<string, callable(list<string>, ?string): iterable<string>> $mergers the functions a lane names (`Merge::fn('<name>')`):
     *                                                                             given the refs waiting and the one arriving, the refs to keep
     */
    public function __construct(
        public readonly CoreDriver $driver,
        Graph|Dag|array $dag,
        ?callable $clock = null,
        ?\Random\Randomizer $rng = null,
        array $mergers = [],
    ) {
        $this->graph = $dag instanceof Graph ? $dag : null;
        $this->version = $this->graph?->document->identity();
        if (null !== $this->graph && !$driver instanceof VersionDriver) {
            throw new MissingCapability('version', 'a journal built on a Graph, whose subjects are pinned to it', VersionDriver::class);
        }
        $this->dag = $dag instanceof Graph ? $dag->dag : ($dag instanceof Dag ? $dag : new Dag(...$dag));
        $this->dag->check();
        $this->clock = null === $clock ? null : \Closure::fromCallable($clock);
        $this->rng = $rng ?? new \Random\Randomizer();
        $this->mergers = array_map(\Closure::fromCallable(...), $mergers);
        foreach ($this->dag as $node) {
            if (null !== $node->lane && !$driver instanceof LaneDriver) {
                throw new MissingCapability('lane', \sprintf("node '%s', a lane", $node->name), LaneDriver::class);
            }
            if ($this->limited($node) && !$driver instanceof LimitDriver) {
                throw new MissingCapability('limit', \sprintf("node '%s', limited by a rate or a concurrency", $node->name), LimitDriver::class);
            }
        }
    }

    /** The journal's time: the injected clock read into the one format, or the driver's. */
    public function now(): string
    {
        return null === $this->clock ? $this->driver->now() : Time::stamp(($this->clock)());
    }

    // -- take ------------------------------------------------------------

    /**
     * Take up to `limit` candidates for this node, as a `Lease` whose `token`
     * the worker brings back to conclude them.
     *
     * THE RULE IS `Dag::claimable()` — parents concluded, nobody holding, no
     * descendant started — decided here on the rows the driver read, and
     * written with `insertIfUnchanged`. Two workers aiming at the same subject:
     * one row, one winner. A `forget` in between: no row.
     *
     * ONE WRITE PER CLAIM: under contention a claim may take fewer than
     * `limit`, never a wrong one.
     *
     * `requireParents: false` — A NAMED EXCEPTION, for a backfill over subjects
     * older than the graph. The two other guards still hold.
     */
    public function claim(string $name, int $limit, mixed $candidates, bool $requireParents = true): Lease
    {
        $node = $this->dag->node($name);
        if (null !== $node->wait) {
            throw new \InvalidArgumentException(\sprintf(
                "node '%s' waits for '%s': it is settled (`settle`), never claimed",
                $name,
                $node->wait,
            ));
        }
        if (null !== $node->lane) {
            throw new \InvalidArgumentException(\sprintf(
                "node '%s' is a lane: subjects `arrive` in it and `settle` lets them through, it is never claimed",
                $name,
            ));
        }
        $token = bin2hex(random_bytes(16));
        if ($limit <= 0) {
            return new Lease([], $token);
        }
        $after = $this->dag->descendants($name);
        $parents = $requireParents && !$node->customJoin() ? $node->parents : [];
        $now = $this->now();
        // A GROUP IS GATHERED BEFORE IT IS JUDGED: the claim must read enough
        // candidates to know whether one is complete, so `limit` alone is not
        // how far it reads.
        $enough = null === $node->group ? $limit : max($limit, $node->group->size);
        $gather = function () use ($node, $name, $candidates, $parents, $after, $enough, $limit, $now, $requireParents): array {
            $chosen = [];
            $ready = [];
            $seen = [];
            foreach ($this->driver->scan($candidates, $name, [$name, ...$node->parents, ...$after], $parents, max($enough, self::PAGE), $now, $after) as $page) {
                foreach ($page as $entry) {
                    $key = Subject::key($entry->subject);
                    if (isset($seen[$key])) {
                        continue;                 // a subject listed twice counts once
                    }
                    $seen[$key] = true;
                    if (!$this->mine($entry) || !$this->takable($node, $after, self::dueAway($name, $entry, $now), $requireParents)) {
                        continue;
                    }
                    if (null !== $node->group) {
                        $ready[] = $entry;

                        continue;
                    }
                    $chosen[] = $entry;
                    if (\count($chosen) >= $limit) {
                        return $chosen;
                    }
                }
            }

            return null === $node->group ? $chosen : $this->group($node, $ready, $now);
        };
        $write = function (array $chosen) use ($node, $name, $now, $token): array {
            /** @var list<Entry> $chosen */
            if ([] === $chosen) {
                return [];
            }
            $insert = function (array $part) use ($name, $now, $token): array {
                /** @var list<array{0: int|string, 1: int}> $part */
                return $this->driver->insertIfUnchanged($name, $part, Status::Running->value, $now, $token);
            };

            return $this->limited($node) ? $this->withinLimits($node, $chosen, $now, $insert) : $insert(self::pairs($chosen));
        };
        // ONE WRITE PER CLAIM: under contention a claim may take fewer than
        // `limit`, never a wrong one. A GROUPING CLAIM READS AND WRITES UNDER
        // THE GUARD of its node, taken before it reads anything: two claimers
        // would otherwise each win a piece of one group.
        $taken = null === $node->group
            ? $write($gather())
            : $this->driver->guard(["group|{$name}"], static fn(): array => $write($gather()));
        $this->pin($taken);

        return new Lease($taken, $token);
    }

    // -- conclude --------------------------------------------------------

    /**
     * Finish this node on these subjects. Return the count touched.
     *
     * ONLY RUNNING ROWS HOLDING `token` ARE TOUCHED: a duplicate report
     * recounts nothing, and a worker whose lease went to someone else rewrites
     * nothing. `token: null` concludes whoever holds the rows (an operator's act).
     *
     * A FAILURE IS RETRIED FIRST when the node declares a `Retry`: under the
     * limit, it is archived and the node scheduled again.
     *
     * A LOOP sends the subject back to `loop.to`, in the same write, under its
     * bound.
     *
     * A CHOICE CONCLUDES BY NAMING ITS BRANCH: `branch` is required to conclude
     * a choice `done` or `skipped`, refused anywhere else; the other branches
     * and what only they reach are `omitted` in the same write. A failed choice
     * omits nothing.
     *
     * @param iterable<int|string> $subjects
     */
    public function conclude(string $name, iterable $subjects, ?string $token, Status|string $status = Status::Done, ?string $branch = null): int
    {
        $subjects = Subject::unique($subjects);
        if ([] === $subjects) {
            return 0;
        }
        $node = $this->dag->node($name);
        $status = Status::valueOf($status);
        if (!\in_array($status, Status::concluded(), true) || Status::Omitted->value === $status) {
            throw new \InvalidArgumentException(\sprintf(
                "unknown conclusion status: '%s' — expected done, skipped or failed",
                $status,
            ));
        }
        $now = $this->now();
        $touched = 0;

        // FIRST, THE RETRIES — each subject under its policy's: under the
        // limit, the failure is archived and the node scheduled again.
        $retryOf = $this->perPolicy($name, 'retry', $subjects);
        if (Status::Failed->value === $status && null === $branch && [] !== array_filter($retryOf)) {
            $tries = $this->driver->archived($subjects, $name, Reason::Retry->value);
            $byDue = [];
            foreach ($subjects as $subject) {
                $retry = $retryOf[Subject::key($subject)] ?? null;
                $done = $tries[Subject::key($subject)] ?? 0;
                if ($retry instanceof Retry && $done < $retry->limit) {
                    $wait = $retry->wait($done + 1, $this->rng);
                    $byDue[Time::shift($now, $wait)][] = $subject;
                }
            }
            $retried = [];
            foreach ($byDue as $due => $group) {
                $touched += $this->driver->conclude($name, $group, $status, $now, $token, [], [], (string) $due);
                foreach ($group as $subject) {
                    $retried[Subject::key($subject)] = true;
                }
            }
            $subjects = array_values(array_filter($subjects, static fn(int|string $s): bool => !isset($retried[Subject::key($s)])));
            if ([] === $subjects) {
                return $touched;
            }
        }

        if (null !== $node->loop && \in_array($status, $node->loop->on, true)) {
            $reset = [$node->loop->to, ...$this->dag->descendants($node->loop->to)];
            $passes = $this->driver->archived($subjects, $node->loop->to, Reason::Loop->value);
            $back = [];
            $stay = [];
            foreach ($subjects as $subject) {
                if (($passes[Subject::key($subject)] ?? 0) < $node->loop->max) {
                    $back[] = $subject;
                } else {
                    $stay[] = $subject;
                }
            }
            if ([] !== $back) {
                $touched += $this->driver->conclude($name, $back, $status, $now, $token, [], $reset, null);
            }
            if ([] !== $stay) {
                $touched += $this->driver->conclude($name, $stay, $status, $now, $token, [], [], null);
            }

            return $touched;
        }

        $omit = [];
        if ($node->choice && Status::Failed->value !== $status) {
            if (null === $branch) {
                throw new \InvalidArgumentException(\sprintf(
                    "node '%s' is a choice: concluding it needs `branch:`, one of its children",
                    $name,
                ));
            }
            $omit = $this->dag->omittedBy($name, $branch);
        } elseif (null !== $branch) {
            throw new \InvalidArgumentException(\sprintf(
                "`branch` given, but node '%s' %s",
                $name,
                $node->choice ? 'failed' : 'is not a choice',
            ));
        }

        return $touched + $this->driver->conclude($name, $subjects, $status, $now, $token, $omit, [], null);
    }

    /**
     * This node did not produce. `failed` satisfies no child — unless a
     * child's edge accepts it (`Node::$on`). `branch` is accepted only to be
     * refused: a failed choice names nothing.
     *
     * @param iterable<int|string> $subjects
     */
    public function fail(string $name, iterable $subjects, ?string $token, ?string $branch = null): int
    {
        return $this->conclude($name, $subjects, $token, Status::Failed, $branch);
    }

    /**
     * Mark this OPTIONAL node as given up, on the candidates.
     *
     * ONLY A CLAIMABLE NODE IS SKIPPED — parents concluded: skipping it on a
     * subject not there yet would let its children start early. A skip never
     * overwrites a row.
     *
     * `limit` BOUNDS A PASS: at most that many subjects, the first the
     * candidates offer. A janitor calls again while a pass returns `limit`.
     */
    public function skip(string $name, mixed $candidates, ?int $limit = null): int
    {
        $node = $this->dag->node($name);
        if (!$node->optional) {
            throw new \InvalidArgumentException(\sprintf(
                "node '%s' is not optional — skipping it would move the graph forward without its work",
                $name,
            ));
        }
        if (null !== $limit && $limit <= 0) {
            return 0;
        }
        $now = $this->now();
        $chosen = [];
        $seen = [];
        $parents = $node->customJoin() ? [] : $node->parents;
        foreach ($this->driver->scan($candidates, $name, [$name, ...$node->parents], $parents, self::PAGE, $now) as $page) {
            foreach ($page as $entry) {
                $key = Subject::key($entry->subject);
                if (isset($seen[$key]) || !$this->mine($entry) || \array_key_exists($name, $entry->rows) || !$this->dag->joined($name, $entry->rows)) {
                    continue;
                }
                $seen[$key] = true;
                $chosen[] = [$entry->subject, $entry->revision];
                if (null !== $limit && \count($chosen) >= $limit) {
                    break 2;
                }
            }
        }
        if ([] === $chosen) {
            return 0;
        }

        $written = $this->driver->insertIfUnchanged($name, $chosen, Status::Skipped->value, $now, null);
        $this->pin($written);

        return \count($written);
    }

    /**
     * Record work ALREADY DONE that the journal does not know — for subjects
     * older than the graph. The application attests the work. NEVER
     * OVERWRITES: a row that exists keeps its status, `failed` included.
     *
     * @param iterable<int|string> $subjects
     */
    public function adopt(string $name, iterable $subjects): int
    {
        $this->dag->node($name);
        $subjects = Subject::unique($subjects);

        if ([] === $subjects) {
            return 0;
        }
        $count = $this->driver->adopt($name, $subjects, $this->now());
        $this->pin($subjects);

        return $count;
    }

    // -- undo ------------------------------------------------------------

    /**
     * Erase this node for these subjects — "never started". THE STEP BACK: a
     * retry, a janitor, a replay. The row goes to the history.
     *
     * @param iterable<int|string> $subjects
     */
    public function forget(string $name, iterable $subjects): int
    {
        $subjects = Subject::unique($subjects);
        if ([] === $subjects) {
            return 0;
        }
        $this->dag->node($name);

        return $this->driver->forget($name, $subjects, $this->now());
    }

    /** Give back the leases a dead worker has held for too long. */
    public function release(string $name, string|\DateTimeInterface $olderThan): int
    {
        $this->dag->node($name);

        return $this->driver->release($name, Time::stamp($olderThan), $this->now(), null, [], $this->version);
    }

    /**
     * Release, on every node declaring a `lease`, the rows held longer than it
     * allows. Return `[node => count]` for the nodes that released something.
     * Meant to be called on a schedule by the application's janitor: gramphp
     * runs nothing of its own.
     *
     * @return array<string, int>
     */
    public function expire(): array
    {
        $now = $this->now();
        $released = [];
        foreach ($this->dag as $node) {
            // A POLICY WITH ITS OWN LEASE is released on its own clock, and
            // left out of the node's.
            $special = [];
            foreach ($this->variants($node->name) as $policy => $variant) {
                if ($variant->lease !== $node->lease) {
                    $special[$policy] = $variant->lease;
                }
            }
            ksort($special, \SORT_STRING);
            $count = 0;
            if (null !== $node->lease) {
                $count += $this->driver->release($node->name, Time::shift($now, -Time::seconds($node->lease)), $now, null, array_map('strval', array_keys($special)), $this->version);
            }
            foreach ($special as $policy => $lease) {
                if (null !== $lease) {
                    $count += $this->driver->release($node->name, Time::shift($now, -Time::seconds($lease)), $now, [(string) $policy], [], $this->version);
                }
            }
            if ($count > 0) {
                $released[$node->name] = $count;
            }
        }

        return $released;
    }

    /**
     * Give these subjects a POLICY. The settings a graph gives that policy
     * then apply to them; the structure of the workflow stays the same for all.
     *
     * @param iterable<int|string> $subjects
     */
    public function enroll(iterable $subjects, ?string $policy): int
    {
        $subjects = Subject::unique($subjects);

        if ([] === $subjects) {
            return 0;
        }
        $count = $this->driver->enroll($subjects, $policy);
        $this->pin($subjects);

        return $count;
    }

    /** The subject's policy, null when it has none. */
    public function policy(int|string $subject): ?string
    {
        return $this->driver->policies([$subject])[Subject::key($subject)] ?? null;
    }

    // -- events ----------------------------------------------------------

    /**
     * Record that `event` happened for these subjects — DURABLY, before any
     * wait for it may have begun: a wait settles on a signal received since it
     * last went back (a loop, a forget), however early.
     *
     * @param iterable<int|string> $subjects
     */
    public function signal(iterable $subjects, string $event, ?string $ref = null): int
    {
        $subjects = Subject::unique($subjects);

        return [] === $subjects ? 0 : $this->driver->note($subjects, $event, Outcome::Received->value, Reason::Signal->value, $this->now(), $ref);
    }

    // -- lanes -----------------------------------------------------------

    /**
     * THESE SUBJECTS CAME BACK — a new version of each, `ref` naming it
     * (opaque, optional) — and wait in the lane `name` to run again.
     *
     * An arrival for a subject already waiting is merged, as the lane says
     * (`Lane::$merge`, `Lane::$position`); the merge is noted in the history.
     * With `WhileRunning::Skip`, an arrival for a subject whose pass is still
     * running is dropped, and noted. `urgent` lets the arrival through at the
     * next `settle` whatever its cooldown or delay — never over a running pass.
     *
     * Nothing runs here: `settle` lets due arrivals in. Return `['queued' => n,
     * 'merged' => n, 'skipped' => n]`.
     *
     * @param iterable<int|string> $subjects
     *
     * @return array{queued: int, merged: int, skipped: int}
     */
    public function arrive(string $name, iterable $subjects, ?string $ref = null, bool $urgent = false): array
    {
        $node = $this->dag->node($name);
        if (null === $node->lane) {
            throw new \InvalidArgumentException(\sprintf("node '%s' is not a lane: nothing arrives in it", $name));
        }
        $out = ['queued' => 0, 'merged' => 0, 'skipped' => 0];
        $subjects = Subject::unique($subjects);
        if ([] === $subjects) {
            return $out;
        }
        $driver = $this->lanes();
        $now = $this->now();
        // EACH SUBJECT IN THE LANE ITS POLICY TUNES.
        $laneOf = $this->perPolicy($name, 'lane', $subjects);
        $pass = [$name, ...$this->dag->descendants($name)];
        $toSkip = array_values(array_filter($subjects, static fn(int|string $s): bool => ($laneOf[Subject::key($s)] ?? null) instanceof Lane && WhileRunning::Skip === $laneOf[Subject::key($s)]->whileRunning));
        $progresses = [] === $toSkip ? [] : $this->progressMany($toSkip);
        /** @var array<int, array{0: Lane, 1: list<int|string>}> $groups */
        $groups = [];
        $skipped = [];
        foreach ($subjects as $subject) {
            $key = Subject::key($subject);
            $lane = $laneOf[$key] ?? null;
            if (!$lane instanceof Lane) {
                continue;
            }
            foreach ($pass as $x) {
                $status = $progresses[$key][$x] ?? null;
                if (Status::Running === $status || Status::Scheduled === $status) {
                    $skipped[] = $subject;

                    continue 2;
                }
            }
            $groups[spl_object_id($lane)] ??= [$lane, []];
            $groups[spl_object_id($lane)][1][] = $subject;
        }
        if ([] !== $skipped) {
            $this->driver->note($skipped, $name, Outcome::Skipped->value, Reason::Lane->value, $now, $ref);
            $out['skipped'] = \count($skipped);
        }
        foreach ($groups as [$lane, $group]) {
            if ($lane->keepsEveryRef()) {
                $this->keepEveryRef($driver, $name, $group, $lane, $ref, $now, $urgent, $out);

                continue;
            }
            $outcome = $driver->arrive($name, $group, $ref, $now, $lane->merge, $lane->position->value, $urgent);
            $merged = array_values(array_filter($group, static fn(int|string $s): bool => Outcome::Merged->value === ($outcome[Subject::key($s)] ?? null)));
            if ([] !== $merged) {
                $this->driver->note($merged, $name, Outcome::Merged->value, Reason::Lane->value, $now, $ref);
            }
            $out['merged'] += \count($merged);
            $out['queued'] += \count(array_filter($group, static fn(int|string $s): bool => Outcome::Queued->value === ($outcome[Subject::key($s)] ?? null)));
        }
        $this->pin($subjects);

        return $out;
    }

    /** What waits for this subject in the lane `name`, if anything. */
    public function arrival(int|string $subject, string $name): ?Arrival
    {
        $this->dag->node($name);

        return $this->lanes()->arrivals([$subject], $name)[Subject::key($subject)] ?? null;
    }

    /**
     * EVERY VERSION WAITING for this subject in the lane `name`, oldest first —
     * what a lane keeping them all has gathered. A lane keeping one version
     * gives that one; a subject with nothing waiting gives nothing.
     *
     * @return list<string>
     */
    public function refs(int|string $subject, string $name): array
    {
        $arrival = $this->arrival($subject, $name);
        if (null === $arrival) {
            return [];
        }
        $kept = self::decodeRefs($arrival->refs);

        return [] !== $kept ? $kept : (null !== $arrival->ref ? [$arrival->ref] : []);
    }

    /**
     * CONCLUDE WHAT NO WORKER DOES, on the candidates, for every node:
     *
     *     wait   `done` when a signal was received since the node last went
     *            back; `failed` once `timeout` has passed since its parents
     *            concluded
     *     lane   a due arrival enters: the previous pass is archived and the
     *            lane is `done` (`Lane`); reported as `entered`
     *     grace  an optional node still untaken `grace` after its parents
     *            concluded is `skipped`
     *
     * Time runs from the LATEST accepted parent's conclusion; a node without
     * parents has no clock and never times out. Return `[node => [status =>
     * count]]` for what was written. `limit` bounds a pass: at most that many
     * subjects written per node.
     *
     * @return array<string, array<string, int>>
     */
    public function settle(mixed $candidates, ?int $limit = null): array
    {
        if (null !== $limit && $limit <= 0) {
            return [];
        }
        $now = $this->now();
        $out = [];
        foreach ($this->dag as $node) {
            if (null !== $node->lane) {
                $entered = $this->letIn($node, $node->lane, $candidates, $now, $limit);
                if ($entered > 0) {
                    $out[$node->name][Outcome::Entered->value] = $entered;
                }

                continue;
            }
            $graced = null !== $node->grace;
            foreach ($this->variants($node->name) as $variant) {
                $graced = $graced || null !== $variant->grace;
            }
            if (null === $node->wait && !$graced) {
                continue;
            }
            $after = $this->dag->descendants($node->name);
            $parents = $node->customJoin() ? [] : $node->parents;
            $ready = [];
            foreach ($this->driver->scan($candidates, $node->name, [$node->name, ...$node->parents, ...$after], $parents, self::PAGE, $now, $after) as $page) {
                foreach ($page as $entry) {
                    if ($this->mine($entry) && $this->dag->claimable($node->name, self::dueAway($node->name, $entry, $now))) {
                        $ready[Subject::key($entry->subject)] ??= $entry;
                    }
                }
            }
            if ([] === $ready) {
                continue;
            }
            $subjects = array_values(array_map(static fn(Entry $e): int|string => $e->subject, $ready));
            $heard = [];
            $wentBack = [];
            if (null !== $node->wait) {
                $heard = $this->driver->latest($subjects, $node->wait, Reason::Signal->value);
                $wentBack = $this->driver->latest($subjects, $node->name, null);
            }
            $decided = [];
            $count = 0;
            foreach ($ready as $key => $entry) {
                if (null !== $limit && $count >= $limit) {
                    break;
                }
                $since = self::joinedSince($node, $entry);
                $seenBy = $this->settings($node->name, $entry->policy);
                if (null !== $node->wait) {
                    $at = $heard[$key] ?? null;
                    if (null !== $at && strcmp($at, $wentBack[$key] ?? '') >= 0) {
                        $decided[Status::Done->value][] = [$entry->subject, $entry->revision];
                        ++$count;
                        continue;
                    }
                    if (null !== $seenBy->timeout && null !== $since
                        && strcmp(Time::shift($since, Time::seconds($seenBy->timeout)), $now) <= 0) {
                        $decided[Status::Failed->value][] = [$entry->subject, $entry->revision];
                        ++$count;
                    }
                } elseif (null !== $since && null !== $seenBy->grace
                    && strcmp(Time::shift($since, Time::seconds($seenBy->grace)), $now) <= 0) {
                    $decided[Status::Skipped->value][] = [$entry->subject, $entry->revision];
                    ++$count;
                }
            }
            foreach ($decided as $status => $chosen) {
                $written = $this->driver->insertIfUnchanged($node->name, $chosen, (string) $status, $now, null);
                $this->pin($written);
                if ([] !== $written) {
                    $out[$node->name][(string) $status] = \count($written);
                }
            }
        }

        return $out;
    }

    // -- read ------------------------------------------------------------

    /**
     * What is recorded for ONE subject — exactly what `Dag::claimable()` reads.
     *
     * @return array<string, Status>
     */
    public function progress(int|string $subject): array
    {
        // A JOURNAL ONLY SPEAKS ABOUT ITS OWN SUBJECTS: one pinned to another
        // graph reads empty, as it claims empty.
        if (null !== $this->version && $this->version !== ($this->versions([$subject])[Subject::key($subject)] ?? $this->version)) {
            return [];
        }

        return array_map(Status::from(...), $this->driver->progress($subject));
    }

    /**
     * `progress` for many subjects, keyed by `Subject::key()`, each subject
     * asked present — in one read when the driver offers it.
     *
     * @param iterable<int|string> $subjects
     *
     * @return array<string, array<string, Status>>
     */
    public function progressMany(iterable $subjects): array
    {
        $subjects = Subject::unique($subjects);
        if ([] === $subjects) {
            return [];
        }
        $read = [];
        if ($this->driver instanceof ProgressMany) {
            $read = $this->driver->progressMany($subjects);
        } else {
            foreach ($subjects as $subject) {
                $read[Subject::key($subject)] = $this->driver->progress($subject);
            }
        }
        $pinned = null === $this->version ? [] : $this->versions($subjects);
        $out = [];
        foreach ($subjects as $subject) {
            $key = Subject::key($subject);
            $elsewhere = isset($pinned[$key]) && $pinned[$key] !== $this->version;
            $out[$key] = $elsewhere ? [] : array_map(Status::from(...), $read[$key] ?? []);
        }

        return $out;
    }

    /**
     * Every row taken away from ONE subject — oldest first, each with
     * `archived_at` and `reason`.
     *
     * @return list<array{node: string, status: string, started_at: string, finished_at: ?string, lease: ?string, archived_at: string, reason: string}>
     */
    public function history(int|string $subject): array
    {
        return $this->driver->history($subject);
    }

    /**
     * KEEP THE HISTORY FROM GROWING FOREVER: delete what was archived before
     * `before`, except the rows a bound counts (`COUNTED`): a limit cannot be
     * reset by a clean-up. Return the count deleted.
     */
    public function pruneHistory(string|\DateTimeInterface $before): int
    {
        return $this->driver->pruneHistory(Time::stamp($before), self::COUNTED);
    }

    /** How many times a declared loop sent this subject back through `name`. */
    public function passes(int|string $subject, string $name): int
    {
        $this->dag->node($name);

        return $this->driver->archived([$subject], $name, Reason::Loop->value)[Subject::key($subject)] ?? 0;
    }

    /** How many times a failure of `name` was retried for this subject. */
    public function retries(int|string $subject, string $name): int
    {
        $this->dag->node($name);

        return $this->driver->archived([$subject], $name, Reason::Retry->value)[Subject::key($subject)] ?? 0;
    }

    /**
     * What a BATCH went through — `[subject => [[node, end, seconds, status], …]]`,
     * subjects as text. Only nodes that worked; a node still running ends `at`.
     *
     * @param iterable<int|string> $subjects
     *
     * @return array<string, list<array{0: string, 1: string, 2: float, 3: string}>>
     */
    public function stages(iterable $subjects, string|\DateTimeInterface $at): array
    {
        return $this->reading('journal stages')->stages(Subject::unique($subjects), Time::stamp($at));
    }

    /**
     * How many subjects stand where, for this node — every status present,
     * and for a lane, `waiting`: the arrivals waiting.
     *
     * @return array<string, int>
     */
    public function counts(string $name): array
    {
        $this->dag->node($name);
        $byStatus = $this->reading('journal counts')->statusCounts($name);
        $out = [];
        foreach ([Status::Running->value, Status::Scheduled->value, ...Status::concluded()] as $status) {
            $out[$status] = (int) ($byStatus[$status] ?? 0);
        }
        if (null !== $this->dag->node($name)->lane) {
            $out[Outcome::Waiting->value] = $this->lanes()->queued($name);
        }

        return $out;
    }

    /**
     * ALL PARENTS CONCLUDED for this subject, in the driver's terms: a boolean
     * for the memory driver, an SQL condition for a database one.
     */
    public function parentsConcluded(string $name, int|string $subject): mixed
    {
        return $this->reading('journal parentsConcluded')->parentsConcluded($this->dag->node($name)->parents, $subject);
    }

    /**
     * WHERE EACH NODE STANDS, for monitoring — `[node => […]]`, plain numbers
     * an application samples, charts and alerts on:
     *
     *     <status>        how many rows, as `counts()` gives them
     *     oldest_running  seconds the longest-running row has run
     *     next_due        seconds until the earliest retry is due (negative: overdue)
     *     oldest_waiting  seconds the oldest arrival has waited (lanes)
     *     ready           with `candidates`, for a node a claim takes: how many
     *                     a claim could take now
     *     oldest_ready    seconds since the oldest of those became ready
     *
     * A time is null when nothing stands there, or when the driver keeps no
     * times (`NodeTimes`, optional). Nothing is written.
     *
     * A GROWING NODE shows as `ready` rising between samples; A STARVING ONE as
     * `oldest_ready` rising while `ready` does not fall; A STUCK WORKER as
     * `oldest_running` past the node's lease.
     *
     * @param list<string>|null $nodes only these nodes
     *
     * @return array<string, array<string, int|float|null>>
     */
    public function snapshot(mixed $candidates = null, ?array $nodes = null): array
    {
        $this->reading('journal snapshot');
        $now = $this->now();
        $wanted = null === $nodes ? null : array_map(fn(string $n): string => $this->dag->node($n)->name, $nodes);
        $out = [];
        foreach ($this->dag as $node) {
            if (null !== $wanted && !\in_array($node->name, $wanted, true)) {
                continue;
            }
            $entry = $this->counts($node->name);
            $times = $this->driver instanceof NodeTimes ? $this->driver->nodeTimes($node->name) : [];
            $entry['oldest_running'] = Time::age($now, $times[Status::Running->value] ?? null);
            $sinceDue = Time::age($now, $times[Status::Scheduled->value] ?? null);
            $entry['next_due'] = null === $sinceDue ? null : -$sinceDue;
            if (null !== $node->lane) {
                $entry['oldest_waiting'] = Time::age($now, $this->lanes()->waitingSince($node->name));
            }
            if (null !== $candidates && null === $node->wait && null === $node->lane) {
                [$entry['ready'], $entry['oldest_ready']] = $this->ready($node, $candidates, $now);
            }
            $out[$node->name] = $entry;
        }

        return $out;
    }

    /** The node whose WORKING state this is, if any. */
    public function nodeForState(string $state): ?string
    {
        foreach ($this->dag as $node) {
            if ($node->working === $state) {
                return $node->name;
            }
        }

        return null;
    }

    // -- inside ----------------------------------------------------------

    /**
     * How many candidates the rule lets a claim of `node` take now, and the
     * age of the oldest of them — read, never written.
     *
     * @return array{0: int, 1: ?float}
     */
    private function ready(Node $node, mixed $candidates, string $now): array
    {
        $after = $this->dag->descendants($node->name);
        $parents = $node->customJoin() ? [] : $node->parents;
        $ready = 0;
        $first = null;
        $seen = [];
        foreach ($this->driver->scan($candidates, $node->name, [$node->name, ...$node->parents, ...$after], $parents, self::SNAPSHOT_PAGE, $now, $after) as $page) {
            foreach ($page as $entry) {
                $key = Subject::key($entry->subject);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                if (!$this->mine($entry) || !$this->takable($node, $after, self::dueAway($node->name, $entry, $now), true)) {
                    continue;
                }
                ++$ready;
                $since = self::readyAt($node, $entry);
                if (null !== $since && (null === $first || strcmp($since, $first) < 0)) {
                    $first = $since;
                }
            }
        }

        return [$ready, Time::age($now, $first)];
    }

    /**
     * @param list<string>          $after
     * @param array<string, string> $rows
     */
    private function takable(Node $node, array $after, array $rows, bool $requireParents): bool
    {
        if ($requireParents) {
            return $this->dag->claimable($node->name, $rows);
        }
        if (\array_key_exists($node->name, $rows)) {
            return false;
        }
        foreach ($after as $descendant) {
            if (\array_key_exists($descendant, $rows)) {
                return false;
            }
        }

        return true;
    }

    /**
     * MERGE BY READING WHAT WAITS, THEN WRITING — under the driver's guard, on
     * each subject's place in this lane.
     *
     * `first` and `last` decide without looking, so one atomic write does
     * them. Keeping every ref, or asking a function, cannot: two workers
     * arriving at once would each start from the state before the other, and
     * one would overwrite the other's version. Subjects ending up with the
     * same refs are written together.
     *
     * @param list<int|string>                               $subjects
     * @param array{queued: int, merged: int, skipped: int} $out
     */
    private function keepEveryRef(LaneDriver $driver, string $name, array $subjects, Lane $lane, ?string $ref, string $now, bool $urgent, array &$out): void
    {
        $merger = $this->merger($lane);
        $dropped = [];
        $merged = [];
        $keys = array_map(static fn(int|string $s): string => "arrival|{$name}|" . Subject::key($s), $subjects);
        $this->driver->guard($keys, function () use ($driver, $name, $subjects, $lane, $ref, $now, $urgent, $merger, &$dropped, &$merged): void {
            $current = $driver->arrivals($subjects, $name);
            /** @var array<string, array{0: list<string>, 1: list<int|string>}> $same */
            $same = [];
            foreach ($subjects as $subject) {
                $waiting = $current[Subject::key($subject)] ?? null;
                $kept = null === $waiting ? [] : self::decodeRefs($waiting->refs);
                $wanted = array_values(array_map(strval(...), [...$merger($kept, $ref)]));
                $id = (string) json_encode($wanted);
                $same[$id] ??= [$wanted, []];
                $same[$id][1][] = $subject;
                foreach ($kept as $gone) {
                    if (!\in_array($gone, $wanted, true)) {
                        $dropped[$gone][] = $subject;
                    }
                }
            }
            foreach ($same as [$wanted, $group]) {
                $last = [] === $wanted ? null : $wanted[\count($wanted) - 1];
                $outcome = $driver->arrive($name, $group, $last, $now, Merge::Set->value, $lane->position->value, $urgent, self::encodeRefs($wanted));
                foreach ($group as $subject) {
                    if (Outcome::Merged->value === ($outcome[Subject::key($subject)] ?? null)) {
                        $merged[] = $subject;
                    }
                }
            }
        });
        // A REF LET GO IS STILL SAID: past `maxSize`, or refused by the
        // function, it leaves a trace rather than vanishing.
        foreach ($dropped as $gone => $group) {
            $this->driver->note($group, $name, Outcome::Dropped->value, Reason::Lane->value, $now, (string) $gone);
        }
        if ([] !== $merged) {
            $this->driver->note($merged, $name, Outcome::Merged->value, Reason::Lane->value, $now, $ref);
        }
        $out['merged'] += \count($merged);
        $out['queued'] += \count($subjects) - \count($merged);
    }

    /**
     * The function this lane merges with: `all`'s, or the application's under
     * the name the lane gives.
     *
     * @return \Closure(list<string>, ?string): iterable<string>
     */
    private function merger(Lane $lane): \Closure
    {
        $named = $lane->merger();
        if (null === $named) {
            $size = $lane->maxSize;

            return static function (array $kept, ?string $arriving) use ($size): array {
                /** @var list<string> $kept */
                return \array_slice(null === $arriving ? $kept : [...$kept, $arriving], -$size);
            };
        }
        if (!isset($this->mergers[$named])) {
            $known = array_keys($this->mergers);
            sort($known);
            throw new \InvalidArgumentException(\sprintf(
                "lane merge '%s' names a function the journal was not given — pass it as new NodeJournal(..., mergers: ['%s' => \$fn]); it has [%s]",
                $lane->merge,
                $named,
                implode(', ', $known),
            ));
        }

        return $this->mergers[$named];
    }

    /**
     * THE LANE'S DOOR. An arrival enters when the subject's parents are
     * joined, nothing of its previous pass runs, and it is due: urgent, or
     * past both its `delay` (from its place) and its `cooldown` (from the end
     * of the previous pass) — or past `maxWait` from its first arrival
     * whatever the rest. Due arrivals enter in the order of their places,
     * within the lane's `rate`.
     */
    private function letIn(Node $node, Lane $lane, mixed $candidates, string $now, ?int $limit): int
    {
        $driver = $this->lanes();
        $pass = [$node->name, ...$this->dag->descendants($node->name)];
        $entries = [];
        $order = [];
        // No pre-filter on the lane's own row: the previous pass holds one.
        foreach ($this->driver->scan($candidates, null, [...$pass, ...$node->parents], [], self::PAGE, $now) as $page) {
            foreach ($page as $entry) {
                $key = Subject::key($entry->subject);
                if (!isset($entries[$key]) && $this->mine($entry)) {
                    $order[$key] = \count($order);
                    $entries[$key] = $entry;
                }
            }
        }
        if ([] === $entries) {
            return 0;
        }
        $waiting = $driver->arrivals(array_values(array_map(static fn(Entry $e): int|string => $e->subject, $entries)), $node->name);
        $due = [];
        foreach ($waiting as $key => $arrival) {
            $entry = $entries[$key] ?? null;
            if (null === $entry || !$this->dag->joined($node->name, $entry->rows)) {
                continue;
            }
            foreach ($pass as $x) {
                $status = $entry->rows[$x] ?? null;
                if (Status::Running->value === $status || Status::Scheduled->value === $status) {
                    continue 2;
                }
            }
            $tuned = $this->settings($node->name, $entry->policy)->lane ?? $lane;   // as the subject's policy tunes it
            if (!$arrival->urgent) {
                $ready = [$arrival->place];
                if (null !== $tuned->delay) {
                    $ready[] = Time::shift($arrival->place, Time::seconds($tuned->delay));
                }
                $ended = array_values(array_intersect_key($entry->finished, array_flip($pass)));
                if (null !== $tuned->cooldown && [] !== $ended) {
                    $ready[] = Time::shift(max($ended), Time::seconds($tuned->cooldown));
                }
                $late = null !== $tuned->maxWait && strcmp(Time::shift($arrival->arrivedAt, Time::seconds($tuned->maxWait)), $now) <= 0;
                if (strcmp(max($ready), $now) > 0 && !$late) {
                    continue;
                }
            }
            $due[] = [$arrival->place, $order[$key], $entry];
        }
        usort($due, static fn(array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        $chosen = array_map(static fn(array $d): Entry => $d[2], $due);
        if (null !== $limit) {
            $chosen = \array_slice($chosen, 0, $limit);
        }
        if ([] === $chosen) {
            return 0;
        }
        $enter = static function (array $part) use ($driver, $node, $pass, $now): array {
            /** @var list<array{0: int|string, 1: int}> $part */
            return $driver->enter($node->name, $part, $pass, $now);
        };

        // Due arrivals enter in the order of their places, within the lane's rate.
        return \count($this->limited($node) ? $this->withinLimits($node, $chosen, $now, $enter) : $enter(self::pairs($chosen)));
    }

    /** @param list<string> $refs */
    private static function encodeRefs(array $refs): ?string
    {
        return [] === $refs ? null : json_encode($refs, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
    }

    /** @return list<string> */
    private static function decodeRefs(?string $stored): array
    {
        if (null === $stored || '' === $stored) {
            return [];
        }
        $refs = json_decode($stored, true, 512, \JSON_THROW_ON_ERROR);

        return \is_array($refs) ? array_values(array_map(static fn(mixed $r): string => \is_scalar($r) ? (string) $r : '', $refs)) : [];
    }

    private function lanes(): LaneDriver
    {
        if (!$this->driver instanceof LaneDriver) {
            throw new MissingCapability('lane', 'a lane', LaneDriver::class);
        }

        return $this->driver;
    }

    /**
     * THE ONE GROUP THIS CLAIM MAY TAKE, or nothing.
     *
     * Candidates keep the order the application gave them, so the group that
     * goes is the one whose members were offered first. A group goes when it
     * is FULL, or when its oldest member has been ready longer than `maxWait`
     * — an incomplete group then goes as it is, and the worker sees how many
     * it really got.
     *
     * @param list<Entry> $ready
     *
     * @return list<Entry>
     */
    private function group(Node $node, array $ready, string $now): array
    {
        $group = $node->group ?? throw new \LogicException('not a grouping node');
        $gathered = [];
        foreach ($ready as $entry) {
            if ($group->perKey && null === $entry->key) {
                // NO KEY IS NOT ONE KEY: grouping every keyless candidate
                // together would hand out groups nobody declared.
                throw new \InvalidArgumentException(\sprintf(
                    "node '%s' groups by key, and a candidate carries none: name the key column `grampy_key` in SQL, "
                    . 'pass new Keyed(subject, key) otherwise, or give the items adapter a groupOf',
                    $node->name,
                ));
            }
            $gathered[$group->perKey ? "k:{$entry->key}" : '*'][] = $entry;
        }
        $late = null === $group->maxWait ? null : Time::shift($now, -Time::seconds($group->maxWait));
        foreach ($gathered as $members) {
            if (\count($members) >= $group->size) {
                return \array_slice($members, 0, $group->size);
            }
            // AN INCOMPLETE GROUP GOES ONLY WHEN IT HAS WAITED. A node with no
            // parents has no clock, so no way to be late.
            $oldest = null;
            foreach ($members as $member) {
                $at = self::readyAt($node, $member);
                if (null !== $at && (null === $oldest || strcmp($at, $oldest) < 0)) {
                    $oldest = $at;
                }
            }
            if (null !== $late && null !== $oldest && strcmp($oldest, $late) <= 0) {
                return $members;
            }
        }

        return [];
    }

    /**
     * RATE AND CONCURRENCY, decided here, kept by the storage's guard.
     *
     * Candidates are grouped by budget — one for the node, or one per policy
     * with `Per::Policy`. Under the guard of every budget's keys, each group is
     * cut to what `concurrency` leaves free and what the rate bands let
     * through (`Rate::admit()`), written by `write` — a claim, or a lane
     * letting subjects in — and the bands advance by what was actually written.
     *
     * @param list<Entry>                                                    $chosen
     * @param callable(list<array{0: int|string, 1: int}>): list<int|string> $write
     *
     * @return list<int|string>
     */
    private function withinLimits(Node $node, array $chosen, string $now, callable $write): array
    {
        $driver = $this->driver instanceof LimitDriver
            ? $this->driver
            : throw new MissingCapability('limit', \sprintf("node '%s'", $node->name), LimitDriver::class);
        /** @var array<string, array{policy: ?string, seenBy: Node, entries: list<Entry>, bands: list<string>}> $budgets */
        $budgets = [];
        foreach ($chosen as $entry) {
            $policy = Per::Policy === $node->per ? $entry->policy : null;
            $id = null === $policy ? '*' : "p:{$policy}";
            if (!isset($budgets[$id])) {
                $prefix = "{$node->name}|{$id}";
                $seenBy = Per::Policy === $node->per ? $this->settings($node->name, $policy) : $node;
                $budgets[$id] = ['policy' => $policy, 'seenBy' => $seenBy, 'entries' => [], 'bands' => array_map(static fn(int $i): string => "rate|{$prefix}|{$i}", array_keys($seenBy->rate))];
            }
            $budgets[$id]['entries'][] = $entry;
        }
        ksort($budgets, \SORT_STRING);
        $guarded = [];
        foreach ($budgets as $id => $budget) {
            array_push($guarded, ...$budget['bands']);
            $guarded[] = "running|{$node->name}|{$id}";
        }
        $instant = Time::epoch($now);

        return $this->driver->guard($guarded, function () use ($driver, $node, $budgets, $instant, $write): array {
            $stored = $driver->limits(array_merge(...array_values(array_map(static fn(array $b): array => $b['bands'], $budgets))));
            $taken = [];
            $advanced = [];
            foreach ($budgets as $budget) {
                $seenBy = $budget['seenBy'];
                $allowed = \count($budget['entries']);
                if (null !== $seenBy->concurrency) {
                    $busy = $driver->running($node->name, Per::Policy === $node->per ? [$budget['policy']] : null);
                    $allowed = min($allowed, max(0, $seenBy->concurrency - $busy));
                }
                $tats = array_map(static fn(string $k): ?float => $stored[$k] ?? null, $budget['bands']);
                if ([] !== $seenBy->rate) {
                    [$allowed] = Rate::admit($seenBy->rate, $tats, $instant, $allowed);
                }
                $written = $allowed > 0 ? $write(self::pairs(\array_slice($budget['entries'], 0, $allowed))) : [];
                array_push($taken, ...$written);
                if ([] !== $seenBy->rate) {
                    [, $moved] = Rate::admit($seenBy->rate, $tats, $instant, \count($written));
                    foreach ($budget['bands'] as $i => $band) {
                        $advanced[$band] = $moved[$i];
                    }
                }
            }
            if ([] !== $advanced) {
                $driver->setLimits($advanced);
            }

            return $taken;
        });
    }

    /**
     * @param list<Entry> $entries
     *
     * @return list<array{0: int|string, 1: int}>
     */
    private static function pairs(array $entries): array
    {
        return array_map(static fn(Entry $e): array => [$e->subject, $e->revision], $entries);
    }

    // -- versions --------------------------------------------------------

    /**
     * The graph the subject is pinned to — a `Document::identity()` — or null
     * before its first write. IT IS WRITTEN ONCE: only `migrate` changes it.
     */
    public function pinned(int|string $subject): ?string
    {
        return $this->versions([$subject])[Subject::key($subject)] ?? null;
    }

    /**
     * MOVE SUBJECTS FROM `source` — another version of this graph — ONTO THIS
     * ONE, or refuse them all.
     *
     * `mapping` names what became of each source node: another name, or null
     * when it is gone (its rows are archived, reason `migrate`). A node left
     * out keeps its name, and must exist here.
     *
     * A subject is COMPLIANT when its journal could have been written on this
     * graph: every row, once renamed, stands where the rule of this graph lets
     * a row stand — its parents joined. A dropped node held by a worker makes
     * a subject non-compliant too.
     *
     * ALL OR NOTHING: every subject is checked before anything is written;
     * one failure raises `MigrationError` naming each non-compliant subject
     * and why, and nothing moves. Return the count migrated.
     *
     * @param iterable<int|string>       $subjects
     * @param array<string, string|null> $mapping
     */
    public function migrate(iterable $subjects, Graph $source, array $mapping = []): int
    {
        if (null === $this->version || !$this->driver instanceof VersionDriver) {
            throw new \LogicException('migrate needs a journal built on a Graph');
        }
        $from = $source->document->identity();
        if ($from === $this->version) {
            throw new \InvalidArgumentException(\sprintf("the source is already '%s'", $this->version));
        }
        $there = array_map(static fn(Node $n): string => $n->name, $source->dag->nodes());
        $unknown = array_values(array_diff(array_map('strval', array_keys($mapping)), $there));
        if ([] !== $unknown) {
            sort($unknown);
            throw new \InvalidArgumentException(\sprintf('mapping names nodes the source does not have: [%s]', implode(', ', $unknown)));
        }
        $full = [];
        foreach ($there as $name) {
            $full[$name] = \array_key_exists($name, $mapping) ? $mapping[$name] : $name;
        }
        $missing = [];
        foreach ($full as $name => $target) {
            if (null !== $target && !$this->dag->has($target)) {
                $missing[] = $name;
            }
        }
        if ([] !== $missing) {
            sort($missing);
            throw new \InvalidArgumentException(\sprintf(
                "source nodes with nowhere to go on '%s': [%s] — map them to a node or to null",
                $this->version,
                implode(', ', $missing),
            ));
        }
        $landed = array_values(array_filter($full, static fn(?string $t): bool => null !== $t));
        if (\count($landed) !== \count(array_unique($landed))) {
            throw new \InvalidArgumentException('two source nodes are mapped onto the same node');
        }

        $subjects = Subject::unique($subjects);
        $pinned = $this->driver->versions($subjects);
        $ours = array_values(array_filter($subjects, static fn(int|string $s): bool => ($pinned[Subject::key($s)] ?? $from) === $from));
        $progresses = [];
        foreach ($ours as $subject) {
            $progresses[Subject::key($subject)] = $this->driver->progress($subject);
        }
        $problems = [];
        $plans = [];
        foreach ($subjects as $subject) {
            $key = Subject::key($subject);
            if (($pinned[$key] ?? $from) !== $from) {
                $problems[$key] = \sprintf("pinned to '%s'", $pinned[$key] ?? '');

                continue;
            }
            $progress = $progresses[$key] ?? [];
            $held = [];
            $moved = [];
            foreach ($progress as $name => $status) {
                $target = \array_key_exists($name, $full) ? $full[$name] : $name;
                if (null === $target) {
                    if (\in_array($status, [Status::Running->value, Status::Scheduled->value], true)) {
                        $held[] = $name;
                    }

                    continue;
                }
                $moved[$target] = $status;
            }
            if ([] !== $held) {
                sort($held);
                $problems[$key] = \sprintf('[%s] would be dropped while held or scheduled', implode(', ', $held));

                continue;
            }
            $stray = array_values(array_filter(array_map('strval', array_keys($moved)), fn(string $n): bool => !$this->dag->has($n)));
            if ([] !== $stray) {
                sort($stray);
                $problems[$key] = \sprintf("rows on [%s], which '%s' lacks", implode(', ', $stray), $this->version);

                continue;
            }
            $unjoined = array_values(array_filter(array_map('strval', array_keys($moved)), fn(string $n): bool => !$this->dag->joined($n, $moved)));
            if ([] !== $unjoined) {
                sort($unjoined);
                $problems[$key] = \sprintf("[%s] could not have run on '%s': their parents are not joined", implode(', ', $unjoined), $this->version);

                continue;
            }
            $plans[] = $subject;
        }
        if ([] !== $problems) {
            throw new MigrationError($problems);
        }
        // Every renamed or dropped node is passed, rows or not: an arrival
        // waiting in a lane follows its node too.
        $rename = [];
        $drop = [];
        foreach ($full as $name => $target) {
            if (null === $target) {
                $drop[] = (string) $name;
            } elseif ($target !== $name) {
                $rename[(string) $name] = $target;
            }
        }
        sort($drop);
        $now = $this->now();
        foreach ($plans as $subject) {
            $this->driver->rewrite($subject, $rename, $drop, $this->version, $now);
        }

        return \count($plans);
    }

    /** The node as a subject of `policy` sees it. */
    public function settings(string $name, ?string $policy): Node
    {
        $this->dag->node($name);

        return null === $this->graph ? $this->dag->node($name) : $this->graph->variant($policy)->node($name);
    }

    /**
     * The node as each policy of the graph sees it.
     *
     * @return array<string, Node> policy => node
     */
    private function variants(string $name): array
    {
        $out = [];
        foreach (array_keys($this->graph->policies ?? []) as $policy) {
            $out[(string) $policy] = $this->settings($name, (string) $policy);
        }

        return $out;
    }

    /**
     * `[Subject::key() => the value of setting on name for the subject's policy]`
     * — one read of the policies, and only when a policy changes it.
     *
     * @param list<int|string> $subjects
     *
     * @return array<string, mixed>
     */
    private function perPolicy(string $name, string $setting, array $subjects): array
    {
        $node = $this->dag->node($name);
        $out = [];
        if (null === $this->graph || !$this->graph->overrides($name, $setting)) {
            foreach ($subjects as $subject) {
                $out[Subject::key($subject)] = $node->{$setting};
            }

            return $out;
        }
        $policies = $this->driver->policies($subjects);
        foreach ($subjects as $subject) {
            $key = Subject::key($subject);
            $out[$key] = $this->settings($name, $policies[$key] ?? null)->{$setting};
        }

        return $out;
    }

    /** True when this journal may work on the subject: pinned to its graph, or not yet pinned. */
    private function mine(Entry $entry): bool
    {
        return null === $this->version || null === $entry->version || $entry->version === $this->version;
    }

    /** @param list<int|string> $subjects pinned to this graph, if they are pinned to none */
    private function pin(array $subjects): void
    {
        if (null !== $this->version && [] !== $subjects && $this->driver instanceof VersionDriver) {
            $this->driver->pin($subjects, $this->version);
        }
    }

    /**
     * @param list<int|string> $subjects
     *
     * @return array<string, string>
     */
    private function versions(array $subjects): array
    {
        return $this->driver instanceof VersionDriver ? $this->driver->versions($subjects) : [];
    }

    /** True when a claim on the node is cut by a rate or a concurrency, under some policy. */
    private function limited(Node $node): bool
    {
        if ($node->limited()) {
            return true;
        }
        foreach ($this->variants($node->name) as $variant) {
            if ($variant->limited()) {
                return true;
            }
        }

        return false;
    }

    /** @param string $why what needs the reading capability */
    private function reading(string $why): ReadingDriver
    {
        if (!$this->driver instanceof ReadingDriver) {
            throw new MissingCapability('reading', $why, ReadingDriver::class);
        }

        return $this->driver;
    }

    /**
     * The rows the rule reads: a `scheduled` row of `name` due by `now` counts
     * as absent — the node may be taken again.
     *
     * @return array<string, string>
     */
    private static function dueAway(string $name, Entry $entry, string $now): array
    {
        $rows = $entry->rows;
        if (Status::Scheduled->value === ($rows[$name] ?? null) && strcmp($entry->due[$name] ?? $now, $now) <= 0) {
            unset($rows[$name]);
        }

        return $rows;
    }

    /**
     * When the node became joined: the latest conclusion among the parents it
     * accepts. Null for a root, or when no conclusion time is known.
     */
    private static function joinedSince(Node $node, Entry $entry): ?string
    {
        $latest = null;
        foreach ($node->parents as $parent) {
            $status = $entry->rows[$parent] ?? null;
            $at = $entry->finished[$parent] ?? null;
            if (null !== $status && null !== $at && \in_array($status, Dag::accepts($node, $parent), true)
                && (null === $latest || strcmp($at, $latest) > 0)) {
                $latest = $at;
            }
        }

        return $latest;
    }

    /** When this subject became ready: the last of its parents to conclude. */
    private static function readyAt(Node $node, Entry $entry): ?string
    {
        $latest = null;
        foreach ($node->parents as $parent) {
            $at = $entry->finished[$parent] ?? null;
            if (null !== $at && (null === $latest || strcmp($at, $latest) > 0)) {
                $latest = $at;
            }
        }

        return $latest;
    }
}
