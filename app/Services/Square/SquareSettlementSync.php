<?php

namespace App\Services\Square;

use App\Models\Workspace;
use App\Services\Finance\ProcessorSettlementRecorder;
use App\Support\Finance\PaymentSettlement;
use App\Support\Finance\PayoutFacts;
use App\Support\Finance\PayoutSyncResult;
use App\Support\Square\SquarePaymentFacts;
use App\Support\Square\SquarePayoutEntry;
use App\Support\Square\SquarePayoutFacts;
use Carbon\CarbonImmutable;

/**
 * Square's side of processor settlement (S7): the connected seller's payouts,
 * and on each payment the payout it settled in, its arrival date and fee.
 *
 * Everything is read from Square's read-only Payouts API - a `payout.*`
 * webhook is only the hint of which payout to read, as every Square webhook
 * is - and handed to {@see ProcessorSettlementRecorder} for the one workspace
 * the Square account is connected to. Square calls happen outside any
 * transaction.
 */
final class SquareSettlementSync
{
    /** The most entry pages one payout is read for; a payout is far smaller. */
    private const int MAX_ENTRY_PAGES = 200;

    public function __construct(
        private readonly SquareClient $client,
        private readonly ProcessorSettlementRecorder $recorder,
    ) {}

    /**
     * Read one payout and what it settled from Square, and record both.
     *
     * @throws SquareRequestFailed
     */
    public function payout(Workspace $workspace, string $payoutId, bool $apply = true): PayoutSyncResult
    {
        $this->assertServes($workspace);
        $payout = $this->client->getPayout($payoutId)['payout'] ?? null;

        $facts = is_array($payout) ? SquarePayoutFacts::from($payout, $payoutId) : throw SquareRequestFailed::unreadable();

        // A transfer into a Square balance reaches no bank account: nothing to store.
        return $facts === null ? new PayoutSyncResult([], 0, 0) : $this->apply($workspace, $facts, $apply);
    }

    /**
     * Every payout of the seller's locations, newest first, created on or
     * after `$since` when given, each recorded with what it settled.
     *
     * @return iterable<PayoutSyncResult>
     *
     * @throws SquareRequestFailed
     */
    public function payouts(Workspace $workspace, ?CarbonImmutable $since, bool $apply = true): iterable
    {
        $this->assertServes($workspace);
        $locations = $this->client->listLocations()['locations'] ?? [];
        foreach (is_array($locations) ? $locations : [] as $location) {
            $locationId = is_array($location) ? ($location['id'] ?? null) : null;
            if (! is_string($locationId)) {
                throw SquareRequestFailed::unreadable();
            }
            $cursor = null;
            do {
                $page = $this->client->listPayouts($locationId, $since?->utc()->toRfc3339String(), $cursor, 100);
                foreach (is_array($page['payouts'] ?? null) ? $page['payouts'] : [] as $payout) {
                    $id = is_array($payout) ? ($payout['id'] ?? null) : null;
                    if (! is_string($id)) {
                        throw SquareRequestFailed::unreadable();
                    }
                    $facts = SquarePayoutFacts::from($payout, $id);
                    if ($facts !== null) {
                        yield $this->apply($workspace, $facts, $apply);
                    }
                }
                $cursor = is_string($page['cursor'] ?? null) && $page['cursor'] !== '' ? $page['cursor'] : null;
            } while ($cursor !== null);
        }
    }

    /**
     * Record a payment's capture time and Square's processing fee so far,
     * from the payment as GetPayment returned it: what is known before any
     * payout. A fee from the payout that settled it is not overwritten.
     */
    public function recordCharge(Workspace $workspace, SquarePaymentFacts $payment, bool $apply = true): bool
    {
        $settlement = PaymentSettlement::charge(
            $payment->receivedAt,
            $payment->processingFee,
            $payment->processingFee === null ? null : $payment->currency,
        );

        return $this->recorder->recordPaymentSettlement($workspace, 'square', [$payment->id], $settlement, $apply);
    }

    /** @throws SquareRequestFailed */
    private function apply(Workspace $workspace, PayoutFacts $payout, bool $apply): PayoutSyncResult
    {
        // Every entry is read before anything is written, so a payout whose
        // entries cannot be read is not stored half applied.
        $entries = [];
        $cursor = null;
        $pages = 0;
        do {
            if (++$pages > self::MAX_ENTRY_PAGES) {
                throw SquareRequestFailed::unreadable();
            }
            $page = $this->client->listPayoutEntries($payout->identifier, $cursor, 100);
            $rows = $page['payout_entries'] ?? [];
            if (! is_array($rows) || ! array_is_list($rows)) {
                throw SquareRequestFailed::unreadable();
            }
            foreach ($rows as $row) {
                $entries[] = is_array($row) ? SquarePayoutEntry::from($row, $payout->identifier) : throw SquareRequestFailed::unreadable();
            }
            $cursor = is_string($page['cursor'] ?? null) && $page['cursor'] !== '' ? $page['cursor'] : null;
        } while ($cursor !== null);

        $outcome = $this->recorder->recordPayout($workspace, $payout, $apply);
        $payments = 0;
        // An older read than the one stored says nothing current about the
        // payout's payments either: an arrival date it carries may be one the
        // newer read already moved.
        foreach ($outcome->current() ? $entries : [] as $entry) {
            if ($entry->paymentId === null) {
                continue;
            }
            $settlement = PaymentSettlement::payout($payout->identifier, $payout->arrivalDate, $payout->observedAtMs, null, $entry->fee, $entry->currency);
            if ($this->recorder->recordPaymentSettlement($workspace, 'square', [$entry->paymentId], $settlement, $apply)) {
                $payments++;
            }
        }

        return new PayoutSyncResult([$workspace->id], $outcome->changed() ? 1 : 0, $payments);
    }

    /** @throws SquareRequestFailed */
    private function assertServes(Workspace $workspace): void
    {
        if (! SquareConfiguration::fromConfig()->servesWorkspace($workspace)) {
            throw SquareRequestFailed::notConnected();
        }
    }
}
