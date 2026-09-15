<?php

namespace App\Services\Billing;

use App\Mail\InvoiceMail;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientInvoiceEmailDelivery;
use App\Models\Workspace;
use App\Services\WorkspaceAuthorization;
use App\Support\Billing\InvoiceEmailDraft;
use App\Support\Billing\InvoiceLineDetail;
use App\Support\Billing\InvoiceStatus;
use App\Support\Concurrency\Locks;
use App\Support\WorkspaceClock;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Sending an invoice to the client, and knowing whether it went.
 *
 * ## Why this stopped being a queued job
 *
 * "Send to client" recorded a delivery, dispatched `SendInvoiceEmailJob` and
 * told the operator "Invoice delivery queued." On the database queue driver
 * this application runs, that wrote a row to `jobs` and returned. Nothing on
 * the deployment runs `queue:work`, so the row sat there, the delivery stayed
 * `pending` forever, and the screen said the cheerful thing. The button did not
 * appear broken - it appeared to work - which is why it went unnoticed.
 *
 * The tests never caught it because `QUEUE_CONNECTION=sync` under PHPUnit runs
 * the job inline, so the suite asserted a delivery in the `sent` state that
 * production could never reach. A test environment that differs from production
 * on the one axis the feature depends on is not covering the feature.
 *
 * So the send happens in the request now, and the operator is told what
 * actually happened. That is also what makes the confirmation honest: a queued
 * send can only ever promise to try.
 *
 * ## Two entry points, because the caller knows which it needs
 *
 * `send()` delivers in the call and reports the outcome. `sendAfterCommit()`
 * registers the delivery and defers the send until the surrounding transaction
 * commits: the agent API runs its mutations in one so the idempotency receipt
 * and the effect land together, and an email already gone is not something a
 * rollback can take back.
 *
 * The first version of this chose between them by asking `DB::transactionLevel()`,
 * which was wrong in exactly the way this class is about. `RefreshDatabase`
 * wraps every test in a transaction, so the suite took the deferred path on
 * every call and asserted a behaviour production would never take - the same
 * shape of blind spot as the queued job it replaced. Which path to take is the
 * caller's decision, so the caller states it.
 */
final class InvoiceEmailService
{
    private const MAX_AUTOMATIC_ATTEMPTS = 5;

    public function __construct(
        private readonly WorkspaceAuthorization $workspaceAuthorization,
        private readonly InvoiceDocumentService $documents,
        private readonly WorkspaceClock $clock = new WorkspaceClock,
    ) {}

    /**
     * Send this invoice now, and record what happened.
     *
     * @throws DomainException when the invoice cannot be emailed, or the send failed
     */
    public function send(
        ClientInvoice $invoice,
        InvoiceEmailDraft $draft,
        ?Workspace $workspace = null,
        ?string $idempotencyKey = null,
    ): ClientInvoiceEmailDelivery {
        $this->assertSendable($invoice, $workspace);

        $delivery = $this->record($invoice, $draft, 'manual', false, $idempotencyKey);
        if ($delivery->wasRecentlyCreated) {
            return $this->deliver($invoice, $delivery, $draft);
        }

        if ($delivery->status !== 'failed') {
            return $delivery;
        }

        $retry = $this->claimFailedManualDelivery($invoice, $delivery);

        return $retry instanceof ClientInvoiceEmailDelivery
            ? $this->deliver($invoice, $retry, $draft)
            : ($delivery->fresh() ?? $delivery);
    }

    /**
     * Register the delivery now and send it once the caller's work commits.
     *
     * For a caller writing inside a transaction. The delivery comes back
     * `pending`, which is the truth at that moment: nothing has been sent, and
     * nothing will be if the transaction rolls back.
     *
     * A failure after commit has nowhere to be reported - the request that
     * asked for this has been answered - so it lands on the delivery row and in
     * the log rather than as an exception nobody is waiting for.
     *
     * @throws DomainException when the invoice cannot be emailed at all
     */
    public function sendAfterCommit(ClientInvoice $invoice, InvoiceEmailDraft $draft, ?Workspace $workspace = null): ClientInvoiceEmailDelivery
    {
        $this->assertSendable($invoice, $workspace);

        $delivery = $this->record($invoice, $draft, 'manual', true, null);
        $id = $delivery->id;

        if ($delivery->wasRecentlyCreated) {
            DB::afterCommit(fn () => $this->deliverRegistered($invoice, $id, $draft));
        }

        return $delivery;
    }

