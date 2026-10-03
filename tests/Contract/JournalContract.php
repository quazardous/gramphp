<?php

declare(strict_types=1);

namespace Quazardous\GramPHP\Tests\Contract;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Quazardous\GramPHP\Dag;
use Quazardous\GramPHP\DagError;
use Quazardous\GramPHP\Document;
use Quazardous\GramPHP\Driver\LaneDriver;
use Quazardous\GramPHP\Graph;
use Quazardous\GramPHP\Group;
use Quazardous\GramPHP\Lane;
use Quazardous\GramPHP\Lease;
use Quazardous\GramPHP\Merge;
use Quazardous\GramPHP\Node;
use Quazardous\GramPHP\NodeJournal;
use Quazardous\GramPHP\Outcome;
use Quazardous\GramPHP\Per;
use Quazardous\GramPHP\Position;
use Quazardous\GramPHP\Rate;
use Quazardous\GramPHP\Reason;
use Quazardous\GramPHP\Retry;
use Quazardous\GramPHP\Status;
use Quazardous\GramPHP\Time;
use Quazardous\GramPHP\WhileRunning;

/**
 * THE DRIVER CONTRACT — one suite, every driver.
 *
 * A driver is a second expression of the rule in `Dag`. Two expressions of
 * one rule drift apart unless something confronts them, so every driver runs
 * the same tests, including a sweep of progresses — some IMPOSSIBLE through
 * the API — where the driver's claim must answer exactly what
 * `Dag::claimableNodes()` answers.
 *
 * Usage: extend it and implement `makeHarness()`.
 */
abstract class JournalContract extends TestCase
{
    protected Harness $harness;

    protected Clock $clock;

    abstract protected function makeHarness(): Harness;

    protected function setUp(): void
    {
        $this->harness = $this->makeHarness();
        $this->clock = new Clock();
    }

    protected function tearDown(): void
    {
        if (isset($this->harness)) {
            $this->harness->close();
        }
    }

    // -- helpers ---------------------------------------------------------

    /**
     * @param 'int'|'string'                                             $subjectType
     * @param array<string, callable(list<string>, ?string): iterable<string>> $mergers
     */
    private function journal(Graph|Dag|null $dag = null, string $subjectType = 'string', array $mergers = []): NodeJournal
    {
        return $this->harness->journal($dag ?? Graphs::diamond(), $this->clock, $subjectType, $mergers);
    }

    /** @param list<int|string> $subjects */
    private function claim(NodeJournal $journal, string $name, array $subjects, int $limit = 10, bool $requireParents = true): Lease
    {
        return $journal->claim($name, $limit, $this->harness->candidates($subjects), $requireParents);
    }

    /**
     * Claim then conclude, the way a worker does.
     *
     * @param list<int|string> $subjects
     */
    private function work(NodeJournal $journal, string $name, array $subjects, ?string $branch = null): Lease
    {
        $lease = $this->claim($journal, $name, $subjects);
        $journal->conclude($name, $lease, $lease->token, Status::Done, $branch);

        return $lease;
    }

    /** @param list<int|string> $expected */
    private static function assertTaken(array $expected, Lease $lease, string $message = ''): void
    {
        $got = $lease->subjects;
        sort($got);
        sort($expected);
        self::assertSame($expected, $got, $message);
    }

    /** @param array<string, Status> $expected */
    private static function assertProgress(array $expected, NodeJournal $journal, int|string $subject, string $message = ''): void
    {
        $got = $journal->progress($subject);
        ksort($got);
        ksort($expected);
        self::assertSame($expected, $got, $message);
    }

    /** @return list<string> */
    private static function reasons(NodeJournal $journal, int|string $subject): array
    {
        return array_map(static fn(array $row): string => $row['reason'], $journal->history($subject));
    }

    private static function at(float $minutes): string
    {
        return Time::shift(Clock::T0, $minutes * 60);
    }

    // -- the rule, confronted --------------------------------------------

    /**
     * Every progress of the diamond with at most two rows, plus a few deeper
     * ones — many of them unreachable through the API.
     *
     * @return iterable<string, array{0: array<string, Status>}>
     */
    public static function progresses(): iterable
    {
        $statuses = [Status::Running, Status::Done, Status::Skipped, Status::Failed, Status::Omitted];
        $names = ['start', 'left', 'right', 'end'];
        yield 'empty' => [[]];
        foreach ($names as $name) {
            foreach ($statuses as $status) {
                yield "{$name}={$status->value}" => [[$name => $status]];
            }
        }
        foreach ($names as $i => $a) {
            foreach (\array_slice($names, $i + 1) as $b) {
                foreach ($statuses as $sa) {
                    foreach ($statuses as $sb) {
                        yield "{$a}={$sa->value},{$b}={$sb->value}" => [[$a => $sa, $b => $sb]];
                    }
                }
            }
        }
        yield 'deep-skipped' => [['start' => Status::Done, 'left' => Status::Done, 'right' => Status::Skipped]];
        yield 'deep-failed' => [['start' => Status::Done, 'left' => Status::Done, 'right' => Status::Failed]];
        yield 'deep-running' => [['start' => Status::Done, 'right' => Status::Done, 'end' => Status::Running]];
    }

    /** @param array<string, Status> $progress */
    #[DataProvider('progresses')]
    public function testTheDriverClaimsExactlyWhatTheRuleSays(array $progress): void
    {
        $dag = Graphs::diamond();
        $expected = $dag->claimableNodes($progress);
        $got = [];
        foreach ($dag as $node) {
            $journal = $this->journal($dag);
            $this->harness->seed($journal, 's1', $progress);
            if (!$this->claim($journal, $node->name, ['s1'])->isEmpty()) {
                $got[] = $node->name;
            }
        }
        sort($expected);
        sort($got);
        self::assertSame($expected, $got);
    }

    public function testARetryDueExactlyNowIsClaimable(): void
    {
        $journal = $this->journal();
        $this->harness->seed($journal, 's1', ['start' => Status::Scheduled]);
        self::assertSame(Clock::T0, $this->clock->now, 'the seeded due time');
        self::assertTaken(['s1'], $this->claim($journal, 'start', ['s1']));
    }

    public function testStagesTakesTheSubjectsAsTheyAre(): void
    {
        $journal = $this->journal(new Dag(new Node('a')), 'int');
        $lease = $this->claim($journal, 'a', [7], 5);
        $journal->conclude('a', $lease, $lease->token);
        self::assertSame(['7'], array_map('strval', array_keys($journal->stages([7], $this->clock->now))), 'an int subject found as given; the keys are text');
    }

    public function testHistoryOfOneSecondComesBackInTheOrderWritten(): void
    {
        $journal = $this->journal();
        foreach ([Outcome::Merged, Outcome::Dropped, Outcome::Entered, Outcome::Queued] as $status) {
            $journal->driver->note(['s1'], 'start', $status->value, Reason::Lane->value, $this->clock->now, null);
        }
        self::assertSame(['merged', 'dropped', 'entered', 'queued'], array_map(static fn(array $row): string => $row['status'], $journal->history('s1')));
    }

    public function testAClockInAnotherZoneIsReadAsTheSameInstant(): void
    {
        $journal = $this->harness->journal(Graphs::diamond(), static fn(): string => '2026-01-01T01:30:00+02:00');
        $this->harness->seed($journal, 's1', ['start' => Status::Scheduled]);   // due 00:00 UTC
        self::assertTaken([], $this->claim($journal, 'start', ['s1']), '23:30 UTC the day before: not due yet');
    }

    public function testAClockWithoutATimeZoneIsRefused(): void
    {
        $journal = $this->harness->journal(Graphs::diamond(), static fn(): string => '2026-01-01T00:00:00');
        $this->expectException(\InvalidArgumentException::class);
        $this->claim($journal, 'start', ['s1']);
    }

    public function testPruningTheHistoryKeepsWhatABoundCounts(): void
    {
        $journal = $this->journal(new Dag(new Node('call', retry: new Retry(limit: 2, delay: '10s')), new Node('next', parents: ['call'])));
        $lease = $this->claim($journal, 'call', ['s1']);
        $journal->fail('call', ['s1'], $lease->token);        // a retry, archived
        $journal->forget('call', ['s1']);                     // a forget, archived
        $reasons = self::reasons($journal, 's1');
        sort($reasons);
        self::assertSame(['forget', 'retry'], $reasons);
        $this->clock->now = '2026-02-01T00:00:00+00:00';
        self::assertSame(1, $journal->pruneHistory($this->clock->now), 'the forget goes');
        self::assertSame(['retry'], self::reasons($journal, 's1'));
        self::assertSame(1, $journal->retries('s1', 'call'), 'the retry used is still counted');
        $lease = $this->claim($journal, 'call', ['s1']);       // back again
        $journal->fail('call', ['s1'], $lease->token);
        self::assertSame(2, $journal->retries('s1', 'call'), 'and the limit still stands');
    }

    // -- take ------------------------------------------------------------

    public function testAClaimTakesCandidatesInOrderUpToTheLimit(): void
    {
        $journal = $this->journal();
        self::assertTaken(['a', 'b'], $this->claim($journal, 'start', ['b', 'a', 'c'], 2));
        self::assertProgress(['start' => Status::Running], $journal, 'b');
        self::assertProgress([], $journal, 'c');
    }

    public function testAHeldNodeIsNotTakenTwice(): void
    {
        $journal = $this->journal();
        self::assertTaken(['s1'], $this->claim($journal, 'start', ['s1']));
        self::assertTaken([], $this->claim($journal, 'start', ['s1']));
    }

    public function testAChildWaitsForItsParentToConclude(): void
    {
        $journal = $this->journal();
        $lease = $this->claim($journal, 'start', ['s1']);
        self::assertTaken([], $this->claim($journal, 'left', ['s1']));
        $journal->conclude('start', $lease, $lease->token);
        self::assertTaken(['s1'], $this->claim($journal, 'left', ['s1']));
    }

    public function testAFailedParentSatisfiesNobody(): void
    {
        $journal = $this->journal();
        $lease = $this->claim($journal, 'start', ['s1']);
        self::assertSame(1, $journal->fail('start', ['s1'], $lease->token));
        self::assertTaken([], $this->claim($journal, 'left', ['s1']));
    }

    public function testAStartedDescendantClosesTheNode(): void
    {
        $journal = $this->journal();
        $this->harness->seed($journal, 's1', ['start' => Status::Done, 'end' => Status::Running]);
        self::assertTaken([], $this->claim($journal, 'left', ['s1']));
    }

