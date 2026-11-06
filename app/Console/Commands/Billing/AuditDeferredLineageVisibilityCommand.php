<?php

namespace App\Console\Commands\Billing;

use App\Console\Commands\SvcCommand;
use App\Services\Billing\DeferredLineageVisibilityAuditor;
use App\Support\Billing\DeferredLineageVisibilityCounts;

/**
 * Report invoices hidden from project-scoped portal members by an
 * unverifiable legacy deferred snapshot (#610).
 *
 * A printer over {@see DeferredLineageVisibilityAuditor}. Unscoped and counts
 * only, so the output is safe to paste into a public issue; a workspace
 * manager lists the invoices themselves through the workspace-scoped REST or
 * MCP audit. Read-only, and it exits clean whatever it finds: the refusal it
 * reports is the decided, fail-closed contract, not a defect.
 */
final class AuditDeferredLineageVisibilityCommand extends SvcCommand
{
    protected $signature = 'svc:billing:audit-deferred-lineage-visibility
        {--format=text : Output text or json}';

    protected $description = 'Count invoices hidden from project-scoped portal members because their deferred snapshot predates project lineage';

    public function handle(DeferredLineageVisibilityAuditor $auditor): int
    {
        // Unscoped: an operator sizing this needs every workspace at once.
        $counts = $auditor->count();

        if ((string) $this->option('format') === 'json') {
            $this->line((string) json_encode(['summary' => $counts->toArray()], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('Client-visible charged invoices with unverifiable deferred lineage', (string) $counts->withUnverifiableLineage);
        $this->components->twoColumnDetail('... hidden from a project-scoped member otherwise granted them', (string) $counts->hiddenFromProjectScopedMembers);
        $this->components->twoColumnDetail('Project-scoped memberships affected', (string) $counts->affectedProjectScopedMemberships);
        $this->newLine();

        if (! $counts->isLive()) {
            $this->components->info('No project-scoped portal member is refused an invoice because of legacy deferred lineage.');

            return self::SUCCESS;
        }

        $this->components->warn(DeferredLineageVisibilityCounts::REMEDY);

        return self::SUCCESS;
    }
}
