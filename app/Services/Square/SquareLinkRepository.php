<?php

namespace App\Services\Square;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientInvoicePayment;
use App\Models\CompanySquareCustomer;
use App\Models\InvoiceSquareLink;
use App\Support\Billing\InvoicePaymentStatus;
use App\Support\Concurrency\Locks;
use Illuminate\Support\Collection;

/**
 * The tenant-scoped reads of SVC's Square links and mappings (#438).
 *
 * Every query names the workspace of the record it starts from: a link, a
 * mapping or a marker belongs to one workspace, and a row stamped to another
 * is not found rather than refused.
 */
final class SquareLinkRepository
{
    public function activeLink(ClientInvoice $invoice): ?InvoiceSquareLink
    {
        return InvoiceSquareLink::query()
            ->where('workspace_id', $invoice->workspace_id)
            ->where('active_invoice_slot', $invoice->id)
            ->first();
    }

    /**
     * The invoice this Square invoice is actively linked to in the workspace, if any.
     */
    public function activeLinkForSquareInvoice(int $workspaceId, string $squareInvoiceId): ?InvoiceSquareLink
    {
        return InvoiceSquareLink::query()
            ->where('workspace_id', $workspaceId)
            ->where('active_square_invoice_slot', $squareInvoiceId)
            ->first();
    }

    public function activeCustomer(ClientCompany $company): ?CompanySquareCustomer
    {
        return CompanySquareCustomer::query()
            ->where('workspace_id', $company->workspace_id)
            ->where('active_company_slot', $company->id)
            ->first();
    }

    public function activeCustomerByCustomerId(int $workspaceId, string $customerId): ?CompanySquareCustomer
    {
        return CompanySquareCustomer::query()
            ->where('workspace_id', $workspaceId)
            ->where('active_customer_slot', $customerId)
            ->first();
    }

    /**
     * The active mappings of a page of companies in one query, keyed by company id.
     *
     * @param  list<int>  $companyIds
     * @return Collection<int, CompanySquareCustomer>
     */
    public function activeCustomers(int $workspaceId, array $companyIds): Collection
    {
        if ($companyIds === []) {
            return collect();
        }

        return CompanySquareCustomer::query()
            ->where('workspace_id', $workspaceId)
            ->whereIn('active_company_slot', $companyIds)
            ->get()
            ->keyBy(fn (CompanySquareCustomer $mapping): int => (int) $mapping->active_company_slot);
    }

    /**
     * Whether a Stripe payment the client started is still in flight on this
     * invoice. Its intent and client secret are already out; nothing SVC
     * decides now can take them back.
     */
    public function hasPendingStripePayment(ClientInvoice $invoice): bool
    {
        return ClientInvoicePayment::query()
            ->where('workspace_id', $invoice->workspace_id)
            ->where('client_invoice_id', $invoice->id)
            ->where('provider', 'stripe')
            ->where('status', InvoicePaymentStatus::Pending->value)
            ->exists();
    }

    /**
     * The number of an invoice of this agreement with a Stripe payment still
     * in flight, read under a share lock on the payment rows so a payment
     * committed after this transaction's snapshot is still seen.
     */
    public function pendingStripePaymentForAgreement(ClientAgreement $agreement): ?string
    {
        $invoiceIds = ClientInvoicePayment::query()
            ->where('workspace_id', $agreement->workspace_id)
            ->where('provider', 'stripe')
            ->where('status', InvoicePaymentStatus::Pending->value)
            ->whereIn('client_invoice_id', ClientInvoice::query()->select('id')
                ->where('workspace_id', $agreement->workspace_id)
                ->where('client_agreement_id', $agreement->id))
            ->orderBy('id')
            ->tap(Locks::forShare())
            ->pluck('client_invoice_id');
        if ($invoiceIds->isEmpty()) {
            return null;
        }
        $number = ClientInvoice::query()->where('workspace_id', $agreement->workspace_id)->whereKey($invoiceIds->first())->value('invoice_number');

        return is_string($number) ? $number : 'another invoice';
    }

    /** The agreement holding this series as its billed-in-Square marker, if any. */
    public function agreementForSeries(int $workspaceId, string $seriesKey): ?ClientAgreement
    {
        return ClientAgreement::query()
            ->where('workspace_id', $workspaceId)
            ->where('square_series_key', $seriesKey)
            ->first();
    }

    /**
     * The series marker of the agreement an invoice was generated under, if any.
     *
     * Read from the agreement row in the invoice's own workspace: an agreement
     * id that names another tenant's row marks nothing here.
     */
    public function agreementSeriesKey(ClientInvoice $invoice): ?string
    {
        if ($invoice->client_agreement_id === null) {
            return null;
        }

        $key = ClientAgreement::query()
            ->where('workspace_id', $invoice->workspace_id)
            ->whereKey($invoice->client_agreement_id)
            ->value('square_series_key');

        return is_string($key) ? $key : null;
    }
}
