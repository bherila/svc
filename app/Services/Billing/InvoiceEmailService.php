<?php

namespace App\Services\Billing;

use App\Mail\InvoiceMail;
use App\Models\ClientInvoice;
use App\Models\ClientInvoiceEmailDelivery;
use App\Models\Workspace;
use App\Services\WorkspaceAuthorization;
use App\Support\Billing\InvoiceEmailDraft;
use App\Support\Billing\InvoiceStatus;
use App\Support\WorkspaceClock;
use DomainException;
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
    public function __construct(
        private readonly WorkspaceAuthorization $workspaceAuthorization,
        private readonly WorkspaceClock $clock = new WorkspaceClock,
    ) {}

    /**
     * Send this invoice now, and record what happened.
     *
     * @throws DomainException when the invoice cannot be emailed, or the send failed
     */
    public function send(ClientInvoice $invoice, InvoiceEmailDraft $draft, ?Workspace $workspace = null): ClientInvoiceEmailDelivery
    {
        $this->assertSendable($invoice, $workspace);

        return $this->deliver($invoice, $this->record($invoice, $draft), $draft);
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

        $delivery = $this->record($invoice, $draft);
        $id = (int) $delivery->id;

        DB::afterCommit(function () use ($invoice, $draft, $id): void {
            $registered = ClientInvoiceEmailDelivery::query()->find($id);

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
        });

        return $delivery;
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

        $suggestions = [];
        $seen = [];

        $billing = trim((string) $company->billing_email);

        if ($billing !== '' && filter_var($billing, FILTER_VALIDATE_EMAIL) !== false) {
            $suggestions[] = ['email' => $billing, 'label' => 'Billing address'];
            $seen[strtolower($billing)] = true;
        }

        foreach ($company->portalUsers()->get() as $user) {
            $address = trim((string) $user->email);
            $key = strtolower($address);

            if ($address === '' || isset($seen[$key])) {
                continue;
            }

            $suggestions[] = ['email' => $address, 'label' => (string) $user->name];
            $seen[$key] = true;
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
     * sender cannot change and the recipient reads first - and because an
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

    private function record(ClientInvoice $invoice, InvoiceEmailDraft $draft): ClientInvoiceEmailDelivery
    {
        return DB::transaction(function () use ($invoice, $draft): ClientInvoiceEmailDelivery {
            $delivery = $invoice->emailDeliveries()->create([
                'workspace_id' => $invoice->workspace_id,
                'recipients' => $draft->recipients,
                'bcc' => $draft->bcc === [] ? null : $draft->bcc,
                'subject' => $draft->subject,
                'body' => $draft->body,
                'status' => 'pending',
                'queued_at' => $this->clock->now($invoice->workspace),
            ]);
            $invoice->advanceAgentRevision();

            return $delivery;
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
     * @throws DomainException when the mailer refused it
     */
    private function deliver(
        ClientInvoice $invoice,
        ClientInvoiceEmailDelivery $delivery,
        InvoiceEmailDraft $draft,
    ): ClientInvoiceEmailDelivery {
        try {
            $mailer = Mail::to($draft->recipients);

            if ($draft->bcc !== []) {
                $mailer->bcc($draft->bcc);
            }

            $sent = $mailer->send(new InvoiceMail($invoice, $draft->subject, $draft->body));

            $delivery->forceFill([
                'status' => 'sent',
                'sent_at' => $this->clock->now($invoice->workspace),
                'provider_message_reference' => $sent?->getMessageId(),
            ])->save();
            $invoice->advanceAgentRevision();

            return $delivery->fresh();
        } catch (Throwable $exception) {
            // The class name, not the message. A transport failure can quote
            // the address it was refused for and the credentials it used, and
            // this string is rendered on a screen and kept in a row.
            $delivery->forceFill([
                'status' => 'failed',
                'failed_at' => $this->clock->now($invoice->workspace),
                'error_summary' => 'Email delivery failed ('.class_basename($exception).').',
            ])->save();
            $invoice->advanceAgentRevision();

            Log::error('An invoice email could not be sent.', [
                'delivery' => $delivery->public_id,
                'exception' => $exception,
            ]);

            throw new DomainException(
                'The mail server refused this message ('.class_basename($exception).'). Nothing was sent.',
            );
        }
    }
}
