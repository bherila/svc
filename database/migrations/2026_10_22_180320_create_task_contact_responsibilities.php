<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_projects', fn (Blueprint $table) => $table->unique(['workspace_id', 'client_company_id', 'id'], 'projects_ws_company_id_unique'));
        Schema::table('client_contacts', fn (Blueprint $table) => $table->unique(['workspace_id', 'client_company_id', 'id'], 'contacts_ws_company_id_unique'));
        Schema::create('client_task_contact_responsibilities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('client_company_id');
            $table->unsignedBigInteger('client_project_id');
            $table->unsignedBigInteger('client_task_id');
            $table->unsignedBigInteger('client_contact_id');
            $table->timestamps();
            $table->unique(['workspace_id', 'client_task_id', 'client_contact_id'], 'task_contact_responsibility_unique');
            $table->foreign(['workspace_id', 'client_company_id'], 'task_contact_responsibility_company_fk')->references(['workspace_id', 'id'])->on('client_companies')->restrictOnDelete();
            $table->foreign(['workspace_id', 'client_project_id', 'client_task_id'], 'task_contact_responsibility_task_fk')->references(['workspace_id', 'client_project_id', 'id'])->on('client_tasks')->restrictOnDelete();
            $table->foreign(['workspace_id', 'client_company_id', 'client_project_id'], 'task_contact_responsibility_project_fk')->references(['workspace_id', 'client_company_id', 'id'])->on('client_projects')->restrictOnDelete();
            $table->foreign(['workspace_id', 'client_company_id', 'client_contact_id'], 'task_contact_responsibility_contact_fk')->references(['workspace_id', 'client_company_id', 'id'])->on('client_contacts')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::table('client_task_contact_responsibilities')->exists()) {
            throw new RuntimeException('Retained task contact responsibilities require this additive schema.');
        }
        Schema::dropIfExists('client_task_contact_responsibilities');
        // InnoDB may replace an implicit owner-FK index with the longer new
        // unique key. Restore support before removing that key, without ever
        // dropping or disabling the existing company foreign key.
        $this->preserveOwnerIndex('client_projects', 'projects_ws_company_id_unique', 'planning_projects_company_idx');
        $this->preserveOwnerIndex('client_contacts', 'contacts_ws_company_id_unique', 'planning_contacts_company_idx');
        Schema::table('client_projects', fn (Blueprint $table) => $table->dropUnique('projects_ws_company_id_unique'));
        Schema::table('client_contacts', fn (Blueprint $table) => $table->dropUnique('contacts_ws_company_id_unique'));
    }

    private function preserveOwnerIndex(string $table, string $dropping, string $replacement): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }
        foreach (Schema::getIndexes($table) as $index) {
            if ($index['name'] !== $dropping && array_slice($index['columns'], 0, 2) === ['workspace_id', 'client_company_id']) {
                return;
            }
        }
        Schema::table($table, fn (Blueprint $blueprint) => $blueprint->index(['workspace_id', 'client_company_id'], $replacement));
    }
};
