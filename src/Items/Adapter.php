<?php

declare(strict_types=1);

namespace Quazardous\GramPHP\Items;

/**
 * HOW GRAMPHP HOOKS ONTO ONE OF YOUR OBJECTS.
 *
 * One method is yours to write — `idOf`. The rest have answers that suit an
 * application with nothing special to say, `inflate` included: it lets your
 * objects straight through, so it only needs writing when ids come in.
 */
abstract class Adapter
{
    // -- required ----------------------------------------------------------

    /**
     * The subject id of a candidate — unique, stable, int or string.
     *
     * IT MAY ALREADY BE ONE. `inflate` runs first and hands on whatever it
     * chose to let through, so an application that works in ids, or that
     * mixes them with objects, writes this and nothing else:
     *
     *     public function idOf(mixed $candidate): int|string
     *     {
     *         return $candidate instanceof Brick ? $candidate->id : $candidate;
     *     }
     *
     * The other handlers below are asked about the DATA, so they only ever
     * see what `inflate` turned into an item.
     */
    abstract public function idOf(mixed $candidate): int|string;

    // -- required only when a driver's query names the candidates -----------

    /**
     * WHATEVER THE CALLER HAD, AS ITEMS — in one call, your storage.
     *
     * The layer hands you the batch exactly as it received it and asks for
     * objects back. Yours already? Let them through, which is what the
     * default does. Ids, or a mix? Fetch what needs fetching, and only that:
     * you are the one who can tell them apart.
     *
     * Order does not matter, and a candidate nothing comes back for may be
     * left out: it lands in `ItemLease::$missing`.
     *
     * THIS IS ALSO WHERE IDS COME BACK FROM A DRIVER'S QUERY — the storage
     * named the candidates, so the claim has ids and nothing attached. An
     * adapter that never overrides this is told, rather than handed ids it
     * would treat as objects.
     *
     * @param list<mixed> $candidates
     *
     * @return iterable<mixed>
     */
    public function inflate(array $candidates): iterable
    {
        return $candidates;
    }

    // -- optional ----------------------------------------------------------

    /**
     * The named set of operating settings this item runs under. Your business
     * word (a crate, a partner, a plan) becomes that name here; gramphp never
     * learns what it meant. `null` means the defaults.
     */
    public function policyOf(mixed $item): ?string
    {
        return null;
    }

    /** Which branch this item takes out of a `choice` node. */
    public function branch(mixed $item, string $node): ?string
    {
        return null;
    }

    /**
     * Whether this OPTIONAL node is for this item at all. `false` gives it up
     * rather than doing it — see `Items`.
     */
    public function applies(mixed $item, string $node): bool
    {
        return true;
    }
}
