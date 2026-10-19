<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per payment-processor payout a workspace's payments settled in: what
 * reached (or left) the bank account, when, and whether it did.
 *
 * - `amount` is signed minor units: positive for a deposit, negative for a
 *   withdrawal from the bank account (a payout that covered refunds or
 *   disputes larger than the period's charges).
 * - `type` is `automatic`, `manual` or `instant`; `status` is `pending`,
 *   `in_transit`, `paid`, `failed` or `canceled`.
 * - `provider_updated_at_ms` is how current the stored facts are, in epoch
 *   milliseconds: the processor's event time, or when svc read the payout from
 *   the processor. An older report never overwrites a newer one.
 *
 * Keyed by workspace, provider and the processor's payout id. A processor
 * account serving several workspaces gives each workspace whose payments are
 * in a payout its own row for it. `(workspace_id, updated_at, id)` serves the
 * change feed, as on the payment table.
 */
return new class extends Migration
{
    public function up(): void
    {
        $binary = in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true);
        Schema::create('processor_payouts', function (Blueprint $table) use ($binary): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 24);
            $identifier = $table->string('provider_payout_identifier', 255);
            if ($binary) {
                $identifier->collation('utf8mb4_bin');
            }
            $table->bigInteger('amount');
            $table->char('currency', 3);
            $table->date('arrival_date')->nullable();
            $table->string('type', 16);
            $table->string('status', 16);
            $table->unsignedBigInteger('provider_updated_at_ms');
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();
            $table->unique(['workspace_id', 'provider', 'provider_payout_identifier'], 'processor_payouts_ws_provider_id_unique');
            $table->index(['workspace_id', 'updated_at', 'id'], 'processor_payouts_ws_updated_id_index');
            $table->index(['workspace_id', 'arrival_date', 'id'], 'processor_payouts_ws_arrival_id_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('processor_payouts');
    }
};
