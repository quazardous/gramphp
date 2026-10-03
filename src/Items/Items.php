<?php

declare(strict_types=1);

namespace Quazardous\GramPHP\Items;

use Quazardous\GramPHP\NodeJournal;
use Quazardous\GramPHP\Status;
use Quazardous\GramPHP\Subject;

/**
 * SPEAK OBJECTS, NOT IDS — the canonical way to use gramphp.
 *
 *     $items = new Items($journal, new Bricks());
 *     $items->admit($bricks);                        // policy read from the item
 *     $lease = $items->claim('sort', 10, $bricks);   // OBJECTS in…
 *     foreach ($lease as $brick) {                   // …and objects out
 *         // …
 *     }
 *     $items->conclude('sort', $lease);              // the token travels with the lease
 *
 * WHERE THE LINE IS. The core takes DECISIONS, never CRITERIA. It is given an
 * id, a label, the name of a branch — never a rule to evaluate, never a
 * payload to look inside. This layer is the translator: it holds the handlers
 * that read the application's own objects and turns their answers into the
 * calls the journal already understands. The core never calls a handler, the
 * graph stays pure, and the drivers are untouched.
 *
 * It translates, it does not become a framework: no retry loop, no logging,
 * no worker lifecycle. Everything here is a call the id-based API could have
 * made by hand.
 *
 * ONE WORKFLOW, SEVERAL KINDS OF SUBJECT. `Adapter::applies()` is how one
 * graph serves subjects that differ slightly. An OPTIONAL node the item
 * refuses is claimed and concluded `skipped` in the same call, so nothing
 * downstream waits for it and the journal records that the decision was
 * taken. That spends a claim on a step not done: a hot path may prefer to
 * leave those subjects out of the candidates, and keep a `grace` as the
 * safety net for the ones nobody takes.
 */
final class Items
{
    public function __construct(
        public readonly NodeJournal $journal,
        public readonly Adapter $adapter,
    ) {}

    // -- the door ----------------------------------------------------------

    /**
     * Take these items in: record each one's policy, once. Items sharing a
     * policy are enrolled together. Return the count.
     *
     * @param iterable<mixed> $items
     */
    public function admit(iterable $items): int
    {
        /** @var array<string, array{?string, list<int|string>}> $byPolicy */
        $byPolicy = [];
        foreach ($items as $item) {
            $policy = $this->adapter->policyOf($item);
            $byPolicy["\0" . $policy] ??= [$policy, []];
            $byPolicy["\0" . $policy][1][] = $this->adapter->idOf($item);
        }
        $written = 0;
        foreach ($byPolicy as [$policy, $ids]) {
            $written += $this->journal->enroll($ids, $policy);
        }

        return $written;
    }

    // -- take and finish ---------------------------------------------------

    /**
     * Take up to `limit` candidates and HAND BACK THE OBJECTS.
     *
     * `candidates` MAY BE ANYTHING YOU HAVE: your own objects, their ids, or a
     * mix of the two, in any iterable. Objects travel with the claim and are
     * handed straight back — a batch you already loaded is not loaded twice —
     * and whatever was named by id alone is loaded with `inflate`.
     *
     * A DRIVER'S OWN QUERY is handed to the journal untouched, and the lease
     * is loaded with `inflate`: the storage produced the candidates, and it
     * has no objects to give. It buys what a list cannot — the driver filters
     * and pages in the database.
     *
     * The items this node does not apply to are concluded `skipped` in the
     * same call and left out of the lease, so what comes back is what there
     * is work to do on.
     *
     * @return ItemLease<mixed>
     */
    public function claim(string $name, int $limit, mixed $candidates): ItemLease
    {
        [$given, $candidates] = $this->subjects($candidates);
        $lease = $this->journal->claim($name, $limit, $candidates);
        // Objects handed in travel with the claim; anything known only by its
        // id is loaded, exactly as a driver's query would be.
        $held = [];
        $rest = [];
        foreach ($lease as $subject) {
            $key = Subject::key($subject);
            if (\array_key_exists($key, $given)) {
                $held[$key] = $given[$key];
            } else {
                $rest[] = $subject;
            }
        }
        if ([] !== $rest) {
            $held += $this->loaded($rest);
        }
        // THE LEASE'S OWN ORDER, whichever half each item came from.
        $keep = [];
        $giveUp = [];
        $missing = [];
        foreach ($lease as $subject) {
            $key = Subject::key($subject);
            if (!\array_key_exists($key, $held)) {
                $missing[] = $subject;
            } elseif ($this->adapter->applies($held[$key], $name)) {
                $keep[] = $held[$key];
            } else {
                $giveUp[] = $subject;
            }
        }
        if ([] !== $giveUp) {
            $this->journal->conclude($name, $giveUp, $lease->token, Status::Skipped);
        }

        return new ItemLease($keep, $lease->token, $missing);
    }

    /**
     * Finish this node on these items, asking `branch` for a choice.
     *
     * `items` is usually the `ItemLease` a claim gave back, and the token
     * travels with it. Pass `token` when the lease did not come along — a
     * worker that took its job off a queue and holds only the proof.
     *
     * @param iterable<mixed> $items
     */
    public function conclude(string $name, iterable $items, ?string $token = null, Status|string $status = Status::Done): int
    {
        return $this->finish($name, $items, $token, Status::valueOf($status));
    }

    /**
     * This node did not produce, on these items.
     *
     * @param iterable<mixed> $items
     */
    public function fail(string $name, iterable $items, ?string $token = null): int
    {
        return $this->finish($name, $items, $token, Status::Failed->value);
    }