    public function testLiftingParentsKeepsTheTwoOtherGuards(): void
    {
        $journal = $this->journal();
        self::assertTaken(['s1'], $this->claim($journal, 'left', ['s1'], requireParents: false));
        self::assertTaken([], $this->claim($journal, 'left', ['s1'], requireParents: false));
        $this->harness->seed($journal, 's2', ['end' => Status::Done]);
        self::assertTaken([], $this->claim($journal, 'left', ['s2'], requireParents: false));
    }

    public function testAnUnknownNodeRaises(): void
    {
        $journal = $this->journal();
        $this->expectException(DagError::class);
        $this->claim($journal, 'nope', ['s1']);
    }

    public function testNoCandidateTakesNothing(): void
    {
        self::assertTaken([], $this->claim($this->journal(), 'start', []));
    }

    // -- conclude --------------------------------------------------------

    public function testOnlyRunningRowsAreConcluded(): void
    {
        $journal = $this->journal();
        $lease = $this->claim($journal, 'start', ['s1']);
        self::assertSame(1, $journal->conclude('start', ['s1', 's1', 'ghost'], $lease->token));
        self::assertSame(0, $journal->conclude('start', ['s1'], $lease->token), 'a duplicate report rewrites nothing');
        self::assertProgress(['start' => Status::Done], $journal, 's1');
    }

    public function testAnUnknownStatusRaises(): void
    {
        $journal = $this->journal();
        $this->expectException(\InvalidArgumentException::class);
        $journal->conclude('start', ['s1'], null, Status::Running);
    }

    public function testConcludingNobodyIsZero(): void
    {
        self::assertSame(0, $this->journal()->conclude('nope', [], null));
    }

    // -- lease -----------------------------------------------------------

    public function testEveryClaimIssuesItsOwnToken(): void
    {
        $journal = $this->journal();
        $first = $this->claim($journal, 'start', ['s1']);
        $second = $this->claim($journal, 'start', ['s2']);
        $empty = $this->claim($journal, 'start', ['s1']);
        self::assertCount(3, array_unique([$first->token, $second->token, $empty->token]));
    }

    public function testAConclusionNeedsTheTokenOfTheClaim(): void
    {
        $journal = $this->journal();
        $first = $this->claim($journal, 'start', ['s1']);
        $second = $this->claim($journal, 'start', ['s2']);
        self::assertSame(1, $journal->conclude('start', ['s1', 's2'], $first->token));
        self::assertProgress(['start' => Status::Running], $journal, 's2');
        self::assertSame(0, $journal->fail('start', ['s2'], 'forged'));
        self::assertSame(1, $journal->fail('start', ['s2'], $second->token));
    }

    public function testAReleasedLeaseCannotConcludeTheNextOne(): void
    {
        $journal = $this->journal();
        $slow = $this->claim($journal, 'start', ['s1']);
        $this->clock->now = '2026-01-01T01:00:00+00:00';
        self::assertSame(1, $journal->release('start', '2026-01-01T00:30:00+00:00'));
        $fresh = $this->claim($journal, 'start', ['s1']);
        self::assertSame(0, $journal->conclude('start', ['s1'], $slow->token), 'the slow worker came back after its lease went to another');
        self::assertProgress(['start' => Status::Running], $journal, 's1');
        self::assertSame(1, $journal->conclude('start', ['s1'], $fresh->token));
    }

    public function testNoTokenIsTheOperatorsOverride(): void
    {
        $journal = $this->journal();
        $this->claim($journal, 'start', ['s1']);
        self::assertSame(1, $journal->fail('start', ['s1'], null));
    }

    // -- joins as data ---------------------------------------------------

    public function testAFailureEdgeClaimsOnTheFailure(): void
    {
        $journal = $this->journal(Graphs::saga());
        $this->work($journal, 'order', ['s1', 's2']);
        $this->work($journal, 'pay', ['s1', 's2']);
        $lease = $this->claim($journal, 'reserve', ['s1', 's2']);
        $journal->fail('reserve', ['s1'], $lease->token);
        $journal->conclude('reserve', ['s2'], $lease->token);
        self::assertTaken(['s1'], $this->claim($journal, 'refund', ['s1', 's2']));
        self::assertTaken(['s2'], $this->claim($journal, 'ship', ['s1', 's2']));
    }

    public function testKOfNClaimsAndSkipsAtK(): void
    {
        $journal = $this->journal(Graphs::quorum());
        $this->work($journal, 'scan', ['s1', 's2', 's3']);
        foreach (['e1', 'e2'] as $engine) {
            $this->work($journal, $engine, ['s1', 's2']);
        }
        $this->work($journal, 'e1', ['s3']);
        self::assertTaken(['s1'], $this->claim($journal, 'merge', ['s1', 's3']));
        self::assertSame(1, $journal->skip('merge', $this->harness->candidates(['s2', 's3'])));
        self::assertSame(Status::Skipped, $journal->progress('s2')['merge']);
        self::assertTaken(['s3'], $this->claim($journal, 'e3', ['s1', 's2', 's3']), 's1 and s2 moved past e3; s3 has not');
    }

    public function testAChoiceOmitsTheOtherRouteInTheSameWrite(): void
    {
        $journal = $this->journal(Graphs::route());
        $lease = $this->claim($journal, 'classify', ['s1', 's2']);
        self::assertSame(1, $journal->conclude('classify', ['s1'], $lease->token, branch: 'publish'));
        self::assertSame(1, $journal->conclude('classify', ['s2'], $lease->token, branch: 'reject'));
        self::assertProgress(['classify' => Status::Done, 'reject' => Status::Omitted, 'notify' => Status::Omitted], $journal, 's1');
        self::assertProgress(['classify' => Status::Done, 'publish' => Status::Omitted], $journal, 's2');
        self::assertTaken(['s2'], $this->claim($journal, 'reject', ['s1', 's2']));
        $this->work($journal, 'publish', ['s1']);
        self::assertTaken(['s1'], $this->claim($journal, 'end', ['s1', 's2']));
        $stages = $journal->stages(['s1'], Clock::T0)['s1'];
        self::assertSame('classify', $stages[0][0]);
        foreach ($stages as $line) {
            self::assertNotSame(Status::Omitted->value, $line[3]);
        }
    }

    public function testAChoiceMustNameABranchAndOnlyAChoiceMay(): void
    {
        $journal = $this->journal(Graphs::route());
        $lease = $this->claim($journal, 'classify', ['s1']);
        self::assertThrows(fn() => $journal->conclude('classify', ['s1'], $lease->token), \InvalidArgumentException::class, 'branch:');
        self::assertThrows(fn() => $journal->conclude('classify', ['s1'], $lease->token, branch: 'end'), DagError::class, 'not a branch');
        self::assertThrows(fn() => $journal->fail('classify', ['s1'], $lease->token, 'publish'), \InvalidArgumentException::class, 'failed');
        self::assertSame(1, $journal->fail('classify', ['s1'], $lease->token));
        self::assertProgress(['classify' => Status::Failed], $journal, 's1', 'a failure omits nothing');
        self::assertThrows(fn() => $journal->conclude('publish', ['s1'], null, branch: 'end'), \InvalidArgumentException::class, 'not a choice');
    }

    public function testAChoiceRefusedByItsTokenOmitsNothing(): void
    {
        $journal = $this->journal(Graphs::route());
        $this->claim($journal, 'classify', ['s1']);
        self::assertSame(0, $journal->conclude('classify', ['s1'], 'stale', branch: 'publish'));
        self::assertProgress(['classify' => Status::Running], $journal, 's1');
    }

    // -- history and loops -----------------------------------------------

    public function testForgetAndReleaseArchiveWhatTheyTakeAway(): void
    {
        $journal = $this->journal();
        $lease = $this->claim($journal, 'start', ['s1', 's2']);
        $journal->conclude('start', ['s1'], $lease->token);
        $this->clock->now = '2026-01-01T01:00:00+00:00';
        self::assertSame(1, $journal->release('start', '2026-01-01T00:30:00+00:00'));
        self::assertSame(1, $journal->forget('start', ['s1']));
        self::assertSame([[
            'node' => 'start', 'status' => 'done', 'started_at' => Clock::T0,
            'finished_at' => Clock::T0, 'lease' => $lease->token,
            'archived_at' => '2026-01-01T01:00:00+00:00', 'reason' => 'forget',
        ]], $journal->history('s1'));
        $released = $journal->history('s2');
        self::assertCount(1, $released);
        self::assertSame(['running', 'release'], [$released[0]['status'], $released[0]['reason']]);
        self::assertSame([], $journal->history('ghost'));
    }

    public function testALoopGoesBackUntilItsBoundThenTheFailureStands(): void
    {
        $journal = $this->journal(Graphs::review());
        for ($round = 0; $round < 3; ++$round) {
            $this->clock->now = \sprintf('2026-01-01T00:0%d:00+00:00', $round);
            $this->work($journal, 'draft', ['s1']);
            $lease = $this->claim($journal, 'review', ['s1']);
            self::assertSame(1, $journal->fail('review', ['s1'], $lease->token));
            if ($round < 2) {
                self::assertProgress([], $journal, 's1', 'sent back to draft');
                self::assertSame($round + 1, $journal->passes('s1', 'draft'));
            }
        }
        self::assertProgress(['draft' => Status::Done, 'review' => Status::Failed], $journal, 's1');
        self::assertTaken(['s1'], $this->claim($journal, 'escalate', ['s1']));
        $rows = array_map(static fn(array $r): array => [$r['node'], $r['status'], $r['reason']], $journal->history('s1'));
        self::assertSame([['draft', 'done', 'loop'], ['review', 'failed', 'loop'], ['draft', 'done', 'loop'], ['review', 'failed', 'loop']], $rows);
    }

    public function testALoopDoesNotFireOnOtherStatuses(): void
    {
        $journal = $this->journal(Graphs::review());
        $this->work($journal, 'draft', ['s1']);
        $this->work($journal, 'review', ['s1']);
        self::assertProgress(['draft' => Status::Done, 'review' => Status::Done], $journal, 's1');
        self::assertSame([], $journal->history('s1'));
    }

    public function testALoopRefusedByItsTokenSendsNobodyBack(): void
    {
        $journal = $this->journal(Graphs::review());
        $this->work($journal, 'draft', ['s1']);
        $this->claim($journal, 'review', ['s1']);
        self::assertSame(0, $journal->fail('review', ['s1'], 'stale'));
        self::assertProgress(['draft' => Status::Done, 'review' => Status::Running], $journal, 's1');
        self::assertSame([], $journal->history('s1'));
    }

    // -- retries ---------------------------------------------------------

