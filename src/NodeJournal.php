<?php

declare(strict_types=1);

namespace Quazardous\GramPHP;

use Quazardous\GramPHP\Driver\CoreDriver;
use Quazardous\GramPHP\Driver\MissingCapability;
use Quazardous\GramPHP\Driver\NodeTimes;
use Quazardous\GramPHP\Driver\ProgressMany;
use Quazardous\GramPHP\Driver\ReadingDriver;

/**
 * THE NODE JOURNAL — who started what, and where it stands.
 *
 *     claim     take a node for eligible subjects — a `Lease`, with its token
 *     conclude  finish it, or declare it failed — with the lease's token
 *     skip      give up an optional node
 *     adopt     record work already done that the journal does not know
 *     forget    erase it — "never started", the initial state
 *     release   give back the leases of a dead worker
 *     expire    give back every lease held longer than its node allows
 *     signal    record that an awaited event happened, for subjects
 *     settle    conclude waits, and skip optional nodes past their grace
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

    /** @var (\Closure(): (string|\DateTimeInterface))|null */
    private readonly ?\Closure $clock;

    private readonly \Random\Randomizer $rng;

    /**
     * @param Dag|list<Node>                          $dag   checked when the journal is built
     * @param (callable(): (string|\DateTimeInterface))|null $clock defaults to the driver's: one time for every process
     */
    public function __construct(
        public readonly CoreDriver $driver,
        Dag|array $dag,
        ?callable $clock = null,
        ?\Random\Randomizer $rng = null,
    ) {
        $this->dag = $dag instanceof Dag ? $dag : new Dag(...$dag);
        $this->dag->check();
        $this->clock = null === $clock ? null : \Closure::fromCallable($clock);
        $this->rng = $rng ?? new \Random\Randomizer();
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
        $token = bin2hex(random_bytes(16));
        if ($limit <= 0) {
            return new Lease([], $token);
        }
        $after = $this->dag->descendants($name);
        $parents = $requireParents && !$node->customJoin() ? $node->parents : [];
        $now = $this->now();
        $chosen = [];
        $seen = [];
        foreach ($this->driver->scan($candidates, $name, [$name, ...$node->parents, ...$after], $parents, max($limit, self::PAGE), $now, $after) as $page) {
            foreach ($page as $entry) {
                $key = Subject::key($entry->subject);
                if (isset($seen[$key])) {
                    continue;                 // a subject listed twice counts once
                }
                $seen[$key] = true;
                if (!$this->takable($node, $after, self::dueAway($name, $entry, $now), $requireParents)) {
                    continue;
                }
                $chosen[] = [$entry->subject, $entry->revision];
                if (\count($chosen) >= $limit) {
                    break 2;
                }
            }
        }
        $taken = [] === $chosen
            ? []
            : $this->driver->insertIfUnchanged($name, $chosen, Status::Running->value, $now, $token);

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

        if (null !== $node->retry && Status::Failed->value === $status && null === $branch) {
            $tries = $this->driver->archived($subjects, $name, Reason::Retry->value);
            $byDue = [];
            foreach ($subjects as $subject) {
                $done = $tries[Subject::key($subject)] ?? 0;
                if ($done < $node->retry->limit) {
                    $wait = $node->retry->wait($done + 1, $this->rng);
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
                if (isset($seen[$key]) || \array_key_exists($name, $entry->rows) || !$this->dag->joined($name, $entry->rows)) {
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

        return \count($this->driver->insertIfUnchanged($name, $chosen, Status::Skipped->value, $now, null));
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

        return [] === $subjects ? 0 : $this->driver->adopt($name, $subjects, $this->now());
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

        return $this->driver->release($name, Time::stamp($olderThan), $this->now());
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
            if (null === $node->lease) {
                continue;
            }
            $count = $this->driver->release($node->name, Time::shift($now, -Time::seconds($node->lease)), $now);
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

        return [] === $subjects ? 0 : $this->driver->enroll($subjects, $policy);
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

    /**
     * CONCLUDE WHAT NO WORKER DOES, on the candidates, for every node:
     *
     *     wait   `done` when a signal was received since the node last went
     *            back; `failed` once `timeout` has passed since its parents
     *            concluded
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
            if (null === $node->wait && null === $node->grace) {
                continue;
            }
            $after = $this->dag->descendants($node->name);
            $parents = $node->customJoin() ? [] : $node->parents;
            $ready = [];
            foreach ($this->driver->scan($candidates, $node->name, [$node->name, ...$node->parents, ...$after], $parents, self::PAGE, $now, $after) as $page) {
                foreach ($page as $entry) {
                    if ($this->dag->claimable($node->name, self::dueAway($node->name, $entry, $now))) {
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
                if (null !== $node->wait) {
                    $at = $heard[$key] ?? null;
                    if (null !== $at && strcmp($at, $wentBack[$key] ?? '') >= 0) {
                        $decided[Status::Done->value][] = [$entry->subject, $entry->revision];
                        ++$count;
                        continue;
                    }
                    if (null !== $node->timeout && null !== $since
                        && strcmp(Time::shift($since, Time::seconds($node->timeout)), $now) <= 0) {
                        $decided[Status::Failed->value][] = [$entry->subject, $entry->revision];
                        ++$count;
                    }
                } elseif (null !== $since                       // no wait, so a grace: the node was kept for one
                    && strcmp(Time::shift($since, Time::seconds($node->grace)), $now) <= 0) {
                    $decided[Status::Skipped->value][] = [$entry->subject, $entry->revision];
                    ++$count;
                }
            }
            foreach ($decided as $status => $chosen) {
                $written = $this->driver->insertIfUnchanged($node->name, $chosen, (string) $status, $now, null);
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
        $out = [];
        foreach ($subjects as $subject) {
            $key = Subject::key($subject);
            $out[$key] = array_map(Status::from(...), $read[$key] ?? []);
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
     * How many subjects stand where, for this node — every status present.
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
     *     ready           with `candidates`: how many a claim could take now
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
            if (null !== $candidates && null === $node->wait) {
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
                if (!$this->takable($node, $after, self::dueAway($node->name, $entry, $now), true)) {
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
