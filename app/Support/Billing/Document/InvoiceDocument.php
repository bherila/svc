<?php

declare(strict_types=1);

namespace App\Support\Billing\Document;

use App\Services\Billing\InvoiceDocumentBuilder;
use App\Support\Billing\InvoiceLineDetail;

/**
 * One invoice document for the screen, the PDF and the proforma.
 *
 * Immutable, and built by {@see InvoiceDocumentBuilder}
 * from one tenant-scoped read, parameterised by audience. Nothing here is
 * recomputed from the ledger: the hours statement and the still-deferred list
 * are the snapshots stored at issue, and every amount is a stored minor-unit
 * figure. The Blade view prints it, and `toArray()` is the JSON the screen
 * draws, so the two cannot disagree.
 *
 * @phpstan-type StatementSection array{title: string, rows: list<array{label: string, hours: string, kind: string}>}
 * @phpstan-type DeferredWork array{entry_count: int, total_hours: float, oldest_worked_on: string, entries: list<array{worked_on: string, hours: float, description: string|null}>}
 * @phpstan-type AppendixBlock array{line_id: string, description: string, minutes: int, entries: list<array{worked_on: string, project: string|null, description: string, minutes: int}>}
 */
final readonly class InvoiceDocument
{
    /**
     * @param  InvoiceLineDetail::OPERATOR|InvoiceLineDetail::CLIENT  $audience
     * @param  list<InvoiceDocumentSectionBlock>  $sections
     * @param  list<InvoiceDocumentLine>  $lines  The same lines in invoice order, for the appendix.
     * @param  list<StatementSection>  $statement
     * @param  DeferredWork|null  $deferredWork
     * @param  list<AppendixBlock>  $appendix
     * @param  list<InvoiceReceiptEntry>  $receipts  Appendix B; empty when the reader may be told of no receipt.
     */
    public function __construct(
        public DocumentMode $mode,
        public string $audience,
        public string $issuerName,
        public ?string $invoiceNumber,
        public string $status,
        public string $currency,
        public string $billTo,
        public ?string $issueDate,
        public ?string $dueDate,
        public ?string $servicePeriodStart,
        public ?string $servicePeriodEnd,
        public ?string $reissuedOn,
        public ?string $voidedOn,
        public array $sections,
        public array $lines,
        public int $subtotal,
        public int $tax,
        public int $total,
        public int $paid,
        public int $balance,
        public array $statement,
        public string $statementIntro,
        public ?array $deferredWork,
        public array $appendix,
        public bool $genericLabelUsed,
        public array $receipts,
        public ?string $payUrl,
        /** "Will be charged to Visa ending 4242 on 2026-12-01." when autopay will apply (#636). */
        public ?string $autopayNotice = null,
    ) {}

    public function statusLabel(): string
    {
        return StoredValueLabel::of($this->status);
    }

    public function banner(): string
    {
        return InvoiceDocumentNotices::audienceBanner($this->audience);
    }

    public function reissueNotice(): ?string
    {
        return InvoiceDocumentNotices::reissueNotice($this->reissuedOn);
    }

    public function voidNotice(): ?string
    {
        return InvoiceDocumentNotices::voidNotice($this->voidedOn, $this->status);
    }

    /** How many receipt files Appendix B lists. */
    public function receiptCount(): int
    {
        return array_sum(array_map(static fn (InvoiceReceiptEntry $entry): int => count($entry->files), $this->receipts));
    }

    /** The running header and the filename's number, with a stand-in for a draft with none. */
    public function identity(): string
    {
        return $this->invoiceNumber ?? '—';
    }

    /**
     * The JSON the screen draws. Contains nothing the Blade view does not print.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'mode' => $this->mode->value,
            'heading' => $this->mode->heading(),
            'stamp' => $this->mode->stamp(),
            'audience' => $this->audience,
            'banner' => $this->banner(),
            'issuer_name' => $this->issuerName,
            'invoice_number' => $this->invoiceNumber,
            'status' => $this->status,
            'currency' => $this->currency,
            'bill_to' => $this->billTo,
            'issue_date' => $this->issueDate,
            'due_date' => $this->dueDate,
            'service_period_start' => $this->servicePeriodStart,
            'service_period_end' => $this->servicePeriodEnd,
            'reissue_notice' => $this->reissueNotice(),
            'void_notice' => $this->voidNotice(),
            'sections' => array_map(static fn (InvoiceDocumentSectionBlock $block): array => $block->toArray(), $this->sections),
            'totals' => [
                'subtotal' => $this->subtotal,
                'tax' => $this->tax,
                'total' => $this->total,
                'paid' => $this->paid,
                'balance' => $this->balance,
            ],
            'statement' => $this->statement === [] ? null : [
                'intro' => $this->statementIntro,
                'sections' => $this->statement,
            ],
            'deferred_work' => $this->deferredWork,
            'appendix' => $this->appendix === [] ? null : [
                'generic_label_used' => $this->genericLabelUsed,
                'generic_label' => InvoiceLineDetail::CLIENT_GENERIC_LABEL,
                'blocks' => $this->appendix,
            ],
            'receipts' => $this->receipts === [] ? null : [
                'intro' => InvoiceDocumentNotices::receiptsIntro($this->audience),
                'receipt_count' => $this->receiptCount(),
                'entries' => array_map(static fn (InvoiceReceiptEntry $entry): array => $entry->toArray(), $this->receipts),
            ],
            'pay_url' => $this->payUrl,
            'autopay_notice' => $this->autopayNotice,
        ];
    }
}
