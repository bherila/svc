<?php

namespace Tests\Unit\Billing;

use App\Support\Billing\DeferredWorkDates;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(DeferredWorkDates::class)]
final class DeferredWorkDatesTest extends TestCase
{
    /** @return iterable<string, array{string|null, string|null, string, string}> */
    public static function dates(): iterable
    {
        yield 'open agreement' => [null, null, '2026-06-15 12:00:00', '2026-06-15 12:00:00'];
        yield 'ended, no successor' => ['2026-05-31 17:00:00', null, '2026-05-31 00:00:00', '2026-06-15 12:00:00'];
        yield 'scheduled end after today' => ['2026-06-30', null, '2026-06-15 12:00:00', '2026-06-15 12:00:00'];
        yield 'handover after the end' => ['2026-05-31', '2026-06-10 17:00:00', '2026-05-31 00:00:00', '2026-06-09 00:00:00'];
        yield 'handover before the end' => ['2026-06-30', '2026-06-10', '2026-06-09 00:00:00', '2026-06-09 00:00:00'];
        yield 'future successor, no end' => [null, '2026-07-01', '2026-06-15 12:00:00', '2026-06-15 12:00:00'];
        yield 'successor starts today' => [null, '2026-06-15', '2026-06-14 00:00:00', '2026-06-14 00:00:00'];
    }

    #[DataProvider('dates')]
    public function test_capacity_stops_at_the_end_while_settlement_follows_work_ownership(?string $endsOn, ?string $successorStartsOn, string $capacity, string $settlement): void
    {
        $through = CarbonImmutable::parse('2026-06-15 12:00:00');
        $end = $endsOn === null ? null : CarbonImmutable::parse($endsOn);
        $successor = $successorStartsOn === null ? null : CarbonImmutable::parse($successorStartsOn);
        $dates = DeferredWorkDates::through($through, $end, $successor);

        $this->assertSame($capacity, $dates->capacityThrough->toDateTimeString());
        $this->assertSame($settlement, $dates->settlementThrough->toDateTimeString());
        $this->assertSame('2026-06-15 12:00:00', $through->toDateTimeString());
        $this->assertSame($endsOn === null ? null : CarbonImmutable::parse($endsOn)->toDateTimeString(), $end?->toDateTimeString());
        $this->assertSame($successorStartsOn === null ? null : CarbonImmutable::parse($successorStartsOn)->toDateTimeString(), $successor?->toDateTimeString());
    }
}
