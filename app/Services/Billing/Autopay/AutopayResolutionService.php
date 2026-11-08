<?php

namespace App\Services\Billing\Autopay;

use App\Exceptions\AutopayVersionConflict;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\InvoiceAutopayAttempt;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Activity\ClientActivityRecorder;
use App\Services\Billing\StripeGateway;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\Billing\Autopay\AutopayAttemptState;
use App\Support\Billing\Autopay\AutopayIntentCheck;
use App\Support\Billing\Autopay\AutopayVerifiedIntent;
use App\Support\WorkspaceClock;
use DomainException;
use Stripe\Exception\ApiErrorException;
use Throwable;

/**
 * Closes out an autopay attempt held for review (#641): claimed, with no
 * PaymentIntent, because Stripe gave no readable answer. After a person has
 * looked in the Stripe dashboard they record what they found.
 *
 * - **Charged**, with the PaymentIntent id. The intent is read back from Stripe
 *   ({@see verify()}, outside any transaction) and must be this workspace's,
 *   this invoice's, this attempt's amount and currency; it then enters the
 *   ledger exactly as the charge path enters it, and Stripe's webhook settles it.
 * - **Not charged**, with a note. The attempt is closed and the invoice is left
 *   for manual collection; no new attempt is made, so one attempt per invoice
 *   still holds.
 *
 * Neither path creates or confirms a PaymentIntent: this class has no way to
 * send a charge. Both close the attempt with a conditional update on state
 * `claimed`, so of two people (or two retries) racing, one wins and the other
 * is told it was already resolved.
 */
final class AutopayResolutionService
{
    public function __construct(
        private readonly StripeGateway $gateway,
        private readonly AutopayLedger $ledger,
        private readonly ClientActivityRecorder $activities,
        private readonly WorkspaceClock $clock = new WorkspaceClock,
    ) {}

    /** Whether the invoice's attempt can be closed out by a person now. */
    public function resolvable(InvoiceAutopayAttempt $attempt): bool
    {
        return $attempt->state === AutopayAttemptState::Claimed
            && $attempt->payment_intent_id === null
            && $attempt->claimed_at->lessThanOrEqualTo($this->clock->now()->utc()->subMinutes(AutopayChargeService::REVIEW_AFTER_MINUTES));
    }

    /**
     * Read the PaymentIntent from Stripe and check it is the held attempt's
     * charge. Call it outside any transaction. Null when the attempt is not
     * held at all (already resolved, or none): there is nothing to check, and
     * {@see recordCharged()} says why.
     *
     * @throws DomainException when Stripe cannot be read or the intent is not this attempt's charge
     */
    public function verify(Workspace $workspace, ClientInvoice $invoice, string $paymentIntentId): ?AutopayVerifiedIntent
    {
        if (preg_match(AutopayIntentCheck::ID_PATTERN, $paymentIntentId) !== 1) {
            throw new DomainException('A PaymentIntent id looks like pi_ followed by letters and digits.');
        }
        $attempt = $this->attemptOf($workspace, $invoice);
        if ($attempt === null || ! $this->resolvable($attempt)) {
            return null;
        }
        $access = $this->gateway->access($workspace);
        if ($access === null) {
            throw new DomainException('Stripe is not connected for this workspace, so the PaymentIntent cannot be checked.');
        }

        try {
            $intent = $access->client()->paymentIntents->retrieve($paymentIntentId)->toArray();
        } catch (ApiErrorException $refusal) {
            throw new DomainException($refusal->getStripeCode() === 'resource_missing'
                ? 'Stripe has no PaymentIntent with that id in this workspace\'s account.'
                : 'Stripe could not read that PaymentIntent; check the id and the connection, then try again.');
        } catch (Throwable) {
            throw new DomainException('Stripe did not answer; try again in a moment.');
        }

        return AutopayIntentCheck::verify(
            $intent,
            $paymentIntentId,
            (string) $workspace->public_id,
            (string) $invoice->public_id,
            $attempt->public_id,
            (int) $attempt->amount,
            (string) $attempt->currency,
        );
    }

    /**
     * Record that the held attempt did charge: the verified intent joins the
     * ledger and the attempt becomes `submitted`.
     *
     * @throws DomainException when the attempt cannot be closed out
     * @throws AutopayVersionConflict
     */
    public function recordCharged(Workspace $workspace, ClientInvoice $invoice, User $actor, ?AutopayVerifiedIntent $verified, ?string $note, ?string $expectedVersion = null): InvoiceAutopayAttempt
    {
        $attempt = $this->heldAttempt($workspace, $invoice, $expectedVersion);
        if ($verified === null) {
            throw new AutopayVersionConflict('The attempt changed while the PaymentIntent was being checked; read it and retry.');
        }
        $this->close($workspace, $attempt, $actor, [
            'state' => AutopayAttemptState::Submitted->value,
            'payment_intent_id' => $verified->id,
        ], $note);
        $this->ledger->record($workspace, $invoice, $attempt, $verified->id, $verified->amount, $verified->status);
        $this->activities->record($workspace, $this->company($workspace, $invoice), 'invoice.autopay_resolved', $invoice, [
            'outcome' => 'charged',
            'amount' => $verified->amount,
            'currency' => $verified->currency,
            'payment_intent_id' => $verified->id,
            'note' => $note,
        ], $actor, $attempt->public_id);

        return $attempt;
    }

