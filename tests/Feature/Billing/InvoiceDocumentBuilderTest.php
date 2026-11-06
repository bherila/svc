<?php

namespace Tests\Feature\Billing;

use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientInvoiceLine;
use App\Models\Workspace;
use App\Services\Billing\InvoiceDocumentBuilder;
use App\Support\Billing\Document\DocumentMode;
use App\Support\Billing\InvoiceLineDetail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsGoldenInvoices;
use Tests\TestCase;

/**
 * The tenant-scoped read behind the invoice document, and what the audiences
 * may and may not carry out of it.
 */
class InvoiceDocumentBuilderTest extends TestCase
{
    use BuildsGoldenInvoices;
    use RefreshDatabase;

    private const INTERNAL_NOTE = 'Synthetic internal note: renegotiate the rate';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpGoldenTenant();
    }

    private function builder(): InvoiceDocumentBuilder
    {
        return app(InvoiceDocumentBuilder::class);
    }

    public function test_the_client_document_never_carries_a_workspace_internal_field(): void
    {
        $invoice = $this->goldenInvoice(['notes' => 'Synthetic invoice-level private note', 'void_reason' => 'Synthetic private void reason']);
        $line = $this->goldenLine($invoice, ['type' => 'additional_hours', 'description' => 'Additional hours']);
        $this->goldenAttach($line, $this->goldenEntry('2026-08-03', 60, ['description' => self::INTERNAL_NOTE]));

        $client = json_encode($this->builder()->build($invoice, InvoiceLineDetail::CLIENT)->toArray(), JSON_THROW_ON_ERROR);
        $operator = json_encode($this->builder()->build($invoice, InvoiceLineDetail::OPERATOR)->toArray(), JSON_THROW_ON_ERROR);

        $this->assertStringContainsString(InvoiceLineDetail::CLIENT_GENERIC_LABEL, $client);
        foreach ([self::INTERNAL_NOTE, 'private note', 'private void reason', 'void_reason', 'workspace_id', 'client_agreement_id'] as $secret) {
            $this->assertStringNotContainsString($secret, $client);
        }
        $this->assertStringContainsString(self::INTERNAL_NOTE, $operator);
        $this->assertStringNotContainsString('private note', $operator);
        $this->assertStringNotContainsString('private void reason', $operator);
    }

    public function test_lines_and_work_of_another_workspace_never_reach_the_document(): void
    {
        $invoice = $this->goldenInvoice();
        $this->goldenLine($invoice, ['description' => 'Synthetic own line']);

        // The schema refuses a line naming another workspace's invoice (composite
        // tenant keys), so the isolation to prove is the read's: another
        // workspace's invoice, lines and company are never drawn into this one.
        $other = Workspace::query()->create(['name' => 'Synthetic other workspace', 'slug' => 'synthetic-other']);
        $otherCompany = ClientCompany::query()->create(['workspace_id' => $other->id, 'name' => 'Synthetic Other Client', 'slug' => 'synthetic-other-client']);
        $otherInvoice = ClientInvoice::query()->create([
            'workspace_id' => $other->id, 'client_company_id' => $otherCompany->id, 'invoice_number' => 'INV-SYN-OTHER-1', 'status' => 'issued',
            'currency' => 'USD', 'subtotal_amount' => 0, 'tax_amount' => 0, 'total_amount' => 0, 'paid_amount' => 0, 'balance_amount' => 0,
        ]);
        ClientInvoiceLine::query()->create([
            'workspace_id' => $other->id, 'client_invoice_id' => $otherInvoice->id, 'type' => 'retainer', 'description' => 'Synthetic foreign line',
            'quantity' => '1', 'unit_amount' => 1, 'tax_amount' => 0, 'total_amount' => 1, 'sort_order' => 0,
        ]);

        $document = $this->builder()->build($invoice, InvoiceLineDetail::OPERATOR);

        $this->assertSame(['Synthetic own line'], array_map(static fn ($line): string => $line->description, $document->lines));
        $this->assertSame($this->company->name, $document->billTo);
        $this->assertSame($this->workspace->name, $document->issuerName);
        $this->assertStringNotContainsString('foreign', json_encode($document->toArray(), JSON_THROW_ON_ERROR));
    }

    public function test_lines_are_grouped_into_sections_with_figures_and_dates(): void
    {
        $invoice = $this->goldenInvoice(['subtotal_amount' => 1000, 'tax_amount' => 80, 'total_amount' => 1080, 'paid_amount' => 80, 'balance_amount' => 1000]);
        $this->goldenLine($invoice, ['type' => 'expense', 'description' => 'Synthetic staging database', 'sort_order' => 1, 'line_date' => '2026-08-31', 'hours' => null]);
        $this->goldenLine($invoice, ['type' => 'retainer', 'description' => 'Synthetic retainer', 'sort_order' => 2]);
        $this->goldenLine($invoice, ['type' => 'additional_hours', 'description' => 'Synthetic hours', 'sort_order' => 3, 'quantity' => '2.5', 'hours' => '2.5']);

        $document = $this->builder()->build($invoice, InvoiceLineDetail::CLIENT);
        $array = $document->toArray();

        $this->assertSame(['time', 'expenses'], array_column($array['sections'], 'key'));
        $this->assertSame(['Synthetic retainer', 'Synthetic hours'], array_column($array['sections'][0]['lines'], 'description'));
        $this->assertSame(2.5, $array['sections'][0]['lines'][1]['hours']);
        $this->assertNull($array['sections'][0]['lines'][0]['hours']);
        $this->assertSame('2026-08-31', $array['sections'][1]['lines'][0]['line_date']);
        $this->assertSame(['subtotal' => 1000, 'tax' => 80, 'total' => 1080, 'paid' => 80, 'balance' => 1000], $array['totals']);
        $this->assertSame('2026-08-15', $array['issue_date']);
        $this->assertSame('2026-09-14', $array['due_date']);
        $this->assertSame('2026-08-01', $array['service_period_start']);
        $this->assertSame('2026-08-31', $array['service_period_end']);
    }

    public function test_a_void_invoice_says_so_with_its_date(): void
    {
        $invoice = $this->goldenInvoice(['status' => 'void', 'voided_at' => '2026-08-20 10:00:00']);

        $array = $this->builder()->build($invoice, InvoiceLineDetail::CLIENT)->toArray();

        $this->assertSame('void', $array['status']);
        $this->assertSame('This invoice was voided on 2026-08-20. Nothing is owed on it.', $array['void_notice']);
        $this->assertNull($array['pay_url']);
    }

    public function test_the_pay_link_is_a_finished_portal_address_only_where_money_can_be_collected(): void
    {
        $owing = $this->goldenInvoice(['subtotal_amount' => 1000, 'total_amount' => 1000, 'balance_amount' => 1000]);
        $hidden = $this->goldenInvoice(['subtotal_amount' => 1000, 'total_amount' => 1000, 'balance_amount' => 1000, 'is_visible_to_client' => false]);
        $paid = $this->goldenInvoice(['status' => 'paid', 'subtotal_amount' => 1000, 'total_amount' => 1000, 'paid_amount' => 1000, 'balance_amount' => 0]);

        $url = $this->builder()->build($owing, InvoiceLineDetail::CLIENT)->payUrl;

        $this->assertSame(route('portal.invoice', [$this->company, $owing], absolute: true), $url);
        $this->assertStringStartsWith('http', (string) $url);
        $this->assertNull($this->builder()->build($hidden, InvoiceLineDetail::CLIENT)->payUrl);
        $this->assertNull($this->builder()->build($paid, InvoiceLineDetail::CLIENT)->payUrl);
        $this->assertNull($this->builder()->build($owing, InvoiceLineDetail::CLIENT, DocumentMode::Proforma)->payUrl);
        $proforma = $this->builder()->build($owing, InvoiceLineDetail::CLIENT, DocumentMode::Proforma)->toArray();
        $this->assertSame('proforma', $proforma['mode']);
        $this->assertNull($proforma['invoice_number'], 'A proforma has no number.');
    }

    public function test_the_builder_reads_a_bounded_number_of_rows_however_many_lines_there_are(): void
    {
        $invoice = $this->goldenInvoice();
        foreach (range(1, 12) as $i) {
            $line = $this->goldenLine($invoice, ['type' => 'additional_hours', 'description' => 'Synthetic line '.$i, 'sort_order' => $i]);
            $this->goldenAttach($line, $this->goldenEntry('2026-08-'.sprintf('%02d', $i), 30));
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->builder()->build($invoice, InvoiceLineDetail::CLIENT);
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(8, $count, 'The document is built from a handful of queries, not one per line.');
        $this->assertGreaterThan(0, ClientInvoiceLine::query()->where('client_invoice_id', $invoice->id)->count());
        $this->assertInstanceOf(ClientInvoice::class, $invoice);
    }
}
