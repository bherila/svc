<?php

namespace Tests\Unit\Accounting;

use App\Support\Accounting\CashEntries;
use App\Support\Accounting\CashEntryType;
use App\Support\Accounting\LineEntryType;
use App\Support\Accounting\LocalDays;
use App\Support\Accounting\StoredValueLabel;
use App\Support\Finance\ProcessorMovementKind;
use PHPUnit\Framework\TestCase;

final class LabelsAndDaysTest extends TestCase
{
    public function test_a_stored_token_reads_as_words(): void
    {
        $this->assertSame('Partially paid', StoredValueLabel::of('partially_paid'));
        $this->assertSame('Prior month retainer', StoredValueLabel::of('prior_month_retainer'));
        $this->assertSame('One time', StoredValueLabel::of('one-time'));
        $this->assertSame('Credit', StoredValueLabel::of('credit'));
        $this->assertSame('A b', StoredValueLabel::of('a__b'));
        $this->assertSame('—', StoredValueLabel::of(''));
        $this->assertSame('—', StoredValueLabel::of(' _ '));
    }

    public function test_entry_types_have_labels_and_the_ranks_of_their_sources(): void
    {
        $this->assertSame(['invoice_line', 'correction', 'void'], LineEntryType::values());
        $this->assertSame(['Invoice line', 'Correction', 'Void'], array_map(static fn (LineEntryType $type): string => $type->label(), LineEntryType::cases()));
        $this->assertSame([1, 2, 3], array_map(static fn (LineEntryType $type): int => $type->rank(), LineEntryType::cases()));
        $this->assertSame(['payment', 'refund', 'refund_reversal', 'dispute_debit', 'dispute_credit', 'fee', 'fee_reversal'], CashEntryType::values());
        $this->assertSame('Refund reversal', CashEntryType::RefundReversal->label());
        $this->assertSame('Dispute debit', CashEntryType::DisputeDebit->label());
    }

    public function test_every_processor_movement_kind_is_a_cash_entry_type_of_the_same_name(): void
    {
        foreach (ProcessorMovementKind::cases() as $kind) {
            $this->assertSame($kind->value, CashEntryType::ofMovement($kind)->value);
        }
        $this->assertSame(['succeeded', 'refunded', 'disputed'], CashEntries::receivedStatuses());
    }

    public function test_a_window_of_days_is_the_instants_between_local_midnights(): void
    {
        // New York is five hours behind UTC in January.
        $this->assertSame(['2030-01-01 05:00:00', '2030-02-01 05:00:00'], LocalDays::instants('2030-01-01', '2030-01-31', 'America/New_York'));
        $this->assertSame(['2030-01-01 00:00:00', '2030-02-01 00:00:00'], LocalDays::instants('2030-01-01', '2030-01-31', 'UTC'));
        // Across the March change of clocks the day after the last day starts four hours ahead of UTC.
        $this->assertSame(['2030-03-09 05:00:00', '2030-03-11 04:00:00'], LocalDays::instants('2030-03-09', '2030-03-10', 'America/New_York'));
        $this->assertSame([null, '2030-02-01 00:00:00'], LocalDays::instants(null, '2030-01-31', 'UTC'));
        $this->assertSame(['2030-01-01 00:00:00', null], LocalDays::instants('2030-01-01', null, 'UTC'));
    }
}
