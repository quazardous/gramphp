<?php

declare(strict_types=1);

namespace Quazardous\GramPHP\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Quazardous\GramPHP\Dag;
use Quazardous\GramPHP\DagError;
use Quazardous\GramPHP\Loop;
use Quazardous\GramPHP\Node;
use Quazardous\GramPHP\Retry;
use Quazardous\GramPHP\Status;
use Quazardous\GramPHP\Tests\Contract\Graphs;

/** The rule, without any storage: an invented graph, a progress, an answer. */
final class DagTest extends TestCase
{
    public function testAForkMakesParallelismVisible(): void
    {
        self::assertSame(['left', 'right'], Graphs::diamond()->claimableNodes(['start' => Status::Done]));
    }

    public function testAJoinWaitsForEveryParentByDefault(): void
    {
        $dag = Graphs::diamond();
        self::assertFalse($dag->claimable('end', ['start' => Status::Done, 'left' => Status::Done]));
        self::assertTrue($dag->claimable('end', ['start' => Status::Done, 'left' => Status::Done, 'right' => Status::Skipped]));
    }

    public function testAFailedParentSatisfiesNobodyUnlessTheEdgeSaysSo(): void
    {
        $dag = Graphs::saga();
        $progress = ['order' => Status::Done, 'pay' => Status::Done, 'reserve' => Status::Failed];
        self::assertFalse($dag->claimable('ship', $progress));
        self::assertTrue($dag->claimable('refund', $progress));
    }

    public function testAChoiceOmitsTheOtherBranchAndWhatOnlyItReaches(): void
    {
        self::assertSame(['reject', 'notify'], Graphs::route()->omittedBy('classify', 'publish'));
        self::assertSame(['publish'], Graphs::route()->omittedBy('classify', 'reject'));
    }

    public function testDescendantsAndAncestorsWalkTheGraph(): void
    {
        $dag = Graphs::diamond();
        self::assertSame(['end', 'left', 'right'], $dag->descendants('start'));
        self::assertSame(['left', 'right', 'start'], $dag->ancestors('end'));
    }

    /** @return iterable<string, array{0: list<Node>, 1: string}> */
    public static function brokenGraphs(): iterable
    {
        yield 'two roots' => [[new Node('a'), new Node('b')], 'root(s)'];
        yield 'unknown parent' => [[new Node('a'), new Node('b', parents: ['x'])], 'does not exist'];
        yield 'duplicate name' => [[new Node('a'), new Node('a')], 'two nodes'];
        yield 'cycle' => [[new Node('r'), new Node('a', parents: ['r', 'b']), new Node('b', parents: ['a'])], 'cycle'];
        yield 'need too high' => [[new Node('a'), new Node('b', parents: ['a'], need: 2)], 'need=2'];
        yield 'on a non-parent' => [[new Node('a'), new Node('b', parents: ['a'], on: ['x' => [Status::Failed]])], 'not one of its parents'];
        yield 'on running' => [[new Node('a'), new Node('b', parents: ['a'], on: ['a' => [Status::Running]])], 'unknown status'];
        yield 'choice without branch' => [[new Node('a', choice: true)], 'without any branch'];
        yield 'loop to a sibling' => [[new Node('r'), new Node('a', parents: ['r']), new Node('b', parents: ['r'], loop: new Loop(to: 'a', max: 1))], 'neither itself'];
        yield 'timeout without wait' => [[new Node('a', timeout: '1h')], 'needs a wait'];
        yield 'wait with retry' => [[new Node('a'), new Node('b', parents: ['a'], wait: 'e', retry: new Retry(1))], 'cannot take'];
        yield 'grace not optional' => [[new Node('a', grace: '1d')], 'not'];
        yield 'shared state' => [[new Node('a', state: 'x'), new Node('b', parents: ['a'], state: 'x')], 'ambiguous'];
        yield 'zero lease' => [[new Node('a', lease: 0)], 'must last'];
    }

    /** @param list<Node> $nodes */
    #[DataProvider('brokenGraphs')]
    public function testCheckRefusesAGraphThatDoesNotHoldTogether(array $nodes, string $needle): void
    {
        $this->expectException(DagError::class);
        $this->expectExceptionMessage($needle);
        (new Dag(...$nodes))->check();
    }

    public function testTheContractGraphsHoldTogether(): void
    {
        foreach ([Graphs::diamond(), Graphs::saga(), Graphs::quorum(), Graphs::route(), Graphs::review(), Graphs::flaky(), Graphs::onboarding()] as $dag) {
            $dag->check();
        }
        $this->addToAssertionCount(7);
    }
}
