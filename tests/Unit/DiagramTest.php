<?php

declare(strict_types=1);

namespace Quazardous\GramPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Quazardous\GramPHP\Dag;
use Quazardous\GramPHP\Diagram;
use Quazardous\GramPHP\Document;
use Quazardous\GramPHP\Driver\Memory\MemoryDriver;
use Quazardous\GramPHP\Graph;
use Quazardous\GramPHP\Group;
use Quazardous\GramPHP\Lane;
use Quazardous\GramPHP\Loop;
use Quazardous\GramPHP\Node;
use Quazardous\GramPHP\NodeJournal;
use Quazardous\GramPHP\Per;
use Quazardous\GramPHP\Rate;
use Quazardous\GramPHP\Retry;
use Quazardous\GramPHP\Status;

/** The graph, drawn: every declared mechanism shows, counts overlay. */
final class DiagramTest extends TestCase
{
    /** A brick sorter: every mechanism in one line. */
    private static function sorter(): Graph
    {
        return new Graph(new Document('brick-sorter'), new Dag(
            new Node('scan', choice: true, lease: '1m'),
            new Node('colour', parents: ['scan']),
            new Node('quarantine', parents: ['scan'], wait: 'deminer.called', timeout: '1h'),
            new Node('defuse', parents: ['quarantine'], retry: new Retry(limit: 3, delay: '10s')),
            new Node('reject', parents: ['defuse'], on: ['defuse' => [Status::Failed]]),
            new Node('check', parents: ['colour'], optional: true, grace: '5m', loop: new Loop(to: 'colour', max: 2)),
            new Node('pack', parents: ['colour', 'defuse', 'check'], need: 2, once: true),
        ), ['supplier-b' => ['defuse' => ['retry' => new Retry(limit: 5)]]]);
    }

    private static function offers(?Lane $lane = null): Graph
    {
        return new Graph(new Document('offers'), new Dag(
            new Node('in', lane: $lane ?? Lane::throttle('1d', maxWait: '3d'), rate: [new Rate(10, '1m')]),
            new Node('ai', parents: ['in'], concurrency: 4, per: Per::Policy),
        ));
    }

    public function testMermaidDrawsEveryMechanism(): void
    {
        $text = Diagram::mermaid(self::sorter());
        self::assertStringStartsWith("flowchart LR\n", $text);
        self::assertStringContainsString('n_scan{"scan<br/>⏱ 1m"}', $text, 'a choice is a diamond');
        self::assertStringContainsString('n_quarantine{{"quarantine<br/>waits deminer.called ⏱ 1h"}}', $text);
        self::assertStringContainsString('retry ×3', $text);
        self::assertStringContainsString('varies by policy', $text);
        self::assertStringContainsString('2/3 · once', $text);
        self::assertStringContainsString('n_defuse -.->|"failed"| n_reject', $text, 'a failure edge');
        self::assertStringContainsString('n_check -.->|"loop ≤2 on failed"| n_colour', $text, 'a loop edge');
        self::assertStringContainsString('class n_check optional', $text);
        self::assertMatchesRegularExpression('/linkStyle \d+ stroke:#d33/', $text, 'the failure edge is red');
        self::assertSame(1, substr_count($text, 'n_colour --> n_pack'));
    }

    public function testTheRedLinkIsTheFailureEdge(): void
    {
        $text = Diagram::mermaid(self::sorter());
        $links = array_values(array_map('trim', array_filter(explode("\n", $text), static fn(string $l): bool => str_contains($l, '-->') || str_contains($l, '-.->'))));
        self::assertSame(1, preg_match_all('/linkStyle (\d+) stroke:#d33/', $text, $m));
        self::assertSame('n_defuse -.->|"failed"| n_reject', $links[(int) $m[1][0]]);
    }

    public function testDotDrawsEveryMechanism(): void
    {
        $text = Diagram::dot(self::sorter());
        self::assertStringStartsWith('digraph gramphp {', $text);
        self::assertStringContainsString('"scan" [label="scan\n⏱ 1m", shape=diamond];', $text);
        self::assertStringContainsString('shape=hexagon', $text);
        self::assertStringContainsString('style="rounded,dashed"', $text);
        self::assertStringContainsString('"defuse" -> "reject" [style=dashed, label="failed", color="#d33"', $text);
        self::assertStringContainsString('"check" -> "colour" [style=dotted, constraint=false', $text);
    }

    public function testCountsOverlayTheJournal(): void
    {
        $journal = new NodeJournal(new MemoryDriver(), self::sorter());
        $lease = $journal->claim('scan', 5, ['a', 'b', 'c']);
        $journal->conclude('scan', ['a'], $lease->token, branch: 'colour');
        $counts = Diagram::overlay($journal);
        self::assertSame(2, $counts['scan']['running']);
        self::assertSame(1, $counts['quarantine']['omitted']);
        $text = Diagram::mermaid(self::sorter(), $counts);
        self::assertStringContainsString('▶2 ✓1', $text);
        self::assertStringContainsString('∅1', $text);
        self::assertStringNotContainsString('✓0', $text, 'zeros are left out');
    }

