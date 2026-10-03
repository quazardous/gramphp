<?php

declare(strict_types=1);

namespace Quazardous\GramPHP;

/**
 * The subjects a claim took, and `token`, the proof a worker brings back to
 * conclude or fail them (a fencing token).
 *
 * @implements \IteratorAggregate<int, int|string>
 */
final readonly class Lease implements \IteratorAggregate, \Countable
{
    /** @var list<int|string> */
    public array $subjects;

    /** @param array<int|string> $subjects */
    public function __construct(array $subjects, public string $token)
    {
        $this->subjects = array_values($subjects);
    }

    /** @return \ArrayIterator<int, int|string> */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->subjects);
    }

    public function count(): int
    {
        return \count($this->subjects);
    }

    public function isEmpty(): bool
    {
        return [] === $this->subjects;
    }
}
