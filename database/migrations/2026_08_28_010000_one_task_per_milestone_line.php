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
 * Null is not constrained by a unique index on any engine this runs on, so
 * every unlinked task is still free to be unlinked.
 */
return new class extends Migration
{
    public function up(): void
    {
        $duplicates = DB::table('client_tasks')
            ->select('workspace_id', 'client_invoice_line_id')
            ->whereNotNull('client_invoice_line_id')
            ->groupBy('workspace_id', 'client_invoice_line_id')
            ->havingRaw('count(*) > 1')
            ->count();

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

        Schema::table('client_tasks', function (Blueprint $table): void {
            $table->dropIndex('task_invoice_line_idx');
            $table->unique(['workspace_id', 'client_invoice_line_id'], 'task_invoice_line_once');
        });
    }

    public function down(): void
    {
        Schema::table('client_tasks', function (Blueprint $table): void {
            $table->dropUnique('task_invoice_line_once');
            $table->index(['workspace_id', 'client_invoice_line_id'], 'task_invoice_line_idx');
        });
    }
};
