<?php

namespace App\Console\Commands\Square;

use App\Console\Commands\MutationCommand;
use App\Services\Square\SquareConfiguration;
use App\Services\Square\SquareWebhookReceipts;

final class PruneSquareWebhookReceiptsCommand extends MutationCommand
{
    protected $signature = 'svc:square:prune-webhook-receipts';

    protected $description = 'Prune expired Square webhook receipts for the configured workspace';

    public function handle(SquareWebhookReceipts $receipts): int
    {
        if (! $this->applying()) {
            return $this->planned();
        }
        $total = 0;
        foreach (SquareConfiguration::servedWorkspaces() as $workspace) {
            do {
                $deleted = $receipts->prune($workspace);
                $total += $deleted;
            } while ($deleted === 1000);
        }

        if ($this->option('format') === 'json') {
            $this->json(['pruned' => $total]);
        }

        return self::SUCCESS;
    }
}
