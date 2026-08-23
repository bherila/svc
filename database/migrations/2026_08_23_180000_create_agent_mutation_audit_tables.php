<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_mutation_receipts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('oauth_client_id', 100);
            $table->string('operation', 100);
            $table->string('idempotency_key', 255);
            $table->string('request_digest', 64);
            $table->json('result_public_ids');
            $table->timestamps();
            $table->unique(['oauth_client_id', 'user_id', 'operation', 'idempotency_key'], 'agent_mutation_receipts_idempotency_unique');
        });

        Schema::create('agent_mutation_audits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('oauth_client_id', 100);
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('operation', 100);
            $table->json('affected_public_ids');
            $table->uuid('request_id');
            $table->string('outcome', 30);
            $table->timestamps();
            $table->index(['workspace_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_mutation_audits');
        Schema::dropIfExists('agent_mutation_receipts');
    }
};
