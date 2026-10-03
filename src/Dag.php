<?php

declare(strict_types=1);

namespace Quazardous\GramPHP;

/**
 * The graph — its nodes, their parents — and the rule that says what can run.
 *
 * A *progress* — `[node => status]` for one subject — is all the rule needs.
 * A missing node has never started: that is the third state, and it costs no
 * column.
 *
 * THE RULE IS PURE, AND THAT IS THE POINT. "All my parents are concluded,
 * nobody holds me, and no descendant has started" is decided here, on a
 * progress, without any storage. A driver is an IMPLEMENTATION of this rule,
 * and the shared driver contract confronts the two.
 *
 * @implements \IteratorAggregate<int, Node>
 */
final class Dag implements \IteratorAggregate, \Countable
{
    /** @var list<Node> */
    private readonly array $nodes;

    /** @var array<string, Node> */
    private readonly array $byName;

    /** @var array<string, list<string>> parent => children, in declaration order */
    private readonly array $children;

    public function __construct(Node ...$nodes)
    {
        $this->nodes = array_values($nodes);
        $byName = [];
        $children = [];
        foreach ($this->nodes as $node) {
            $byName[$node->name] ??= $node;
            foreach ($node->parents as $parent) {
                $children[$parent][] = $node->name;
            }
        }
        $this->byName = $byName;
        $this->children = $children;
    }

