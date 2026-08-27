<?php

namespace Tests\Feature\Billing;

use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientInvoiceLine;
use App\Models\Workspace;
use App\Services\Billing\OverpaymentCreditService;
use App\Support\Billing\InvoiceLineType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Overpayment credits carry forward and never expire, and a credit must never
 * take an invoice below zero — the excess stays in the pool.
 *
 * The predecessor covered this only through the invoicing service, so these are
 * new. Money here is integer minor units; the ported ledger reasons in whole
 * currency units and converts at its boundary.
 */
final class OverpaymentCreditServiceTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private ClientCompany $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workspace = Workspace::query()->create(['name' => 'Credits', 'slug' => 'credits']);
        $this->company = ClientCompany::query()->create([
            'workspace_id' => $this->workspace->id, 'name' => 'Credit Client', 'slug' => 'credit-client',
        ]);
    }

    public function test_an_overpayment_becomes_available_credit(): void
    {
        // Billed 100.00, paid 150.00.
        $this->invoice('SVC-1', 'paid', 10000, paid: 15000);

        $this->assertSame(50.0, $this->service()->availableCreditForCompany($this->company, 'USD'));
    }

    public function test_underpayment_and_exact_payment_create_no_credit(): void
    {
        $this->invoice('SVC-1', 'partially_paid', 10000, paid: 4000);
        $this->invoice('SVC-2', 'paid', 10000, paid: 10000);

        $this->assertSame(0.0, $this->service()->availableCreditForCompany($this->company, 'USD'));
    }

    public function test_a_draft_receives_a_negative_credit_line(): void
    {
        $this->invoice('SVC-1', 'paid', 10000, paid: 15000);
        $draft = $this->invoice('SVC-2', 'draft', 8000);
        $this->line($draft, 'service', 8000);

        $this->service()->applyCreditsToDraftInvoice($draft);

        $credit = $draft->lines()->where('type', InvoiceLineType::Credit->value)->firstOrFail();
        $this->assertSame(-5000, (int) $credit->total_amount);
        $this->assertSame(3000, (int) $draft->refresh()->total_amount);
    }

    public function test_a_credit_never_takes_an_invoice_below_zero(): void
    {
        $this->invoice('SVC-1', 'paid', 10000, paid: 30000);
        $draft = $this->invoice('SVC-2', 'draft', 5000);
        $this->line($draft, 'service', 5000);

        $this->service()->applyCreditsToDraftInvoice($draft);

        $credit = $draft->lines()->where('type', InvoiceLineType::Credit->value)->firstOrFail();
        // 200.00 of credit against a 50.00 invoice: only 50.00 is applied.
        $this->assertSame(-5000, (int) $credit->total_amount);
        $this->assertSame(0, (int) $draft->refresh()->total_amount);
    }

    public function test_reapplying_replaces_the_previous_credit_line_rather_than_stacking(): void
    {
        $this->invoice('SVC-1', 'paid', 10000, paid: 15000);
        $draft = $this->invoice('SVC-2', 'draft', 8000);
        $this->line($draft, 'service', 8000);

        $this->service()->applyCreditsToDraftInvoice($draft);
        $this->service()->applyCreditsToDraftInvoice($draft);

        $this->assertSame(1, $draft->lines()->where('type', InvoiceLineType::Credit->value)->count());
        $this->assertSame(3000, (int) $draft->refresh()->total_amount);
    }

    public function test_an_issued_invoice_is_left_alone(): void
    {
        $this->invoice('SVC-1', 'paid', 10000, paid: 15000);
        $issued = $this->invoice('SVC-2', 'issued', 8000);
        $this->line($issued, 'service', 8000);

        $this->service()->applyCreditsToDraftInvoice($issued);

        $this->assertSame(0, $issued->lines()->where('type', InvoiceLineType::Credit->value)->count());
    }

    public function test_credit_already_taken_on_an_issued_invoice_is_consumed(): void
    {
        $this->invoice('SVC-1', 'paid', 10000, paid: 15000);
        $issued = $this->invoice('SVC-2', 'issued', 3000);
        $this->line($issued, InvoiceLineType::Credit->value, -3000);

        // 50.00 overpaid, 30.00 already taken, so 20.00 remains.
        $this->assertSame(20.0, $this->service()->availableCreditForCompany($this->company, 'USD'));
    }

    public function test_a_refunded_or_unsettled_payment_is_not_credit(): void
    {
        $invoice = $this->invoice('SVC-1', 'paid', 10000, paid: 15000);
        // The overpayment was refunded, so there is no money to spend.
        $invoice->payments()->first()->forceFill(['refunded_amount' => 5000])->save();

        $this->assertSame(0.0, $this->service()->availableCreditForCompany($this->company, 'USD'));

        $pending = $this->invoice('SVC-2', 'issued', 10000);
        $pending->payments()->create([
            'workspace_id' => $this->workspace->id, 'status' => 'pending', 'amount' => 20000,
            'currency' => 'USD', 'method' => 'ach', 'received_on' => '2026-03-01',
        ]);

        $this->assertSame(0.0, $this->service()->availableCreditForCompany($this->company, 'USD'));
    }

    public function test_credit_never_crosses_currencies(): void
    {
        $usd = $this->invoice('SVC-1', 'paid', 10000, paid: 15000);
        $this->assertSame('USD', $usd->currency);

        $draft = $this->invoice('SVC-2', 'draft', 8000);
        $draft->forceFill(['currency' => 'EUR'])->save();
        $this->line($draft, 'service', 8000);

        $this->service()->applyCreditsToDraftInvoice($draft);

        // A dollar overpayment is not euros and must not be subtracted from one.
        $this->assertSame(0, $draft->lines()->where('type', InvoiceLineType::Credit->value)->count());
        $this->assertSame(0.0, $this->service()->availableCreditForCompany($this->company, 'EUR'));
    }

    private function service(): OverpaymentCreditService
    {
        return app(OverpaymentCreditService::class);
    }

    private function invoice(string $number, string $status, int $total, int $paid = 0): ClientInvoice
    {
        $invoice = ClientInvoice::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'invoice_number' => $number,
            'currency' => 'USD',
            'status' => $status,
        ]);
        $invoice->forceFill([
            'subtotal_amount' => $total,
            'total_amount' => $total,
            'paid_amount' => $paid,
            'balance_amount' => max(0, $total - $paid),
        ])->save();

        if ($paid > 0) {
            $invoice->payments()->create([
                'workspace_id' => $this->workspace->id,
                'status' => 'succeeded',
                'amount' => $paid,
                'currency' => 'USD',
                'method' => 'ach',
                'received_on' => '2026-03-01',
            ]);
        }

        return $invoice;
    }

    private function line(ClientInvoice $invoice, string $type, int $amount): ClientInvoiceLine
    {
        return ClientInvoiceLine::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_invoice_id' => $invoice->id,
            'type' => $type,
            'description' => 'Line',
            'quantity' => '1.0000',
            'unit_amount' => $amount,
            'tax_amount' => 0,
            'total_amount' => $amount,
            'sort_order' => 0,
        ]);
    }
}
