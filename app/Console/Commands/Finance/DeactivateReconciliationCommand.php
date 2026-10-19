<?php

namespace App\Console\Commands\Finance;

use App\Http\Resources\Api\V1\PaymentReconciliationResource;
use App\Models\Workspace;
use App\Services\Finance\FinanceReconciliationOperations;
use App\Support\Finance\FinanceCaller;
use App\Support\Finance\ReconciliationTarget;

/** The console twin of `finance.reconciliations.deactivate`; see {@see FinanceCommand}. */
class DeactivateReconciliationCommand extends FinanceCommand
{
    protected $signature = 'svc:finance:unreconcile
        {payment : Payment public UUID}
        {system : External finance system, as a lowercase slug}
        {transaction : The external system\'s transaction UUID}
        {--workspace= : Workspace public UUID (required)}
        {--user= : Public UUID of the workspace owner or admin acting (required)}
        {--format=text : Output text or json}';

    protected $description = 'Stop a finance reconciliation allocation counting; it is kept with its history';

    protected function perform(FinanceReconciliationOperations $operations, FinanceCaller $caller, Workspace $workspace): array
    {
        $allocation = $operations->deactivate(
            $caller,
            $workspace,
            ReconciliationTarget::of((string) $this->argument('payment'), (string) $this->argument('system'), (string) $this->argument('transaction')),
        );

        return ['data' => (new PaymentReconciliationResource($allocation))->resolve()];
    }

    protected function summarize(array $result): void
    {
        $this->components->info('Allocation deactivated.');
        $this->components->twoColumnDetail('Allocation', $result['data']['id']);
    }
}
