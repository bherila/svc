<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspace_payment_connections', function (Blueprint $table): void {
            // The last answer to "is Stripe's webhook endpoint subscribed to every
            // event SVC needs?" (see StripeWebhookHealthService). The row is the
            // connection's own, so the result is workspace-scoped like the key.
            // Null until a check has run. Nothing here is secret: a status word,
            // a list of Stripe event names and when the check ran.
            $table->string('webhook_check_status', 24)->nullable()->after('webhook_endpoint_id');
            $table->json('webhook_check_missing')->nullable()->after('webhook_check_status');
            $table->dateTime('webhook_checked_at')->nullable()->after('webhook_check_missing');
        });
    }

    public function down(): void
    {
        Schema::table('workspace_payment_connections', function (Blueprint $table): void {
            $table->dropColumn(['webhook_check_status', 'webhook_check_missing', 'webhook_checked_at']);
        });
    }
};
