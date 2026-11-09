<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a generated cadence draft was priced from, as far as disputes go
 * (docs/ux-overhaul.md 7b″): the agreement's project scope, the last day the
 * ledger read, and a digest of the dispute and settlement state of the
 * company's time through that day. Issuing re-reads that state with row locks
 * and refuses the draft when it has moved, because a dispute on work the draft
 * does not link can still change the capacity its charges were sized against.
 *
 * Null for every invoice generated before this column and for every kind that
 * is not a cadence draft: there is nothing recorded to compare, and issue
 * behaves as it did.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_invoices', function (Blueprint $table): void {
            $table->json('held_work_basis')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('client_invoices', function (Blueprint $table): void {
            $table->dropColumn('held_work_basis');
        });
    }
};
