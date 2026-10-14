<?php

namespace Tests\Feature\Square;

use App\Models\InvoiceSquareLink;
use App\Models\SquareInvoiceSnapshot;
use App\Models\Workspace;
use App\Services\AgentApi\AgentInvoiceReviewReadService;
use App\Services\Billing\RecordReceivedPayment;
use App\Services\Square\SquarePaymentMismatchAudit;
use App\Services\Square\SquarePaymentReconciler;
use App\Support\Billing\ReceivedPaymentData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\FakesSquarePaymentSync;
use Tests\TestCase;

final class SquarePaymentMismatchTest extends TestCase
{
    use FakesSquarePaymentSync;
    use RefreshDatabase;

    public function test_outside_payment_flags_the_invoice_and_billing_audit_without_changing_square(): void
    {
        [$workspace, $owner, $company, $invoice] = $this->paymentFixture();
        $this->paymentInvoice['status'] = 'UNPAID';
        $this->squarePayments['pay_fixture_1']['status'] = 'PENDING';
        app(SquarePaymentReconciler::class)->invoice($workspace, 'inv_fixture_1');
        app(RecordReceivedPayment::class)->record($workspace, $invoice, ReceivedPaymentData::from(['amount' => 12_345, 'currency' => 'USD', 'received_on' => '2030-02-01', 'method' => 'cash']));
        $url = 'https://squareupsandbox.com/dashboard/invoices/inv_fixture_1';
        $this->assertSame(['code' => 'paid_outside_square', 'dashboard_url' => $url], app(SquarePaymentMismatchAudit::class)->invoice($workspace, $invoice));
        $audit = app(AgentInvoiceReviewReadService::class)->staleAndMissing($owner, $workspace)->toArray()['square_payment_mismatches'];
        $this->assertSame(1, $audit['count']);
        $this->assertFalse($audit['truncated']);
        $this->assertSame([['invoice_id' => $invoice->public_id, 'url' => route('clients.invoice', [$workspace, $company, $invoice]), 'code' => 'paid_outside_square', 'dashboard_url' => $url]], $audit['invoices']);
        $this->actingAs($owner)->get(route('clients.invoice', [$workspace, $company, $invoice]))->assertOk()->assertInertia(fn (Assert $page) => $page->where('square.payment_mismatch.dashboard_url', $url));
        $this->assertSame('UNPAID', SquareInvoiceSnapshot::query()->where('workspace_id', $workspace->id)->firstOrFail()->status);
        Http::assertNotSent(fn ($request): bool => $request->method() !== 'GET');
    }

    public function test_flags_are_workspace_scoped_and_disappear_when_square_is_closed_or_unlinked(): void
    {
        [$workspace, , , $invoice] = $this->paymentFixture();
        $this->paymentInvoice['status'] = 'UNPAID';
        $this->squarePayments['pay_fixture_1']['status'] = 'PENDING';
        app(SquarePaymentReconciler::class)->invoice($workspace, 'inv_fixture_1');
        app(RecordReceivedPayment::class)->record($workspace, $invoice, ReceivedPaymentData::from(['amount' => 12_345, 'currency' => 'USD', 'received_on' => '2030-02-01', 'method' => 'cash']));
        $service = app(SquarePaymentMismatchAudit::class);
        $other = Workspace::query()->create(['name' => 'Synthetic audit other', 'slug' => 'synthetic-audit-other']);
        $this->assertNull($service->invoice($other, $invoice));
        $this->assertSame(['count' => 0, 'invoices' => [], 'truncated' => false], $service->report($other));
        $snapshot = SquareInvoiceSnapshot::query()->where('workspace_id', $workspace->id)->firstOrFail();
        $snapshot->forceFill(['status' => 'PAID'])->save();
        $this->assertNull($service->invoice($workspace, $invoice));
        $snapshot->forceFill(['status' => 'UNPAID'])->save();
        $this->assertSame(1, $service->report($workspace)['count']);
        InvoiceSquareLink::query()->where('workspace_id', $workspace->id)->update(['active_invoice_slot' => null, 'active_square_invoice_slot' => null]);
        $this->assertSame(0, $service->report($workspace)['count']);
    }
}
