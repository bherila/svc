<?php

namespace App\Services\Billing;

use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientInvoiceLine;
use App\Models\Workspace;
use App\Services\Billing\Autopay\AutopayReader;
use App\Support\Billing\Document\DocumentMode;
use App\Support\Billing\Document\InvoiceDocument;
use App\Support\Billing\Document\InvoiceDocumentGrouper;
use App\Support\Billing\Document\InvoiceDocumentLine;
use App\Support\Billing\Document\InvoiceDocumentNotices;
use App\Support\Billing\InvoiceDeferredWorkDisclosure;
use App\Support\Billing\InvoiceHoursStatementRows;
use App\Support\Billing\InvoiceLineDetail;

/**
 * Builds the {@see InvoiceDocument} for an invoice: the one tenant-scoped read
 * behind the screen, the PDF and the proforma.
 *
 * Every query names the invoice's workspace. The audience is a parameter with
 * no default: the same document is served to operators and to portal clients,
 * and what each may read is decided by {@see InvoiceLineDetail} and
 * {@see InvoiceDeferredWorkDisclosure}, not here. The decisions that are not
 * reads live in {@see InvoiceDocumentNotices} and the grouper, which are pure.
 */
final class InvoiceDocumentBuilder
{
    /** @param InvoiceLineDetail::OPERATOR|InvoiceLineDetail::CLIENT $audience */
    public function build(ClientInvoice $invoice, string $audience, DocumentMode $mode = DocumentMode::Invoice): InvoiceDocument
    {
        $lines = array_values($invoice->lines()->where('workspace_id', $invoice->workspace_id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(static fn (ClientInvoiceLine $line): InvoiceDocumentLine => new InvoiceDocumentLine(
                id: (string) $line->public_id,
                type: (string) $line->type,
                description: (string) $line->description,
                quantity: (string) $line->quantity,
                hours: $line->hours === null ? null : (float) $line->hours,
                lineDate: $line->line_date?->toDateString(),
                unitAmount: (int) $line->unit_amount,
                taxAmount: (int) $line->tax_amount,
                totalAmount: (int) $line->total_amount,
            ))
            ->all());

        $detail = InvoiceLineDetail::forInvoice($invoice, $audience);
        $statement = $invoice->hoursStatement();
        $company = ClientCompany::query()
            ->where('workspace_id', $invoice->workspace_id)
            ->whereKey($invoice->client_company_id)
            ->first();
        $issuer = Workspace::query()->whereKey($invoice->workspace_id)->value('name');

        $payUrl = $company !== null && InvoiceDocumentNotices::offersPayLink(
            $mode,
            (string) $invoice->status,
            (int) $invoice->balance_amount,
            (bool) $invoice->is_visible_to_client,
        ) ? route('portal.invoice', [$company, $invoice], absolute: true) : null;

        return new InvoiceDocument(
            mode: $mode,
            audience: $audience,
            issuerName: is_string($issuer) ? $issuer : '',
            // A proforma has no number: the preview's draft was numbered inside a
            // transaction that is rolled back, so the number names nothing.
            invoiceNumber: $mode === DocumentMode::Proforma || ((string) $invoice->invoice_number) === '' ? null : (string) $invoice->invoice_number,
            status: (string) $invoice->status,
            currency: (string) $invoice->currency,
            billTo: (string) $company?->name,
            issueDate: $invoice->issue_date?->format('Y-m-d'),
            dueDate: $invoice->due_date?->format('Y-m-d'),
            servicePeriodStart: $invoice->service_period_start?->format('Y-m-d'),
            servicePeriodEnd: $invoice->service_period_end?->format('Y-m-d'),
            reissuedOn: $invoice->reissued_at?->format('Y-m-d'),
            voidedOn: $invoice->voided_at?->format('Y-m-d'),
            sections: InvoiceDocumentGrouper::group($lines),
            lines: $lines,
            subtotal: (int) $invoice->subtotal_amount,
            tax: (int) $invoice->tax_amount,
            total: (int) $invoice->total_amount,
            paid: (int) $invoice->paid_amount,
            balance: (int) $invoice->balance_amount,
            // The stored snapshot, laid out; never recomputed from the ledger,
            // so an issued invoice's statement cannot move after it was sent.
            statement: $statement === null ? [] : InvoiceHoursStatementRows::for($statement),
            statementIntro: InvoiceDocumentNotices::statementIntro($statement?->isCorrection() === true),
            // Clients see the itemised list only where the agreement opts in.
            deferredWork: InvoiceDeferredWorkDisclosure::documentData($invoice, $audience),
            appendix: InvoiceDocumentNotices::appendix($lines, $detail),
            genericLabelUsed: InvoiceDocumentNotices::genericLabelUsed($audience, $detail),
            payUrl: $payUrl,
            // Only a client copy of a real invoice says it will be charged, and
            // only when autopay will apply; free of queries for a company
            // with autopay off.
            autopayNotice: $mode === DocumentMode::Invoice && $payUrl !== null
                ? app(AutopayReader::class)->notice($invoice, $company)
                : null,
        );
    }
}
