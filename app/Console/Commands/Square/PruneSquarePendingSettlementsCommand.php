<?php

namespace App\Console\Commands\Square;

use App\Models\Workspace;
use App\Services\Square\SquareConfiguration;
use App\Services\Square\SquarePendingSettlements;
use Illuminate\Console\Command;

final class PruneSquarePendingSettlementsCommand extends Command
{
    protected $signature = 'svc:square:prune-pending-settlements';

    protected $description = 'Prune up to 1000 expired waiting Square settlement entries for the configured workspace';

    public function handle(SquarePendingSettlements $pending): int
    {
        $publicId = SquareConfiguration::fromConfig()->workspacePublicId;
        $workspace = $publicId === null ? null : Workspace::query()->where('public_id', $publicId)->first();
        if ($workspace !== null) {
            $pending->prune($workspace);
        }

        return self::SUCCESS;
    }
}
