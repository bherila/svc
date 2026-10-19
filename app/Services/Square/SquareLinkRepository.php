<?php

namespace App\Services\Square;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientInvoiceEmailDelivery;
use App\Models\ClientInvoicePayment;
use App\Models\CompanySquareCustomer;
use App\Models\InvoiceSquareLink;
use App\Support\Billing\InvoicePaymentStatus;
use App\Support\Concurrency\Locks;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
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

    /**
     * The active link as committed now, read under a share lock: a current
     * read, so a re-link committed after this transaction's snapshot is seen.
     * Taken after the invoice lock.
     */
    public function currentActiveLinkForSquareInvoice(int $workspaceId, string $squareInvoiceId): ?InvoiceSquareLink
    {
        return InvoiceSquareLink::query()
            ->where('workspace_id', $workspaceId)
            ->where('active_square_invoice_slot', $squareInvoiceId)
            ->tap(Locks::forShare())
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
     * Stripe payment states whose intent can still take the client's money.
     *
     * Pending, and failed too: a declined intent returns to
     * `requires_payment_method` and its client secret can be confirmed again,
     * so it stays live until it succeeds or is canceled in Stripe.
     */
    public const array STRIPE_IN_FLIGHT = [InvoicePaymentStatus::Pending->value, InvoicePaymentStatus::Failed->value];

    /**
     * Whether a Stripe payment the client started can still complete on this
     * invoice. Its intent and client secret are already out; nothing SVC
     * decides now can take them back.
     */
    public function hasStripePaymentInFlight(ClientInvoice $invoice): bool
    {
        return ClientInvoicePayment::query()
            ->where('workspace_id', $invoice->workspace_id)
            ->where('client_invoice_id', $invoice->id)
            ->where('provider', 'stripe')
            ->whereIn('status', self::STRIPE_IN_FLIGHT)
            ->exists();
    }

    /**
     * The number of an invoice of this agreement with a Stripe payment that
     * can still complete, read under a share lock on the payment rows so a payment
     * committed after this transaction's snapshot is still seen.
     */
    public function stripePaymentInFlightForAgreement(ClientAgreement $agreement): ?string
    {
        $invoiceIds = ClientInvoicePayment::query()
            ->where('workspace_id', $agreement->workspace_id)
            ->where('provider', 'stripe')
            ->whereIn('status', self::STRIPE_IN_FLIGHT)
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

    /**
     * Whether an email to the client for this invoice may be leaving right
     * now: registered or claimed since `$since`. Read under a share lock after
     * the invoice lock, so a claim committed while this waited is seen.
     */
    public function hasEmailDeliveryInFlight(ClientInvoice $invoice, CarbonImmutable $since): bool
    {
        return $this->deliveriesInFlight($invoice->workspace_id, $since)
            ->where('client_invoice_id', $invoice->id)
            ->tap(Locks::forShare())
            ->exists();
    }

    /** The number of an invoice of this agreement with an email to the client in flight, if any. */
    public function emailDeliveryInFlightForAgreement(ClientAgreement $agreement, CarbonImmutable $since): ?string
    {
        $invoiceId = $this->deliveriesInFlight($agreement->workspace_id, $since)
            ->whereIn('client_invoice_id', ClientInvoice::query()->select('id')
                ->where('workspace_id', $agreement->workspace_id)
                ->where('client_agreement_id', $agreement->id))
            ->orderBy('id')
            ->tap(Locks::forShare())
            ->value('client_invoice_id');
        if ($invoiceId === null) {
            return null;
        }
        $number = ClientInvoice::query()->where('workspace_id', $agreement->workspace_id)->whereKey($invoiceId)->value('invoice_number');

        return is_string($number) ? $number : 'another invoice';
    }

    /**
     * Deliveries that can still reach the client: registered and not yet
     * claimed, or claimed and not yet resolved, recently. A claim older than
     * that is an ambiguous outcome awaiting reconciliation, not a message about
     * to leave - sending is synchronous - and must not block a hand-off forever.
     *
     * @return Builder<ClientInvoiceEmailDelivery>
     */
    private function deliveriesInFlight(int $workspaceId, CarbonImmutable $since): Builder
    {
        return ClientInvoiceEmailDelivery::query()
            ->where('workspace_id', $workspaceId)
            ->whereIn('status', ['pending', 'sending'])
            ->where(fn (Builder $recent): Builder => $recent->where('claimed_at', '>=', $since)
                ->orWhere(fn (Builder $unclaimed): Builder => $unclaimed->whereNull('claimed_at')->where('queued_at', '>=', $since)));
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
