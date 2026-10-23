<?php

namespace Tests\Feature\Api;

use App\Models\AgentMutationAudit;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientInvoicePayment;
use App\Models\PaymentReconciliation;
use App\Models\User;
use App\Models\Workspace;
use App\Support\AgentApi\AgentApiResponseSchemaCatalog;
use App\Support\AgentApi\AgentApiVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Mcp\Capability\Discovery\SchemaValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CallsMcp;
use Tests\Concerns\InspectsAgentOperations;
use Tests\TestCase;

final class FinanceReconciliationAcknowledgementTransportTest extends TestCase
{
    use CallsMcp;
    use InspectsAgentOperations;
    use RefreshDatabase;

    private const string OPERATION = 'finance.reconciliations.acknowledge';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2030-01-20 12:00:00');
        config(['agent_api.mcp_enabled' => true, 'agent_api.mcp_feature_flags' => []]);
    }

    public function test_the_operation_declares_conditional_write_safety_and_the_existing_scope(): void
    {
        $operation = $this->agentOperation(self::OPERATION);
        $this->assertSame('PUT', $operation->rest->method);
        $this->assertSame(['finance:reconcile'], $operation->requirement->scopes);
        $this->assertTrue($operation->safety->confirm);
        $this->assertTrue($operation->safety->expectedVersion);
        $this->assertTrue($operation->idempotent);
        $this->assertSame('FinanceReconciliationAcknowledgementRequest', AgentApiResponseSchemaCatalog::catalog()->requestComponent(self::OPERATION));
    }

    public function test_rest_acknowledges_and_audits_without_changing_money_or_activity(): void
    {
        [$owner, $workspace, $payment, $allocation] = $this->fixture();
        $this->actingAsMcp($owner, ['finance:reconcile']);
        $proof = $this->proof($payment, $allocation);
        $response = $this->putJson($this->path($workspace, $payment, $allocation), $proof)->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('data.needs_review', false)
            ->assertJsonPath('data.review_reason', null)->assertJsonPath('data.review_flagged_at', null)
            ->assertJsonPath('data.allocated_amount', 1000)->assertJsonPath('data.is_active', true);
        $this->assertSame([], (new SchemaValidator)->validateAgainstJsonSchema($response->json(), AgentApiResponseSchemaCatalog::schema('FinanceReconciliationResponse')));
        $this->assertSame('disputed', $payment->fresh()->status);
        $this->assertSame(900, $payment->fresh()->refunded_amount);
        $audit = AgentMutationAudit::query()->where('operation', self::OPERATION)->sole();
        $this->assertSame('success', $audit->outcome);
        $this->assertSame([$allocation->public_id], $audit->affected_public_ids);
        $this->putJson($this->path($workspace, $payment, $allocation), $proof)->assertConflict();
        $this->assertSame(['success', 'failed'], AgentMutationAudit::query()->where('operation', self::OPERATION)->orderBy('id')->pluck('outcome')->all());
        $this->assertDatabaseCount('agent_mutation_receipts', 0);
    }

    #[DataProvider('invalidConfirmation')]
    public function test_rest_requires_boolean_true_confirmation(mixed $confirm): void
    {
        [$owner, $workspace, $payment, $allocation] = $this->fixture();
        $this->actingAsMcp($owner, ['finance:reconcile']);
        $this->putJson($this->path($workspace, $payment, $allocation), [...$this->proof($payment, $allocation), 'confirm' => $confirm])
            ->assertUnprocessable()->assertJsonValidationErrors('confirm');
        $this->assertNotNull($allocation->fresh()->review_reason);
    }

    public static function invalidConfirmation(): iterable
    {
        foreach ([false, null, 1, 'true'] as $value) {
            yield [$value];
        }
    }

    public function test_rest_requires_proof_and_rejects_money_edits(): void
    {
        [$owner, $workspace, $payment, $allocation] = $this->fixture();
        $this->actingAsMcp($owner, ['finance:reconcile']);
        $this->putJson($this->path($workspace, $payment, $allocation), ['confirm' => true])->assertUnprocessable()->assertJsonValidationErrors('expected_payment_version');
        $this->putJson($this->path($workspace, $payment, $allocation), [...$this->proof($payment, $allocation), 'allocated_amount' => 1])->assertUnprocessable()->assertJsonValidationErrors('allocated_amount');
        $this->assertSame(1000, $allocation->fresh()->allocated_amount);
    }

    public function test_rest_matches_existing_scope_role_and_tenant_boundaries(): void
    {
        [$owner, $workspace, $payment, $allocation] = $this->fixture();
        $path = $this->path($workspace, $payment, $allocation);
        $proof = $this->proof($payment, $allocation);
        $this->actingAsMcp($owner, ['finance:read']);
        $this->putJson($path, $proof)->assertForbidden();
        $member = User::factory()->create();
        $workspace->memberships()->create(['user_id' => $member->id, 'role' => 'member']);
        $this->actingAsMcp($member, ['finance:reconcile']);
        $this->putJson($path, $proof)->assertForbidden();
        $outsider = User::factory()->create();
        $this->actingAsMcp($outsider, ['finance:reconcile']);
        $this->putJson($path, $proof)->assertNotFound();
        $this->actingAsMcp($owner, ['finance:reconcile']);
        $this->putJson(str_replace($payment->public_id, (string) Str::uuid(), $path), $proof)->assertNotFound();
        $this->putJson(str_replace($allocation->external_transaction_uuid, (string) Str::uuid(), $path), $proof)->assertNotFound();
        $this->assertNotNull($allocation->fresh()->review_reason);
    }

    public function test_a_sanctum_finance_token_can_acknowledge_but_read_scope_cannot(): void
    {
        [$owner, $workspace, $payment, $allocation] = $this->fixture();
        $reader = $owner->createToken('synthetic-reader', ['finance.read'], now()->addHour());
        $this->withToken($reader->plainTextToken)->putJson($this->path($workspace, $payment, $allocation), $this->proof($payment, $allocation))->assertForbidden();
        Auth::forgetGuards();
        $writer = $owner->createToken('synthetic-writer', ['finance.reconcile'], now()->addHour());
        $this->withToken($writer->plainTextToken)->putJson($this->path($workspace, $payment, $allocation), $this->proof($payment, $allocation))->assertOk();
        $this->assertSame('finance-token:'.$writer->accessToken->id, AgentMutationAudit::query()->where('outcome', 'success')->sole()->oauth_client_id);
    }

    public function test_mcp_uses_the_same_confirmation_proof_and_conflict_response(): void
    {
        [$owner, $workspace, $payment, $allocation] = $this->fixture();
        $this->actingAsMcp($owner, ['mcp:use', 'finance:reconcile']);
        $session = $this->initialize();
        $this->assertContains(self::OPERATION, $this->toolNames($session));
        $arguments = ['workspace_id' => $workspace->public_id, 'payment_id' => $payment->public_id, 'external_system' => $allocation->external_system_slug, 'transaction_id' => $allocation->external_transaction_uuid, ...$this->proof($payment, $allocation)];
        $refused = $this->callTool($session, self::OPERATION, [...$arguments, 'confirm' => false]);
        $this->assertTrue(isset($refused['error']) || ($refused['result']['isError'] ?? false));
        $this->assertStringContainsString('confirm', json_encode($refused));
        $this->assertNotNull($allocation->fresh()->review_reason);
        $accepted = $this->callTool($session, self::OPERATION, $arguments);
        $this->assertFalse($accepted['result']['isError'] ?? false, json_encode($accepted));
        $this->assertSame(false, $accepted['result']['structuredContent']['data']['needs_review']);
        $stale = $this->callTool($session, self::OPERATION, $arguments);
        $this->assertTrue($stale['result']['isError']);
        $this->assertStringContainsString('refresh', json_encode($stale));
    }

    public function test_cli_previews_then_applies_and_refuses_a_stale_retry(): void
    {
        [$owner, $workspace, $payment, $allocation] = $this->fixture();
        $options = ['payment' => $payment->public_id, 'system' => $allocation->external_system_slug, 'transaction' => $allocation->external_transaction_uuid, '--workspace' => $workspace->public_id, '--user' => $owner->public_id, '--expected-payment-version' => AgentApiVersion::for($payment), '--expected-review-reason' => 'processor_dispute', '--expected-review-flagged-at' => $allocation->review_flagged_at->toIso8601String(), '--confirm' => true, '--format' => 'json'];
        $this->artisan('svc:finance:acknowledge-reconciliation', $options)->expectsOutputToContain('"dry_run":true')->assertSuccessful();
        $this->assertNotNull($allocation->fresh()->review_reason);
        $this->assertDatabaseCount('agent_mutation_audits', 0);
        $this->artisan('svc:finance:acknowledge-reconciliation', [...$options, '--apply' => true])->expectsOutputToContain('"needs_review":false')->assertSuccessful();
        $this->assertNull($allocation->fresh()->review_reason);
        $this->artisan('svc:finance:acknowledge-reconciliation', [...$options, '--apply' => true])->assertFailed();
        $this->assertSame(['success', 'failed'], AgentMutationAudit::query()->orderBy('id')->pluck('outcome')->all());
    }

    private function fixture(): array
    {
        $owner = User::factory()->create();
        $workspace = Workspace::query()->create(['name' => 'Synthetic acknowledgement transport', 'slug' => 'synthetic-ack-transport']);
        $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic company', 'slug' => 'synthetic-transport']);
        $invoice = ClientInvoice::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'invoice_number' => 'SYNTHETIC-TRANSPORT', 'currency' => 'USD']);
        $payment = ClientInvoicePayment::query()->create(['workspace_id' => $workspace->id, 'client_invoice_id' => $invoice->id, 'provider' => 'stripe', 'provider_payment_identifier' => 'pi_synthetic_transport', 'method' => 'card', 'amount' => 1000, 'refunded_amount' => 900, 'currency' => 'USD', 'status' => 'disputed']);
        $allocation = PaymentReconciliation::query()->create(['workspace_id' => $workspace->id, 'client_invoice_payment_id' => $payment->id, 'external_system_slug' => 'synthetic-finance', 'external_transaction_uuid' => (string) Str::uuid(), 'allocated_amount' => 1000, 'currency' => 'USD', 'created_by_user_id' => $owner->id, 'is_active' => true]);
        $allocation->forceFill(['review_reason' => 'processor_dispute', 'review_flagged_at' => now()])->save();

        return [$owner, $workspace, $payment->fresh(), $allocation->fresh()];
    }

    private function proof(ClientInvoicePayment $payment, PaymentReconciliation $allocation): array
    {
        return ['expected_payment_version' => AgentApiVersion::for($payment), 'expected_review_reason' => $allocation->review_reason->value, 'expected_review_flagged_at' => $allocation->review_flagged_at->toIso8601String(), 'confirm' => true];
    }

    private function path(Workspace $workspace, ClientInvoicePayment $payment, PaymentReconciliation $allocation): string
    {
        return '/api/v1/workspaces/'.$workspace->public_id.'/invoice-payments/'.$payment->public_id.'/reconciliations/'.$allocation->external_system_slug.'/'.$allocation->external_transaction_uuid.'/acknowledgement';
    }
}
