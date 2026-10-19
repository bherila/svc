<?php

namespace App\Services\Finance;

use App\Casts\DateOnly;
use App\Models\ClientInvoicePayment;
use App\Models\ProcessorPayout;
use App\Models\Workspace;
use App\Services\Billing\BillingMaintenance;
use App\Support\Concurrency\Locks;
use App\Support\Finance\PaymentSettlement;
use App\Support\Finance\PayoutFacts;
use App\Support\Finance\PayoutRecordOutcome;
use App\Support\Finance\PayoutStatus;
use App\Support\WorkspaceClock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/**
 * The tenant-scoped boundary where processor settlement facts - payouts, and
 * each payment's charge time, fee and payout - reach the database (S7).
 *
 * Every write is named by the workspace, the provider and the processor's own
 * identifier, and writes only what differs, so the same report applied twice
 * (a redelivered webhook, a rerun backfill) changes nothing the second time and
 * moves no version. Each write that changes a row advances its revision and
 * `updated_at`, which is what the finance change feeds follow.
 *
 * `$apply = false` answers whether the report would change anything, through
 * the same comparison, and writes nothing: the backfill's dry run.
 *
 * No HTTP here: the adapters read the processor first, outside any
 * transaction, and hand over facts.
 */
final class ProcessorSettlementRecorder
{
    /** Concurrent writers of one payout row: each retry rereads it. */
    private const int PAYOUT_ATTEMPTS = 3;

    public function __construct(private readonly WorkspaceClock $clock) {}

