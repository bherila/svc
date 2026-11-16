<?php

namespace App\Services\Billing;

use App\Casts\EmailAddressList;
use App\Models\ClientInvoiceEmailDelivery;
use App\Support\Billing\Reminders\PaymentReminderOrigin;
use App\Support\Billing\Reminders\PaymentReminderStatus;
use App\Support\Concurrency\Locks;
use App\Support\WorkspaceClock;
use Illuminate\Database\DatabaseTransactionRecord;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use LogicException;
use WeakMap;

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
 * Waiting is not enough on its own. The count is an ordinary read, made before
 * the key's lock: under REPEATABLE READ it comes from the transaction's
 * snapshot, which the agent API's transaction may have taken long before, and
 * under READ COMMITTED from whenever it ran. So the key also carries the
 * recipients it has admitted, a total that only grows. That total is read in
 * the same statement as the count - one view, at either isolation level - and
 * again under the lock, and the difference is the admissions committed after
 * the count was taken, which are added on top. Counting the rows with a lock
 * instead would take gap locks under REPEATABLE READ that deadlock with the
 * delivery claim's own writes (billing-simplification-plan.md, Part 1). The
 * bound is exact, erring only towards refusing when one of those sends has
 * since failed.
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

    /**
     * The workspaces each open application transaction has called
     * {@see admit()} for. Keyed weakly by the transaction's record, so an
     * entry ends with its transaction and a new transaction starts clean.
     *
     * @var WeakMap<DatabaseTransactionRecord, array<int, true>>|null
     */
    private static ?WeakMap $admissions = null;

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
     * own statement and admit nothing atomically. Nothing is admitted when
     * this refuses.
     *
     * One admission per workspace per transaction. The correction for a stale
     * count reads the key before this transaction has written it; once it has
     * - and the upsert below writes it even when this refuses - a second call
     * under REPEATABLE READ reads its own write instead and would miss every
     * admission committed after the snapshot. So admit everything a
     * transaction sends in one call, and a send whose recipients grow later
     * is asked for again in a transaction of its own (see
     * `PaymentReminderService::sendRegistered()`). A second call for the same
     * workspace in one application transaction, or a call outside any, throws.
     *
     * @throws LogicException called outside a transaction, or a second time for this workspace in one
     */
    public function admit(int $workspaceId, int $recipients): bool
    {
        $this->claimTransaction($workspaceId);

        // The count and the key's total in one statement, so they describe
        // one moment at either isolation level - a REPEATABLE READ snapshot,
        // or the statement's own view at READ COMMITTED - and before this
        // transaction writes the key: once it has, its own write is what a
        // REPEATABLE READ transaction would read.
        [$counted, $seen] = $this->countedWithAdmitted($workspaceId);

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

        if (! self::fits($counted, $seen, $current, $recipients, $this->limit())) {
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
     * and the key's admitted total read in the same statement
     * (`$seenAdmitted`) and under its lock (`$lockedAdmitted`).
     *
     * The difference is the recipients of admissions committed after the
     * count was taken, whose rows it could not see. A locked total below the
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
        return $this->countedWithAdmitted($workspaceId)[0];
    }

    /**
     * {@see usedInLastDay()}'s count and the key's admitted total, from one
     * statement and so from one view of the data.
     *
     * @return array{int, int}
     */
    private function countedWithAdmitted(int $workspaceId): array
    {
        $since = $this->clock->instant()->subDay();
        $deliveries = DB::table('client_invoice_email_deliveries')
            ->select(['recipients', DB::raw('null as admitted')])
            ->where('workspace_id', $workspaceId)
            ->where('origin', 'manual')
            ->whereIn('status', ['pending', 'sending', 'sent'])
            ->where('queued_at', '>=', $since);
        $reminders = DB::table('invoice_reminder_logs')
            ->select(['recipients', DB::raw('null as admitted')])
            ->where('workspace_id', $workspaceId)
            ->where('origin', PaymentReminderOrigin::Manual->value)
            ->whereIn('status', [PaymentReminderStatus::Pending->value, PaymentReminderStatus::Sending->value, PaymentReminderStatus::Sent->value])
            ->where('queued_at', '>=', $since);
        $key = DB::table(self::KEY_TABLE)
            ->select([DB::raw('null as recipients'), 'admitted_recipients as admitted'])
            ->where('workspace_id', $workspaceId);

        $addresses = new EmailAddressList;
        $model = new ClientInvoiceEmailDelivery;
        $counted = 0;
        $admitted = 0;
        foreach ($deliveries->unionAll($reminders)->unionAll($key)->get() as $row) {
            $row = (array) $row;
            if ($row['admitted'] !== null) {
                $admitted = is_numeric($row['admitted']) ? (int) $row['admitted'] : 0;

                continue;
            }
            // Read through the column's cast, as the models read it.
            $counted += count($addresses->get($model, 'recipients', $row['recipients'], []));
        }

        return [$counted, $admitted];
    }

    /**
     * Note this transaction's admission for the workspace, or throw if it has
     * one already. "This transaction" is the outermost one the application
     * opened: a savepoint shares its snapshot. The transactions manager's
     * callback-applicable transactions are exactly those - under
     * `RefreshDatabase` it leaves out the test's wrapping transaction - and
     * when there is none past that wrapper there is nothing to note.
     */
    private function claimTransaction(int $workspaceId): void
    {
        $connection = DB::connection();
        if ($connection->transactionLevel() === 0) {
            throw new LogicException('ManualClientEmailBudget::admit() must run inside the transaction that records the send.');
        }
        $transaction = app('db.transactions')->callbackApplicableTransactions()
            ->first(static fn (DatabaseTransactionRecord $record): bool => $record->connection === $connection->getName());
        if (! $transaction instanceof DatabaseTransactionRecord) {
            return;
        }
        self::$admissions ??= new WeakMap;
        $admitted = self::$admissions[$transaction] ?? [];
        if (isset($admitted[$workspaceId])) {
            throw new LogicException('ManualClientEmailBudget::admit() was already called for this workspace in this transaction; admit every recipient in one call.');
        }
        $admitted[$workspaceId] = true;
        self::$admissions[$transaction] = $admitted;
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
