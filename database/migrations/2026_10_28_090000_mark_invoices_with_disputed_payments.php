<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Freeze the historical vocabulary here. Draft, void, and unknown
        // invoice states must never be rewritten by a payment backfill.
        DB::table('client_invoices')
            ->whereIn('status', ['issued', 'partially_paid', 'paid'])
            ->whereExists(fn (Builder $payments): Builder => $payments
                ->selectRaw('1')->from('client_invoice_payments')
                ->whereColumn('client_invoice_payments.workspace_id', 'client_invoices.workspace_id')
                ->whereColumn('client_invoice_payments.client_invoice_id', 'client_invoices.id')
                ->where('client_invoice_payments.status', 'disputed'))
            ->update(['status' => 'disputed', 'lock_version' => DB::raw('lock_version + 1'), 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('client_invoices')->where('status', 'disputed')->update([
            'status' => DB::raw("CASE WHEN paid_amount >= total_amount THEN 'paid' WHEN paid_amount > 0 THEN 'partially_paid' ELSE 'issued' END"),
            'lock_version' => DB::raw('lock_version + 1'),
            'updated_at' => now(),
        ]);
    }
};
