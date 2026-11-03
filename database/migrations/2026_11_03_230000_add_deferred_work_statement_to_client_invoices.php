<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_invoices', function (Blueprint $table): void {
            $table->json('deferred_work_statement')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('client_invoices', function (Blueprint $table): void {
            $table->dropColumn('deferred_work_statement');
        });
    }
};
