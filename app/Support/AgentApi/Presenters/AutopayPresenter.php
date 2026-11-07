<?php

namespace App\Support\AgentApi\Presenters;

use App\Models\ClientAutopayConsent;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\Workspace;
use App\Services\Billing\Autopay\AutopayConsentService;
use App\Services\Billing\Autopay\AutopayContext;
use App\Services\Billing\Autopay\AutopayReader;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\Billing\Autopay\AutopayEligibility;
use App\Support\Billing\Autopay\AutopayInvoiceState;
use App\Support\Billing\InvoiceStatus;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Autopay as operators, clients and agents read it (#636): the switch, the
 * limit and the method it charges, the append-only consent record, the
 * invoices it will charge next, and one invoice's attempt.
 */
final class AutopayPresenter
{
    /** Invoices listed as upcoming charges. */
    public const int UPCOMING_LIMIT = 10;

    public function __construct(
        private readonly AutopayReader $reader,
        private readonly AutopayConsentService $consents,
    ) {}

    /** @return array<string, mixed> */
    public function company(Workspace $workspace, ClientCompany $company): array
    {
        $method = $this->reader->defaultMethod($workspace->id, $company->id);

        return [
            'client_id' => $company->public_id,
            'version' => AgentApiVersion::for($company),
            'enabled' => $company->autopay_enabled_at !== null,
            'enabled_at' => self::instant($company->autopay_enabled_at),
            'limit_amount' => $company->autopay_limit_amount === null ? null : (int) $company->autopay_limit_amount,
            'currency' => $this->reader->limitCurrency($workspace, $company),
            'method' => $method === null ? null : [
                'label' => $this->reader->methodLabel($method),
                'brand' => $method->brand,
                'last4' => $method->last4,
                'exp_month' => $method->exp_month,
                'exp_year' => $method->exp_year,
            ],
            'consents' => array_map(fn (ClientAutopayConsent $consent): array => $this->consent($consent), $this->consents->history($workspace, $company)),
            'upcoming' => $this->upcoming($workspace, $company),
        ];
    }

    /** @return array<string, mixed> */
    public function consent(ClientAutopayConsent $consent): array
    {
        return [
            'id' => $consent->public_id,
            'action' => $consent->action->value,
            'actor_name' => $consent->actor_name,
            'actor_role' => $consent->actor_role->value,
            'method' => $consent->method->value,
            'limit_amount' => $consent->limit_amount,
            'currency' => $consent->currency,
            'payment_method_label' => $consent->payment_method_label,
            'wording' => $consent->wording,
            'attestation' => $consent->attestation,
            'reason' => $consent->reason,
            'notice_sent' => $consent->notice_sent,
            'occurred_at' => self::instant($consent->occurred_at),
        ];
    }

    /** @return array<string, mixed> */
    public function invoice(Workspace $workspace, ClientInvoice $invoice, ?ClientCompany $company = null): array
    {
        $context = $this->reader->context($workspace, $invoice, $company);

        return $this->presentContext($context) + [
            'invoice_id' => $invoice->public_id,
            'invoice_version' => AgentApiVersion::for($invoice),
        ];
    }

    /**
     * The invoice-level answer for a context already read.
     *
     * @return array<string, mixed>
     */
    public function presentContext(AutopayContext $context): array
    {
        $state = $this->reader->state($context);
        $attempt = $context->attempt;
        $facts = $context->facts;
        $payment = $context->attemptPayment;

        return [
            'state' => $state->value,
            'state_label' => $state->label(),
            'enabled' => $facts->autopayOn,
            'limit_amount' => $facts->limit,
            'currency' => $context->invoice->currency,
            'amount' => (int) $context->invoice->balance_amount,
            'method_label' => $context->method === null ? null : $this->reader->methodLabel($context->method),
            'will_charge_on' => in_array($state, [AutopayInvoiceState::WillCharge], true) ? $context->invoice->due_date?->toDateString() : null,
            'verdict' => AutopayEligibility::decide($facts->onDueDate())->value,
            'can_skip' => $facts->autopayOn && $attempt === null
                && in_array($facts->invoiceStatus, InvoiceStatus::collectible(), true) && $facts->balance > 0,
            'attempt' => $attempt === null ? null : [
                'id' => $attempt->public_id,
                'state' => $attempt->state->value,
                'amount' => $attempt->amount,
                'currency' => $attempt->currency,
                'payment_method_label' => $attempt->payment_method_label,
                'failure_code' => $attempt->failure_code,
                'failure_reason' => $attempt->failure_reason,
                'payment_status' => $payment === null ? null : (string) $payment->status,
                'skipped_by_name' => $attempt->skippedBy?->name,
                'skipped_at' => self::instant($attempt->skipped_at),
                'claimed_at' => self::instant($attempt->claimed_at),
                'resolved_at' => self::instant($attempt->resolved_at),
            ],
        ];
    }

    /**
     * The invoices autopay will charge next, soonest first, each with how it
     * will be treated: charged, or over the limit and left to the client.
     *
     * @return list<array<string, mixed>>
     */
    public function upcoming(Workspace $workspace, ClientCompany $company): array
    {
        if ($company->autopay_enabled_at === null) {
            return [];
        }
        $rows = [];
        $invoices = ClientInvoice::query()
            ->where('workspace_id', $workspace->id)
            ->where('client_company_id', $company->id)
            ->whereIn('status', InvoiceStatus::collectible())
            ->where('balance_amount', '>', 0)
            ->where('is_visible_to_client', true)
            ->whereNotNull('due_date')
            ->orderBy('due_date')
            ->orderBy('id')
            ->limit(self::UPCOMING_LIMIT)
            ->get();
        foreach ($invoices as $invoice) {
            $context = $this->reader->context($workspace, $invoice, $company);
            $state = $this->reader->state($context);
            if ($state === AutopayInvoiceState::NotApplicable) {
                continue;
            }
            $rows[] = [
                'invoice_id' => $invoice->public_id,
                'invoice_number' => $invoice->invoice_number,
                'due_on' => $invoice->due_date?->toDateString(),
                'amount' => (int) $invoice->balance_amount,
                'currency' => $invoice->currency,
                'state' => $state->value,
                'state_label' => $state->label(),
            ];
        }

        return $rows;
    }

    private static function instant(?DateTimeInterface $at): ?string
    {
        return $at === null ? null : CarbonImmutable::instance($at)->utc()->format('Y-m-d\TH:i:s\Z');
    }
}
