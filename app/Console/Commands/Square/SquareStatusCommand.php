<?php

namespace App\Console\Commands\Square;

use App\Models\Workspace;
use App\Services\Square\Mirror\SquareMirrorReadService;
use App\Services\Square\SquareConfiguration;
use Illuminate\Console\Command;

/**
 * Whether Square is configured, and how its last mirror sync went (#458),
 * without exposing a credential and without calling Square. The post-deploy
 * probe runs it beside the Stripe and Brevo probes.
 *
 * Exits 0 when Square is configured, its workspace exists and the latest sync
 * run did not fail; 1 when Square is not configured or that is not so, which
 * the probe reports without failing a deploy. The last run is read from svc's
 * own records, through the same freshness the mirror reads report.
 */
final class SquareStatusCommand extends Command
{
    protected $signature = 'svc:square:status {--format=text : Output text or json}';

    protected $description = 'Report whether Square is configured and how the last mirror sync ended, without exposing credentials';

    public function handle(SquareMirrorReadService $mirror): int
    {
        $format = (string) $this->option('format');
        if (! in_array($format, ['text', 'json'], true)) {
            $this->error('The --format option must be text or json.');

            return self::INVALID;
        }

        $configuration = SquareConfiguration::fromConfig();
        $configured = $configuration->isConfigured();
        $workspace = $configured ? Workspace::query()->where('public_id', (string) $configuration->workspacePublicId)->first() : null;
        $freshness = $workspace === null ? ['last_synced_at' => null, 'last_run' => null] : $mirror->freshness($workspace);
        $lastRun = $freshness['last_run'];
        $status = [
            'provider' => 'square',
            'configured' => $configured,
            'environment' => $configured ? $configuration->environment : null,
            'square_version' => $configured ? $configuration->version : null,
            'workspace_present' => $workspace !== null,
            'webhook_signature_key_present' => is_string($key = config('services.square.webhook_signature_key')) && trim($key) !== '',
            'last_synced_at' => $freshness['last_synced_at'],
            'last_run' => $lastRun === null ? null : array_intersect_key($lastRun, array_flip(['id', 'trigger', 'status', 'requested_at', 'finished_at', 'invoices_seen', 'invoices_changed', 'truncated', 'error_code'])),
        ];
        $healthy = $workspace !== null && ($lastRun === null || $lastRun['status'] !== 'failed');

        if ($format === 'json') {
            $this->line((string) json_encode($status, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } else {
            $this->components->twoColumnDetail('Provider', 'Square');
            $this->components->twoColumnDetail('Configured', $configured ? 'yes' : 'no');
            $this->components->twoColumnDetail('Environment', $status['environment'] ?? 'not configured');
            $this->components->twoColumnDetail('Workspace present', $status['workspace_present'] ? 'yes' : 'no');
            $this->components->twoColumnDetail('Webhook signature key', $status['webhook_signature_key_present'] ? 'present' : 'absent');
            $this->components->twoColumnDetail('Last completed sync', $status['last_synced_at'] ?? 'never');
            $this->components->twoColumnDetail('Last run', $lastRun === null ? 'none' : sprintf('%s (%s)%s', $lastRun['status'], $lastRun['trigger'], $lastRun['error_code'] === null ? '' : ', '.$lastRun['error_code']));
        }

        return $healthy ? self::SUCCESS : self::FAILURE;
    }
}
