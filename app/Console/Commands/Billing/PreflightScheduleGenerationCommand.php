<?php

namespace App\Console\Commands\Billing;

use App\Services\Billing\ScheduleGenerationPreflight;
use App\Support\Billing\PeriodRefusalReason;
use App\Support\Billing\ScheduleGenerationPreflightReport;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Ask every due billing schedule what it would do, and report what would stop.
 *
 * Run this against production before deploying a change to the collision
 * guards, and after any import or bulk edit. Everything it asks lives in
 * {@see ScheduleGenerationPreflight}, which runs the real resolver over the
 * real due periods; this is a printer, so an operator screen can later show the
 * same numbers rather than re-deriving them.
 *
 * It prints counts only - never a row, an id, an invoice number, a company or a
 * workspace. That is enforced by the shape of
 * {@see ScheduleGenerationPreflightReport} rather than by care taken here, so
 * the output is safe to paste into a public issue against a database of real
 * client billing records. The consequence is that it tells an operator *how
 * many* schedules would halt and *why*, but not which - finding those means
 * running the schedule and reading the message it throws, which names the
 * invoice.
 *
 * ## Why this one has an exit code and the unplaceable audit does not
 *
 * That audit reports data quality behind a guard that already fails safe: it
 * prompts a correction and always exits clean. A halt is not that. It stops a
 * schedule generating, and one naming a paid invoice stops it on every
 * subsequent run too, because the invoice can be neither voided nor re-dated
 * once money has been taken against it. So this exits non-zero when it finds
 * any, which is what lets a deployment pipeline gate on it.
 */
final class PreflightScheduleGenerationCommand extends Command
{
    protected $signature = 'svc:billing:preflight-schedule-generation
        {--through= : Classify periods due through this date (default: today)}
        {--format=text : Output text or json}';

    protected $description = 'Classify every period each active billing schedule is due to bill, and report how many would halt and why';

    public function handle(ScheduleGenerationPreflight $preflight): int
    {
        $format = (string) $this->option('format');

        if (! in_array($format, ['text', 'json'], true)) {
            $this->error('The --format option must be text or json.');

            return self::INVALID;
        }

        $through = $this->option('through');

        try {
            // Parsed here rather than inside the service, so an unreadable date
            // is an operator's typo caught at the door instead of a span nobody
            // asked for being classified.
            $parsed = is_string($through) ? CarbonImmutable::parse($through) : null;
        } catch (\Throwable) {
            $this->error('The --through option must be a date this application can read.');

            return self::INVALID;
        }

        // Unscoped: an operator gating a deployment needs every workspace at
        // once. A tenant-facing caller passes its own workspace instead.
        $report = $preflight->run(through: $parsed);

        if ($format === 'json') {
            $this->line((string) json_encode(['summary' => $report->toArray()], JSON_THROW_ON_ERROR));

            return $report->wouldHalt === 0 ? self::SUCCESS : self::FAILURE;
        }

        $this->components->twoColumnDetail('Active schedules', (string) $report->schedules);
        $this->components->twoColumnDetail('... with a period due', (string) $report->schedulesDue);
        $this->components->twoColumnDetail('Periods classified', (string) $report->periodsClassified);

        $this->newLine();
        $this->components->twoColumnDetail('Schedules that would halt', (string) $report->wouldHalt);
        $this->components->twoColumnDetail('... on a refusal', (string) $report->haltedByARefusal);
        $this->components->twoColumnDetail('... on a pending draft', (string) $report->haltedByAPendingDraft);

        if ($report->haltedByARefusal > 0) {
            $this->newLine();
            foreach (PeriodRefusalReason::cases() as $reason) {
                $count = $report->refusalsByReason[$reason->value] ?? 0;
                if ($count > 0) {
                    $this->components->twoColumnDetail('... '.$reason->summary(), (string) $count);
                }
            }
        }

        if ($report->schedulesTruncated > 0) {
            $this->newLine();
            $this->components->warn(
                $report->schedulesTruncated.' schedule(s) have more periods due than this preflight examines, so their '
                .'later periods were not classified. That is a backlog worth looking at on its own.'
            );
        }

        $this->newLine();

        if ($report->wouldHalt === 0) {
            $this->components->info(
                'Every period now due classifies cleanly, so no active schedule would halt on this data. '
                .'This takes no locks and writes nothing, so it describes the database as it is now rather than '
                .'guaranteeing a future run; a schedule with nothing due was not examined at all.'
            );

            return self::SUCCESS;
        }

        $this->components->warn(
            $report->wouldHalt.' of '.$report->schedules.' active schedule(s) would stop rather than bill a period now due. '
            .'Repair the data before deploying: attribute or clear the lineage columns, give an owned invoice a complete '
            .'service period, correct any unreadable status, and issue or void any draft that has claimed a period '
            .'without billing it. A halt is safe - nothing is billed twice and no period is skipped - but a schedule '
            .'stays halted until the row it names is dealt with, and an invoice that has taken money can be neither '
            .'voided nor re-dated.'
        );

        return self::FAILURE;
    }
}
