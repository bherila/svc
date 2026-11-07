<?php

namespace Tests\Unit\Billing\Document;

use App\Support\Billing\Document\DocumentMode;
use App\Support\Billing\Document\InvoiceDocument;
use App\Support\Billing\Document\InvoiceDocumentGrouper;
use App\Support\Billing\Document\InvoiceDocumentLine;
use App\Support\Billing\Document\InvoiceDocumentNotices;
use App\Support\Billing\Document\InvoiceReceiptEntry;
use App\Support\Billing\Document\InvoiceReceiptFile;
use App\Support\Billing\Document\ReceiptPlacement;
use App\Support\Billing\InvoiceLineDetail;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(InvoiceDocumentNotices::class)]
#[CoversClass(InvoiceDocument::class)]
#[CoversClass(InvoiceDocumentLine::class)]
final class InvoiceDocumentNoticesTest extends TestCase
{
    public function test_only_the_operator_copy_has_the_banner(): void
    {
        $this->assertSame('Administrator copy: includes internal descriptions', InvoiceDocumentNotices::audienceBanner(InvoiceLineDetail::OPERATOR));
        $this->assertSame('', InvoiceDocumentNotices::audienceBanner(InvoiceLineDetail::CLIENT));
    }

    public function test_the_statement_intro_follows_whether_it_is_a_correction(): void
    {
        $this->assertSame('For the work this correction reconciles and the pool it draws on. All figures are hours.', InvoiceDocumentNotices::statementIntro(true));
        $this->assertSame('For the work this invoice reconciles and the retainer period that follows it. All figures are hours.', InvoiceDocumentNotices::statementIntro(false));
    }

    public function test_the_reissue_notice(): void
    {
        $this->assertNull(InvoiceDocumentNotices::reissueNotice(null));
        $this->assertSame('Reissued 2026-08-20 under the same invoice number; this replaces the earlier version.', InvoiceDocumentNotices::reissueNotice('2026-08-20'));
    }

    public function test_the_void_notice_is_only_for_a_void_invoice(): void
    {
        $this->assertNull(InvoiceDocumentNotices::voidNotice('2026-08-20', 'issued'));
        $this->assertNull(InvoiceDocumentNotices::voidNotice(null, 'paid'));
        $this->assertSame('This invoice was voided on 2026-08-20. Nothing is owed on it.', InvoiceDocumentNotices::voidNotice('2026-08-20', 'void'));
        $this->assertSame('This invoice was voided. Nothing is owed on it.', InvoiceDocumentNotices::voidNotice(null, 'void'));
    }

    public function test_the_generic_label_is_explained_only_to_a_client_who_was_shown_it(): void
    {
        $generic = ['l1' => [['description' => InvoiceLineDetail::CLIENT_GENERIC_LABEL]]];
        $written = ['l1' => [['description' => 'Reviewed the roadmap']]];

        $this->assertTrue(InvoiceDocumentNotices::genericLabelUsed(InvoiceLineDetail::CLIENT, $generic));
        $this->assertTrue(InvoiceDocumentNotices::genericLabelUsed(InvoiceLineDetail::CLIENT, ['a' => [['description' => 'x']], 'b' => [['description' => 'y'], ['description' => InvoiceLineDetail::CLIENT_GENERIC_LABEL]]]));
        $this->assertFalse(InvoiceDocumentNotices::genericLabelUsed(InvoiceLineDetail::CLIENT, $written));
        $this->assertFalse(InvoiceDocumentNotices::genericLabelUsed(InvoiceLineDetail::CLIENT, []));
        $this->assertFalse(InvoiceDocumentNotices::genericLabelUsed(InvoiceLineDetail::OPERATOR, $generic));
    }

    /** @return iterable<string, array{DocumentMode, string, int, bool, bool}> */
    public static function payLinks(): iterable
    {
        yield 'issued with a balance' => [DocumentMode::Invoice, 'issued', 100, true, true];
        yield 'partially paid' => [DocumentMode::Invoice, 'partially_paid', 1, true, true];
        yield 'paid' => [DocumentMode::Invoice, 'paid', 0, true, false];
        yield 'no balance' => [DocumentMode::Invoice, 'issued', 0, true, false];
        yield 'negative balance' => [DocumentMode::Invoice, 'issued', -5, true, false];
        yield 'disputed' => [DocumentMode::Invoice, 'disputed', 100, true, false];
        yield 'void' => [DocumentMode::Invoice, 'void', 100, true, false];
        yield 'draft' => [DocumentMode::Invoice, 'draft', 100, true, false];
        yield 'hidden from the client' => [DocumentMode::Invoice, 'issued', 100, false, false];
        yield 'proforma' => [DocumentMode::Proforma, 'issued', 100, true, false];
    }

