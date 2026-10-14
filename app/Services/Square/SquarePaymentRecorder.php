<?php

namespace App\Services\Square;

use App\Models\ClientInvoice;
use App\Models\ClientInvoicePayment;
use App\Models\InvoiceSquareLink;
use App\Models\Workspace;
use App\Services\Billing\BillingMaintenance;
use App\Services\Billing\InvoiceLifecycleService;
use App\Services\Billing\RecordReceivedPayment;
use App\Support\Billing\ReceivedPaymentData;
use App\Support\Billing\ReceivedPaymentProvider;
use App\Support\Concurrency\Locks;
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

        DB::transaction(function () use ($workspace, $link, $facts): void {
            BillingMaintenance::share($workspace->id);
            $payment = ClientInvoicePayment::query()->where('workspace_id', $workspace->id)->where('client_invoice_id', $link->client_invoice_id)
                ->where('provider', 'square')->where('provider_payment_identifier', $facts->id)->tap(Locks::forUpdate())->firstOrFail();
            // Completed refunds are cumulative. A slower fetch never undoes one.
            if ($facts->refundedAmount > $payment->refunded_amount) {
                $this->invoices->setRefundedAmount($payment, $facts->refundedAmount, $workspace);
            }
        });
    }
}
