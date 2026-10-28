<?php

namespace App\Services\Square;

use App\Models\Workspace;
use App\Services\Square\Mirror\SquareMirrorRepository;
use App\Services\Square\Mirror\SquareSnapshotMapper;
use App\Support\Square\SquarePaymentFacts;
use App\Support\WorkspaceClock;

/** Refetched invoice/order identity binds completed payment facts before any ledger write. */
final class SquareInvoicePaymentReader
{
    public function __construct(private readonly SquareClient $client, private readonly SquareSnapshotMapper $mapper,
        private readonly SquareMirrorRepository $mirror, private readonly WorkspaceClock $clock) {}

    /** @return list<SquarePaymentFacts> */
    public function read(Workspace $workspace, string $squareInvoiceId, string $currency, bool $refreshMirror = true): array
    {
        if (! SquareConfiguration::fromConfig()->servesWorkspace($workspace)) {
            throw SquareRequestFailed::notConnected();
        }
        $invoice = $this->client->getInvoice($squareInvoiceId)['invoice'] ?? null;
        $row = is_array($invoice) ? $this->mapper->invoice($invoice) : null;
        if ($row === null || $row['square_invoice_id'] !== $squareInvoiceId || ! is_string($row['square_order_id'])
            || ! is_string($row['square_location_id']) || $row['currency'] !== $currency) {
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
                if ($fact->orderId !== $row['square_order_id'] || $fact->locationId !== $row['square_location_id'] || $fact->currency !== $currency) {
                    throw SquareRequestFailed::unreadable();
                }
                $facts[$id] = $fact;
            }
        }
        if ($refreshMirror) {
            $this->mirror->storeInvoices($workspace, [$row], $this->clock->now()->utc());
        }

        return array_values($facts);
    }
}