    /**
     * Record that the held attempt charged nothing: the invoice is left for
     * manual collection and autopay never tries it again.
     *
     * @throws DomainException when the attempt cannot be closed out
     * @throws AutopayVersionConflict
     */
    public function recordNotCharged(Workspace $workspace, ClientInvoice $invoice, User $actor, string $note, ?string $expectedVersion = null): InvoiceAutopayAttempt
    {
        $note = trim($note);
        if ($note === '') {
            throw new DomainException('Say what you checked in Stripe: a note is required.');
        }
        $attempt = $this->heldAttempt($workspace, $invoice, $expectedVersion);
        $this->close($workspace, $attempt, $actor, ['state' => AutopayAttemptState::NotCharged->value], $note);
        $this->activities->record($workspace, $this->company($workspace, $invoice), 'invoice.autopay_resolved', $invoice, [
            'outcome' => 'not_charged',
            'amount' => $attempt->amount,
            'currency' => $attempt->currency,
            'note' => $note,
        ], $actor, $attempt->public_id);

        return $attempt;
    }

    private function attemptOf(Workspace $workspace, ClientInvoice $invoice): ?InvoiceAutopayAttempt
    {
        return InvoiceAutopayAttempt::query()
            ->where('workspace_id', $workspace->id)
            ->where('client_invoice_id', $invoice->id)
            ->first();
    }

    /** @throws DomainException|AutopayVersionConflict */
    private function heldAttempt(Workspace $workspace, ClientInvoice $invoice, ?string $expectedVersion): InvoiceAutopayAttempt
    {
        $current = ClientInvoice::query()->where('workspace_id', $workspace->id)->whereKey($invoice->id)->firstOrFail();
        if ($expectedVersion !== null && ! AgentApiVersion::matches($current, $expectedVersion)) {
            throw new AutopayVersionConflict('The invoice has changed; read it and retry.');
        }
        $attempt = $this->attemptOf($workspace, $current);
        if ($attempt === null) {
            throw new DomainException('Autopay has not tried to charge this invoice, so there is no attempt to close out.');
        }
        if ($attempt->state !== AutopayAttemptState::Claimed || $attempt->payment_intent_id !== null) {
            throw new DomainException(match ($attempt->state) {
                AutopayAttemptState::Claimed, AutopayAttemptState::Submitted => 'This autopay attempt already has a PaymentIntent, so nothing is held for review.',
                AutopayAttemptState::Failed => 'This autopay attempt already failed and is on record; it is not held for review.',
                AutopayAttemptState::Skipped => 'Autopay was skipped for this invoice; there is no held attempt to close out.',
                AutopayAttemptState::NotCharged => 'This autopay attempt was already closed out as not charged.',
            });
        }
        if (! $this->resolvable($attempt)) {
            throw new DomainException('This autopay attempt may still be in progress; wait '.AutopayChargeService::REVIEW_AFTER_MINUTES.' minutes from when it was claimed before closing it out.');
        }

        return $attempt;
    }

    /**
     * The one write: only while still claimed with no PaymentIntent, so a
     * second resolver, or a charge answering late, cannot overwrite the first.
     *
     * @param  array<string, mixed>  $values
     *
     * @throws AutopayVersionConflict
     */
    private function close(Workspace $workspace, InvoiceAutopayAttempt $attempt, User $actor, array $values, ?string $note): void
    {
        $now = $this->clock->now($workspace)->utc();
        $updated = InvoiceAutopayAttempt::query()
            ->where('workspace_id', $workspace->id)
            ->whereKey($attempt->id)
            ->where('state', AutopayAttemptState::Claimed->value)
            ->whereNull('payment_intent_id')
            ->update($values + [
                'resolved_at' => $now,
                'resolved_by_user_id' => $actor->id,
                'resolution_note' => $note === null || trim($note) === '' ? null : mb_substr(trim($note), 0, 255),
                'updated_at' => $now,
            ]);
        if ($updated !== 1) {
            throw new AutopayVersionConflict('This autopay attempt was closed out by someone else first; read it and retry.');
        }
    }

    private function company(Workspace $workspace, ClientInvoice $invoice): ClientCompany
    {
        return ClientCompany::query()->where('workspace_id', $workspace->id)->whereKey($invoice->client_company_id)->firstOrFail();
    }
}
