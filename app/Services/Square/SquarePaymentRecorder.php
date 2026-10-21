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
     * Apply the complete current group, not a single dispute: a newer win for
     * one dispute cannot release money another still contests. The snapshot
     * is applied only to the payment revision read before the provider call.
     *
     * @param  list<SquareDisputeFacts>  $disputes
     */
    public function recordDisputes(Workspace $workspace, string $paymentId, array $disputes, int $expectedVersion): void
    {
        DB::transaction(function () use ($workspace, $paymentId, $disputes, $expectedVersion): void {
            BillingMaintenance::share($workspace->id);
            $payment = ClientInvoicePayment::query()->where('workspace_id', $workspace->id)
                ->where('provider', 'square')->where('provider_payment_identifier', $paymentId)->tap(Locks::forUpdate())->first();
            if ($payment === null || $payment->lock_version !== $expectedVersion) {
                throw SquareRequestFailed::unreadable();
            }
            $withheld = 0;
            $updatedAtMs = 0;
            foreach ($disputes as $dispute) {
                if ($dispute->paymentId !== $paymentId || $dispute->currency !== $payment->currency || $dispute->amount > $payment->amount) {
                    throw new DomainException('Square dispute facts do not match the recorded payment.');
                }
                $updatedAtMs = max($updatedAtMs, $dispute->updatedAtMs);
                if (! $dispute->resolvedForMerchant) {
                    if ($dispute->amount > PHP_INT_MAX - $withheld) {
                        throw new DomainException('Square dispute amounts exceed the supported payment amount.');
                    }
                    $withheld += $dispute->amount;
                }
            }
            if ($disputes === [] || $withheld > $payment->amount) {
                throw new DomainException('Square dispute amounts exceed the supported payment amount.');
            }
            // Keep the old single-dispute stale-read protection as well as
            // revision checking. Equal maxima never prove a group is current.
            $applied = $payment->provider_dispute_updated_at_ms;
            if ($applied !== null && $updatedAtMs < $applied) {
                return;
            }
            $current = InvoicePaymentStatus::tryFrom((string) $payment->status);
            if ($withheld === 0) {
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
                    $this->invoices->setPaymentDisputed($payment, $withheld, $workspace);
                }
            }
            ClientInvoicePayment::query()->where('workspace_id', $workspace->id)->whereKey($payment->id)->update([
                'provider_dispute_updated_at_ms' => $updatedAtMs,
                // A builder update never reaches IncrementsAgentRevision.
                'lock_version' => DB::raw('lock_version + 1'),
            ]);
        });
    }
}
