<?php

namespace App\Services\Billing;

use App\Models\ClientInvoice;
use App\Support\Billing\InvoiceHoursStatementRows;
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
    /**
     * US Letter, 8.5 x 11 in, in PDF points (72 to the inch).
     *
     * Given as a box rather than the name `letter`, so the page size is a fact
     * this class states and a test can read back from the MediaBox, rather than
     * whatever the renderer's paper table maps that name to.
     *
     * @var array{0: float, 1: float, 2: float, 3: float}
     */
    public const US_LETTER_POINTS = [0.0, 0.0, 612.0, 792.0];

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

        $detail = InvoiceLineDetail::forInvoice($invoice, $audience);

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
            'detail' => $detail,
            'audience' => $audience,
            'genericLabelUsed' => $audience === InvoiceLineDetail::CLIENT && collect($detail)
                ->flatten(1)
                ->contains(static fn (array $item): bool => $item['description'] === InvoiceLineDetail::CLIENT_GENERIC_LABEL),
            // The stored snapshot, laid out; never recomputed from the ledger,
            // so an issued invoice's statement cannot move after it was sent.
            'statement' => ($statement = $invoice->hoursStatement()) === null
                ? []
                : InvoiceHoursStatementRows::for($statement),
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
        $dompdf->setPaper(self::US_LETTER_POINTS);
        $dompdf->render();
        $this->numberPages($dompdf);

        return $dompdf->output();
    }

    /**
     * "Page N of M" in the running footer of every page.
     *
     * Written onto the canvas after layout rather than through CSS: the total
     * is only known once every page exists, and the renderer's `counter(pages)`
     * prints zero. Right-aligned to the same margin as the footer rule.
     */
    private function numberPages(Dompdf $dompdf): void
    {
        $canvas = $dompdf->getCanvas();
        $metrics = $dompdf->getFontMetrics();
        $font = $metrics->getFont('DejaVu Sans');
        // Bundled with the renderer, so always found; a document is still
        // worth more unnumbered than not at all.
        if ($font === null) {
            return;
        }
        $size = 6.75;
        // Measured with two-digit numbers, the widest a realistic document
        // reaches, so the text ends at the margin once the numbers are filled in.
        $width = $metrics->getTextWidth('Page 88 of 88', $font, $size);
        $rightMargin = 0.75 * 72;
        $canvas->page_text(
            $canvas->get_width() - $rightMargin - $width,
            $canvas->get_height() - 0.55 * 72 - 2,
            'Page {PAGE_NUM} of {PAGE_COUNT}',
            $font,
            $size,
            [0.42, 0.45, 0.5],
        );
    }

    /** Mirror the sentence-case convention used for stored values in the UI. */
    private static function storedValueLabel(string $value): string
    {
        $words = trim((string) preg_replace('/[_-]+/', ' ', $value));

        return $words === '' ? '—' : ucfirst($words);
    }
}
