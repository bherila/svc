<?php

namespace App\Services\Finance;

use App\Models\ClientInvoicePayment;
use App\Models\PaymentReconciliation;
use App\Models\Workspace;
use App\Services\Billing\BillingMaintenance;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\Concurrency\Locks;
use App\Support\Finance\FinanceCaller;
use App\Support\Finance\ReconciliationReviewAcknowledgement;
use App\Support\Finance\ReconciliationTarget;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** Explicitly affirm an allocation without changing its activity, money or payment policy. */
final class ReconciliationReviewAcknowledgementService
{
    public function acknowledge(FinanceCaller $caller, Workspace $workspace, ReconciliationTarget $target, ReconciliationReviewAcknowledgement $proof): PaymentReconciliation
    {
        Gate::forUser($caller->user)->authorize('manage', $workspace);

        return DB::transaction(function () use ($workspace, $target, $proof): PaymentReconciliation {
            BillingMaintenance::share($workspace->id);
            $payment = ClientInvoicePayment::query()->where('workspace_id', $workspace->id)
                ->where('public_id', $target->paymentPublicId)->tap(Locks::forUpdate())->firstOrFail();
            // Compare the opaque revision before considering a cleared flag.
            // Repeating an old request is a conflict, even when it once worked:
            // the caller must refetch rather than silently accepting a newer review.
            abort_unless(AgentApiVersion::matches($payment, $proof->expectedPaymentVersion), 409, 'The payment changed; refresh the allocation before acknowledging its review.');
            $allocation = PaymentReconciliation::query()->where('workspace_id', $workspace->id)
                ->where('client_invoice_payment_id', $payment->id)
                ->where('external_system_slug', $target->externalSystem)
                ->where('external_transaction_uuid', $target->externalTransactionUuid)
                ->tap(Locks::forUpdate())->firstOrFail();
            abort_unless($allocation->review_reason === $proof->expectedReviewReason
                && $allocation->review_flagged_at !== null
                && $allocation->review_flagged_at->utc()->format('Y-m-d H:i:s.u') === $proof->expectedReviewFlaggedAt->format('Y-m-d H:i:s.u'),
                409, 'The allocation review changed or is already clear; refresh it before acknowledging.');

            // A processor may leave the allocation above net or on a disputed
            // payment. Acknowledgement affirms that allocation exactly as stored;
            // it neither reallocates money nor resolves the processor dispute.
            $allocation->forceFill(['review_reason' => null, 'review_flagged_at' => null])->save();
            $payment->advanceAgentRevision();

            return $allocation;
        });
    }
}
