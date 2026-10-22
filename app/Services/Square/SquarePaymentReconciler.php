<?php

namespace App\Services\Square;

use App\Models\ClientInvoicePayment;
use App\Models\InvoiceSquareLink;
use App\Models\SquareInvoiceSnapshot;
use App\Models\Workspace;
use App\Services\Square\Mirror\SquareMirrorRepository;
use App\Services\Square\Mirror\SquareSnapshotMapper;
use App\Support\Square\SquareDisputeFacts;
use App\Support\Square\SquarePaymentFacts;
use App\Support\WorkspaceClock;

/** Webhooks and the sweep share exactly the same synchronous refetch operation. */
final class SquarePaymentReconciler
{
    /** Local bounds, not provider page-size promises; an incomplete sweep must fail. */
    private const int DISPUTE_MAX_PAGES = 100;

    private const int DISPUTE_MAX_RECORDS_PER_PAGE = 100;

    private const int DISPUTE_MAX_KNOWN_PAYMENTS = 10_000;

    public function __construct(
        private readonly SquareClient $client,
        private readonly SquareLinkRepository $links,
        private readonly SquareSnapshotMapper $mapper,
        private readonly SquareMirrorRepository $mirror,
        private readonly SquarePaymentRecorder $payments,
        private readonly WorkspaceClock $clock,
        private readonly SquareSettlementSync $settlements,
    ) {}

    public function invoice(Workspace $workspace, string $squareInvoiceId): void
    {
        $refetched = $this->refetch($workspace, $squareInvoiceId);
        if ($refetched !== null) {
            $this->apply($workspace, $refetched);
        }
    }

    /**
     * The read half: Square's invoice, order and payments re-read and
     * checked, and the mirror refreshed. No ledger write, and no database
     * transaction is held across the Square calls. Null when the Square
     * invoice was never linked here.
     */
    public function refetch(Workspace $workspace, string $squareInvoiceId): ?SquareInvoiceRefetch
    {
        if (! SquareConfiguration::fromConfig()->servesWorkspace($workspace)) {
            throw SquareRequestFailed::notConnected();
        }
        $link = $this->links->activeLinkForSquareInvoice($workspace->id, $squareInvoiceId);
        $historical = $link ?? InvoiceSquareLink::query()->where('workspace_id', $workspace->id)->where('square_invoice_id', $squareInvoiceId)->orderByDesc('id')->first();
        if ($historical === null) {
            return null;
        }
        $invoice = $this->client->getInvoice($squareInvoiceId)['invoice'] ?? null;
        $row = is_array($invoice) ? $this->mapper->invoice($invoice) : null;
        if ($row === null || $row['square_invoice_id'] !== $squareInvoiceId || ! is_string($row['square_order_id'])
            || ! is_string($row['square_location_id']) || $row['currency'] !== $historical->currency) {
            throw SquareRequestFailed::unreadable();
        }
        $order = $this->client->retrieveOrder($row['square_order_id'])['order'] ?? null;
        if (! is_array($order) || ($order['id'] ?? null) !== $row['square_order_id'] || ($order['location_id'] ?? null) !== $row['square_location_id']) {
            throw SquareRequestFailed::unreadable();
        }
        $tenders = $order['tenders'] ?? [];
        if (! is_array($tenders) || ! array_is_list($tenders) || count($tenders) > 100) {
            throw SquareRequestFailed::unreadable();
        }
        $facts = [];
        foreach ($tenders as $tender) {
            $id = is_array($tender) ? ($tender['payment_id'] ?? $tender['id'] ?? null) : null;
            if (! is_string($id) || preg_match(SquareClient::ID_PATTERN, $id) !== 1) {
                throw SquareRequestFailed::unreadable();
            }
            $payment = $this->client->getPayment($id)['payment'] ?? null;
            if (! is_array($payment)) {
                throw SquareRequestFailed::unreadable();
            }
            $fact = SquarePaymentFacts::from($payment, $id);
            if ($fact !== null) {
                if ($fact->orderId !== $row['square_order_id'] || $fact->locationId !== $row['square_location_id'] || $fact->currency !== $historical->currency) {
                    throw SquareRequestFailed::unreadable();
                }
                $facts[$id] = $fact;
            }
        }
        $this->mirror->storeInvoices($workspace, [$row], $this->clock->now()->utc());

        return new SquareInvoiceRefetch($link, array_values($facts));
    }

    /**
     * The write half: refunds applied to payments already recorded, and new
     * completed payments recorded against the active link, if there is one.
     * Makes no Square call.
     */
    public function apply(Workspace $workspace, SquareInvoiceRefetch $refetched): void
    {
        foreach ($refetched->payments as $fact) {
            if (! $this->payments->refundExisting($workspace, $fact) && $refetched->activeLink !== null) {
                $this->payments->record($workspace, $refetched->activeLink, $fact);
            }
            // Its capture time and Square's fee so far (S7), on the payment
            // just recorded or already there; nothing when svc has none.
            $this->settlements->recordCharge($workspace, $fact);
        }
    }

    public function payment(Workspace $workspace, string $paymentId): void
    {
        if (! SquareConfiguration::fromConfig()->servesWorkspace($workspace)) {
            throw SquareRequestFailed::notConnected();
        }
        $payment = $this->client->getPayment($paymentId)['payment'] ?? null;
        $facts = is_array($payment) ? SquarePaymentFacts::from($payment, $paymentId) : throw SquareRequestFailed::unreadable();
        if ($facts === null) {
            return;
        }
        if ($this->payments->refundExisting($workspace, $facts)) {
            $this->settlements->recordCharge($workspace, $facts);

            return;
        }
        // Only trusted, refetched order identity selects a workspace's links.
        $snapshot = SquareInvoiceSnapshot::query()->where('workspace_id', $workspace->id)->where('square_order_id', $facts->orderId)->first();
        if ($snapshot !== null) {
            $this->invoice($workspace, $snapshot->square_invoice_id);
        }
    }

