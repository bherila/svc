<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The two ledger columns the first restore pass missed.
 *
 * `unused_hours_balance` and `negative_hours_balance` record where a cycle
 * *ended*. These record where it *opened*, after catch-up billing has paid down
 * any prior debt. The generator writes both on every non-monthly invoice and
 * reads neither, so their absence is invisible until someone asks an invoice
 * what balance it inherited - which is exactly what the historical ledger is
 * for.
 *
 * Separate from the first restore migration because that one is already under
 * review; this is additive and carries its own backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_invoices', function (Blueprint $table): void {
            $table->decimal('starting_unused_hours', 12, 4)->nullable()->after('negative_hours_balance');
            $table->decimal('starting_negative_hours', 12, 4)->nullable()->after('starting_unused_hours');
        });
    }

    public function down(): void
    {
        Schema::table('client_invoices', function (Blueprint $table): void {
            $table->dropColumn(['starting_unused_hours', 'starting_negative_hours']);
        });
    }
};
