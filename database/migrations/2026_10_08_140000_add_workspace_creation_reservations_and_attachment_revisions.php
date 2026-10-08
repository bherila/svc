<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_workspace_creations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('oauth_client_id', 100);
            $table->string('idempotency_key', 255);
            $table->char('request_digest', 64);
            $table->foreignId('created_workspace_id')->nullable()->constrained('workspaces')->cascadeOnDelete();
            $table->unique(['user_id', 'oauth_client_id', 'idempotency_key'], 'agent_workspace_creation_actor_key');
        });
        Schema::table('client_attachments', function (Blueprint $table): void {
            $table->unsignedInteger('lock_version')->default(1);
        });
    }

    public function down(): void
    {
        Schema::table('client_attachments', function (Blueprint $table): void {
            $table->dropColumn('lock_version');
        });
        Schema::dropIfExists('agent_workspace_creations');
    }
};
