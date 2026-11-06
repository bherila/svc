<?php

namespace Tests\Feature\Billing;

use App\Services\Billing\InvoiceDocumentBuilder;
use App\Services\Billing\InvoiceDocumentService;
use App\Services\Billing\Pdf\DompdfInvoicePdfRenderer;
use App\Services\Billing\Pdf\InvoicePdfRenderer;
use App\Support\Billing\Document\DocumentMode;
use App\Support\Billing\InvoiceLineDetail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Concerns\BuildsGoldenInvoices;
use Tests\TestCase;

class InvoicePdfRendererTest extends TestCase
{
    use BuildsGoldenInvoices;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpGoldenTenant();
    }

    public function test_dompdf_is_the_configured_renderer_and_returns_bytes_and_a_page_count(): void
    {
        $this->assertInstanceOf(DompdfInvoicePdfRenderer::class, app(InvoicePdfRenderer::class));
        $invoice = $this->goldenInvoice(['hours_statement' => $this->goldenStatement(['ordinaryHours' => 2.0, 'ordinaryAppliedToWorkPool' => 2.0])->toArray()]);
        $this->goldenLine($invoice);
        $document = app(InvoiceDocumentBuilder::class)->build($invoice, InvoiceLineDetail::CLIENT);

        $rendered = app(InvoicePdfRenderer::class)->render($document);

        $this->assertStringStartsWith('%PDF-', $rendered->bytes);
        $this->assertSame(2, $rendered->pageCount, 'The cover and the hours statement.');
    }

    public function test_the_pdf_embeds_inter_and_stays_on_us_letter(): void
    {
        $invoice = $this->goldenInvoice();
        $this->goldenLine($invoice);

        $bytes = app(InvoiceDocumentService::class)->pdf($invoice, InvoiceLineDetail::CLIENT);

        $this->assertStringContainsString('Inter-Regular', $bytes);
        $this->assertMatchesRegularExpression('#/MediaBox\s*\[\s*0(\.0+)?\s+0(\.0+)?\s+612(\.0+)?\s+792(\.0+)?\s*]#', $bytes);
    }

    public function test_the_template_uses_no_flexbox_or_grid(): void
    {
        $invoice = $this->goldenInvoice();
        $this->goldenLine($invoice);

        $html = app(InvoiceDocumentService::class)->html($invoice, InvoiceLineDetail::OPERATOR)->render();

        foreach (['display: flex', 'display:flex', 'inline-flex', 'display: grid', 'display:grid', 'margin-top: auto', 'rotate('] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $html);
        }
        $this->assertStringContainsString('background: #ffffff', $html, 'The PDF is always on white paper.');
        $this->assertStringContainsString('page-break-inside: avoid', $html);
    }

    public function test_a_proforma_is_stamped_in_the_html_and_named_apart_from_the_invoice(): void
    {
        $invoice = $this->goldenInvoice(['invoice_number' => 'INV-SYN-PRO-1']);
        $this->goldenLine($invoice);
        $service = app(InvoiceDocumentService::class);

        $proforma = $service->html($invoice, InvoiceLineDetail::CLIENT, DocumentMode::Proforma)->render();
        $issued = $service->html($invoice, InvoiceLineDetail::CLIENT)->render();

        $this->assertStringContainsString('PROFORMA · NOT AN INVOICE', $proforma);
        $this->assertStringNotContainsString('PROFORMA', $issued);
        $this->assertSame('invoice-inv-syn-pro-1.pdf', $service->filename($invoice));
        $this->assertSame('proforma-inv-syn-pro-1.pdf', $service->filename($invoice, DocumentMode::Proforma));
    }

    public function test_an_unknown_renderer_in_configuration_is_refused(): void
    {
        config(['svc.invoice_pdf_renderer' => 'latex']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unknown invoice PDF renderer "latex"');

        app()->make(InvoicePdfRenderer::class);
    }
}