    public function testAFailureIsRetriedWhenDueThenStands(): void
    {
        $journal = $this->journal(Graphs::flaky());
        $this->work($journal, 'prepare', ['s1']);
        $lease = $this->claim($journal, 'call', ['s1']);
        self::assertSame(1, $journal->fail('call', ['s1'], $lease->token));
        self::assertProgress(['prepare' => Status::Done, 'call' => Status::Scheduled], $journal, 's1');
        self::assertSame(1, $journal->counts('call')['scheduled']);
        self::assertTaken([], $this->claim($journal, 'call', ['s1']), 'not due yet');
        self::assertTaken([], $this->claim($journal, 'alert', ['s1']), 'a retry is not a failure');

        $this->clock->now = '2026-01-01T00:00:10+00:00';
        $lease = $this->claim($journal, 'call', ['s1']);
        self::assertTaken(['s1'], $lease, 'due after 10s');
        $journal->fail('call', ['s1'], $lease->token);
        $this->clock->now = '2026-01-01T00:00:29+00:00';
        self::assertTaken([], $this->claim($journal, 'call', ['s1']), 'second wait is 20s');
        $this->clock->now = '2026-01-01T00:00:30+00:00';
        $lease = $this->claim($journal, 'call', ['s1']);
        $journal->fail('call', ['s1'], $lease->token);

        self::assertProgress(['prepare' => Status::Done, 'call' => Status::Failed], $journal, 's1');
        self::assertSame(2, $journal->retries('s1', 'call'));
        self::assertSame(['retry', 'retry'], self::reasons($journal, 's1'));
        self::assertTaken(['s1'], $this->claim($journal, 'alert', ['s1']));
    }

    public function testExpireReleasesWhatEachNodeAllowsNoLonger(): void
    {
        $journal = $this->journal(Graphs::flaky());
        $this->claim($journal, 'prepare', ['old']);
        $this->clock->now = '2026-01-01T00:59:00+00:00';
        $this->claim($journal, 'prepare', ['young']);
        self::assertSame([], $journal->expire(), 'nothing held an hour yet');
        $this->clock->now = '2026-01-01T01:00:01+00:00';
        self::assertSame(['prepare' => 1], $journal->expire());
        self::assertProgress([], $journal, 'old');
        self::assertProgress(['prepare' => Status::Running], $journal, 'young');
        self::assertSame(['release'], self::reasons($journal, 'old'));
    }

    public function testAScheduledRowClosesWhatItGuards(): void
    {
        $journal = $this->journal(Graphs::flaky());
        $this->work($journal, 'prepare', ['s1']);
        $lease = $this->claim($journal, 'call', ['s1']);
        $journal->fail('call', ['s1'], $lease->token);
        $this->clock->now = '2026-01-01T01:00:00+00:00';
        self::assertSame(0, $journal->conclude('call', ['s1'], null), 'a scheduled row is not held');
        self::assertTaken([], $this->claim($journal, 'prepare', ['s1']));
        self::assertSame(1, $journal->forget('call', ['s1']));
        self::assertSame(['failed', 'scheduled'], array_map(static fn(array $r): string => $r['status'], $journal->history('s1')));
    }

    public function testARetryRefusedByItsTokenSchedulesNothing(): void
    {
        $journal = $this->journal(Graphs::flaky());
        $this->work($journal, 'prepare', ['s1']);
        $this->claim($journal, 'call', ['s1']);
        self::assertSame(0, $journal->fail('call', ['s1'], 'stale'));
        self::assertSame(Status::Running, $journal->progress('s1')['call']);
        self::assertSame([], $journal->history('s1'));
    }

    // -- waits, signals, grace -------------------------------------------

    public function testASignalReceivedBeforeTheWaitStillSettlesIt(): void
    {
        $journal = $this->journal(Graphs::onboarding());
        self::assertSame(1, $journal->signal(['early'], 'email.clicked', 'click-1'));
        $this->work($journal, 'send', ['early', 'late']);
        self::assertThrows(fn() => $this->claim($journal, 'clicked', ['early']), \InvalidArgumentException::class, 'settled');
        self::assertSame(['clicked' => ['done' => 1]], $journal->settle($this->harness->candidates(['early', 'late'])));
        self::assertSame(Status::Done, $journal->progress('early')['clicked']);
        self::assertArrayNotHasKey('clicked', $journal->progress('late'));
        self::assertTaken(['early'], $this->claim($journal, 'activate', ['early', 'late']));
        $history = $journal->history('early');
        self::assertCount(1, $history);
        self::assertSame(['email.clicked', 'signal', 'click-1'], [$history[0]['node'], $history[0]['reason'], $history[0]['lease']]);
    }

    public function testAWaitFailsAtItsTimeoutAndAFailureEdgeTakesOver(): void
    {
        $journal = $this->journal(Graphs::onboarding());
        $this->work($journal, 'send', ['s1']);
        $this->clock->now = '2026-01-07T23:59:59+00:00';
        self::assertArrayNotHasKey('clicked', $journal->settle($this->harness->candidates(['s1'])), 'one second early');
        $this->clock->now = '2026-01-08T00:00:00+00:00';
        self::assertSame(['failed' => 1], $journal->settle($this->harness->candidates(['s1']))['clicked']);
        self::assertTaken(['s1'], $this->claim($journal, 'remind', ['s1']));
        $journal->signal(['s1'], 'email.clicked');
        self::assertArrayNotHasKey('clicked', $journal->settle($this->harness->candidates(['s1'])), 'too late: the wait has concluded');
    }

    public function testGraceSkipsAnOptionalNodeLeftUntaken(): void
    {
        $journal = $this->journal(Graphs::onboarding());
        $this->work($journal, 'send', ['idle', 'busy']);
        $this->claim($journal, 'survey', ['busy']);
        $this->clock->now = '2026-01-02T00:00:00+00:00';
        self::assertSame(['skipped' => 1], $journal->settle($this->harness->candidates(['idle', 'busy']))['survey']);
        self::assertSame(Status::Skipped, $journal->progress('idle')['survey']);
        self::assertSame(Status::Running, $journal->progress('busy')['survey']);
    }

    public function testASignalCountsAgainOnlyAfterTheWaitWentBack(): void
    {
        $journal = $this->journal(Graphs::onboarding());
        $this->work($journal, 'send', ['s1']);
        $journal->signal(['s1'], 'email.clicked');
        $this->clock->now = '2026-01-01T00:01:00+00:00';
        $journal->settle($this->harness->candidates(['s1']));
        $this->clock->now = '2026-01-01T00:02:00+00:00';
        $journal->forget('clicked', ['s1']);
        self::assertArrayNotHasKey('clicked', $journal->settle($this->harness->candidates(['s1'])), 'the old click was spent before the node went back');
        $this->clock->now = '2026-01-01T00:03:00+00:00';
        $journal->signal(['s1'], 'email.clicked');
        self::assertSame(['done' => 1], $journal->settle($this->harness->candidates(['s1']))['clicked']);
    }

    // -- skip ------------------------------------------------------------

    public function testOnlyAnOptionalNodeIsSkipped(): void
    {
        $journal = $this->journal();
        $this->expectException(\InvalidArgumentException::class);
        $journal->skip('left', $this->harness->candidates(['s1']));
    }

    public function testASkipNeedsConcludedParentsAndSatisfiesChildren(): void
    {
        $journal = $this->journal();
        $this->harness->seed($journal, 's1', ['start' => Status::Running]);
        $this->harness->seed($journal, 's2', ['start' => Status::Done, 'left' => Status::Done]);
        self::assertSame(1, $journal->skip('right', $this->harness->candidates(['s1', 's2'])));
        self::assertSame(Status::Skipped, $journal->progress('s2')['right']);
        self::assertArrayNotHasKey('right', $journal->progress('s1'));
        self::assertTaken(['s2'], $this->claim($journal, 'end', ['s2']));
    }

    public function testBoundedSkipsEndWhereOneSkipEnds(): void
    {
        $subjects = array_map(static fn(int $i): string => "s{$i}", range(0, 6));
        $build = function () use ($subjects): NodeJournal {
            $journal = $this->journal();
            foreach ($subjects as $i => $subject) {
                $this->harness->seed($journal, $subject, ['start' => 0 === $i % 3 ? Status::Running : Status::Done]);
            }

            return $journal;
        };
        $whole = $build();
        self::assertSame(4, $whole->skip('right', $this->harness->candidates($subjects)));
        $passes = $build();
        self::assertSame(2, $passes->skip('right', $this->harness->candidates($subjects), 2));
        self::assertSame(['s1', 's2'], array_values(array_filter($subjects, static fn(string $s): bool => isset($passes->progress($s)['right']))), 'a pass takes the first candidates the rule allows, in their order');
        self::assertSame([2, 0], self::bounded(fn(): int => $passes->skip('right', $this->harness->candidates($subjects), 2), 2));
        foreach ($subjects as $subject) {
            self::assertEquals($whole->progress($subject), $passes->progress($subject));
        }
    }

    public function testBoundedSettlesEndWhereOneSettleEnds(): void
    {
        $subjects = array_map(static fn(int $i): string => "s{$i}", range(0, 4));
        $build = function () use ($subjects): NodeJournal {
            $this->clock->now = Clock::T0;
            $journal = $this->journal(Graphs::onboarding());
            $this->work($journal, 'send', $subjects);
            $this->clock->now = '2026-01-02T00:00:00+00:00';     // past the survey's grace

            return $journal;
        };
        $whole = $build();
        self::assertSame(['survey' => ['skipped' => 5]], $whole->settle($this->harness->candidates($subjects)));
        $passes = $build();
        $counted = fn(): int => array_sum(array_map('array_sum', $passes->settle($this->harness->candidates($subjects), 2)));
        self::assertSame([2, 2, 1, 0], self::bounded($counted, 2));
        foreach ($subjects as $subject) {
            self::assertEquals($whole->progress($subject), $passes->progress($subject));
        }
    }

    public function testASkipNeverOverwrites(): void
    {
        $journal = $this->journal();
        $this->harness->seed($journal, 's1', ['start' => Status::Done, 'right' => Status::Failed]);
        self::assertSame(0, $journal->skip('right', $this->harness->candidates(['s1'])));
        self::assertSame(Status::Failed, $journal->progress('s1')['right']);
    }

    // -- monitoring ------------------------------------------------------

    /** @return array<string, array<string, Status>> */
    private static function snapshotProgress(): array
    {
        return [
            'fresh' => [],
            'started' => ['start' => Status::Running],
            'forked' => ['start' => Status::Done],
            'half' => ['start' => Status::Done, 'left' => Status::Done],
            'busy' => ['start' => Status::Done, 'left' => Status::Running],
            'retry' => ['start' => Status::Done, 'right' => Status::Scheduled],
            'moved' => ['start' => Status::Done, 'end' => Status::Done],
        ];
    }

