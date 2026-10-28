<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_task_labels', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->char('name_key', 64);
            $table->boolean('is_archived')->default(false);
            $table->unsignedBigInteger('lock_version')->default(1);
            $table->timestamps();
            $table->unique(['workspace_id', 'id'], 'task_labels_ws_id_unique');
            $table->unique(['workspace_id', 'name_key'], 'task_labels_ws_name_unique');
            $table->index(['workspace_id', 'is_archived', 'id'], 'task_labels_active_idx');
        });
        Schema::create('client_task_label_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('client_task_id');
            $table->unsignedBigInteger('client_task_label_id');
            $table->timestamps();
            $table->unique(['workspace_id', 'client_task_id', 'client_task_label_id'], 'task_label_assignment_unique');
            $table->foreign(['workspace_id', 'client_task_id'], 'task_label_assignment_task_fk')->references(['workspace_id', 'id'])->on('client_tasks')->restrictOnDelete();
            $table->foreign(['workspace_id', 'client_task_label_id'], 'task_label_assignment_label_fk')->references(['workspace_id', 'id'])->on('client_task_labels')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::table('client_task_labels')->exists() || DB::table('client_task_label_assignments')->exists()) {
            throw new RuntimeException('Retained task labels require this additive schema.');
        }
        Schema::dropIfExists('client_task_label_assignments');
        Schema::dropIfExists('client_task_labels');
    }
};