    /** @return \ArrayIterator<int, Node> */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->nodes);
    }

    public function count(): int
    {
        return \count($this->nodes);
    }

    /** @return list<Node> */
    public function nodes(): array
    {
        return $this->nodes;
    }

    /**
     * The named node. Throws rather than returning null: a caller that got
     * null would put an empty name in a query, and the claim would simply
     * return nothing.
     */
    public function node(string $name): Node
    {
        return $this->byName[$name] ?? throw new DagError(\sprintf("unknown node: '%s'", $name));
    }

    public function has(string $name): bool
    {
        return isset($this->byName[$name]);
    }

    /**
     * Every node downstream of this one, directly or not, sorted.
     *
     * WHAT THEY ARE FOR: NEVER GOING BACKWARDS. A node must not be claimed
     * once one of its descendants has started — the subject has moved PAST it.
     *
     * @return list<string>
     */
    public function descendants(string $name): array
    {
        $seen = [];
        $toVisit = $this->children[$name] ?? [];
        while ([] !== $toVisit) {
            $current = array_pop($toVisit);
            if (isset($seen[$current])) {
                continue;
            }
            $seen[$current] = true;
            array_push($toVisit, ...$this->children[$current] ?? []);
        }

        return self::sorted(array_keys($seen));
    }

    /**
     * Every node upstream of this one, directly or not, sorted. A sibling
     * branch is NOT an ancestor.
     *
     * @return list<string>
     */
    public function ancestors(string $name): array
    {
        $seen = [];
        $toVisit = $this->node($name)->parents;
        while ([] !== $toVisit) {
            $current = array_pop($toVisit);
            if (isset($seen[$current]) || !isset($this->byName[$current])) {
                continue;
            }
            $seen[$current] = true;
            array_push($toVisit, ...$this->byName[$current]->parents);
        }

        return self::sorted(array_keys($seen));
    }

    /**
     * The statuses of `parent` that `child` accepts.
     *
     * @return list<string>
     */
    public static function accepts(Node $child, string $parent): array
    {
        return $child->on[$parent] ?? Status::satisfying();
    }

    /**
     * ENOUGH PARENTS CONCLUDED THE WAY THIS NODE ACCEPTS — `need` of them, all
     * when `need` is null. A root has nothing to wait for.
     *
     * @param array<string, Status|string> $progress
     */
    public function joined(string $name, array $progress): bool
    {
        $node = $this->node($name);
        $accepted = 0;
        foreach ($node->parents as $parent) {
            $status = $progress[$parent] ?? null;
            if (null !== $status && \in_array(Status::valueOf($status), self::accepts($node, $parent), true)) {
                ++$accepted;
            }
        }

        return $accepted >= ($node->need ?? \count($node->parents));
    }

    /**
     * Can this node be taken, given what is already recorded?
     *
     * THREE REFUSALS, AND THEY DO NOT MEAN THE SAME THING:
     *
     *     a row already exists      someone holds it, or it is concluded
     *     a descendant has started  the subject has moved PAST it
     *     not joined                its input does not exist yet
     *
     * @param array<string, Status|string> $progress
     */
    public function claimable(string $name, array $progress): bool
    {
        $this->node($name);
        if (\array_key_exists($name, $progress)) {
            return false;
        }
        foreach ($this->descendants($name) as $descendant) {
            if (\array_key_exists($descendant, $progress)) {
                return false;
            }
        }

        return $this->joined($name, $progress);
    }

    /**
     * EVERY node that can be taken now. Empty means the subject is finished,
     * blocked by a failure, or entirely in progress. A fork returns two names.
     *
     * @param array<string, Status|string> $progress
     *
     * @return list<string>
     */
    public function claimableNodes(array $progress): array
    {
        $out = [];
        foreach ($this->nodes as $node) {
            if ($this->claimable($node->name, $progress)) {
                $out[] = $node->name;
            }
        }

        return $out;
    }

    /**
     * WHAT DIES WHEN `choice` TAKES `branch`: the other children of the choice,
     * and every node that can only be reached through them. Reachability, not
     * descendance: a join the taken branch also reaches stays alive. Returned in
     * declaration order.
     *
     * @return list<string>
     */
    public function omittedBy(string $choice, string $branch): array
    {
        $node = $this->node($choice);
        if (!$node->choice) {
            throw new DagError(\sprintf("node '%s' is not a choice", $choice));
        }
        $children = $this->children[$choice] ?? [];
        if (!\in_array($branch, $children, true)) {
            throw new DagError(\sprintf(
                "'%s' is not a branch of '%s' — expected one of [%s]",
                $branch,
                $choice,
                implode(', ', $children),
            ));
        }
        $cut = array_fill_keys(array_diff($children, [$branch]), true);
        $reachable = [];
        do {                                       // a fixpoint: no order assumed
            $grew = false;
            foreach ($this->nodes as $candidate) {
                if (isset($cut[$candidate->name]) || isset($reachable[$candidate->name])) {
                    continue;
                }
                $reached = [] === $candidate->parents;
                foreach ($candidate->parents as $parent) {
                    $reached = $reached || isset($reachable[$parent]);
                }
                if ($reached) {
                    $reachable[$candidate->name] = true;
                    $grew = true;
                }
            }
        } while ($grew);

        $out = [];
        foreach ($this->nodes as $candidate) {
            if (!isset($reachable[$candidate->name])) {
                $out[] = $candidate->name;
            }
        }

        return $out;
    }

    /**
     * Refuse a graph that does not hold together. Called by the journal when
     * it is built: FAIL WHEN THE GRAPH IS DECLARED, not three weeks later as a
     * quality statistic.
     */
    public function check(): void
    {
        $seen = [];
        foreach ($this->nodes as $node) {
            if (isset($seen[$node->name])) {
                throw new DagError(\sprintf("two nodes are named '%s'", $node->name));
            }
            $seen[$node->name] = true;
        }

        foreach ($this->nodes as $node) {
            foreach ($node->parents as $parent) {
                if (!isset($this->byName[$parent])) {
                    throw new DagError(\sprintf(
                        "node '%s' descends from '%s', which does not exist",
                        $node->name,
                        $parent,
                    ));
                }
            }
            if (\count(array_unique($node->parents)) !== \count($node->parents)) {
                throw new DagError(\sprintf("node '%s' names a parent twice", $node->name));
            }
        }

        foreach ($this->nodes as $node) {
            $this->checkJoin($node);
            $this->checkTime($node);
        }

        // NO CYCLE, PROVEN BY A WALK: a cycle would not raise — none of its
        // nodes would ever become claimable, and the queue would stop there.
        $done = [];
        $inProgress = [];
        $descend = function (string $name) use (&$descend, &$done, &$inProgress): void {
            if (isset($inProgress[$name])) {
                throw new DagError(\sprintf("cycle in the graph, through '%s'", $name));
            }
            if (isset($done[$name])) {
                return;
            }
            $inProgress[$name] = true;
            foreach ($this->byName[$name]->parents as $parent) {
                $descend($parent);
            }
            unset($inProgress[$name]);
            $done[$name] = true;
        };
        foreach ($this->nodes as $node) {
            $descend($node->name);
        }

        // ONE ROOT: two parentless nodes would be two entry points.
        $roots = [];
        foreach ($this->nodes as $node) {
            if ([] === $node->parents) {
                $roots[] = $node->name;
            }
        }
        if (1 !== \count($roots)) {
            throw new DagError(\sprintf(
                'the graph has %d root(s): [%s]. A fresh subject would not know where to enter.',
                \count($roots),
                implode(', ', $roots),
            ));
        }

        // A PROJECTED STATE HAS ONE AUTHOR.
        $postedBy = [];
        foreach ($this->nodes as $node) {
            foreach ([$node->working, $node->state] as $state) {
                if (null === $state) {
                    continue;
                }
                if (isset($postedBy[$state]) && $postedBy[$state] !== $node->name) {
                    throw new DagError(\sprintf(
                        "state '%s' is posted by '%s' AND by '%s' — the label becomes ambiguous",
                        $state,
                        $postedBy[$state],
                        $node->name,
                    ));
                }
                $postedBy[$state] = $node->name;
            }
        }
    }

    private function checkJoin(Node $node): void
    {
        foreach ($node->on as $parent => $statuses) {
            if (!\in_array($parent, $node->parents, true)) {
                throw new DagError(\sprintf("node '%s': `on` names '%s', not one of its parents", $node->name, $parent));
            }
            if ([] === $statuses) {
                throw new DagError(\sprintf("node '%s': `on` accepts nothing from '%s'", $node->name, $parent));
            }
            $unknown = array_diff($statuses, Status::concluded());
            if ([] !== $unknown) {
                throw new DagError(\sprintf(
                    "node '%s': `on` accepts unknown status(es) [%s] from '%s' — expected among [%s]",
                    $node->name,
                    implode(', ', $unknown),
                    $parent,
                    implode(', ', Status::concluded()),
                ));
            }
        }
        if (null !== $node->need && ($node->need < 1 || $node->need > \count($node->parents))) {
            throw new DagError(\sprintf(
                "node '%s': need=%d but it has %d parent(s)",
                $node->name,
                $node->need,
                \count($node->parents),
            ));
        }
        if ($node->choice && [] === ($this->children[$node->name] ?? [])) {
            throw new DagError(\sprintf("node '%s' is a choice without any branch", $node->name));
        }
        if ($node->choice && $node->optional) {
            throw new DagError(\sprintf(
                "node '%s' is an optional choice: skipped, it would name no branch and every branch would run",
                $node->name,
            ));
        }
        if (null !== $node->loop) {
            $loop = $node->loop;
            if ($loop->to !== $node->name && !\in_array($loop->to, $this->ancestors($node->name), true)) {
                throw new DagError(\sprintf(
                    "node '%s' loops to '%s', which is neither itself nor one of its ancestors",
                    $node->name,
                    $loop->to,
                ));
            }
            if ($loop->max < 1) {
                throw new DagError(\sprintf("node '%s': a loop needs max >= 1, got %d", $node->name, $loop->max));
            }
            $allowed = [Status::Done->value, Status::Skipped->value, Status::Failed->value];
            if ([] === $loop->on || [] !== array_diff($loop->on, $allowed)) {
                throw new DagError(\sprintf(
                    "node '%s': a loop fires on done, skipped or failed, got [%s]",
                    $node->name,
                    implode(', ', $loop->on),
                ));
            }
            if ($node->choice) {
                throw new DagError(\sprintf("node '%s' is a choice and a loop: pick one", $node->name));
            }
        }
    }

    private function checkTime(Node $node): void
    {
        foreach (['lease' => $node->lease, 'timeout' => $node->timeout, 'grace' => $node->grace] as $label => $value) {
            if (null === $value) {
                continue;
            }
            try {
                $seconds = Time::seconds($value);
            } catch (\InvalidArgumentException $e) {
                throw new DagError(\sprintf("node '%s': %s %s — %s", $node->name, $label, var_export($value, true), $e->getMessage()), 0, $e);
            }
            if ($seconds <= 0) {
                throw new DagError(\sprintf("node '%s': %s %s — a duration must last", $node->name, $label, var_export($value, true)));
            }
        }
        if (null !== $node->wait) {
            if ('' === $node->wait) {
                throw new DagError(\sprintf("node '%s': wait needs an event name", $node->name));
            }
            $unfit = array_keys(array_filter([
                'choice' => $node->choice,
                'loop' => null !== $node->loop,
                'retry' => null !== $node->retry,
                'lease' => null !== $node->lease,
            ]));
            if ([] !== $unfit) {
                throw new DagError(\sprintf(
                    "node '%s' waits for '%s': it is settled, never worked, so it cannot take [%s]",
                    $node->name,
                    $node->wait,
                    implode(', ', $unfit),
                ));
            }
        } elseif (null !== $node->timeout) {
            throw new DagError(\sprintf("node '%s': a timeout needs a wait", $node->name));
        }
        if (null !== $node->grace && !$node->optional) {
            throw new DagError(\sprintf("node '%s': grace skips an optional node — this one is not", $node->name));
        }
        if (null !== $node->concurrency && $node->concurrency < 1) {
            throw new DagError(\sprintf("node '%s': concurrency %d — at least 1", $node->name, $node->concurrency));
        }
        if (null !== $node->wait && $node->limited()) {
            throw new DagError(\sprintf("node '%s' waits: it is never claimed, so it takes no rate or concurrency", $node->name));
        }
        if (null !== $node->lane) {
            $this->checkLane($node, $node->lane);
        }
        if (null !== $node->group) {
            $this->checkGroup($node, $node->group);
        }
    }

    /**
     * A group gathers subjects a worker takes together; it cannot sit on a
     * node no worker claims, nor on one that concludes by naming a branch.
     */
    private function checkGroup(Node $node, Group $group): void
    {
        if ($group->size < 1) {
            throw new DagError(\sprintf("node '%s': group size=%d — a group of fewer than one subject is not a group", $node->name, $group->size));
        }
        if (null !== $group->maxWait) {
            try {
                $seconds = Time::seconds($group->maxWait);
            } catch (\InvalidArgumentException $e) {
                throw new DagError(\sprintf("node '%s': group maxWait %s — %s", $node->name, var_export($group->maxWait, true), $e->getMessage()), 0, $e);
            }
            if ($seconds <= 0) {
                throw new DagError(\sprintf("node '%s': group maxWait %s — a duration must last", $node->name, var_export($group->maxWait, true)));
            }
        }
        $unfit = array_keys(array_filter(['wait' => null !== $node->wait, 'lane' => null !== $node->lane, 'choice' => $node->choice]));
        if ([] !== $unfit) {
            throw new DagError(\sprintf(
                "node '%s' groups its subjects, so a worker takes them together: it cannot also take [%s]",
                $node->name,
                implode(', ', $unfit),
            ));
        }
    }

    private function checkLane(Node $node, Lane $lane): void
    {
        $modes = [Merge::First->value, Merge::Last->value, Merge::All->value];
        if (!\in_array($lane->merge, $modes, true) && null === $lane->merger()) {
            throw new DagError(\sprintf(
                "node '%s': lane merge=%s — expected one of [%s], or Merge::fn('<name>') and a function the journal is given",
                $node->name,
                var_export($lane->merge, true),
                implode(', ', $modes),
            ));
        }
        if ('' === $lane->merger()) {
            throw new DagError(\sprintf("node '%s': lane merge=%s names no function", $node->name, var_export($lane->merge, true)));
        }
        if ($lane->maxSize < 1) {
            throw new DagError(\sprintf("node '%s': lane maxSize=%d — keeping fewer than one ref keeps nothing", $node->name, $lane->maxSize));
        }
        foreach (['cooldown' => $lane->cooldown, 'delay' => $lane->delay, 'maxWait' => $lane->maxWait] as $label => $value) {
            if (null === $value) {
                continue;
            }
            try {
                $seconds = Time::seconds($value);
            } catch (\InvalidArgumentException $e) {
                throw new DagError(\sprintf("node '%s': lane %s %s — %s", $node->name, $label, var_export($value, true), $e->getMessage()), 0, $e);
            }
            if ($seconds <= 0) {
                throw new DagError(\sprintf("node '%s': lane %s %s — a duration must last", $node->name, $label, var_export($value, true)));
            }
        }
        $unfit = array_keys(array_filter([
            'wait' => null !== $node->wait,
            'choice' => $node->choice,
            'loop' => null !== $node->loop,
            'retry' => null !== $node->retry,
            'lease' => null !== $node->lease,
            'grace' => null !== $node->grace,
            'optional' => $node->optional,
            'concurrency' => null !== $node->concurrency,
        ]));
        if ([] !== $unfit) {
            throw new DagError(\sprintf(
                "node '%s' is a lane: it is entered, never worked, so it cannot take [%s]",
                $node->name,
                implode(', ', $unfit),
            ));
        }
    }

    /**
     * @param list<string> $names
     *
     * @return list<string>
     */
    private static function sorted(array $names): array
    {
        $names = array_map('strval', $names);
        sort($names, \SORT_STRING);

        return $names;
    }
}
