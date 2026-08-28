<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A milestone line bills one deliverable, so one task may hold it.
 *
 * The column had an index and no constraint, which left the invariant to
 * whoever happened to be writing. Two writers checking a line was free and then
 * taking it can both be right at the moment they check - a predicate over other
 * rows cannot settle that, and the import's reconciliation is exactly the kind
 * of long pass that can be racing a generation run.
 *
 * Not scoped to a workspace, deliberately. An invoice line's id is global and
 * the foreign key is not workspace-composite, so a task in one workspace can
 * already name another's line - a per-workspace index would let two tasks in
 * two workspaces hold one line and see nothing wrong. The workspace-scoped
 * checks in the reconciliation cannot see across that boundary either; this
 * can, and it is the only thing here that can.
 *
 * Null is not constrained by a unique index on any engine this runs on, so
 * every unlinked task is still free to be unlinked.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Counted as groups, not as a group's size: count() on a grouped query
        // returns the first group's tally, which for three tasks on one line
        // would report three duplicated lines.
        $duplicates = DB::query()->fromSub(
            DB::table('client_tasks')
                ->select('client_invoice_line_id')
                ->whereNotNull('client_invoice_line_id')
                ->groupBy('client_invoice_line_id')
                ->havingRaw('count(*) > 1'),
            'contested',
        )->count();

        // Said plainly rather than left to the index to reject. A deployment
        // stopping here means two tasks already share a line, and that has to
        // be resolved by deciding which deliverable was billed - not by a
        // migration picking one.
        if ($duplicates > 0) {
            throw new RuntimeException(
                "Cannot enforce one task per milestone line: {$duplicates} line(s) are held by more than one task. ".
                'Resolve which task each line billed before running this migration.'
            );
        }

        // The unique index first, the old one after. A duplicate acquired
        // between the diagnostic above and the DDL makes this fail, as it
        // should - but on MySQL each statement commits as it runs, so dropping
        // first would leave the table with neither index and the deployment
        // stopped.
        Schema::table('client_tasks', function (Blueprint $table): void {
            $table->unique('client_invoice_line_id', 'task_invoice_line_once');
        });

        Schema::table('client_tasks', function (Blueprint $table): void {
            $table->dropIndex('task_invoice_line_idx');
        });
    }

    public function down(): void
    {
        Schema::table('client_tasks', function (Blueprint $table): void {
            $table->index(['workspace_id', 'client_invoice_line_id'], 'task_invoice_line_idx');
        });

        Schema::table('client_tasks', function (Blueprint $table): void {
            $table->dropUnique('task_invoice_line_once');
        });
    }
};