    /**
     * Store the workspace's row for this payout, or bring it up to date.
     * A report older than the stored one changes nothing and is answered
     * {@see PayoutRecordOutcome::Stale}, so the caller applies nothing else it
     * says either. A newer report that changes nothing a reader sees still
     * moves the stored report time, so a report older than it is recognised
     * as stale, but not the row's version or `updated_at`.
     *
     * No row lock: the row is created by an insert that ignores a duplicate,
     * and changed by an update conditioned on the report time and revision it
     * read, so of two concurrent reports the newer one is the one that sticks.
     */
    public function recordPayout(Workspace $workspace, PayoutFacts $facts, bool $apply = true): PayoutRecordOutcome
    {
        for ($attempt = 1; $attempt <= self::PAYOUT_ATTEMPTS; $attempt++) {
            $stored = ProcessorPayout::query()
                ->where('workspace_id', $workspace->id)
                ->where('provider', $facts->provider)
                ->where('provider_payout_identifier', $facts->identifier)
                ->first();
            if ($stored === null) {
                if (! $apply) {
                    return PayoutRecordOutcome::Recorded;
                }
                $now = $this->clock->now()->utc();
                $inserted = DB::table('processor_payouts')->insertOrIgnore([
                    'public_id' => (string) Str::uuid(),
                    'workspace_id' => $workspace->id,
                    'provider' => $facts->provider,
                    'provider_payout_identifier' => $facts->identifier,
                    ...$this->payoutColumns($facts),
                    'lock_version' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                if ($inserted === 1) {
                    return PayoutRecordOutcome::Recorded;
                }

                // Another report created it first: compare with that one.
                continue;
            }
            if (! $facts->supersedes($stored->provider_updated_at_ms, PayoutStatus::tryFrom((string) $stored->status))) {
                return PayoutRecordOutcome::Stale;
            }
            $columns = $this->payoutColumns($facts);
            $changed = (int) $stored->amount !== $facts->amount
                || $stored->currency !== $facts->currency
                || $stored->arrival_date?->toDateString() !== $facts->arrivalDate
                || $stored->type !== $columns['type']
                || $stored->status !== $columns['status'];
            if (! $apply) {
                return $changed ? PayoutRecordOutcome::Recorded : PayoutRecordOutcome::Unchanged;
            }
            if (! $changed && $facts->observedAtMs === $stored->provider_updated_at_ms) {
                return PayoutRecordOutcome::Unchanged;
            }
            $updated = ProcessorPayout::query()
                ->where('workspace_id', $workspace->id)
                ->whereKey($stored->id)
                ->where('provider_updated_at_ms', $stored->provider_updated_at_ms)
                ->where('lock_version', $stored->lock_version)
                ->update($changed
                    ? [
                        ...$columns,
                        'lock_version' => DB::raw('lock_version + 1'),
                        'updated_at' => $this->clock->now()->utc(),
                    ]
                    // Nothing a reader sees moved: the newer report time is
                    // kept, so an older report is still refused after it, but
                    // the version and updated_at - what a change feed follows -
                    // stay where they are.
                    : ['provider_updated_at_ms' => $facts->observedAtMs, 'updated_at' => $stored->updated_at]);
            if ($updated === 1) {
                return $changed ? PayoutRecordOutcome::Recorded : PayoutRecordOutcome::Unchanged;
            }
        }

        throw new LogicException('The payout kept changing while it was being recorded.');
    }

    /**
     * Bring one of the workspace's processor payments up to date with what the
     * processor reports about settling it. The payment is found by any of the
     * processor's identifiers for it (a Stripe payment intent or its charge);
     * one svc never recorded is left alone, and false is returned.
     *
     * A fee is kept only in the payment's own currency: a payment settled in
     * another currency is in a payout of that currency, and its fee would not
     * be minor units of the payment. A fee from the payout's accounting is not
     * overwritten by a charge-time report.
     *
     * @param  list<string>  $identifiers
     */
    public function recordPaymentSettlement(Workspace $workspace, string $provider, array $identifiers, PaymentSettlement $settlement, bool $apply = true): bool
    {
        $identifiers = array_values(array_unique(array_filter($identifiers, static fn (string $id): bool => $id !== '')));
        if ($identifiers === []) {
            return false;
        }

        $write = function () use ($workspace, $provider, $identifiers, $settlement, $apply): bool {
            $payment = ClientInvoicePayment::query()
                ->where('workspace_id', $workspace->id)
                ->where('provider', $provider)
                ->whereIn('provider_payment_identifier', $identifiers)
                ->when($apply, static fn ($payments) => $payments->tap(Locks::forUpdate()))
                ->orderBy('id')
                ->first();
            if ($payment === null) {
                return false;
            }
            $changes = $this->paymentChanges($payment, $settlement);
            if ($changes === []) {
                return false;
            }
            if (! $apply) {
                return true;
            }
            ClientInvoicePayment::query()
                ->where('workspace_id', $workspace->id)
                ->whereKey($payment->id)
                ->update([
                    ...$changes,
                    // A builder update never reaches IncrementsAgentRevision, so
                    // the revision the finance feed compares is moved here.
                    'lock_version' => DB::raw('lock_version + 1'),
                    'updated_at' => $this->clock->now()->utc(),
                ]);

            return true;
        };

        if (! $apply) {
            return $write();
        }

        return DB::transaction(function () use ($workspace, $write): bool {
            BillingMaintenance::share($workspace->id);

            return $write();
        });
    }

    /**
     * The columns this settlement changes on the payment, compared as stored.
     *
     * @return array<string, mixed>
     */
    private function paymentChanges(ClientInvoicePayment $payment, PaymentSettlement $settlement): array
    {
        $changes = [];
        if ($settlement->chargedAt !== null) {
            // Stored to the second, and compared as stored.
            $chargedAt = $settlement->chargedAt->utc()->startOfSecond();
            if ($payment->provider_charged_at === null || ! $chargedAt->equalTo($payment->provider_charged_at)) {
                $changes['provider_charged_at'] = $chargedAt->format('Y-m-d H:i:s');
            }
        }
        $feeKept = $settlement->fromPayout || $payment->provider_payout_identifier === null;
        if ($settlement->feeAmount !== null && $feeKept && $settlement->feeCurrency === $payment->currency
            && $payment->provider_fee_amount !== $settlement->feeAmount) {
            $changes['provider_fee_amount'] = $settlement->feeAmount;
        }
        if ($settlement->payoutIdentifier !== null) {
            if ($payment->provider_payout_identifier !== $settlement->payoutIdentifier) {
                $changes['provider_payout_identifier'] = $settlement->payoutIdentifier;
            }
            if ($payment->provider_payout_arrival_date?->toDateString() !== $settlement->payoutArrivalDate) {
                $changes['provider_payout_arrival_date'] = $settlement->payoutArrivalDate === null ? null : DateOnly::toStored($settlement->payoutArrivalDate);
            }
        }

        return $changes;
    }

    /** @return array{amount: int, currency: string, arrival_date: ?string, type: string, status: string, provider_updated_at_ms: int} */
    private function payoutColumns(PayoutFacts $facts): array
    {
        return [
            'amount' => $facts->amount,
            'currency' => $facts->currency,
            'arrival_date' => $facts->arrivalDate === null ? null : DateOnly::toStored($facts->arrivalDate),
            'type' => $facts->type->value,
            'status' => $facts->status->value,
            'provider_updated_at_ms' => $facts->observedAtMs,
        ];
    }
}