    #[DataProvider('payLinks')]
    public function test_a_pay_link_is_offered_only_where_money_can_be_collected(DocumentMode $mode, string $status, int $balance, bool $visible, bool $expected): void
    {
        $this->assertSame($expected, InvoiceDocumentNotices::offersPayLink($mode, $status, $balance, $visible));
    }

    public function test_the_appendix_follows_line_order_and_skips_lines_with_no_work(): void
    {
        $lines = [$this->line('a', 'First'), $this->line('b', 'Second'), $this->line('c', 'Third')];
        $entry = static fn (string $day, int $minutes): array => ['worked_on' => $day, 'project' => null, 'description' => 'w', 'minutes' => $minutes];

        $appendix = InvoiceDocumentNotices::appendix($lines, [
            'c' => [$entry('2026-08-02', 30)],
            'a' => [$entry('2026-08-01', 90), $entry('2026-08-03', 60)],
        ]);

        $this->assertSame(['a', 'c'], array_column($appendix, 'line_id'));
        $this->assertSame(['First', 'Third'], array_column($appendix, 'description'));
        $this->assertSame([150, 30], array_column($appendix, 'minutes'));
        $this->assertCount(2, $appendix[0]['entries']);
        $this->assertSame([], InvoiceDocumentNotices::appendix($lines, []));
    }

    public function test_the_document_serialises_for_the_screen(): void
    {
        $document = $this->document(statement: [], appendix: [], deferred: null, payUrl: null);
        $array = $document->toArray();

        $this->assertNull($array['statement']);
        $this->assertNull($array['appendix']);
        $this->assertNull($array['deferred_work']);
        $this->assertNull($array['pay_url']);
        $this->assertNull($array['stamp']);
        $this->assertSame('Invoice', $array['heading']);
        $this->assertSame('Administrator copy: includes internal descriptions', $array['banner']);
        $this->assertNull($array['reissue_notice']);
        $this->assertNull($array['void_notice']);
        $this->assertSame(['subtotal' => 1000, 'tax' => 80, 'total' => 1080, 'paid' => 80, 'balance' => 1000], $array['totals']);
        $this->assertSame([], $array['sections']);
        $this->assertSame('INV-SYN-1', $document->identity());
        $this->assertSame('Partially paid', $document->statusLabel());

        $full = $this->document(
            statement: [['title' => 'Opening pool', 'rows' => [['label' => 'x', 'hours' => '1.00', 'kind' => 'row']]]],
            appendix: [['line_id' => 'a', 'description' => 'd', 'minutes' => 30, 'entries' => []]],
            deferred: ['entry_count' => 1, 'total_hours' => 1.5, 'oldest_worked_on' => '2026-06-01', 'entries' => []],
            payUrl: 'https://svc.example.test/portal/x',
            mode: DocumentMode::Proforma,
            number: null,
            audience: InvoiceLineDetail::CLIENT,
        )->toArray();

        $this->assertSame('PROFORMA · NOT AN INVOICE', $full['stamp']);
        $this->assertSame('Proforma invoice', $full['heading']);
        $this->assertSame('', $full['banner']);
        $this->assertSame('Opening pool', $full['statement']['sections'][0]['title']);
        $this->assertSame('For the work this invoice reconciles and the retainer period that follows it. All figures are hours.', $full['statement']['intro']);
        $this->assertSame(InvoiceLineDetail::CLIENT_GENERIC_LABEL, $full['appendix']['generic_label']);
        $this->assertFalse($full['appendix']['generic_label_used']);
        $this->assertSame('https://svc.example.test/portal/x', $full['pay_url']);
        $this->assertSame(1.5, $full['deferred_work']['total_hours']);
        $this->assertNull($full['invoice_number']);
        $this->assertSame('—', $this->document(number: null)->identity());
    }

