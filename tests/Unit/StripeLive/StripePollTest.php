<?php

namespace Tests\Unit\StripeLive;

use PHPUnit\Framework\TestCase;
use Tests\StripeLive\Support\StripePoll;

final class StripePollTest extends TestCase
{
    private float $clock = 0.0;

    /** @var list<int> */
    private array $sleeps = [];

    private function poll(): StripePoll
    {
        $this->clock = 0.0;
        $this->sleeps = [];

        return new StripePoll(fn (): float => $this->clock, function (int $milliseconds): void {
            $this->sleeps[] = $milliseconds;
            $this->clock += $milliseconds / 1000;
        });
    }

    public function test_a_first_attempt_that_answers_never_sleeps(): void
    {
        $this->assertSame('ok', $this->poll()->until(fn (): string => 'ok', 30));
        $this->assertSame([], $this->sleeps);
    }

    public function test_it_retries_with_growing_waits_until_the_attempt_answers(): void
    {
        $calls = 0;

        $result = $this->poll()->until(function () use (&$calls): ?string {
            return ++$calls === 5 ? 'late' : null;
        }, 30, 1000);

        $this->assertSame('late', $result);
        $this->assertSame(5, $calls);
        $this->assertSame([1000, 1500, 2250, 3375], $this->sleeps);
    }

    public function test_waits_never_exceed_five_seconds(): void
    {
        $this->poll()->until(fn (): ?string => null, 60, 4000);

        $this->assertSame(4000, $this->sleeps[0]);
        $this->assertSame(5000, $this->sleeps[1]);
        $this->assertSame(5000, $this->sleeps[2]);
    }

    public function test_it_gives_up_at_the_deadline_and_never_sleeps_past_it(): void
    {
        $calls = 0;

        $result = $this->poll()->until(function () use (&$calls): ?string {
            $calls++;

            return null;
        }, 3, 1000);

        $this->assertNull($result);
        $this->assertSame([1000, 1500, 500], $this->sleeps);
        $this->assertSame(4, $calls);
        $this->assertEqualsWithDelta(3.0, $this->clock, 0.0001);
    }

    public function test_a_zero_timeout_still_tries_once(): void
    {
        $calls = 0;

        $this->assertNull($this->poll()->until(function () use (&$calls): ?string {
            $calls++;

            return null;
        }, 0));
        $this->assertSame(1, $calls);
        $this->assertSame([], $this->sleeps);
    }

    public function test_the_default_clock_and_sleep_work(): void
    {
        $started = microtime(true);

        $this->assertNull((new StripePoll)->until(fn (): ?string => null, 0));
        $this->assertLessThan(1.0, microtime(true) - $started);
    }
}
