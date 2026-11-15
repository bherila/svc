<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a generated cadence draft was priced from (#718, review finding B4):
 * the agreement's project scope, the last day its ledger read, and a digest of
 * every ledger input - the scope's time with its allocations, the agreement's
 * other live invoices and their re-carried deferred lines. Issuing re-reads
 * them with row locks and refuses a draft priced from inputs that have moved.
 *
 * Null for invoices generated before this column and for kinds that are not
 * cadence drafts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_invoices', function (Blueprint $table): void {
            $table->json('pricing_basis')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('client_invoices', function (Blueprint $table): void {
            $table->dropColumn('pricing_basis');
        });
    }
};
