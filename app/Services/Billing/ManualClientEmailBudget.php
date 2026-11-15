<?php

namespace App\Services\Billing;

use App\Models\ClientInvoiceEmailDelivery;
use App\Models\InvoiceReminderLog;
use App\Support\Billing\Reminders\PaymentReminderOrigin;
use App\Support\Billing\Reminders\PaymentReminderStatus;
use App\Support\Concurrency\Locks;
use App\Support\WorkspaceClock;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

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
 * {@see admit()} is the check, and it is atomic with the caller's insert: it
 * locks the workspace's budget key (`client_email_budget_locks`) before
 * counting, inside the transaction that then inserts the delivery or reminder,
 * so a second send in the workspace waits until the first has committed or
 * rolled back. The key, not the workspace row: the agent API's receipt insert
 * has already share-locked the workspace row through its foreign key, so two
 * sends upgrading it would deadlock.
 *
 * Waiting is not enough under REPEATABLE READ, where the count is read through
 * the transaction's snapshot and the agent API's transaction may have taken it
 * before the lock. So the key also carries the recipients it has admitted, a
 * total that only grows: read once through the snapshot and once under the
 * lock, the difference is what admissions the snapshot cannot see added, and
 * it is counted on top. The bound is then exact, erring only towards refusing
 * when one of those sends has since failed.
 *
 * Not counted against the limit and not checked here: a keyed retry of a
 * failed manual delivery (`InvoiceEmailService::claimFailedManualDelivery`),
 * which resends the recipients that delivery was admitted with.
 */
final class ManualClientEmailBudget
{
    /** Default recipients per workspace per rolling 24 hours; `svc.client_email.daily_recipients` overrides it. */
    public const int DAILY_RECIPIENTS = 300;

    private const string KEY_TABLE = 'client_email_budget_locks';

    public function __construct(private readonly WorkspaceClock $clock = new WorkspaceClock) {}

    public function limit(): int
    {
        $configured = config('svc.client_email.daily_recipients');

        return is_numeric($configured) && (int) $configured > 0 ? (int) $configured : self::DAILY_RECIPIENTS;
    }

    /**
     * Admit `$recipients` more people addressed by hand now, or refuse.
     *
     * Call inside the transaction that inserts the delivery or reminder being
     * admitted, after its invoice lock (`LockResource::ClientEmailBudgetLock`
     * ranks after `ClientInvoice`), and insert it in that same transaction:
     * the key stays locked until it commits, and an admission rolled back with
     * it is undone with it. Outside a transaction the lock would end with its
     * own statement and admit nothing atomically. Nothing is written when this
     * refuses.
     */
    public function admit(int $workspaceId, int $recipients): bool
    {
        // Through this transaction's snapshot, and before this transaction
        // writes the key: once it has, its own write is what this would read.
        $seen = $this->admitted($this->key($workspaceId));

        // An upsert, not an ignored insert: InnoDB locks a duplicate an upsert
        // meets exclusively, where an ignored insert would take a shared lock
        // that two sends would each hold while waiting to upgrade it (#715).
        $now = $this->clock->instant();
        DB::table(self::KEY_TABLE)->upsert([[
            'workspace_id' => $workspaceId,
            'admitted_recipients' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]], ['workspace_id'], ['updated_at']);
        $current = $this->admitted($this->key($workspaceId)->tap(Locks::forUpdate()));

        if (! self::fits($this->usedInLastDay($workspaceId), $seen, $current, $recipients, $this->limit())) {
            return false;
        }

        $this->key($workspaceId)->update([
            'admitted_recipients' => $current + $recipients,
            'updated_at' => $now,
        ]);

        return true;
    }

    /**
     * Whether `$recipients` more fit under `$limit`, given what was counted
     * through the snapshot and the key's admitted total read through that
     * snapshot (`$seenAdmitted`) and under its lock (`$lockedAdmitted`).
     *
     * The difference is the recipients of admissions committed after the
     * snapshot, whose rows the count could not see. A locked total below the
     * one seen means the key was reset, which nothing does; it adds nothing
     * rather than taking sends away.
     */
    public static function fits(int $counted, int $seenAdmitted, int $lockedAdmitted, int $recipients, int $limit): bool
    {
        return $counted + max(0, $lockedAdmitted - $seenAdmitted) + $recipients <= $limit;
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

    private function key(int $workspaceId): Builder
    {
        return DB::table(self::KEY_TABLE)->where('workspace_id', $workspaceId);
    }

    private function admitted(Builder $key): int
    {
        $admitted = $key->value('admitted_recipients');

        return is_numeric($admitted) ? (int) $admitted : 0;
    }
}
