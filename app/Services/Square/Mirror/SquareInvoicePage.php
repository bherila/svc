<?php

namespace App\Services\Square\Mirror;

use App\Models\ClientInvoice;
use App\Models\SquareInvoiceSnapshot;
use App\Models\Workspace;
use App\Services\Square\SquareConfiguration;
use App\Services\Square\SquareLinkRepository;
use App\Support\Square\SquareHostedPage;

/**
 * Where a person views or pays a linked invoice in Square (#438).
 *
 * The address is the linked Square invoice's public_url as svc's mirror last
 * stored it from Square - never an address built from an id, never one a
 * caller supplied - and is checked again here to be HTTPS on a Square host.
 * An invoice that is not linked, whose Square invoice the mirror has not seen,
 * or in a workspace Square is not connected to, has none.
 */
final class SquareInvoicePage
{
    public function __construct(private readonly SquareLinkRepository $links) {}

    /** @return array{url: string, square_invoice_number: string, square_status: string, synced_at: string|null}|null */
    public function forInvoice(Workspace $workspace, ClientInvoice $invoice): ?array
    {
        if ($invoice->workspace_id !== $workspace->id || ! SquareConfiguration::fromConfig()->servesWorkspace($workspace)) {
            return null;
        }
        $link = $this->links->activeLink($invoice);
        if ($link === null) {
            return null;
        }
        $snapshot = SquareInvoiceSnapshot::query()->where('workspace_id', $workspace->id)->where('square_invoice_id', $link->square_invoice_id)->first();
        $url = SquareHostedPage::url($snapshot?->public_url);
        if ($snapshot === null || $url === null) {
            return null;
        }

        return [
            'url' => $url,
            'square_invoice_number' => $link->square_invoice_number,
            'square_status' => $snapshot->status,
            'synced_at' => SquareMirrorPresenter::instant($snapshot->synced_at),
        ];
    }
}
