<?php

namespace App\Console\Commands\Finance;

use App\Console\Commands\Concerns\GuardsWrites;
use App\Models\Workspace;
use App\Services\Finance\FinanceReconciliationOperations;
use App\Support\Finance\FinanceCaller;

abstract class FinanceMutationCommand extends FinanceCommand
{
    use GuardsWrites;

    protected function runOperation(FinanceReconciliationOperations $operations, FinanceCaller $caller, Workspace $workspace): array
    {
        return ['dry_run' => ! $this->applying(), ...$this->preview(fn () => $this->perform($operations, $caller, $workspace))];
    }
}
