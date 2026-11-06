<?php

namespace Tests\Unit\Revenue;

use App\Support\Billing\InvoiceLineType;
use App\Support\Revenue\RevenueBand;
use PHPUnit\Framework\TestCase;

/**
 * Every invoice line type is decided explicitly. A new case fails static
 * analysis in RevenueBand's matches and fails here until it is added below.
 */
final class RevenueBandTest extends TestCase
{
    private const array BANDS = [
        'retainer' => RevenueBand::Recurring,
        'prior_month_retainer' => RevenueBand::Recurring,
        'recurring_item' => RevenueBand::Recurring,
        'carried_deferred_applied' => RevenueBand::Recurring,
        'prior_month_billable' => RevenueBand::OverageAndOther,
        'additional_hours' => RevenueBand::OverageAndOther,
        'carried_deferred_billed' => RevenueBand::OverageAndOther,
        'deferred_buydown' => RevenueBand::OverageAndOther,
        'subcontractor' => RevenueBand::OverageAndOther,
        'milestone' => RevenueBand::OverageAndOther,
        'adjustment' => RevenueBand::OverageAndOther,
        'reconciliation' => RevenueBand::OverageAndOther,
        'credit' => RevenueBand::OverageAndOther,
        'expense' => RevenueBand::Expenses,
    ];

    public function test_every_line_type_has_its_decided_band(): void
    {
        $decided = [];
        foreach (InvoiceLineType::cases() as $type) {
            $decided[$type->value] = RevenueBand::forLineType($type);
        }
        ksort($decided);
        $expected = self::BANDS;
        ksort($expected);

        $this->assertSame($expected, $decided);
    }

    public function test_an_unknown_stored_type_still_counts_as_overage_and_other(): void
    {
        $this->assertSame(RevenueBand::OverageAndOther, RevenueBand::forStoredLineType('legacy_import_fee'));
        $this->assertSame(RevenueBand::Expenses, RevenueBand::forStoredLineType('expense'));
    }

    public function test_only_lines_sold_in_advance_are_placed_by_their_own_date(): void
    {
        $this->assertSame(['retainer', 'recurring_item'], RevenueBand::ownDateLineTypeValues());
        // A credit is billed "in advance" by the work-period vocabulary but nets in its invoice's month.
        $this->assertContains(InvoiceLineType::Credit->value, InvoiceLineType::billedInAdvance());
        $this->assertFalse(RevenueBand::placesByOwnDate(InvoiceLineType::Credit));
    }

    public function test_only_hours_billed_at_the_rate_feed_the_overage_projection(): void
    {
        $this->assertSame(['prior_month_billable', 'additional_hours'], RevenueBand::billedOverageLineTypeValues());
        foreach (InvoiceLineType::cases() as $type) {
            if (RevenueBand::countsAsBilledOverage($type)) {
                $this->assertSame(RevenueBand::OverageAndOther, RevenueBand::forLineType($type), $type->value);
            }
        }
    }
}
