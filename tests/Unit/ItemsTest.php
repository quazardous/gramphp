<?php

declare(strict_types=1);

namespace Quazardous\GramPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Quazardous\GramPHP\Driver\Memory\MemoryDriver;
use Quazardous\GramPHP\Items\Adapter;
use Quazardous\GramPHP\Items\ItemLease;
use Quazardous\GramPHP\Items\Items;
use Quazardous\GramPHP\Node;
use Quazardous\GramPHP\NodeJournal;
use Quazardous\GramPHP\Status;
use Quazardous\GramPHP\Tests\Contract\Brick;
use Quazardous\GramPHP\Tests\Contract\Bricks;
use Quazardous\GramPHP\Tests\Contract\Graphs;

/** The items layer: objects in, objects out, handlers where data is needed. */
final class ItemsTest extends TestCase
{
    /** @var list<Brick> */
    private array $bricks;

    private Bricks $adapter;

    private Items $items;

    protected function setUp(): void
    {
        $this->bricks = [new Brick(1), new Brick(2, crate: 'salvage'), new Brick(3, kind: 'scrap')];
        $this->adapter = new Bricks($this->bricks);
        $this->items = new Items(new NodeJournal(new MemoryDriver(), Bricks::graph()), $this->adapter);
    }

    public function testCandidatesAreItemsTooAndAreNotLoadedTwice(): void
    {
        $lease = $this->items->claim('scan', 10, $this->bricks);
        self::assertSame([1, 2, 3], self::ids($lease));
        foreach ($lease as $brick) {
            self::assertSame($this->bricks[Bricks::brick($brick)->id - 1], $brick, 'the very objects given');
        }
        self::assertSame(['applies(1,scan)', 'applies(2,scan)', 'applies(3,scan)'], $this->adapter->calls, 'nothing inflated');
        self::assertNotSame('', $lease->token, 'the lease still carries its proof');
    }

    public function testAGeneratorOfItemsWorksLikeAList(): void
    {
        $lease = $this->items->claim('scan', 10, (fn(): \Generator => yield from $this->bricks)());
        self::assertSame([1, 2, 3], self::ids($lease));
        self::assertSame(['applies(1,scan)', 'applies(2,scan)', 'applies(3,scan)'], $this->adapter->calls, 'nothing inflated');
    }

    public function testAChoiceTakesTheBranchTheHandlerNames(): void
    {
        $lease = $this->items->claim('scan', 10, $this->bricks);
        self::assertSame(3, $this->items->conclude('scan', $lease));

        // Brick 3 is scrap: it burns, so `sort` is omitted for it alone. The
        // branch taken has no row yet — it is simply what comes next.
        self::assertSame(Status::Omitted, $this->items->progress($this->bricks[2])['sort']);
        self::assertArrayNotHasKey('burn', $this->items->progress($this->bricks[2]), 'the branch taken is next');
        self::assertSame(Status::Omitted, $this->items->progress($this->bricks[0])['burn']);
        self::assertArrayNotHasKey('sort', $this->items->progress($this->bricks[0]));

        // One call, two branches: each item went where its handler said.
        self::assertSame([3], self::ids($this->items->claim('burn', 10, $this->bricks)));
        self::assertSame([1, 2], self::ids($this->items->claim('sort', 10, $this->bricks)));
    }

    public function testOnlyAChoiceIsAskedForABranch(): void
    {
        $this->items->conclude('scan', $this->items->claim('scan', 10, $this->bricks));
        $this->adapter->calls = [];
        $this->items->conclude('sort', $this->items->claim('sort', 10, $this->bricks));
        self::assertSame([], $this->adapter->called('branch'));
    }

    public function testAFailedChoiceNamesNoBranch(): void
    {
        $lease = $this->items->claim('scan', 10, $this->bricks);
        $this->adapter->calls = [];
        self::assertSame(3, $this->items->fail('scan', $lease));
        self::assertSame([], $this->adapter->called('branch'));
        self::assertSame(Status::Failed, $this->items->progress($this->bricks[0])['scan']);
    }

    public function testAnOptionalNodeIsGivenUpOnTheItemsThatRefuseIt(): void
    {
        $two = \array_slice($this->bricks, 0, 2);
        $this->items->conclude('scan', $this->items->claim('scan', 10, $two));
        $this->items->conclude('sort', $this->items->claim('sort', 10, $two));

        $lease = $this->items->claim('polish', 10, $two);
        self::assertSame([1], self::ids($lease), 'the salvage brick is not in the lease');
        self::assertSame(Status::Skipped, $this->items->progress($this->bricks[1])['polish'], 'and the journal records that the decision was taken');

        $this->items->conclude('polish', $lease);
        // Nothing downstream waits for a step that was given up.
        self::assertSame([1, 2], self::ids($this->items->claim('pack', 10, $two)));
    }

    public function testThePolicyIsReadFromTheItem(): void
    {
        self::assertSame(3, $this->items->admit($this->bricks));
        self::assertSame('salvage', $this->items->journal->policy(2));
        self::assertSame('factory', $this->items->journal->policy(1));
    }

    public function testTheAdapterIsAskedOncePerItemPerCall(): void
    {
        $this->items->claim('scan', 10, [...$this->bricks, ...$this->bricks]);
        foreach ([1, 2, 3] as $id) {
            self::assertSame(1, \count(array_keys($this->adapter->calls, "applies({$id},scan)", true)));
        }
    }

