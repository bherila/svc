<?php

namespace Tests\Unit\Casts;

use App\Casts\DateOnly;
use App\Models\ClientAgreement;
use App\Models\ClientAgreementRecurringItem;
use App\Models\ClientBillingSchedule;
use App\Models\ClientExpense;
use App\Models\ClientExpenseSchedule;
use App\Models\ClientInvoice;
use App\Models\ClientInvoiceLine;
use App\Models\ClientInvoicePayment;
use App\Models\ClientProposal;
use App\Models\PaymentReconciliation;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The cast tables of every model #362 moved to `DateOnly`, pinned.
 *
 * A cast decides how a column is stored, so changing one is a storage-format
 * change: this test is where that shows up. The whole table is pinned, not
 * only the date entries: the diff-scoped mutation lane mutates a cast array
 * as a whole once any line of it changes, and only a whole-table assertion
 * notices an entry removed from it.
 */
final class CalendarDateCastTablesTest extends TestCase
{
    /** @param  array<string, string>  $expected */
    #[DataProvider('tables')]
    public function test_the_cast_table_is_as_pinned(string $model, array $expected): void
    {
        /** @var Model $instance */
        $instance = new $model;

        $this->assertSame($expected, $instance->getCasts());
    }

    /** @return iterable<string, array{class-string<Model>, array<string, string>}> */
    public static function tables(): iterable
    {
        $tables = [
            ClientAgreement::class => [
                'id' => 'int',
                'starts_on' => DateOnly::class,
                'ends_on' => DateOnly::class,
                'is_visible_to_client' => 'boolean',
                'hourly_rate_amount' => 'integer',
                'retainer_amount' => 'integer',
                'retainer_minutes' => 'integer',
                'catch_up_threshold_minutes' => 'integer',
                'period_retainer_minutes' => 'integer',
                'period_retainer_amount' => 'integer',
                'rollover_months' => 'integer',
                'initial_rollover_minutes' => 'integer',
                'bill_overage_interim' => 'boolean',
                'activated_at' => 'immutable_datetime',
                'signed_at' => 'immutable_datetime',
                'terminated_at' => 'immutable_datetime',
            ],
            ClientInvoice::class => [
                'id' => 'int',
                'issue_date' => DateOnly::class,
                'due_date' => DateOnly::class,
                'service_period_start' => DateOnly::class,
                'service_period_end' => DateOnly::class,
                'cycle_start' => DateOnly::class,
                'cycle_end' => DateOnly::class,
                'paid_on' => DateOnly::class,
                'retainer_hours_included' => 'decimal:4',
                'hours_worked' => 'decimal:4',
                'rollover_hours_used' => 'decimal:4',
                'unused_hours_balance' => 'decimal:4',
                'negative_hours_balance' => 'decimal:4',
                'hours_billed_at_rate' => 'decimal:4',
                'starting_unused_hours' => 'decimal:4',
                'starting_negative_hours' => 'decimal:4',
                'hours_statement' => 'array',
                'catch_up_basis' => 'array',
                'issued_at' => 'datetime',
                'voided_at' => 'datetime',
                'automatic_delivery_due_at' => 'datetime',
                'automatic_delivery_held_at' => 'datetime',
                'document_revision' => 'integer',
                'automatic_delivery_delay_days' => 'integer',
                'is_visible_to_client' => 'boolean',
                'subtotal_amount' => 'integer',
                'tax_amount' => 'integer',
                'total_amount' => 'integer',
                'paid_amount' => 'integer',
                'balance_amount' => 'integer',
            ],
            ClientProposal::class => [
                'id' => 'int',
                'is_visible_to_client' => 'boolean',
                'valid_until' => DateOnly::class,
                'sent_at' => 'immutable_datetime',
                'accepted_at' => 'immutable_datetime',
                'declined_at' => 'immutable_datetime',
                'expired_at' => 'immutable_datetime',
            ],
            PaymentReconciliation::class => [
                'id' => 'int',
                'allocated_amount' => 'integer',
                'reconciled_on' => DateOnly::class,
                'is_active' => 'boolean',
            ],
            ClientInvoiceLine::class => [
                'id' => 'int',
                'quantity' => 'decimal:4',
                'hours' => 'decimal:4',
                'line_date' => DateOnly::class,
                'unit_amount' => 'integer',
                'tax_amount' => 'integer',
                'total_amount' => 'integer',
                'sort_order' => 'integer',
            ],
            ClientExpenseSchedule::class => [
                'id' => 'int',
                'starts_on' => DateOnly::class,
                'next_occurrence' => 'integer',
                'amount' => 'integer',
                'is_active' => 'boolean',
            ],
            ClientAgreementRecurringItem::class => [
                'id' => 'int',
                'anchor_month' => 'integer',
                'anchor_day' => 'integer',
                'effective_on' => DateOnly::class,
                'expires_on' => DateOnly::class,
                'quantity' => 'decimal:3',
                'amount' => 'integer',
                'is_taxable' => 'boolean',
                'is_active' => 'boolean',
                'sort_order' => 'integer',
            ],
            ClientInvoicePayment::class => [
                'id' => 'int',
                'amount' => 'integer',
                'refunded_amount' => 'integer',
                'received_on' => DateOnly::class,
                'provider_event_created_at' => 'integer',
            ],
            ClientBillingSchedule::class => [
                'id' => 'int',
                'next_run_on' => DateOnly::class,
                'anchor_month' => 'integer',
                'anchor_day' => 'integer',
                'due_days' => 'integer',
                'is_active' => 'boolean',
                'line_template' => 'array',
            ],
            ClientExpense::class => [
                'id' => 'int',
                'deleted_at' => 'datetime',
                'spent_on' => DateOnly::class,
                'amount' => 'integer',
                'approved_at' => 'immutable_datetime',
            ],
        ];

        foreach ($tables as $model => $casts) {
            yield class_basename($model) => [$model, $casts];
        }
    }
}
