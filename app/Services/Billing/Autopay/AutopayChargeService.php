<?php

namespace App\Services\Billing\Autopay;

use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\InvoiceAutopayAttempt;
use App\Models\Workspace;
use App\Services\Activity\ClientActivityRecorder;
use App\Services\Billing\InvoiceLifecycleService;
use App\Services\Billing\StripeGateway;
use App\Services\Payments\StripeAccess;
use App\Support\Billing\Autopay\AutopayAttemptState;
use App\Support\Billing\Autopay\AutopayChargeOutcome;
use App\Support\Billing\Autopay\AutopayChargeResult;
use App\Support\Billing\Autopay\AutopayVerdict;
use App\Support\Billing\InvoicePaymentStatus;
use App\Support\Billing\InvoiceStatus;
use App\Support\WorkspaceClock;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\AuthenticationException;
use Stripe\Exception\CardException;
use Stripe\Exception\InvalidRequestException;
use Stripe\Exception\PermissionException;
use Throwable;

/**
 * Charges the invoices autopay is due to charge (#636), from the scheduled
 * `svc:billing:charge-autopay` command. No queue: the charge happens in the
 * run that found it.
 *
 * One attempt per invoice, ever:
 *
 * 1. **Claim.** An `invoice_autopay_attempts` row is inserted, committed on its
 *    own. `(workspace, invoice)` is unique, so of any number of concurrent or
 *    repeated runs exactly one gets the row and the rest stop.
 * 2. **Last word.** The facts are read again with the claim in place. If the
 *    invoice moved since the first read - a payment, a void, a removed method -
 *    the claim is released and nothing was sent.
 * 3. **Charge.** One off-session PaymentIntent, outside every transaction, with
 *    a Stripe idempotency key derived from the attempt, so even a replayed call
 *    cannot charge twice.
 * 4. **Record.** The outcome is written onto the attempt, and a pending payment
 *    carrying the PaymentIntent id joins the ordinary ledger; Stripe's webhook
 *    settles it exactly as it settles a portal payment. No second bookkeeping.
 *
 * A crash, or an answer we cannot read, between 2 and 4 leaves the attempt
 * `claimed` with no PaymentIntent. That is reported for review and never
 * retried: whether Stripe charged is a question for a person and the Stripe
 * dashboard, not for a loop.
 */
final class AutopayChargeService
{
    /** A claim this old with no PaymentIntent is reported for review. */
    public const int REVIEW_AFTER_MINUTES = 10;

    public function __construct(
        private readonly AutopayReader $reader,
        private readonly StripeGateway $gateway,
        private readonly InvoiceLifecycleService $invoices,
        private readonly ClientActivityRecorder $activities,
        private readonly WorkspaceClock $clock = new WorkspaceClock,
    ) {}

    /**
     * One pass: look at the invoices that could be due and charge up to `$limit`
     * of those that are. Without `$apply`, report what would be charged and
     * touch nothing.
     *
     * @return list<AutopayChargeOutcome>
     */
    public function run(int $limit, bool $apply): array
    {
        $outcomes = [];
        $attempted = 0;
        /** @var array<int, Workspace|null> $workspaces */
        $workspaces = [];

        foreach ($this->candidates() as $invoice) {
            if ($attempted >= $limit) {
                break;
            }
            $workspace = $workspaces[$invoice->workspace_id] ??= Workspace::query()->find($invoice->workspace_id);
            if ($workspace === null) {
                continue;
            }
            $context = $this->reader->context($workspace, $invoice);
            $verdict = $this->reader->verdict($context);
            if (! $verdict->charges() || $context->method === null) {
                continue;
            }
            $attempted++;
            $outcomes[] = $apply
                ? $this->charge($workspace, $context)
                : $this->outcome($workspace, $invoice, AutopayChargeResult::WouldCharge, $verdict, $context->facts->balance, 'Would charge '.$this->reader->methodLabel($context->method));
        }

        return $outcomes;
    }

    /**
     * Claims old enough, with no PaymentIntent: Stripe may or may not have been
     * called. Reported by the command on every run until someone resolves them.
     *
     * @return list<InvoiceAutopayAttempt>
     */
    public function needingReview(int $limit = 100): array
    {
        return InvoiceAutopayAttempt::query()
            ->where('state', AutopayAttemptState::Claimed->value)
            ->whereNull('payment_intent_id')
            ->where('claimed_at', '<=', $this->clock->now()->utc()->subMinutes(self::REVIEW_AFTER_MINUTES))
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->all();
    }

