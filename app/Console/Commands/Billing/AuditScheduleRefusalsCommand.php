<?php

namespace App\Console\Commands\Billing;

use App\Services\Billing\ScheduleRefusalAuditor;
use App\Support\Billing\ScheduleRefusalCounts;
use Illuminate\Console\Command;

/**
 * Report how many invoices would stop a billing schedule, and why.
 *
 * Run this against production *before* deploying a change to the collision
 * refusals, and after any import or bulk edit. Everything it asks lives in
 * {@see ScheduleRefusalAuditor}; this is a printer, so an operator screen can
 * later show the same numbers rather than re-deriving them from a second copy
 * that drifts.
 *
 * It prints counts only - never a row, an id, an invoice number, a company or a
 * workspace. That is enforced by the shape of {@see ScheduleRefusalCounts}
 * rather than by care taken here, so the output is safe to paste into a public
 * issue against a database of real client billing records.
 *
 * ## Why this one has an exit code and the unplaceable audit does not
 *
 * That audit reports data quality behind a guard that already fails safe: it
 * prompts a correction and always exits clean. A refusal is not that. It stops
 * a schedule generating, and a refusal naming a paid invoice stops it on every
 * subsequent run too, because the invoice can be neither voided nor re-dated
 * once money has been taken against it. So this exits non-zero when it finds
 * any, which is what lets a deployment pipeline actually gate on it.
 */
final class AuditScheduleRefusalsCommand extends Command
{
    protected $signature = 'svc:billing:audit-schedule-refusals
        {--format=text : Output text or json}';

    protected $description = 'Count invoices that would make a billing schedule refuse to generate, broken down by reason, and how many schedules would halt';

    public function handle(ScheduleRefusalAuditor $auditor): int
    {
        $format = (string) $this->option('format');

        if (! in_array($format, ['text', 'json'], true)) {
            $this->error('The --format option must be text or json.');

            return self::INVALID;
        }

        // Unscoped: an operator gating a deployment needs every workspace at
        // once. A tenant-facing caller passes its own workspace instead.
        $counts = $auditor->count();

        if ($format === 'json') {
            $this->line((string) json_encode(['summary' => $counts->toArray()], JSON_THROW_ON_ERROR));

            return $counts->wouldRefuse === 0 ? self::SUCCESS : self::FAILURE;
        }

        $this->components->twoColumnDetail('Invoices', (string) $counts->invoices);
        $this->components->twoColumnDetail('... a schedule could reach', (string) $counts->candidates);
        $this->components->twoColumnDetail('Would refuse generation', (string) $counts->wouldRefuse);

        $this->newLine();
        $this->components->twoColumnDetail('... naming a schedule that does not resolve', (string) $counts->danglingScheduleLink);
        $this->components->twoColumnDetail('... naming an agreement that does not resolve', (string) $counts->danglingAgreementLink);
        $this->components->twoColumnDetail('... whose schedule and agreement disagree', (string) $counts->contradictoryLineage);
        $this->components->twoColumnDetail('... carrying a status this code cannot read', (string) $counts->unknownStatus);
        $this->components->twoColumnDetail('... owned but missing a period boundary', (string) $counts->incompletePeriodOnAnOwnedRow);
        $this->components->twoColumnDetail('... unattributed where another owner could claim them', (string) $counts->unattributedAndContested);

        $this->newLine();
        $this->components->twoColumnDetail('Schedules', (string) $counts->schedules);
        $this->components->twoColumnDetail('Schedules that would halt', (string) $counts->schedulesHalted);

        $this->newLine();

        if ($counts->wouldRefuse === 0) {
            // Deliberately not an all-clear. The last refusal in classify() -
            // a live invoice overlapping a period without matching it - depends
            // on which period is being billed and cannot be counted by any
            // query, so a zero here bounds the reasons a data repair can clear
            // ahead of time and says nothing about that one.
            $this->components->info(
                'No invoice would refuse generation for a lineage, status or unplaceable-period reason. '
                .'A partial period overlap depends on the period being billed and is not counted here, so this is not a guarantee that no schedule halts.'
            );

            return self::SUCCESS;
        }

        $this->components->warn(
            $counts->wouldRefuse.' invoice(s) would make a schedule refuse to generate, halting '.$counts->schedulesHalted
            .' of '.$counts->schedules.' schedule(s). Repair them before deploying: attribute or clear the lineage columns, give an owned invoice a '
            .'complete service period, and correct any unreadable status. A refusal is safe - nothing is billed twice and no period is skipped - but a '
            .'schedule stays halted until the row it names is repaired, and an invoice that has taken money can be neither voided nor re-dated.'
        );

        return self::FAILURE;
    }
}
