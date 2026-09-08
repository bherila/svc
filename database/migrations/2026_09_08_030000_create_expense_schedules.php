<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_expense_schedules', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('client_company_id');
            $table->unsignedBigInteger('client_project_id')->nullable();
            $table->date('starts_on');
            $table->string('cadence');
            $table->unsignedInteger('next_occurrence')->default(0);
            $table->unsignedBigInteger('amount');
            $table->char('currency', 3);
            $table->text('description');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['workspace_id', 'id'], 'ces_ws_id_unique');
            $table->foreign(['workspace_id', 'client_company_id'], 'ces_ws_company_fk')->references(['workspace_id', 'id'])->on('client_companies')->restrictOnDelete();
            $table->foreign(['workspace_id', 'client_project_id'], 'ces_ws_project_fk')->references(['workspace_id', 'id'])->on('client_projects')->restrictOnDelete();
        });
        Schema::table('client_expenses', function (Blueprint $table): void {
            $table->unsignedBigInteger('client_expense_schedule_id')->nullable();
            $table->date('occurrence_on')->nullable();
            $table->foreign(['workspace_id', 'client_expense_schedule_id'], 'cex_ws_schedule_fk')->references(['workspace_id', 'id'])->on('client_expense_schedules')->restrictOnDelete();
            $table->unique(['workspace_id', 'client_expense_schedule_id', 'occurrence_on'], 'cex_schedule_occurrence_unique');
        });
    }

    public function down(): void
    {
        Schema::table('client_expenses', function (Blueprint $table): void {
            $table->dropForeign(Schema::getConnection()->getDriverName() === 'sqlite' ? ['workspace_id', 'client_expense_schedule_id'] : 'cex_ws_schedule_fk');
            $table->dropUnique('cex_schedule_occurrence_unique');
            $table->dropColumn(['client_expense_schedule_id', 'occurrence_on']);
        });
        Schema::dropIfExists('client_expense_schedules');
    }
};
