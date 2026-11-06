<?php

namespace Tests\Unit\Billing;

use App\Providers\AppServiceProvider;
use App\Services\Billing\Pdf\DompdfInvoicePdfRenderer;
use App\Services\Billing\Pdf\InvoicePdfRenderer;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;
use Tests\TestCase;

#[CoversClass(AppServiceProvider::class)]
final class InvoicePdfRendererBindingTest extends TestCase
{
    private function resolve(string $renderer): InvoicePdfRenderer
    {
        $previous = Container::getInstance();
        try {
            $application = new Application($this->app->basePath());
            $application->instance('config', new Repository(['svc' => ['invoice_pdf_renderer' => $renderer]]));
            (new AppServiceProvider($application))->register();

            return $application->make(InvoicePdfRenderer::class);
        } finally {
            Container::setInstance($previous);
        }
    }

    public function test_the_configured_renderer_is_dompdf(): void
    {
        $this->assertInstanceOf(DompdfInvoicePdfRenderer::class, $this->resolve('dompdf'));
    }

    public function test_an_unknown_renderer_is_refused_by_name(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unknown invoice PDF renderer "latex".');

        $this->resolve('latex');
    }
}