    /**
     * Invoices of autopay-enabled companies that might be due: collectible with
     * a balance, never attempted, due by tomorrow in UTC (a workspace ahead of
     * UTC can be a calendar day ahead). The exact test is the eligibility
     * decision; this only keeps the sweep small.
     *
     * @return iterable<ClientInvoice>
     */
    private function candidates(): iterable
    {
        return ClientInvoice::query()
            ->select('client_invoices.*')
            ->join('client_companies', static function ($join): void {
                $join->on('client_companies.workspace_id', '=', 'client_invoices.workspace_id')
                    ->on('client_companies.id', '=', 'client_invoices.client_company_id');
            })
            ->whereNotNull('client_companies.autopay_enabled_at')
            ->whereIn('client_invoices.status', InvoiceStatus::collectible())
            ->where('client_invoices.balance_amount', '>', 0)
            ->where('client_invoices.is_visible_to_client', true)
            ->whereNotNull('client_invoices.due_date')
            ->where('client_invoices.due_date', '<=', $this->clock->now()->utc()->addDay()->toDateString())
            ->whereNotExists(static function ($attempts): void {
                $attempts->selectRaw('1')
                    ->from('invoice_autopay_attempts')
                    ->whereColumn('invoice_autopay_attempts.workspace_id', 'client_invoices.workspace_id')
                    ->whereColumn('invoice_autopay_attempts.client_invoice_id', 'client_invoices.id');
            })
            ->lazyById(200, 'client_invoices.id', 'id');
    }

    private function charge(Workspace $workspace, AutopayContext $context): AutopayChargeOutcome
    {
        $invoice = $context->invoice;
        $method = $context->method;
        $amount = $context->facts->balance;
        if ($method === null) {
            return $this->outcome($workspace, $invoice, AutopayChargeResult::Withheld, AutopayVerdict::NoDefaultMethod);
        }
        $label = $this->reader->methodLabel($method);

        $attempt = $this->claim($workspace, $context, $amount, $label);
        if ($attempt === null) {
            return $this->outcome($workspace, $invoice, AutopayChargeResult::AlreadyClaimed, AutopayVerdict::AlreadyAttempted);
        }

        try {
            $fresh = ClientInvoice::query()->where('workspace_id', $workspace->id)->whereKey($invoice->id)->first();
            $again = $fresh === null ? null : $this->reader->context($workspace, $fresh, null, $attempt->id);
            $stillCharges = $again !== null
                && $this->reader->verdict($again)->charges()
                && $again->facts->balance === $amount
                && $again->method?->stripe_payment_method_id === $method->stripe_payment_method_id;
        } catch (Throwable $failure) {
            // Nothing has been sent: leaving the claim would consume the one
            // attempt on a failure that charged no one.
            $this->release($workspace, $attempt);
            throw $failure;
        }
        if (! $stillCharges) {
            $this->release($workspace, $attempt);

            return $this->outcome($workspace, $invoice, AutopayChargeResult::Released, null, $amount, 'The invoice changed after it was claimed; nothing was sent.');
        }

        return $this->submit($workspace, $invoice, $context->company, $method->stripe_payment_method_id, $method->customer?->stripe_customer_id, $attempt, $amount);
    }

