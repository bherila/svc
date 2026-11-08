<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_stripe_customers', function (Blueprint $table): void {
            // A customer's default method is whatever Stripe says it is now, read
            // when a customer event arrives. Event time and id never ordered that
            // (one-second precision, no delivery order), so they are gone. This
            // counter moves on every write of the default; a read made before the
            // counter moved is discarded and the event retried.
            $table->unsignedBigInteger('default_payment_method_revision')->default(0);
            $table->dropColumn(['default_payment_method_event_created_at', 'default_payment_method_event_id']);
        });
    }

    public function down(): void
    {
        Schema::table('client_stripe_customers', function (Blueprint $table): void {
            $table->unsignedBigInteger('default_payment_method_event_created_at')->nullable();
            $table->string('default_payment_method_event_id', 255)->nullable();
            $table->dropColumn('default_payment_method_revision');
        });
    }
};
