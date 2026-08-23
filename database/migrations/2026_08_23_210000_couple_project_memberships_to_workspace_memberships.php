<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('client_project_memberships')
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('workspace_memberships')
                    ->whereColumn('workspace_memberships.workspace_id', 'client_project_memberships.workspace_id')
                    ->whereColumn('workspace_memberships.user_id', 'client_project_memberships.user_id');
            })
            ->delete();

        Schema::table('client_project_memberships', function (Blueprint $table): void {
            $table->foreign(['workspace_id', 'user_id'])
                ->references(['workspace_id', 'user_id'])
                ->on('workspace_memberships')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('client_project_memberships', function (Blueprint $table): void {
            $table->dropForeign(['workspace_id', 'user_id']);
        });
    }
};
