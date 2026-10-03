<?php

declare(strict_types=1);

namespace Quazardous\GramPHP\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Quazardous\GramPHP\Dag;
use Quazardous\GramPHP\Document;
use Quazardous\GramPHP\Graph;
use Quazardous\GramPHP\GraphFormatError;
use Quazardous\GramPHP\Group;
use Quazardous\GramPHP\Lane;
use Quazardous\GramPHP\Loop;
use Quazardous\GramPHP\Node;
use Quazardous\GramPHP\Retry;
use Quazardous\GramPHP\Status;
use Quazardous\GramPHP\Tests\Contract\Graphs;

/** The graph as data: one canonical form, a strict reader, grampy's format. */
final class GraphFormatTest extends TestCase
{
    public function testTheCanonicalFormWritesOnlyWhatDiffersFromADefault(): void
    {
        $graph = new Graph(new Document('orders', '3', 'shop'), new Dag(
            new Node('start', working: 'starting', state: 'started'),
            new Node('left', parents: ['start']),
            new Node('right', parents: ['start'], optional: true, once: true),
            new Node('end', parents: ['left', 'right']),
        ));
        self::assertSame([
            'document' => ['dsl' => 'grampy/1', 'namespace' => 'shop', 'name' => 'orders', 'version' => '3'],
            'nodes' => [
                'start' => ['working' => 'starting', 'state' => 'started'],
                'left' => ['parents' => ['start']],
                'right' => ['parents' => ['start'], 'optional' => true, 'once' => true],
                'end' => ['parents' => ['left', 'right']],
            ],
        ], $graph->toArray());
    }

    public function testJoinsChoicesLoopsAndRetriesAreWrittenAsData(): void
    {
        $graph = new Graph(new Document('saga'), new Dag(
            new Node('order', choice: true),
            new Node('pay', parents: ['order']),
            new Node('cancel', parents: ['order']),
            new Node('refund', parents: ['pay'], on: ['pay' => [Status::Failed]]),
            new Node('end', parents: ['pay', 'cancel'], need: 1),
            new Node('check', parents: ['end'], loop: new Loop(to: 'pay', max: 3), retry: new Retry(limit: 2, delay: '30s', maxDelay: '5m', jitter: 0.1), lease: '10m'),
        ));
        $nodes = $graph->toArray()['nodes'];
        self::assertIsArray($nodes);
        self::assertSame(['choice' => true], $nodes['order']);
        self::assertSame(['parents' => ['pay'], 'on' => ['pay' => ['failed']]], $nodes['refund']);
        self::assertSame(['parents' => ['pay', 'cancel'], 'need' => 1], $nodes['end']);
        self::assertSame([
            'parents' => ['end'], 'loop' => ['to' => 'pay', 'max' => 3, 'on' => ['failed']], 'lease' => '10m',
            'retry' => ['limit' => 2, 'delay' => '30s', 'backoff' => 'exponential', 'max_delay' => '5m', 'jitter' => 0.1],
        ], $nodes['check']);
        self::assertSame($graph->toArray(), Graph::fromJson($graph->toJson())->toArray());
    }

    public function testLanesGroupsAndPoliciesRoundTrip(): void
    {
        $graph = new Graph(new Document('x'), new Dag(
            new Node('in', lane: Lane::debounce('5m', maxWait: '1h')),
            new Node('work', parents: ['in']),
            new Node('pack', parents: ['work'], group: new Group(5, maxWait: '1h', perKey: false)),
        ), ['slow' => ['in' => ['lane' => Lane::throttle('1d')], 'work' => ['lease' => '2h']]]);
        $data = $graph->toArray();
        $nodes = $data['nodes'];
        self::assertIsArray($nodes);
        self::assertSame(['lane' => ['position' => 'last', 'delay' => '5m', 'max_wait' => '1h']], $nodes['in']);
        self::assertSame(['parents' => ['work'], 'group' => ['size' => 5, 'max_wait' => '1h', 'per_key' => false]], $nodes['pack']);
        self::assertSame(['slow' => ['in' => ['lane' => ['cooldown' => '1d']], 'work' => ['lease' => '2h']]], $data['policies'] ?? null);
        $back = Graph::fromJson($graph->toJson());
        self::assertSame($data, $back->toArray());
        self::assertSame('1d', $back->variant('slow')->node('in')->lane?->cooldown);
    }

    public function testEveryContractGraphRoundTrips(): void
    {
        foreach ([Graphs::diamond(), Graphs::saga(), Graphs::quorum(), Graphs::route(), Graphs::review(), Graphs::flaky(), Graphs::onboarding(), Graphs::listing()] as $dag) {
            $graph = new Graph(new Document('g'), $dag);
            self::assertSame($graph->toJson(), Graph::fromJson($graph->toJson())->toJson());
        }
    }

    /** @return iterable<string, array{string}> */
    public static function grampyFiles(): iterable
    {
        yield 'every mechanism' => ['grampy-sorter.json'];
        yield 'lanes and a policy' => ['grampy-listing.json'];
    }

