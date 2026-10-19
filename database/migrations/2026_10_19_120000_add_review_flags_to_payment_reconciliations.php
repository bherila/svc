<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A processor refund or dispute no longer has to fit a payment's finance
 * reconciliation allocations. The allocations are kept and flagged instead:
 * `review_reason` says why (null: nothing to review), `review_flagged_at` when.
 * Existing rows need no backfill; none has been flagged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_reconciliations', function (Blueprint $table): void {
            $table->string('review_reason', 40)->nullable()->after('is_active');
            $table->timestamp('review_flagged_at')->nullable()->after('review_reason');
        });
    }

    public function down(): void
    {
        Schema::table('payment_reconciliations', function (Blueprint $table): void {
            $table->dropColumn(['review_reason', 'review_flagged_at']);
        });
    }
};
