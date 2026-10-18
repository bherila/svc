<?php

namespace App\Console\Commands\Billing;

use App\Models\ClientAgreement;
use App\Models\Workspace;
use App\Services\Billing\DeferredBacklogReadService;
use Illuminate\Console\Command;

/**
 * Print one agreement's deferred backlog.
 *
 * A printer over {@see DeferredBacklogReadService}, the workflow the capacity
 * ledger, the operations summary and the agreement page also read, so an
 * operator sees the figure those surfaces show. Read-only.
 */
final class DeferredBacklogCommand extends Command
{
    protected $signature = 'svc:billing:deferred-backlog
        {workspace : Workspace public UUID}
        {agreement : Agreement public UUID}
        {--months=3 : Trailing calendar months of trend, 1 to 60}
        {--format=text : Output text or json}';

    protected $description = 'Show the deferred work an agreement still has on the shelf and what termination would charge for it';

    public function handle(DeferredBacklogReadService $backlogs): int
    {
        $format = (string) $this->option('format');
        if (! in_array($format, ['text', 'json'], true)) {
            $this->error('The --format option must be text or json.');

            return self::INVALID;
        }
        $months = filter_var($this->option('months'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 60]]);
        if ($months === false) {
            $this->error('The --months option must be a whole number from 1 to 60.');

            return self::INVALID;
        }

        $workspace = Workspace::query()->where('public_id', (string) $this->argument('workspace'))->first();
        $agreement = $workspace === null ? null : ClientAgreement::query()
            ->where('workspace_id', $workspace->id)
            ->where('public_id', (string) $this->argument('agreement'))
            ->first();
        if ($workspace === null || $agreement === null) {
            $this->error('Agreement not found in the requested workspace.');

            return self::FAILURE;
        }

        $backlog = $backlogs->trailing($workspace, $agreement, $months);

        if ($format === 'json') {
            $this->line((string) json_encode(['data' => $backlog], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));

            return self::SUCCESS;
        }

        $amount = $backlog['amount_at_hourly_rate'];
        $this->components->twoColumnDetail('Through', (string) $backlog['through']);
        $this->components->twoColumnDetail('Hours', $backlog['hours'] === null ? 'withheld' : (string) $backlog['hours']);
        $this->components->twoColumnDetail('Entries', $backlog['entry_count'] === null ? 'withheld' : (string) $backlog['entry_count']);
        $this->components->twoColumnDetail('Oldest worked on', (string) ($backlog['oldest_worked_on'] ?? '-'));
        $this->components->twoColumnDetail('At hourly rate', is_array($amount) ? sprintf('%s %s', number_format($amount['amount'] / 100, 2), $amount['currency']) : 'unpriced');
        $this->components->twoColumnDetail('Net hours per month', $backlog['net_hours_per_month'] === null ? '-' : (string) $backlog['net_hours_per_month']);
        foreach ($backlog['trend'] as $month) {
            $this->components->twoColumnDetail(
                '  '.$month['period'],
                sprintf('+%s added, -%s applied, -%s billed', $month['hours_added'], $month['hours_applied'], $month['hours_billed']),
            );
        }
        if ($backlog['issue_message'] !== null) {
            $this->components->warn(trim($backlog['issue_message'].' '.($backlog['issue_detail'] ?? '')));
        }

        return self::SUCCESS;
    }
}
