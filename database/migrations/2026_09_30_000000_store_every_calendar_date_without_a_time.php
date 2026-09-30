<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Every DATE column Eloquent wrote through a `date` cast stores `Y-m-d 00:00:00`
 * on SQLite, and a date-string bound leaves those rows out on the last day of
 * a range (#362, after #354 did `worked_on`). MariaDB's `DATE` column never
 * held a time, so this changes nothing there.
 */
return new class extends Migration
{
    /** @var array<string, list<string>> */
    private const COLUMNS = [
        'client_agreements' => ['starts_on', 'ends_on'],
        'client_invoices' => ['issue_date', 'due_date', 'service_period_start', 'service_period_end', 'cycle_start', 'cycle_end', 'paid_on'],
        'client_proposals' => ['valid_until'],
        'payment_reconciliations' => ['reconciled_on'],
        'client_invoice_lines' => ['line_date'],
        'client_expense_schedules' => ['starts_on'],
        'client_agreement_recurring_items' => ['effective_on', 'expires_on'],
        'client_invoice_payments' => ['received_on'],
        'client_billing_schedules' => ['next_run_on'],
        'client_expenses' => ['spent_on'],
    ];

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            return;
        }

        foreach (self::COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                DB::table($table)
                    ->whereRaw("length({$column}) > 10")
                    ->update([$column => DB::raw("substr({$column}, 1, 10)")]);
            }
        }
    }

    public function down(): void
    {
        // The time was always midnight and meant nothing; there is nothing to restore.
    }
};