    public function testASnapshotCountsReadyWhatAClaimTakes(): void
    {
        $journal = $this->journal();
        foreach (self::snapshotProgress() as $subject => $progress) {
            $this->harness->seed($journal, $subject, $progress);
        }
        $subjects = array_keys(self::snapshotProgress());
        $snapshot = $journal->snapshot($this->harness->candidates($subjects));
        foreach (['start', 'left', 'right', 'end'] as $name) {
            $probe = $this->journal();
            foreach (self::snapshotProgress() as $subject => $progress) {
                $this->harness->seed($probe, $subject, $progress);
            }
            self::assertSame(\count($this->claim($probe, $name, $subjects, 100)), $snapshot[$name]['ready'], "ready of {$name} is what a claim takes");
        }
    }

    public function testASnapshotAgesWhatStandsAtEachNode(): void
    {
        $journal = $this->journal();
        foreach (self::snapshotProgress() as $subject => $progress) {
            $this->harness->seed($journal, $subject, $progress);
        }
        $this->clock->now = self::at(10);
        $snapshot = $journal->snapshot();
        self::assertSame(1, $snapshot['start']['running']);
        self::assertSame(600.0, $snapshot['start']['oldest_running']);
        self::assertSame(-600.0, $snapshot['right']['next_due'], 'the retry is overdue');
        self::assertNull($snapshot['end']['oldest_running'], 'nothing stands there');
    }

    // -- read ------------------------------------------------------------

    public function testAdoptRecordsDoneAndNeverOverwrites(): void
    {
        $journal = $this->journal();
        $this->harness->seed($journal, 's2', ['start' => Status::Failed]);
        self::assertSame(1, $journal->adopt('start', ['s1', 's1', 's2']));
        self::assertProgress(['start' => Status::Done], $journal, 's1');
        self::assertProgress(['start' => Status::Failed], $journal, 's2');
        self::assertSame(0, $journal->adopt('start', []));
        $this->expectException(DagError::class);
        $journal->adopt('nope', []);
    }

    public function testForgetMakesTheNodeClaimableAgain(): void
    {
        $journal = $this->journal();
        $this->claim($journal, 'start', ['s1']);
        self::assertSame(1, $journal->forget('start', ['s1', 's2']));
        self::assertProgress([], $journal, 's1');
        self::assertTaken(['s1'], $this->claim($journal, 'start', ['s1']));
        self::assertSame(0, $journal->forget('start', []));
    }

    public function testReleaseDropsOnlyOldRunningLeases(): void
    {
        $journal = $this->journal();
        $lease = $this->claim($journal, 'start', ['old', 'done']);
        $journal->conclude('start', ['done'], $lease->token);
        $this->clock->now = '2026-01-01T01:00:00+00:00';
        $this->claim($journal, 'start', ['young']);
        self::assertSame(1, $journal->release('start', '2026-01-01T00:30:00+00:00'));
        self::assertProgress([], $journal, 'old');
        self::assertProgress(['start' => Status::Done], $journal, 'done');
        self::assertProgress(['start' => Status::Running], $journal, 'young');
    }

    public function testCountsCoverEveryStatus(): void
    {
        $journal = $this->journal();
        $this->harness->seed($journal, 'a', ['start' => Status::Done]);
        $this->harness->seed($journal, 'b', ['start' => Status::Done]);
        $this->harness->seed($journal, 'c', ['start' => Status::Failed]);
        $this->harness->seed($journal, 'd', ['start' => Status::Omitted]);
        self::assertSame(['running' => 0, 'scheduled' => 0, 'done' => 2, 'skipped' => 0, 'failed' => 1, 'omitted' => 1], $journal->counts('start'));
    }

    public function testStagesMeasureWhatWorkedInOrder(): void
    {
        $journal = $this->journal();
        $lease = $this->claim($journal, 'start', ['s1']);
        $this->clock->now = '2026-01-01T00:00:10+00:00';
        $journal->conclude('start', ['s1'], $lease->token);
        $journal->skip('right', $this->harness->candidates(['s1']));
        $this->claim($journal, 'left', ['s1']);
        $stages = $journal->stages(['s1', 'ghost'], '2026-01-01T00:00:15+00:00');
        self::assertSame(['s1'], array_keys($stages));
        self::assertSame([
            ['start', '2026-01-01T00:00:10+00:00', 10.0, 'done'],
            ['left', '2026-01-01T00:00:15+00:00', 5.0, 'running'],
        ], $stages['s1']);
    }

    public function testParentsConcludedReadsLikeTheRule(): void
    {
        $journal = $this->journal();
        $this->harness->seed($journal, 's1', ['left' => Status::Done, 'right' => Status::Skipped]);
        $this->harness->seed($journal, 's2', ['left' => Status::Done, 'right' => Status::Failed]);
        self::assertTrue($this->harness->parentsConcluded($journal, 'end', 's1'));
        self::assertFalse($this->harness->parentsConcluded($journal, 'end', 's2'));
        self::assertTrue($this->harness->parentsConcluded($journal, 'start', 's3'), 'no parent, nothing required');
    }

    public function testNodeForStateNamesTheWorkingNode(): void
    {
        $journal = $this->journal();
        self::assertSame('left', $journal->nodeForState('lefting'));
        self::assertNull($journal->nodeForState('lefted'));
    }

    public function testIntegerIdsComeBackAsIntegers(): void
    {
        $journal = $this->journal(Graphs::flaky(), 'int');
        $big = 2 ** 40;
        $lease = $this->claim($journal, 'prepare', [3, $big, 1]);
        self::assertTaken([1, 3, $big], $lease);
        foreach ($lease as $subject) {
            self::assertIsInt($subject);
        }
        self::assertSame(2, $journal->conclude('prepare', [3, $big], $lease->token));
        self::assertProgress(['prepare' => Status::Done], $journal, $big);
        $lease = $this->claim($journal, 'call', [$big]);
        self::assertSame([$big], $lease->subjects);
        $journal->fail('call', [$big], $lease->token);
        self::assertSame(1, $journal->retries($big, 'call'));
        self::assertSame(1, $journal->forget('prepare', [$big]));
        self::assertSame(['call', 'prepare'], array_map(static fn(array $r): string => $r['node'], $journal->history($big)));
    }

    // -- lanes -----------------------------------------------------------

    /** @param list<int|string> $subjects */
    private function letIn(NodeJournal $journal, array $subjects): int
    {
        return $journal->settle($this->harness->candidates($subjects))['arrive'][Outcome::Entered->value] ?? 0;
    }

    /**
     * One whole pass after the lane: scrape, then publish.
     *
     * @param list<int|string> $subjects
     */
    private function through(NodeJournal $journal, array $subjects): void
    {
        $this->work($journal, 'scrape', $subjects);
        $this->work($journal, 'publish', $subjects);
    }

    /** @return list<?string> the refs the history notes with this status */
    private static function noted(NodeJournal $journal, int|string $subject, Outcome $status): array
    {
        return array_values(array_map(
            static fn(array $row): ?string => $row['lease'],
            array_filter($journal->history($subject), static fn(array $row): bool => $status->value === $row['status']),
        ));
    }

    public function testAnArrivalWaitsInItsLaneUntilSettled(): void
    {
        $journal = $this->journal(Graphs::listing());
        self::assertSame(['queued' => 1, 'merged' => 0, 'skipped' => 0], $journal->arrive('arrive', ['s1'], 'v1'));
        self::assertSame([], $journal->progress('s1'));
        self::assertTaken([], $this->claim($journal, 'scrape', ['s1']));
        self::assertSame(1, $journal->counts('arrive')['waiting']);
        self::assertSame('v1', $journal->arrival('s1', 'arrive')?->ref);
        self::assertSame(1, $this->letIn($journal, ['s1']));
        self::assertProgress(['arrive' => Status::Done], $journal, 's1');
        self::assertNull($journal->arrival('s1', 'arrive'));
        self::assertSame(0, $journal->counts('arrive')['waiting']);
        self::assertTaken(['s1'], $this->claim($journal, 'scrape', ['s1']));
        $history = $journal->history('s1');
        self::assertCount(1, $history);
        self::assertSame(['arrive', 'entered', 'v1', 'lane'], [$history[0]['node'], $history[0]['status'], $history[0]['lease'], $history[0]['reason']]);
    }

    public function testALaneIsNeverClaimedAndOnlyALaneTakesArrivals(): void
    {
        $journal = $this->journal(Graphs::listing());
        self::assertThrows(fn() => $this->claim($journal, 'arrive', ['s1']), \InvalidArgumentException::class, 'lane');
        self::assertThrows(static fn() => $journal->arrive('scrape', ['s1']), \InvalidArgumentException::class, 'not a lane');
    }

    public function testThrottleKeepsTheLastVersionAtThePlaceOfTheFirst(): void
    {
        $journal = $this->journal(Graphs::listing());
        $journal->arrive('arrive', ['s1'], 'v1');
        $this->clock->now = self::at(5);
        self::assertSame(1, $journal->arrive('arrive', ['s1'], 'v2')['merged']);
        $waiting = $journal->arrival('s1', 'arrive');
        self::assertNotNull($waiting);
        self::assertSame(['v2', self::at(0), self::at(0)], [$waiting->ref, $waiting->place, $waiting->arrivedAt]);
        self::assertSame(['v2'], self::noted($journal, 's1', Outcome::Merged));
    }

    public function testDedupeKeepsTheFirstVersion(): void
    {
        $journal = $this->journal(Graphs::listing(new Lane(Merge::First)));
        $journal->arrive('arrive', ['s1'], 'v1');
        $this->clock->now = self::at(5);
        $journal->arrive('arrive', ['s1'], 'v2');
        self::assertSame('v1', $journal->arrival('s1', 'arrive')?->ref);
    }

    public function testBatchKeepsEveryVersionInTheOrderTheyCame(): void
    {
        $journal = $this->journal(Graphs::listing(new Lane(Merge::All)));
        foreach (['v1', 'v2', 'v3'] as $minute => $ref) {
            $this->clock->now = self::at($minute);
            $journal->arrive('arrive', ['s1'], $ref);
        }
        self::assertSame(['v1', 'v2', 'v3'], $journal->refs('s1', 'arrive'));
        self::assertSame('v3', $journal->arrival('s1', 'arrive')?->ref, 'the latest is still named');
    }