    /** A notification selects its payment, whose complete current dispute group is applied. */
    public function dispute(Workspace $workspace, string $disputeId): void
    {
        if (preg_match(SquareClient::ID_PATTERN, $disputeId) !== 1) {
            throw SquareRequestFailed::unreadable();
        }
        $snapshot = $this->disputeSnapshot($workspace);
        $paymentId = $snapshot->paymentByDispute[$disputeId] ?? null;
        if ($paymentId === null) {
            throw SquareRequestFailed::notFound();
        }
        if (isset($snapshot->paymentVersions[$paymentId])) {
            $this->payments->recordDisputes($workspace, $paymentId, $snapshot->groups[$paymentId], $snapshot->paymentVersions[$paymentId]);
        }
    }

    /** Recover notifications only after the sweep has recorded its payments. */
    public function disputes(Workspace $workspace): void
    {
        $snapshot = $this->disputeSnapshot($workspace);
        foreach ($snapshot->groups as $paymentId => $disputes) {
            if (isset($snapshot->paymentVersions[$paymentId])) {
                $this->payments->recordDisputes($workspace, (string) $paymentId, $disputes, $snapshot->paymentVersions[$paymentId]);
            }
        }
    }

    /**
     * Square lists all states with no changed-since filter. Read and validate
     * every bounded page before writing, so an incomplete set cannot release
     * another dispute's hold. Local revisions are captured before HTTP: the
     * maximum update time of distinct disputes cannot order whole snapshots.
     *
     * @see https://developer.squareup.com/reference/square/disputes-api/list-disputes
     */
    private function disputeSnapshot(Workspace $workspace): SquareDisputeSnapshot
    {
        if (! SquareConfiguration::fromConfig()->servesWorkspace($workspace)) {
            throw SquareRequestFailed::notConnected();
        }
        $known = ClientInvoicePayment::query()->where('workspace_id', $workspace->id)
            ->where('provider', 'square')->whereNotNull('provider_payment_identifier')
            ->orderBy('id')->limit(self::DISPUTE_MAX_KNOWN_PAYMENTS + 1)->get(['provider_payment_identifier', 'lock_version']);
        if ($known->count() > self::DISPUTE_MAX_KNOWN_PAYMENTS) {
            throw SquareRequestFailed::unreadable();
        }
        $versions = [];
        foreach ($known as $payment) {
            $id = $payment->provider_payment_identifier;
            if (! is_string($id) || preg_match(SquareClient::ID_PATTERN, $id) !== 1) {
                throw SquareRequestFailed::unreadable();
            }
            $versions[$id] = $payment->lock_version;
        }
        unset($known);
        $groups = [];
        $paymentByDispute = [];
        $cursor = null;
        $seen = [];
        for ($page = 0; $page < self::DISPUTE_MAX_PAGES; $page++) {
            $response = $this->client->listDisputes($cursor);
            $records = array_key_exists('disputes', $response) ? $response['disputes'] : [];
            if (! is_array($records) || ! array_is_list($records) || count($records) > self::DISPUTE_MAX_RECORDS_PER_PAGE || ! empty($response['errors'])) {
                throw SquareRequestFailed::unreadable(SquareReadFailureReason::MalformedRecords);
            }
            foreach ($records as $record) {
                if (! is_array($record) || ! is_string($record['id'] ?? null)) {
                    throw SquareRequestFailed::unreadable(SquareReadFailureReason::MalformedRecords);
                }
                $facts = SquareDisputeFacts::from($record, $record['id']);
                if (isset($paymentByDispute[$facts->id])) {
                    throw SquareRequestFailed::unreadable(SquareReadFailureReason::MalformedRecords);
                }
                $paymentByDispute[$facts->id] = $facts->paymentId;
                $groups[$facts->paymentId][] = $facts;
            }
            $next = $response['cursor'] ?? null;
            if ($next === null || $next === '') {
                return new SquareDisputeSnapshot($versions, $groups, $paymentByDispute);
            }
            if (! is_string($next) || preg_match(SquareClient::CURSOR_PATTERN, $next) !== 1) {
                throw SquareRequestFailed::unreadable(SquareReadFailureReason::InvalidCursor);
            }
            if (isset($seen[$next])) {
                throw SquareRequestFailed::unreadable(SquareReadFailureReason::RepeatedCursor);
            }
            $seen[$next] = true;
            $cursor = $next;
        }

        throw SquareRequestFailed::unreadable();
    }

    /** @return iterable<InvoiceSquareLink> */
    public function linkedInvoices(Workspace $workspace): iterable
    {
        return InvoiceSquareLink::query()->where('workspace_id', $workspace->id)->whereNotNull('active_invoice_slot')->orderBy('id')->lazyById(100);
    }

    /** @return iterable<ClientInvoicePayment> */
    public function recordedPayments(Workspace $workspace): iterable
    {
        return ClientInvoicePayment::query()->where('workspace_id', $workspace->id)->where('provider', 'square')->whereNotNull('provider_payment_identifier')->orderBy('id')->lazyById(100);
    }
}
