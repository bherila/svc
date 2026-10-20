<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_expenses', function (Blueprint $table): void {
            $table->uuid('external_finance_transaction_uuid')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('client_expenses', function (Blueprint $table): void {
            $table->dropColumn('external_finance_transaction_uuid');
        });
    }
};