    public function testABatchPastItsSizeLetsTheOldestGoAndSaysSo(): void
    {
        $journal = $this->journal(Graphs::listing(new Lane(Merge::All, maxSize: 2)));
        foreach (['v1', 'v2', 'v3'] as $minute => $ref) {
            $this->clock->now = self::at($minute);
            $journal->arrive('arrive', ['s1'], $ref);
        }
        self::assertSame(['v2', 'v3'], $journal->refs('s1', 'arrive'), 'the oldest was let go');
        self::assertSame(['v1'], self::noted($journal, 's1', Outcome::Dropped), 'and it left a trace');
    }

    /** @var list<array{0: list<string>, 1: ?string}> what a merge function was asked */
    private array $asked = [];

    /**
     * Keep the first and the latest version, nothing in between.
     *
     * @param list<string> $kept
     *
     * @return list<string>
     */
    private function keepTheEnds(array $kept, ?string $arriving): array
    {
        $this->asked[] = [$kept, $arriving];
        $whole = [...$kept, (string) $arriving];

        return \count($whole) > 2 ? [$whole[0], $whole[\count($whole) - 1]] : $whole;
    }

    public function testANamedFunctionDecidesWhatTheLaneKeeps(): void
    {
        // The graph holds the NAME; the journal holds the function.
        $journal = $this->journal(Graphs::listing(new Lane(Merge::fn('ends'))), mergers: ['ends' => $this->keepTheEnds(...)]);
        foreach (['v1', 'v2', 'v3', 'v4'] as $minute => $ref) {
            $this->clock->now = self::at($minute);
            $journal->arrive('arrive', ['s1'], $ref);
        }
        self::assertSame(['v1', 'v4'], $journal->refs('s1', 'arrive'));
        self::assertSame([[], 'v1'], $this->asked[0], 'asked with what waits and what arrives');
        self::assertSame([['v1', 'v3'], 'v4'], $this->asked[\count($this->asked) - 1]);
    }

    public function testALaneNamingAFunctionTheJournalLacksSaysWhich(): void
    {
        $journal = $this->journal(Graphs::listing(new Lane(Merge::fn('nowhere'))));
        self::assertThrows(static fn() => $journal->arrive('arrive', ['s1'], 'v1'), \InvalidArgumentException::class, 'nowhere');
    }

    public function testDebounceWaitsForQuietAndMaxWaitEndsIt(): void
    {
        $journal = $this->journal(Graphs::listing(new Lane(position: Position::Last, delay: '10m', maxWait: '25m')));
        foreach ([0, 5, 10] as $minute) {
            $this->clock->now = self::at($minute);
            $journal->arrive('arrive', ['s1'], "v{$minute}");
        }
        self::assertSame(self::at(10), $journal->arrival('s1', 'arrive')?->place);
        $this->clock->now = self::at(19);
        self::assertSame(0, $this->letIn($journal, ['s1']), 'quiet since 10, not 10 minutes yet');
        $this->clock->now = self::at(15);
        $journal->arrive('arrive', ['s1'], 'v15');
        $this->clock->now = self::at(24);
        self::assertSame(0, $this->letIn($journal, ['s1']));
        $this->clock->now = self::at(25);
        self::assertSame(1, $this->letIn($journal, ['s1']), 'maxWait from the first arrival');
        $history = $journal->history('s1');
        self::assertSame('v15', $history[\count($history) - 1]['lease']);
    }

    public function testCooldownRunsFromTheEndOfThePreviousPass(): void
    {
        $journal = $this->journal(Graphs::listing());
        $journal->arrive('arrive', ['s1'], 'v1');
        self::assertSame(1, $this->letIn($journal, ['s1']), 'no previous pass, no cooldown');
        $this->clock->now = self::at(30);
        $this->through($journal, ['s1']);
        $this->clock->now = self::at(40);
        $journal->arrive('arrive', ['s1'], 'v2');
        $this->clock->now = self::at(89);
        self::assertSame(0, $this->letIn($journal, ['s1']), 'the pass ended at 30');
        self::assertProgress(['arrive' => Status::Done, 'scrape' => Status::Done, 'publish' => Status::Done], $journal, 's1', 'the previous pass stays visible while the next version waits');
        $this->clock->now = self::at(90);
        self::assertSame(1, $this->letIn($journal, ['s1']));
        self::assertProgress(['arrive' => Status::Done], $journal, 's1');
        $archived = array_map(
            static fn(array $row): string => $row['node'] . ':' . $row['status'],
            array_values(array_filter($journal->history('s1'), static fn(array $row): bool => Reason::Arrival->value === $row['reason'])),
        );
        sort($archived);
        self::assertSame(['arrive:done', 'publish:done', 'scrape:done'], $archived);
        self::assertTaken(['s1'], $this->claim($journal, 'scrape', ['s1']));
    }

    public function testAnArrivalDuringARunningPassWaitsForIt(): void
    {
        $journal = $this->journal(Graphs::listing(new Lane()));
        $journal->arrive('arrive', ['s1'], 'v1');
        $this->letIn($journal, ['s1']);
        $lease = $this->claim($journal, 'scrape', ['s1']);
        $journal->arrive('arrive', ['s1'], 'v2');
        self::assertSame(0, $this->letIn($journal, ['s1']), 'scrape is running');
        $journal->conclude('scrape', $lease, $lease->token);
        self::assertSame(1, $this->letIn($journal, ['s1']));
        self::assertProgress(['arrive' => Status::Done], $journal, 's1');
    }

    public function testTheDriverNeverLetsAnArrivalInOverARunningPass(): void
    {
        // What the journal checks before, the driver holds on its own: a
        // claim may land between the journal's read and the write.
        $journal = $this->journal(Graphs::listing(new Lane()));
        $driver = $journal->driver;
        self::assertInstanceOf(LaneDriver::class, $driver);
        $journal->arrive('arrive', ['s1'], 'v1');
        $this->letIn($journal, ['s1']);
        $this->claim($journal, 'scrape', ['s1']);
        $journal->arrive('arrive', ['s1'], 'v2');
        $entries = [];
        foreach ($driver->scan($this->harness->candidates(['s1']), null, ['arrive', 'scrape', 'publish'], [], 10, self::at(0)) as $page) {
            array_push($entries, ...$page);
        }
        self::assertCount(1, $entries);
        $revision = $entries[0]->revision;
        self::assertSame([], $driver->enter('arrive', [['s1', $revision]], ['arrive', 'scrape', 'publish'], self::at(0)));
        self::assertSame([], $driver->enter('arrive', [['s1', $revision + 1]], ['arrive'], self::at(0)), 'stale revision');
        self::assertProgress(['arrive' => Status::Done, 'scrape' => Status::Running], $journal, 's1');
        self::assertSame([], $driver->enter('scrape', [['s1', $revision]], ['scrape'], self::at(0)), 'nothing waits there');
    }

    public function testSkipDropsAnArrivalDuringARunningPass(): void
    {
        $journal = $this->journal(Graphs::listing(new Lane(whileRunning: WhileRunning::Skip)));
        $journal->arrive('arrive', ['s1'], 'v1');
        $this->letIn($journal, ['s1']);
        $this->claim($journal, 'scrape', ['s1']);
        self::assertSame(['queued' => 0, 'merged' => 0, 'skipped' => 1], $journal->arrive('arrive', ['s1'], 'v2'));
        self::assertNull($journal->arrival('s1', 'arrive'));
        self::assertSame(['v2'], self::noted($journal, 's1', Outcome::Skipped));
    }

    public function testAnUrgentArrivalSkipsTheCooldownButNotARunningPass(): void
    {
        $journal = $this->journal(Graphs::listing());
        $journal->arrive('arrive', ['s1'], 'v1');
        $this->letIn($journal, ['s1']);
        $lease = $this->claim($journal, 'scrape', ['s1']);
        $journal->arrive('arrive', ['s1'], 'v2');
        $journal->arrive('arrive', ['s1'], 'v3', urgent: true);
        self::assertTrue($journal->arrival('s1', 'arrive')?->urgent);
        self::assertSame(0, $this->letIn($journal, ['s1']));
        $journal->conclude('scrape', $lease, $lease->token);
        $this->clock->now = self::at(1);
        self::assertSame(1, $this->letIn($journal, ['s1']), 'urgent: no hour of cooldown');
    }

    public function testALaneAfterOtherNodesWaitsForItsParents(): void
    {
        $journal = $this->journal(new Dag(
            new Node('fetch'),
            new Node('tag', parents: ['fetch'], lane: new Lane()),
            new Node('index', parents: ['tag']),
        ));
        $journal->arrive('tag', ['s1']);
        self::assertSame([], $journal->settle($this->harness->candidates(['s1'])));
        $this->work($journal, 'fetch', ['s1']);
        self::assertSame(['tag' => ['entered' => 1]], $journal->settle($this->harness->candidates(['s1'])));
        self::assertProgress(['fetch' => Status::Done, 'tag' => Status::Done], $journal, 's1');
    }

    public function testBoundedSettlesLetALaneInByParts(): void
    {
        $subjects = array_map(static fn(int $i): string => "s{$i}", range(0, 4));
        $build = function () use ($subjects): NodeJournal {
            $journal = $this->journal(Graphs::listing(new Lane()));
            $journal->arrive('arrive', $subjects);

            return $journal;
        };
        $whole = $build();
        self::assertSame(5, $this->letIn($whole, $subjects));
        $passes = $build();
        self::assertSame([2, 2, 1, 0], self::bounded(fn(): int => $passes->settle($this->harness->candidates($subjects), 2)['arrive'][Outcome::Entered->value] ?? 0, 2));
        foreach ($subjects as $subject) {
            self::assertEquals($whole->progress($subject), $passes->progress($subject));
        }
        self::assertSame(0, $passes->counts('arrive')['waiting']);
    }

    public function testASnapshotAgesALanesOldestArrival(): void
    {
        $journal = $this->journal(Graphs::listing());
        $journal->arrive('arrive', ['s1', 's2']);
        $this->clock->now = self::at(5);
        $snapshot = $journal->snapshot($this->harness->candidates(['s1', 's2']));
        self::assertSame(2, $snapshot['arrive']['waiting']);
        self::assertEquals(300, $snapshot['arrive']['oldest_waiting']);
        self::assertArrayNotHasKey('ready', $snapshot['arrive'], 'a lane is settled, never claimed');
    }

    public function testIntegerSubjectsWaitInALaneAsIntegers(): void
    {
        $journal = $this->journal(Graphs::listing(new Lane(Merge::All)), 'int');
        $big = 2 ** 40;
        $journal->arrive('arrive', [$big, 7], 'v1');
        $journal->arrive('arrive', [$big], 'v2');
        self::assertSame(['v1', 'v2'], $journal->refs($big, 'arrive'));
        self::assertSame(2, $this->letIn($journal, [$big, 7]));
        self::assertTaken([7, $big], $this->claim($journal, 'scrape', [$big, 7]));
    }

