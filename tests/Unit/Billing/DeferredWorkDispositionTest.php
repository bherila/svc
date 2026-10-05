<?php

namespace Tests\Unit\Billing;

use App\Support\Billing\DeferredWorkDisposition;
use App\Support\Billing\InvoiceLineType;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class DeferredWorkDispositionTest extends TestCase
{
    /** @return iterable<string, array{?string, DeferredWorkDisposition, bool, bool}> */
    public static function allocations(): iterable
    {
        yield 'waiting' => [null, DeferredWorkDisposition::Waiting, false, false];
        yield 'termination' => ['additional_hours', DeferredWorkDisposition::BilledOnTermination, true, false];
        yield 'buy-down' => ['deferred_buydown', DeferredWorkDisposition::SettledOutsideRetainer, false, false];
        foreach ([
            'retainer', 'prior_month_retainer', 'prior_month_billable', 'credit', 'expense', 'milestone',
            'adjustment', 'recurring_item', 'reconciliation', 'subcontractor', 'carried_deferred_applied',
            'carried_deferred_billed', 'legacy_allocation',
        ] as $type) {
            yield $type => [$type, DeferredWorkDisposition::RetainerApplied, true, true];
        }
    }

    #[DataProvider('allocations')]
    public function test_the_disposition_decides_capacity_without_a_database(?string $type, DeferredWorkDisposition $expected, bool $usesCapacity, bool $drawsAsDeferred): void
    {
        $actual = DeferredWorkDisposition::fromAllocation($type);
        $this->assertSame($expected, $actual);
        $this->assertSame($usesCapacity, $actual->usesCapacity());
        $this->assertSame($drawsAsDeferred, $actual->drawsAsDeferred());
    }

    public function test_query_exclusions_come_from_the_same_disposition_as_loaded_entries(): void
    {
        $this->assertSame(['deferred_buydown'], InvoiceLineType::settledOutsideRetainerValues());
        $this->assertContains('deferred_buydown', InvoiceLineType::systemOnlyValues());
        $this->assertNotContains('deferred_buydown', InvoiceLineType::systemGeneratedValues(), 'A cadence rebuild cannot release an independent settlement');
    }
}
