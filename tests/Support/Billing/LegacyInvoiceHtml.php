<?php

namespace Tests\Support\Billing;

use App\Models\ClientInvoice;
use App\Support\Billing\InvoiceDeferredWorkDisclosure;
use App\Support\Billing\InvoiceHoursStatementRows;
use App\Support\Billing\InvoiceLineDetail;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\View as ViewFactory;
use Tests\Feature\Billing\InvoiceDocumentLegacyParityTest;

/**
 * The invoice document exactly as it printed before the `InvoiceDocument` DTO,
 * frozen: the template is `Legacy/invoice.blade.php` (a verbatim copy of the
 * old `invoices.show`) and the data preparation is the old
 * `InvoiceDocumentService::html()`.
 *
 * Kept only so {@see InvoiceDocumentLegacyParityTest}
 * can render the same invoices through the old and the new document and
 * compare every printed figure. Delete it, and that test, once a release has
 * shipped with the new document.
 */
final class LegacyInvoiceHtml
{
    public function __construct()
    {
        ViewFactory::addNamespace('legacy-invoice', __DIR__.'/Legacy');
    }

    /** @param InvoiceLineDetail::OPERATOR|InvoiceLineDetail::CLIENT $audience */
    public function html(ClientInvoice $invoice, string $audience): View
    {
        $lines = $invoice->lines()->where('workspace_id', $invoice->workspace_id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $detail = InvoiceLineDetail::forInvoice($invoice, $audience);
        $statement = $invoice->hoursStatement();

        return ViewFactory::make('legacy-invoice::invoice', [
            'invoice' => $invoice,
            'statusLabel' => self::label((string) $invoice->status),
            'lineTypeLabels' => $lines->mapWithKeys(static fn ($line): array => [$line->public_id => self::label($line->type)])->all(),
            'lines' => $lines,
            'detail' => $detail,
            'audience' => $audience,
            'genericLabelUsed' => $audience === InvoiceLineDetail::CLIENT && collect($detail)
                ->flatten(1)
                ->contains(static fn (array $item): bool => $item['description'] === InvoiceLineDetail::CLIENT_GENERIC_LABEL),
            'statement' => $statement === null ? [] : InvoiceHoursStatementRows::for($statement),
            'statementIntro' => $statement?->isCorrection() === true
                ? 'For the work this correction reconciles and the pool it draws on. All figures are hours.'
                : 'For the work this invoice reconciles and the retainer period that follows it. All figures are hours.',
            'deferredWork' => InvoiceDeferredWorkDisclosure::documentData($invoice, $audience),
        ]);
    }

    private static function label(string $value): string
    {
        $words = trim((string) preg_replace('/[_-]+/', ' ', $value));

        return $words === '' ? '—' : ucfirst($words);
    }
}
