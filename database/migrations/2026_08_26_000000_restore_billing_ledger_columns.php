<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Restores the columns the external import had nowhere to put.
 *
 * Every column here exists because the source carried a value for it that was
 * discarded at import. Nothing speculative is added: a column earns its place
 * only if `svc:billing:backfill-ledger` can fill it from the source.
 *
 * Hours are kept as decimals rather than converted to SVC's usual integer
 * minutes. 197 of the 771 source lines carry an hours value that is not a whole
 * number of minutes, and these rows are a historical record that can never be
 * recomputed — rounding them to fit a convention would corrupt the only copy.
 * Policy fields that new code will write use SVC's conventions instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_invoices', function (Blueprint $table): void {
            $table->string('invoice_kind', 32)->nullable()->after('status');
            $table->date('cycle_start')->nullable()->after('service_period_end');
            $table->date('cycle_end')->nullable()->after('cycle_start');
            $table->date('paid_on')->nullable()->after('due_date');
            // The retainer ledger: what capacity the period granted, consumed, and carried.
            $table->decimal('retainer_hours_included', 8, 2)->nullable()->after('cycle_end');
            $table->decimal('hours_worked', 8, 2)->nullable()->after('retainer_hours_included');
            $table->decimal('rollover_hours_used', 8, 2)->nullable()->after('hours_worked');
            $table->decimal('unused_hours_balance', 8, 2)->nullable()->after('rollover_hours_used');
            $table->decimal('negative_hours_balance', 8, 2)->nullable()->after('unused_hours_balance');
            $table->decimal('hours_billed_at_rate', 8, 2)->nullable()->after('negative_hours_balance');
        });

        Schema::table('client_invoice_lines', function (Blueprint $table): void {
            $table->date('line_date')->nullable()->after('description');
            $table->decimal('hours', 10, 4)->nullable()->after('quantity');
            // No cross-slice FK: the engagement migrations own these tables.
            $table->unsignedBigInteger('client_agreement_id')->nullable()->after('client_project_id');
            $table->unsignedBigInteger('client_agreement_recurring_item_id')->nullable()->after('client_agreement_id');
            $table->index(['workspace_id', 'client_agreement_id'], 'cil_workspace_agreement_idx');
        });

        Schema::table('client_agreements', function (Blueprint $table): void {
            $table->unsignedInteger('catch_up_threshold_minutes')->nullable()->after('retainer_minutes');
            // Period-level overrides: the retainer for one whole billing cycle, used
            // in preference to the monthly figure times the cycle length. Six of the
            // nine source agreements set the hours override, so this is the ordinary
            // path, not an edge case.
            $table->unsignedInteger('period_retainer_minutes')->nullable()->after('catch_up_threshold_minutes');
            $table->unsignedBigInteger('period_retainer_amount')->nullable()->after('period_retainer_minutes');
            $table->unsignedInteger('rollover_months')->nullable()->after('rollover_policy');
            $table->unsignedInteger('initial_rollover_minutes')->nullable()->after('rollover_months');
            // Nullable, not false-by-default: a backfill has to tell an unset flag from a
            // deliberate false, or a re-run revives a policy an operator turned off.
            $table->boolean('bill_overage_interim')->nullable()->after('initial_rollover_minutes');
            $table->string('first_cycle_proration', 30)->nullable()->after('bill_overage_interim');
            $table->string('agreement_link', 2048)->nullable()->after('agreement_text');
        });

        Schema::table('client_tasks', function (Blueprint $table): void {
            $table->unsignedBigInteger('milestone_price_amount')->nullable()->after('completed_at');
        });
    }

    public function down(): void
    {
        Schema::table('client_tasks', function (Blueprint $table): void {
            $table->dropColumn('milestone_price_amount');
        });

        Schema::table('client_agreements', function (Blueprint $table): void {
            $table->dropColumn([
                'catch_up_threshold_minutes', 'period_retainer_minutes', 'period_retainer_amount',
                'rollover_months', 'initial_rollover_minutes',
                'bill_overage_interim', 'first_cycle_proration', 'agreement_link',
            ]);
        });

        Schema::table('client_invoice_lines', function (Blueprint $table): void {
            $table->dropIndex('cil_workspace_agreement_idx');
            $table->dropColumn([
                'line_date', 'hours', 'client_agreement_id', 'client_agreement_recurring_item_id',
            ]);
        });

        Schema::table('client_invoices', function (Blueprint $table): void {
            $table->dropColumn([
                'invoice_kind', 'cycle_start', 'cycle_end', 'paid_on',
                'retainer_hours_included', 'hours_worked', 'rollover_hours_used',
                'unused_hours_balance', 'negative_hours_balance', 'hours_billed_at_rate',
            ]);
        });
    }
};
