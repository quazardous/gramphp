<?php

declare(strict_types=1);

namespace Quazardous\GramPHP;

/**
 * Where a node stands for a subject: its row in the journal.
 *
 * A node's name, a subject, a policy are YOURS. A status is GRAMPHP'S: a
 * member of this closed set. It is stored as its value (`Status::Done->value`
 * is `"done"`), and every method that takes one also takes that string.
 */
enum Status: string
{
    /** Taken by a worker, not concluded yet. */
    case Running = 'running';
    /** Waiting to be taken again — a retry's row; `started_at` is when it is due. */
    case Scheduled = 'scheduled';
    case Done = 'done';
    /** Given up: an optional node skipped — by `skip`, or once its grace ran out. */
    case Skipped = 'skipped';
    /** A branch a choice did not take, and what only it leads to. */
    case Omitted = 'omitted';
    /** Did not produce; satisfies no child unless the child's edge says so. */
    case Failed = 'failed';

    /**
     * WHAT SATISFIES A CHILD, unless the child's edge says otherwise.
     *
     * `done`, `skipped` and `omitted` all satisfy, on purpose: a child does not
     * need to know WHY its parent is concluded, only that it will not be kept
     * waiting.
     *
     * @return list<string>
     */
    public static function satisfying(): array
    {
        return [self::Done->value, self::Skipped->value, self::Omitted->value];
    }

    /**
     * WHAT IS NEVER TAKEN AGAIN. Going back means FORGETTING the row.
     *
     * @return list<string>
     */
    public static function concluded(): array
    {
        return [self::Done->value, self::Skipped->value, self::Failed->value, self::Omitted->value];
    }

    /** The value of a member, or of a string naming one. */
    public static function valueOf(self|string $status): string
    {
        return $status instanceof self ? $status->value : $status;
    }
}
