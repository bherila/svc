<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_task_graph_states', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('client_project_id');
            $table->unsignedBigInteger('lock_version')->default(0);
            $table->timestamps();
            $table->unique(['workspace_id', 'client_project_id'], 'task_graph_ws_project_unique');
            $table->unique(['workspace_id', 'id'], 'task_graph_ws_id_unique');
            $table->foreign(['workspace_id', 'client_project_id'], 'task_graph_ws_project_fk')
                ->references(['workspace_id', 'id'])->on('client_projects')->cascadeOnDelete();
        });
        Schema::table('client_tasks', function (Blueprint $table): void {
            $table->unsignedInteger('organization_position')->default(0);
            $table->index(['workspace_id', 'client_project_id', 'parent_task_id', 'client_task_list_id', 'organization_position', 'id'], 'task_organization_order_idx');
        });
        Schema::table('client_task_lists', function (Blueprint $table): void {
            $table->unsignedInteger('organization_position')->default(0);
            $table->index(['workspace_id', 'client_project_id', 'organization_position', 'id'], 'task_list_organization_order_idx');
        });
    }

    public function down(): void
    {
        // Ordinary application rollback keeps additive schema. A manual schema
        // rollback cannot discard a revision or an established sibling order.
        if (DB::table('client_task_graph_states')->exists()
            || DB::table('client_tasks')->where('organization_position', '!=', 0)->exists()
            || DB::table('client_task_lists')->where('organization_position', '!=', 0)->exists()) {
            throw new RuntimeException('Task organization revisions and ordering require the compatible reader floor. Retain this migration.');
        }
        Schema::table('client_tasks', function (Blueprint $table): void {
            $table->dropIndex('task_organization_order_idx');
            $table->dropColumn('organization_position');
        });
        Schema::table('client_task_lists', function (Blueprint $table): void {
            $table->dropIndex('task_list_organization_order_idx');
            $table->dropColumn('organization_position');
        });
        Schema::dropIfExists('client_task_graph_states');
    }
};
