<?php

declare(strict_types=1);

namespace Quazardous\GramPHP;

/**
 * A node of the graph: who it descends from, how it joins, what it projects.
 *
 * `parents` IS THE ONLY DECLARATION OF ORDER. Empty means it starts from the
 * root — a freshly submitted subject. Parents are DIRECT, never transitive.
 *
 * `working` and `state` ARE PROJECTIONS, NOT COMMANDS: the label an
 * application may mirror on its subject while the node runs, and once it
 * concludes. Nothing in this package reads them to decide what to claim.
 *
 * `optional`: the node may be skipped (`$journal->skip()`, or once `grace`
 * has passed since its parents concluded) and its children go on.
 *
 * `once`: a replay does not redo it.
 *
 * HOW A NODE JOINS ITS PARENTS — data, not code:
 *
 * - `on` says, per parent, which of its statuses this node accepts; a parent
 *   not named accepts `Status::satisfying()`. A compensation that runs when a
 *   reservation failed and the payment went through:
 *   `new Node('refund', parents: ['pay', 'reserve'], on: ['reserve' => [Status::Failed]])`
 * - `need` is how many parents must be accepted — all of them when null.
 * - `choice` marks a node that concludes by NAMING one of its children: the
 *   others, and whatever only they lead to, are `omitted` in the same write.
 * - `loop` declares a way back (`Loop`), bounded.
 *
 * TIME:
 *
 * - `retry` declares what a failure does first (`Retry`): archived, and the
 *   node scheduled again after a delay, a bounded number of times.
 * - `lease`: how long a worker may hold this node (`"10m"`); past it,
 *   `$journal->expire()` gives the row back as if the worker had died.
 * - `wait` makes the node an ATTENDED EVENT rather than work: no worker
 *   claims it; `$journal->settle()` concludes it `done` once a signal of that
 *   name was received for the subject — before the wait began included — or
 *   `failed` once `timeout` has passed since its parents concluded.
 * - `grace` gives an optional node that long, once its parents concluded,
 *   before `$journal->settle()` skips it.
 *
 * LIMITS — what the node uses, protected:
 *
 * - `rate` (bands of `Rate`) and `concurrency`: a claim takes no more than
 *   the bands let through, nor more than `concurrency` rows running at once.
 *   `per: Per::Policy` gives each policy its own budget; `Per::All` shares one.
 * - `group` (`Group`): the claim hands out a whole group of subjects sharing a
 *   key, under one lease, or none.
 *
 * `lane` makes the node a WAY IN for subjects that come back (`Lane`): no
 * worker claims it; `$journal->arrive()` puts a subject in it,
 * `$journal->settle()` lets it through, and each pass through what follows is
 * archived when the next one enters.
 */
final readonly class Node
{
    /** @var list<string> */
    public array $parents;

    /** @var array<string, list<string>> parent => statuses accepted */
    public array $on;

    /** @var list<Rate> */
    public array $rate;

    /**
     * @param array<string>                      $parents
     * @param array<string, array<Status|string>> $on
     * @param array<Rate>                         $rate
     */
    public function __construct(
        public string $name,
        array $parents = [],
        public ?string $working = null,
        public ?string $state = null,
        public bool $optional = false,
        public bool $once = false,
        array $on = [],
        public ?int $need = null,
        public bool $choice = false,
        public ?Loop $loop = null,
        public ?Retry $retry = null,
        public int|float|string|null $lease = null,
        public ?string $wait = null,
        public int|float|string|null $timeout = null,
        public int|float|string|null $grace = null,
        public ?Lane $lane = null,
        array $rate = [],
        public ?int $concurrency = null,
        public Per $per = Per::All,
        public ?Group $group = null,
    ) {
        $this->rate = array_values($rate);
        $this->parents = array_values($parents);
        $edges = [];
        foreach ($on as $parent => $statuses) {
            $edges[(string) $parent] = array_values(array_map(Status::valueOf(...), $statuses));
        }
        $this->on = $edges;
    }

    /**
     * This node with some settings changed — what a policy sees.
     *
     * @param array<string, mixed> $settings constructor argument => value
     */
    public function with(array $settings): self
    {
        $args = [
            'name' => $this->name, 'parents' => $this->parents, 'working' => $this->working, 'state' => $this->state,
            'optional' => $this->optional, 'once' => $this->once, 'on' => $this->on, 'need' => $this->need,
            'choice' => $this->choice, 'loop' => $this->loop, 'retry' => $this->retry, 'lease' => $this->lease,
            'wait' => $this->wait, 'timeout' => $this->timeout, 'grace' => $this->grace, 'lane' => $this->lane,
            'rate' => $this->rate, 'concurrency' => $this->concurrency, 'per' => $this->per, 'group' => $this->group,
        ];
        foreach ($settings as $key => $value) {
            if (!\array_key_exists($key, $args)) {
                throw new \InvalidArgumentException(\sprintf("a node has no setting '%s'", $key));
            }
            $args[$key] = $value;
        }

        // The values are checked by the constructor's own types: a wrong one
        // raises a TypeError naming it.
        return (new \ReflectionClass(self::class))->newInstanceArgs($args);
    }

    /** True when a claim on this node is cut by a rate or a concurrency. */
    public function limited(): bool
    {
        return [] !== $this->rate || null !== $this->concurrency;
    }

    /** True when the node joins otherwise than "every parent satisfying". */
    public function customJoin(): bool
    {
        return [] !== $this->on || null !== $this->need;
    }
}
