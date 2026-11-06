<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspace_payment_connections', function (Blueprint $table): void {
            // Stripe `pk_live_...` / `pk_test_...`: the key the browser confirms
            // a payment with. Not a secret, so it is stored as typed. Null means
            // the connection offers no card payment.
            $table->string('publishable_key', 255)->nullable()->after('secret_hint');
        });
    }

    public function down(): void
    {
        Schema::table('workspace_payment_connections', function (Blueprint $table): void {
            $table->dropColumn('publishable_key');
        });
    }
};
