<?php

namespace App\Services\Square;

use App\Casts\DateOnly;
use App\Models\ClientInvoicePayment;
use App\Models\Workspace;
use App\Services\Billing\BillingMaintenance;
use App\Services\Finance\ProcessorSettlementRecorder;
use App\Support\Concurrency\Locks;
use App\Support\Finance\PaymentSettlement;
use App\Support\WorkspaceClock;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Durable, tenant-scoped payout facts waiting for their invoice payment to be recorded. */
final class SquarePendingSettlements
{
    public function __construct(
        private readonly ProcessorSettlementRecorder $recorder,
        private readonly WorkspaceClock $clock,
    ) {}

    /** Store before attempting delivery, so recording a payment cannot fall between a miss and storage. */
    public function remember(Workspace $workspace, string $paymentId, PaymentSettlement $settlement): void
    {
        if ($settlement->payoutIdentifier === null || $settlement->payoutReportedAtMs === null) {
            throw new LogicException('Only payout settlement facts can wait for a payment.');
        }
        $facts = [
            'payout_identifier' => $settlement->payoutIdentifier,
            'arrival_date' => $settlement->payoutArrivalDate === null ? null : DateOnly::toStored($settlement->payoutArrivalDate),
            'fee_amount' => $settlement->feeAmount,
            'fee_currency' => $settlement->feeCurrency,
            'reported_at_ms' => $settlement->payoutReportedAtMs,
        ];
        // This producer transaction takes no payment lock. Its callers (payout webhooks and
        // backfills) commit it before deliver() takes payment -> pending locks.
        DB::transaction(function () use ($workspace, $paymentId, $facts): void {
            $query = $this->forPayment($workspace, $paymentId);
            (clone $query)->insertOrIgnore(['workspace_id' => $workspace->id, 'payment_identifier' => $paymentId, ...$facts, 'received_at' => $this->clock->now()->utc()]);
            $stored = (clone $query)->tap(Locks::forUpdate())->firstOrFail();
            if ($facts['reported_at_ms'] < (int) $stored->reported_at_ms) {
                return;
            }
            $same = (string) $stored->payout_identifier === $facts['payout_identifier']
                && $stored->arrival_date === $facts['arrival_date']
                && ($stored->fee_amount === null ? null : (int) $stored->fee_amount) === $facts['fee_amount']
                && $stored->fee_currency === $facts['fee_currency']
                && (int) $stored->reported_at_ms === $facts['reported_at_ms'];
            if (! $same) {
                $query->update([...$facts, 'received_at' => $this->clock->now()->utc()]);
            }
        });
    }

    /** Apply current waiting facts, if the payment exists; unchanged/stale facts are consumed too. */
    public function deliver(Workspace $workspace, string $paymentId, bool $apply = true): bool
    {
        return DB::transaction(function () use ($workspace, $paymentId, $apply): bool {
            BillingMaintenance::share($workspace->id);
            $payment = ClientInvoicePayment::query()->where('workspace_id', $workspace->id)
                ->where('provider', 'square')->where('provider_payment_identifier', $paymentId)
                ->tap(Locks::forUpdate())->first();
            if ($payment === null) {
                return false;
            }
            $query = $this->forPayment($workspace, $paymentId);
            // A current read is needed even in the API's outer reconciliation transaction:
            // its earlier invoice reads may have fixed a snapshot before the producer committed.
            $stored = (clone $query)->tap(Locks::forUpdate())->first();
            if ($stored === null) {
                return false;
            }
            $settlement = PaymentSettlement::payout(
                (string) $stored->payout_identifier,
                $stored->arrival_date === null ? null : substr((string) $stored->arrival_date, 0, 10),
                (int) $stored->reported_at_ms,
                null,
                $stored->fee_amount === null ? null : (int) $stored->fee_amount,
                $stored->fee_currency === null ? null : (string) $stored->fee_currency,
            );
            $changed = $this->recorder->recordPaymentSettlement($workspace, 'square', [$paymentId], $settlement, $apply);
            if ($apply) {
                // The payment is locked and known to exist: false now means unchanged/stale,
                // rather than a missing payment whose facts must remain waiting.
                $query->where('id', $stored->id)->delete();
            }

            return $changed;
        });
    }

    /** One bounded prune pass; newer reports arriving after selection are retained. */
    public function prune(Workspace $workspace): int
    {
        $days = (int) config('services.square.pending_settlement_retention_days', 30);
        if ($days < 1) {
            throw new LogicException('Pending Square settlement retention must be at least one day.');
        }
        $expired = DB::table('square_pending_settlements')->where('workspace_id', $workspace->id)
            ->where('received_at', '<', $this->clock->now()->utc()->subDays($days));
        $ids = (clone $expired)->orderBy('id')->limit(1000)->pluck('id')->all();

        return $ids === [] ? 0 : $expired->whereIn('id', $ids)->delete();
    }

    private function forPayment(Workspace $workspace, string $paymentId): Builder
    {
        return DB::table('square_pending_settlements')->where('workspace_id', $workspace->id)->where('payment_identifier', $paymentId);
    }
}
