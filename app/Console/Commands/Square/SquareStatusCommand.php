<?php

namespace App\Console\Commands\Square;

use App\Console\Commands\SvcCommand;
use App\Models\SquareSyncRun;
use App\Models\Workspace;
use App\Services\Payments\WorkspacePaymentConnections;
use App\Services\Square\Mirror\SquareMirrorPresenter;
use App\Services\Square\Mirror\SquareMirrorReadService;
use App\Services\Square\Mirror\SquareMirrorSync;
use App\Services\Square\SquareConfiguration;
use App\Support\Payments\PaymentProcessor;
use Carbon\CarbonImmutable;

/**
 * Whether Square is configured, and how its last mirror sync went (#458),
 * without exposing a credential and without calling Square. The post-deploy
 * probe runs it beside the Stripe and Brevo probes.
 *
 * Exits 0 when Square is configured, its workspace exists, a sync completed
 * within the hour and the latest run neither failed, was truncated nor was
 * abandoned; 1 otherwise, which the probe reports without failing a deploy. The last run is read from svc's
 * own records: the latest run as the mirror reads report it, and freshness
 * from the authoritative (highest) completed generation.
 */
final class SquareStatusCommand extends SvcCommand
{
    protected $signature = 'svc:square:status {--format=text : Output text or json}';

    protected $description = 'Report whether Square is configured and how the last mirror sync ended, without exposing credentials';

    /** The schedule syncs every fifteen minutes; an hour without a completed sync is overdue. */
    public const int OVERDUE_AFTER_MINUTES = 60;

