<?php

namespace App\Console\Commands\Square;

use App\Console\Commands\MutationCommand;
use App\Models\SquareSyncRun;
use App\Models\Workspace;
use App\Services\Square\Mirror\SquareMirrorSync;
use App\Services\Square\SquareConfiguration;

/**
 * The scheduled refresh of svc's Square mirror (#438). Read-only against
 * Square and safe to repeat; does nothing while Square is not configured.
 */
final class SyncSquareMirrorCommand extends MutationCommand
{
    protected $signature = 'svc:square:sync';

    protected $description = 'Refresh the read-only mirror of the connected Square account';

    public function handle(SquareMirrorSync $sync): int
    {
        $configuration = SquareConfiguration::fromConfig();
        if (! $configuration->isConfigured()) {
            if ($this->option('format') === 'json') {
                $this->json(['configured' => false, 'synced' => false]);
            } else {
                $this->components->info('Square is not configured; nothing to sync.');
            }

            return self::SUCCESS;
        }
        $workspace = $configuration->workspace();
        if (! $workspace instanceof Workspace) {
            $this->components->warn('The workspace Square is bound to does not exist.');

            return self::FAILURE;
        }
        if (! $this->applying()) {
            return $this->planned();
        }

        $sync->abandonStale($workspace);
        $run = $sync->perform($workspace, $sync->request($workspace, SquareSyncRun::TRIGGER_SCHEDULE));
        if ($this->option('format') === 'json') {
            $this->json(['run_id' => $run->public_id, 'status' => $run->status, 'error_code' => $run->error_code,
                'invoices_seen' => $run->invoices_seen, 'invoices_changed' => $run->invoices_changed,
                'customers_seen' => $run->customers_seen, 'customers_changed' => $run->customers_changed]);

            return $run->status === SquareSyncRun::COMPLETED ? self::SUCCESS : self::FAILURE;
        }
        if ($run->status !== SquareSyncRun::COMPLETED) {
            $this->components->warn("Square sync {$run->public_id} ended {$run->status} ({$run->error_code}).");

            return self::FAILURE;
        }
        $this->components->info(sprintf(
            'Square sync %s: %d invoices seen, %d changed; %d customers seen, %d changed.',
            $run->public_id,
            $run->invoices_seen,
            $run->invoices_changed,
            $run->customers_seen,
            $run->customers_changed,
        ));

        return self::SUCCESS;
    }
}
