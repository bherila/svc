<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legacy_migration_runs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('source_connection', 100);
            $table->string('source_identity_hash', 64);
            $table->string('mode', 20);
            $table->string('status', 40);
            $table->json('source_high_water_marks')->nullable();
            $table->json('counts')->nullable();
            $table->json('fingerprints')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['workspace_id', 'created_at']);
            $table->index(['source_connection', 'source_identity_hash']);
        });

        Schema::create('legacy_migration_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('legacy_migration_run_id')->constrained()->cascadeOnDelete();
            $table->string('source_connection', 100);
            $table->string('source_identity_hash', 64);
            $table->string('source_table', 191);
            $table->string('source_key', 191);
            $table->string('target_type', 191);
            $table->uuid('target_public_id')->nullable();
            $table->string('source_fingerprint', 64);
            $table->string('status', 40);
            $table->string('reason_code', 100)->nullable();
            $table->timestamps();
            $table->unique(['source_identity_hash', 'source_table', 'source_key'], 'legacy_source_identity_unique');
            $table->index(['target_type', 'target_public_id']);
            $table->index(['legacy_migration_run_id', 'status']);
        });

        Schema::create('legacy_migration_run_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('legacy_migration_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('legacy_migration_item_id')->constrained()->cascadeOnDelete();
            $table->string('observed_status', 40);
            $table->string('source_fingerprint', 64);
            $table->timestamps();
            $table->unique(['legacy_migration_run_id', 'legacy_migration_item_id'], 'legacy_run_item_unique');
            $table->index(['legacy_migration_run_id', 'observed_status']);
        });

        Schema::create('legacy_migration_failures', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('legacy_migration_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('legacy_migration_item_id')->nullable()->constrained('legacy_migration_items')->nullOnDelete();
            $table->string('source_connection', 100);
            $table->string('source_table', 191);
            $table->string('source_key_hash', 64);
            $table->string('reason_code', 100);
            $table->json('redacted_context')->nullable();
            $table->string('failure_fingerprint', 64);
            $table->timestamps();
            $table->index(['legacy_migration_run_id', 'reason_code']);
            $table->unique(['legacy_migration_run_id', 'source_table', 'source_key_hash', 'reason_code'], 'legacy_failure_identity_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legacy_migration_failures');
        Schema::dropIfExists('legacy_migration_run_items');
        Schema::dropIfExists('legacy_migration_items');
        Schema::dropIfExists('legacy_migration_runs');
    }
};
