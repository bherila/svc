<?php

namespace Tests\Unit\Billing;

use App\Models\ClientAgreement;
use App\Models\ClientTimeEntry;
use App\Support\Billing\DeferredBuyDownRequest;
use App\Support\Billing\DeferredBuyDownSelection;
use App\Support\Billing\InvoiceKind;
use DomainException;
use Tests\TestCase;

/** No database: target arithmetic must preserve termination's grouped rounding. */
final class DeferredBuyDownSelectionTest extends TestCase
{
    public function test_amount_targets_round_the_canonical_group_total_instead_of_each_entry(): void
    {
        $entries = [$this->entry(1, 1), $this->entry(2, 1)];
        $selection = DeferredBuyDownSelection::select($this->agreement(), $entries, DeferredBuyDownRequest::from(['amount' => 333]));
        $this->assertSame([1, 2], array_map(fn ($entry): int => $entry->id, $selection->entries));
        $this->assertSame(2, $selection->minutes);
        $this->assertSame(333, $selection->total());
        $under = DeferredBuyDownSelection::select($this->agreement(), $entries, DeferredBuyDownRequest::from(['amount' => 332]));
        $this->assertCount(1, $under->entries);
        $this->assertSame(167, $under->total());
        $rounded = DeferredBuyDownSelection::select($this->agreement(), $entries, DeferredBuyDownRequest::from(['amount' => 332, 'round_up' => true]));
        $this->assertSame(333, $rounded->total());
        $this->assertCount(2, $rounded->entries);
    }

    public function test_flat_hourly_groups_keep_person_project_and_rate_boundaries_and_direct_work_stays_out(): void
    {
        $entries = [$this->entry(1, 1, ['subcontractor_billing_mode' => 'flat_hourly', 'subcontractor_cost_amount' => 5001, 'subcontractor_cost_currency' => 'USD']),
            $this->entry(2, 1, ['user_id' => 2, 'subcontractor_billing_mode' => 'flat_hourly', 'subcontractor_cost_amount' => 5001, 'subcontractor_cost_currency' => 'USD']),
            $this->entry(3, 60, ['subcontractor_billing_mode' => 'direct'])];
        $selection = DeferredBuyDownSelection::select($this->agreement(), $entries, DeferredBuyDownRequest::from(['amount' => 166]));
        $this->assertCount(2, $selection->entries);
        $this->assertSame(2, $selection->minutes);
        $this->assertSame(166, $selection->total());
        $all = DeferredBuyDownSelection::select($this->agreement(), $entries, DeferredBuyDownRequest::from(['hours' => 100]));
        $this->assertCount(2, $all->entries);
        $this->assertSame(166, $all->total());
    }

    public function test_skipping_a_large_entry_does_not_add_its_minutes_to_the_price_and_discount_keeps_the_rate(): void
    {
        $entries = [$this->entry(1, 180), $this->entry(2, 60, ['subcontractor_billing_mode' => 'retainer'])];
        $selection = DeferredBuyDownSelection::select($this->agreement(), $entries,
            DeferredBuyDownRequest::from(['hours' => 1, 'discount_amount' => 1, 'discount_reason' => 'Synthetic rounding discount']));
        $this->assertSame([2], array_map(fn ($entry): int => $entry->id, $selection->entries));
        $this->assertSame(10001, $selection->pricing->agreementRateUnitAmount);
        $this->assertSame(10000, $selection->total());
        $empty = DeferredBuyDownSelection::select($this->agreement(), [$entries[0]], DeferredBuyDownRequest::from(['hours' => 1]));
        $this->assertSame([], $empty->entries);
        $this->assertSame(0, $empty->minutes);
        $this->assertSame(0, $empty->total());
        $rounded = DeferredBuyDownSelection::select($this->agreement(), $entries, DeferredBuyDownRequest::from(['hours' => 1, 'round_up' => true]));
        $this->assertSame([1], array_map(fn ($entry): int => $entry->id, $rounded->entries));
        $this->assertSame(30003, $rounded->total());
    }

    public function test_nonpositive_minutes_are_refused(): void
    {
        $this->expectException(DomainException::class);
        DeferredBuyDownSelection::select($this->agreement(), [$this->entry(1, 0)], DeferredBuyDownRequest::from(['hours' => 1]));
    }

    public function test_the_settlement_kind_preserves_history_without_claiming_a_cadence_cycle(): void
    {
        $kind = InvoiceKind::DeferredBuyDown;
        $this->assertSame('deferred_buydown', $kind->value);
        $this->assertTrue($kind->requiresCompleteServicePeriod());
        $this->assertContains($kind->value, InvoiceKind::cycleGuardExclusions());
        $this->assertNotContains($kind->value, InvoiceKind::matchedByCycle());
        $this->assertEqualsCanonicalizing(['ad_hoc', 'deferred_buydown'], InvoiceKind::preservedHistoryValues());
    }

    private function agreement(): ClientAgreement
    {
        return (new ClientAgreement)->setRawAttributes(['id' => 1, 'currency' => 'USD', 'hourly_rate_amount' => 10001], true);
    }

    /** @param array<string, mixed> $overrides */
    private function entry(int $id, int $minutes, array $overrides = []): ClientTimeEntry
    {
        return (new ClientTimeEntry)->setRawAttributes($overrides + ['id' => $id, 'minutes' => $minutes,
            'user_id' => 1, 'client_project_id' => 1, 'subcontractor_billing_mode' => null,
            'subcontractor_cost_amount' => null, 'subcontractor_cost_currency' => null], true);
    }
}