    public function testNamesAreMadeSafeAndStayUnique(): void
    {
        $dag = new Dag(new Node('a"b'), new Node('a-b', parents: ['a"b']), new Node('a b', parents: ['a-b']));
        $text = Diagram::mermaid($dag);
        preg_match_all('/^\s+(n_\w+)[\(\{]/m', $text, $m);
        self::assertCount(3, array_unique($m[1]));
        self::assertCount(3, $m[1]);
        self::assertStringContainsString('#quot;', $text);
        self::assertStringContainsString('"a\"b" -> "a-b";', Diagram::dot($dag));
    }

    public function testGraphvizAcceptsTheDot(): void
    {
        $dot = trim((string) shell_exec('command -v dot 2>/dev/null'));
        if ('' === $dot) {
            self::markTestSkipped('graphviz is not installed');
        }
        $process = proc_open([$dot, '-Tsvg'], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        fwrite($pipes[0], Diagram::dot(self::sorter()));
        fclose($pipes[0]);
        stream_get_contents($pipes[1]);
        $errors = (string) stream_get_contents($pipes[2]);
        self::assertSame(0, proc_close($process), $errors);
    }

    public function testLanesLimitsAndGroupsAreDrawn(): void
    {
        $text = Diagram::mermaid(self::offers());
        self::assertStringContainsString('n_in[/"in<br/>lane: last version, place of the first · cooldown 1d · max wait 3d', $text);
        self::assertStringContainsString('rate 10/1m', $text);
        self::assertStringContainsString('≤4 at once · per policy', $text);
        self::assertStringContainsString('shape=house', Diagram::dot(self::offers()));
        $journal = new NodeJournal(new MemoryDriver(), self::offers(), static fn(): string => '2026-01-01T00:00:00+00:00');
        $journal->arrive('in', ['a', 'b']);
        self::assertStringContainsString('⧖2', Diagram::mermaid(self::offers(), Diagram::overlay($journal)));
        $packing = new Dag(new Node('sort'), new Node('pack', parents: ['sort'], group: new Group(5, maxWait: '1h')));
        self::assertStringContainsString('groups of 5 by key, max wait 1h', Diagram::mermaid($packing));
    }

    public function testTheStateDiagramReadsLikeAStatechart(): void
    {
        $text = Diagram::stateDiagram(self::sorter());
        self::assertStringStartsWith("stateDiagram-v2\n    direction LR\n", $text);
        foreach ([
            '    [*] --> n_scan',
            '    state n_scan_choice <<choice>>',
            '    n_scan --> n_scan_choice',
            '    n_scan_choice --> n_colour',
            'n_scan_choice --> n_quarantine',
            '    state n_pack_join <<join>>',
            '    n_pack_join --> n_pack : need 2/3',
            '    state n_defuse_choice <<choice>>',
            '    n_defuse_choice --> n_reject : failed',
            '    n_defuse_choice --> n_pack_join : done',
            '    state n_colour_fork <<fork>>',
            '    n_check --> n_colour : loop ≤2 on failed',
            '    n_defuse --> n_defuse : failed, retry ×3',
            '    n_pack --> [*]',
            '    n_reject --> [*]',
            'note right of n_quarantine : waits deminer.called, timeout 1h',
            'note right of n_check : optional, grace 5m',
            'varies by policy',
        ] as $line) {
            self::assertStringContainsString($line, $text);
        }
        self::assertLessThan(strpos($text, '--> n_pack_join'), strpos($text, 'state n_pack_join <<join>>'), 'declared before use, or Mermaid draws a plain state');
    }

    public function testTheStateDiagramForksWhereANodeHasSeveralChildren(): void
    {
        $text = Diagram::stateDiagram(new Dag(new Node('a'), new Node('b', parents: ['a']), new Node('c', parents: ['a']), new Node('d', parents: ['b', 'c'])));
        self::assertStringContainsString('    state n_a_fork <<fork>>', $text);
        self::assertStringContainsString('    n_a_fork --> n_b', $text);
        self::assertStringContainsString('    n_b --> n_d_join', $text);
        self::assertStringContainsString("    n_d_join --> n_d\n", $text, 'a join of every parent says nothing more');
    }

    public function testTheStateDiagramNotesLanesAndLimits(): void
    {
        $text = Diagram::stateDiagram(self::offers(Lane::throttle('1d')));
        self::assertStringContainsString('note right of n_in : lane∶ last version, place of the first · cooldown 1d', $text);
        self::assertStringContainsString('rate 10/1m', $text);
        self::assertStringContainsString('note right of n_ai : ≤4 at once · per policy', $text);
    }
}