    /**
     * Send a delivery that was registered earlier, by its id.
     *
     * Extracted from the `afterCommit` closure so it can be tested at all: a
     * callback registered inside a transaction never runs under
     * `RefreshDatabase`, which wraps every test in one, so everything it
     * decided was unreachable from the suite.
     *
     * A delivery that has gone, or that is no longer pending, is not an error.
     * The transaction it was written in may have rolled back after this was
     * registered - which is exactly the case deferring exists to handle - and a
     * delivery already resolved has nothing left to do.
     *
     * Nor is a refusal, here. The request that asked for this has already been
     * answered, so the outcome goes on the delivery row and the reason into the
     * log rather than into an exception nobody is waiting for.
     */
    public function deliverRegistered(ClientInvoice $invoice, int $deliveryId, InvoiceEmailDraft $draft): void
    {
        $registered = DB::transaction(function () use ($invoice, $deliveryId): ?ClientInvoiceEmailDelivery {
            $delivery = ClientInvoiceEmailDelivery::query()
                ->where('workspace_id', $invoice->workspace_id)
                ->where('client_invoice_id', $invoice->id)
                ->whereKey($deliveryId)
                ->tap(Locks::forUpdate())
                ->first();

            if (! $delivery instanceof ClientInvoiceEmailDelivery || $delivery->status !== 'pending') {
                return null;
            }

            $delivery->forceFill([
                'status' => 'sending',
                'claimed_at' => $this->clock->now($delivery->workspace)->utc(),
            ])->save();

            return $delivery;
        });

        if (! $registered instanceof ClientInvoiceEmailDelivery) {
            return;
        }

        try {
            $this->deliver($invoice, $registered, $draft);
        } catch (DomainException $failure) {
            Log::warning('An invoice email failed after commit.', [
                'delivery' => $registered->public_id,
                'reason' => $failure->getMessage(),
            ]);
        }
    }

    /**
     * Which addresses an invoice would go to if nobody said otherwise.
     *
     * The company's billing address first, then every portal user of the
     * company. Offered as suggestions rather than imposed: the compose screen
     * shows them and the operator decides, because the right recipient for one
     * invoice is not always the address on the client record.
     *
     * @return list<array{email: string, label: string}>
     */
    public function suggestedRecipients(ClientInvoice $invoice): array
    {
        $company = $invoice->clientCompany;

        if ($company === null) {
            return [];
        }

        return $this->suggestedRecipientsForCompany($company);
    }

    /**
     * @return list<array{email: string, label: string}>
     */
    public function suggestedRecipientsForCompany(ClientCompany $company, ?string $billingEmail = null): array
    {

        $suggestions = [];
        // A list of the addresses already offered rather than a map to `true`.
        // `isset()` never looks at the value, so the flag could be anything and
        // nothing would change - which is not a thing to leave in code that
        // decides whether a client is emailed twice.
        $seen = [];

        $billing = trim((string) ($billingEmail ?? $company->billing_email));

        if ($billing !== '' && filter_var($billing, FILTER_VALIDATE_EMAIL) !== false) {
            $suggestions[] = ['email' => $billing, 'label' => 'Billing address'];
            $seen[] = strtolower($billing);
        }

        foreach ($company->portalUsers()->wherePivot('workspace_id', $company->workspace_id)->get() as $user) {
            $address = trim($user->email);
            $key = strtolower($address);

            // `continue`, not `break`: one duplicate in the middle of the list
            // must not stop the people after it being offered.
            if ($address === ''
                || filter_var($address, FILTER_VALIDATE_EMAIL) === false
                || in_array($key, $seen, true)) {
                continue;
            }

            $suggestions[] = ['email' => $address, 'label' => $user->name];
            $seen[] = $key;
        }

        return $suggestions;
    }

    /** The subject an operator starts from. */
    public function defaultSubject(ClientInvoice $invoice): string
    {
        return 'Invoice '.$invoice->invoice_number;
    }

