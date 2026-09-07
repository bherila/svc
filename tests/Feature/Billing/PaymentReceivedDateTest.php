<?php

namespace Tests\Feature\Billing;

use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\InvoiceLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The date a payment arrived on, at every door that writes it.
 *
 * `received_on` is what the finance window reconciles by, and nothing about it
 * reaches the invoice's status or balance - so a wrong date is silent here and
 * loud a month later, in a reconciliation that puts a cheque in the wrong
 * period with nothing disagreeing. These are the refusals that make the column
 * mean what it says.
 */
final class PaymentReceivedDateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        if (! Route::has('svc.billing.invoices.payments.store')) {
            require base_path('routes/billing.php');
        }
    }

    /**
     * The write door and the read door agree about what a date looks like.
     *
     * `date` is `strtotime()` with a thin wrapper, and it accepted every
     * spelling that function understands - `01/02/2026`, `2026-8-15`,
     * `20260815`, a whole ISO 8601 instant - while `InvoicePaymentController`
     * filters the same column by `date_format:Y-m-d` and could never ask for
     * any of them back. `01/02/2026` is the case worth naming: it is the second
     * of January to the parser and the first of February to half the people who
     * would type it, and either reading is stored without comment.
     */
    public function test_the_write_door_refuses_a_date_the_read_door_could_not_ask_for(): void
    {
        [$owner, $workspace, $invoice] = $this->issuedInvoice();

        foreach (['01/02/2026', '2026-8-15', '15 August 2026', '2026-08-15T00:00:00Z'] as $spelling) {
            $this->actingAs($owner)
                ->postJson("/workspaces/{$workspace->public_id}/invoices/{$invoice->public_id}/payments", [
                    'amount' => 1000,
                    'currency' => 'USD',
                    'method' => 'wire',
                    'received_on' => $spelling,
                ])
                ->assertStatus(422)
                ->assertJsonValidationErrors('received_on');
        }

        $this->assertDatabaseCount('client_invoice_payments', 0);
    }

    /** @return array{0:User,1:Workspace,2:ClientInvoice} */
    private function issuedInvoice(string $name = 'Payment Date Workspace'): array
    {
        $owner = User::factory()->create(['email' => 'payment-date-'.str()->random(6).'@synthetic.test']);
        $workspace = Workspace::query()->create(['name' => $name, 'slug' => str($name)->slug().'-'.str()->random(5)]);
        $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
        $company = ClientCompany::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Synthetic Client',
            'slug' => 'synthetic-client-'.$workspace->id,
        ]);

        $service = app(InvoiceLifecycleService::class);
        $invoice = $service->createDraft($workspace, $company, [
            'invoice_number' => 'INV-DATE-'.str()->upper(str()->random(8)),
            'currency' => 'USD',
        ], [[
            'type' => 'service',
            'description' => 'Synthetic service',
            'quantity' => '2',
            'unit_amount' => 5000,
            'tax_amount' => 0,
            'sort_order' => 1,
        ]]);

        return [$owner, $workspace, $service->issue($invoice, $workspace)];
    }
}
