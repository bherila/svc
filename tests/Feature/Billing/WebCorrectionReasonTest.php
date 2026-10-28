<?php

namespace Tests\Feature\Billing;

use App\Models\ClientCompany;
use App\Models\ClientCompanyActivity;
use App\Models\ClientInvoice;
use App\Models\ClientInvoicePayment;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\InvoiceLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\CallsInvoiceApi;
use Tests\TestCase;

/**
 * The invoice screen asks why, as the API does (#454 owner decision): voiding
 * an invoice and correcting a payment both require a reason, kept on the record.
 */
final class WebCorrectionReasonTest extends TestCase
{
    use CallsInvoiceApi;
    use RefreshDatabase;

    public function test_voiding_on_the_screen_requires_a_reason_and_keeps_it(): void
    {
        [$owner, $workspace, $invoice] = $this->issuedInvoice();

        foreach ([[], ['reason' => '   '], ['reason' => str_repeat('r', ClientInvoice::VOID_REASON_MAX_LENGTH + 1)]] as $body) {
            $this->invoiceApi($owner, $workspace, $invoice, 'void', $body)->assertUnprocessable()->assertJsonValidationErrors('reason');
            $this->assertSame('issued', $invoice->fresh()?->status);
        }

        $this->invoiceApi($owner, $workspace, $invoice, 'void', ['reason' => ' Issued to the wrong synthetic client '])->assertOk();
        $fresh = $invoice->fresh();
        $this->assertSame('void', $fresh?->status);
        $this->assertSame('Issued to the wrong synthetic client', $fresh?->void_reason);
    }

    public function test_correcting_a_payment_date_on_the_screen_requires_a_reason_and_records_it(): void
    {
        [$owner, $workspace, $invoice] = $this->issuedInvoice();
        $service = app(InvoiceLifecycleService::class);
        $payment = $service->applyPayment($invoice, ['amount' => 1000, 'currency' => 'USD', 'method' => 'wire', 'received_on' => now()->toDateString()], $workspace);
        $day = now()->subDays(3)->toDateString();

        $this->correctPaymentApi($owner, $workspace, $payment, ['received_on' => $day])->assertUnprocessable()->assertJsonValidationErrors('body.reason');
        $this->assertSame(now()->toDateString(), $payment->fresh()?->received_on?->toDateString());

        $this->correctPaymentApi($owner, $workspace, $payment, ['received_on' => $day, 'reason' => 'Synthetic bank statement'])->assertOk();
        $this->assertSame($day, ClientInvoicePayment::query()->where('workspace_id', $workspace->id)->sole()->received_on?->toDateString());

        // The same audited correction the API and the console command record.
        $activity = ClientCompanyActivity::query()->where('workspace_id', $workspace->id)->where('action', 'invoice.payment_corrected')->sole();
        $this->assertSame('Synthetic bank statement', $activity->payload['reason'] ?? null);
        $this->assertSame(['old' => now()->toDateString(), 'new' => $day], $activity->payload['changes']['received_on'] ?? null);
    }

    /** @return array{User, Workspace, ClientInvoice} */
    private function issuedInvoice(): array
    {
        $owner = User::factory()->create(['email' => 'synthetic-'.Str::lower(Str::random(6)).'@example.test']);
        $workspace = Workspace::query()->create(['name' => 'Synthetic reasons', 'slug' => 'synthetic-reasons-'.Str::lower(Str::random(8))]);
        $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic client', 'slug' => 'synthetic-client']);
        $service = app(InvoiceLifecycleService::class);
        $invoice = $service->issue($service->createDraft($workspace, $company, ['currency' => 'USD', 'invoice_number' => 'INV-SYN-'.Str::upper(Str::random(8))], [
            ['type' => 'adjustment', 'description' => 'Synthetic service', 'quantity' => 1, 'unit_amount' => 10000],
        ]), $workspace);

        return [$owner, $workspace, $invoice];
    }
}
