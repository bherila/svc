<?php

namespace App\Services\Square;

use App\Models\ClientInvoice;
use App\Models\ClientInvoicePayment;
use App\Models\InvoiceSquareLink;
use App\Models\Workspace;
use App\Services\Billing\BillingMaintenance;
use App\Services\Billing\InvoiceLifecycleService;
use App\Services\Billing\RecordReceivedPayment;
use App\Support\Billing\InvoicePaymentStatus;
use App\Support\Billing\ReceivedPaymentData;
use App\Support\Billing\ReceivedPaymentProvider;
use App\Support\Concurrency\Locks;
use App\Support\Square\SquareDisputeFacts;
use App\Support\Square\SquarePaymentFacts;
use DomainException;
use Illuminate\Support\Facades\DB;

/** Tenant-scoped boundary between a read-only adapter and the billing ledger. */
final class SquarePaymentRecorder
{
    public function __construct(private readonly RecordReceivedPayment $received, private readonly InvoiceLifecycleService $invoices) {}

    public function record(Workspace $workspace, InvoiceSquareLink $link, SquarePaymentFacts $facts): void
    {
        if ($link->workspace_id !== $workspace->id || $facts->currency !== $link->currency) {
            throw new DomainException('Square payment ownership or currency does not match.');
        }
        // No HTTP in either transaction. Payment corrections acquire payment,
        // reconciliations, invoice and company in the existing billing order.
        DB::transaction(function () use ($workspace, $link, $facts): void {
            BillingMaintenance::share($workspace->id);
            $invoice = ClientInvoice::query()->where('workspace_id', $workspace->id)->whereKey($link->client_invoice_id)->tap(Locks::forUpdate())->firstOrFail();
            $active = InvoiceSquareLink::query()->where('workspace_id', $workspace->id)->whereKey($link->id)->where('active_invoice_slot', $invoice->id)->first();
            if ($active === null) {
                throw new DomainException('The Square invoice link changed during reconciliation.');
            }
            $data = ReceivedPaymentData::from([
                'amount' => $facts->amount, 'currency' => $facts->currency,
                'received_on' => $facts->receivedAt->setTimezone($workspace->timezone ?? (string) config('app.timezone', 'UTC'))->toDateString(),
                'method' => 'square',
            ]);
            $this->received->recordProvider($workspace, $invoice, $data, new ReceivedPaymentProvider('square', $facts->id));
        });

        $this->refundExisting($workspace, $facts);
    }

    /** A received payment belongs to its original invoice even after unlinking. */
    public function refundExisting(Workspace $workspace, SquarePaymentFacts $facts): bool
    {
        return DB::transaction(function () use ($workspace, $facts): bool {
            BillingMaintenance::share($workspace->id);
            $payment = ClientInvoicePayment::query()->where('workspace_id', $workspace->id)
                ->where('provider', 'square')->where('provider_payment_identifier', $facts->id)->tap(Locks::forUpdate())->first();
            if ($payment === null) {
                return false;
            }
            if ($payment->amount !== $facts->amount || $payment->currency !== $facts->currency) {
                throw new DomainException('Square refund facts do not match the recorded payment.');
            }
            // Completed refunds are cumulative. A slower fetch never undoes one.
            if ($facts->refundedAmount > $payment->refunded_amount) {
                $this->invoices->setRefundedAmount($payment, $facts->refundedAmount, $workspace);
            }

            return true;
        });
    }

    /**
     * Move a recorded payment into or out of dispute, as Square now reports
     * the dispute. A dispute on a payment svc never recorded changes nothing.
     *
     * An open, lost or accepted dispute holds back only the amount it contests
     * ({@see InvoiceLifecycleService::setPaymentDisputed()}); a won dispute or
     * a closed inquiry restores the payment, as a whole win does. A refunded
     * payment stays refunded. Reconciliation allocations are kept and flagged
     * by the status change.
     *
     * Two notifications for one dispute are read from Square independently and
     * can reach the payment in either order, so Square's own update time of
     * the dispute last applied is kept on the payment: an older read changes
     * nothing, and the same one again is applied again, which is harmless.
     */
    public function recordDispute(Workspace $workspace, SquareDisputeFacts $dispute): bool
    {
        return DB::transaction(function () use ($workspace, $dispute): bool {
            BillingMaintenance::share($workspace->id);
            $payment = ClientInvoicePayment::query()->where('workspace_id', $workspace->id)
                ->where('provider', 'square')->where('provider_payment_identifier', $dispute->paymentId)->tap(Locks::forUpdate())->first();
            if ($payment === null) {
                return false;
            }
            if ($dispute->currency !== $payment->currency || $dispute->amount > $payment->amount) {
                throw new DomainException('Square dispute facts do not match the recorded payment.');
            }
            $applied = $payment->provider_dispute_updated_at_ms;
            if ($applied !== null && $dispute->updatedAtMs < $applied) {
                return true;
            }
            $current = InvoicePaymentStatus::tryFrom((string) $payment->status);
            if ($dispute->resolvedForMerchant) {
                if ($current === InvoicePaymentStatus::Disputed) {
                    $this->invoices->setPaymentStatus($payment, InvoicePaymentStatus::Succeeded->value, $workspace);
                }
            } else {
                $holds = match ($current) {
                    InvoicePaymentStatus::Succeeded, InvoicePaymentStatus::Disputed => true,
                    InvoicePaymentStatus::Pending, InvoicePaymentStatus::Failed, InvoicePaymentStatus::Canceled,
                    InvoicePaymentStatus::Refunded, null => false,
                };
                if ($holds) {
                    $this->invoices->setPaymentDisputed($payment, $dispute->amount, $workspace);
                }
            }
            ClientInvoicePayment::query()->where('workspace_id', $workspace->id)->whereKey($payment->id)->update([
                'provider_dispute_updated_at_ms' => $dispute->updatedAtMs,
                // A builder update never reaches IncrementsAgentRevision.
                'lock_version' => DB::raw('lock_version + 1'),
            ]);

            return true;
        });
    }
}
