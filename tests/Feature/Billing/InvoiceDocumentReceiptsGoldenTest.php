<?php

namespace Tests\Feature\Billing;

use App\Models\ClientAttachment;
use App\Models\ClientCompany;
use App\Models\ClientCompanyMembership;
use App\Models\ClientExpense;
use App\Models\ClientInvoice;
use App\Models\ClientInvoiceLine;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\InvoiceDocumentBuilder;
use App\Services\Billing\InvoiceDocumentService;
use App\Services\Billing\Pdf\InvoicePdfRenderer;
use App\Support\Billing\Document\DocumentMode;
use App\Support\Billing\Document\InvoiceReceipts;
use App\Support\Billing\Document\ReceiptDetail;
use App\Support\Billing\InvoiceLineDetail;
use Dompdf\Frame;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsGoldenInvoices;
use Tests\Concerns\BuildsReceiptInvoices;
use Tests\Support\Billing\BladeInvoiceDocumentFacts;
use Tests\Support\Billing\InvoiceDocumentFacts;
use Tests\TestCase;

/**
 * Golden tests for Appendix B (receipts), for both audiences, plus the budget,
 * isolation and query bounds that keep it safe to render in a request.
 *
 * The facts are read from the same HTML the PDF is laid out from
 * ({@see InvoiceDocumentFacts}), so a rebuilt renderer is held to the same
 * statements by overriding {@see self::documentFacts()}.
 */
