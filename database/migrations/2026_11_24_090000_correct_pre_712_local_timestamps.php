<?php

use App\Support\Database\LocalStamps\LocalStampCorrection;
use App\Support\Database\LocalStamps\LocalStampSpec;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Correct the event times code before #712 stored in a workspace's wall clock.
 *
 * `WorkspaceClock::now($workspace)` was written to timestamp columns as is:
 * the driver dropped the offset, and the value read back as UTC. In
 * America/Los_Angeles an agreement's `signed_at`, an invoice's `voided_at`, a
 * client's approval and the rest read seven or eight hours early. #712
 * (released 2026-11-13 22:28 UTC) writes UTC; this rewrites what came before
 * it, column by column as `LocalStampSpec::writtenLocalBefore712()` lists,
 * deciding each value as `LocalStampClassifier` describes.
 *
 * Each correction is logged with the value it replaced. What cannot be decided
 * is left as stored and listed, by table, column and row id, in the log and
 * on the migration's output. docs/client-management/timestamps.md says how to
 * read that list. Workspaces on UTC are not touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        $report = LocalStampCorrection::run(DB::connection(), LocalStampSpec::writtenLocalBefore712());
        if ($report['corrected'] === [] && $report['ambiguous'] === []) {
            return;
        }

        Log::warning('Corrected the timestamps written in workspace-local time before #712.', $report);
        if (defined('STDOUT')) {
            fwrite(STDOUT, 'Pre-#712 local timestamps: '.json_encode($report, JSON_THROW_ON_ERROR).PHP_EOL);
        }
    }

    /**
     * Nothing to undo automatically: the log holds every replaced value, and
     * putting one back would make it wrong again.
     */
    public function down(): void {}
};