    /**
     * The address the client will see this arrive from.
     *
     * Shown on the compose screen because it is the one part of the message the
     * operator who does not know which address their invoices come from cannot
     * tell a client where to reply.
     */
    public function fromAddress(): string
    {
        $address = config('mail.from.address');
        $name = config('mail.from.name');

        if (! is_string($address) || $address === '') {
            return 'Not configured';
        }

        return is_string($name) && $name !== ''
            ? "{$name} <{$address}>"
            : $address;
    }

    /**
     * Claim and send one due automatic delivery after rechecking every mutable
     * eligibility fact under the invoice row lock.
     */
    public function sendAutomatic(ClientInvoice $invoice): ?ClientInvoiceEmailDelivery
    {
        $claimed = DB::transaction(function () use ($invoice): ?array {
            $locked = ClientInvoice::query()
                ->where('workspace_id', $invoice->workspace_id)
                ->whereKey($invoice->id)
                ->tap(Locks::forUpdate())
                ->with(['workspace', 'clientCompany'])
                ->first();

            if (! $locked instanceof ClientInvoice) {
                return null;
            }

            if ($locked->automatic_delivery_status === 'held'
                || $locked->automatic_delivery_status === 'cancelled'
                || $locked->automatic_delivery_status === 'manually_sent'
                || $locked->automatic_delivery_status === 'automatically_sent'
                || $locked->automatic_delivery_status === 'sending') {
                return null;
            }

            $company = $locked->clientCompany;
            $due = $locked->automatic_delivery_due_at;
            if ($company === null
                || ! $company->automatic_invoice_email_enabled
                || $locked->status !== InvoiceStatus::Issued->value
                || $locked->paid_amount > 0
                || $due === null
                || $due->isFuture()) {
                $locked->forceFill([
                    'automatic_delivery_status' => 'cancelled',
                    'automatic_delivery_note' => $company !== null && ! $company->automatic_invoice_email_enabled
                        ? 'Automatic client delivery was disabled.'
                        : 'The invoice is no longer eligible for automatic client delivery.',
                ])->save();

                return null;
            }

            $successful = $locked->emailDeliveries()
                ->where('workspace_id', $locked->workspace_id)
                ->where('status', 'sent')
                ->exists();
            if ($successful) {
                $locked->forceFill([
                    'automatic_delivery_status' => 'manually_sent',
                    'automatic_delivery_note' => 'A successful client delivery already exists.',
                ])->save();

                return null;
            }

            $inFlight = $locked->emailDeliveries()
                ->where('workspace_id', $locked->workspace_id)
                ->whereIn('status', ['pending', 'sending'])
                ->exists();
            if ($inFlight) {
                return null;
            }

            $attempt = $locked->emailDeliveries()
                ->where('workspace_id', $locked->workspace_id)
                ->where('origin', 'automatic')
                ->where('invoice_revision', $locked->document_revision)
                ->count() + 1;
            if ($attempt > self::MAX_AUTOMATIC_ATTEMPTS) {
                return null;
            }

            $recipients = array_column($this->suggestedRecipients($locked), 'email');
            if ($recipients === []) {
                $locked->forceFill([
                    'automatic_delivery_status' => 'held',
                    'automatic_delivery_held_at' => $this->clock->now($locked->workspace)->utc(),
                    'automatic_delivery_note' => 'No valid client billing recipients are configured. Add one, then release automatic sending.',
                ])->save();

                return null;
            }

            $draft = InvoiceEmailDraft::of(
                $recipients,
                [],
                $this->defaultSubject($locked),
                null,
            );
            $delivery = $locked->emailDeliveries()->create([
                'workspace_id' => $locked->workspace_id,
                'origin' => 'automatic',
                'invoice_revision' => $locked->document_revision,
                'attempt_number' => $attempt,
                'recipients' => $draft->recipients,
                'bcc' => null,
                'subject' => $draft->subject,
                'body' => $draft->body,
                'status' => 'sending',
                'queued_at' => $this->clock->now($locked->workspace)->utc(),
                'claimed_at' => $this->clock->now($locked->workspace)->utc(),
            ]);
            $locked->forceFill([
                'automatic_delivery_status' => 'sending',
                'automatic_delivery_note' => null,
            ])->save();

            return [$locked, $delivery, $draft];
        });

        if (! is_array($claimed)) {
            return null;
        }

        /** @var ClientInvoice $locked */
        [$locked, $delivery, $draft] = $claimed;

        try {
            return $this->deliver($locked, $delivery, $draft);
        } catch (DomainException) {
            return $delivery->fresh();
        }
    }

