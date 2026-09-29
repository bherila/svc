<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The catch-up a monthly invoice was sized against when it was generated: each
 * earlier invoice of its agreement whose charge the capacity ledger counted -
 * issued ones from the billed-overage ledger, drafts from the overlay - and the
 * hours each charged. `issue()` re-measures it and refuses an invoice whose
 * earlier charges have moved since, and `void()` refuses an issued invoice a
 * live later one recorded here (`App\Support\Billing\CatchUpBasis`).
 *
 * Expand-only. Null for every invoice generated before it existed and for
 * every kind the monthly generator does not write, which issue and void as
 * they did before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_invoices', function (Blueprint $table): void {
            $table->json('catch_up_basis')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('client_invoices', function (Blueprint $table): void {
            $table->dropColumn('catch_up_basis');
        });
    }
};