    public function handle(SquareMirrorReadService $mirror): int
    {
        $format = (string) $this->option('format');

        // One entry per workspace Square serves. The top-level fields summarise
        // them (any/oldest/worst), so no single workspace stands in for the rest.
        $entries = [];
        foreach (SquareConfiguration::servedWorkspaces() as $workspace) {
            $entries[] = $this->workspaceStatus($mirror, $workspace);
        }
        $legacy = SquareConfiguration::legacy();
        $count = count($entries);
        $environments = array_values(array_unique(array_filter(array_column($entries, 'environment'))));
        $versions = array_values(array_unique(array_filter(array_column($entries, 'square_version'))));
        $synced = array_filter(array_column($entries, 'last_synced_at'), 'is_string');
        $status = [
            'provider' => 'square',
            'configured' => $count > 0 || $legacy->isConfigured(),
            'environment' => count($environments) === 1 ? $environments[0] : null,
            'square_version' => count($versions) === 1 ? $versions[0] : null,
            'workspace_present' => $count > 0,
            'webhook_signature_key_present' => $count > 0
                ? ! in_array(false, array_column($entries, 'webhook_signature_key_present'), true)
                : (is_string($key = config('services.square.webhook_signature_key')) && trim($key) !== ''),
            'last_synced_at' => $count > 0 && count($synced) === $count ? min($synced) : null,
            // A single run only describes a single workspace; with several, read `workspaces`.
            'last_run' => $count === 1 ? $entries[0]['last_run'] : null,
            'last_run_abandoned' => in_array(true, array_column($entries, 'last_run_abandoned'), true),
            'sync_overdue' => in_array(true, array_column($entries, 'sync_overdue'), true),
            'last_completed_truncated' => in_array(true, array_column($entries, 'last_completed_truncated'), true),
            'workspaces' => $entries,
        ];
        $healthy = $count > 0 && ! in_array(false, array_column($entries, 'healthy'), true);

        if ($format === 'json') {
            $this->line((string) json_encode($status, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } else {
            $this->components->twoColumnDetail('Provider', 'Square');
            $this->components->twoColumnDetail('Configured', $status['configured'] ? 'yes' : 'no');
            $this->components->twoColumnDetail('Connected workspaces', (string) $count);
            foreach ($entries as $entry) {
                $lastRun = $entry['last_run'];
                $this->components->twoColumnDetail('Workspace', (string) $entry['workspace']);
                $this->components->twoColumnDetail('  Environment', $entry['environment'] ?? 'not configured');
                $this->components->twoColumnDetail('  Webhook signature key', $entry['webhook_signature_key_present'] ? 'present' : 'absent');
                $this->components->twoColumnDetail('  Last completed sync', $entry['last_synced_at'] ?? 'never');
                $this->components->twoColumnDetail('  Last run', $lastRun === null ? 'none' : sprintf('%s (%s)%s%s%s', $lastRun['status'], $lastRun['trigger'], $lastRun['error_code'] === null ? '' : ', '.$lastRun['error_code'], $entry['last_run_abandoned'] ? ', abandoned' : '', $lastRun['truncated'] === true ? ', truncated' : ''));
                $this->components->twoColumnDetail('  Last completed run truncated', $entry['last_completed_truncated'] ? 'yes' : 'no');
                $this->components->twoColumnDetail('  Sync overdue', $entry['sync_overdue'] ? 'yes' : 'no');
            }
        }

        return $healthy ? self::SUCCESS : self::FAILURE;
    }

    /** @return array<string, mixed> */
    private function workspaceStatus(SquareMirrorReadService $mirror, Workspace $workspace): array
    {
        $configuration = SquareConfiguration::for($workspace);
        $freshness = $mirror->freshness($workspace);
        $lastRun = $freshness['last_run'];
        // The authoritative completed generation, as the mirror fences its
        // writes: the highest completed run id, not the last to finish, since
        // overlapping runs can finish out of order. Freshness and truncation
        // both come from it.
        $lastCompleted = SquareSyncRun::query()->where('workspace_id', $workspace->id)->where('status', SquareSyncRun::COMPLETED)
            ->orderByDesc('id')->first(['finished_at', 'truncated']);
        $connection = app(WorkspacePaymentConnections::class)->active($workspace, PaymentProcessor::Square);
        $key = config('services.square.webhook_signature_key');
        // A run left requested or running past the sync's own abandonment
        // threshold, or no completed sync for an hour, means the scheduler or
        // its process stopped; nothing else marks either while it is down.
        $now = CarbonImmutable::now();
        $abandoned = $lastRun !== null && in_array($lastRun['status'], [SquareSyncRun::REQUESTED, SquareSyncRun::RUNNING], true)
            && is_string($lastRun['requested_at']) && CarbonImmutable::parse($lastRun['requested_at'])->lt($now->subMinutes(SquareMirrorSync::ABANDONED_AFTER_MINUTES));
        $overdue = $lastCompleted?->finished_at === null || $lastCompleted->finished_at->lt($now->subMinutes(self::OVERDUE_AFTER_MINUTES));
        // A truncated run completed but left the mirror short of Square -
        // whether it is the latest run, or the completed one a newer run is
        // still retrying.
        $truncated = $lastCompleted?->truncated === true;

        return [
            'workspace' => $workspace->public_id,
            'configured' => $configuration->isConfigured(),
            'environment' => $configuration->isConfigured() ? $configuration->environment : null,
            'square_version' => $configuration->isConfigured() ? $configuration->version : null,
            'webhook_signature_key_present' => $connection !== null
                ? app(WorkspacePaymentConnections::class)->credentialSource($connection)?->webhookSecret() !== null || $connection->isReused()
                : (is_string($key) && trim($key) !== ''),
            'last_synced_at' => SquareMirrorPresenter::instant($lastCompleted?->finished_at),
            'last_run' => $lastRun === null ? null : array_intersect_key($lastRun, array_flip(['id', 'trigger', 'status', 'requested_at', 'finished_at', 'invoices_seen', 'invoices_changed', 'truncated', 'error_code'])),
            'last_run_abandoned' => $abandoned,
            'sync_overdue' => $overdue,
            'last_completed_truncated' => $truncated,
            'healthy' => ! $abandoned && ! $overdue && ! $truncated
                && ($lastRun === null || ($lastRun['status'] !== SquareSyncRun::FAILED && $lastRun['truncated'] !== true)),
        ];
    }
}
