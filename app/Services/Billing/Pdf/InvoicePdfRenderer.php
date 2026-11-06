<?php

namespace App\Services\Billing\Pdf;

use App\Support\Billing\Document\InvoiceDocument;

/**
 * Turns an {@see InvoiceDocument} into PDF bytes.
 *
 * The audience is part of the document, so a renderer never decides what a
 * reader may see. Which implementation serves is chosen by
 * `config('billing.invoice_pdf_renderer')`; Dompdf is the only one today, and
 * any other must print every fact the golden tests read from the document.
 */
interface InvoicePdfRenderer
{
    public function render(InvoiceDocument $document): RenderedInvoicePdf;
}
