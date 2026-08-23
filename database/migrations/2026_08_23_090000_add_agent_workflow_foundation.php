<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table): void {
            $table->string('timezone', 64)->default('UTC')->after('slug');
            $table->char('default_currency', 3)->default('USD')->after('timezone');
        });

        Schema::create('client_project_memberships', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 24);
            $table->timestamps();
            $table->unique(['client_project_id', 'user_id']);
            $table->index(['workspace_id', 'user_id']);
        });

        foreach (['client_projects', 'client_tasks', 'client_time_entries', 'client_invoices'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->unsignedInteger('lock_version')->default(1)->after('updated_at');
            });
        }

        Schema::table('client_time_entries', function (Blueprint $table): void {
            $table->boolean('is_visible_to_client')->default(false)->after('is_deferred');
            $table->text('client_visible_description')->nullable()->after('description');
            $table->softDeletes();
            $table->index(['workspace_id', 'deleted_at']);
        });
    }

    public function down(): void
    {
        Schema::table('client_time_entries', function (Blueprint $table): void {
            $table->dropIndex(['workspace_id', 'deleted_at']);
            $table->dropSoftDeletes();
            $table->dropColumn(['is_visible_to_client', 'client_visible_description']);
        });

        foreach (['client_projects', 'client_tasks', 'client_time_entries', 'client_invoices'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropColumn('lock_version');
            });
        }

        Schema::dropIfExists('client_project_memberships');

        Schema::table('workspaces', function (Blueprint $table): void {
            $table->dropColumn(['timezone', 'default_currency']);
        });
    }
};
