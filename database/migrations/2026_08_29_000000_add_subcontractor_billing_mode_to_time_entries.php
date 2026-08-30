<?php

use App\Support\Billing\SubcontractorBillingMode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_time_entries', function (Blueprint $table): void {
            $table->string('subcontractor_billing_mode', 32)
                ->nullable()
                ->after('approved_at');
            $table->index(
                ['workspace_id', 'subcontractor_billing_mode', 'worked_on'],
                'cte_workspace_subcontractor_mode_worked_idx',
            );
        });

        // A cost amount was the old schema's only flat-hourly signal. Preserve
        // that meaning before application code starts selecting by mode.
        $workspaceIds = DB::table('client_time_entries')
            ->whereNotNull('subcontractor_cost_amount')
            ->select('workspace_id')
            ->distinct()
            ->orderBy('workspace_id')
            ->pluck('workspace_id');

        foreach ($workspaceIds as $workspaceId) {
            DB::table('client_time_entries')
                ->where('workspace_id', $workspaceId)
                ->whereNotNull('subcontractor_cost_amount')
                ->update(['subcontractor_billing_mode' => SubcontractorBillingMode::FlatHourly->value]);
        }
    }

    public function down(): void
    {
        Schema::table('client_time_entries', function (Blueprint $table): void {
            $table->dropIndex('cte_workspace_subcontractor_mode_worked_idx');
            $table->dropColumn('subcontractor_billing_mode');
        });
    }
};
