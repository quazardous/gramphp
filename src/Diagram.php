<?php

declare(strict_types=1);

namespace Quazardous\GramPHP;

/**
 * THE GRAPH, DRAWN — Mermaid and Graphviz, with live counts if given.
 *
 *     mermaid       a Mermaid `flowchart` (GitHub, GitLab and most docs render it)
 *     stateDiagram  a Mermaid `stateDiagram-v2`, the way a statechart reads
 *     dot           a Graphviz `digraph`
 *     overlay       the journal's counts per node, ready to be drawn
 *
 * EVERY MECHANISM HAS A SHAPE — NOTHING DECLARED IS LEFT UNDRAWN:
 *
 *     choice        a diamond
 *     wait          a hexagon, with the event and its timeout
 *     lane          a trapezoid (Mermaid) or a house (DOT), with its merge,
 *                   place and timings
 *     optional      a dashed border, with its grace
 *     need=k        `k/n` on the node
 *     on failed     a dashed red edge labelled with the statuses it accepts
 *     loop          a dotted edge back to `to`, labelled `loop ≤max`
 *     retry         `retry ×limit` on the node
 *     rate          `rate limit/period` per band, `≤n at once`, `per policy`
 *     group         `groups of n`, by key or not, with its max wait
 *     lease         `⏱ lease` on the node
 *     policies      `varies by policy` when a policy changes the node
 *
 * A drawing that silently left out a loop or a failure edge would show a
 * workflow simpler than the one that runs.
 *
 * Counts (`overlay`) are an optional second layer: `⧖ waiting in a lane
 * ▶ running ⏳ scheduled ✓ done ↷ skipped ✗ failed ∅ omitted`, zeros left out.
 */
final class Diagram
{
    /** How a count is shown, in this order. */
    private const COUNT_MARKS = ['waiting' => '⧖', 'running' => '▶', 'scheduled' => '⏳', 'done' => '✓', 'skipped' => '↷', 'failed' => '✗', 'omitted' => '∅'];

    /** @return array<string, array<string, int>> node => status => count, for every node of the journal's graph */
    public static function overlay(NodeJournal $journal): array
    {
        $out = [];
        foreach ($journal->dag as $node) {
            $out[$node->name] = $journal->counts($node->name);
        }

        return $out;
    }

