<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An append-only trail of workspace access changes: staff granted, re-roled
 * or removed, project assignments removed with them, and portal access
 * granted, re-scoped or revoked.
 *
 * Client activity cannot hold these. It is keyed by a client company and a
 * company-owned subject, and a staff membership belongs to the workspace, not
 * to any one client.
 *
 * Ids only. People are referenced by user id and nulled, not cascaded, when
 * the account is deleted, so the trail outlives them; projects and companies
 * are named by public id inside the event so a later deletion does not erase
 * what was changed. Nothing here stores a name or an email address.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access_audit_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('actor_type', 16);
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('subject_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 64);
            $table->uuid('client_company_public_id')->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->string('operation_id', 64)->nullable();
            $table->string('request_id', 128)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['workspace_id', 'created_at']);
            $table->index(['workspace_id', 'operation_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_audit_events');
    }
};
