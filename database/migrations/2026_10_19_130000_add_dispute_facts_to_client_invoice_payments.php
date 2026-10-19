<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a payment processor's dispute holds back, and how current that report is.
 *
 * `disputed_amount` is set only while a payment is `disputed`: the part of it
 * the processor withheld. Null means the whole payment - every dispute recorded
 * before this column existed, and one whose amount was not reported - so no
 * existing row needs a backfill. `provider_dispute_updated_at_ms` is the
 * processor's own update time (epoch milliseconds) of the dispute last applied,
 * so an older read of the same dispute cannot undo a newer one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_invoice_payments', function (Blueprint $table): void {
            $table->unsignedBigInteger('disputed_amount')->nullable()->after('refunded_amount');
            $table->unsignedBigInteger('provider_dispute_updated_at_ms')->nullable()->after('provider_event_id');
        });
    }

    public function down(): void
    {
        Schema::table('client_invoice_payments', function (Blueprint $table): void {
            $table->dropColumn(['disputed_amount', 'provider_dispute_updated_at_ms']);
        });
    }
};
