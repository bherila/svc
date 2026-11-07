<?php

namespace App\Services\Billing;

use App\Models\AgentPrincipal;
use App\Models\ClientAttachment;
use App\Models\ClientCompany;
use App\Models\ClientExpense;
use App\Models\ClientInvoice;
use App\Models\ClientInvoiceLine;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\Autopay\AutopayReader;
use App\Services\Files\AttachmentAudience;
use App\Services\Files\AttachmentReader;
use App\Services\Files\AttachmentStorageService;
use App\Support\Billing\Document\DocumentMode;
use App\Support\Billing\Document\InvoiceDocument;
use App\Support\Billing\Document\InvoiceDocumentGrouper;
use App\Support\Billing\Document\InvoiceDocumentLine;
use App\Support\Billing\Document\InvoiceDocumentNotices;
use App\Support\Billing\Document\InvoiceReceiptEntry;
use App\Support\Billing\Document\InvoiceReceiptFile;
use App\Support\Billing\Document\InvoiceReceipts;
use App\Support\Billing\Document\ReceiptDetail;
use App\Support\Billing\Document\ReceiptPlacement;
use App\Support\Billing\InvoiceDeferredWorkDisclosure;
use App\Support\Billing\InvoiceHoursStatementRows;
use App\Support\Billing\InvoiceLineDetail;
use Illuminate\Database\Eloquent\Collection;

/**
 * Builds the {@see InvoiceDocument} for an invoice: the one tenant-scoped read
 * behind the screen, the PDF and the proforma.
 *
 * Every query names the invoice's workspace. The audience is a parameter with
 * no default: the same document is served to operators and to portal clients,
 * and what each may read is decided by {@see InvoiceLineDetail} and
 * {@see InvoiceDeferredWorkDisclosure}, not here. The decisions that are not
 * reads live in {@see InvoiceDocumentNotices} and the grouper, which are pure.
 *
 * Appendix B (receipts) is the one part that depends on the viewer rather than
 * the audience alone, so the caller says how much it may draw with a
 * {@see ReceiptDetail}; the default is none. A client's receipts are also the
 * viewer's own: the attachment reader decides which this viewer may open, so a
 * client audience built without a viewer prints none.
 */
final class InvoiceDocumentBuilder
{
    public function __construct(private readonly AttachmentStorageService $storage) {}

