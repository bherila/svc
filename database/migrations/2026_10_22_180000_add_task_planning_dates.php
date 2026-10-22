<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_tasks', function (Blueprint $table): void {
            $table->date('starts_on')->nullable();
            $table->date('due_on')->nullable();
            $table->unique(['workspace_id', 'id'], 'tasks_ws_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('client_tasks', function (Blueprint $table): void {
            $table->dropUnique('tasks_ws_id_unique');
            $table->dropColumn(['starts_on', 'due_on']);
        });
    }
};