    /** @param array<string, array<string, int>>|null $counts node => status => count */
    public static function mermaid(Graph|Dag $graph, ?array $counts = null, string $direction = 'LR'): string
    {
        [$nodes, $varying] = self::nodes($graph);
        $ids = self::ids($nodes);
        $lines = ["flowchart {$direction}"];
        foreach ($nodes as $n) {
            $label = str_replace('"', '#quot;', implode('<br/>', self::label($n, $varying, $counts)));
            [$open, $close] = self::mermaidShape($n);
            $lines[] = "    {$ids[$n->name]}{$open}\"{$label}\"{$close}";
        }
        $link = 0;
        $red = [];
        foreach ($nodes as $n) {
            foreach ($n->parents as $parent) {
                if (isset($n->on[$parent])) {
                    $lines[] = \sprintf('    %s -.->|"%s"| %s', $ids[$parent], implode(' / ', $n->on[$parent]), $ids[$n->name]);
                    if (\in_array(Status::Failed->value, $n->on[$parent], true)) {
                        $red[] = $link;
                    }
                } else {
                    $lines[] = "    {$ids[$parent]} --> {$ids[$n->name]}";
                }
                ++$link;
            }
            if (null !== $n->loop) {
                $lines[] = \sprintf('    %s -.->|"loop ≤%d on %s"| %s', $ids[$n->name], $n->loop->max, implode(' / ', $n->loop->on), $ids[$n->loop->to]);
                ++$link;
            }
        }
        $optional = array_values(array_map(static fn(Node $n): string => $ids[$n->name], array_filter($nodes, static fn(Node $n): bool => $n->optional)));
        if ([] !== $optional) {
            $lines[] = '    classDef optional stroke-dasharray: 5 5';
            $lines[] = '    class ' . implode(',', $optional) . ' optional';
        }
        foreach ($red as $index) {
            $lines[] = "    linkStyle {$index} stroke:#d33,color:#d33";
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * THE SAME GRAPH, AS A STATECHART READER EXPECTS IT: `[*]` enters the root
     * and leaves from the nodes nobody follows; a choice is a `<<choice>>`
     * pseudo-state, a fork and a join are `<<fork>>` and `<<join>>`; a join
     * that needs only some parents says `need k/n`; an edge that accepts
     * other statuses says which; a loop and a retry go back, labelled. What a
     * node waits for, its lane, its lease and limits are notes. No counts:
     * Mermaid cannot style the states of this diagram — `mermaid()` draws them.
     */
    public static function stateDiagram(Graph|Dag $graph, string $direction = 'LR'): string
    {
        [$nodes, $varying] = self::nodes($graph);
        $ids = self::ids($nodes);
        $children = [];
        foreach ($nodes as $n) {
            foreach ($n->parents as $parent) {
                $children[$parent][] = $n;
            }
        }
        $lines = ['stateDiagram-v2', "    direction {$direction}"];
        foreach ($nodes as $n) {
            $lines[] = \sprintf('    state "%s" as %s', self::escapeState($n->name), $ids[$n->name]);
        }
        // A pseudo-state must be declared before a transition names it, or
        // Mermaid draws an ordinary state of that name.
        foreach ($nodes as $n) {
            if (\count($n->parents) > 1) {
                $lines[] = "    state {$ids[$n->name]}_join <<join>>";
            }
        }
        foreach ($nodes as $n) {
            if ([] === $n->parents) {
                $lines[] = "    [*] --> {$ids[$n->name]}";
            }
        }
        foreach ($nodes as $n) {
            $after = $children[$n->name] ?? [];
            if ([] === $after) {
                $lines[] = "    {$ids[$n->name]} --> [*]";

                continue;
            }
            $target = [];
            foreach ($after as $c) {
                $target[$c->name] = \count($c->parents) > 1 ? "{$ids[$c->name]}_join" : $ids[$c->name];
            }
            // Children taken on different outcomes are alternatives, not
            // branches running together: an outcome choice first, a fork only
            // among the children of one outcome.
            $outcomes = [];
            foreach ($after as $child) {
                $outcomes[self::edgeLabel($child, $n->name)][] = $child;
            }
            if ($n->choice || \count($outcomes) > 1) {
                $split = "{$ids[$n->name]}_choice";
                $lines[] = "    state {$split} <<choice>>";
                $lines[] = "    {$ids[$n->name]} --> {$split}";
                $i = 0;
                foreach ($outcomes as $label => $group) {
                    $shown = '' !== (string) $label ? (string) $label : ($n->choice ? '' : Status::Done->value);
                    $arrow = '' !== $shown ? " : {$shown}" : '';
                    if (1 === \count($group) || $n->choice) {
                        foreach ($group as $c) {
                            $lines[] = "    {$split} --> {$target[$c->name]}{$arrow}";
                        }
                    } else {
                        $fork = "{$ids[$n->name]}_fork{$i}";
                        $lines[] = "    state {$fork} <<fork>>";
                        $lines[] = "    {$split} --> {$fork}{$arrow}";
                        foreach ($group as $c) {
                            $lines[] = "    {$fork} --> {$target[$c->name]}";
                        }
                    }
                    ++$i;
                }
            } elseif (\count($after) > 1) {
                $fork = "{$ids[$n->name]}_fork";
                $lines[] = "    state {$fork} <<fork>>";
                $lines[] = "    {$ids[$n->name]} --> {$fork}";
                foreach ($after as $c) {
                    $lines[] = "    {$fork} --> {$target[$c->name]}";
                }
            } else {
                $label = self::edgeLabel($after[0], $n->name);
                $lines[] = "    {$ids[$n->name]} --> {$target[$after[0]->name]}" . ('' !== $label ? " : {$label}" : '');
            }
        }
        foreach ($nodes as $n) {
            if (\count($n->parents) > 1) {
                $need = null !== $n->need ? \sprintf(' : need %d/%d', $n->need, \count($n->parents)) : '';
                $lines[] = "    {$ids[$n->name]}_join --> {$ids[$n->name]}{$need}";
            }
            if (null !== $n->loop) {
                $lines[] = \sprintf('    %s --> %s : loop ≤%d on %s', $ids[$n->name], $ids[$n->loop->to], $n->loop->max, implode(' / ', $n->loop->on));
            }
            if (null !== $n->retry) {
                $lines[] = \sprintf('    %s --> %s : failed, retry ×%d', $ids[$n->name], $ids[$n->name], $n->retry->limit);
            }
        }
        foreach ($nodes as $n) {
            $notes = self::stateNotes($n, $varying);
            if ([] !== $notes) {
                $lines[] = "    note right of {$ids[$n->name]} : " . self::escapeState(implode(' · ', $notes));
            }
        }

        return implode("\n", $lines) . "\n";
    }

    /** @param array<string, array<string, int>>|null $counts node => status => count */
    public static function dot(Graph|Dag $graph, ?array $counts = null, string $direction = 'LR'): string
    {
        [$nodes, $varying] = self::nodes($graph);
        $lines = ['digraph gramphp {', "    rankdir={$direction};", '    node [fontname="Helvetica", shape=box, style=rounded];'];
        foreach ($nodes as $n) {
            $attrs = ['label="' . self::escapeDot(implode("\n", self::label($n, $varying, $counts))) . '"'];
            if ($n->choice) {
                $attrs[] = 'shape=diamond';
            } elseif (null !== $n->wait) {
                $attrs[] = 'shape=hexagon';
            } elseif (null !== $n->lane) {
                $attrs[] = 'shape=house';
            }
            if ($n->optional) {
                $attrs[] = 'style="rounded,dashed"';
            }
            $lines[] = \sprintf('    "%s" [%s];', self::escapeDot($n->name), implode(', ', $attrs));
        }
        foreach ($nodes as $n) {
            foreach ($n->parents as $parent) {
                $edge = \sprintf('    "%s" -> "%s"', self::escapeDot($parent), self::escapeDot($n->name));
                if (isset($n->on[$parent])) {
                    $colour = \in_array(Status::Failed->value, $n->on[$parent], true) ? ', color="#d33", fontcolor="#d33"' : '';
                    $edge .= \sprintf(' [style=dashed, label="%s"%s]', self::escapeDot(implode(' / ', $n->on[$parent])), $colour);
                }
                $lines[] = $edge . ';';
            }
            if (null !== $n->loop) {
                $lines[] = \sprintf(
                    '    "%s" -> "%s" [style=dotted, constraint=false, label="loop ≤%d on %s"];',
                    self::escapeDot($n->name),
                    self::escapeDot($n->loop->to),
                    $n->loop->max,
                    self::escapeDot(implode(' / ', $n->loop->on)),
                );
            }
        }
        $lines[] = '}';

        return implode("\n", $lines) . "\n";
    }

    // -- inside ----------------------------------------------------------

    /** @return array{0: list<Node>, 1: array<string, true>} the nodes, and the names a policy changes */
    private static function nodes(Graph|Dag $graph): array
    {
        $nodes = ($graph instanceof Graph ? $graph->dag : $graph)->nodes();
        $varying = [];
        foreach ($graph instanceof Graph ? $graph->policies : [] as $overrides) {
            foreach (array_keys($overrides) as $name) {
                $varying[(string) $name] = true;
            }
        }

        return [$nodes, $varying];
    }

    /**
     * @param array<string, true>                    $varying
     * @param array<string, array<string, int>>|null $counts
     *
     * @return list<string>
     */
    private static function label(Node $n, array $varying, ?array $counts): array
    {
        $lines = [$n->name];
        $badges = [];
        if (null !== $n->need) {
            $badges[] = \sprintf('%d/%d', $n->need, \count($n->parents));
        }
        if (null !== $n->wait) {
            $badges[] = "waits {$n->wait}" . (null !== $n->timeout ? ' ⏱ ' . self::duration($n->timeout) : '');
        }
        if (null !== $n->lane) {
            $badges[] = self::describeLane($n->lane);
        }
        if (null !== $n->grace) {
            $badges[] = 'grace ' . self::duration($n->grace);
        }
        if (null !== $n->retry) {
            $badges[] = "retry ×{$n->retry->limit}";
        }
        array_push($badges, ...self::limits($n));
        if (null !== $n->lease) {
            $badges[] = '⏱ ' . self::duration($n->lease);
        }
        if ($n->once) {
            $badges[] = 'once';
        }
        if ([] !== $badges) {
            $lines[] = implode(' · ', $badges);
        }
        if (isset($varying[$n->name])) {
            $lines[] = 'varies by policy';
        }
        if (null !== $counts && isset($counts[$n->name])) {
            $shown = [];
            foreach (self::COUNT_MARKS as $status => $mark) {
                $count = $counts[$n->name][$status] ?? 0;
                if (0 !== $count) {
                    $shown[] = "{$mark}{$count}";
                }
            }
            if ([] !== $shown) {
                $lines[] = implode(' ', $shown);
            }
        }

        return $lines;
    }

    /** @return list<string> the rate, concurrency and group badges of a node */
    private static function limits(Node $n): array
    {
        $out = [];
        if ([] !== $n->rate) {
            $out[] = 'rate ' . implode(' + ', array_map(static fn(Rate $b): string => "{$b->limit}/" . self::duration($b->period), $n->rate));
        }
        if (null !== $n->concurrency) {
            $out[] = "≤{$n->concurrency} at once";
        }
        if ($n->limited() && Per::Policy === $n->per) {
            $out[] = 'per policy';
        }
        if (null !== $n->group) {
            $out[] = "groups of {$n->group->size}" . ($n->group->perKey ? ' by key' : '')
                . (null !== $n->group->maxWait ? ', max wait ' . self::duration($n->group->maxWait) : '');
        }

        return $out;
    }

    /**
     * @param array<string, true> $varying
     *
     * @return list<string>
     */
    private static function stateNotes(Node $n, array $varying): array
    {
        $notes = [];
        if (null !== $n->wait) {
            $notes[] = "waits {$n->wait}" . (null !== $n->timeout ? ', timeout ' . self::duration($n->timeout) : '');
        }
        if (null !== $n->lane) {
            $notes[] = self::describeLane($n->lane);
        }
        if ($n->optional) {
            $notes[] = 'optional' . (null !== $n->grace ? ', grace ' . self::duration($n->grace) : '');
        }
        if (null !== $n->lease) {
            $notes[] = 'lease ' . self::duration($n->lease);
        }
        array_push($notes, ...self::limits($n));
        if ($n->once) {
            $notes[] = 'once';
        }
        if (isset($varying[$n->name])) {
            $notes[] = 'varies by policy';
        }

        return $notes;
    }

    private static function edgeLabel(Node $child, string $parent): string
    {
        return isset($child->on[$parent]) ? implode(' / ', $child->on[$parent]) : '';
    }

    private static function describeLane(Lane $lane): string
    {
        $merger = $lane->merger();
        $head = match (true) {
            null !== $merger => "lane: merged by {$merger}",
            Merge::All->value === $lane->merge => "lane: every version, up to {$lane->maxSize}",
            default => "lane: {$lane->merge} version",
        };
        $words = ["{$head}, place of the {$lane->position->value}"];
        foreach (['cooldown' => $lane->cooldown, 'delay' => $lane->delay, 'max wait' => $lane->maxWait] as $label => $value) {
            if (null !== $value) {
                $words[] = "{$label} " . self::duration($value);
            }
        }
        if (WhileRunning::Skip === $lane->whileRunning) {
            $words[] = 'skips while running';
        }

        return implode(' · ', $words);
    }

    /** A duration as declared: text as written, a number as seconds. */
    private static function duration(int|float|string $value): string
    {
        return \is_string($value) ? $value : "{$value}s";
    }

    /** @return array{0: string, 1: string} */
    private static function mermaidShape(Node $n): array
    {
        return match (true) {
            $n->choice => ['{', '}'],
            null !== $n->wait => ['{{', '}}'],
            null !== $n->lane => ['[/', '\]'],
            default => ['(', ')'],
        };
    }

    /**
     * Mermaid ids: node names made safe, unique even when two names clean up
     * to the same text.
     *
     * @param list<Node> $nodes
     *
     * @return array<string, string>
     */
    private static function ids(array $nodes): array
    {
        $ids = [];
        $used = [];
        foreach ($nodes as $n) {
            $base = 'n_' . preg_replace('/[^A-Za-z0-9_]/', '_', $n->name);
            $candidate = $base;
            for ($i = 2; isset($used[$candidate]); ++$i) {
                $candidate = "{$base}_{$i}";
            }
            $used[$candidate] = true;
            $ids[$n->name] = $candidate;
        }

        return $ids;
    }

    /** A state label cannot hold a double quote, and a colon starts a label. */
    private static function escapeState(string $text): string
    {
        return str_replace(['"', ':'], ["'", '∶'], $text);
    }

    /** Backslashes and quotes escaped; a line break becomes DOT's `\n`. */
    private static function escapeDot(string $text): string
    {
        return str_replace(["\\", '"', "\n"], ["\\\\", '\\"', '\\n'], $text);
    }
}
