<?php

declare(strict_types=1);

namespace Quazardous\GramPHP\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Quazardous\GramPHP\Dag;
use Quazardous\GramPHP\DagError;
use Quazardous\GramPHP\Document;
use Quazardous\GramPHP\Graph;
use Quazardous\GramPHP\Lane;
use Quazardous\GramPHP\Node;
use Quazardous\GramPHP\Rate;
use Quazardous\GramPHP\Retry;

/** A graph, its policies and their variants — no storage. */
final class GraphTest extends TestCase
{
    public function testAPolicyChangesSettingsAndNothingElse(): void
    {
        $graph = new Graph(new Document('offers'), new Dag(
            new Node('scrape', lease: '2m'),
            new Node('call', parents: ['scrape'], retry: new Retry(limit: 1)),
        ), ['slow' => ['scrape' => ['lease' => '10m'], 'call' => ['retry' => new Retry(limit: 4, delay: '1m')]]]);
        self::assertSame('10m', $graph->variant('slow')->node('scrape')->lease);
        self::assertSame(4, $graph->variant('slow')->node('call')->retry?->limit);
        self::assertSame(['scrape'], $graph->variant('slow')->node('call')->parents, 'the structure is the same');
        self::assertSame('2m', $graph->variant('other')->node('scrape')->lease, 'an unknown policy sees the defaults');
        self::assertSame(1, $graph->variant(null)->node('call')->retry?->limit);
        self::assertTrue($graph->overrides('call', 'retry'));
        self::assertFalse($graph->overrides('call', 'lease'));
    }

    public function testADocumentNamesItselfWhole(): void
    {
        self::assertSame('shop/orders@2', (new Document('orders', '2', 'shop'))->identity());
        self::assertSame('default/orders@0', (new Document('orders'))->identity());
    }

    /** @return iterable<string, array{array<string, array<string, array<string, mixed>>>, string}> */
    public static function brokenPolicies(): iterable
    {
        yield 'a node that does not exist' => [['x' => ['ghost' => ['lease' => '1m']]], 'does not exist'];
        yield 'the structure' => [['x' => ['a' => ['parents' => []]]], 'never the structure'];
        yield 'a grace on a node not optional' => [['x' => ['a' => ['grace' => '1m']]], 'not'];
        yield 'a lease that does not last' => [['x' => ['a' => ['lease' => '0s']]], 'lease'];
        yield 'a lane added' => [['x' => ['a' => ['lane' => new Lane()]]], 'never adds or removes'];
        yield 'a shared budget' => [['x' => ['a' => ['concurrency' => 2]]], 'Per::Policy'];
        yield 'a wrong type' => [['x' => ['a' => ['rate' => 'fast']]], "policy 'x' on 'a'"];
        yield 'a lease of the wrong type' => [['x' => ['a' => ['lease' => []]]], "policy 'x' on 'a'"];
    }

    /** @param array<string, array<string, array<string, mixed>>> $policies */
    #[DataProvider('brokenPolicies')]
    public function testAPolicyThatWouldBreakTheGraphIsRefused(array $policies, string $needle): void
    {
        $this->expectException(DagError::class);
        $this->expectExceptionMessage($needle);
        new Graph(new Document('x'), new Dag(new Node('a')), $policies);
    }

    public function testAPolicyMayRateABudgetOfItsOwn(): void
    {
        $graph = new Graph(new Document('api'), new Dag(new Node('call', per: \Quazardous\GramPHP\Per::Policy)), ['bulk' => ['call' => ['rate' => [new Rate(3, '1h')]]]]);
        self::assertCount(1, $graph->variant('bulk')->node('call')->rate);
        self::assertSame([], $graph->variant(null)->node('call')->rate);
    }
}