    private function claim(Workspace $workspace, AutopayContext $context, int $amount, string $label): ?InvoiceAutopayAttempt
    {
        try {
            return InvoiceAutopayAttempt::query()->create([
                'workspace_id' => $workspace->id,
                'client_invoice_id' => $context->invoice->id,
                'client_company_id' => $context->company->id,
                'state' => AutopayAttemptState::Claimed,
                'amount' => $amount,
                'currency' => $context->invoice->currency,
                'limit_amount' => $context->company->autopay_limit_amount,
                'consent_public_id' => $this->consentPublicId($workspace, $context->company),
                'payment_method_label' => mb_substr($label, 0, 120),
                'claimed_at' => $this->clock->now($workspace)->utc(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }

    private function consentPublicId(Workspace $workspace, ClientCompany $company): ?string
    {
        $id = DB::table('client_autopay_consents')
            ->where('workspace_id', $workspace->id)
            ->where('client_company_id', $company->id)
            ->orderByDesc('id')
            ->value('public_id');

        return is_string($id) ? $id : null;
    }

    /** Give the claim back; only while nothing has been sent for it. */
    private function release(Workspace $workspace, InvoiceAutopayAttempt $attempt): void
    {
        InvoiceAutopayAttempt::query()
            ->where('workspace_id', $workspace->id)
            ->whereKey($attempt->id)
            ->where('state', AutopayAttemptState::Claimed->value)
            ->whereNull('payment_intent_id')
            ->delete();
    }

    private function submit(Workspace $workspace, ClientInvoice $invoice, ClientCompany $company, string $paymentMethodId, ?string $customerId, InvoiceAutopayAttempt $attempt, int $amount): AutopayChargeOutcome
    {
        $access = $this->gateway->access($workspace);
        if ($access === null || $customerId === null) {
            $this->release($workspace, $attempt);

            return $this->outcome($workspace, $invoice, AutopayChargeResult::Released, null, $amount, 'Stripe is not connected for this workspace; nothing was sent.');
        }

        try {
            $intent = $access->client()->paymentIntents->create([
                'amount' => $amount,
                'currency' => strtolower($invoice->currency),
                'customer' => $customerId,
                'payment_method' => $paymentMethodId,
                'off_session' => true,
                'confirm' => true,
                'description' => 'Invoice '.$invoice->invoice_number.' (autopay)',
                // The keys the webhook resolves an invoice from, as the portal pay path sends them.
                'metadata' => [
                    'invoice_public_id' => $invoice->public_id,
                    'workspace_public_id' => $workspace->public_id,
                    'client_company_public_id' => $company->public_id,
                    'autopay_attempt_public_id' => $attempt->public_id,
                ],
            ], ['idempotency_key' => 'autopay-attempt-'.$attempt->public_id]);
        } catch (CardException $refusal) {
            return $this->failed($workspace, $invoice, $company, $attempt, $amount, $this->failureOf($refusal), $this->intentIdOf($refusal));
        } catch (InvalidRequestException|AuthenticationException|PermissionException $refusal) {
            return $this->failed($workspace, $invoice, $company, $attempt, $amount, $this->failureOf($refusal), null);
        } catch (Throwable $unknown) {
            // Whether Stripe charged is not known. The claim stays, with no
            // PaymentIntent, and the run reports it until a person resolves it.
            Log::warning('An autopay charge got no usable answer from Stripe; the attempt is held for review.', ['attempt' => $attempt->public_id, 'error' => $unknown::class]);

            return $this->outcome($workspace, $invoice, AutopayChargeResult::NeedsReview, null, $amount, 'Stripe did not answer; check the Stripe dashboard before charging by hand.');
        }

        $intentId = (string) $intent->id;
        $status = (string) $intent->status;
        if ($intentId === '') {
            return $this->outcome($workspace, $invoice, AutopayChargeResult::NeedsReview, null, $amount, 'Stripe answered without a payment intent id.');
        }
        if (! in_array($status, ['succeeded', 'processing'], true)) {
            $this->cancelIntent($access, $intentId);

            return $this->failed($workspace, $invoice, $company, $attempt, $amount, ['code' => 'authentication_required', 'reason' => 'The payment needed the client to act, which autopay cannot do.'], $intentId);
        }

        $this->settle($workspace, $attempt, [
            'state' => AutopayAttemptState::Submitted->value,
            'payment_intent_id' => $intentId,
            'resolved_at' => $this->clock->now($workspace)->utc(),
        ]);
        $this->ledger($workspace, $invoice, $attempt, $intentId, $amount, InvoicePaymentStatus::Pending);
        $this->activities->record($workspace, $company, 'invoice.autopay_submitted', $invoice, [
            'amount' => $amount,
            'currency' => $invoice->currency,
            'payment_method' => $attempt->payment_method_label,
        ], null, $attempt->public_id);

        return $this->outcome($workspace, $invoice, AutopayChargeResult::Submitted, AutopayVerdict::Charge, $amount, $intentId);
    }

    /** @param array{code: string, reason: string} $failure */
    private function failed(Workspace $workspace, ClientInvoice $invoice, ClientCompany $company, InvoiceAutopayAttempt $attempt, int $amount, array $failure, ?string $intentId): AutopayChargeOutcome
    {
        $this->settle($workspace, $attempt, [
            'state' => AutopayAttemptState::Failed->value,
            'payment_intent_id' => $intentId,
            'failure_code' => mb_substr($failure['code'], 0, 64),
            'failure_reason' => mb_substr($failure['reason'], 0, 255),
            'resolved_at' => $this->clock->now($workspace)->utc(),
        ]);
        if ($intentId !== null) {
            // The failed row is what the invoice's payment list and the
            // failure filters read; Stripe's own failure event then finds it.
            $this->ledger($workspace, $invoice, $attempt, $intentId, $amount, InvoicePaymentStatus::Failed);
        }
        $this->activities->record($workspace, $company, 'invoice.autopay_failed', $invoice, [
            'amount' => $amount,
            'currency' => $invoice->currency,
            'code' => $failure['code'],
        ], null, $attempt->public_id);

        return $this->outcome($workspace, $invoice, AutopayChargeResult::Failed, AutopayVerdict::Charge, $amount, $failure['reason']);
    }

    /** @param array<string, mixed> $values */
    private function settle(Workspace $workspace, InvoiceAutopayAttempt $attempt, array $values): void
    {
        InvoiceAutopayAttempt::query()
            ->where('workspace_id', $workspace->id)
            ->whereKey($attempt->id)
            ->where('state', AutopayAttemptState::Claimed->value)
            ->update($values + ['updated_at' => $this->clock->now($workspace)->utc()]);
    }

    private function ledger(Workspace $workspace, ClientInvoice $invoice, InvoiceAutopayAttempt $attempt, string $intentId, int $amount, InvoicePaymentStatus $status): void
    {
        try {
            // The webhook can win the race and create the payment from the
            // intent's metadata; then there is already a row to leave alone.
            $exists = $invoice->payments()
                ->where('workspace_id', $workspace->id)
                ->where('provider', 'stripe')
                ->where('provider_payment_identifier', $intentId)
                ->exists();
            if ($exists) {
                return;
            }
            $this->invoices->applyPayment($invoice, [
                'amount' => $amount,
                'currency' => $invoice->currency,
                'received_on' => $this->clock->today($workspace)->toDateString(),
                'method' => 'stripe',
                'status' => $status->value,
                'provider' => 'stripe',
                'provider_payment_identifier' => $intentId,
                'idempotency_key' => 'autopay:'.$attempt->public_id,
            ], $workspace);
        } catch (Throwable $failure) {
            // The attempt already says what happened; Stripe's webhook records
            // a success from the intent's metadata whether or not this did.
            Log::warning('An autopay payment could not be entered in the ledger yet.', ['attempt' => $attempt->public_id, 'error' => $failure->getMessage()]);
        }
    }

    private function cancelIntent(StripeAccess $access, string $intentId): void
    {
        try {
            $access->client()->paymentIntents->cancel($intentId);
        } catch (Throwable) {
            // An open intent with no confirmation charges nothing.
        }
    }

    /** @return array{code: string, reason: string} */
    private function failureOf(ApiErrorException $refusal): array
    {
        $error = $refusal->getError();
        $code = $refusal->getStripeCode() ?? $error?->code ?? 'charge_refused';
        $message = trim((string) ($error?->message ?? $refusal->getMessage()));

        return ['code' => (string) $code, 'reason' => $message !== '' ? $message : 'Stripe refused the charge.'];
    }

    private function intentIdOf(CardException $refusal): ?string
    {
        $intent = $refusal->getError()?->payment_intent ?? null;
        $id = is_object($intent) ? ($intent->id ?? null) : (is_array($intent) ? ($intent['id'] ?? null) : null);

        return is_string($id) && preg_match('/^pi_[A-Za-z0-9_]{1,252}$/D', $id) === 1 ? $id : null;
    }

    private function outcome(Workspace $workspace, ClientInvoice $invoice, AutopayChargeResult $result, ?AutopayVerdict $verdict = null, ?int $amount = null, ?string $detail = null): AutopayChargeOutcome
    {
        return new AutopayChargeOutcome(
            (string) $workspace->public_id,
            (string) $invoice->public_id,
            (string) $invoice->invoice_number,
            $result,
            $verdict,
            $amount,
            (string) $invoice->currency,
            $detail,
        );
    }
}
