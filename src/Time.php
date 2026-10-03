<?php

declare(strict_types=1);

namespace Quazardous\GramPHP;

/**
 * TIME, AS THE JOURNAL WRITES IT — durations and instants.
 *
 * INSTANTS ARE TEXT, AND THEY COMPARE AS TEXT. Rows carry ISO-8601 instants
 * in UTC, to the second (`2026-01-01T10:00:00+00:00`). One fixed format and
 * one offset make lexical order the time order, in every storage — which is
 * what lets a driver compare `started_at < older_than` without knowing what a
 * date is. Every instant coming from outside goes through `stamp()`.
 */
final class Time
{
    /** The journal's format: UTC, ISO-8601, to the second. */
    public const FORMAT = 'Y-m-d\TH:i:sP';

    private const UNITS = ['' => 1, 's' => 1, 'm' => 60, 'h' => 3600, 'd' => 86400];

    /** The default clock: now, in the journal's format. */
    public static function utcNow(): string
    {
        return gmdate('Y-m-d\TH:i:s') . '+00:00';
    }

    /**
     * A duration in seconds: a number, or text with a unit — `90`, `"30s"`,
     * `"10m"`, `"2h"`, `"7d"`.
     */
    public static function seconds(int|float|string $duration): float
    {
        if (\is_int($duration) || \is_float($duration)) {
            $value = (float) $duration;
        } else {
            if (1 !== preg_match('/^\s*(\d+(?:\.\d+)?)\s*([smhd]?)\s*$/', $duration, $match)) {
                throw new \InvalidArgumentException(\sprintf(
                    'not a duration: %s — expected e.g. 30s, 10m, 2h, 7d',
                    var_export($duration, true),
                ));
            }
            $value = (float) $match[1] * self::UNITS[$match[2]];
        }
        if ($value < 0) {
            throw new \InvalidArgumentException(\sprintf('a duration is never negative: %s', var_export($duration, true)));
        }

        return $value;
    }

    /**
     * ANY MOMENT, IN THE JOURNAL'S FORMAT: UTC, ISO-8601, to the second.
     *
     * Times compare as TEXT, which orders them only in one format and one
     * offset: `11:00+02:00` sorts after `10:00+00:00` though it is an hour
     * earlier. So every time that comes from outside — an injected clock, an
     * `older_than` — is read, converted to UTC and written back in the one
     * format. A moment without a time zone is refused, since nothing says which
     * zone it meant. Fractions of a second are dropped.
     */
    public static function stamp(string|\DateTimeInterface $moment): string
    {
        if ($moment instanceof \DateTimeInterface) {
            $instant = \DateTimeImmutable::createFromInterface($moment);
        } else {
            $text = trim($moment);
            if (1 !== preg_match('/(?:[zZ]|[+-]\d{2}:?\d{2})$/', $text)) {
                throw new \InvalidArgumentException(\sprintf(
                    '%s has no time zone: say which (+00:00, Z, or an aware DateTime)',
                    var_export($moment, true),
                ));
            }
            try {
                $instant = new \DateTimeImmutable($text);
            } catch (\Exception) {
                throw new \InvalidArgumentException(\sprintf(
                    'not a moment: %s — expected ISO-8601, such as 2026-01-01T10:00:00+00:00',
                    var_export($moment, true),
                ));
            }
        }

        return $instant->setTimezone(new \DateTimeZone('UTC'))->format(self::FORMAT);
    }

    /** `moment` moved by `by` seconds, in the journal's format. */
    public static function shift(string $moment, float $by): string
    {
        $at = self::epoch($moment) + $by;

        return gmdate('Y-m-d\TH:i:s', (int) floor($at)) . '+00:00';
    }

    /** Seconds since the Unix epoch of a moment in the journal's format. */
    public static function epoch(string $moment): float
    {
        return (float) (new \DateTimeImmutable($moment))->getTimestamp();
    }

    /** Seconds from `then` to `now`, both in the journal's format — null when `then` is. */
    public static function age(string $now, ?string $then): ?float
    {
        return null === $then ? null : self::epoch($now) - self::epoch($then);
    }
}
