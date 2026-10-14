<?php

namespace App\Services\Square;

use App\Models\InvoiceSquareLink;
use App\Models\SquareInvoiceSnapshot;
use App\Models\Workspace;
use App\Services\Square\Mirror\SquareMirrorRepository;
use App\Services\Square\Mirror\SquareSnapshotMapper;
use App\Support\Square\SquarePaymentFacts;
use App\Support\WorkspaceClock;

/** Webhooks and the sweep share exactly the same synchronous refetch operation. */
final class SquarePaymentReconciler
{
    public function __construct(
        private readonly SquareClient $client,
        private readonly SquareLinkRepository $links,
        private readonly SquareSnapshotMapper $mapper,
        private readonly SquareMirrorRepository $mirror,
        private readonly SquarePaymentRecorder $payments,
        private readonly WorkspaceClock $clock,
    ) {}

    public function invoice(Workspace $workspace, string $squareInvoiceId): void
    {
        if (! SquareConfiguration::fromConfig()->servesWorkspace($workspace)) {
            throw SquareRequestFailed::notConnected();
        }
        $link = $this->links->activeLinkForSquareInvoice($workspace->id, $squareInvoiceId);
        if ($link === null) {
            return;
        }
        $invoice = $this->client->getInvoice($squareInvoiceId)['invoice'] ?? null;
        $row = is_array($invoice) ? $this->mapper->invoice($invoice) : null;
        if ($row === null || $row['square_invoice_id'] !== $squareInvoiceId || ! is_string($row['square_order_id'])
            || ! is_string($row['square_location_id']) || $row['currency'] !== $link->currency) {
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
                if ($fact->orderId !== $row['square_order_id'] || $fact->locationId !== $row['square_location_id'] || $fact->currency !== $link->currency) {
                    throw SquareRequestFailed::unreadable();
                }
                $facts[$id] = $fact;
            }
        }
        $this->mirror->storeInvoices($workspace, [$row], $this->clock->now()->utc());
        foreach ($facts as $fact) {
            $this->payments->record($workspace, $link, $fact);
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
        // Only trusted, refetched order identity selects a workspace's links.
        $snapshot = SquareInvoiceSnapshot::query()->where('workspace_id', $workspace->id)->where('square_order_id', $facts->orderId)->first();
        if ($snapshot !== null) {
            $this->invoice($workspace, $snapshot->square_invoice_id);
        }
    }

    /** @return iterable<InvoiceSquareLink> */
    public function linkedInvoices(Workspace $workspace): iterable
    {
        return InvoiceSquareLink::query()->where('workspace_id', $workspace->id)->whereNotNull('active_invoice_slot')->orderBy('id')->lazyById(100);
    }
}