    /** Process a bounded set; each invoice owns its own short claim transaction. */
    public function dispatchAutomaticDue(int $limit = 50): int
    {
        $now = $this->clock->now()->utc();
        $workspaceIds = Workspace::query()
            ->whereExists(fn (QueryBuilder $query): QueryBuilder => $query
                ->selectRaw('1')
                ->from('client_invoices')
                ->whereColumn('client_invoices.workspace_id', 'workspaces.id')
                ->whereIn('automatic_delivery_status', ['scheduled', 'failed'])
                ->whereNotNull('automatic_delivery_due_at')
                ->where('automatic_delivery_due_at', '<=', $now))
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        $inspected = 0;
        foreach ($workspaceIds as $workspaceId) {
            $remaining = $limit - $inspected;
            if ($remaining <= 0) {
                break;
            }
            $invoices = ClientInvoice::query()
                ->where('workspace_id', $workspaceId)
                ->whereIn('automatic_delivery_status', ['scheduled', 'failed'])
                ->whereNotNull('automatic_delivery_due_at')
                ->where('automatic_delivery_due_at', '<=', $now)
                ->orderBy('automatic_delivery_due_at')
                ->orderBy('id')
                ->limit($remaining)
                ->get(['id', 'workspace_id']);

            foreach ($invoices as $invoice) {
                $this->sendAutomatic($invoice);
                $inspected++;
            }
        }

        return $inspected;
    }

    /** @throws DomainException */
    private function assertSendable(ClientInvoice $invoice, ?Workspace $workspace): void
    {
        if ($workspace !== null && ! $this->workspaceAuthorization->isOwnedBy($workspace, $invoice)) {
            throw new DomainException('Invoice does not belong to this workspace.');
        }

        if (! in_array($invoice->status, InvoiceStatus::collectible(), true)) {
            throw new DomainException('Only collectible issued invoices can be emailed.');
        }
    }

    private function record(
        ClientInvoice $invoice,
        InvoiceEmailDraft $draft,
        string $origin,
        bool $deferred,
        ?string $idempotencyKey,
    ): ClientInvoiceEmailDelivery {
        return DB::transaction(function () use ($invoice, $draft, $origin, $deferred, $idempotencyKey): ClientInvoiceEmailDelivery {
            $locked = ClientInvoice::query()
                ->where('workspace_id', $invoice->workspace_id)
                ->whereKey($invoice->id)
                ->tap(Locks::forUpdate())
                ->with('workspace')
                ->firstOrFail();
            $this->assertSendable($locked, null);

            if ($idempotencyKey !== null && $idempotencyKey !== '') {
                $existing = ClientInvoiceEmailDelivery::query()
                    ->where('workspace_id', $locked->workspace_id)
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();
                if ($existing instanceof ClientInvoiceEmailDelivery) {
                    if ($existing->client_invoice_id !== $locked->id
                        || $existing->origin !== $origin
                        || $existing->recipients !== $draft->recipients
                        || ($existing->bcc ?? []) !== $draft->bcc
                        || $existing->subject !== $draft->subject
                        || $existing->body !== $draft->body) {
                        throw new DomainException('The idempotency key is already bound to a different invoice delivery.');
                    }

                    return $existing;
                }
            }

            if ($origin === 'manual' && $locked->automatic_delivery_status === 'automatically_sent') {
                throw new DomainException('This invoice was already delivered automatically.');
            }

            $inFlight = $locked->emailDeliveries()
                ->where('workspace_id', $locked->workspace_id)
                ->whereIn('status', ['pending', 'sending'])
                ->exists();
            if ($inFlight) {
                throw new DomainException('Another client delivery is already in progress for this invoice.');
            }

            $delivery = $locked->emailDeliveries()->create([
                'workspace_id' => $locked->workspace_id,
                'origin' => $origin,
                'invoice_revision' => $locked->document_revision,
                'attempt_number' => 1,
                'idempotency_key' => $idempotencyKey,
                'recipients' => $draft->recipients,
                'bcc' => $draft->bcc === [] ? null : $draft->bcc,
                'subject' => $draft->subject,
                'body' => $draft->body,
                'status' => $deferred ? 'pending' : 'sending',
                'queued_at' => $this->clock->now($locked->workspace)->utc(),
                'claimed_at' => $deferred ? null : $this->clock->now($locked->workspace)->utc(),
            ]);
            $locked->advanceAgentRevision();

            return $delivery;
        });
    }

