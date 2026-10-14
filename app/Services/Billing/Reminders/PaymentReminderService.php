<?php

namespace App\Services\Billing\Reminders;

use App\Exceptions\PaymentReminderRefused;
use App\Exceptions\PaymentReminderVersionConflict;
use App\Mail\InvoicePaymentReminderMail;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\InvoiceReminderLog;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Activity\ClientActivityRecorder;
use App\Services\Billing\InvoiceDocumentService;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\Billing\InvoiceLineDetail;
use App\Support\Billing\InvoiceStatus;
use App\Support\Billing\Reminders\PaymentReminderDecision;
use App\Support\Billing\Reminders\PaymentReminderFacts;
use App\Support\Billing\Reminders\PaymentReminderOrigin;
use App\Support\Billing\Reminders\PaymentReminderPayLink;
use App\Support\Billing\Reminders\PaymentReminderPolicy;
use App\Support\Billing\Reminders\PaymentReminderStatus;
use App\Support\Billing\Reminders\PaymentReminderStep;
use App\Support\Billing\Reminders\PaymentReminderTrigger;
use App\Support\Concurrency\Locks;
use App\Support\WorkspaceClock;
use DomainException;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Payment reminders for open invoices (#438): sent by a person, or by the
 * per-client cadence the scheduler runs. No queue worker: a reminder leaves in
 * the request or the scheduled command that asked for it.
 *
 * Every reminder is a row before it is a message. The row is written under
 * the invoice lock, the message is sent after that transaction commits, and
 * its outcome is written back. A person's reminder is registered inside the
 * caller's transaction - the agent API's, beside its idempotency receipt - and
 * sent by {@see self::sendRegistered()} once that has committed; an automatic
 * one claims its cadence step, which `(invoice, step)` keeps unique, so
 * retries and concurrent runs never send a step twice. Just before a message
 * leaves, the facts are read again outside every lock, so a payment, a void or
 * a Square link that landed after the claim stops it.
 */
final class PaymentReminderService
{
    public function __construct(
        private readonly PaymentReminderRepository $repository,
        private readonly PaymentReminderPolicy $policy,
        private readonly InvoiceDocumentService $documents,
        private readonly ClientActivityRecorder $activities,
        private readonly WorkspaceClock $clock = new WorkspaceClock,
    ) {}

    /**
     * Register a reminder a person asked for. Nothing is sent: call
     * {@see self::sendRegistered()} once the surrounding transaction commits.
     *
     * @throws PaymentReminderRefused
     */
    public function register(
        Workspace $workspace,
        ClientInvoice $invoice,
        User $actor,
        PaymentReminderTrigger $trigger,
        ?string $oauthClientId = null,
        ?string $expectedVersion = null,
    ): InvoiceReminderLog {
        return DB::transaction(function () use ($workspace, $invoice, $actor, $trigger, $oauthClientId, $expectedVersion): InvoiceReminderLog {
            $locked = $this->lockInvoice($workspace, $invoice);
            if ($expectedVersion !== null && ! AgentApiVersion::matches($locked, $expectedVersion)) {
                throw new PaymentReminderVersionConflict('The invoice has changed; read it and retry.');
            }
            $facts = $this->repository->facts($workspace, $locked);
            $decision = $this->policy->manual($facts);
            if ($decision->refusal !== null) {
                throw PaymentReminderRefused::because($decision->refusal);
            }

            return InvoiceReminderLog::query()->create([
                ...$this->attributes($workspace, $locked, $facts, $decision),
                'origin' => PaymentReminderOrigin::Manual,
                'cadence_step' => null,
                'status' => PaymentReminderStatus::Pending,
                'attempt_number' => 1,
                'triggered_by' => $trigger,
                'triggered_by_user_id' => $actor->id,
                'oauth_client_id' => $trigger === PaymentReminderTrigger::Agent ? $oauthClientId : null,
                'queued_at' => $this->clock->now($workspace)->utc(),
            ]);
        });
    }

    /**
     * Send a registered reminder, and return it as it now stands.
     *
     * One that is no longer pending - already sent, failed, or claimed by a
     * concurrent replay - is returned untouched, so replaying the request that
     * registered it sends nothing more. One whose invoice changed under it is
     * cancelled with the reason rather than sent.
     */
    public function sendRegistered(Workspace $workspace, ClientInvoice $invoice, string $reminderPublicId): InvoiceReminderLog
    {
        $claimed = DB::transaction(function () use ($workspace, $invoice, $reminderPublicId): array {
            $locked = $this->lockInvoice($workspace, $invoice);
            $reminder = InvoiceReminderLog::query()
                ->where('workspace_id', $workspace->id)
                ->where('client_invoice_id', $locked->id)
                ->where('public_id', $reminderPublicId)
                ->tap(Locks::forUpdate())
                ->firstOrFail();
            if ($reminder->status !== PaymentReminderStatus::Pending) {
                return [$locked, $reminder, null];
            }
            $facts = $this->repository->facts($workspace, $locked, $reminder->id);
            $decision = $this->policy->manual($facts);
            if ($decision->refusal !== null) {
                $this->cancel($workspace, $reminder, $decision->refusal->message());

                return [$locked, $reminder, null];
            }
            $reminder->forceFill([
                ...$this->attributes($workspace, $locked, $facts, $decision),
                'status' => PaymentReminderStatus::Sending,
                'claimed_at' => $this->clock->now($workspace)->utc(),
            ])->save();

            return [$locked, $reminder, $decision];
        });
        [$locked, $reminder, $decision] = $claimed;

        return $decision instanceof PaymentReminderDecision ? $this->deliver($workspace, $locked, $reminder) : $reminder;
    }

    /**
     * Claim and send the cadence step that is due for this invoice, if any.
     * The claim is the step's row, inserted or - for a failed attempt whose
     * retry has come round - reclaimed under the invoice lock.
     */
    public function sendAutomatic(ClientInvoice $invoice): ?InvoiceReminderLog
    {
        try {
            $claimed = DB::transaction(function () use ($invoice): ?array {
                $locked = ClientInvoice::query()
                    ->where('workspace_id', $invoice->workspace_id)
                    ->whereKey($invoice->id)
                    ->tap(Locks::forUpdate())
                    ->with('workspace')
                    ->first();
                $workspace = $locked?->workspace;
                if (! $locked instanceof ClientInvoice || ! $workspace instanceof Workspace) {
                    return null;
                }
                $facts = $this->repository->facts($workspace, $locked);
                $decision = $this->policy->automatic($facts);
                if ($decision->refusal !== null || $decision->step === null) {
                    return null;
                }
                $attributes = [
                    ...$this->attributes($workspace, $locked, $facts, $decision),
                    'status' => PaymentReminderStatus::Sending,
                    'claimed_at' => $this->clock->now($workspace)->utc(),
                    'failed_at' => null,
                    'next_attempt_at' => null,
                    'error_summary' => null,
                ];
                if ($decision->retry) {
                    $reminder = $this->stepClaim($workspace, $locked, $decision->step);
                    if (! $reminder instanceof InvoiceReminderLog || $reminder->status !== PaymentReminderStatus::Failed) {
                        return null;
                    }
                    $reminder->forceFill([...$attributes, 'attempt_number' => $reminder->attempt_number + 1])->save();
                } else {
                    $reminder = InvoiceReminderLog::query()->create([
                        ...$attributes,
                        'origin' => PaymentReminderOrigin::Automatic,
                        'cadence_step' => $decision->step,
                        'attempt_number' => 1,
                        'triggered_by' => PaymentReminderTrigger::Scheduler,
                        'queued_at' => $this->clock->now($workspace)->utc(),
                    ]);
                }

                return [$workspace, $locked, $reminder];
            });
        } catch (UniqueConstraintViolationException) {
            // Another run claimed this step first; it is that run's to send.
            return null;
        }
        if ($claimed === null) {
            return null;
        }
        [$workspace, $locked, $reminder] = $claimed;

        return $this->deliver($workspace, $locked, $reminder);
    }

    /**
     * The scheduler's pass: every open invoice of a client with the cadence on
     * whose due date puts a step within reach, in id order, sending at most
     * `$limit` reminders. The policy decides each one exactly; this query only
     * narrows the candidates, with a day of slack for time zones. There is no
     * lower bound on the due date: an invoice leaves the scan once its final
     * step is settled, so a final reminder the scheduler missed while it was
     * down is still sent when it comes back.
     */
    public function dispatchAutomaticDue(int $limit = 50): int
    {
        $today = $this->clock->now()->utc()->startOfDay();
        $sent = 0;
        $candidates = ClientInvoice::query()
            ->select(['client_invoices.id', 'client_invoices.workspace_id'])
            ->whereIn('client_invoices.status', InvoiceStatus::collectible())
            ->where('client_invoices.balance_amount', '>', 0)
            ->whereNotNull('client_invoices.due_date')
            ->where('client_invoices.due_date', '<=', $today->addDays(1 - PaymentReminderStep::BeforeDue->offsetDays())->toDateString())
            // The final step is sent, in flight, cancelled or out of retries:
            // nothing more can fall due for this invoice.
            ->whereNotExists(fn (QueryBuilder $final): QueryBuilder => $final->selectRaw('1')->from('invoice_reminder_logs')
                ->whereColumn('invoice_reminder_logs.workspace_id', 'client_invoices.workspace_id')
                ->whereColumn('invoice_reminder_logs.client_invoice_id', 'client_invoices.id')
                ->where('invoice_reminder_logs.cadence_step', PaymentReminderStep::TwoWeeksOverdue->value)
                ->whereNull('invoice_reminder_logs.next_attempt_at'))
            ->whereExists(fn (QueryBuilder $company): QueryBuilder => $company->selectRaw('1')->from('client_companies')
                ->whereColumn('client_companies.id', 'client_invoices.client_company_id')
                ->whereColumn('client_companies.workspace_id', 'client_invoices.workspace_id')
                ->whereNotNull('client_companies.payment_reminders_enabled_at'));
        foreach ($candidates->lazyById(100, 'client_invoices.id', 'id') as $invoice) {
            if ($sent >= $limit) {
                break;
            }
            if ($this->sendAutomatic($invoice) instanceof InvoiceReminderLog) {
                $sent++;
            }
        }

        return $sent;
    }

    /**
     * Turn the client's automatic cadence on or off. Turning it on records
     * when, so steps that fell due before then are never sent; turning it off
     * stops every step not yet claimed. Recorded in the client's history.
     */
    public function setCadence(Workspace $workspace, ClientCompany $company, bool $enabled, User $actor, ?string $expectedVersion = null): ClientCompany
    {
        return DB::transaction(function () use ($workspace, $company, $enabled, $actor, $expectedVersion): ClientCompany {
            $locked = ClientCompany::query()
                ->where('workspace_id', $workspace->id)
                ->whereKey($company->id)
                ->tap(Locks::forUpdate())
                ->firstOrFail();
            if ($expectedVersion !== null && ! AgentApiVersion::matches($locked, $expectedVersion)) {
                throw new PaymentReminderVersionConflict('The client has changed; read it and retry.');
            }
            $before = $locked->payment_reminders_enabled_at !== null;
            if ($enabled === $before) {
                return $locked;
            }
            $locked->forceFill(['payment_reminders_enabled_at' => $enabled ? $this->clock->now($workspace)->utc() : null])->save();
            $this->activities->record($workspace, $locked, 'client.payment_reminders_updated', $locked, [
                'before' => ['enabled' => $before],
                'after' => ['enabled' => $enabled],
            ], $actor, (string) str()->uuid());

            return $locked;
        });
    }

    /**
     * Hand the claimed reminder to the mailer and write down the answer.
     */
    private function deliver(Workspace $workspace, ClientInvoice $invoice, InvoiceReminderLog $reminder): InvoiceReminderLog
    {
        // The last word, outside every lock: the claim has committed, so a
        // payment, void or Square link that landed since is current here.
        // A failure here is before anything left: the claim must not stay
        // `sending`, which reads as an ambiguous send and is never retried.
        try {
            $current = ClientInvoice::query()->where('workspace_id', $workspace->id)->whereKey($invoice->id)->first();
            $facts = $current === null ? null : $this->repository->facts($workspace, $current, $reminder->id);
            $decision = $facts === null ? null : $this->policy->lastWord($facts, $reminder->cadence_step);
        } catch (Throwable $exception) {
            return $this->failed($workspace, $invoice, $reminder, $exception, 'Not sent: the reminder could not be checked before sending');
        }
        if ($current === null || $decision === null || $decision->refusal !== null
            || $decision->payLink !== $reminder->pay_link || $decision->squareInvoiceNumber !== $reminder->square_invoice_number) {
            return $this->resolve($workspace, $invoice, $reminder, PaymentReminderStatus::Cancelled, [
                'error_summary' => 'Not sent: '.($decision?->refusal?->message() ?? 'the invoice changed after the reminder was claimed.'),
            ]);
        }
        // Who it goes to is part of what was claimed. A billing address
        // changed or a portal user removed since must not still receive it -
        // with the PDF attached, for an invoice SVC collects.
        if ($facts->recipients !== $reminder->recipients) {
            return $this->resolve($workspace, $invoice, $reminder, PaymentReminderStatus::Cancelled, [
                'error_summary' => 'Not sent: the client\'s recipients changed after the reminder was claimed.',
            ]);
        }
        // And so is what it says: the log records the balance and due date
        // the email states, so a payment or new due date since the claim
        // would leave the record saying something the client was never told.
        if ((int) $current->balance_amount !== $reminder->amount_due
            || $current->due_date?->toDateString() !== $reminder->due_on?->toDateString()) {
            return $this->resolve($workspace, $invoice, $reminder, PaymentReminderStatus::Cancelled, [
                'error_summary' => 'Not sent: the invoice\'s balance or due date changed after the reminder was claimed.',
            ]);
        }

        try {
            $pdf = $decision->payLink === PaymentReminderPayLink::Square ? null
                : [$this->documents->pdf($current, InvoiceLineDetail::CLIENT), $this->documents->filename($current)];
            $sent = Mail::to($reminder->recipients)->send(new InvoicePaymentReminderMail(
                $current->loadMissing('clientCompany'),
                $reminder->subject,
                (string) $current->clientCompany?->name,
                $decision->payLink,
                $decision->payUrl,
                $decision->squareInvoiceNumber,
                $this->clock->today($workspace)->toDateString(),
                $pdf,
            ));
        } catch (Throwable $exception) {
            return $this->failed($workspace, $invoice, $reminder, $exception, 'Email delivery failed');
        }

        try {
            return $this->resolve($workspace, $invoice, $reminder, PaymentReminderStatus::Sent, [
                'sent_at' => $this->clock->now($workspace)->utc(),
                'provider_message_reference' => $sent?->getMessageId(),
            ]);
        } catch (Throwable $exception) {
            // Accepted by the provider and not saved: the row stays `sending`,
            // an ambiguous outcome nothing will send again blindly.
            Log::critical('An accepted payment reminder could not be recorded.', ['reminder' => $reminder->public_id, 'exception' => $exception]);

            return $reminder;
        }
    }

    /**
     * Nothing left: a definite failure, retried on the cadence's backoff when
     * the reminder is automatic and by asking again when a person sent it.
     */
    private function failed(Workspace $workspace, ClientInvoice $invoice, InvoiceReminderLog $reminder, Throwable $exception, string $summary): InvoiceReminderLog
    {
        // The class name, not the message: a transport or rendering failure
        // can quote recipient addresses or credentials.
        Log::error('A payment reminder could not be sent.', ['reminder' => $reminder->public_id, 'exception' => class_basename($exception)]);
        $now = $this->clock->now($workspace);

        return $this->resolve($workspace, $invoice, $reminder, PaymentReminderStatus::Failed, [
            'failed_at' => $now->utc(),
            'error_summary' => $summary.' ('.class_basename($exception).').',
            'next_attempt_at' => $reminder->origin === PaymentReminderOrigin::Automatic
                ? $this->policy->nextAttemptAt($reminder->attempt_number, $now)?->utc()
                : null,
        ]);
    }

    /**
     * Write a claimed reminder's outcome under the invoice and reminder locks.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function resolve(Workspace $workspace, ClientInvoice $invoice, InvoiceReminderLog $reminder, PaymentReminderStatus $status, array $attributes): InvoiceReminderLog
    {
        return DB::transaction(function () use ($workspace, $invoice, $reminder, $status, $attributes): InvoiceReminderLog {
            $locked = $this->lockInvoice($workspace, $invoice);
            $row = InvoiceReminderLog::query()
                ->where('workspace_id', $workspace->id)
                ->where('client_invoice_id', $locked->id)
                ->whereKey($reminder->id)
                ->tap(Locks::forUpdate())
                ->firstOrFail();
            if ($row->status !== PaymentReminderStatus::Sending) {
                throw new DomainException('The claimed payment reminder changed before its result was saved.');
            }
            $row->forceFill(['status' => $status, ...$attributes])->save();

            return $row;
        });
    }

    private function cancel(Workspace $workspace, InvoiceReminderLog $reminder, string $reason): void
    {
        $reminder->forceFill([
            'status' => PaymentReminderStatus::Cancelled,
            'failed_at' => $this->clock->now($workspace)->utc(),
            'error_summary' => 'Not sent: '.$reason,
        ])->save();
    }

    private function lockInvoice(Workspace $workspace, ClientInvoice $invoice): ClientInvoice
    {
        return ClientInvoice::query()
            ->where('workspace_id', $workspace->id)
            ->whereKey($invoice->id)
            ->tap(Locks::forUpdate())
            ->firstOrFail();
    }

    private function stepClaim(Workspace $workspace, ClientInvoice $invoice, PaymentReminderStep $step): ?InvoiceReminderLog
    {
        return InvoiceReminderLog::query()
            ->where('workspace_id', $workspace->id)
            ->where('client_invoice_id', $invoice->id)
            ->where('cadence_step', $step->value)
            ->tap(Locks::forUpdate())
            ->first();
    }

    /**
     * What the reminder says and who it goes to, as of this decision.
     *
     * @return array<string, mixed>
     */
    private function attributes(Workspace $workspace, ClientInvoice $invoice, PaymentReminderFacts $facts, PaymentReminderDecision $decision): array
    {
        return [
            'workspace_id' => $workspace->id,
            'client_invoice_id' => $invoice->id,
            'channel' => InvoiceReminderLog::CHANNEL_EMAIL,
            'pay_link' => $decision->payLink,
            'square_invoice_number' => $decision->squareInvoiceNumber,
            'recipients' => $facts->recipients,
            'subject' => 'Payment reminder: invoice '.$invoice->invoice_number,
            'currency' => $invoice->currency,
            'amount_due' => (int) $invoice->balance_amount,
            'due_on' => $invoice->due_date,
        ];
    }
}
