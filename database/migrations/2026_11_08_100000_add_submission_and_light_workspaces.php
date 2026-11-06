<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Submission and the light workspace (docs/ux-overhaul.md sections 7b and 7e,
 * slice 11). Schema only; nothing here writes data.
 *
 * - `submitted_at` / `submitted_by_user_id` on time and expenses: the author's
 *   statement that this row is ready for whoever reviews or approves it. It is
 *   not an approval and moves no status; it is the single fact that puts a
 *   draft row on a reviewer's queue. Withdrawing clears both. Both tables are
 *   tenant-owned, so the rows already carry `workspace_id`; the index leads
 *   with it so the queue's "submitted, per workspace" read is one range.
 * - `workspaces.edition`: `full` (the default, today's behaviour) or `light`,
 *   a partner's minimum workspace. It is a property of the tenant row itself.
 *
 * Index and constraint names are explicit and under MariaDB's 64 characters.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_time_entries', function (Blueprint $table): void {
            $table->timestamp('submitted_at')->nullable();
            $table->unsignedBigInteger('submitted_by_user_id')->nullable();
            $table->foreign('submitted_by_user_id', 'cte_submitted_by_fk')->references('id')->on('users')->nullOnDelete();
            $table->index(['workspace_id', 'submitted_at'], 'cte_ws_submitted_idx');
        });

        Schema::table('client_expenses', function (Blueprint $table): void {
            $table->timestamp('submitted_at')->nullable();
            $table->unsignedBigInteger('submitted_by_user_id')->nullable();
            $table->foreign('submitted_by_user_id', 'cex_submitted_by_fk')->references('id')->on('users')->nullOnDelete();
            $table->index(['workspace_id', 'submitted_at'], 'cex_ws_submitted_idx');
        });

        Schema::table('workspaces', function (Blueprint $table): void {
            $table->string('edition', 16)->default('full');
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table): void {
            $table->dropColumn('edition');
        });

        foreach (['client_expenses' => ['cex_submitted_by_fk', 'cex_ws_submitted_idx'], 'client_time_entries' => ['cte_submitted_by_fk', 'cte_ws_submitted_idx']] as $name => [$foreign, $index]) {
            Schema::table($name, function (Blueprint $table) use ($foreign, $index): void {
                $table->dropForeign($foreign);
                $table->dropIndex($index);
                $table->dropColumn(['submitted_at', 'submitted_by_user_id']);
            });
        }
    }
};
