<?php

namespace App\Services\Billing;

use App\Models\ClientInvoice;
use App\Support\Billing\InvoiceLineDetail;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;

/**
 * The invoice as a document.
 *
 * The audience is a parameter, not a default. This PDF is served to operators
 * and to portal clients through the same route, and the appendix behind it
 * lists the work each line was billed from - so building one for an operator
 * and handing it to a client would publish every internal note behind a bill.
 * `InvoiceLineDetail` decides what each audience may read; this decides which
 * one is asking.
 */
final class InvoiceDocumentService
{
    /** A stable, filesystem-safe name for this invoice's PDF. */
    public function filename(ClientInvoice $invoice): string
    {
        return 'invoice-'.(Str::slug($invoice->invoice_number) ?: $invoice->public_id).'.pdf';
    }

    /** @param InvoiceLineDetail::OPERATOR|InvoiceLineDetail::CLIENT $audience */
    public function html(ClientInvoice $invoice, string $audience = InvoiceLineDetail::CLIENT): View
    {
        $lines = $invoice->lines()->where('workspace_id', $invoice->workspace_id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return view('invoices.show', [
            'invoice' => $invoice,
            // Stored billing values are snake-case protocol tokens. They are
            // useful for comparisons and the wrong vocabulary to print on a
            // document a client is being asked to pay.
            'statusLabel' => self::storedValueLabel((string) $invoice->status),
            'lineTypeLabels' => $lines->mapWithKeys(
                static fn ($line): array => [
                    $line->public_id => self::storedValueLabel($line->type),
                ],
            )->all(),
            // Read here and handed to the template, workspace-scoped, rather
            // than left for the view to reach through `$invoice->lines`. That
            // relation is unbounded, so the document listed one set of lines
            // while the appendix below itemised another - and on a row migrated
            // in from before the composite tenant keys those two sets are not
            // the same.
            'lines' => $lines,
            // Keyed by line public id, and empty for a line with no work behind
            // it - the retainer being sold for the coming cycle is a charge, not
            // a record of hours, and has nothing to itemise.
            'detail' => InvoiceLineDetail::forInvoice($invoice, $audience),
        ]);
    }

    /** @param InvoiceLineDetail::OPERATOR|InvoiceLineDetail::CLIENT $audience */
    public function pdf(ClientInvoice $invoice, string $audience = InvoiceLineDetail::CLIENT): string
    {
        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($this->html($invoice, $audience)->render());
        $dompdf->setPaper('letter');
        $dompdf->render();

        return $dompdf->output();
    }

    /** Mirror the sentence-case convention used for stored values in the UI. */
    private static function storedValueLabel(string $value): string
    {
        $words = trim((string) preg_replace('/[_-]+/', ' ', $value));

        return $words === '' ? '—' : ucfirst($words);
    }
}
