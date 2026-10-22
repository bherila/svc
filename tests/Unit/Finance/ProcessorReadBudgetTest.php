<?php

namespace App\Support\Finance;

use Tests\Unit\Finance\ProcessorReadBudgetTest;

/** Test-only monotonic clock; every other test retains the native clock. */
function hrtime(bool $as_number = false): array|int|float|false
{
    $ticks = ProcessorReadBudgetTest::$nanoseconds;
    if ($ticks === null) {
        return \hrtime($as_number);
    }

    return $as_number ? $ticks : [intdiv($ticks, 1_000_000_000), $ticks % 1_000_000_000];
}

namespace Tests\Unit\Finance;

use App\Support\Finance\ProcessorReadBudget;
use DomainException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProcessorReadBudgetTest extends TestCase
{
    public static ?int $nanoseconds = null;

    protected function tearDown(): void
    {
        self::$nanoseconds = null;
        parent::tearDown();
    }

    public function test_the_unset_test_clock_preserves_native_monotonic_clock_behavior(): void
    {
        $this->assertNull(self::$nanoseconds);
        $before = \hrtime(true);
        $actual = \App\Support\Finance\hrtime(as_number: true);
        $after = \hrtime(true);
        $this->assertGreaterThanOrEqual($before, $actual);
        $this->assertLessThanOrEqual($after, $actual);
        $beforeParts = \hrtime();
        $actualParts = \App\Support\Finance\hrtime();
        $afterParts = \hrtime();
        $this->assertIsArray($actualParts);
        $this->assertCount(2, $actualParts);
        $this->assertGreaterThanOrEqual($beforeParts, $actualParts);
        $this->assertLessThanOrEqual($afterParts, $actualParts);
    }

    /** @return iterable<string, array{?int}> */
    public static function durationBoundaries(): iterable
    {
        yield 'default ten minutes' => [null];
        yield 'one second' => [1];
        yield 'maximum ten minutes' => [600];
    }

    #[DataProvider('durationBoundaries')]
    public function test_a_read_is_allowed_one_nanosecond_before_the_deadline_and_refused_at_it(?int $maxSeconds): void
    {
        self::$nanoseconds = 10_000_000_000;
        try {
            $budget = $maxSeconds === null ? new ProcessorReadBudget : new ProcessorReadBudget(maxSeconds: $maxSeconds);
            $deadline = self::$nanoseconds + ($maxSeconds ?? 600) * 1_000_000_000;
            self::$nanoseconds = $deadline - 1;
            $budget->consume();
            $this->assertSame(1, $budget->used());
            $this->assertFalse($budget->refused());
            self::$nanoseconds = $deadline;
            try {
                $budget->consume();
                $this->fail('A provider read must not begin at the run deadline.');
            } catch (DomainException $failure) {
                $this->assertSame('The processor read budget is exhausted; continue the sync in another run.', $failure->getMessage());
                $this->assertSame(1, $budget->used());
                $this->assertTrue($budget->refused());
            }
        } finally {
            self::$nanoseconds = null;
        }
    }

    public function test_the_default_allows_exactly_two_hundred_provider_calls(): void
    {
        $budget = new ProcessorReadBudget;
        for ($read = 0; $read < 200; $read++) {
            $budget->consume();
        }
        $this->assertSame(200, $budget->used());
        $this->assertFalse($budget->refused());
        $this->expectException(DomainException::class);
        $budget->consume();
    }

    public function test_the_maximum_call_limit_is_inclusive(): void
    {
        $budget = new ProcessorReadBudget(1000);
        for ($read = 0; $read < 1000; $read++) {
            $budget->consume();
        }
        $this->assertSame(1000, $budget->used());
        $this->assertFalse($budget->refused());
        $this->expectException(DomainException::class);
        $budget->consume();
    }

    public function test_the_duration_refuses_values_outside_its_inclusive_bounds(): void
    {
        foreach ([-1, 601] as $seconds) {
            try {
                new ProcessorReadBudget(maxSeconds: $seconds);
                $this->fail('An out-of-range run duration was accepted.');
            } catch (DomainException $failure) {
                $this->assertSame('The processor run duration must be between 0 and 600 seconds.', $failure->getMessage());
            }
        }
    }

    public function test_every_read_shares_one_limit_and_refusal_does_not_reset_it(): void
    {
        $budget = new ProcessorReadBudget(2);
        $budget->consume();
        $budget->consume();
        for ($retry = 0; $retry < 2; $retry++) {
            try {
                $budget->consume();
                $this->fail('A spent read budget must refuse another call.');
            } catch (DomainException $failure) {
                $this->assertSame('The processor read budget is exhausted; continue the sync in another run.', $failure->getMessage());
                $this->assertNull($failure->getPrevious());
            }
        }
        $this->assertSame(2, $budget->used());
    }

    public function test_an_expired_run_refuses_before_consuming_another_call(): void
    {
        $budget = new ProcessorReadBudget(2, maxSeconds: 0);
        try {
            $budget->consume();
            $this->fail('An elapsed run must not begin another provider request.');
        } catch (DomainException $failure) {
            $this->assertSame('The processor read budget is exhausted; continue the sync in another run.', $failure->getMessage());
            $this->assertSame(0, $budget->used());
        }
    }

    public function test_refused_is_set_only_by_an_actual_consume_denial(): void
    {
        $budget = new ProcessorReadBudget(1);
        $this->assertFalse($budget->refused());
        $budget->consume();
        $this->assertFalse($budget->refused());
        try {
            $budget->consume();
        } catch (DomainException) {
            $this->assertTrue($budget->refused());
        }
        $expired = new ProcessorReadBudget(1, maxSeconds: 0);
        $this->assertFalse($expired->refused());
        try {
            $expired->consume();
        } catch (DomainException) {
            $this->assertTrue($expired->refused());
        }
    }

    public function test_a_zero_or_excessive_limit_is_refused(): void
    {
        foreach ([0, -1, 1001] as $limit) {
            try {
                new ProcessorReadBudget($limit);
                $this->fail('An invalid budget was accepted.');
            } catch (DomainException $failure) {
                $this->assertSame('The processor read budget must be between 1 and 1000 calls.', $failure->getMessage());
            }
        }
    }
}