    public function testTheHandlersHaveAnswersForAnApplicationWithNothingToSay(): void
    {
        $bare = new class extends Adapter {
            public function idOf(mixed $candidate): int|string
            {
                return \is_int($candidate) || \is_string($candidate) ? $candidate : throw new \LogicException('an id');
            }
        };
        $items = new Items(new NodeJournal(new MemoryDriver(), [new Node('a'), new Node('b', parents: ['a'])]), $bare);
        $lease = $items->claim('a', 10, ['x', 'y']);
        self::assertSame(['x', 'y'], $lease->items);
        self::assertSame(2, $items->conclude('a', $lease));
        self::assertSame(['a' => Status::Done], $items->progress('x'));
    }

    public function testTheJanitorPassSpeaksItemsToo(): void
    {
        $two = \array_slice($this->bricks, 0, 2);
        $this->items->conclude('scan', $this->items->claim('scan', 10, $two));
        $this->items->conclude('sort', $this->items->claim('sort', 10, $two));

        // `skip` gives up an optional node without claiming it first.
        self::assertSame(1, $this->items->skip('polish', [$this->bricks[1]]));
        self::assertSame(Status::Skipped, $this->items->progress($this->bricks[1])['polish']);

        // `settle` reports per node, and takes items rather than ids.
        self::assertSame([], $this->items->settle($this->bricks));
        self::assertSame([2], self::ids($this->items->claim('pack', 10, $two)));
    }

    public function testIdsAreAcceptedWhereverItemsAre(): void
    {
        $lease = $this->items->claim('scan', 10, [1, 2, 3]);
        self::assertSame([1, 2, 3], self::ids($lease));
        self::assertContainsOnlyInstancesOf(Brick::class, $lease->items, 'ids came back as objects');
        self::assertSame(['inflate([1, 2, 3])'], $this->adapter->called('inflate'));

        $this->items->conclude('scan', $lease);
        self::assertSame([], $this->items->settle([1, 2, 3]), 'settle takes ids too');
    }

    public function testObjectsAndIdsMayBeMixedInOneCall(): void
    {
        $lease = $this->items->claim('scan', 10, [$this->bricks[0], 2, $this->bricks[2]]);
        self::assertSame([1, 2, 3], self::ids($lease));
        self::assertSame($this->bricks[0], $lease->items[0], 'the object given is the object returned');
        self::assertSame(['inflate([2])'], $this->adapter->called('inflate'), 'only the id was fetched');
    }

    public function testAnIdNothingLoadsIsLeftOutOfTheCandidates(): void
    {
        $lease = $this->items->claim('scan', 10, [1, 99]);
        self::assertSame([1], self::ids($lease), 'the adapter said what is an item');
        self::assertSame([], $lease->missing);
    }

    public function testProgressOfManyItemsGivesEachItemBackWithIt(): void
    {
        $this->items->conclude('scan', $this->items->claim('scan', 1, $this->bricks));
        $read = $this->items->progressMany($this->bricks);
        self::assertSame($this->bricks, array_column($read, 0), 'the very objects given, in their order');
        $progress = array_column($read, 1);
        self::assertSame(array_map($this->items->progress(...), $this->bricks), $progress);
        self::assertNotSame([], $progress[0]);
        self::assertSame([], $progress[1]);
    }

    public function testSignalAndHistoryAndSnapshotSpeakItems(): void
    {
        self::assertSame(1, $this->items->signal([$this->bricks[0]], 'arrived'));
        self::assertSame(3, $this->items->snapshot($this->bricks, ['scan'])['scan']['ready']);
        $lease = $this->items->claim('scan', 2, $this->bricks);
        $this->items->conclude('scan', $lease);
        $this->items->journal->forget('scan', [1]);
        self::assertContains(['scan', 'forget'], array_map(static fn(array $row): array => [$row['node'], $row['reason']], $this->items->history($this->bricks[0])));
        self::assertSame(1, $this->items->snapshot($this->bricks, ['scan'])['scan']['ready'], 'brick 3 is still to scan');
    }

    public function testArrivalsOfDifferentVersionsAreCountedTogether(): void
    {
        // One call per distinct ref, but ONE set of counts: added, not the
        // last ref's counts replacing the others'.
        $stamps = new class ([new Brick(1), new Brick(2), new Brick(3)]) extends Adapter {
            /** @param list<Brick> $bricks */
            public function __construct(private readonly array $bricks) {}

            public function idOf(mixed $candidate): int
            {
                return Bricks::brick($candidate)->id;
            }

            public function refOf(mixed $item): string
            {
                return 'v' . Bricks::brick($item)->id;
            }

            /** @return list<Brick> */
            public function all(): array
            {
                return $this->bricks;
            }
        };
        $items = new Items(new NodeJournal(new MemoryDriver(), Graphs::listing()), $stamps);
        self::assertSame(['queued' => 3, 'merged' => 0, 'skipped' => 0], $items->arrive('arrive', $stamps->all()), 'three refs, three groups, three arrivals');
        self::assertSame('v2', $items->journal->arrival(2, 'arrive')?->ref);
        self::assertSame(['queued' => 0, 'merged' => 1, 'skipped' => 0], $items->arrive('arrive', [new Brick(2)]));
    }

    public function testATokenMayComeWithoutTheLease(): void
    {
        $lease = $this->items->claim('scan', 10, $this->bricks);
        self::assertSame(0, $this->items->conclude('scan', $lease->items, 'not-the-token'), 'a wrong proof writes nothing');
        self::assertSame(3, $this->items->conclude('scan', $lease->items, $lease->token));
    }

    /**
     * @param ItemLease<mixed> $lease
     *
     * @return list<int>
     */
    private static function ids(ItemLease $lease): array
    {
        return array_map(static fn(mixed $b): int => Bricks::brick($b)->id, $lease->items);
    }
}