    // -- policies --------------------------------------------------------

    /** Onboarding and a flaky call in one graph, where `slow` waits longer, retries more and holds longer. */
    private static function policied(): Graph
    {
        return new Graph(new Document('policies'), new Dag(
            new Node('send'),
            new Node('clicked', parents: ['send'], wait: 'email.clicked', timeout: '7d'),
            new Node('call', parents: ['send'], retry: new Retry(limit: 1, delay: '10s'), lease: '1h'),
            new Node('survey', parents: ['send'], optional: true, grace: '1d'),
        ), [
            'slow' => [
                'clicked' => ['timeout' => '30d'],
                'call' => ['retry' => new Retry(limit: 3, delay: '1m'), 'lease' => '5h'],
                'survey' => ['grace' => '10d'],
            ],
        ]);
    }

    public function testASubjectCarriesItsPolicy(): void
    {
        $journal = $this->journal(self::policied());
        self::assertSame(2, $journal->enroll(['a', 'b'], 'slow'));
        self::assertSame('slow', $journal->policy('a'));
        self::assertNull($journal->policy('c'));
        $journal->enroll(['b'], null);
        self::assertNull($journal->policy('b'));
        self::assertSame('5h', $journal->settings('call', 'slow')->lease);
        self::assertSame('1h', $journal->settings('call', null)->lease);
        self::assertSame('1h', $journal->settings('call', 'unknown')->lease, 'an unknown policy sees the defaults');
    }

    public function testAPolicyChangesRetriesLeasesTimeoutsAndGraces(): void
    {
        $journal = $this->journal(self::policied());
        $journal->enroll(['slow'], 'slow');
        $this->work($journal, 'send', ['slow', 'fast']);

        // retries: one for the default, three for `slow`
        for ($i = 0; $i < 2; ++$i) {
            $lease = $this->claim($journal, 'call', ['slow', 'fast']);
            $journal->fail('call', $lease, $lease->token);
            $this->clock->now = Time::shift($this->clock->now, 3600);
        }
        self::assertSame(Status::Failed, $journal->progress('fast')['call']);
        self::assertSame(Status::Scheduled, $journal->progress('slow')['call']);

        // leases: 1h by default, 5h for `slow`
        self::assertTaken(['slow'], $this->claim($journal, 'call', ['slow']));
        $this->clock->now = Time::shift($this->clock->now, 2 * 3600);
        self::assertSame([], $journal->expire(), 'two hours is within the `slow` lease');
        $this->clock->now = Time::shift($this->clock->now, 4 * 3600);
        self::assertSame(['call' => 1], $journal->expire());

        // timeouts and graces, measured from `send` concluding
        $this->clock->now = '2026-01-08T00:00:00+00:00';
        $settled = $journal->settle($this->harness->candidates(['slow', 'fast']));
        self::assertSame(['failed' => 1], $settled['clicked']);
        self::assertSame(['skipped' => 1], $settled['survey']);
        self::assertArrayNotHasKey('clicked', $journal->progress('slow'));
        self::assertArrayNotHasKey('survey', $journal->progress('slow'));
    }

    public function testASourceThatNeedsAnExtraStepSkipsItElsewhere(): void
    {
        // The node is optional for everyone, and the policy that does not want
        // it gives it a grace: `settle` skips it, and `skipped` satisfies what follows.
        $journal = $this->journal(new Graph(new Document('offers'), new Dag(
            new Node('scrape'),
            new Node('enrich', parents: ['scrape'], optional: true),
            new Node('publish', parents: ['enrich']),
        ), ['plain' => ['enrich' => ['grace' => '1s']]]));
        $journal->enroll(['plain1'], 'plain');
        $this->work($journal, 'scrape', ['rich1', 'plain1']);
        $this->clock->now = '2026-01-01T00:00:05+00:00';
        self::assertSame(['enrich' => ['skipped' => 1]], $journal->settle($this->harness->candidates(['rich1', 'plain1'])), 'only the policy with a grace');
        self::assertSame(Status::Skipped, $journal->progress('plain1')['enrich']);
        self::assertArrayNotHasKey('enrich', $journal->progress('rich1'), 'no grace: never skipped alone');
        self::assertTaken(['plain1'], $this->claim($journal, 'publish', ['plain1', 'rich1']));
        $this->work($journal, 'enrich', ['rich1']);
        self::assertTaken(['rich1'], $this->claim($journal, 'publish', ['rich1']));
    }

    public function testAPolicyTunesItsLane(): void
    {
        $journal = $this->journal(new Graph(new Document('listings'), Graphs::listing(), ['fast' => ['arrive' => ['lane' => Lane::throttle(cooldown: '5m')]]]));
        $journal->enroll(['slow1'], null);
        $journal->enroll(['fast1'], 'fast');
        $journal->arrive('arrive', ['slow1', 'fast1']);
        self::assertSame(2, $this->letIn($journal, ['slow1', 'fast1']));
        $this->through($journal, ['slow1', 'fast1']);
        $journal->arrive('arrive', ['slow1', 'fast1']);
        $this->clock->now = self::at(5);
        self::assertSame(1, $this->letIn($journal, ['slow1', 'fast1']));
        self::assertNotNull($journal->arrival('slow1', 'arrive'), 'the default lane still cools down');
    }

    public function testAPolicyKeepsItsOwnRate(): void
    {
        $journal = $this->journal(new Graph(
            new Document('api'),
            new Dag(new Node('call', rate: [new Rate(1, '1h')], per: Per::Policy)),
            ['bulk' => ['call' => ['rate' => [new Rate(3, '1h')]]]],
        ));
        $journal->enroll(['b1', 'b2', 'b3', 'b4'], 'bulk');
        self::assertTaken(['b1', 'b2', 'b3', 'x'], $this->claim($journal, 'call', ['b1', 'b2', 'b3', 'b4', 'x', 'y']), 'three an hour for bulk, one for the rest');
    }

    // -- rate and concurrency --------------------------------------------

    public function testConcurrencyCapsWhatRunsAtOnce(): void
    {
        $journal = $this->journal(new Dag(new Node('gpu', concurrency: 2)));
        $first = $this->claim($journal, 'gpu', ['a', 'b', 'c', 'd']);
        self::assertCount(2, $first);
        self::assertTaken([], $this->claim($journal, 'gpu', ['a', 'b', 'c', 'd']));
        $journal->conclude('gpu', [$first->subjects[0]], $first->token);
        self::assertCount(1, $this->claim($journal, 'gpu', ['a', 'b', 'c', 'd']));
    }

    public function testRateBandsLetThroughTheirBurstThenTheirPace(): void
    {
        $journal = $this->journal(new Dag(new Node('call', rate: [new Rate(3, '1m'), new Rate(4, '1h')])));
        $subjects = array_map(static fn(int $i): string => "s{$i}", range(0, 9));
        self::assertCount(3, $this->claim($journal, 'call', $subjects));
        self::assertTaken([], $this->claim($journal, 'call', $subjects));
        $this->clock->now = '2026-01-01T00:00:20+00:00';
        self::assertCount(1, $this->claim($journal, 'call', $subjects), '20 s buys one');
        $this->clock->now = '2026-01-01T00:05:00+00:00';
        self::assertTaken([], $this->claim($journal, 'call', $subjects), 'the minute band is full again, the hour band is spent');
    }

    public function testPerPolicyGivesEachPolicyItsOwnBudget(): void
    {
        $journal = $this->journal(new Dag(new Node('call', concurrency: 1, per: Per::Policy)));
        $journal->enroll(['b1', 'b2', 'b3'], 'big');
        $journal->enroll(['s1', 's2'], 'small');
        self::assertTaken(['b1', 's1', 'x'], $this->claim($journal, 'call', ['b1', 'b2', 'b3', 's1', 's2', 'x', 'y']), 'one for big, one for small, one for the subjects without a policy');
        self::assertTaken([], $this->claim($journal, 'call', ['b2', 's2', 'y']), 'every budget is spent');
    }

    public function testAPolicyGivesItsBudgetItsOwnSize(): void
    {
        $journal = $this->journal(new Graph(
            new Document('api'),
            new Dag(new Node('call', concurrency: 1, per: Per::Policy)),
            ['big' => ['call' => ['concurrency' => 3]]],
        ));
        $journal->enroll(['b1', 'b2', 'b3', 'b4'], 'big');
        $journal->enroll(['s1', 's2'], 'small');
        self::assertTaken(['b1', 'b2', 'b3', 's1', 'x'], $this->claim($journal, 'call', ['b1', 'b2', 'b3', 'b4', 's1', 's2', 'x']), '3 for big, 1 for small, 1 for the subjects without a policy');
    }

    public function testALaneLetsArrivalsInByPlaceWithinItsRate(): void
    {
        $journal = $this->journal(new Dag(
            new Node('arrive', lane: new Lane(), rate: [new Rate(1, '1m')]),
            new Node('scrape', parents: ['arrive']),
            new Node('publish', parents: ['scrape']),
        ));
        foreach ([[0, 'c'], [1, 'a'], [2, 'b']] as [$minute, $subject]) {
            $this->clock->now = self::at($minute);
            $journal->arrive('arrive', [$subject]);
        }
        $this->clock->now = self::at(3);
        self::assertSame(1, $this->letIn($journal, ['a', 'b', 'c']));
        self::assertProgress(['arrive' => Status::Done], $journal, 'c', 'c arrived first');
        self::assertSame(0, $this->letIn($journal, ['a', 'b', 'c']), 'one a minute');
        $this->clock->now = self::at(4);
        self::assertSame(1, $this->letIn($journal, ['a', 'b', 'c']));
        self::assertProgress(['arrive' => Status::Done], $journal, 'a');
    }

    // -- groups ----------------------------------------------------------

    /** Sorting, then packing three of a colour together. */
    private static function packing(?Group $group = null): Dag
    {
        return new Dag(new Node('sort'), new Node('pack', parents: ['sort'], group: $group ?? new Group(3)));
    }

    /**
     * Every brick through `sort`, so `pack` is the only thing left.
     *
     * @param array<string, string> $bricks brick => colour
     */
    private function sorted(NodeJournal $journal, array $bricks): void
    {
        $this->work($journal, 'sort', array_map('strval', array_keys($bricks)));
    }

    /**
     * @param array<string, string> $bricks
     *
     * @return list<array{0: string, 1: string}>
     */
    private static function pairsOf(array $bricks): array
    {
        $pairs = [];
        foreach ($bricks as $brick => $colour) {
            $pairs[] = [(string) $brick, $colour];
        }

        return $pairs;
    }

