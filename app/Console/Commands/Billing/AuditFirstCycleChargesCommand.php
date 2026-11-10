<?php

namespace App\Console\Commands\Billing;

use App\Console\Commands\SvcCommand;
use App\Services\Billing\FirstCycleChargeAuditor;
use App\Support\Billing\FirstCycleChargeFinding;
use App\Support\Billing\FirstCycleChargeOutcome;
use Illuminate\Support\Str;

/**
 * Report the agreements whose first billing periods charged a recurring item
 * an extra time (#685, #695): one on the agreement's cadence twice, or one on
 * another cadence once more than its cadence says.
 *
 * A printer over {@see FirstCycleChargeAuditor}. By default counts, and the
 * overcharge already issued summed per currency - no id, company or workspace
 * - so the output is safe to paste into an issue, like the other billing
 * audits. `--list` adds one row per agreement keyed by public ids, for the
 * owner who has to decide what to repay; that output is not for pasting.
 *
 * Read-only, and it exits clean whatever it finds: the engine already bills
 * every later period once, and an overcharge already issued is the owner's
 * to settle, never this command's.
 */
final class AuditFirstCycleChargesCommand extends SvcCommand
{
    protected $signature = 'svc:billing:audit-first-cycle-charges
        {--format=text : Output text or json}
        {--list : Also list each agreement by public id (not safe to paste)}
        {--workspace= : Audit only this workspace (its public id); every workspace by default}';

    protected $description = 'List agreements on a longer cadence started mid-month whose first periods charged a recurring item an extra time';

    public function handle(FirstCycleChargeAuditor $auditor): int
    {
        $workspace = null;
        $requested = $this->option('workspace');
        // A bare --workspace must not widen into every tenant.
        if ($requested !== null || $this->input->hasParameterOption('--workspace')) {
            $workspace = is_string($requested) && Str::isUuid($requested) ? $this->workspaceByPublicId($requested) : null;
            if ($workspace === null) {
                $this->error('The --workspace option must be the public id of an existing workspace.');

                return self::INVALID;
            }
        }

        $findings = $auditor->findings($workspace);
        $summary = self::summary($findings);
        $rows = $this->option('list') ? array_map(static fn (FirstCycleChargeFinding $finding): array => $finding->toArray(), $findings) : null;

        if ($this->option('format') === 'json') {
            $this->json(array_filter(['summary' => $summary, 'agreements' => $rows], static fn ($value): bool => $value !== null));

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('Agreements of the shape', (string) $summary['agreements']);
        $this->components->twoColumnDetail('... through a service package', (string) $summary['packages']);
        foreach ($summary['outcomes'] as $outcome => $count) {
            $this->components->twoColumnDetail('... '.str_replace('_', ' ', $outcome), (string) $count);
        }
        foreach ($summary['issued_overcharge_minor_by_currency'] as $currency => $minor) {
            $this->components->twoColumnDetail('Overcharge issued ('.$currency.', minor units)', (string) $minor);
        }
        $this->newLine();

        $issued = $summary['outcomes'][FirstCycleChargeOutcome::IssuedWithDuplicate->value];
        $drafts = $summary['outcomes'][FirstCycleChargeOutcome::DraftWithDuplicate->value];
        if ($issued === 0 && $drafts === 0) {
            $this->components->info('No first period charged a recurring item an extra time.');
        }
        if ($issued > 0) {
            $this->components->warn($issued.' agreement(s) were sent an invoice charging a recurring item an extra time in its first period. Nothing has been changed: repaying it is the owner\'s decision.');
        }
        if ($drafts > 0) {
            $this->components->warn($drafts.' draft(s) still charge a recurring item an extra time. Regenerate each before issuing it; as it stands it would issue the overcharge.');
        }

        if ($rows !== null) {
            $this->newLine();
            $this->table(
                ['Workspace', 'Company', 'Agreement', 'Cadence', 'Starts', 'Package', 'Outcome', 'Invoice', 'Status', 'Overcharge'],
                array_map(static fn (array $row): array => [
                    $row['workspace'], $row['company'], $row['agreement'], $row['cadence'], $row['starts_on'], $row['package'] ? 'yes' : 'no',
                    $row['outcome'], $row['invoice'] ?? '-', $row['invoice_status'] ?? '-',
                    $row['currency'] === null ? '-' : $row['overcharge_minor'].' '.$row['currency'],
                ], $rows),
            );
        }

        return self::SUCCESS;
    }

    /**
     * @param  list<FirstCycleChargeFinding>  $findings
     * @return array{agreements: int, packages: int, outcomes: array<string, int>, issued_overcharge_minor_by_currency: array<string, int>}
     */
    private static function summary(array $findings): array
    {
        $outcomes = array_fill_keys(array_map(static fn (FirstCycleChargeOutcome $outcome): string => $outcome->value, FirstCycleChargeOutcome::cases()), 0);
        $overcharge = [];
        foreach ($findings as $finding) {
            $outcomes[$finding->outcome->value]++;
            if ($finding->outcome === FirstCycleChargeOutcome::IssuedWithDuplicate && $finding->currency !== null) {
                $overcharge[$finding->currency] = ($overcharge[$finding->currency] ?? 0) + $finding->overchargeMinor;
            }
        }
        ksort($overcharge);

        return [
            'agreements' => count($findings),
            'packages' => count(array_filter($findings, static fn (FirstCycleChargeFinding $finding): bool => $finding->package)),
            'outcomes' => $outcomes,
            'issued_overcharge_minor_by_currency' => $overcharge,
        ];
    }
}
