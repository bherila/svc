<?php

namespace Tests\Feature\Billing;

use App\Mail\InvoiceMail;
use App\Models\ClientAgreement;
use App\Models\ClientInvoiceEmailDelivery;
use App\Services\Billing\ClientInvoicingService;
use App\Services\Billing\InvoiceEmailService;
use App\Services\Billing\InvoiceLifecycleService;
use App\Support\Billing\InvoiceEmailDraft;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsSyntheticExpenses;
use Tests\TestCase;

final class ReissuedInvoiceAutomaticDeliveryTest extends TestCase
{
    use BuildsSyntheticExpenses;
    use RefreshDatabase;

    /** @return iterable<string, array{string}> */
    public static function originalDeliveryOrigins(): iterable
    {
        yield 'manual original delivery' => ['manual'];
        yield 'automatic original delivery' => ['automatic'];
    }

    #[DataProvider('originalDeliveryOrigins')]
    public function test_a_successful_original_delivery_does_not_suppress_the_reissue(string $origin): void
    {
        Mail::fake();
        $this->travelTo(Carbon::parse('2026-03-02 12:00:00'));
        $workspace = $this->syntheticWorkspace('Reissued delivery');
        $company = $this->syntheticCompany($workspace, 'Reissued delivery');
        $company->forceFill([
            'billing_email' => 'billing@synthetic.example.test',
            'automatic_invoice_email_enabled' => true,
            'automatic_invoice_email_delay_days' => 0,
        ])->save();
        $agreement = ClientAgreement::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'title' => 'Synthetic retainer',
            'status' => 'active',
            'currency' => 'USD',
            'starts_on' => '2026-01-01',
            'retainer_minutes' => 600,
            'retainer_amount' => 150000,
            'catch_up_threshold_minutes' => 60,
            'hourly_rate_amount' => 15000,
            'billing_cadence' => 'monthly',
            'rollover_months' => 1,
        ]);
        $lifecycle = app(InvoiceLifecycleService::class);
        $email = app(InvoiceEmailService::class);
        $invoice = $lifecycle->issue(
            app(ClientInvoicingService::class)->generateInvoice($company, Carbon::parse('2026-01-01'), Carbon::parse('2026-01-31'), $agreement),
            $workspace,
        );
        $original = $origin === 'automatic'
            ? $email->sendAutomatic($invoice)
            : $email->send($invoice, InvoiceEmailDraft::of(['billing@synthetic.example.test'], [], $email->defaultSubject($invoice), null), $workspace);
        $this->assertInstanceOf(ClientInvoiceEmailDelivery::class, $original);
        $this->assertSame('sent', $original->status);
        $this->assertSame(1, $original->invoice_revision);
        Mail::assertSent(InvoiceMail::class, 1);
        $this->assertNull($email->sendAutomatic($invoice->fresh()), 'A successful delivery still suppresses another send of the same revision.');
        $this->assertSame($origin === 'manual' ? 'manually_sent' : 'automatically_sent', $invoice->fresh()?->automatic_delivery_status);
        Mail::assertSent(InvoiceMail::class, 1);

        $reissued = $lifecycle->issue(
            $lifecycle->reopen($invoice->fresh(), 'Refresh approved work', $workspace),
            $workspace,
        );
        $this->assertSame(2, $reissued->document_revision);
        $this->assertSame('scheduled', $reissued->automatic_delivery_status);

        $foreignWorkspace = $this->syntheticWorkspace('Unrelated delivery');
        $foreignHandle = clone $reissued;
        $foreignHandle->workspace_id = $foreignWorkspace->id;
        $this->assertNull($email->sendAutomatic($foreignHandle));
        $this->assertSame('scheduled', $reissued->fresh()?->automatic_delivery_status);
        Mail::assertSent(InvoiceMail::class, 1);

        $delivery = $email->sendAutomatic($reissued);

        $this->assertInstanceOf(ClientInvoiceEmailDelivery::class, $delivery, 'A prior revision was delivered, but this reissue still needs to reach the client.');
        $this->assertSame('sent', $delivery->status);
        $this->assertSame(2, $delivery->invoice_revision);
        $this->assertSame('automatic', $delivery->origin);
        $this->assertTrue($delivery->is_reissue);
        $this->assertSame(1, $delivery->attempt_number);
        $this->assertSame('Invoice '.$invoice->invoice_number.' (reissued)', $delivery->subject);
        $this->assertSame('automatically_sent', $reissued->fresh()?->automatic_delivery_status);
        $this->assertSame('sent', $original->fresh()?->status);
        $this->assertSame(1, $original->fresh()?->invoice_revision);
        Mail::assertSent(InvoiceMail::class, 2);
        $this->assertNull($email->sendAutomatic($reissued->fresh()));
        Mail::assertSent(InvoiceMail::class, 2);
    }
}
