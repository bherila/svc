<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_mutation_receipts', function (Blueprint $table): void {
            $table->json('response_snapshot')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('agent_mutation_receipts', function (Blueprint $table): void {
            $table->dropColumn('response_snapshot');
        });
    }
};