    public function testANodeGroupingByKeyRefusesACandidateWithoutOne(): void
    {
        $journal = $this->journal(new Dag(new Node('pack', group: new Group(2))));
        self::assertThrows(fn() => $journal->claim('pack', 2, $this->harness->candidates(['a', 'b'])), \InvalidArgumentException::class, 'grampy_key');
    }

    public function testAGroupGoesWholeOrNotAtAll(): void
    {
        $journal = $this->journal(self::packing());
        $bricks = ['b1' => 'red', 'b2' => 'blue', 'b3' => 'red', 'b4' => 'blue', 'b5' => 'red'];
        $this->sorted($journal, $bricks);
        $candidates = $this->harness->keyed(self::pairsOf($bricks));
        self::assertTaken(['b1', 'b3', 'b5'], $journal->claim('pack', 10, $candidates), 'the three reds went together');
        self::assertTaken([], $journal->claim('pack', 10, $candidates), 'two blues are not a group of three, and nothing was written for them');
    }

    public function testAGroupIsOneLeaseOverEveryMember(): void
    {
        $journal = $this->journal(self::packing());
        $bricks = ['b1' => 'red', 'b2' => 'red', 'b3' => 'red'];
        $this->sorted($journal, $bricks);
        $lease = $journal->claim('pack', 10, $this->harness->keyed(self::pairsOf($bricks)));
        self::assertTaken(['b1', 'b2', 'b3'], $lease);
        self::assertSame(3, $journal->conclude('pack', $lease, $lease->token), 'one token for the group');
    }

    public function testTwoKeysNeverEndUpInOneGroup(): void
    {
        $journal = $this->journal(self::packing());
        $bricks = ['b1' => 'red', 'b2' => 'blue', 'b3' => 'red', 'b4' => 'blue', 'b5' => 'blue', 'b6' => 'red'];
        $this->sorted($journal, $bricks);
        $candidates = $this->harness->keyed(self::pairsOf($bricks));
        self::assertTaken(['b1', 'b3', 'b6'], $journal->claim('pack', 10, $candidates), 'reds, in the order they were offered');
        self::assertTaken(['b2', 'b4', 'b5'], $journal->claim('pack', 10, $candidates), 'then blues — never a mixture');
    }

    public function testMaxWaitLetsAShortGroupGoAsItIs(): void
    {
        $journal = $this->journal(self::packing(new Group(3, maxWait: '1h')));
        $bricks = ['b1' => 'blue', 'b2' => 'blue'];
        $this->sorted($journal, $bricks);
        $candidates = $this->harness->keyed(self::pairsOf($bricks));
        $this->clock->now = self::at(59);
        self::assertTaken([], $journal->claim('pack', 10, $candidates), 'not yet');
        $this->clock->now = self::at(61);
        self::assertTaken(['b1', 'b2'], $journal->claim('pack', 10, $candidates), 'past maxWait an incomplete group goes, and the worker sees its size');
    }

    public function testWithoutMaxWaitAShortGroupWaitsForEver(): void
    {
        $journal = $this->journal(self::packing());
        $this->sorted($journal, ['b1' => 'pink']);
        $this->clock->now = self::at(60 * 24 * 365);
        self::assertTaken([], $journal->claim('pack', 10, $this->harness->keyed([['b1', 'pink']])));
    }

    public function testAGroupWithoutAKeyTakesAnySubjects(): void
    {
        $journal = $this->journal(self::packing(new Group(3, perKey: false)));
        $bricks = ['b1' => 'red', 'b2' => 'blue', 'b3' => 'green'];
        $this->sorted($journal, $bricks);
        self::assertTaken(['b1', 'b2', 'b3'], $journal->claim('pack', 10, $this->harness->keyed(self::pairsOf($bricks))));
    }

    // -- concurrency -----------------------------------------------------
    //
    // SAID IN SESSIONS, NOT IN LOCKS. A session is one unit of work on shared
    // storage. The tests open, act, commit — in forked processes, each with
    // its own connection; whatever a driver uses to stay correct is its own
    // business, the outcome is not.

    public function testAClaimRacingAForgetNeverOrphansANode(): void
    {
        $store = $this->store(Graphs::diamond());
        try {
            $setup = $store->session();
            $lease = $setup->journal()->claim('start', 1, $setup->candidates(['s1']));
            $setup->journal()->conclude('start', $lease, $lease->token);
            $setup->commit();

            $requeue = $store->session();
            foreach (['start', 'left', 'right', 'end'] as $name) {
                $requeue->journal()->forget($name, ['s1']);
            }
            self::race(
                static function () use ($store): void {
                    self::unit($store, static fn(Session $s): Lease => $s->journal()->claim('left', 1, $s->candidates(['s1'])));
                },
                static fn() => $requeue->commit(),
            );
            self::assertNoOrphan($store, ['s1']);
        } finally {
            $store->close();
        }
    }

    public function testConcurrentClaimersNeverTakeASubjectTwice(): void
    {
        $subjects = array_map(static fn(int $i): string => \sprintf('s%02d', $i), range(0, 39));
        $store = $this->store(Graphs::diamond());
        try {
            $workers = [];
            for ($i = 0; $i < 4; ++$i) {
                // Each worker walks the candidates in its own order, so that
                // claimers collide on different subjects at different times.
                $order = 1 === $i % 2 ? [...\array_slice($subjects, $i), ...\array_slice($subjects, 0, $i)] : array_reverse($subjects);
                $workers[] = static function () use ($store, $order): array {
                    $taken = [];
                    while (true) {
                        $got = self::unit($store, static fn(Session $s): Lease => $s->journal()->claim('start', 7, $s->candidates($order)));
                        if ($got->isEmpty()) {
                            return $taken;
                        }
                        array_push($taken, ...$got->subjects);
                    }
                };
            }
            $everyone = array_merge(...array_map(static fn(mixed $r): array => \is_array($r) ? $r : [], self::processes($workers)));
            sort($everyone);
            self::assertSame($subjects, $everyone, 'each subject taken exactly once');
        } finally {
            $store->close();
        }
    }

    public function testClaimsAndRequeuesInterleavedKeepEveryParent(): void
    {
        $subjects = array_map(static fn(int $i): string => "s{$i}", range(0, 5));
        $store = $this->store(Graphs::diamond());
        try {
            $janitor = static function () use ($store, $subjects): void {
                for ($round = 0; $round < 15; ++$round) {
                    $chosen = array_values(array_filter($subjects, static fn(int $k): bool => $k % 3 === $round % 3, \ARRAY_FILTER_USE_KEY));
                    self::unit($store, static function (Session $s) use ($chosen): void {
                        foreach (['start', 'left', 'right', 'end'] as $name) {
                            $s->journal()->forget($name, $chosen);
                        }
                    });
                }
            };
            self::processes([self::claimer($store, ['start', 'left'], $subjects), self::claimer($store, ['right', 'end'], $subjects), self::claimer($store, ['left', 'end', 'start'], $subjects), $janitor]);
            self::assertNoOrphan($store, $subjects);
        } finally {
            $store->close();
        }
    }

    public function testTwoVersionsArrivingAtOnceAreBothKept(): void
    {
        // A lane keeping every version reads what waits, merges, then writes:
        // two arrivals at once would each start from the state before the
        // other. The journal merges under the driver's guard.
        $store = $this->store(Graphs::listing(new Lane(Merge::All, maxSize: 10)));
        try {
            $one = $store->session();
            $one->journal()->arrive('arrive', ['s1'], 'v1');
            self::race(
                static function () use ($store): void {
                    self::unit($store, static fn(Session $s): array => $s->journal()->arrive('arrive', ['s1'], 'v2'));
                },
                static fn() => $one->commit(),
            );
            $check = $store->session();
            try {
                self::assertSame(['v1', 'v2'], $check->journal()->refs('s1', 'arrive'), 'both versions waited, neither overwrote the other');
            } finally {
                $check->rollback();
            }
        } finally {
            $store->close();
        }
    }

    /** @return iterable<string, array{string}> */
    public static function firsts(): iterable
    {
        yield 'the first holds' => ['first'];
        yield 'the second holds' => ['second'];
    }

    /**
     * The lane lets v2 in — archiving the pass — while a worker claims
     * `publish` on the strength of that pass. Whoever writes first, a claim
     * granted is never archived under the worker's feet.
     */
    #[DataProvider('firsts')]
    public function testALaneNeverLetsAVersionInOverAClaim(string $first): void
    {
        $dag = Graphs::listing(new Lane());
        $store = $this->store($dag);
        try {
            $setup = $store->session();
            $setup->journal()->arrive('arrive', ['s1'], 'v1');
            $setup->journal()->settle($setup->candidates(['s1']));
            $lease = $setup->journal()->claim('scrape', 1, $setup->candidates(['s1']));
            $setup->journal()->conclude('scrape', $lease, $lease->token);
            $setup->journal()->arrive('arrive', ['s1'], 'v2');
            $setup->commit();

            $claim = static fn(Session $s): Lease => $s->journal()->claim('publish', 1, $s->candidates(['s1']));
            $letIn = static fn(Session $s): array => $s->journal()->settle($s->candidates(['s1']));
            $held = $store->session();
            ('first' === $first ? $claim : $letIn)($held);
            $other = 'first' === $first ? $letIn : $claim;
            self::race(
                static function () use ($store, $other): void {
                    self::unit($store, $other);
                },
                static fn() => $held->commit(),
            );
            self::assertNoOrphan($store, ['s1'], $dag);
            $check = $store->session();
            try {
                $progress = $check->journal()->progress('s1');
                if (isset($progress['publish'])) {
                    // The claim won: the pass it stands on must still be there.
                    self::assertSame(Status::Running, $progress['publish'], 'the lane archived a pass while a worker held publish');
                    self::assertNotNull($check->journal()->arrival('s1', 'arrive'), 'v2 still waits for the pass to end');
                } else {
                    // The lane won: v2 entered, and the claim took nothing.
                    self::assertSame(['arrive' => Status::Done], $progress);
                }
            } finally {
                $check->rollback();
            }
        } finally {
            $store->close();
        }
    }

    #[DataProvider('firsts')]
    public function testAVersionArrivingAsTheLaneLetsOneInIsNeverLost(string $first): void
    {
        $store = $this->store(Graphs::listing(new Lane()));
        try {
            $setup = $store->session();
            $setup->journal()->arrive('arrive', ['s1'], 'v1');
            $setup->commit();

            $arrive = static fn(Session $s): array => $s->journal()->arrive('arrive', ['s1'], 'v2');
            $letIn = static fn(Session $s): array => $s->journal()->settle($s->candidates(['s1']));
            $held = $store->session();
            ('first' === $first ? $arrive : $letIn)($held);
            $other = 'first' === $first ? $letIn : $arrive;
            self::race(
                static function () use ($store, $other): void {
                    self::unit($store, $other);
                },
                static fn() => $held->commit(),
            );
            $check = $store->session();
            try {
                $waiting = $check->journal()->arrival('s1', 'arrive');
                $kept = [...self::noted($check->journal(), 's1', Outcome::Entered), ...(null === $waiting ? [] : [$waiting->ref])];
                self::assertContains('v2', $kept, 'v2 was lost');
            } finally {
                $check->rollback();
            }
        } finally {
            $store->close();
        }
    }

