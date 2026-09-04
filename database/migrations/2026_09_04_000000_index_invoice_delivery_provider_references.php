<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_invoice_email_deliveries', function (Blueprint $table): void {
            $table->index('provider_message_reference', 'invoice_delivery_provider_reference_idx');
        });
    }

    public function down(): void
    {
        Schema::table('client_invoice_email_deliveries', function (Blueprint $table): void {
            $table->dropIndex('invoice_delivery_provider_reference_idx');
        });
    }
};
