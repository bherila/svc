<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_mutation_receipts', function (Blueprint $table): void {
            $table->unique(
                ['workspace_id', 'oauth_client_id', 'user_id', 'operation', 'idempotency_key'],
                'agent_mut_receipts_workspace_idempotency_unique',
            );
            $table->dropUnique('agent_mutation_receipts_idempotency_unique');
        });
    }

    public function down(): void
    {
        Schema::table('agent_mutation_receipts', function (Blueprint $table): void {
            $table->unique(
                ['oauth_client_id', 'user_id', 'operation', 'idempotency_key'],
                'agent_mutation_receipts_idempotency_unique',
            );
            $table->dropUnique('agent_mut_receipts_workspace_idempotency_unique');
        });
    }
};
