<?php

namespace App\Services\Billing\Autopay;

use App\Models\ClientAutopayConsent;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\InvoiceAutopayAttempt;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Activity\ClientActivityRecorder;
use App\Services\Billing\StripeGateway;
use App\Services\Payments\StripeAccess;
use App\Services\Payments\StripeConnectionHealth;
use App\Support\Auth\ProviderIdentityLiveness;
use App\Support\Auth\ProviderLiveness;
use App\Support\Billing\Autopay\AutopayActorRole;
use App\Support\Billing\Autopay\AutopayAttemptState;
use App\Support\Billing\Autopay\AutopayChargeOutcome;
use App\Support\Billing\Autopay\AutopayChargeResult;
use App\Support\Billing\Autopay\AutopayConnectionRefusal;
use App\Support\Billing\Autopay\AutopayVerdict;
use App\Support\Billing\InvoicePaymentStatus;
use App\Support\Billing\InvoiceStatus;
use App\Support\Payments\StripeHealthRefusal;
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
 *
 * **Consent whose giver has gone** (docs/provider-identity.md, gap 2). Before
 * any of that, with provider identity enforcement on, the consent in force is
 * read: when a client contact gave it and the provider now says their identity
 * has ended (they left the client, say), the invoice is not charged. Its
 * attempt is claimed and held for review at once, marked
 * {@see CONSENT_HOLD_CODE}, Stripe is never called, and the run reports it
 * with the other held attempts for a workspace manager to re-confirm consent
 * with the client. Recording new consent releases the hold on the next run,
 * which then decides the invoice afresh; closing it out as not charged
 * (`invoices.autopay.resolve`) leaves it for manual collection. When the
 * provider cannot answer, the invoice is left alone for the next run. Consent
 * a workspace manager recorded with the client's attestation, and consent of
 * a contact with no provider binding, are not checked.
 */
final class AutopayChargeService
{
    /** A claim this old with no PaymentIntent is reported for review. */
    public const int REVIEW_AFTER_MINUTES = 10;

    /** Marks an attempt held because the consent's client contact no longer has a live identity. */
    public const string CONSENT_HOLD_CODE = 'consent_identity_ended';

    public const string CONSENT_HOLD_REASON = 'Held for review; nothing was sent to Stripe. The client contact whose consent autopay relies on no longer has an active identity, so re-confirm consent with the client.';

    public function __construct(
        private readonly AutopayReader $reader,
        private readonly StripeGateway $gateway,
        private readonly AutopayLedger $ledger,
        private readonly StripeConnectionHealth $health,
        private readonly ClientActivityRecorder $activities,
        private readonly ProviderIdentityLiveness $identities,
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
        /** @var array<int, ProviderLiveness> $consentGivers consent id => its client contact's liveness, asked once per run */
        $consentGivers = [];
        if ($apply) {
            $this->releaseSupersededHolds();
        }

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
            // A connection Stripe refused lately is left alone, and does not
            // use up the run's limit that other workspaces' invoices need.
            if ($this->health->pauses($workspace)) {
                $outcomes[] = $this->outcome($workspace, $invoice, AutopayChargeResult::ConnectionNeedsAttention, $verdict, $context->facts->balance, 'Not charged yet: Stripe refused this workspace\'s connection lately; autopay tries again after it is fixed or after '.StripeConnectionHealth::BACKOFF_HOURS.' hours.');

                continue;
            }
            $consent = $this->consentInForce($workspace, $context->company);
            $giver = $this->consentGiver($consent, $consentGivers);
            if ($giver === ProviderLiveness::Unavailable) {
                // Never charged on an unknown answer, and no attempt is spent: the next run asks again.
                $outcomes[] = $this->outcome($workspace, $invoice, AutopayChargeResult::ConsentCheckUnavailable, $verdict, $context->facts->balance, 'Not charged yet: the identity provider could not confirm that the client contact who consented to autopay is still active; autopay asks again on the next run.');

                continue;
            }
            if ($consent !== null && $giver->ended()) {
                $outcomes[] = $apply
                    ? $this->hold($workspace, $context, $consent)
                    : $this->outcome($workspace, $invoice, AutopayChargeResult::HeldForConsent, $verdict, $context->facts->balance, 'Would hold for review: '.self::CONSENT_HOLD_REASON);

                continue;
            }
            $attempted++;
            $outcomes[] = $apply
                ? $this->charge($workspace, $context, $consent)
                : $this->outcome($workspace, $invoice, AutopayChargeResult::WouldCharge, $verdict, $context->facts->balance, 'Would charge '.$this->reader->methodLabel($context->method));
        }

