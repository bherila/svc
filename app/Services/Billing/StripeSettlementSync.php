<?php

namespace App\Services\Billing;

use App\Models\ClientInvoicePayment;
use App\Models\ProcessorPayout;
use App\Models\Workspace;
use App\Services\Finance\ProcessorSettlementRecorder;
use App\Support\Billing\StripeBalanceEntry;
use App\Support\Billing\StripeEventMustBeRetried;
use App\Support\Billing\StripePayoutFacts;
use App\Support\Finance\PaymentSettlement;
use App\Support\Finance\PayoutFacts;
use App\Support\Finance\PayoutSyncResult;
use App\Support\Finance\PayoutType;
use Carbon\CarbonImmutable;
use RuntimeException;
use Stripe\Exception\ExceptionInterface;

/**
 * Stripe's side of processor settlement (S7): which payout each payment
 * settled in, its fee and its charge time, and the payouts themselves.
 *
 * Reads Stripe, outside any transaction, and hands facts to
 * {@see ProcessorSettlementRecorder}. Every Stripe failure is a
 * {@see StripeEventMustBeRetried}: the webhook answers it with a retryable
 * error and the backfill counts it.
 *
 * One Stripe account serves the installation, and a payout carries no
 * workspace. A payout belongs to every workspace one of its balance
 * transactions names a payment of - a charge it settles, or a refund or
 * dispute it pays back - and each gets its own row. Stripe lists a payout's
 * balance transactions only for an automatic payout, so a manual or instant
 * payout is stored only where a row for it already exists: in v1 those are
 * left to the finance system as unexplained (net-only matching).
 */
final class StripeSettlementSync
{
    public function __construct(
        private readonly StripeGateway $gateway,
        private readonly ProcessorSettlementRecorder $recorder,
    ) {}

    /**
     * The balance transactions an automatic payout settled; none for any
     * other payout, whose transactions Stripe does not list.
     *
     * @return list<StripeBalanceEntry>
     *
     * @throws StripeEventMustBeRetried
     */
    public function members(PayoutFacts $payout): array
    {
        if ($payout->type !== PayoutType::Automatic) {
            return [];
        }
        $entries = [];
        $this->read(function () use ($payout, &$entries): void {
            $transactions = $this->gateway->client()->balanceTransactions->all([
                'payout' => $payout->identifier,
                'limit' => 100,
                'expand' => ['data.source'],
            ]);
            foreach ($transactions->autoPagingIterator() as $transaction) {
                $row = $transaction->toArray();
                // The payout's own transaction is the payout, not a member of it.
                if (($row['type'] ?? null) === 'payout') {
                    continue;
                }
                $entries[] = StripeBalanceEntry::from($row);
            }
        });

        return $entries;
    }

    /**
     * Store the payout for each workspace it reaches, and record on each
     * charged payment that it settled in this payout, with its fee and charge
     * time. `$onlyWorkspaceId` keeps every write to that one workspace.
     *
     * @param  list<StripeBalanceEntry>  $members
     */
    public function apply(PayoutFacts $payout, array $members, bool $apply = true, ?int $onlyWorkspaceId = null): PayoutSyncResult
    {
        $identifiers = [];
        foreach ($members as $member) {
            array_push($identifiers, ...$member->paymentIdentifiers);
        }
        // Which workspace each identifier names a payment in. A payment is
        // found across workspaces by the processor's id, as every Stripe event
        // finds it; nothing is written outside the payment's own workspace.
        $owners = [];
        foreach (array_chunk(array_values(array_unique($identifiers)), 500) as $chunk) {
            foreach (ClientInvoicePayment::query()->where('provider', 'stripe')->whereIn('provider_payment_identifier', $chunk)
                ->get(['workspace_id', 'provider_payment_identifier']) as $payment) {
                $identifier = $payment->getAttribute('provider_payment_identifier');
                $workspaceId = $payment->workspaceId();
                if (is_string($identifier) && $workspaceId !== null) {
                    $owners[$identifier] = $workspaceId;
                }
            }
        }
        $workspaceIds = array_values($owners);
        // A report about a payout already stored (a failure after it was
        // paid, or one Stripe no longer lists members for) reaches it.
        foreach (ProcessorPayout::query()->where('provider', 'stripe')->where('provider_payout_identifier', $payout->identifier)->get(['workspace_id']) as $stored) {
            $workspaceIds[] = $stored->workspaceId();
        }
        $workspaceIds = array_values(array_unique(array_filter($workspaceIds, static fn (?int $id): bool => $id !== null)));
        if ($onlyWorkspaceId !== null) {
            $workspaceIds = array_values(array_intersect($workspaceIds, [$onlyWorkspaceId]));
        }
        sort($workspaceIds);

        $rows = 0;
        $payments = 0;
        foreach (Workspace::query()->whereIn('id', $workspaceIds)->orderBy('id')->get() as $workspace) {
            $outcome = $this->recorder->recordPayout($workspace, $payout, $apply);
            if ($outcome->changed()) {
                $rows++;
            }
            // An older report than the one stored says nothing current about
            // the payout's payments either: an arrival date it carries may be
            // one the newer report already moved.
            if (! $outcome->current()) {
                continue;
            }
            foreach ($members as $member) {
                if (! $member->isCharge || ! $this->belongsTo($member, $owners, $workspace->id)) {
                    continue;
                }
                $settlement = PaymentSettlement::payout($payout->identifier, $payout->arrivalDate, $member->chargedAt, $member->fee, $member->currency);
                if ($this->recorder->recordPaymentSettlement($workspace, 'stripe', $member->paymentIdentifiers, $settlement, $apply)) {
                    $payments++;
                }
            }
        }

        return new PayoutSyncResult($workspaceIds, $rows, $payments);
    }

