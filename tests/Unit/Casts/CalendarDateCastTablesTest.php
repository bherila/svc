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
 * The calendar-date casts of every model #362 moved to `DateOnly`, pinned.
 *
 * A cast decides how a column is stored, so changing one is a storage-format
 * change: this test is where that shows up. Only the date entries are pinned,
 * so an unrelated cast change does not fail here.
 */
final class CalendarDateCastTablesTest extends TestCase
{
    /** @param  array<string, string>  $expected */
    #[DataProvider('tables')]
    public function test_the_calendar_dates_are_cast_as_pinned(string $model, array $expected): void
    {
        /** @var Model $instance */
        $instance = new $model;

        $this->assertSame($expected, array_intersect_key($instance->getCasts(), $expected));
    }

    /** @return iterable<string, array{class-string<Model>, array<string, string>}> */
    public static function tables(): iterable
    {
        $tables = [
            ClientAgreement::class => [
                'starts_on' => DateOnly::class,
                'ends_on' => DateOnly::class,
            ],
            ClientInvoice::class => [
                'issue_date' => DateOnly::class,
                'due_date' => DateOnly::class,
                'service_period_start' => DateOnly::class,
                'service_period_end' => DateOnly::class,
                'cycle_start' => DateOnly::class,
                'cycle_end' => DateOnly::class,
                'paid_on' => DateOnly::class,
            ],
            ClientProposal::class => [
                'valid_until' => DateOnly::class,
            ],
            PaymentReconciliation::class => [
                'reconciled_on' => DateOnly::class,
            ],
            ClientInvoiceLine::class => [
                'line_date' => DateOnly::class,
            ],
            ClientExpenseSchedule::class => [
                'starts_on' => DateOnly::class,
            ],
            ClientAgreementRecurringItem::class => [
                'effective_on' => DateOnly::class,
                'expires_on' => DateOnly::class,
            ],
            ClientInvoicePayment::class => [
                'received_on' => DateOnly::class,
            ],
            ClientBillingSchedule::class => [
                'next_run_on' => DateOnly::class,
            ],
            ClientExpense::class => [
                'spent_on' => DateOnly::class,
            ],
        ];

        foreach ($tables as $model => $casts) {
            yield class_basename($model) => [$model, $casts];
        }
    }
}
