<?php

declare(strict_types=1);

namespace Quazardous\GramPHP\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Quazardous\GramPHP\Dag;
use Quazardous\GramPHP\DagError;
use Quazardous\GramPHP\Group;
use Quazardous\GramPHP\Lane;
use Quazardous\GramPHP\Loop;
use Quazardous\GramPHP\Merge;
use Quazardous\GramPHP\Node;
use Quazardous\GramPHP\Rate;
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
        yield 'lane with a retry' => [[new Node('a', lane: new Lane(), retry: new Retry(1))], 'is a lane'];
        yield 'optional lane' => [[new Node('a', lane: new Lane(), optional: true)], 'is a lane'];
        yield 'lane merge unknown' => [[new Node('a', lane: new Lane('sometimes'))], 'expected one of'];
        yield 'lane merge naming nothing' => [[new Node('a', lane: new Lane(Merge::fn('')))], 'names no function'];
        yield 'lane keeping nothing' => [[new Node('a', lane: Lane::batch(maxSize: 0))], 'keeps nothing'];
        yield 'lane zero cooldown' => [[new Node('a', lane: Lane::throttle(0))], 'must last'];
        yield 'lane bad delay' => [[new Node('a', lane: Lane::debounce('soon'))], 'not a duration'];
        yield 'lane with a concurrency' => [[new Node('a', lane: new Lane(), concurrency: 2)], 'is a lane'];
        yield 'zero concurrency' => [[new Node('a', concurrency: 0)], 'at least 1'];
        yield 'wait with a rate' => [[new Node('a'), new Node('b', parents: ['a'], wait: 'e', rate: [new Rate(1, '1m')])], 'takes no rate'];
        yield 'empty group' => [[new Node('a', group: new Group(0))], 'not a group'];
        yield 'group on a choice' => [[new Node('a', group: new Group(2), choice: true), new Node('b', parents: ['a'])], 'groups its subjects'];
        yield 'group zero maxWait' => [[new Node('a', group: new Group(2, maxWait: 0))], 'must last'];
    }

    /** @param list<Node> $nodes */
    #[DataProvider('brokenGraphs')]
    public function testCheckRefusesAGraphThatDoesNotHoldTogether(array $nodes, string $needle): void
    {
        $this->expectException(DagError::class);
        $this->expectExceptionMessage($needle);
        (new Dag(...$nodes))->check();
    }

    public function testRateBandsAdmitTheirBurstThenTheirPace(): void
    {
        $band = new Rate(3, '1m');
        self::assertSame([3, [60.0]], Rate::admit([$band], [null], 0.0, 10), 'a fresh band lets its burst through');
        self::assertSame([0, [60.0]], Rate::admit([$band], [60.0], 0.0, 10), 'then nothing');
        self::assertSame(1, Rate::admit([$band], [60.0], 20.0, 10)[0], 'until one interval has passed');
        self::assertSame(1, Rate::admit([$band, new Rate(1, '1h')], [null, null], 0.0, 10)[0], 'the tightest band decides');
    }

    public function testARateRefusesWhatCannotBe(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Rate(0, '1m');
    }

    public function testTheContractGraphsHoldTogether(): void
    {
        foreach ([Graphs::diamond(), Graphs::saga(), Graphs::quorum(), Graphs::route(), Graphs::review(), Graphs::flaky(), Graphs::onboarding(), Graphs::listing()] as $dag) {
            $dag->check();
        }
        $this->addToAssertionCount(8);
    }
}