    /** @param InvoiceLineDetail::OPERATOR|InvoiceLineDetail::CLIENT $audience */
    public function build(ClientInvoice $invoice, string $audience, DocumentMode $mode = DocumentMode::Invoice, ReceiptDetail $receipts = ReceiptDetail::None, User|AgentPrincipal|null $viewer = null): InvoiceDocument
    {
        $lineModels = $invoice->lines()->where('workspace_id', $invoice->workspace_id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
        $lines = array_values($lineModels
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
        $issuer = Workspace::query()->whereKey($invoice->workspace_id)->first();

        $payUrl = $company !== null && InvoiceDocumentNotices::offersPayLink(
            $mode,
            (string) $invoice->status,
            (int) $invoice->balance_amount,
            (bool) $invoice->is_visible_to_client,
        ) ? route('portal.invoice', [$company, $invoice], absolute: true) : null;

        return new InvoiceDocument(
            mode: $mode,
            audience: $audience,
            issuerName: (string) $issuer?->name,
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
            receipts: $receipts->drawsAppendix() && $issuer instanceof Workspace && $company instanceof ClientCompany
                ? $this->receipts($invoice, $issuer, $company, $lineModels, $audience, $receipts, $viewer)
                : [],
            payUrl: $payUrl,
            // Only a client copy of a real invoice says it will be charged, and
            // only when autopay will apply; free of queries for a company
            // with autopay off.
            autopayNotice: $mode === DocumentMode::Invoice && $payUrl !== null
                ? app(AutopayReader::class)->notice($invoice, $company)
                : null,
        );
    }

    /**
     * Appendix B: the expenses billed on this invoice and the receipts filed on
     * them that this reader may be told about.
     *
     * Two workspace-scoped queries however many expenses there are: the expenses
     * claimed by this invoice's lines, then every available receipt on them. For
     * a client the second query is the attachment reader's own, so the file flag,
     * the invoice the viewer can see and a project-scoped contact's grants are
     * decided in one place. Empty when no receipt survives the audience policy,
     * so a document with none prints no heading.
     *
     * @param  Collection<int, ClientInvoiceLine>  $lines
     * @param  InvoiceLineDetail::OPERATOR|InvoiceLineDetail::CLIENT  $audience
     * @return list<InvoiceReceiptEntry>
     */
    private function receipts(ClientInvoice $invoice, Workspace $workspace, ClientCompany $company, Collection $lines, string $audience, ReceiptDetail $detail, User|AgentPrincipal|null $viewer): array
    {
        if ($lines->isEmpty()) {
            return [];
        }

        $expenses = ClientExpense::query()
            ->where('workspace_id', $invoice->workspace_id)
            ->where('client_company_id', $invoice->client_company_id)
            ->whereIn('client_invoice_line_id', $lines->modelKeys())
            ->get();
        if ($expenses->isEmpty()) {
            return [];
        }

        $files = $this->receiptFiles($invoice, $workspace, $expenses, $audience, $viewer)
            ->filter(static fn (ClientAttachment $file): bool => InvoiceReceipts::visibleTo($audience, $file->is_visible_to_client))
            ->groupBy('record_public_id');
        if ($files->isEmpty()) {
            return [];
        }

        // Invoice line order first, then the date spent, then the expense's own order.
        $byLine = $expenses->groupBy('client_invoice_line_id');
        $imagesRead = 0;
        $bytesRead = 0;
        $entries = [];
        foreach ($lines as $line) {
            $claimed = $byLine->get($line->id, new Collection)->sortBy([['spent_on', 'asc'], ['id', 'asc']]);
            foreach ($claimed as $expense) {
                $drawn = [];
                foreach ($files->get($expense->public_id, []) as $file) {
                    $drawn[] = $this->file($file, $workspace, $company, $expense, $audience, $detail, $imagesRead, $bytesRead);
                }
                if ($drawn === [] && ! InvoiceReceipts::listsExpenseWithoutFiles($audience)) {
                    continue;
                }
                $entries[] = new InvoiceReceiptEntry(
                    ref: InvoiceReceipts::ref(count($entries) + 1),
                    lineId: (string) $line->public_id,
                    spentOn: $expense->spent_on->toDateString(),
                    description: (string) $expense->description,
                    amount: (int) $expense->amount,
                    files: $drawn,
                );
            }
        }

        return $entries;
    }

    /**
     * The available receipts on these expenses that this audience may be told of,
     * oldest first. The operator reads every one (the caller has established
     * they manage the workspace); a client reads what the attachment reader
     * serves them, and a client with no identity reads nothing.
     *
     * @param  Collection<int, ClientExpense>  $expenses
     * @param  InvoiceLineDetail::OPERATOR|InvoiceLineDetail::CLIENT  $audience
     * @return Collection<int, ClientAttachment>
     */
    private function receiptFiles(ClientInvoice $invoice, Workspace $workspace, Collection $expenses, string $audience, User|AgentPrincipal|null $viewer): Collection
    {
        $reader = app(AttachmentReader::class);
        $files = match ($audience) {
            InvoiceLineDetail::OPERATOR => ClientAttachment::query()
                ->where('workspace_id', $invoice->workspace_id)
                ->where('record_type', 'expense'),
            InvoiceLineDetail::CLIENT => $viewer === null
                ? ClientAttachment::query()->whereRaw('1 = 0')
                : $reader->queryForTypes($viewer, $workspace, $viewer instanceof User ? $reader->nativePortalAudience($viewer, $workspace) : AttachmentAudience::Portal, ['expense']),
        };

        return $files
            ->whereIn('record_public_id', $expenses->pluck('public_id')->all())
            ->where('lifecycle_state', ClientAttachment::STATE_AVAILABLE)
            ->orderBy('id')
            ->get();
    }

    /**
     * One receipt file: its metadata, and, for the PDF, the image itself within
     * the document's budget. The counters are the budget spent so far.
     *
     * @param  InvoiceLineDetail::OPERATOR|InvoiceLineDetail::CLIENT  $audience
     */
    private function file(ClientAttachment $file, Workspace $workspace, ClientCompany $company, ClientExpense $expense, string $audience, ReceiptDetail $detail, int &$imagesRead, int &$bytesRead): InvoiceReceiptFile
    {
        $filename = (string) $file->original_filename;
        $href = InvoiceReceipts::hrefFor(
            $audience,
            route('svc.files.download', ['workspace' => $workspace->public_id, 'clientAttachment' => $file->public_id], absolute: false),
            route('portal.files.receipt.download', ['clientCompany' => $company->public_id, 'recordPublicId' => $expense->public_id, 'attachment' => $file->public_id], absolute: false),
        );
        $placement = InvoiceReceipts::placementFromMetadata($file->media_type, $file->bytes);
        $plain = static fn (ReceiptPlacement $as): InvoiceReceiptFile => new InvoiceReceiptFile($file->public_id, $filename, $file->media_type, $file->bytes, $href, $as);

        if ($placement !== ReceiptPlacement::Embedded || ! $detail->readsFiles()) {
            return $plain($placement);
        }
        if (! InvoiceReceipts::fitsBudget($imagesRead, $bytesRead, $file->bytes)) {
            return $plain(ReceiptPlacement::TooLarge);
        }

        $contents = $this->storage->boundedContents($file, InvoiceReceipts::MAX_IMAGE_BYTES);
        $image = $contents === null ? null : InvoiceReceipts::inspect($contents);
        if ($contents === null || $image === null) {
            return $plain(ReceiptPlacement::Unreadable);
        }
        if (! InvoiceReceipts::withinPixelBudget($image['width'], $image['height'])) {
            return $plain(ReceiptPlacement::TooLarge);
        }

        $imagesRead++;
        $bytesRead += strlen($contents);
        [$width, $height] = InvoiceReceipts::drawnSize($image['width'], $image['height']);

        return new InvoiceReceiptFile($file->public_id, $filename, $file->media_type, $file->bytes, $href, ReceiptPlacement::Embedded, 'data:'.$image['mime'].';base64,'.base64_encode($contents), $width, $height);
    }
}