    // -- pass through, in items' terms -------------------------------------

    /**
     * Record that `event` happened for these items.
     *
     * @param iterable<mixed> $items
     */
    public function signal(iterable $items, string $event, ?string $ref = null): int
    {
        return $this->journal->signal($this->ids($items), $event, $ref);
    }

    /** @return array<string, Status> `[node => status]` for one item */
    public function progress(mixed $item): array
    {
        return $this->journal->progress($this->adapter->idOf($item));
    }

    /**
     * `[item, [node => status]]` for each item, in their order — one read for
     * all of them when the driver offers it (`NodeJournal::progressMany()`).
     *
     * @param iterable<mixed> $items
     *
     * @return list<array{mixed, array<string, Status>}>
     */
    public function progressMany(iterable $items): array
    {
        $items = [...$items];
        $read = $this->journal->progressMany($this->ids($items));
        $out = [];
        foreach ($items as $item) {
            $out[] = [$item, $read[Subject::key($this->adapter->idOf($item))] ?? []];
        }

        return $out;
    }

    /**
     * Where each node stands, for monitoring (`NodeJournal::snapshot()`): the
     * candidates may be your objects. `ready` counts what the graph's rule
     * allows; items your adapter turns away (`applies`) are among them, since
     * the journal cannot know.
     *
     * @param list<string>|null $nodes
     *
     * @return array<string, array<string, int|float|null>>
     */
    public function snapshot(mixed $candidates = null, ?array $nodes = null): array
    {
        if (null !== $candidates) {
            $candidates = $this->subjects($candidates)[1];
        }

        return $this->journal->snapshot($candidates, $nodes);
    }

    /**
     * Every row forget, release, a retry or a loop took away from this item.
     *
     * @return list<array{node: string, status: string, started_at: string, finished_at: ?string, lease: ?string, archived_at: string, reason: string}>
     */
    public function history(mixed $item): array
    {
        return $this->journal->history($this->adapter->idOf($item));
    }

    /**
     * Give up this OPTIONAL node on the candidates that are at it — for items
     * you already know refuse it, without claiming them first
     * (`NodeJournal::skip()`).
     */
    public function skip(string $name, mixed $candidates, ?int $limit = null): int
    {
        return $this->journal->skip($name, $this->subjects($candidates)[1], $limit);
    }

    /**
     * The janitor's pass, in items' terms: waits concluded, optional nodes
     * past their grace skipped (`NodeJournal::settle()`).
     *
     * @return array<string, array<string, int>>
     */
    public function settle(mixed $candidates, ?int $limit = null): array
    {
        return $this->journal->settle($this->subjects($candidates)[1], $limit);
    }

    // -- inside ------------------------------------------------------------

    /** @param iterable<mixed> $items */
    private function finish(string $name, iterable $items, ?string $token, string $status): int
    {
        $token ??= $items instanceof ItemLease ? $items->token : null;
        // ONLY A CHOICE IS ASKED FOR A BRANCH. Anywhere else the core refuses
        // one, and rightly: there would be nothing to omit.
        $asking = $this->journal->dag->node($name)->choice && Status::Failed->value !== $status;
        /** @var array<string, array{?string, list<int|string>}> $byBranch */
        $byBranch = [];
        foreach ($items as $item) {
            $branch = $asking ? $this->adapter->branch($item, $name) : null;
            $byBranch["\0" . $branch] ??= [$branch, []];
            $byBranch["\0" . $branch][1][] = $this->adapter->idOf($item);
        }
        $touched = 0;
        foreach ($byBranch as [$branch, $ids]) {
            $touched += $this->journal->conclude($name, $ids, $token, $status, $branch);
        }

        return $touched;
    }

    /**
     * @param iterable<mixed> $items
     *
     * @return list<int|string>
     */
    private function ids(iterable $items): array
    {
        $ids = [];
        foreach ($items as $item) {
            $ids[] = $this->adapter->idOf($item);
        }

        return $ids;
    }

    /**
     * `[[key => item], what the journal gets]`. Items become their ids and
     * stay in hand; anything not iterable — a driver's query — travels on
     * untouched.
     *
     * @return array{array<string, mixed>, mixed}
     */
    private function subjects(mixed $candidates): array
    {
        if (!is_iterable($candidates)) {
            return [[], $candidates];
        }
        // THE ADAPTER SEES THE BATCH AS IT CAME, and says what is an item.
        $given = [];
        $ids = [];
        foreach ($this->adapter->inflate(iterator_to_array($candidates, false)) as $item) {
            $id = $this->adapter->idOf($item);
            $key = Subject::key($id);
            if (!\array_key_exists($key, $given)) {
                $ids[] = $id;
            }
            $given[$key] = $item;
        }

        return [$given, $ids];
    }

    /**
     * `[key => item]` for subjects a claim named by id alone, in ONE call.
     *
     * @param list<int|string> $subjects
     *
     * @return array<string, mixed>
     */
    private function loaded(array $subjects): array
    {
        if (Adapter::class === (new \ReflectionMethod($this->adapter, 'inflate'))->getDeclaringClass()->getName()) {
            throw new \LogicException(\sprintf(
                '%s needs `inflate`: a driver\'s query named the candidates, so the claim came back as ids with '
                . 'no objects attached. Write it, or pass your items as candidates and they travel with the claim',
                $this->adapter::class,
            ));
        }
        $found = [];
        foreach ($this->adapter->inflate($subjects) as $item) {
            $found[Subject::key($this->adapter->idOf($item))] = $item;
        }

        return $found;
    }
}
