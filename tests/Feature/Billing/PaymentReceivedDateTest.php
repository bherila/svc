<?php

namespace Tests\Feature\Billing;

use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\InvoiceLifecycleService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
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

    /**
     * Tomorrow is refused on the workspace's calendar, not the server's.
     *
     * Frozen at 05:30 UTC on the 30th, a Los Angeles workspace is still on the
     * 29th. `now()` would accept the 30th as today; the workspace clock refuses
     * it as a claim about money that has not arrived.
     */
    public function test_a_future_payment_is_refused_against_the_workspaces_own_clock(): void
    {
        Date::setTestNow(CarbonImmutable::parse('2026-08-30 05:30:00 UTC'));

        try {
            [, $workspace, $invoice] = $this->issuedInvoice(timezone: 'America/Los_Angeles');

            $this->expectException(DomainException::class);
            $this->expectExceptionMessage('A payment cannot be dated after 2026-08-29');

            app(InvoiceLifecycleService::class)->applyPayment($invoice, [
                'amount' => 1000,
                'currency' => 'USD',
                'method' => 'wire',
                'received_on' => '2026-08-30',
            ], $workspace);
        } finally {
            Date::setTestNow();
        }
    }

    /**
     * The floor is two years, and the refusal says where it is.
     *
     * A year typed one digit wrong is the date nothing downstream can notice:
     * the invoice's status and balance are unaffected, and the payment simply
     * reconciles into a period that closed a decade ago.
     */
    public function test_a_payment_older_than_the_floor_is_refused_and_the_message_names_the_earliest_date(): void
    {
        Date::setTestNow(CarbonImmutable::parse('2026-08-29 12:00:00 UTC'));

        try {
            [, $workspace, $invoice] = $this->issuedInvoice();
            $service = app(InvoiceLifecycleService::class);

            // The floor itself is acceptable; the day before it is not.
            $onTheFloor = $service->applyPayment($invoice, [
                'amount' => 1000, 'currency' => 'USD', 'method' => 'wire', 'received_on' => '2024-08-29',
            ], $workspace);
            $this->assertSame('2024-08-29', $onTheFloor->received_on?->toDateString());

            $this->expectException(DomainException::class);
            $this->expectExceptionMessage('A payment cannot be dated before 2024-08-29');

            $service->applyPayment($invoice, [
                'amount' => 1000, 'currency' => 'USD', 'method' => 'wire', 'received_on' => '2024-08-28',
            ], $workspace);
        } finally {
            Date::setTestNow();
        }
    }

    /**
     * A payment may predate the invoice it pays, and the write succeeds.
     *
     * A deposit or an advance retainer applied to an invoice issued afterwards
     * is a real arrangement, so this is a warning on the screens rather than a
     * refusal here. Pinned so that tightening it later has to be a decision.
     */
    public function test_a_payment_may_predate_the_invoices_issue_date(): void
    {
        Date::setTestNow(CarbonImmutable::parse('2026-08-29 12:00:00 UTC'));

        try {
            [, $workspace, $invoice] = $this->issuedInvoice();
            $this->assertSame('2026-08-29', $invoice->issue_date?->toDateString());

            $payment = app(InvoiceLifecycleService::class)->applyPayment($invoice, [
                'amount' => 1000, 'currency' => 'USD', 'method' => 'wire', 'received_on' => '2026-07-01',
            ], $workspace);

            $this->assertSame('2026-07-01', $payment->received_on?->toDateString());
        } finally {
            Date::setTestNow();
        }
    }

    /**
     * The console door is bounded by the same rule, not by `strtotime`.
     *
     * `--received-on` has always existed and was passed straight through, so
     * this is the caller the FormRequest cannot reach.
     */
    public function test_the_console_door_is_bounded_by_the_same_rule(): void
    {
        Date::setTestNow(CarbonImmutable::parse('2026-08-29 12:00:00 UTC'));

        try {
            [, $workspace, $invoice] = $this->issuedInvoice();

            foreach (['next friday', '2015-08-29', '2026-08-30'] as $refused) {
                try {
                    $this->artisan('svc:billing:payment', [
                        'invoice' => $invoice->public_id,
                        'amount' => '1000',
                        'currency' => 'USD',
                        'method' => 'wire',
                        '--workspace' => $workspace->public_id,
                        '--received-on' => $refused,
                    ])->run();
                    $this->fail('The command accepted "'.$refused.'" as a payment date.');
                } catch (DomainException $exception) {
                    $this->assertStringContainsString('Nothing has been changed.', $exception->getMessage());
                }
            }

            $this->assertDatabaseCount('client_invoice_payments', 0);
        } finally {
            Date::setTestNow();
        }
    }

    /**
     * A dated payment is refused across a tenant boundary as a tenant refusal.
     *
     * The date adds no new surface, and the point of asserting it is that the
     * clock the bound reads is the *invoice's* workspace: a member of one
     * workspace posting at another's invoice must be turned away for whose
     * invoice it is, never for what day it is.
     */
    public function test_a_member_of_one_workspace_cannot_date_a_payment_into_another(): void
    {
        [$owner, $workspace] = $this->issuedInvoice('Alpha Workspace');
        [, $otherWorkspace, $otherInvoice] = $this->issuedInvoice('Beta Workspace');

        // Their own workspace in the URL, another workspace's invoice in it.
        $this->actingAs($owner)
            ->postJson("/workspaces/{$workspace->public_id}/invoices/{$otherInvoice->public_id}/payments", [
                'amount' => 1000, 'currency' => 'USD', 'method' => 'wire', 'received_on' => '2026-08-15',
            ])
            ->assertNotFound();

        // The other workspace in the URL, where they are not a member at all.
        $this->actingAs($owner)
            ->postJson("/workspaces/{$otherWorkspace->public_id}/invoices/{$otherInvoice->public_id}/payments", [
                'amount' => 1000, 'currency' => 'USD', 'method' => 'wire', 'received_on' => '2026-08-15',
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('client_invoice_payments', 0);
    }

    /** @return array{0:User,1:Workspace,2:ClientInvoice} */
    private function issuedInvoice(string $name = 'Payment Date Workspace', string $timezone = 'UTC'): array
    {
        $owner = User::factory()->create(['email' => 'payment-date-'.str()->random(6).'@synthetic.test']);
        $workspace = Workspace::query()->create([
            'name' => $name,
            'slug' => str($name)->slug().'-'.str()->random(5),
            'timezone' => $timezone,
        ]);
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
