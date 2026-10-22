<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_task_dependencies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('client_project_id');
            $table->unsignedBigInteger('client_task_id');
            $table->unsignedBigInteger('prerequisite_task_id');
            $table->timestamps();
            $table->unique(['workspace_id', 'client_project_id', 'client_task_id', 'prerequisite_task_id'], 'task_dependency_unique');
            $table->index(['workspace_id', 'client_project_id', 'prerequisite_task_id', 'client_task_id'], 'task_dependency_reverse_idx');
            $table->foreign(['workspace_id', 'client_project_id'], 'task_dependency_project_fk')->references(['workspace_id', 'id'])->on('client_projects')->restrictOnDelete();
            $table->foreign(['workspace_id', 'client_project_id', 'client_task_id'], 'task_dependency_source_fk')->references(['workspace_id', 'client_project_id', 'id'])->on('client_tasks')->restrictOnDelete();
            $table->foreign(['workspace_id', 'client_project_id', 'prerequisite_task_id'], 'task_dependency_prerequisite_fk')->references(['workspace_id', 'client_project_id', 'id'])->on('client_tasks')->restrictOnDelete();
        });
        if (DB::getDriverName() === 'sqlite') {
            // SQLite cannot add a CHECK to an existing table. Both triggers
            // enforce the identical self-edge guard for insert and update.
            foreach (['INSERT', 'UPDATE'] as $operation) {
                $name = 'task_dependency_not_self_'.strtolower($operation);
                DB::unprepared("CREATE TRIGGER {$name} BEFORE {$operation} ON client_task_dependencies WHEN NEW.client_task_id = NEW.prerequisite_task_id BEGIN SELECT RAISE(ABORT, 'A task cannot depend on itself.'); END");
            }
        } else {
            DB::statement('ALTER TABLE client_task_dependencies ADD CONSTRAINT task_dependency_not_self CHECK (client_task_id <> prerequisite_task_id)');
        }
    }

    public function down(): void
    {
        if (DB::table('client_task_dependencies')->exists()) {
            throw new RuntimeException('Retained task dependencies require this additive schema.');
        }
        Schema::dropIfExists('client_task_dependencies');
    }
};