class InvoiceDocumentReceiptsGoldenTest extends TestCase
{
    use BuildsGoldenInvoices;
    use BuildsReceiptInvoices;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpGoldenTenant();
        $this->fakeReceiptDisk();
    }

    private function portalClient(): User
    {
        $client = User::factory()->create();
        ClientCompanyMembership::query()->create([
            'workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id, 'user_id' => $client->id,
            'role' => 'client', 'access_scope' => ClientCompanyMembership::SCOPE_COMPANY,
        ]);

        return $client;
    }

    protected function documentFacts(): InvoiceDocumentFacts
    {
        return new BladeInvoiceDocumentFacts(app(InvoiceDocumentService::class), withReceipts: true);
    }

    /** An issued invoice with two expenses, an image and a PDF on the first and an image on the second, and a third with none. */
    private function invoiceWithReceipts(): ClientInvoice
    {
        $invoice = $this->goldenInvoice();
        [$staging] = $this->goldenExpense($invoice, 'Synthetic staging database', 41200, '2026-08-12', sort: 10);
        [$api] = $this->goldenExpense($invoice, 'Synthetic lab-results API usage', 9900, '2026-08-20', sort: 11);
        $this->goldenExpense($invoice, 'Synthetic parking', 1500, '2026-08-25', sort: 12);
        $this->goldenReceipt($staging, 'staging-august.png', 'image/png', $this->pngBytes());
        $this->goldenReceipt($staging, 'staging-august.pdf', 'application/pdf', '%PDF-1.4 synthetic receipt');
        $this->goldenReceipt($api, 'api-usage.jpg', 'image/jpeg', $this->jpegBytes());

        return $invoice;
    }

    public function test_the_operator_copy_lists_every_expense_with_its_receipts_and_draws_the_images(): void
    {
        $invoice = $this->invoiceWithReceipts();

        $facts = $this->documentFacts()->extract($invoice, InvoiceLineDetail::OPERATOR);

        $this->assertSame('Appendix B: receipts', $facts['receipts']['heading'] ?? null);
        $this->assertSame(
            'The expenses billed on this invoice, with the receipt filed for each. An expense with no receipt on file is listed without one.',
            $facts['receipts']['intro'] ?? null,
        );
        $this->assertSame([
            [
                'ref' => 'B1', 'date' => '2026-08-12', 'description' => 'Synthetic staging database', 'amount' => '412.00',
                'files' => [
                    ['name' => 'staging-august.png', 'note' => null],
                    ['name' => 'staging-august.pdf', 'note' => 'Not drawn in the PDF; open the file from the invoice online.'],
                ],
                'images' => 1,
            ],
            [
                'ref' => 'B2', 'date' => '2026-08-20', 'description' => 'Synthetic lab-results API usage', 'amount' => '99.00',
                'files' => [['name' => 'api-usage.jpg', 'note' => null]],
                'images' => 1,
            ],
            [
                'ref' => 'B3', 'date' => '2026-08-25', 'description' => 'Synthetic parking', 'amount' => '15.00',
                'files' => [],
                'images' => 0,
            ],
        ], $facts['receipts']['entries'] ?? null);
        // Appendix A is a different block and is not displaced by B.
        $this->assertNull($facts['appendix']);
    }

    public function test_the_client_copy_has_no_appendix_b_unless_it_is_built_for_a_viewer_and_a_receipt_is_shared(): void
    {
        $invoice = $this->invoiceWithReceipts();

        // Built for no one in particular (the emailed and stored copies): nothing.
        $facts = $this->documentFacts()->extract($invoice, InvoiceLineDetail::CLIENT);
        $html = app(InvoiceDocumentService::class)->html($invoice, InvoiceLineDetail::CLIENT, receipts: ReceiptDetail::Embedded)->render();
        $json = json_encode(app(InvoiceDocumentService::class)->document($invoice, InvoiceLineDetail::CLIENT, receipts: ReceiptDetail::Listed)->toArray(), JSON_THROW_ON_ERROR);

        $this->assertNull($facts['receipts']);
        foreach (['Appendix B: receipts', 'staging-august', 'api-usage', '<img', 'data:image'] as $leak) {
            $this->assertStringNotContainsString($leak, $html);
        }
        foreach (['staging-august', 'api-usage', 'attachments/'] as $leak) {
            $this->assertStringNotContainsString($leak, $json);
        }
        // The expense lines themselves are on the invoice the client pays.
        $this->assertStringContainsString('Synthetic staging database', $html);
    }

    public function test_the_client_copy_lists_only_the_shared_receipts_of_the_expenses_that_have_one(): void
    {
        $invoice = $this->goldenInvoice();
        [$staging] = $this->goldenExpense($invoice, 'Synthetic staging database', 41200, '2026-08-12', sort: 10);
        [$api] = $this->goldenExpense($invoice, 'Synthetic lab-results API usage', 9900, '2026-08-20', sort: 11);
        $this->goldenExpense($invoice, 'Synthetic parking', 1500, '2026-08-25', sort: 12);
        [$privateOnly] = $this->goldenExpense($invoice, 'Synthetic private-only expense', 700, '2026-08-26', sort: 13);
        $this->goldenReceipt($staging, 'shared.png', 'image/png', $this->pngBytes());
        $this->goldenReceipt($staging, 'unshared.png', 'image/png', $this->pngBytes(), visibleToClient: false);
        $this->goldenReceipt($api, 'api-usage.pdf', 'application/pdf', '%PDF-1.4 a');
        $this->goldenReceipt($privateOnly, 'secret.png', 'image/png', $this->pngBytes(), visibleToClient: false);
        $client = $this->portalClient();
        $service = app(InvoiceDocumentService::class);

        $array = $service->document($invoice, InvoiceLineDetail::CLIENT, receipts: ReceiptDetail::Listed, viewer: $client)->toArray();
        $html = $service->html($invoice, InvoiceLineDetail::CLIENT, receipts: ReceiptDetail::Embedded, viewer: $client)->render();
        // The expenses are lines on the invoice the client pays; only Appendix B is in question.
        $json = json_encode($array['receipts'], JSON_THROW_ON_ERROR);
        $appendix = substr($html, (int) strpos($html, 'Appendix B: receipts'));

        $this->assertSame(2, $array['receipts']['receipt_count']);
        $this->assertSame(['B1', 'B2'], array_column($array['receipts']['entries'], 'ref'));
        $this->assertSame(['Synthetic staging database', 'Synthetic lab-results API usage'], array_column($array['receipts']['entries'], 'description'));
        $this->assertSame(['shared.png'], array_column($array['receipts']['entries'][0]['files'], 'filename'));
        $this->assertSame('The expenses billed on this invoice that have a receipt shared with you, with that receipt.', $array['receipts']['intro']);
        $this->assertMatchesRegularExpression('#^/portal/[0-9a-f-]{36}/expenses/[0-9a-f-]{36}/receipts/[0-9a-f-]{36}$#', (string) $array['receipts']['entries'][0]['files'][0]['href']);
        // Nothing says that anything was withheld.
        foreach (['unshared', 'secret', 'private-only', 'parking', 'withheld', 'No receipt on file', '/workspaces/'] as $absent) {
            $this->assertStringNotContainsString($absent, $json);
            $this->assertStringNotContainsString($absent, $appendix);
        }
        $this->assertStringContainsString('Appendix B: receipts', $html);
        $this->assertSame(1, substr_count($html, '<img'), 'Only the shared image is drawn.');
    }

    public function test_the_screen_document_carries_addresses_for_an_operator_and_no_image_bytes(): void
    {
        $invoice = $this->invoiceWithReceipts();

        $array = app(InvoiceDocumentService::class)->document($invoice, InvoiceLineDetail::OPERATOR, receipts: ReceiptDetail::Listed)->toArray();
        $json = json_encode($array, JSON_THROW_ON_ERROR);

        $this->assertSame(3, $array['receipts']['receipt_count']);
        $this->assertSame(['B1', 'B2', 'B3'], array_column($array['receipts']['entries'], 'ref'));
        $first = $array['receipts']['entries'][0]['files'][0];
        $this->assertSame('staging-august.png', $first['filename']);
        $this->assertMatchesRegularExpression('#^/workspaces/[0-9a-f-]{36}/attachments/[0-9a-f-]{36}$#', (string) $first['href']);
        $this->assertSame($first['href'], route('svc.files.download', [$this->workspace, $first['id']], absolute: false));
        $this->assertSame([], $array['receipts']['entries'][2]['files']);
        $this->assertStringNotContainsString('base64', $json);
        $this->assertStringNotContainsString('object_key', $json);
        $this->assertStringNotContainsString('sha256', $json);
        // The screen opens nothing: the same document with the disk gone still lists.
        Storage::fake('svc_files');
        $again = app(InvoiceDocumentService::class)->document($invoice, InvoiceLineDetail::OPERATOR, receipts: ReceiptDetail::Listed)->toArray();
        $this->assertSame(3, $again['receipts']['receipt_count']);
    }

    public function test_no_receipts_means_no_page_and_no_empty_heading(): void
    {
        $withExpenseOnly = $this->goldenInvoice();
        $this->goldenExpense($withExpenseOnly, 'Synthetic hosting', 1000, '2026-08-12');
        $plain = $this->goldenInvoice();
        $this->goldenLine($plain);

        foreach ([$withExpenseOnly, $plain] as $invoice) {
            $this->assertNull($this->documentFacts()->extract($invoice, InvoiceLineDetail::OPERATOR)['receipts']);
            $this->assertNull(app(InvoiceDocumentService::class)->document($invoice, InvoiceLineDetail::OPERATOR, receipts: ReceiptDetail::Listed)->toArray()['receipts']);
            $this->assertStringNotContainsString('Appendix B: receipts', app(InvoiceDocumentService::class)->html($invoice, InvoiceLineDetail::OPERATOR, receipts: ReceiptDetail::Embedded)->render());
        }
    }

    public function test_a_caller_that_asks_for_nothing_gets_nothing(): void
    {
        $invoice = $this->invoiceWithReceipts();

        $document = app(InvoiceDocumentService::class)->document($invoice, InvoiceLineDetail::OPERATOR);

        $this->assertSame([], $document->receipts);
        $this->assertNull($document->toArray()['receipts']);
    }

    #[DataProvider('states')]
    public function test_drafts_and_voids_print_appendix_b_like_appendix_a_and_a_proforma_asks_for_none(string $status): void
    {
        $invoice = $this->goldenInvoice(['status' => $status, 'invoice_number' => '']);
        [$expense, $line] = $this->goldenExpense($invoice, 'Synthetic hosting', 1000, '2026-08-12');
        $this->goldenReceipt($expense, 'hosting.png', 'image/png', $this->pngBytes());
        $this->goldenAttach($line, $this->goldenEntry('2026-08-03', 60));
        $service = app(InvoiceDocumentService::class);

        $facts = $this->documentFacts()->extract($invoice, InvoiceLineDetail::OPERATOR);
        $proforma = $service->document($invoice, InvoiceLineDetail::CLIENT, DocumentMode::Proforma);

        $this->assertNotNull($facts['appendix'], 'Appendix A prints in this state.');
        $this->assertSame(['B1'], array_column($facts['receipts']['entries'] ?? [], 'ref'), 'So does Appendix B.');
        $this->assertSame([], $proforma->receipts);
    }

    /** @return iterable<string, array{string}> */
    public static function states(): iterable
    {
        yield 'draft' => ['draft'];
        yield 'issued' => ['issued'];
        yield 'void' => ['void'];
    }

    public function test_receipts_are_listed_in_invoice_line_order_with_references_in_that_order(): void
    {
        $invoice = $this->goldenInvoice();
        [$late, $lateLine] = $this->goldenExpense($invoice, 'Synthetic later line, earlier date', 100, '2026-08-01', sort: 20);
        [$early] = $this->goldenExpense($invoice, 'Synthetic earlier line, later date', 200, '2026-08-30', sort: 5);
        $this->goldenReceipt($late, 'a.pdf', 'application/pdf', '%PDF-1.4 a');
        $this->goldenReceipt($early, 'b.pdf', 'application/pdf', '%PDF-1.4 b');

        $entries = app(InvoiceDocumentService::class)->document($invoice, InvoiceLineDetail::OPERATOR, receipts: ReceiptDetail::Listed)->toArray()['receipts']['entries'];

        $this->assertSame(['Synthetic earlier line, later date', 'Synthetic later line, earlier date'], array_column($entries, 'description'));
        $this->assertSame($lateLine->public_id, $entries[1]['line_id']);
    }

    public function test_receipts_of_another_workspace_company_invoice_or_state_never_appear(): void
    {
        $invoice = $this->goldenInvoice();
        [$own] = $this->goldenExpense($invoice, 'Synthetic own expense', 1000, '2026-08-12');
        $this->goldenReceipt($own, 'own.pdf', 'application/pdf', '%PDF-1.4 own');

        // Another workspace with its own invoice, expense and receipt.
        $other = Workspace::query()->create(['name' => 'Synthetic other workspace', 'slug' => 'synthetic-other']);
        $otherCompany = ClientCompany::query()->create(['workspace_id' => $other->id, 'name' => 'Synthetic Other Client', 'slug' => 'synthetic-other-client']);
        $otherInvoice = ClientInvoice::query()->create([
            'workspace_id' => $other->id, 'client_company_id' => $otherCompany->id, 'invoice_number' => 'INV-SYN-OTHER-1', 'status' => 'issued',
            'currency' => 'USD', 'subtotal_amount' => 0, 'tax_amount' => 0, 'total_amount' => 0, 'paid_amount' => 0, 'balance_amount' => 0,
        ]);
        $otherLine = ClientInvoiceLine::query()->create([
            'workspace_id' => $other->id, 'client_invoice_id' => $otherInvoice->id, 'type' => 'expense', 'description' => 'Synthetic foreign expense',
            'quantity' => '1', 'unit_amount' => 500, 'tax_amount' => 0, 'total_amount' => 500, 'sort_order' => 0,
        ]);
        $foreign = ClientExpense::query()->create([
            'workspace_id' => $other->id, 'client_company_id' => $otherCompany->id, 'spent_on' => '2026-08-01', 'amount' => 500, 'currency' => 'USD',
            'description' => 'Synthetic foreign expense', 'status' => 'invoiced', 'client_invoice_line_id' => $otherLine->id,
        ]);
        $foreignFile = $this->goldenReceipt($foreign, 'foreign.pdf', 'application/pdf', '%PDF-1.4 foreign');

        // A forged row: another workspace's file naming this workspace's expense.
        $forged = $this->goldenReceipt($own, 'forged.pdf', 'application/pdf', '%PDF-1.4 forged');
        DB::table('client_attachments')->where('id', $forged->id)->update(['workspace_id' => $other->id]);

        // Same workspace, but not this invoice's expense, not available, or not an expense receipt.
        $otherInvoiceSameWorkspace = $this->goldenInvoice();
        [$sibling] = $this->goldenExpense($otherInvoiceSameWorkspace, 'Synthetic sibling invoice expense', 700, '2026-08-02');
        $this->goldenReceipt($sibling, 'sibling.pdf', 'application/pdf', '%PDF-1.4 sibling');
        $unclaimed = ClientExpense::query()->create([
            'workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id, 'spent_on' => '2026-08-03', 'amount' => 800,
            'currency' => 'USD', 'description' => 'Synthetic unclaimed expense', 'status' => 'approved',
        ]);
        $this->goldenReceipt($unclaimed, 'unclaimed.pdf', 'application/pdf', '%PDF-1.4 unclaimed');
        foreach ([ClientAttachment::STATE_STAGED, ClientAttachment::STATE_DELETING, ClientAttachment::STATE_DELETED, ClientAttachment::STATE_CORRUPT] as $state) {
            $this->goldenReceipt($own, $state.'.pdf', 'application/pdf', '%PDF-1.4 '.$state, ['lifecycle_state' => $state]);
        }

        $document = app(InvoiceDocumentService::class)->document($invoice, InvoiceLineDetail::OPERATOR, receipts: ReceiptDetail::Listed)->toArray();
        $json = json_encode($document, JSON_THROW_ON_ERROR);

        $this->assertSame(['own.pdf'], array_column($document['receipts']['entries'][0]['files'], 'filename'));
        $this->assertSame(1, $document['receipts']['receipt_count']);
        foreach (['foreign', 'forged', 'sibling', 'unclaimed', 'staged', 'deleting', 'deleted', 'corrupt', $foreignFile->public_id] as $absent) {
            $this->assertStringNotContainsString($absent, $json);
        }

        // And the other workspace's own invoice shows only its own.
        $theirs = app(InvoiceDocumentService::class)->document($otherInvoice->fresh() ?? $otherInvoice, InvoiceLineDetail::OPERATOR, receipts: ReceiptDetail::Listed)->toArray();
        $this->assertSame(['foreign.pdf'], array_column($theirs['receipts']['entries'][0]['files'], 'filename'), 'The forged row names an expense that is not theirs.');
    }

    public function test_the_queries_behind_appendix_b_do_not_grow_with_the_number_of_expenses(): void
    {
        $few = $this->goldenInvoice();
        [$one] = $this->goldenExpense($few, 'Synthetic one', 100, '2026-08-01');
        $this->goldenReceipt($one, 'one.pdf', 'application/pdf', '%PDF-1.4 one');
        $many = $this->goldenInvoice();
        foreach (range(1, 12) as $number) {
            [$expense] = $this->goldenExpense($many, 'Synthetic expense '.$number, 100 * $number, '2026-08-'.sprintf('%02d', $number), sort: 10 + $number);
            $this->goldenReceipt($expense, "r{$number}.png", 'image/png', $this->pngBytes());
            $this->goldenReceipt($expense, "r{$number}.pdf", 'application/pdf', '%PDF-1.4 r');
        }

        $queries = static function (ClientInvoice $invoice, ReceiptDetail $detail): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            app(InvoiceDocumentBuilder::class)->build($invoice, InvoiceLineDetail::OPERATOR, receipts: $detail);
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };

        $base = $queries($few, ReceiptDetail::None);
        $this->assertSame($base + 2, $queries($few, ReceiptDetail::Listed), 'Expenses, then their receipts.');
        $this->assertSame($base + 2, $queries($many, ReceiptDetail::Listed), 'Twelve expenses and twenty-four files cost the same.');
        $this->assertSame($base + 2, $queries($many, ReceiptDetail::Embedded), 'Reading the files is disk work, not queries.');
    }

    public function test_an_oversized_a_mislabelled_a_missing_and_a_corrupt_receipt_are_listed_not_drawn(): void
    {
        $invoice = $this->goldenInvoice();
        [$expense] = $this->goldenExpense($invoice, 'Synthetic mixed bag', 5000, '2026-08-12');
        $this->goldenReceipt($expense, 'huge.png', 'image/png', 'x', ['bytes' => InvoiceReceipts::MAX_IMAGE_BYTES + 1]);
        $this->goldenReceipt($expense, 'actually-a-gif.png', 'image/png', $this->gifBytes());
        $this->goldenReceipt($expense, 'missing.png', 'image/png', $this->pngBytes(), store: false);
        $this->goldenReceipt($expense, 'tampered.png', 'image/png', $this->pngBytes(), ['sha256' => str_repeat('0', 64)]);
        $this->goldenReceipt($expense, 'not-an-image.png', 'image/png', 'definitely not a png');
        $this->goldenReceipt($expense, 'wide.png', 'image/png', $this->pngBytes(3001, 2000));
        $this->goldenReceipt($expense, 'fine.png', 'image/png', $this->pngBytes());

        $files = $this->documentFacts()->extract($invoice, InvoiceLineDetail::OPERATOR)['receipts']['entries'][0]['files'] ?? [];

        $this->assertSame([
            ['name' => 'huge.png', 'note' => 'Too large to draw in the PDF; open the file from the invoice online.'],
            ['name' => 'actually-a-gif.png', 'note' => 'Could not be drawn in the PDF; open the file from the invoice online.'],
            ['name' => 'missing.png', 'note' => 'Could not be drawn in the PDF; open the file from the invoice online.'],
            ['name' => 'tampered.png', 'note' => 'Could not be drawn in the PDF; open the file from the invoice online.'],
            ['name' => 'not-an-image.png', 'note' => 'Could not be drawn in the PDF; open the file from the invoice online.'],
            ['name' => 'wide.png', 'note' => 'Too large to draw in the PDF; open the file from the invoice online.'],
            ['name' => 'fine.png', 'note' => null],
        ], $files);
        $facts = $this->documentFacts()->extract($invoice, InvoiceLineDetail::OPERATOR);
        $this->assertSame(1, $facts['receipts']['entries'][0]['images'] ?? null, 'Only the sound image is drawn.');
    }

    public function test_the_document_stops_drawing_images_at_its_count_and_byte_budget(): void
    {
        $invoice = $this->goldenInvoice();
        [$expense] = $this->goldenExpense($invoice, 'Synthetic many photos', 5000, '2026-08-12');
        foreach (range(1, InvoiceReceipts::MAX_IMAGES + 2) as $number) {
            $this->goldenReceipt($expense, "photo-{$number}.png", 'image/png', $this->pngBytes(20 + $number, 20));
        }

        $facts = $this->documentFacts()->extract($invoice, InvoiceLineDetail::OPERATOR);
        $files = $facts['receipts']['entries'][0]['files'] ?? [];

        $this->assertCount(InvoiceReceipts::MAX_IMAGES + 2, $files);
        $this->assertSame(InvoiceReceipts::MAX_IMAGES, $facts['receipts']['entries'][0]['images'] ?? null);
        $this->assertNull($files[InvoiceReceipts::MAX_IMAGES - 1]['note']);
        $this->assertSame('Too large to draw in the PDF; open the file from the invoice online.', $files[InvoiceReceipts::MAX_IMAGES]['note']);
    }

    public function test_the_document_stops_drawing_images_at_its_byte_budget(): void
    {
        $invoice = $this->goldenInvoice();
        [$expense] = $this->goldenExpense($invoice, 'Synthetic heavy photos', 5000, '2026-08-12');
        $heavy = $this->uncompressedPngBytes(700, 700);
        $this->assertGreaterThan(InvoiceReceipts::MAX_TOTAL_IMAGE_BYTES / 5, strlen($heavy));
        $this->assertLessThan(InvoiceReceipts::MAX_IMAGE_BYTES, strlen($heavy));
        foreach (range(1, 5) as $number) {
            $this->goldenReceipt($expense, "heavy-{$number}.png", 'image/png', $heavy);
        }

        $document = app(InvoiceDocumentBuilder::class)->build($invoice, InvoiceLineDetail::OPERATOR, receipts: ReceiptDetail::Embedded);

        $this->assertSame(
            ['embedded', 'embedded', 'embedded', 'embedded', 'too_large'],
            array_map(static fn ($file): string => $file->placement->value, $document->receipts[0]->files),
        );
    }

    public function test_the_pdf_draws_the_images_on_an_appendix_page_of_its_own(): void
    {
        $invoice = $this->invoiceWithReceipts();
        $service = app(InvoiceDocumentService::class);
        $renderer = app(InvoicePdfRenderer::class);

        $without = $renderer->render($service->document($invoice, InvoiceLineDetail::OPERATOR));
        $with = $renderer->render($service->document($invoice, InvoiceLineDetail::OPERATOR, receipts: ReceiptDetail::Embedded));
        $clientCopy = $renderer->render($service->document($invoice, InvoiceLineDetail::CLIENT, receipts: ReceiptDetail::Embedded));

        $this->assertSame(1, $without->pageCount);
        $this->assertSame(2, $with->pageCount, 'The cover, then Appendix B.');
        $this->assertSame(1, $clientCopy->pageCount);
        $this->assertSame(0, substr_count($without->bytes, '/Subtype /Image'));
        $this->assertSame(2, substr_count($with->bytes, '/Subtype /Image'));
        $this->assertStringStartsWith('%PDF-', $with->bytes);
    }

    public function test_the_template_still_uses_no_flexbox_or_grid_with_receipts(): void
    {
        $html = app(InvoiceDocumentService::class)->html($this->invoiceWithReceipts(), InvoiceLineDetail::OPERATOR, receipts: ReceiptDetail::Embedded)->render();

        foreach (['display: flex', 'display:flex', 'inline-flex', 'display: grid', 'display:grid', 'margin-top: auto', 'rotate('] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $html);
        }
        $this->assertStringContainsString('<img src="data:image/png;base64,', $html);
        $this->assertStringContainsString('width="60" height="40"', $html);
    }

    /**
     * A vendor name and a filename with no break in them wrap inside their own
     * cells; nothing is painted past the right margin of the page.
     */
    public function test_an_unbroken_vendor_name_and_filename_stay_inside_the_page_margin(): void
    {
        $invoice = $this->goldenInvoice();
        [$expense] = $this->goldenExpense($invoice, str_repeat('Vendorname', 20), 41200, '2026-08-12');
        $this->goldenReceipt($expense, str_repeat('receipt-file', 20).'.png', 'image/png', $this->pngBytes(2400, 800));
        $this->goldenReceipt($expense, str_repeat('scan', 60).'.pdf', 'application/pdf', '%PDF-1.4 x');
        $rightMargin = InvoiceDocumentService::US_LETTER_POINTS[2] - 0.75 * 72;

        $painted = 0;
        $overflowing = [];
        app(InvoiceDocumentService::class)->rendered($invoice, InvoiceLineDetail::OPERATOR, function (Frame $frame) use (&$painted, &$overflowing, $rightMargin): void {
            $painted++;
            [$x, , $width] = $frame->get_border_box();
            if ((float) $x + (float) $width > $rightMargin + 0.5) {
                $overflowing[] = $frame->get_node()->nodeName.': '.mb_substr((string) $frame->get_node()->textContent, 0, 40);
            }
        }, ReceiptDetail::Embedded);

        $this->assertGreaterThan(0, $painted, 'The inspector saw the document being painted');
        $this->assertSame([], $overflowing);
    }

    private function uncompressedPngBytes(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        $this->assertNotFalse($image);
        ob_start();
        imagepng($image, null, 0);

        return (string) ob_get_clean();
    }

    private function gifBytes(): string
    {
        $image = imagecreatetruecolor(10, 10);
        $this->assertNotFalse($image);
        ob_start();
        imagegif($image);

        return (string) ob_get_clean();
    }
}
