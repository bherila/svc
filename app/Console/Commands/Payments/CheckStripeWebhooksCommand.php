<?php

namespace App\Console\Commands\Payments;

use App\Console\Commands\MutationCommand;
use App\Models\Workspace;
use App\Models\WorkspacePaymentConnection;
use App\Services\Payments\StripeWebhookHealthService;
use App\Services\Payments\WorkspacePaymentConnections;
use App\Support\Payments\PaymentProcessor;
use Throwable;

/**
 * Looks at each workspace's Stripe account to see that its webhook endpoint is
 * subscribed to every event SVC needs, and records the answer for the Payments
 * page. Run daily by the scheduler, with no queue: it makes one read of Stripe
 * per connection, in the scheduler's own process.
 *
 * Connections are taken oldest-checked first (never-checked first, then by the
 * time of the last check, then by id) so that a bounded run never starves the same
 * workspaces.
 *
 * A preview unless --apply is given, and a preview calls nothing: it lists the
 * connections that would be checked. The command only checks; changing an
 * endpoint is a button an owner or admin presses on the Payments page.
 */
final class CheckStripeWebhooksCommand extends MutationCommand
{
    protected $signature = 'svc:payments:check-stripe-webhooks {--workspace= : Public id of one workspace to check} {--limit=200 : Most connections to check in one run}';

    protected $description = 'Check each workspace Stripe webhook endpoint is subscribed to every required event (preview unless --apply)';

    public function handle(WorkspacePaymentConnections $connections, StripeWebhookHealthService $health): int
    {
        $limit = (int) $this->option('limit');
        if ($limit < 1 || $limit > 1000) {
            $this->error('The --limit option must be between 1 and 1000.');

            return self::INVALID;
        }
        $only = $this->option('workspace');
        $workspaceId = null;
        if (is_string($only) && $only !== '') {
            $workspaceId = $this->workspaceByPublicId($only)?->id;
            if ($workspaceId === null) {
                $this->error('No workspace has that public id.');

                return self::INVALID;
            }
        }

        $rows = $connections->allActive(PaymentProcessor::Stripe)
            ->filter(fn (WorkspacePaymentConnection $row): bool => $health->checkable($row) && ($workspaceId === null || $row->workspace_id === $workspaceId))
            // Oldest-checked first, never-checked before all: a bounded run walks the
            // whole list over successive days instead of re-checking the same first rows.
            ->sort(fn (WorkspacePaymentConnection $a, WorkspacePaymentConnection $b): int => $this->staleness($a) <=> $this->staleness($b))
            ->take($limit)
            ->values();
        $publicIds = Workspace::query()->whereIn('id', $rows->pluck('workspace_id'))->pluck('public_id', 'id');

        $report = [];
        $failed = false;
        foreach ($rows as $row) {
            $line = ['workspace' => (string) ($publicIds[$row->workspace_id] ?? ''), 'status' => 'would_check'];
            if ($this->applying()) {
                try {
                    $result = $health->check($row);
                    $line = ['workspace' => $line['workspace'], 'status' => $result->status->value, 'missing' => $result->missing];
                } catch (Throwable $failure) {
                    // The key and Stripe's answer are never printed; the class says enough.
                    $line = ['workspace' => $line['workspace'], 'status' => 'error', 'reason' => $failure::class];
                    $failed = true;
                }
            }
            $report[] = $line;
        }

        if ($this->option('format') === 'json') {
            $this->json(['dry_run' => ! $this->applying(), 'checked' => count($report), 'connections' => $report]);
        } else {
            $this->components->info(($this->applying() ? 'Checked' : 'Preview (nothing called; re-run with --apply)').' '.count($report).' Stripe connection(s).');
            foreach ($report as $line) {
                $this->components->twoColumnDetail($line['workspace'], $line['status'].(isset($line['missing']) && $line['missing'] !== [] ? ' - missing '.implode(', ', $line['missing']) : '').(isset($line['reason']) ? ' - '.$line['reason'] : ''));
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Sort key: never checked first, then the older check, then the row id so the
     * order is stable.
     *
     * @return array{0: int, 1: int, 2: int}
     */
    private function staleness(WorkspacePaymentConnection $row): array
    {
        return [$row->webhook_checked_at === null ? 0 : 1, $row->webhook_checked_at?->getTimestamp() ?? 0, $row->id];
    }
}
