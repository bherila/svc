<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a payment processor reports about settling a payment: when it charged
 * it, what it kept as its fee, and which payout carried the rest to the bank
 * and when that payout arrives.
 *
 * - `provider_charged_at`: the processor's own charge time. Not
 *   `received_on`, which for Stripe is the day the webhook arrived, and not
 *   `provider_event_created_at`, which every later event overwrites.
 * - `provider_fee_amount`: the processor's fee in minor units of the payment's
 *   currency, positive for a fee charged. Signed, since an adjustment can
 *   return part of one.
 * - `provider_payout_identifier` and `provider_payout_arrival_date`: the payout
 *   the payment settled in and the day that payout arrives at the bank.
 *
 * All nullable: a payment recorded by hand has none of them, and a processor
 * payment has them only once the processor reports them. Existing rows are
 * filled by `svc:finance:backfill-payouts`, not here.
 *
 * The payout identifier is opaque and compared exactly, as the payment
 * identifier is (`2026_10_14_091000`), and indexed with the workspace and
 * provider so a payout's member payments are found without a scan.
 */
return new class extends Migration
{
    public function up(): void
    {
        $binary = in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true);
        Schema::table('client_invoice_payments', function (Blueprint $table) use ($binary): void {
            $table->timestamp('provider_charged_at')->nullable()->after('provider_dispute_updated_at_ms');
            $table->bigInteger('provider_fee_amount')->nullable()->after('provider_charged_at');
            $payout = $table->string('provider_payout_identifier', 255)->nullable()->after('provider_fee_amount');
            if ($binary) {
                $payout->collation('utf8mb4_bin');
            }
            $table->date('provider_payout_arrival_date')->nullable()->after('provider_payout_identifier');
            $table->index(['workspace_id', 'provider', 'provider_payout_identifier'], 'client_invoice_payments_ws_payout_index');
        });
    }

    public function down(): void
    {
        Schema::table('client_invoice_payments', function (Blueprint $table): void {
            $table->dropIndex('client_invoice_payments_ws_payout_index');
            $table->dropColumn(['provider_charged_at', 'provider_fee_amount', 'provider_payout_identifier', 'provider_payout_arrival_date']);
        });
    }
};
