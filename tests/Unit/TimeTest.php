<?php

declare(strict_types=1);

namespace Quazardous\GramPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Quazardous\GramPHP\Backoff;
use Quazardous\GramPHP\Retry;
use Quazardous\GramPHP\Time;

final class TimeTest extends TestCase
{
    public function testDurationsReadTheirUnit(): void
    {
        self::assertSame(90.0, Time::seconds(90));
        self::assertSame(30.0, Time::seconds('30s'));
        self::assertSame(600.0, Time::seconds('10m'));
        self::assertSame(7200.0, Time::seconds('2h'));
        self::assertSame(604800.0, Time::seconds('7d'));
    }

    public function testANonsenseDurationIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Time::seconds('ten minutes');
    }

    public function testAMomentIsReadIntoUtcToTheSecond(): void
    {
        self::assertSame('2025-12-31T23:30:00+00:00', Time::stamp('2026-01-01T01:30:00+02:00'));
        self::assertSame('2026-01-01T00:00:00+00:00', Time::stamp('2026-01-01T00:00:00.789Z'));
        self::assertSame('2026-01-01T00:00:00+00:00', Time::stamp(new \DateTimeImmutable('2026-01-01T01:00:00+01:00')));
    }

    public function testAMomentWithoutATimeZoneIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Time::stamp('2026-01-01T00:00:00');
    }

    public function testShiftKeepsTheFormat(): void
    {
        self::assertSame('2026-01-01T00:00:10+00:00', Time::shift('2026-01-01T00:00:00+00:00', 10));
        self::assertSame('2025-12-31T23:00:00+00:00', Time::shift('2026-01-01T00:00:00+00:00', -3600));
    }

    public function testBackoffShapesTheWait(): void
    {
        $exponential = new Retry(limit: 5, delay: '10s');
        self::assertSame([10.0, 20.0, 40.0], [$exponential->wait(1), $exponential->wait(2), $exponential->wait(3)]);
        $linear = new Retry(limit: 5, delay: '10s', backoff: Backoff::Linear);
        self::assertSame([10.0, 20.0, 30.0], [$linear->wait(1), $linear->wait(2), $linear->wait(3)]);
        $capped = new Retry(limit: 5, delay: '10s', maxDelay: '25s');
        self::assertSame(25.0, $capped->wait(3));
    }

    public function testJitterStaysWithinItsBand(): void
    {
        $retry = new Retry(limit: 1, delay: '100s', backoff: Backoff::Constant, jitter: 0.1);
        for ($i = 0; $i < 50; ++$i) {
            $wait = $retry->wait(1);
            self::assertGreaterThanOrEqual(90.0, $wait);
            self::assertLessThanOrEqual(110.0, $wait);
        }
    }
}