    public function test_the_whole_serialised_document_is_exactly_what_the_screen_draws(): void
    {
        $line = new InvoiceDocumentLine('a', 'retainer', 'Retainer', '1.0000', null, null, 100, 0, 100);
        $document = new InvoiceDocument(
            mode: DocumentMode::Invoice, audience: InvoiceLineDetail::CLIENT, issuerName: 'Synthetic workspace', invoiceNumber: 'INV-SYN-1',
            status: 'void', currency: 'USD', billTo: 'Synthetic Client', issueDate: '2026-08-15', dueDate: '2026-09-14',
            servicePeriodStart: '2026-08-01', servicePeriodEnd: '2026-08-31', reissuedOn: '2026-08-18', voidedOn: '2026-08-20',
            sections: InvoiceDocumentGrouper::group([$line]), lines: [$line], subtotal: 100, tax: 0, total: 100, paid: 0, balance: 0,
            statement: [], statementIntro: 'intro', deferredWork: null,
            appendix: [['line_id' => 'a', 'description' => 'd', 'minutes' => 30, 'entries' => []]], genericLabelUsed: true, receipts: [], payUrl: null,
        );

        $this->assertSame('', $document->banner());
        $this->assertSame('Reissued 2026-08-18 under the same invoice number; this replaces the earlier version.', $document->reissueNotice());
        $this->assertSame('This invoice was voided on 2026-08-20. Nothing is owed on it.', $document->voidNotice());
        $this->assertSame('Retainer', $line->typeLabel());
        $this->assertSame([
            'mode' => 'invoice', 'heading' => 'Invoice', 'stamp' => null, 'audience' => 'client', 'banner' => '',
            'issuer_name' => 'Synthetic workspace', 'invoice_number' => 'INV-SYN-1', 'status' => 'void', 'currency' => 'USD',
            'bill_to' => 'Synthetic Client', 'issue_date' => '2026-08-15', 'due_date' => '2026-09-14',
            'service_period_start' => '2026-08-01', 'service_period_end' => '2026-08-31',
            'reissue_notice' => 'Reissued 2026-08-18 under the same invoice number; this replaces the earlier version.',
            'void_notice' => 'This invoice was voided on 2026-08-20. Nothing is owed on it.',
            'sections' => [[
                'key' => 'time', 'title' => 'Time', 'subtotal' => 100, 'tax' => 0,
                'lines' => [$line->toArray()],
            ]],
            'totals' => ['subtotal' => 100, 'tax' => 0, 'total' => 100, 'paid' => 0, 'balance' => 0],
            'statement' => null,
            'deferred_work' => null,
            'appendix' => [
                'generic_label_used' => true, 'generic_label' => 'Professional services',
                'blocks' => [['line_id' => 'a', 'description' => 'd', 'minutes' => 30, 'entries' => []]],
            ],
            'receipts' => null,
            'pay_url' => null,
            'autopay_notice' => null,
        ], $document->toArray());
    }

    public function test_a_document_with_receipts_serialises_them_with_their_count_and_intro(): void
    {
        $file = new InvoiceReceiptFile('f1', 'a.png', 'image/png', 10, '/x/f1', ReceiptPlacement::Embedded);
        $other = new InvoiceReceiptFile('f2', 'b.pdf', 'application/pdf', 20, null, ReceiptPlacement::ListedOnly);
        $entries = [
            new InvoiceReceiptEntry('B1', 'l1', '2026-08-01', 'One', 100, [$file, $other]),
            new InvoiceReceiptEntry('B2', 'l2', '2026-08-02', 'Two', 200, []),
        ];
        $document = new InvoiceDocument(
            mode: DocumentMode::Invoice, audience: InvoiceLineDetail::OPERATOR, issuerName: 'Synthetic workspace', invoiceNumber: 'INV-SYN-1',
            status: 'issued', currency: 'USD', billTo: 'Synthetic Client', issueDate: null, dueDate: null,
            servicePeriodStart: null, servicePeriodEnd: null, reissuedOn: null, voidedOn: null,
            sections: [], lines: [], subtotal: 0, tax: 0, total: 0, paid: 0, balance: 0,
            statement: [], statementIntro: '', deferredWork: null, appendix: [], genericLabelUsed: false, receipts: $entries, payUrl: null,
        );

        $this->assertSame(2, $document->receiptCount());
        $this->assertSame([
            'intro' => InvoiceDocumentNotices::receiptsIntro(InvoiceLineDetail::OPERATOR),
            'receipt_count' => 2,
            'entries' => [$entries[0]->toArray(), $entries[1]->toArray()],
        ], $document->toArray()['receipts']);
    }

    private function line(string $id, string $description): InvoiceDocumentLine
    {
        return new InvoiceDocumentLine($id, 'retainer', $description, '1.0000', null, null, 100, 0, 100);
    }

    /**
     * @param  list<array<string, mixed>>  $statement
     * @param  list<array<string, mixed>>  $appendix
     * @param  array<string, mixed>|null  $deferred
     */
    private function document(array $statement = [], array $appendix = [], ?array $deferred = null, ?string $payUrl = null, DocumentMode $mode = DocumentMode::Invoice, ?string $number = 'INV-SYN-1', string $audience = InvoiceLineDetail::OPERATOR): InvoiceDocument
    {
        return new InvoiceDocument(
            mode: $mode, audience: $audience, issuerName: 'Synthetic workspace', invoiceNumber: $number, status: 'partially_paid',
            currency: 'USD', billTo: 'Synthetic Client', issueDate: '2026-08-15', dueDate: '2026-09-14',
            servicePeriodStart: '2026-08-01', servicePeriodEnd: '2026-08-31', reissuedOn: null, voidedOn: null,
            sections: [], lines: [], subtotal: 1000, tax: 80, total: 1080, paid: 80, balance: 1000,
            statement: $statement, statementIntro: InvoiceDocumentNotices::statementIntro(false),
            deferredWork: $deferred, appendix: $appendix, genericLabelUsed: false, receipts: [], payUrl: $payUrl,
        );
    }
}