    /**
     * A provider refusal is a definite outcome, so the same keyed request may
     * be attempted again. Claim the existing row instead of inserting a
     * second one: the key remains one logical delivery, while the row lock
     * prevents two concurrent retries from submitting it together.
     */
    private function claimFailedManualDelivery(
        ClientInvoice $invoice,
        ClientInvoiceEmailDelivery $delivery,
    ): ?ClientInvoiceEmailDelivery {
        return DB::transaction(function () use ($invoice, $delivery): ?ClientInvoiceEmailDelivery {
            $lockedInvoice = ClientInvoice::query()
                ->where('workspace_id', $invoice->workspace_id)
                ->whereKey($invoice->id)
                ->tap(Locks::forUpdate())
                ->with('workspace')
                ->firstOrFail();
            if ($lockedInvoice->automatic_delivery_status === 'automatically_sent') {
                throw new DomainException('This invoice was already delivered automatically.');
            }

            $lockedDelivery = ClientInvoiceEmailDelivery::query()
                ->where('workspace_id', $lockedInvoice->workspace_id)
                ->where('client_invoice_id', $lockedInvoice->id)
                ->whereKey($delivery->id)
                ->tap(Locks::forUpdate())
                ->firstOrFail();
            if ($lockedDelivery->status !== 'failed') {
                return null;
            }
            if ($lockedDelivery->origin !== 'manual') {
                throw new DomainException('Only a failed manual invoice delivery can be retried with its key.');
            }

            $lockedDelivery->forceFill([
                'status' => 'sending',
                'attempt_number' => $lockedDelivery->attempt_number + 1,
                'claimed_at' => $this->clock->now($lockedInvoice->workspace)->utc(),
                'failed_at' => null,
                'next_attempt_at' => null,
                'error_summary' => null,
            ])->save();
            $lockedInvoice->advanceAgentRevision();

            return $lockedDelivery;
        });
    }

    /**
     * Hand the message to the mailer and write down the answer.
     *
     * The provider's message id is kept because it is the only handle the Brevo
     * webhook has to say what became of this message. Without it a delivered or
     * bounced event names an address and a timestamp and nothing that ties it
     * back to an invoice.
     *
     * @throws DomainException when the document or message could not be delivered
     */
    private function deliver(
        ClientInvoice $invoice,
        ClientInvoiceEmailDelivery $delivery,
        InvoiceEmailDraft $draft,
    ): ClientInvoiceEmailDelivery {
        try {
            // Email is a client-facing boundary, so the audience is explicit.
            // Reuse the same document service as the authenticated PDF route;
            // keeping a second email-only renderer would let their disclosure
            // rules drift apart.
            $pdf = $this->documents->pdf($invoice, InvoiceLineDetail::CLIENT);
            $mailer = Mail::to($draft->recipients);

            if ($draft->bcc !== []) {
                $mailer->bcc($draft->bcc);
            }

            $sent = $mailer->send(new InvoiceMail(
                $invoice,
                $draft->subject,
                $draft->body,
                $pdf,
                $this->documents->filename($invoice),
            ));

        } catch (Throwable $exception) {
            return $this->recordFailure($invoice, $delivery, $exception);
        }

        try {
            $persisted = DB::transaction(function () use ($invoice, $delivery, $sent): ClientInvoiceEmailDelivery {
                $lockedInvoice = ClientInvoice::query()
                    ->where('workspace_id', $invoice->workspace_id)
                    ->whereKey($invoice->id)
                    ->tap(Locks::forUpdate())
                    ->with('workspace')
                    ->firstOrFail();
                $lockedDelivery = ClientInvoiceEmailDelivery::query()
                    ->where('workspace_id', $lockedInvoice->workspace_id)
                    ->where('client_invoice_id', $lockedInvoice->id)
                    ->whereKey($delivery->id)
                    ->tap(Locks::forUpdate())
                    ->firstOrFail();
                if ($lockedDelivery->status !== 'sending') {
                    throw new DomainException('The claimed invoice delivery changed before its result was saved.');
                }

                $lockedDelivery->forceFill([
                    'status' => 'sent',
                    'sent_at' => $this->clock->now($lockedInvoice->workspace)->utc(),
                    'provider_message_reference' => $sent?->getMessageId(),
                ])->save();
                if ($lockedInvoice->automatic_delivery_status !== null) {
                    $lockedInvoice->forceFill([
                        'automatic_delivery_status' => $lockedDelivery->origin === 'automatic'
                            ? 'automatically_sent'
                            : 'manually_sent',
                        'automatic_delivery_note' => $lockedDelivery->origin === 'automatic'
                            ? 'Automatically sent to the configured client recipients.'
                            : 'A manual client delivery succeeded.',
                        'automatic_delivery_due_at' => null,
                        'automatic_delivery_held_at' => null,
                    ])->save();
                } else {
                    $lockedInvoice->advanceAgentRevision();
                }

                return $lockedDelivery;
            });

            // The instance in hand rather than a re-read. `fresh()` is nullable
            // - it returns null for a row deleted underneath you - and there is
            // nothing to re-read: the save above wrote these attributes onto
            // this object.
            return $persisted;
        } catch (Throwable $exception) {
            // The provider returned acceptance. If local persistence now
            // fails, leaving the durable claim in `sending` is deliberate:
            // its outcome is ambiguous and a scheduler must never blindly
            // submit the same invoice again.
            Log::critical('An accepted invoice email could not be persisted.', [
                'delivery' => $delivery->public_id,
                'exception' => $exception,
            ]);

            throw new DomainException(
                'The mail provider accepted the request, but its local result could not be saved. Delivery is marked in progress for reconciliation.',
            );
        }
    }

