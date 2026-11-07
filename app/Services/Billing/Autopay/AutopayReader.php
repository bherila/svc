<?php

namespace App\Services\Billing\Autopay;

use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientInvoicePayment;
use App\Models\ClientStripePaymentMethod;
use App\Models\InvoiceAutopayAttempt;
use App\Models\Workspace;
use App\Services\Payments\PaymentConnectionResolver;
use App\Services\Square\SquareCollectionGuard;
use App\Support\Billing\Autopay\AutopayEligibility;
use App\Support\Billing\Autopay\AutopayFacts;
use App\Support\Billing\Autopay\AutopayInvoiceState;
use App\Support\Billing\Autopay\AutopayInvoiceView;
use App\Support\Billing\Autopay\AutopayVerdict;
use App\Support\Billing\Autopay\AutopayWording;
use App\Support\Billing\InvoicePaymentStatus;
use App\Support\WorkspaceClock;
use Illuminate\Support\Collection;

/**
 * Reads the facts behind {@see AutopayEligibility} for invoices of one
 * workspace. Every read names the workspace; the decisions stay in the pure
 * classes. A bounded number of queries per invoice, none when the company has
 * autopay off and the invoice has never had an attempt.
 */
final class AutopayReader
{
    public function __construct(
        private readonly PaymentConnectionResolver $connections,
        private readonly SquareCollectionGuard $square,
        private readonly WorkspaceClock $clock = new WorkspaceClock,
    ) {}

    /** The company's default saved method, if it has one that is still saved. */
    public function defaultMethod(int $workspaceId, int $companyId): ?ClientStripePaymentMethod
    {
        return ClientStripePaymentMethod::query()
            ->where('workspace_id', $workspaceId)
            ->where('client_company_id', $companyId)
            ->where('is_default', true)
            ->with('customer')
            ->orderBy('id')
            ->first();
    }

    /** The currency a company's limit is in: its billing currency, else the workspace's. */
    public function limitCurrency(Workspace $workspace, ClientCompany $company): string
    {
        $stored = $company->getAttribute('currency');
        $currency = is_string($stored) ? trim($stored) : '';

        return strtoupper($currency !== '' ? $currency : (string) $workspace->default_currency);
    }

    public function methodLabel(ClientStripePaymentMethod $method): string
    {
        return AutopayWording::methodLabel($method->brand, $method->type, $method->last4);
    }

    public function attemptFor(int $workspaceId, int $invoiceId): ?InvoiceAutopayAttempt
    {
        return InvoiceAutopayAttempt::query()
            ->where('workspace_id', $workspaceId)
            ->where('client_invoice_id', $invoiceId)
            ->with('skippedBy')
            ->first();
    }

    /** @param int|null $ignoringAttemptId an attempt to read as absent: the claim being checked is the caller's own */
    public function context(Workspace $workspace, ClientInvoice $invoice, ?ClientCompany $company = null, ?int $ignoringAttemptId = null): AutopayContext
    {
        $company ??= ClientCompany::query()->where('workspace_id', $workspace->id)->whereKey($invoice->client_company_id)->firstOrFail();
        $method = $company->autopay_enabled_at === null ? null : $this->defaultMethod($workspace->id, $company->id);
        $attempt = $this->attemptFor($workspace->id, $invoice->id);
        if ($attempt !== null && $attempt->id === $ignoringAttemptId) {
            $attempt = null;
        }
        $payments = $this->payments($workspace, $invoice, $company->autopay_enabled_at !== null || $attempt !== null);

        $attemptPayment = $attempt?->payment_intent_id === null ? null : $payments->first(
            static fn (ClientInvoicePayment $payment): bool => $payment->provider === 'stripe' && $payment->provider_payment_identifier === $attempt->payment_intent_id,
        );
        $inFlight = $payments->contains(static fn (ClientInvoicePayment $payment): bool => $payment->hasUnreadableStatus()
            || $payment->status === InvoicePaymentStatus::Pending->value);
        $on = $company->autopay_enabled_at !== null && $company->autopay_limit_amount !== null;

        $facts = new AutopayFacts(
            invoiceStatus: (string) $invoice->status,
            balance: (int) $invoice->balance_amount,
            invoiceCurrency: (string) $invoice->currency,
            limitCurrency: $this->limitCurrency($workspace, $company),
            visibleToClient: (bool) $invoice->is_visible_to_client,
            dueOn: $invoice->due_date?->toDateString(),
            today: $this->clock->today($workspace)->toDateString(),
            autopayEnabledOn: $company->autopay_enabled_at?->setTimezone($workspace->timezone)->toDateString(),
            autopayOn: $on,
            limit: $company->autopay_limit_amount === null ? null : (int) $company->autopay_limit_amount,
            hasDefaultMethod: $method !== null,
            priorAttempt: $attempt?->state,
            paymentInFlight: $inFlight,
            squareCollects: $on && $this->square->isSquareCollected($invoice),
            stripeCanCharge: $on && $this->connections->stripe($workspace) !== null,
        );

        return new AutopayContext($invoice, $company, $facts, $method, $attempt, $attemptPayment);
    }

    public function verdict(AutopayContext $context): AutopayVerdict
    {
        return AutopayEligibility::decide($context->facts);
    }

    public function state(AutopayContext $context): AutopayInvoiceState
    {
        $payment = $context->attemptPayment === null ? null : InvoicePaymentStatus::tryFrom((string) $context->attemptPayment->status);

        return AutopayInvoiceView::classify(AutopayEligibility::decide($context->facts->onDueDate()), $context->attempt?->state, $payment);
    }

    /**
     * The line an invoice and its email print when autopay will apply, or null.
     * Free of queries for a company with autopay off.
     */
    public function notice(ClientInvoice $invoice, ?ClientCompany $company): ?string
    {
        if ($company === null || $company->autopay_enabled_at === null || $invoice->due_date === null) {
            return null;
        }
        $workspace = Workspace::query()->find($invoice->workspace_id);
        if ($workspace === null) {
            return null;
        }
        $context = $this->context($workspace, $invoice, $company);
        $onDueDate = AutopayEligibility::decide($context->facts->onDueDate());
        if (! in_array($onDueDate, [AutopayVerdict::Charge, AutopayVerdict::PaymentInFlight], true) || $context->method === null) {
            return null;
        }

        return AutopayWording::invoiceNotice($this->methodLabel($context->method), $invoice->due_date->toDateString());
    }

    /**
     * @param  bool  $load  false answers an empty collection without a query
     * @return Collection<int, ClientInvoicePayment>
     */
    private function payments(Workspace $workspace, ClientInvoice $invoice, bool $load): Collection
    {
        if (! $load) {
            return new Collection;
        }

        return ClientInvoicePayment::query()
            ->where('workspace_id', $workspace->id)
            ->where('client_invoice_id', $invoice->id)
            ->get(['id', 'public_id', 'status', 'provider', 'provider_payment_identifier']);
    }
}