        return $outcomes;
    }

    /**
     * Claims old enough, with no PaymentIntent: Stripe may or may not have been
     * called. Reported by the command on every run until someone resolves them.
     * A consent hold is reported at once: nothing was sent, so nothing is in
     * flight to wait for.
     *
     * @return list<InvoiceAutopayAttempt>
     */
    public function needingReview(int $limit = 100): array
    {
        return array_values(InvoiceAutopayAttempt::query()
            ->where('state', AutopayAttemptState::Claimed->value)
            ->whereNull('payment_intent_id')
            ->where(function ($held): void {
                $held->where('claimed_at', '<=', $this->clock->now()->utc()->subMinutes(self::REVIEW_AFTER_MINUTES))
                    ->orWhere('failure_code', self::CONSENT_HOLD_CODE);
            })
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->all());
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

    /** Whether the attempt is held because its consent's client contact no longer has a live identity. */
    public static function isConsentHold(InvoiceAutopayAttempt $attempt): bool
    {
        return $attempt->state === AutopayAttemptState::Claimed
            && $attempt->payment_intent_id === null
            && $attempt->failure_code === self::CONSENT_HOLD_CODE;
    }

    private function charge(Workspace $workspace, AutopayContext $context, ?ClientAutopayConsent $consent): AutopayChargeOutcome
    {
        $invoice = $context->invoice;
        $method = $context->method;
        $amount = $context->facts->balance;
        if ($method === null) {
            return $this->outcome($workspace, $invoice, AutopayChargeResult::Withheld, AutopayVerdict::NoDefaultMethod);
        }
        $label = $this->reader->methodLabel($method);

        $attempt = $this->claim($workspace, $context, $amount, $label, $consent);
        if ($attempt === null) {
            return $this->outcome($workspace, $invoice, AutopayChargeResult::AlreadyClaimed, AutopayVerdict::AlreadyAttempted);
        }

        try {
            $fresh = ClientInvoice::query()->where('workspace_id', $workspace->id)->whereKey($invoice->id)->first();
            $again = $fresh === null ? null : $this->reader->context($workspace, $fresh, null, $attempt->id);
            $stillCharges = $again !== null
                && $this->reader->verdict($again)->charges()
                && $again->facts->balance === $amount
                && $again->method?->stripe_payment_method_id === $method->stripe_payment_method_id
                // The consent whose giver was checked is still the one in force.
                && $this->consentInForce($workspace, $again->company)?->id === $consent?->id;
        } catch (Throwable $failure) {
            // Nothing has been sent: leaving the claim would consume the one
            // attempt on a failure that charged no one.
            $this->release($workspace, $attempt);
            throw $failure;
        }
        if (! $stillCharges) {
            $this->release($workspace, $attempt);

            return $this->outcome($workspace, $invoice, AutopayChargeResult::Released, null, $amount, 'The invoice or its autopay consent changed after it was claimed; nothing was sent.');
        }

        return $this->submit($workspace, $invoice, $context->company, $method->stripe_payment_method_id, $method->customer?->stripe_customer_id, $attempt, $amount);
    }

    /** @param array<string, string> $hold the hold marker, for an attempt claimed only to be held */
    private function claim(Workspace $workspace, AutopayContext $context, int $amount, string $label, ?ClientAutopayConsent $consent, array $hold = []): ?InvoiceAutopayAttempt
    {
        try {
            return InvoiceAutopayAttempt::query()->create($hold + [
                'workspace_id' => $workspace->id,
                'client_invoice_id' => $context->invoice->id,
                'client_company_id' => $context->company->id,
                'state' => AutopayAttemptState::Claimed,
                'amount' => $amount,
                'currency' => $context->invoice->currency,
                'limit_amount' => $context->company->autopay_limit_amount,
                'consent_public_id' => $consent?->public_id,
                'payment_method_label' => mb_substr($label, 0, 120),
                'claimed_at' => $this->clock->now($workspace)->utc(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }

    /** The company's latest consent record: the one autopay is charging on. */
    private function consentInForce(Workspace $workspace, ClientCompany $company): ?ClientAutopayConsent
    {
        return ClientAutopayConsent::query()
            ->where('workspace_id', $workspace->id)
            ->where('client_company_id', $company->id)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Whether the person who gave the consent in force still has a live provider identity.
     * Only a client contact's own consent is checked: a manager's attestation records the
     * client's agreement given outside SVC, not a contact's, and SVC's own records turn
     * autopay off. A contact whose account here was deleted, or that has no provider
     * binding, is not the provider's to answer for.
     *
     * @param  array<int, ProviderLiveness>  $asked  answers already given this run, by consent id
     */
    private function consentGiver(?ClientAutopayConsent $consent, array &$asked): ProviderLiveness
    {
        if ($consent === null || $consent->actor_user_id === null) {
            return ProviderLiveness::NotChecked;
        }
        $checked = match ($consent->actor_role) {
            AutopayActorRole::Client => true,
            AutopayActorRole::Provider, AutopayActorRole::System => false,
        };
        if (! $checked) {
            return ProviderLiveness::NotChecked;
        }
        if (! array_key_exists($consent->id, $asked)) {
            $contact = User::query()->find($consent->actor_user_id);
            $asked[$consent->id] = $contact === null ? ProviderLiveness::NotChecked : $this->identities->of($contact);
        }

        return $asked[$consent->id];
    }

    /**
     * Hold the invoice's one attempt for review without calling Stripe: claimed, no
     * PaymentIntent, marked as a consent hold. The activity and the run's report tell a
     * workspace manager to re-confirm consent with the client.
     */
    private function hold(Workspace $workspace, AutopayContext $context, ClientAutopayConsent $consent): AutopayChargeOutcome
    {
        $invoice = $context->invoice;
        $amount = $context->facts->balance;
        $label = $context->method === null ? '' : $this->reader->methodLabel($context->method);
        $attempt = $this->claim($workspace, $context, $amount, $label, $consent, [
            'failure_code' => self::CONSENT_HOLD_CODE,
            'failure_reason' => self::CONSENT_HOLD_REASON,
        ]);
        if ($attempt === null) {
            return $this->outcome($workspace, $invoice, AutopayChargeResult::AlreadyClaimed, AutopayVerdict::AlreadyAttempted);
        }
        $this->activities->record($workspace, $context->company, 'invoice.autopay_held', $invoice, [
            'code' => self::CONSENT_HOLD_CODE,
            'amount' => $amount,
            'currency' => $invoice->currency,
            'consent_id' => $consent->public_id,
        ], null, $attempt->public_id);
        Log::warning('An autopay charge was held for review: the client contact who consented no longer has an active identity.', ['attempt' => $attempt->public_id]);

        return $this->outcome($workspace, $invoice, AutopayChargeResult::HeldForConsent, AutopayVerdict::Charge, $amount, self::CONSENT_HOLD_REASON);
    }

    /**
     * A consent hold whose company has recorded consent since (re-confirmed, or turned
     * off) is given back, so this run decides the invoice afresh on the consent now in
     * force. Nothing was ever sent for a hold.
     */
    private function releaseSupersededHolds(): void
    {
        $holds = InvoiceAutopayAttempt::query()
            ->where('state', AutopayAttemptState::Claimed->value)
            ->whereNull('payment_intent_id')
            ->where('failure_code', self::CONSENT_HOLD_CODE)
            ->orderBy('id')
            ->limit(500)
            ->get();
        foreach ($holds as $hold) {
            $standing = ClientAutopayConsent::query()
                ->where('workspace_id', $hold->workspace_id)
                ->where('client_company_id', $hold->client_company_id)
                ->orderByDesc('id')
                ->value('public_id');
            if ($standing === $hold->consent_public_id) {
                continue;
            }
            InvoiceAutopayAttempt::query()
                ->where('workspace_id', $hold->workspace_id)
                ->whereKey($hold->id)
                ->where('state', AutopayAttemptState::Claimed->value)
                ->whereNull('payment_intent_id')
                ->where('failure_code', self::CONSENT_HOLD_CODE)
                ->delete();
        }
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
            // The card was refused, so Stripe accepted the key.
            $this->health->accepted($workspace, $access);

            return $this->failed($workspace, $invoice, $company, $attempt, $amount, $this->failureOf($refusal), $this->intentIdOf($refusal));
        } catch (InvalidRequestException|AuthenticationException|PermissionException $refusal) {
            if (AutopayConnectionRefusal::refuses($refusal)) {
                return $this->connectionRefused($workspace, $invoice, $company, $attempt, $access, $amount, $this->failureOf($refusal));
            }
            $this->health->accepted($workspace, $access);

            return $this->failed($workspace, $invoice, $company, $attempt, $amount, $this->failureOf($refusal), null);
        } catch (Throwable $unknown) {
            // Whether Stripe charged is not known. The claim stays, with no
            // PaymentIntent, and the run reports it until a person resolves it.
            Log::warning('An autopay charge got no usable answer from Stripe; the attempt is held for review.', ['attempt' => $attempt->public_id, 'error' => $unknown::class]);

            return $this->outcome($workspace, $invoice, AutopayChargeResult::NeedsReview, null, $amount, 'Stripe did not answer; check the Stripe dashboard before charging by hand.');
        }

        $this->health->accepted($workspace, $access);
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
        $this->ledger->record($workspace, $invoice, $attempt, $intentId, $amount, InvoicePaymentStatus::Pending);
        $this->activities->record($workspace, $company, 'invoice.autopay_submitted', $invoice, [
            'amount' => $amount,
            'currency' => $invoice->currency,
            'payment_method' => $attempt->payment_method_label,
        ], null, $attempt->public_id);

        return $this->outcome($workspace, $invoice, AutopayChargeResult::Submitted, AutopayVerdict::Charge, $amount, $intentId);
    }

    /**
     * Stripe refused the provider's own key or account, so nobody was charged
     * and the client's card was never tried: give the claim back, so the first
     * run after the connection is fixed charges the invoice, and tell the
     * provider (an activity, once per breakage; the billing cards read the
     * workspace marker). Nothing is recorded on the invoice's attempt.
     *
     * @param  array{code: string, reason: string}  $failure
     */
    private function connectionRefused(Workspace $workspace, ClientInvoice $invoice, ClientCompany $company, InvoiceAutopayAttempt $attempt, StripeAccess $access, int $amount, array $failure): AutopayChargeOutcome
    {
        $this->release($workspace, $attempt);
        // Keep the once-per-breakage activity in the guarded health transition's
        // transaction; reconnect cannot replace the key between the two writes.
        $health = DB::transaction(function () use ($workspace, $company, $invoice, $access, $failure, $amount, $attempt): StripeHealthRefusal {
            $health = $this->health->recordRefusal($workspace, $access);
            if ($health === StripeHealthRefusal::First) {
                $this->activities->record($workspace, $company, 'invoice.autopay_connection_refused', $invoice, [
                    'code' => mb_substr($failure['code'], 0, 64),
                    'amount' => $amount,
                    'currency' => $invoice->currency,
                ], null, $attempt->public_id);
            }

            return $health;
        });
        if ($health === StripeHealthRefusal::Obsolete) {
            return $this->outcome($workspace, $invoice, AutopayChargeResult::Released, null, $amount, 'Stripe refused an earlier connection that has already changed; nothing was charged and the invoice can be tried with the current connection.');
        }
        Log::warning('Stripe refused a workspace connection while autopay was charging; nothing was charged.', ['workspace' => $workspace->public_id, 'code' => $failure['code']]);

        return $this->outcome($workspace, $invoice, AutopayChargeResult::ConnectionNeedsAttention, AutopayVerdict::Charge, $amount, 'Not charged yet: the Stripe connection needs attention ('.$failure['code'].'). Fix it in the workspace payments settings; autopay charges on the first run after.');
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
            $this->ledger->record($workspace, $invoice, $attempt, $intentId, $amount, InvoicePaymentStatus::Failed);
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
        $code = $refusal->getStripeCode() ?? $error->code ?? 'charge_refused';
        $message = trim((string) ($error->message ?? $refusal->getMessage()));

        return ['code' => (string) $code, 'reason' => $message !== '' ? $message : 'Stripe refused the charge.'];
    }

    private function intentIdOf(CardException $refusal): ?string
    {
        $id = $refusal->getError()->payment_intent->id ?? null;

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
