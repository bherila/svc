<?php

namespace App\Console\Commands\Finance;

use App\Http\Resources\Api\V1\PaymentReconciliationResource;
use App\Models\Workspace;
use App\Services\Finance\FinanceReconciliationOperations;
use App\Support\Finance\FinanceCaller;
use App\Support\Finance\ReconciliationAllocation;
use App\Support\Finance\ReconciliationTarget;

/** The console twin of `finance.reconciliations.upsert`; see {@see FinanceCommand}. */
class ReconcilePaymentCommand extends FinanceMutationCommand
{
    protected $signature = 'svc:finance:reconcile
        {payment : Payment public UUID}
        {system : External finance system, as a lowercase slug}
        {transaction : The external system\'s transaction UUID}
        {--workspace= : Workspace public UUID (required)}
        {--user= : Public UUID of the workspace owner or admin acting (required)}
        {--amount= : Minor units of the payment the transaction covers (required)}
        {--currency= : The payment\'s currency, e.g. USD (required)}
        {--reconciled-on= : The day it was reconciled, YYYY-MM-DD}
        {--inactive : Record the allocation without it counting}
        {--format=text : Output text or json}';

    protected $description = 'Create or update a finance reconciliation allocation against a received payment';

    protected function perform(FinanceReconciliationOperations $operations, FinanceCaller $caller, Workspace $workspace): array
    {
        $input = [
            'allocated_amount' => $this->option('amount'),
            'currency' => $this->option('currency'),
            'is_active' => $this->option('inactive') ? '0' : '1',
        ];
        if ($this->option('reconciled-on') !== null) {
            $input['reconciled_on'] = (string) $this->option('reconciled-on');
        }
        $allocation = $operations->upsert(
            $caller,
            $workspace,
            ReconciliationTarget::of((string) $this->argument('payment'), (string) $this->argument('system'), (string) $this->argument('transaction')),
            ReconciliationAllocation::fromInput($input),
        );

        return ['created' => $allocation->wasRecentlyCreated, 'data' => (new PaymentReconciliationResource($allocation))->resolve()];
    }

    protected function summarize(array $result): void
    {
        $allocation = $result['data'];
        $this->components->info($result['created'] ? 'Allocation created.' : 'Allocation updated.');
        $this->components->twoColumnDetail('Allocation', $allocation['id']);
        $this->components->twoColumnDetail('Amount', $allocation['allocated_amount'].' '.$allocation['currency']);
        $this->components->twoColumnDetail('Active', $allocation['is_active'] ? 'yes' : 'no');
    }
}