    private function recordFailure(
        ClientInvoice $invoice,
        ClientInvoiceEmailDelivery $delivery,
        Throwable $exception,
    ): never {
        // The class name, not the message. A rendering or transport failure can
        // quote recipient addresses or credentials.
        DB::transaction(function () use ($invoice, $delivery, $exception): void {
            $lockedInvoice = ClientInvoice::query()
                ->where('workspace_id', $invoice->workspace_id)
                ->whereKey($invoice->id)
                ->tap(Locks::forUpdate())
                ->with('workspace')
                ->firstOrFail();
            $lockedDelivery = ClientInvoiceEmailDelivery::query()
                ->where('workspace_id', $lockedInvoice->workspace_id)
                ->where('client_invoice_id', $lockedInvoice->id)
                ->whereKey($delivery->id)
                ->tap(Locks::forUpdate())
                ->firstOrFail();
            $lockedDelivery->forceFill([
                'status' => 'failed',
                'failed_at' => $this->clock->now($lockedInvoice->workspace)->utc(),
                'error_summary' => 'Email delivery failed ('.class_basename($exception).').',
            ]);
            if ($lockedDelivery->origin === 'automatic') {
                $terminal = $lockedDelivery->attempt_number >= self::MAX_AUTOMATIC_ATTEMPTS;
                $nextAttemptAt = $terminal ? null : $this->automaticNextAttemptAt($lockedInvoice, $lockedDelivery->attempt_number);
                $lockedDelivery->next_attempt_at = $nextAttemptAt;
                $lockedInvoice->forceFill([
                    'automatic_delivery_status' => 'failed',
                    'automatic_delivery_due_at' => $nextAttemptAt,
                    'automatic_delivery_note' => $lockedDelivery->error_summary,
                ])->save();
            } else {
                $lockedInvoice->advanceAgentRevision();
            }
            $lockedDelivery->save();
        });

        Log::error('An invoice email could not be sent.', [
            'delivery' => $delivery->public_id,
            'exception' => $exception,
        ]);

        throw new DomainException(
            'Invoice delivery failed ('.class_basename($exception).'). Nothing was sent.',
        );
    }

    private function automaticNextAttemptAt(ClientInvoice $invoice, int $attempt): CarbonImmutable
    {
        $minutes = match ($attempt) {
            1 => 5,
            2 => 30,
            3 => 120,
            4 => 720,
            default => 1440,
        };

        return $this->clock->now($invoice->workspace)->addMinutes($minutes)->utc();
    }
}
