<?php

declare(strict_types=1);

namespace Quazardous\GramPHP\Items;

/**
 * The items a claim took — YOUR objects, in the lease's order — with the
 * `token` to conclude them, and `missing`, the ids nothing loaded for.
 *
 * @template T
 *
 * @implements \IteratorAggregate<int, T>
 */
final readonly class ItemLease implements \IteratorAggregate, \Countable
{
    /** @var list<T> */
    public array $items;

    /** @var list<int|string> */
    public array $missing;

    /**
     * @param array<T>          $items
     * @param array<int|string> $missing
     */
    public function __construct(array $items, public string $token, array $missing = [])
    {
        $this->items = array_values($items);
        $this->missing = array_values($missing);
    }

    /** @return \ArrayIterator<int, T> */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->items);
    }

    public function count(): int
    {
        return \count($this->items);
    }

    public function isEmpty(): bool
    {
        return [] === $this->items;
    }
}
