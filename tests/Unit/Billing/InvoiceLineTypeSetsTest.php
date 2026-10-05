<?php

namespace Tests\Unit\Billing;

use App\Support\Billing\InvoiceLineType;
use Tests\TestCase;

/** The line-type sets, spelled out: each one decides what a rebuild or a period reads. */
final class InvoiceLineTypeSetsTest extends TestCase
{
    public function test_the_system_generated_set_is_what_a_draft_rebuild_releases(): void
    {
        $this->assertSame([
            'retainer', 'prior_month_retainer', 'prior_month_billable', 'additional_hours', 'credit', 'milestone',
            'recurring_item', 'reconciliation', 'subcontractor', 'carried_deferred_applied', 'carried_deferred_billed',
        ], InvoiceLineType::systemGeneratedValues());
    }

    public function test_the_work_period_set_is_what_dates_the_work_reconciled(): void
    {
        $this->assertSame([
            'prior_month_retainer', 'prior_month_billable', 'additional_hours', 'milestone', 'expense', 'subcontractor',
            'reconciliation', 'adjustment', 'carried_deferred_applied', 'carried_deferred_billed', 'deferred_buydown',
        ], InvoiceLineType::definingTheWorkPeriod());
        $this->assertSame(['retainer', 'credit', 'recurring_item'], InvoiceLineType::billedInAdvance());
    }
}