    /**
     * Read the payment's charge and its balance transaction from Stripe and
     * record its charge time and fee: what is known before any payout.
     *
     * @throws StripeEventMustBeRetried
     */
    public function recordCharge(ClientInvoicePayment $payment, bool $apply = true): bool
    {
        $identifier = $payment->getAttribute('provider_payment_identifier');
        if (! is_string($identifier) || $identifier === '') {
            return false;
        }
        $charge = null;
        $this->read(function () use ($identifier, &$charge): void {
            $client = $this->gateway->client();
            if (str_starts_with($identifier, 'pi_')) {
                // The intent's charges, newest first: the one that succeeded is
                // the payment. Read through the charges, never the intent,
                // which is where svc starts a collection.
                $charges = $client->charges->all(['payment_intent' => $identifier, 'limit' => 10, 'expand' => ['data.balance_transaction']])->toArray();
                foreach (is_array($charges['data'] ?? null) ? $charges['data'] : [] as $candidate) {
                    if (is_array($candidate) && ($candidate['status'] ?? null) === 'succeeded') {
                        $charge = $candidate;
                        break;
                    }
                }
            } elseif (str_starts_with($identifier, 'ch_') || str_starts_with($identifier, 'py_')) {
                $charge = $client->charges->retrieve($identifier, ['expand' => ['balance_transaction']])->toArray();
            }
        });
        if (! is_array($charge)) {
            return false;
        }
        $created = $charge['created'] ?? null;
        $transaction = $charge['balance_transaction'] ?? null;
        $fee = is_array($transaction) && is_int($transaction['fee'] ?? null) ? $transaction['fee'] : null;
        $currency = is_array($transaction) && is_string($transaction['currency'] ?? null) ? strtoupper($transaction['currency']) : null;
        $currency = $currency !== null && preg_match('/^[A-Z]{3}$/D', $currency) === 1 ? $currency : null;
        $settlement = PaymentSettlement::charge(
            is_int($created) ? CarbonImmutable::createFromTimestampUTC($created) : null,
            $fee !== null && $currency !== null ? $fee : null,
            $fee !== null && $currency !== null ? $currency : null,
        );
        $identifiers = array_values(array_filter([$identifier, is_string($charge['id'] ?? null) ? $charge['id'] : null]));

        return $this->recorder->recordPaymentSettlement($this->workspaceOf($payment), 'stripe', $identifiers, $settlement, $apply);
    }

    /**
     * Stripe's payouts, newest first, created on or after `$since` when given,
     * each read as facts observed now.
     *
     * @return iterable<PayoutFacts>
     *
     * @throws StripeEventMustBeRetried
     */
    public function payouts(?CarbonImmutable $since, int $observedAtMs): iterable
    {
        try {
            $payouts = $this->gateway->client()->payouts->all(array_filter([
                'limit' => 100,
                'created' => $since === null ? null : ['gte' => $since->getTimestamp()],
            ], static fn (mixed $value): bool => $value !== null));
            // An exception in the caller's loop never reaches this generator;
            // only Stripe's reads, as the iterator advances, are caught here.
            foreach ($payouts->autoPagingIterator() as $payout) {
                yield StripePayoutFacts::from($payout->toArray(), $observedAtMs);
            }
        } catch (ExceptionInterface|RuntimeException $failure) {
            throw $this->retryable($failure);
        }
    }

    /**
     * @param  array<string, int>  $owners  payment identifier => workspace id
     */
    private function belongsTo(StripeBalanceEntry $member, array $owners, int $workspaceId): bool
    {
        foreach ($member->paymentIdentifiers as $identifier) {
            if (($owners[$identifier] ?? null) === $workspaceId) {
                return true;
            }
        }

        return false;
    }

    private function workspaceOf(ClientInvoicePayment $payment): Workspace
    {
        return Workspace::query()->whereKey($payment->workspace_id)->firstOrFail();
    }

    /** @throws StripeEventMustBeRetried */
    private function read(\Closure $read): void
    {
        try {
            $read();
        } catch (ExceptionInterface|RuntimeException $failure) {
            throw $this->retryable($failure);
        }
    }

    /**
     * A Stripe failure, or Stripe not being configured, as a retryable one.
     * Not chained: a Stripe error can carry request details. Anything else is
     * not a read failure and is rethrown as it is.
     */
    private function retryable(ExceptionInterface|RuntimeException $failure): StripeEventMustBeRetried
    {
        if ($failure instanceof StripeEventMustBeRetried) {
            return $failure;
        }
        if ($failure instanceof ExceptionInterface) {
            return new StripeEventMustBeRetried('Stripe could not be read ('.$failure::class.').');
        }
        if ($failure->getMessage() === 'Stripe is not configured.') {
            return new StripeEventMustBeRetried('Stripe is not configured.');
        }

        throw $failure;
    }
}
