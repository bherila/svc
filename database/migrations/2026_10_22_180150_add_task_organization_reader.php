<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_task_lists', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('client_project_id');
            $table->string('name');
            $table->boolean('is_visible_to_client')->default(false);
            $table->unsignedBigInteger('lock_version')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['workspace_id', 'id'], 'task_lists_ws_id_unique');
            $table->unique(['workspace_id', 'client_project_id', 'id'], 'task_lists_ws_project_id_unique');
            $table->foreign(['workspace_id', 'client_project_id'], 'task_lists_ws_project_fk')
                ->references(['workspace_id', 'id'])->on('client_projects')->cascadeOnDelete();
        });

        Schema::table('client_tasks', function (Blueprint $table): void {
            $table->unsignedBigInteger('parent_task_id')->nullable();
            $table->unsignedBigInteger('client_task_list_id')->nullable();
            $table->unique(['workspace_id', 'client_project_id', 'id'], 'tasks_ws_project_id_unique');
            $table->index(['workspace_id', 'client_project_id', 'parent_task_id'], 'task_parent_scope_idx');
            $table->index(['workspace_id', 'client_project_id', 'client_task_list_id'], 'task_list_scope_idx');
            // RESTRICT preserves disclosure: deletion must never silently turn
            // a child of a private task/list into an unlisted public root.
            $table->foreign(['workspace_id', 'client_project_id', 'parent_task_id'], 'tasks_project_parent_fk')
                ->references(['workspace_id', 'client_project_id', 'id'])->on('client_tasks')->restrictOnDelete();
            $table->foreign(['workspace_id', 'client_project_id', 'client_task_list_id'], 'tasks_project_list_fk')
                ->references(['workspace_id', 'client_project_id', 'id'])->on('client_task_lists')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::table('client_tasks')->whereNotNull('parent_task_id')->orWhereNotNull('client_task_list_id')->exists()
            || DB::table('client_task_lists')->exists()) {
            throw new RuntimeException('Task organization data requires the compatible task reader. Retain this migration and its disclosure guards.');
        }
        Schema::table('client_tasks', function (Blueprint $table): void {
            if (DB::connection()->getDriverName() === 'sqlite') {
                $table->dropForeign(['workspace_id', 'client_project_id', 'parent_task_id']);
                $table->dropForeign(['workspace_id', 'client_project_id', 'client_task_list_id']);
            } else {
                $table->dropForeign('tasks_project_parent_fk');
                $table->dropForeign('tasks_project_list_fk');
            }
            $table->dropUnique('tasks_ws_project_id_unique');
            $table->dropIndex('task_parent_scope_idx');
            $table->dropIndex('task_list_scope_idx');
            $table->dropColumn(['parent_task_id', 'client_task_list_id']);
        });
        Schema::dropIfExists('client_task_lists');
    }
};