    public function testAClaimRacingAnotherNeverExceedsTheConcurrency(): void
    {
        // One claim takes the whole budget and has not committed yet; a second
        // claim runs meanwhile. It must not count the budget as free.
        $store = $this->store(new Dag(new Node('gpu', concurrency: 3)));
        $subjects = array_map(static fn(int $i): string => \sprintf('s%02d', $i), range(0, 9));
        try {
            $first = $store->session();
            self::assertCount(3, $first->journal()->claim('gpu', 3, $first->candidates(\array_slice($subjects, 0, 5))));
            $theirs = \array_slice($subjects, 5);
            self::race(
                static function () use ($store, $theirs): void {
                    self::unit($store, static fn(Session $s): Lease => $s->journal()->claim('gpu', 3, $s->candidates($theirs)));
                },
                static fn() => $first->commit(),
            );
            $check = $store->session();
            try {
                self::assertSame(3, $check->journal()->counts('gpu')['running']);
            } finally {
                $check->rollback();
            }
        } finally {
            $store->close();
        }
    }

    public function testConcurrentClaimersNeverExceedTheConcurrency(): void
    {
        $store = $this->store(new Dag(new Node('gpu', concurrency: 3)));
        $subjects = array_map(static fn(int $i): string => \sprintf('s%02d', $i), range(0, 29));
        try {
            $workers = [];
            for ($i = 0; $i < 4; ++$i) {
                $workers[] = static function () use ($store, $subjects): void {
                    for ($round = 0; $round < 8; ++$round) {
                        self::unit($store, static fn(Session $s): Lease => $s->journal()->claim('gpu', 2, $s->candidates($subjects)));
                    }
                };
            }
            self::processes($workers);
            $check = $store->session();
            try {
                self::assertSame(3, $check->journal()->counts('gpu')['running']);
            } finally {
                $check->rollback();
            }
        } finally {
            $store->close();
        }
    }

    public function testTwoClaimersNeverSplitOneGroup(): void
    {
        // Both workers see three reds — overlapping, not the same — and both
        // decide to take them. Only one may end up holding any: a group half
        // taken is a bag that can never be filled.
        $store = $this->store(self::packing());
        try {
            $setup = $store->session();
            $lease = $setup->journal()->claim('sort', 4, $setup->candidates(['b1', 'b2', 'b3', 'b4']));
            $setup->journal()->conclude('sort', $lease, $lease->token);
            $setup->commit();

            $one = $store->session();
            $first = $one->journal()->claim('pack', 10, $one->keyed([['b1', 'red'], ['b2', 'red'], ['b3', 'red']]));
            self::race(
                static function () use ($store): void {
                    self::unit($store, static fn(Session $s): Lease => $s->journal()->claim('pack', 10, $s->keyed([['b2', 'red'], ['b3', 'red'], ['b4', 'red']])));
                },
                static fn() => $one->commit(),
            );
            self::assertCount(3, $first);
            $check = $store->session();
            try {
                $packed = array_values(array_filter(['b1', 'b2', 'b3', 'b4'], static fn(string $b): bool => isset($check->journal()->progress($b)['pack'])));
                self::assertSame(['b1', 'b2', 'b3'], $packed, 'one whole group, and nothing of a second one');
            } finally {
                $check->rollback();
            }
        } finally {
            $store->close();
        }
    }

    // -- concurrency helpers ---------------------------------------------

    /**
     * ONE UNIT OF WORK on a fresh session, committed. A transient conflict the
     * storage reports — a deadlock between transactions mixing claims and
     * forgets on the same subjects — is retried, as an application retries
     * its transaction: the guarantees under test are about what is written,
     * never about whether a database may ask to try again.
     *
     * @template T
     *
     * @param callable(Session): T $work
     *
     * @return T
     */
    private static function unit(Store $store, callable $work): mixed
    {
        for ($attempt = 1; ; ++$attempt) {
            $session = $store->session();
            try {
                $result = $work($session);
                $session->commit();

                return $result;
            } catch (\Throwable $e) {
                try {
                    $session->rollback();
                } catch (\Throwable) {
                    // the storage already rolled it back
                }
                if ($attempt >= 20 || !$store->retryable($e)) {
                    throw $e;
                }
                usleep(random_int(1_000, 20_000));
            }
        }
    }

    /**
     * A worker claiming and concluding `names`, round after round.
     *
     * @param list<string>     $names
     * @param list<int|string> $subjects
     *
     * @return \Closure(): void
     */
    private static function claimer(Store $store, array $names, array $subjects): \Closure
    {
        return static function () use ($store, $names, $subjects): void {
            for ($round = 0; $round < 15; ++$round) {
                foreach ($names as $name) {
                    self::unit($store, static function (Session $s) use ($name, $subjects): void {
                        $got = $s->journal()->claim($name, 3, $s->candidates($subjects));
                        $s->journal()->conclude($name, $got, $got->token);
                    });
                }
            }
        };
    }

    private function store(Graph|Dag $dag): Store
    {
        $store = $this->harness->store($dag, $this->clock);
        if (null === $store) {
            self::markTestSkipped('this driver lives in one process: no concurrency to test');
        }
        if (!\function_exists('pcntl_fork') || !\function_exists('posix_kill')) {
            self::markTestSkipped('the concurrency tests fork: they need the pcntl and posix extensions');
        }

        return $store;
    }

    /**
     * Run `claim` in a forked process while the other session is still open.
     * If it is still busy after a moment — the driver made it wait — commit
     * the other session to let it through.
     *
     * @param callable(): void $claim
     * @param callable(): void $commitOther
     */
    private static function race(callable $claim, callable $commitOther): void
    {
        [$pid, $file] = self::fork($claim);
        usleep(500_000);
        $commitOther();
        self::collect([$pid => $file], 30.0);
    }

    /**
     * Run each worker in a process of its own; return what each returned.
     *
     * @param list<callable(): mixed> $workers
     *
     * @return list<mixed>
     */
    private static function processes(array $workers): array
    {
        $running = [];
        foreach ($workers as $worker) {
            [$pid, $file] = self::fork($worker);
            $running[$pid] = $file;
        }

        return self::collect($running, 120.0);
    }

    /**
     * Fork: the child runs `work`, writes its result or its error to a file,
     * and ends itself with SIGKILL — never returning into PHPUnit, and never
     * closing the connections it inherited, which belong to the parent.
     *
     * @param callable(): mixed $work
     *
     * @return array{0: int, 1: string}
     */
    private static function fork(callable $work): array
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'gramphp');
        $pid = pcntl_fork();
        if (-1 === $pid) {
            self::fail('could not fork');
        }
        if (0 === $pid) {
            try {
                $result = ['ok' => $work()];
            } catch (\Throwable $e) {
                $result = ['error' => $e::class . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString()];
            }
            file_put_contents($file, serialize($result));
            posix_kill(posix_getpid(), \SIGKILL);
        }

        return [$pid, $file];
    }

    /**
     * Wait for the children, within `timeout` seconds; fail on an error or a hang.
     *
     * @param array<int, string> $running pid => result file
     *
     * @return list<mixed>
     */
    private static function collect(array $running, float $timeout): array
    {
        $deadline = microtime(true) + $timeout;
        $pending = $running;
        while ([] !== $pending) {
            foreach (array_keys($pending) as $pid) {
                if (0 !== pcntl_waitpid($pid, $status, \WNOHANG)) {
                    unset($pending[$pid]);
                }
            }
            if ([] !== $pending && microtime(true) > $deadline) {
                foreach (array_keys($pending) as $pid) {
                    posix_kill($pid, \SIGKILL);
                    pcntl_waitpid($pid, $status);
                }
                self::fail('a worker never returned');
            }
            usleep(10_000);
        }
        $results = [];
        foreach ($running as $file) {
            $raw = (string) file_get_contents($file);
            @unlink($file);
            $result = '' === $raw ? null : unserialize($raw);
            if (!\is_array($result)) {
                self::fail('a worker died without a word');
            }
            if (isset($result['error'])) {
                self::fail('a worker failed: ' . (\is_string($result['error']) ? $result['error'] : 'unknown'));
            }
            $results[] = $result['ok'] ?? null;
        }

        return $results;
    }

    /**
     * No row of a node while one of its parents is not done, skipped or omitted.
     *
     * @param list<int|string> $subjects
     */
    private static function assertNoOrphan(Store $store, array $subjects, ?Dag $dag = null): void
    {
        $session = $store->session();
        try {
            $dag ??= Graphs::diamond();
            $orphans = [];
            foreach ($subjects as $subject) {
                $progress = $session->journal()->progress($subject);
                foreach (array_keys($progress) as $name) {
                    foreach ($dag->node((string) $name)->parents as $parent) {
                        $status = isset($progress[$parent]) ? $progress[$parent]->value : null;
                        if (!\in_array($status, Status::satisfying(), true)) {
                            $orphans[] = "{$subject}: {$name} is {$progress[$name]->value} but its parent {$parent} is " . ($status ?? 'absent');
                        }
                    }
                }
            }
            self::assertSame([], $orphans, 'no node held without its parents');
        } finally {
            $session->rollback();
        }
    }

    // -- small things ----------------------------------------------------

    /**
     * Passes of at most `limit` until one writes nothing.
     *
     * @param callable(): int $work
     *
     * @return list<int>
     */
    private static function bounded(callable $work, int $limit): array
    {
        $passes = [];
        do {
            $passes[] = $last = $work();
            self::assertLessThanOrEqual($limit, $last, "a pass wrote {$last}, over its limit {$limit}");
            self::assertLessThan(50, \count($passes), 'the passes never end');
        } while (0 !== $last);

        return $passes;
    }

    /**
     * @param callable(): mixed         $call
     * @param class-string<\Throwable> $class
     */
    private static function assertThrows(callable $call, string $class, string $needle): void
    {
        try {
            $call();
        } catch (\Throwable $e) {
            self::assertInstanceOf($class, $e);
            self::assertStringContainsString($needle, $e->getMessage());

            return;
        }
        self::fail("expected {$class} mentioning '{$needle}'");
    }
}
