<?php

namespace Tests\Feature\Billing;

use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientInvoicePayment;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\InvoiceLifecycleService;
use App\Services\Billing\OverpaymentCreditService;
use App\Services\Billing\StripePaymentIntentService;
use App\Support\AgentApi\AgentApiVersion;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\WritesLegacyCrossTenantRows;
use Tests\TestCase;

final class DisputedInvoiceTest extends TestCase
{
    use RefreshDatabase;
    use WritesLegacyCrossTenantRows;

    public function test_a_partial_dispute_stays_visible_after_replacement_payment_and_a_win_restores_credit(): void
    {
        config(['agent_api.writes_enabled' => true, 'agent_api.payment_writes_enabled' => true]);
        [$owner, $workspace, $company, $invoice] = $this->fixture();
        $service = app(InvoiceLifecycleService::class);
        $payment = $this->pay($workspace, $invoice, 1000);
        $before = AgentApiVersion::for($invoice->fresh());
        $service->setPaymentDisputed($payment, 400, $workspace);
        $disputed = $invoice->fresh();
        $this->assertSame(['disputed', 600, 400], [$disputed->status, $disputed->paid_amount, $disputed->balance_amount]);
        $this->assertNotSame($before, AgentApiVersion::for($disputed));
        $this->assertTrue($disputed->isImmutable());
        $credits = app(OverpaymentCreditService::class);
        $this->assertSame(0.0, $credits->availableCreditForCompany($company, 'USD'));

        $this->actingAs($owner)->get(route('clients.invoice', [$workspace, $company, $invoice]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('invoice.status', 'disputed')
                ->where('actions.payment', route('agent-api.v1.payments.store', $workspace, absolute: false))
                ->where('actions.send', null)->where('actions.correct', null)
                ->where('payment_reminders.send_href', null));

        $this->actingAsMcp($owner, ['payments:record', 'billing:read']);
        $this->postJson("/api/v1/workspaces/{$workspace->public_id}/payments", [
            'invoice_id' => $invoice->public_id, 'expected_version' => AgentApiVersion::for($disputed),
            'amount' => 400, 'currency' => 'USD', 'method' => 'wire', 'confirm' => true,
        ], ['Idempotency-Key' => 'synthetic-disputed-replacement'])->assertCreated();
        $this->assertSame(['disputed', 1000, 0], [$invoice->fresh()->status, $invoice->fresh()->paid_amount, $invoice->fresh()->balance_amount]);
        $this->assertSame(0.0, $credits->availableCreditForCompany($company, 'USD'));
        $service->setPaymentStatus($payment, 'succeeded', $workspace);
        $this->assertSame(['paid', 1000, 0], [$invoice->fresh()->status, $invoice->fresh()->paid_amount, $invoice->fresh()->balance_amount]);
        $this->assertSame(4.0, $credits->availableCreditForCompany($company, 'USD'));
    }

    public function test_disputed_invoices_are_scoped_readable_and_filterable_but_cannot_start_collection(): void
    {
        [$owner, $workspace, $company, $invoice] = $this->fixture();
        $payment = $this->pay($workspace, $invoice, 1000);
        app(InvoiceLifecycleService::class)->setPaymentDisputed($payment, 400, $workspace);
        [$foreignOwner, $foreignWorkspace, , $foreignInvoice] = $this->fixture();
        $foreignPayment = $this->pay($foreignWorkspace, $foreignInvoice, 1000);
        app(InvoiceLifecycleService::class)->setPaymentDisputed($foreignPayment, 200, $foreignWorkspace);
        $this->actingAsMcp($owner, ['billing:read']);
        $base = "/api/v1/workspaces/{$workspace->public_id}/invoices";
        $this->getJson($base.'?status=disputed')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $invoice->public_id)->assertJsonPath('data.0.status', 'disputed');
        $this->getJson($base.'?collectible=true')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($base.'/'.$foreignInvoice->public_id)->assertNotFound();
        $this->getJson("/api/v1/workspaces/{$foreignWorkspace->public_id}/invoices?status=disputed")->assertNotFound();
        $portalUser = User::factory()->create();
        $company->portalUsers()->attach($portalUser->id, ['workspace_id' => $workspace->id]);
        $this->actingAs($portalUser)->get(route('portal.invoice', [$company, $invoice]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('invoice.status', 'disputed')->where('square_page_url', null));
        $this->actingAs($portalUser)->postJson(route('svc.billing.invoices.stripe-payment-intent', [$workspace, $invoice]), ['idempotency_key' => 'synthetic-disputed-intent'])->assertForbidden();
        try {
            app(StripePaymentIntentService::class)->create($invoice->fresh(), $workspace, null, 'synthetic-disputed-service-intent');
            $this->fail('A dispute must pause payment initiation at the service boundary too.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('require an issued invoice', $exception->getMessage());
        }
    }

    public function test_the_last_dispute_resolution_restores_the_current_balance_state_and_void_is_terminal(): void
    {
        [, $workspace, , $invoice] = $this->fixture();
        $first = $this->pay($workspace, $invoice, 600);
        $second = $this->pay($workspace, $invoice, 400);
        $service = app(InvoiceLifecycleService::class);
        $service->setPaymentDisputed($first, 600, $workspace);
        $service->setPaymentDisputed($second, 400, $workspace);
        $this->assertSame(['disputed', 0], [$invoice->fresh()->status, $invoice->fresh()->paid_amount]);
        $service->setPaymentStatus($first, 'failed', $workspace);
        $this->assertSame('disputed', $invoice->fresh()->status);
        $service->setPaymentStatus($second, 'succeeded', $workspace);
        $this->assertSame(['partially_paid', 400, 600], [$invoice->fresh()->status, $invoice->fresh()->paid_amount, $invoice->fresh()->balance_amount]);
        $service->setPaymentStatus($second, 'failed', $workspace);
        $this->assertSame(['issued', 1000], [$invoice->fresh()->status, $invoice->fresh()->balance_amount]);
        $invoice->forceFill(['status' => 'void'])->save();
        $service->setPaymentDisputed($first, 100, $workspace);
        $this->assertSame('void', $invoice->fresh()->status);
        $service->setPaymentStatus($first, 'succeeded', $workspace);
        $this->assertSame('void', $invoice->fresh()->status);
    }

    public function test_historical_backfill_changes_only_known_charged_invoices_with_their_own_disputes(): void
    {
        [, $workspace, , $invoice] = $this->fixture();
        $payment = $this->pay($workspace, $invoice, 1000);
        DB::table('client_invoice_payments')->where('id', $payment->id)->update(['status' => 'disputed', 'disputed_amount' => 400]);
        $before = AgentApiVersion::for($invoice->fresh());
        [, $foreignWorkspace, , $foreignInvoice] = $this->fixture();
        $this->writingLegacyCrossTenantRows(fn () => ClientInvoicePayment::query()->create([
            'workspace_id' => $workspace->id, 'client_invoice_id' => $foreignInvoice->id,
            'amount' => 1000, 'currency' => 'USD', 'status' => 'disputed', 'method' => 'synthetic', 'received_on' => now()->toDateString(),
        ]));
        $protected = [];
        foreach (['draft', 'void', 'unknown_synthetic'] as $status) {
            [, $protectedWorkspace, , $protectedInvoice] = $this->fixture();
            $this->pay($protectedWorkspace, $protectedInvoice, 1000);
            DB::table('client_invoice_payments')->where('client_invoice_id', $protectedInvoice->id)->update(['status' => 'disputed']);
            $protectedInvoice->forceFill(['status' => $status])->save();
            $protected[$protectedInvoice->id] = $status;
        }
        $migration = require database_path('migrations/2026_10_28_090000_mark_invoices_with_disputed_payments.php');
        $migration->up();
        $this->assertSame('disputed', $invoice->fresh()->status);
        $this->assertNotSame($before, AgentApiVersion::for($invoice->fresh()));
        $this->assertSame('issued', $foreignInvoice->fresh()->status);
        foreach ($protected as $id => $status) {
            $this->assertSame($status, DB::table('client_invoices')->where('id', $id)->value('status'));
        }
        $version = AgentApiVersion::for($invoice->fresh());
        $migration->up();
        $this->assertSame($version, AgentApiVersion::for($invoice->fresh()));
        $migration->down();
        $this->assertSame('paid', $invoice->fresh()->status);
    }

    /** @return array{User, Workspace, ClientCompany, ClientInvoice} */
    private function fixture(): array
    {
        $owner = User::factory()->create();
        $workspace = Workspace::query()->create(['name' => 'Synthetic dispute workspace', 'slug' => 'synthetic-dispute-'.str()->random(8)]);
        $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic dispute client', 'slug' => 'synthetic-dispute-client-'.str()->random(8)]);
        $invoice = ClientInvoice::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'invoice_number' => 'SYNTHETIC-'.str()->uuid(), 'status' => 'issued', 'invoice_kind' => 'ad_hoc', 'currency' => 'USD', 'total_amount' => 1000, 'subtotal_amount' => 1000, 'paid_amount' => 0, 'balance_amount' => 1000, 'issue_date' => now()->toDateString(), 'due_date' => now()->toDateString(), 'is_visible_to_client' => true]);
        $invoice->lines()->create(['workspace_id' => $workspace->id, 'type' => 'manual', 'description' => 'Synthetic dispute service', 'quantity' => 1, 'unit_amount' => 1000, 'total_amount' => 1000]);

        return [$owner, $workspace, $company, $invoice];
    }

    private function pay(Workspace $workspace, ClientInvoice $invoice, int $amount): ClientInvoicePayment
    {
        return app(InvoiceLifecycleService::class)->applyPayment($invoice, ['amount' => $amount, 'currency' => 'USD', 'method' => 'wire', 'status' => 'succeeded'], $workspace);
    }
}