    /** A graph written by grampy itself, read here and written back: the same file, byte for byte. */
    #[DataProvider('grampyFiles')]
    public function testAGraphWrittenByGrampyIsTheSameFileHere(string $file): void
    {
        $text = (string) file_get_contents(__DIR__ . '/../Fixtures/' . $file);
        self::assertSame($text, Graph::fromJson($text)->toJson());
    }

    /** @return iterable<string, array{mixed, string}> */
    public static function lies(): iterable
    {
        $doc = ['name' => 'x'];
        yield 'not an object' => ['nope', '$: expected an object'];
        yield 'no nodes' => [['document' => $doc], "\$: missing key(s) [nodes]"];
        yield 'an extra key' => [['document' => $doc, 'nodes' => ['a' => []], 'extra' => 1], '$: unknown key(s) [extra]'];
        yield 'another format' => [['document' => ['name' => 'x', 'dsl' => 'grampy/99'], 'nodes' => ['a' => []]], '$.document.dsl'];
        yield 'no name' => [['document' => [], 'nodes' => ['a' => []]], '$.document: '];
        yield 'a name that is no string' => [['document' => ['name' => 3], 'nodes' => ['a' => []]], '$.document.name'];
        yield 'a misspelled option' => [['document' => $doc, 'nodes' => ['a' => ['optionnal' => true]]], '$.nodes.a: unknown key(s) [optionnal]'];
        yield 'parents not a list' => [['document' => $doc, 'nodes' => ['a' => ['parents' => 'b']]], '$.nodes.a.parents'];
        yield 'a parent not a name' => [['document' => $doc, 'nodes' => ['a' => ['parents' => [1]]]], '$.nodes.a.parents[0]'];
        yield 'a flag not a boolean' => [['document' => $doc, 'nodes' => ['a' => ['optional' => 'yes']]], '$.nodes.a.optional'];
        yield 'an empty state' => [['document' => $doc, 'nodes' => ['a' => ['state' => '']]], '$.nodes.a.state'];
        yield 'on not by parent' => [['document' => $doc, 'nodes' => ['a' => ['on' => ['failed']]]], '$.nodes.a.on'];
        yield 'on not a list' => [['document' => $doc, 'nodes' => ['a' => ['on' => ['b' => 'failed']]]], '$.nodes.a.on.b'];
        yield 'need as text' => [['document' => $doc, 'nodes' => ['a' => ['need' => '2']]], '$.nodes.a.need'];
        yield 'need as a boolean' => [['document' => $doc, 'nodes' => ['a' => ['need' => true]]], '$.nodes.a.need'];
        yield 'a loop without max' => [['document' => $doc, 'nodes' => ['a' => ['loop' => ['to' => 'a']]]], '$.nodes.a.loop: missing key(s) [max]'];
        yield 'a retry that never tries' => [['document' => $doc, 'nodes' => ['a' => ['retry' => ['limit' => 0]]]], '$.nodes.a'];
        yield 'a backoff unknown' => [['document' => $doc, 'nodes' => ['a' => ['retry' => ['limit' => 1, 'backoff' => 'fast']]]], '$.nodes.a.retry'];
        yield 'a lease as a list' => [['document' => $doc, 'nodes' => ['a' => ['lease' => [10]]]], '$.nodes.a.lease'];
        yield 'a wait not a name' => [['document' => $doc, 'nodes' => ['a' => ['wait' => 3]]], '$.nodes.a.wait'];
        yield 'a misspelled lane' => [['document' => $doc, 'nodes' => ['a' => ['lane' => ['cooldwon' => '1h']]]], '$.nodes.a.lane: unknown key(s) [cooldwon]'];
        yield 'a lane merge not a word' => [['document' => $doc, 'nodes' => ['a' => ['lane' => ['merge' => 1]]]], '$.nodes.a.lane.merge'];
        yield 'a lane as a word' => [['document' => $doc, 'nodes' => ['a' => ['lane' => 'throttle']]], '$.nodes.a.lane'];
        yield 'a per unknown' => [['document' => $doc, 'nodes' => ['a' => ['per' => 'team']]], '$.nodes.a.per'];
        yield 'a policy changing the structure' => [['document' => $doc, 'nodes' => ['a' => []], 'policies' => ['c' => ['a' => ['need' => 1]]]], '$.policies.c.a: unknown key(s) [need]'];
        yield 'a policy lease as a list' => [['document' => $doc, 'nodes' => ['a' => []], 'policies' => ['c' => ['a' => ['lease' => []]]]], '$.policies.c.a.lease'];
        yield 'a graph that does not hold' => [['document' => $doc, 'nodes' => ['a' => [], 'b' => []]], '2 root(s)'];
    }

    #[DataProvider('lies')]
    public function testADocumentThatLiesIsRefusedWithItsPath(mixed $data, string $path): void
    {
        $this->expectException(GraphFormatError::class);
        $this->expectExceptionMessage($path);
        Graph::fromArray($data);
    }

    public function testNotJsonIsRefused(): void
    {
        $this->expectException(GraphFormatError::class);
        $this->expectExceptionMessage('not JSON');
        Graph::fromJson('{nope');
    }
}
