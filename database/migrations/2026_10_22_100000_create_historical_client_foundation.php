<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_companies', function (Blueprint $table): void {
            $table->text('billing_address')->nullable();
            $table->string('phone', 191)->nullable();
            $table->string('website_url', 191)->nullable();
            $table->string('tax_id', 191)->nullable();
            $table->char('currency', 3)->nullable();
            $table->text('notes')->nullable();
        });

        Schema::create('client_contacts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('client_company_id');
            $table->string('first_name', 100)->nullable();
            $table->string('last_name', 100)->nullable();
            $table->string('title', 191)->nullable();
            $table->string('email', 255)->nullable();
            $table->string('phone', 191)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_archived')->default(false);
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();
            $table->unique(['workspace_id', 'id'], 'contacts_ws_id_unique');
            $table->foreign(['workspace_id', 'client_company_id'], 'contacts_ws_company_fk')
                ->references(['workspace_id', 'id'])->on('client_companies')->restrictOnDelete();
        });

        Schema::create('historical_sources', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('system', 50);
            $table->char('identity_hash', 64);
            $table->string('label', 160);
            $table->timestamps();
            $table->unique(['workspace_id', 'id'], 'historical_sources_ws_id_unique');
            $table->unique(['workspace_id', 'system', 'identity_hash'], 'historical_sources_identity_unique');
        });

        Schema::create('historical_records', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('historical_source_id');
            $table->string('source_table', 100);
            $table->string('source_key', 191);
            $table->char('fingerprint', 64);
            $table->json('snapshot');
            $table->string('target_type', 50)->nullable();
            $table->uuid('target_public_id')->nullable();
            $table->timestamps();
            $table->unique(['workspace_id', 'historical_source_id', 'source_table', 'source_key'], 'historical_records_source_unique');
            $table->index(['workspace_id', 'target_type', 'target_public_id'], 'historical_records_target_index');
            $table->foreign(['workspace_id', 'historical_source_id'], 'historical_records_ws_source_fk')
                ->references(['workspace_id', 'id'])->on('historical_sources')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('historical_records');
        Schema::dropIfExists('historical_sources');
        Schema::dropIfExists('client_contacts');
        Schema::table('client_companies', function (Blueprint $table): void {
            $table->dropColumn(['billing_address', 'phone', 'website_url', 'tax_id', 'currency', 'notes']);
        });
    }
};
