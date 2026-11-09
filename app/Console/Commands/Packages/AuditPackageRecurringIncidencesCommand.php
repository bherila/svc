<?php

namespace App\Console\Commands\Packages;

use App\Console\Commands\SvcCommand;
use App\Services\Packages\PackageRecurringIncidenceAuditor;

final class AuditPackageRecurringIncidencesCommand extends SvcCommand
{
    protected $signature = 'svc:packages:audit-recurring-incidences
        {--workspace= : Workspace public id; omit for all workspaces}
        {--format=text : Output text or json}';

    protected $description = 'Audit non-monthly agreements started mid-month affected by duplicate first-cycle recurring charges';

    public function handle(PackageRecurringIncidenceAuditor $auditor): int
    {
        $id = $this->option('workspace');
        $workspace = is_string($id) ? $this->workspaceByPublicId($id) : null;
        if (is_string($id) && $workspace === null) {
            $this->error("Workspace {$id} not found.");

            return self::INVALID;
        }

        $report = $auditor->report($workspace);

        if ($this->option('format') === 'json') {
            $this->json($report);

            return self::SUCCESS;
        }

        $summary = $report['summary'];

        $this->components->twoColumnDetail('Agreements checked', (string) $summary['agreements_checked']);
        $this->components->twoColumnDetail('Agreements affected (duplicate charges)', (string) $summary['agreements_affected']);
        $this->components->twoColumnDetail('... charged invoices affected', (string) $summary['charged_invoices_affected']);
        $this->components->twoColumnDetail('... draft invoices affected', (string) $summary['draft_invoices_affected']);
        $this->components->twoColumnDetail('Unbilled agreements (at risk before fix)', (string) $summary['unbilled_agreements']);
        $this->components->twoColumnDetail('Total charged overage', sprintf('$%.2f', $summary['total_overcharge_cents'] / 100));

        $this->newLine();

        if ($summary['agreements_affected'] === 0 && $summary['unbilled_agreements'] === 0) {
            $this->components->info('No existing agreements are affected by duplicate first-cycle recurring charges.');

            return self::SUCCESS;
        }

        if ($summary['agreements_affected'] > 0) {
            $this->components->warn(sprintf(
                '%d agreement(s) have duplicate recurring charges. Review invoices for credits/refunds.',
                $summary['agreements_affected']
            ));
        }

        foreach ($report['agreements'] as $agr) {
            $this->line(sprintf(
                '[%s] Agreement %s (%s, started %s, %s) - %s',
                strtoupper($agr['disposition']),
                $agr['agreement_id'],
                $agr['company_name'],
                $agr['starts_on'],
                $agr['cadence'],
                count($agr['affected_invoices']) > 0
                    ? count($agr['affected_invoices']).' duplicate invoice(s)'
                    : 'no invoices generated yet'
            ));
        }

        return self::SUCCESS;
    }
}
