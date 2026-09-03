<?php

namespace App\Services\Billing;

use App\Models\ClientInvoice;
use App\Support\Billing\InvoiceLineDetail;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Contracts\View\View;

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
    /** @param InvoiceLineDetail::OPERATOR|InvoiceLineDetail::CLIENT $audience */
    public function html(ClientInvoice $invoice, string $audience = InvoiceLineDetail::CLIENT): View
    {
        $invoice->load(['lines', 'clientCompany']);

        return view('invoices.show', [
            'invoice' => $invoice,
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
}
