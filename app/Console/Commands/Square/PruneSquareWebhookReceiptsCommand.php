<?php

namespace App\Console\Commands\Square;

use App\Models\Workspace;
use App\Services\Square\SquareConfiguration;
use App\Services\Square\SquareWebhookReceipts;
use Illuminate\Console\Command;

final class PruneSquareWebhookReceiptsCommand extends Command
{
    protected $signature = 'svc:square:prune-webhook-receipts';

    protected $description = 'Prune expired Square webhook receipts for the configured workspace';

    public function handle(SquareWebhookReceipts $receipts): int
    {
        $publicId = SquareConfiguration::fromConfig()->workspacePublicId;
        $workspace = $publicId === null ? null : Workspace::query()->where('public_id', $publicId)->first();
        if ($workspace !== null) {
            do {
                $deleted = $receipts->prune($workspace);
            } while ($deleted === 1000);
        }

        return self::SUCCESS;
    }
}
