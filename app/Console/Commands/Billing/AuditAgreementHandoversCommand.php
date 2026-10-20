<?php

namespace App\Console\Commands\Billing;

use App\Console\Commands\SvcCommand;
use App\Services\Billing\AgreementHandoverAudit;

final class AuditAgreementHandoversCommand extends SvcCommand
{
    protected $signature = 'svc:billing:audit-handovers {--workspace= : Workspace public id; omit for all workspaces} {--format=text : Output text or json}';

    protected $description = 'Report issued invoice coverage that overlaps a successor agreement';

    public function handle(AgreementHandoverAudit $audit): int
    {
        $id = $this->option('workspace');
        $workspace = is_string($id) ? $this->workspaceByPublicId($id) : null;
        if (is_string($id) && $workspace === null) {
            return self::INVALID;
        }
        $report = $audit->report($workspace);
        if ($this->option('format') === 'json') {
            $this->json($report);
        } else {
            $this->line($report['count'].' issued invoice(s) have coverage overlapping a successor. Review the original terms and any proration before making a correction.');
        }

        return self::SUCCESS;
    }
}
