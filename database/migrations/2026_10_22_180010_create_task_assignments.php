<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_task_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_task_id')->constrained('client_tasks')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['workspace_id', 'client_task_id', 'user_id'], 'task_assignment_unique');
            $table->foreign(['workspace_id', 'client_task_id'], 'task_assignments_ws_task_fk')
                ->references(['workspace_id', 'id'])->on('client_tasks')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_task_assignments');
    }
};
