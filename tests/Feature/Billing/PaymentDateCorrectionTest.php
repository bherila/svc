<?php

namespace Tests\Feature\Billing;

use App\Models\ClientCompany;
use App\Models\ClientCompanyActivity;
use App\Models\ClientInvoice;
use App\Models\ClientInvoicePayment;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\InvoiceLifecycleService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Correcting the day a payment arrived, and nothing else about it.
 *
 * There is deliberately no payment-edit path here: a payment is corrected by
 * transitioning its status or its refunded amount, so history is preserved
 * rather than rewritten. A mistyped date is not a money correction - it moves
 * no amount and cannot move an invoice's balance - and the only remedy for one
 * before this was to cancel the payment and record it again, which invents a
 * cancellation that never happened and leaves it in the client's history.
 *
 * These pin the narrowness: one column, the same bounds as the way in, and the
 * workspace boundary that every tenant-owned write is held to.
 */
final class PaymentDateCorrectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        if (! Route::has('svc.billing.invoices.payments.received-on')) {
            require base_path('routes/billing.php');
        }
        Date::setTestNow(CarbonImmutable::parse('2026-08-29 12:00:00 UTC'));
    }

    protected function tearDown(): void
    {
        Date::setTestNow();

        parent::tearDown();
    }

    public function test_a_correction_moves_the_date_and_leaves_the_money_alone(): void
    {
        [, $workspace, , $payment] = $this->recordedPayment();
        $service = app(InvoiceLifecycleService::class);

        $corrected = $service->setPaymentReceivedOn($payment, '2026-08-18', $workspace);

        $this->assertSame('2026-08-18', $corrected->received_on?->toDateString());
        $this->assertSame($payment->amount, $corrected->amount);
        $this->assertSame($payment->currency, $corrected->currency);
        $this->assertSame($payment->method, $corrected->method);
        $this->assertSame($payment->status, $corrected->status);
        $this->assertSame($payment->refunded_amount, $corrected->refunded_amount);

        // Recorded the way the comparable corrections are, carrying the date it
        // replaced: the history says what was changed rather than only that the
        // row now reads differently.
        $activity = ClientCompanyActivity::query()
            ->where('action', 'invoice.payment_date_corrected')
            ->sole();
        $this->assertSame('2026-08-25', $activity->payload['previous_received_on'] ?? null);
        $this->assertSame('2026-08-18', $activity->payload['received_on'] ?? null);
        $this->assertSame($payment->amount, $activity->payload['amount'] ?? null);
    }

    /** Correcting to the date already recorded changes nothing and records nothing. */
    public function test_a_correction_to_the_recorded_date_is_a_no_op(): void
    {
        [, $workspace, , $payment] = $this->recordedPayment();

        app(InvoiceLifecycleService::class)->setPaymentReceivedOn($payment, '2026-08-25', $workspace);

        $this->assertSame(
            0,
            ClientCompanyActivity::query()->where('action', 'invoice.payment_date_corrected')->count(),
        );
    }

    /**
     * The same window as the way in.
     *
     * A correction is not a way around the bound: an operator who can type a
     * mistaken date into the recording form can type the same one here.
     */
    public function test_a_correction_is_bounded_by_the_same_window(): void
    {
        [, $workspace, , $payment] = $this->recordedPayment();
        $service = app(InvoiceLifecycleService::class);

        foreach ([
            '2026-08-30' => 'A payment cannot be dated after 2026-08-29',
            '2015-08-29' => 'A payment cannot be dated before 2024-08-29',
            '2026-02-31' => 'must be a real calendar date',
        ] as $refused => $expected) {
            try {
                $service->setPaymentReceivedOn($payment, (string) $refused, $workspace);
                $this->fail('The correction accepted "'.$refused.'".');
            } catch (DomainException $exception) {
                $this->assertStringContainsString($expected, $exception->getMessage());
            }
        }

        $this->assertSame('2026-08-25', $payment->fresh()?->received_on?->toDateString());
    }

    /**
     * The HTTP door validates one field and writes one column.
     *
     * Money sent alongside is not validated, not read and not written: the
     * request names `received_on` and nothing else, and the service takes a
     * date rather than an array of attributes.
     */
    public function test_the_route_corrects_the_date_and_ignores_everything_sent_beside_it(): void
    {
        [$owner, $workspace, $invoice, $payment] = $this->recordedPayment();

        $this->actingAs($owner)
            ->postJson($this->correctionUrl($workspace, $invoice, $payment), [
                'received_on' => '2026-08-18',
                'amount' => 999999,
                'status' => 'refunded',
                'refunded_amount' => 999999,
                'method' => 'cash',
            ])
            ->assertOk();

        $fresh = $payment->fresh();
        $this->assertSame('2026-08-18', $fresh?->received_on?->toDateString());
        $this->assertSame(1000, $fresh?->amount);
        $this->assertSame(0, $fresh?->refunded_amount);
        $this->assertSame('succeeded', $fresh?->status);
        $this->assertSame('wire', $fresh?->method);
    }

    public function test_the_route_refuses_a_date_that_is_not_a_calendar_day(): void
    {
        [$owner, $workspace, $invoice, $payment] = $this->recordedPayment();

        $this->actingAs($owner)
            ->postJson($this->correctionUrl($workspace, $invoice, $payment), ['received_on' => '08/18/2026'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('received_on');

        $this->assertSame('2026-08-25', $payment->fresh()?->received_on?->toDateString());
    }

    /**
     * The new write is workspace-scoped, at the service and over the web.
     *
     * A payment binds by a public id unique across every workspace, so passing
     * the gate on a workspace is not passing a check on the payment reached
     * through it.
     */
    public function test_a_correction_cannot_cross_a_workspace_boundary(): void
    {
        [$owner, $workspace, $invoice, $payment] = $this->recordedPayment('Alpha');
        [, $otherWorkspace, $otherInvoice, $otherPayment] = $this->recordedPayment('Beta');
        $service = app(InvoiceLifecycleService::class);

        // The service refuses to find another workspace's payment at all.
        try {
            $service->setPaymentReceivedOn($otherPayment, '2026-08-18', $workspace);
            $this->fail('A payment was corrected from outside its workspace.');
        } catch (ModelNotFoundException) {
            $this->assertSame('2026-08-25', $otherPayment->fresh()?->received_on?->toDateString());
        }

        // Their own workspace and invoice in the URL, another workspace's
        // payment in it.
        $this->actingAs($owner)
            ->postJson(
                "/workspaces/{$workspace->public_id}/invoices/{$invoice->public_id}/payments/{$otherPayment->public_id}/received-on",
                ['received_on' => '2026-08-18'],
            )
            ->assertNotFound();

        // The other workspace in the URL, where they are not a member.
        $this->actingAs($owner)
            ->postJson($this->correctionUrl($otherWorkspace, $otherInvoice, $otherPayment), ['received_on' => '2026-08-18'])
            ->assertForbidden();

        $this->assertSame('2026-08-25', $otherPayment->fresh()?->received_on?->toDateString());
        $this->assertSame('2026-08-25', $payment->fresh()?->received_on?->toDateString());
    }

    private function correctionUrl(Workspace $workspace, ClientInvoice $invoice, ClientInvoicePayment $payment): string
    {
        return "/workspaces/{$workspace->public_id}/invoices/{$invoice->public_id}/payments/{$payment->public_id}/received-on";
    }

    /** @return array{0:User,1:Workspace,2:ClientInvoice,3:ClientInvoicePayment} */
    private function recordedPayment(string $name = 'Correction Workspace'): array
    {
        $owner = User::factory()->create(['email' => 'correction-'.str()->random(6).'@synthetic.test']);
        $workspace = Workspace::query()->create([
            'name' => $name,
            'slug' => str($name)->slug().'-'.str()->random(5),
        ]);
        $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
        $company = ClientCompany::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Synthetic Client',
            'slug' => 'synthetic-client-'.$workspace->id,
        ]);

        $service = app(InvoiceLifecycleService::class);
        $invoice = $service->issue($service->createDraft($workspace, $company, [
            'invoice_number' => 'INV-CORRECT-'.str()->upper(str()->random(8)),
            'currency' => 'USD',
        ], [[
            'type' => 'service',
            'description' => 'Synthetic service',
            'quantity' => '2',
            'unit_amount' => 5000,
            'tax_amount' => 0,
            'sort_order' => 1,
        ]]), $workspace);

        $payment = $service->applyPayment($invoice, [
            'amount' => 1000,
            'currency' => 'USD',
            'method' => 'wire',
            'received_on' => '2026-08-25',
        ], $workspace);

        return [$owner, $workspace, $invoice, $payment];
    }
}
