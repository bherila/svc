<?php

namespace App\Services\Billing;

use App\Models\ClientInvoice;
use App\Services\Billing\Pdf\DompdfInvoicePdfRenderer;
use App\Services\Billing\Pdf\InvoicePdfRenderer;
use App\Support\Billing\Document\DocumentMode;
use App\Support\Billing\Document\InvoiceDocument;
use App\Support\Billing\InvoiceLineDetail;
use Dompdf\Dompdf;
use Dompdf\Frame;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;

/**
 * The invoice as a document, for the screen, the PDF and the proforma.
 *
 * The audience is a parameter, not a default. This PDF is served to operators
 * and to portal clients through the same route, and the appendix behind it
 * lists the work each line was billed from - so building one for an operator
 * and handing it to a client would publish every internal note behind a bill.
 * `InvoiceLineDetail` decides what each audience may read; this decides which
 * one is asking.
 *
 * The document itself is {@see InvoiceDocument}, built by
 * {@see InvoiceDocumentBuilder}; the PDF is drawn by whichever
 * {@see InvoicePdfRenderer} the configuration names.
 */
final class InvoiceDocumentService
{
    /** US Letter in PDF points; see {@see DompdfInvoicePdfRenderer::US_LETTER_POINTS}. */
    public const US_LETTER_POINTS = DompdfInvoicePdfRenderer::US_LETTER_POINTS;

    public function __construct(
        private readonly InvoiceDocumentBuilder $builder,
        private readonly InvoicePdfRenderer $renderer,
        private readonly DompdfInvoicePdfRenderer $dompdf,
    ) {}

    /** A stable, filesystem-safe name for this invoice's PDF. */
    public function filename(ClientInvoice $invoice, DocumentMode $mode = DocumentMode::Invoice): string
    {
        $slug = Str::slug((string) $invoice->invoice_number) ?: (string) $invoice->public_id;

        return match ($mode) {
            DocumentMode::Invoice => 'invoice-'.$slug.'.pdf',
            DocumentMode::Proforma => 'proforma-'.$slug.'.pdf',
        };
    }

    /** @param InvoiceLineDetail::OPERATOR|InvoiceLineDetail::CLIENT $audience */
    public function document(ClientInvoice $invoice, string $audience = InvoiceLineDetail::CLIENT, DocumentMode $mode = DocumentMode::Invoice): InvoiceDocument
    {
        return $this->builder->build($invoice, $audience, $mode);
    }

    /** @param InvoiceLineDetail::OPERATOR|InvoiceLineDetail::CLIENT $audience */
    public function html(ClientInvoice $invoice, string $audience = InvoiceLineDetail::CLIENT, DocumentMode $mode = DocumentMode::Invoice): View
    {
        return $this->dompdf->html($this->document($invoice, $audience, $mode));
    }

    /** @param InvoiceLineDetail::OPERATOR|InvoiceLineDetail::CLIENT $audience */
    public function pdf(ClientInvoice $invoice, string $audience = InvoiceLineDetail::CLIENT, DocumentMode $mode = DocumentMode::Invoice): string
    {
        return $this->renderer->render($this->document($invoice, $audience, $mode))->bytes;
    }

    /**
     * The laid-out document before it is serialised, for the layout tests.
     *
     * @param  InvoiceLineDetail::OPERATOR|InvoiceLineDetail::CLIENT  $audience
     * @param  (callable(Frame): void)|null  $onFrame
     */
    public function rendered(ClientInvoice $invoice, string $audience = InvoiceLineDetail::CLIENT, ?callable $onFrame = null): Dompdf
    {
        return $this->dompdf->laidOut($this->document($invoice, $audience), $onFrame);
    }
}
