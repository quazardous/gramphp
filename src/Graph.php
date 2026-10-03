<?php

declare(strict_types=1);

namespace Quazardous\GramPHP;

/**
 * A document, its nodes, and the settings some POLICIES change. Checked on
 * construction, on the nodes and on every policy's variant: a Graph that
 * exists holds together under every policy.
 *
 *     new Graph(new Document('offers'), $dag, policies: [
 *         'slow-partner' => ['call' => ['retry' => new Retry(5, '1m'), 'lease' => '2h']],
 *     ]);
 *
 * A subject is given a policy with `$journal->enroll()`; the journal then
 * reads every setting below through it.
 */
final class Graph
{
    /**
     * WHAT A POLICY MAY CHANGE ON A NODE: its settings, never its structure.
     * Parents, joins, choices, loops and waits are the workflow; how long to
     * wait, how often to retry, how long a lease lasts are how hard a subject
     * under that policy is pushed.
     */
    public const OVERRIDABLE = ['retry', 'lease', 'timeout', 'grace', 'rate', 'concurrency', 'lane'];

    public readonly Dag $dag;

    /** @var array<string, Dag> policy => the nodes as it sees them */
    private array $variants = [];

    /**
     * @param Dag|list<Node>                                    $nodes
     * @param array<string, array<string, array<string, mixed>>> $policies policy => node => setting => value
     */
    public function __construct(
        public readonly Document $document,
        Dag|array $nodes,
        public readonly array $policies = [],
    ) {
        $this->dag = $nodes instanceof Dag ? $nodes : new Dag(...$nodes);
        $this->dag->check();
        foreach ($policies as $policy => $overrides) {
            $policy = (string) $policy;
            $changed = [];
            foreach ($this->dag as $node) {
                $settings = $overrides[$node->name] ?? null;
                try {
                    $changed[] = null === $settings ? $node : $node->with($this->checked($policy, $node, $settings));
                } catch (\TypeError|\InvalidArgumentException $e) {
                    throw new DagError(\sprintf("policy '%s' on '%s': %s", $policy, $node->name, $e->getMessage()), 0, $e);
                }
            }
            foreach (array_keys($overrides) as $name) {
                if (!$this->dag->has((string) $name)) {
                    throw new DagError(\sprintf("policy '%s' changes '%s', which does not exist", $policy, $name));
                }
            }
            $variant = new Dag(...$changed);
            $variant->check();
            $this->variants[$policy] = $variant;
        }
    }

    /** The nodes as `policy` sees them: its settings over the defaults. An unknown policy, or none, sees the defaults. */
    public function variant(?string $policy): Dag
    {
        return null === $policy ? $this->dag : ($this->variants[$policy] ?? $this->dag);
    }

    /** True when some policy changes `setting` on the node `name`. */
    public function overrides(string $name, string $setting): bool
    {
        foreach ($this->policies as $overrides) {
            if (\array_key_exists($setting, $overrides[$name] ?? [])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $settings
     *
     * @return array<string, mixed>
     */
    private function checked(string $policy, Node $node, array $settings): array
    {
        $refused = array_values(array_diff(array_keys($settings), self::OVERRIDABLE));
        if ([] !== $refused) {
            sort($refused);
            throw new DagError(\sprintf(
                "policy '%s' changes [%s] on '%s' — a policy changes only [%s], never the structure",
                $policy,
                implode(', ', $refused),
                $node->name,
                implode(', ', self::OVERRIDABLE),
            ));
        }
        if (\array_key_exists('lane', $settings) && (null === $node->lane || null === $settings['lane'])) {
            throw new DagError(\sprintf(
                "policy '%s' changes the lane of '%s': a policy tunes a lane the node declares, it never adds or removes one",
                $policy,
                $node->name,
            ));
        }
        $shared = array_values(array_intersect(['rate', 'concurrency'], array_keys($settings)));
        if ([] !== $shared && Per::Policy !== $node->per) {
            throw new DagError(\sprintf(
                "policy '%s' changes [%s] on '%s', whose budget is shared by every policy — declare per: Per::Policy first",
                $policy,
                implode(', ', $shared),
                $node->name,
            ));
        }

        return $settings;
    }
}
