<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Identity allocation holds no tenant parent or counter fence. Rows
        // are removed when their final event is published at physical commit.
        Schema::create('billing_eligibility_event_reservations', function (Blueprint $table): void {
            $table->id();
        });
        Schema::create('client_billing_eligibility_counters', function (Blueprint $table): void {
            $table->unsignedBigInteger('workspace_id');
            $table->unsignedBigInteger('client_company_id');
            $table->unsignedBigInteger('sequence')->default(0);
            $table->primary(['workspace_id', 'client_company_id'], 'billing_eligibility_counters_pk');
            $table->foreign(['workspace_id', 'client_company_id'], 'billing_eligibility_counter_company_fk')
                ->references(['workspace_id', 'id'])->on('client_companies')->cascadeOnDelete();
        });
        DB::table('client_companies')->orderBy('id')->chunkById(200, function ($companies): void {
            DB::table('client_billing_eligibility_counters')->insert($companies->map(static fn ($company): array => [
                'workspace_id' => $company->workspace_id, 'client_company_id' => $company->id, 'sequence' => 0,
            ])->all());
        });
        Schema::create('client_billing_eligibility_events', function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('workspace_id');
            $table->unsignedBigInteger('client_company_id');
            // Never null in a committed event. Assigned only at physical commit.
            $table->unsignedBigInteger('sequence')->nullable();
            $table->unique(['workspace_id', 'id'], 'billing_eligibility_events_ws_id_uq');
            $table->unique(['workspace_id', 'client_company_id', 'sequence'], 'billing_eligibility_events_sequence_uq');
            $table->foreign(['workspace_id', 'client_company_id'], 'billing_eligibility_event_company_fk')
                ->references(['workspace_id', 'id'])->on('client_companies')->cascadeOnDelete();
        });
        foreach (['client_time_entries', 'client_invoices'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                // Attribution deliberately has no delete action against the
                // business row: deleting internal history must not delete time
                // or a financial document. All reads verify event ownership.
                $table->unsignedBigInteger('billing_eligibility_event_id')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['client_time_entries', 'client_invoices'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn('billing_eligibility_event_id'));
        }
        Schema::dropIfExists('client_billing_eligibility_events');
        Schema::dropIfExists('client_billing_eligibility_counters');
        Schema::dropIfExists('billing_eligibility_event_reservations');
    }
};
