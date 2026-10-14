<?php

namespace App\Services\Billing\Reminders;

use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientInvoicePayment;
use App\Models\InvoiceReminderLog;
use App\Models\SquareInvoiceSnapshot;
use App\Models\Workspace;
use App\Services\Billing\InvoiceEmailService;
use App\Services\Square\SquareCollectionGuard;
use App\Services\Square\SquareConfiguration;
use App\Services\Square\SquareLinkRepository;
use App\Support\Billing\InvoicePaymentStatus;
use App\Support\Billing\Reminders\PaymentReminderClaim;
use App\Support\Billing\Reminders\PaymentReminderFacts;
use App\Support\Billing\Reminders\PaymentReminderOrigin;
use App\Support\Billing\Reminders\PaymentReminderStatus;
use App\Support\Billing\Reminders\PaymentReminderStep;
use App\Support\Billing\Reminders\SquareReminderFacts;
use App\Support\Square\SquareHostedPage;
use App\Support\WorkspaceClock;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * The tenant-scoped reads behind payment reminders (#438).
 *
 * Every query names the invoice's own workspace. {@see self::facts()} is the
 * one bounded load the database-free policy decides from: the client and its
 * recipients, the Square link and the mirror's copy of the Square invoice,
 * money taken outside Square, and the reminders already on the invoice.
 */
final class PaymentReminderRepository
{
    /**
     * How long a claimed reminder counts as about to leave. Sending is
     * synchronous, so a claim older than this is an ambiguous outcome awaiting
     * reconciliation rather than a message in flight, and must not block the
     * invoice's reminders for ever.
     */
    public const int IN_FLIGHT_MINUTES = 60;

    public function __construct(
        private readonly InvoiceEmailService $emails,
        private readonly SquareCollectionGuard $squareCollection,
        private readonly SquareLinkRepository $links,
        private readonly WorkspaceClock $clock = new WorkspaceClock,
    ) {}

    /**
     * What the policy decides from, for an invoice in this workspace.
     *
     * @param  int|null  $excludingReminderId  a claim being sent, which does not count against itself
     */
    public function facts(Workspace $workspace, ClientInvoice $invoice, ?int $excludingReminderId = null): PaymentReminderFacts
    {
        if ($invoice->workspace_id !== $workspace->id) {
            throw new DomainException('Invoice does not belong to this workspace.');
        }
        $now = $this->clock->now($workspace);
        $company = ClientCompany::query()
            ->where('workspace_id', $workspace->id)
            ->whereKey($invoice->client_company_id)
            ->first();
        // Bounded whatever the history: the automatic claims are at most one
        // per cadence step, and in flight is an existence check. A person may
        // remind as often as they decide to, and none of that is loaded here.
        $claims = InvoiceReminderLog::query()
            ->where('workspace_id', $workspace->id)
            ->where('client_invoice_id', $invoice->id)
            ->where('origin', PaymentReminderOrigin::Automatic->value)
            ->whereNotNull('cadence_step')
            ->when($excludingReminderId !== null, fn (Builder $query): Builder => $query->whereKeyNot($excludingReminderId))
            ->limit(count(PaymentReminderStep::cases()))
            ->get(['id', 'origin', 'cadence_step', 'status', 'attempt_number', 'next_attempt_at']);
        $since = $now->utc()->subMinutes(self::IN_FLIGHT_MINUTES);
        $square = $this->squareFacts($workspace, $invoice);
        $enabledAt = $company?->payment_reminders_enabled_at;

        return new PaymentReminderFacts(
            invoiceStatus: (string) $invoice->status,
            balanceAmount: (int) $invoice->balance_amount,
            issueDate: $invoice->issue_date,
            dueDate: $invoice->due_date,
            now: $now,
            recipients: $company === null ? [] : array_column($this->emails->suggestedRecipientsForCompany($company), 'email'),
            square: $square,
            portalUrl: $square === null && $company !== null ? $this->portalUrl($company, $invoice) : null,
            cadenceEnabledAt: $enabledAt?->setTimezone($now->getTimezone()),
            claims: $this->claims($claims),
            inFlight: InvoiceReminderLog::query()
                ->where('workspace_id', $workspace->id)
                ->where('client_invoice_id', $invoice->id)
                ->whereIn('status', [PaymentReminderStatus::Pending->value, PaymentReminderStatus::Sending->value])
                ->where(fn (Builder $recent): Builder => $recent->where('claimed_at', '>=', $since)
                    ->orWhere(fn (Builder $unclaimed): Builder => $unclaimed->whereNull('claimed_at')->where('queued_at', '>=', $since)))
                ->when($excludingReminderId !== null, fn (Builder $query): Builder => $query->whereKeyNot($excludingReminderId))
                ->exists(),
        );
    }

    /**
     * The latest reminders on an invoice, newest first.
     *
     * @return Collection<int, InvoiceReminderLog>
     */
    public function history(ClientInvoice $invoice, int $limit = 50): Collection
    {
        return InvoiceReminderLog::query()
            ->where('workspace_id', $invoice->workspace_id)
            ->where('client_invoice_id', $invoice->id)
            ->with('triggeredByUser:id,name')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /** Null for an invoice SVC collects itself. */
    private function squareFacts(Workspace $workspace, ClientInvoice $invoice): ?SquareReminderFacts
    {
        if (! $this->squareCollection->isSquareCollected($invoice)) {
            return null;
        }
        $link = $this->links->activeLink($invoice);
        // The mirror's copy is used only while Square is connected to this
        // workspace, as everywhere else that sends a person to a Square page.
        $snapshot = $link === null || ! SquareConfiguration::fromConfig()->servesWorkspace($workspace) ? null
            : SquareInvoiceSnapshot::query()
                ->where('workspace_id', $workspace->id)
                ->where('square_invoice_id', $link->square_invoice_id)
                ->first();

        return new SquareReminderFacts(
            linked: $link !== null,
            squareInvoiceNumber: $link?->square_invoice_number,
            squareStatus: $snapshot?->status,
            payUrl: SquareHostedPage::url($snapshot?->public_url),
            autopay: (bool) $snapshot?->autopay,
            squareSendsReminders: $snapshot !== null && $this->hasSquareReminders($snapshot->payment_requests),
            paidOutsideSquare: $link !== null && $this->paidOutsideSquare($invoice),
        );
    }

    /** @param  list<array<string, mixed>>|null  $paymentRequests */
    private function hasSquareReminders(?array $paymentRequests): bool
    {
        foreach ($paymentRequests ?? [] as $request) {
            if (is_array($request['reminders'] ?? null) && $request['reminders'] !== []) {
                return true;
            }
        }

        return false;
    }

    /** Money SVC holds for the invoice that did not come through Square, so Square's balance does not show it. */
    private function paidOutsideSquare(ClientInvoice $invoice): bool
    {
        return ClientInvoicePayment::query()
            ->where('workspace_id', $invoice->workspace_id)
            ->where('client_invoice_id', $invoice->id)
            ->where('status', InvoicePaymentStatus::Succeeded->value)
            ->whereColumn('amount', '>', 'refunded_amount')
            ->where(fn (Builder $outside): Builder => $outside->whereNull('provider')->orWhere('provider', '!=', 'square'))
            ->exists();
    }

    /**
     * The invoice in the client portal: a sign-in page carrying no token, and
     * only where a portal user of the client could open it.
     */
    private function portalUrl(ClientCompany $company, ClientInvoice $invoice): ?string
    {
        if (! (bool) $invoice->is_visible_to_client
            || ! $company->portalUsers()->wherePivot('workspace_id', $company->workspace_id)->exists()) {
            return null;
        }

        return rtrim(config()->string('app.url'), '/').route('portal.invoice', [$company, $invoice], absolute: false);
    }

    /**
     * @param  Collection<int, InvoiceReminderLog>  $reminders
     * @return list<PaymentReminderClaim>
     */
    private function claims(Collection $reminders): array
    {
        $claims = [];
        foreach ($reminders as $reminder) {
            if ($reminder->origin === PaymentReminderOrigin::Automatic && $reminder->cadence_step instanceof PaymentReminderStep) {
                $claims[] = new PaymentReminderClaim($reminder->cadence_step, $reminder->status, $reminder->attempt_number, $reminder->next_attempt_at);
            }
        }

        return $claims;
    }
}
