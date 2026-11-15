<?php

namespace App\Services\Billing;

use App\Models\ClientInvoiceEmailDelivery;
use App\Models\InvoiceReminderLog;
use App\Support\Billing\Reminders\PaymentReminderOrigin;
use App\Support\Billing\Reminders\PaymentReminderStatus;
use App\Support\WorkspaceClock;

/**
 * How many recipients a workspace may email by hand in a rolling day.
 *
 * `invoices.send` takes up to ten arbitrary addresses a call and a payment
 * reminder goes to the client's addresses, both from SVC's own sender. The
 * per-invoice guards stop repeats of one message; this bounds the total, so a
 * leaked credential or a runaway agent cannot turn the sender into a relay.
 * Automatic deliveries and the reminder cadence are not counted and never
 * refused here: they address only the client's own contacts, on a schedule.
 *
 * The count is read without a workspace lock, so concurrent sends can each
 * pass at the edge and overshoot by at most one request's recipients apiece.
 * That is accepted: the bound is against volume, not an exact quota.
 */
final class ManualClientEmailBudget
{
    /** Default recipients per workspace per rolling 24 hours; `svc.client_email.daily_recipients` overrides it. */
    public const int DAILY_RECIPIENTS = 300;

    public function __construct(private readonly WorkspaceClock $clock = new WorkspaceClock) {}

    public function limit(): int
    {
        $configured = config('svc.client_email.daily_recipients');

        return is_numeric($configured) && (int) $configured > 0 ? (int) $configured : self::DAILY_RECIPIENTS;
    }

    /** Whether the workspace can address `$recipients` more people by hand now. */
    public function allows(int $workspaceId, int $recipients): bool
    {
        return $this->usedInLastDay($workspaceId) + $recipients <= $this->limit();
    }

    /** Recipients addressed by hand in the last 24 hours: invoice emails and payment reminders that did not fail. */
    public function usedInLastDay(int $workspaceId): int
    {
        $since = $this->clock->instant()->subDay();
        $count = static function (iterable $rows): int {
            $total = 0;
            foreach ($rows as $recipients) {
                $total += is_array($recipients) ? count($recipients) : 0;
            }

            return $total;
        };

        return $count(ClientInvoiceEmailDelivery::query()
            ->where('workspace_id', $workspaceId)
            ->where('origin', 'manual')
            ->whereIn('status', ['pending', 'sending', 'sent'])
            ->where('queued_at', '>=', $since)
            ->pluck('recipients'))
            + $count(InvoiceReminderLog::query()
                ->where('workspace_id', $workspaceId)
                ->where('origin', PaymentReminderOrigin::Manual->value)
                ->whereIn('status', [PaymentReminderStatus::Pending->value, PaymentReminderStatus::Sending->value, PaymentReminderStatus::Sent->value])
                ->where('queued_at', '>=', $since)
                ->pluck('recipients'));
    }
}
