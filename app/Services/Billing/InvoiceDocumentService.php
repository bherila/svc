<?php

namespace App\Services\Billing;

use App\Models\ClientInvoice;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Contracts\View\View;

final class InvoiceDocumentService
{
    public function html(ClientInvoice $invoice): View
    {
        return view('invoices.show', ['invoice' => $invoice->load(['lines', 'clientCompany'])]);
    }

    public function pdf(ClientInvoice $invoice): string
    {
        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($this->html($invoice)->render());
        $dompdf->setPaper('letter');
        $dompdf->render();

        return $dompdf->output();
    }
}
