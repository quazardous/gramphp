<?php

declare(strict_types=1);

namespace Quazardous\GramPHP\Tests\Contract;

use Quazardous\GramPHP\Dag;
use Quazardous\GramPHP\Items\Adapter;
use Quazardous\GramPHP\Node;

/** Everything the workflow needs to know about a brick, and no more. */
final class Bricks extends Adapter
{
    /** @var array<int, Brick> */
    public array $bricks = [];

    /** @var list<string> every call the layer made, to prove it asks once and loads once */
    public array $calls = [];

    /** @param list<Brick> $bricks */
    public function __construct(array $bricks)
    {
        foreach ($bricks as $brick) {
            $this->bricks[$brick->id] = $brick;
        }
    }

    /** scan chooses sort or burn; polish is optional; pack follows polish. */
    public static function graph(): Dag
    {
        return new Dag(
            new Node('scan', choice: true),
            new Node('sort', parents: ['scan']),
            new Node('burn', parents: ['scan']),
            new Node('polish', parents: ['sort'], optional: true),
            new Node('pack', parents: ['polish']),
        );
    }

    public function idOf(mixed $candidate): int
    {
        return self::brick($candidate)->id;
    }

    /** Objects pass; ids are fetched — the adapter alone can tell. */
    public function inflate(array $candidates): iterable
    {
        $thin = array_values(array_filter($candidates, is_int(...)));
        if ([] !== $thin) {
            sort($thin);
            $this->calls[] = 'inflate([' . implode(', ', $thin) . '])';
        }
        $out = [];
        foreach ($candidates as $candidate) {
            if (!\is_int($candidate)) {
                $out[] = $candidate;
            } elseif (isset($this->bricks[$candidate])) {
                $out[] = $this->bricks[$candidate];
            }
        }

        return $out;
    }

    public function policyOf(mixed $item): string
    {
        return self::brick($item)->crate;
    }

    public function branch(mixed $item, string $node): string
    {
        $this->calls[] = \sprintf('branch(%d,%s)', self::brick($item)->id, $node);

        return 'scrap' === self::brick($item)->kind ? 'burn' : 'sort';
    }

    public function applies(mixed $item, string $node): bool
    {
        $this->calls[] = \sprintf('applies(%d,%s)', self::brick($item)->id, $node);

        return 'polish' !== $node || 'factory' === self::brick($item)->crate;
    }

    /** @return list<string> the calls made to one handler */
    public function called(string $handler): array
    {
        return array_values(array_filter($this->calls, static fn(string $c): bool => str_starts_with($c, $handler . '(')));
    }

    public static function brick(mixed $item): Brick
    {
        return $item instanceof Brick ? $item : throw new \LogicException('not a brick: ' . get_debug_type($item));
    }
}
