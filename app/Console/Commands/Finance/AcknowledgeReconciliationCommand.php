<?php

namespace App\Console\Commands\Finance;

use App\Http\Resources\Api\V1\PaymentReconciliationResource;
use App\Models\Workspace;
use App\Services\Finance\FinanceReconciliationOperations;
use App\Support\Finance\FinanceCaller;
use App\Support\Finance\ReconciliationReviewAcknowledgement;
use App\Support\Finance\ReconciliationTarget;

/** The console twin of conditional finance allocation-review acknowledgement. */
class AcknowledgeReconciliationCommand extends FinanceMutationCommand
{
    protected $signature = 'svc:finance:acknowledge-reconciliation
        {payment : Payment public UUID}
        {system : External finance system, as a lowercase slug}
        {transaction : External transaction UUID}
        {--workspace= : Workspace public UUID (required)}
        {--user= : Acting owner or admin public UUID (required)}
        {--expected-payment-version= : Current opaque payment version (required)}
        {--expected-review-reason= : Current processor_refund or processor_dispute reason (required)}
        {--expected-review-flagged-at= : Current review timestamp with timezone (required)}
        {--confirm : Affirm that this exact allocation is still correct}
        {--format=text : Output text or json}';

    protected $description = 'Acknowledge the current allocation review without changing money or activity';

    protected function perform(FinanceReconciliationOperations $operations, FinanceCaller $caller, Workspace $workspace): array
    {
        $proof = ReconciliationReviewAcknowledgement::fromInput([
            'expected_payment_version' => $this->option('expected-payment-version'),
            'expected_review_reason' => $this->option('expected-review-reason'),
            'expected_review_flagged_at' => $this->option('expected-review-flagged-at'),
        ]);
        $allocation = $operations->acknowledge(
            $caller,
            $workspace,
            ReconciliationTarget::of((string) $this->argument('payment'), (string) $this->argument('system'), (string) $this->argument('transaction')),
            $proof,
            $this->option('confirm'),
        );

        return ['data' => (new PaymentReconciliationResource($allocation))->resolve()];
    }

    protected function summarize(array $result): void
    {
        $this->components->info('The allocation review is acknowledged; money and activity are unchanged.');
        $this->components->twoColumnDetail('Allocation', $result['data']['id']);
    }
}
