<?php

namespace App\Console\Commands\Square;

use App\Console\Commands\MutationCommand;
use App\Services\Square\SquareConfiguration;
use App\Services\Square\SquarePendingSettlements;

final class PruneSquarePendingSettlementsCommand extends MutationCommand
{
    protected $signature = 'svc:square:prune-pending-settlements';

    protected $description = 'Prune up to 1000 expired waiting Square settlement entries for the configured workspace';

    public function handle(SquarePendingSettlements $pending): int
    {
        $publicId = SquareConfiguration::fromConfig()->workspacePublicId;
        $workspace = $publicId === null ? null : $this->workspaceByPublicId($publicId);
        $pruned = $workspace === null ? 0 : $this->preview(fn () => $pending->prune($workspace));
        if ($this->option('format') === 'json') {
            $this->json(['dry_run' => ! $this->applying(), 'pruned' => $pruned]);
        } else {
            $this->info(($this->applying() ? 'Pruned ' : 'Would prune ').$pruned.' pending settlement entries.');
        }

        return self::SUCCESS;
    }
}
