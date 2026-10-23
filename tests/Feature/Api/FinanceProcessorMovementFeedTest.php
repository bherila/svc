<?php

namespace Tests\Feature\Api;

use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientInvoicePayment;
use App\Models\ProcessorMovement;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Finance\ProcessorMovementRecorder;
use App\Support\AgentApi\AgentApiResponseSchemaCatalog;
use App\Support\Finance\FinanceProcessorMovementQuery;
use App\Support\Finance\ProcessorMovementFacts;
use App\Support\Finance\ProcessorMovementKind;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Mcp\Capability\Discovery\SchemaValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CallsMcp;
use Tests\Concerns\InspectsAgentOperations;
use Tests\TestCase;

final class FinanceProcessorMovementFeedTest extends TestCase
{
    use CallsMcp;
    use InspectsAgentOperations;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2030-01-20 12:00:00');
        config(['agent_api.mcp_enabled' => true, 'agent_api.mcp_feature_flags' => []]);
    }

    public function test_the_operation_is_read_only_and_uses_the_existing_finance_scope_and_contract_baseline(): void
    {
        $operation = $this->agentOperation('finance.processor_movements.list');
        $this->assertSame('GET', $operation->rest->method);
        $this->assertSame('/workspaces/{workspace}/processor-movements', $operation->rest->path);
        $this->assertSame(['finance:read'], $operation->requirement->scopes);
        $this->assertTrue($operation->effect->readOnly());
        $this->assertSame(ProcessorMovementKind::all(), FinanceProcessorMovementQuery::KINDS);
        $this->assertSame('FinanceProcessorMovementListResponse', AgentApiResponseSchemaCatalog::operationComponent($operation->id));
    }

    public function test_rest_returns_only_the_closed_signed_money_and_owned_payment_representation(): void
    {
        [$owner, $workspace, $payment, $movement] = $this->fixture();
        $this->actingAsMcp($owner, ['finance:read']);
        $this->readClock();
        $response = $this->getJson($this->path($workspace))->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $row = $response->json('data.0');
        $keys = ['id', 'version', 'payment_id', 'provider', 'kind', 'principal_minor', 'fee_minor', 'net_minor', 'currency', 'provider_occurred_at', 'occurred_on', 'occurred_timezone', 'date_basis', 'available_on', 'provider_payout_identifier', 'needs_review', 'review_reason'];
        sort($keys);
        $actual = array_keys($row);
        sort($actual);
        $this->assertSame($keys, $actual);
        $this->assertSame($movement->public_id, $row['id']);
        $this->assertSame($payment->public_id, $row['payment_id']);
        $this->assertSame([-100, -3, -97], [$row['principal_minor'], $row['fee_minor'], $row['net_minor']]);
        $this->assertSame('2030-01-01T00:30:00.123456+00:00', $row['provider_occurred_at']);
        $this->assertSame('2029-12-31', $row['occurred_on']);
        $this->assertSame('America/Los_Angeles', $row['occurred_timezone']);
        $this->assertNull($response->json('meta.next_cursor'));
        foreach (['synthetic_private_account', 'synthetic_entry_private', 'synthetic_refund_private'] as $private) {
            $this->assertStringNotContainsString($private, $response->getContent());
        }
        $this->assertSchema($response->json());
    }

    public function test_the_scopes_roles_and_workspace_boundary_match_the_other_finance_reads(): void
    {
        [$owner, $workspace] = $this->fixture();
        $this->readClock();
        $this->actingAsMcp($owner, ['payments:read']);
        $this->getJson($this->path($workspace))->assertForbidden();
        $member = User::factory()->create();
        $workspace->memberships()->create(['user_id' => $member->id, 'role' => 'member']);
        $this->actingAsMcp($member, ['finance:read']);
        $this->getJson($this->path($workspace))->assertForbidden();
        $outsider = User::factory()->create();
        $this->actingAsMcp($outsider, ['finance:read']);
        $this->getJson($this->path($workspace))->assertNotFound();
        Auth::forgetGuards();
        $token = $member->createToken('synthetic-finance', ['finance.read'], now()->addHour())->plainTextToken;
        $this->withToken($token)->getJson($this->path($workspace))->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_mcp_and_cli_deliver_the_same_shared_read(): void
    {
        [$owner, $workspace, , $movement] = $this->fixture();
        $this->readClock();
        $this->actingAsMcp($owner, ['mcp:use', 'finance:read']);
        $session = $this->initialize();
        $this->assertContains('finance.processor_movements.list', $this->toolNames($session));
        $called = $this->callTool($session, 'finance.processor_movements.list', ['workspace_id' => $workspace->public_id, 'provider' => 'stripe']);
        $this->assertFalse($called['result']['isError'] ?? false, json_encode($called));
        $this->assertSame($movement->public_id, $called['result']['structuredContent']['data'][0]['id']);
        $this->assertSchema($called['result']['structuredContent']);
        $this->artisan('svc:finance:processor-movements', ['--workspace' => $workspace->public_id, '--user' => $owner->public_id, '--format' => 'json'])
            ->expectsOutputToContain($movement->public_id)->assertSuccessful();
    }

    public function test_workspace_capability_is_only_advertised_to_an_authorized_finance_reader(): void
    {
        [$owner, $workspace] = $this->fixture();
        $this->actingAsMcp($owner, ['identity:read', 'finance:read']);
        $response = $this->getJson('/api/v1/context')->assertOk();
        $this->assertContains('finance:processor_movements:read', $response->json('data.workspaces.0.capabilities'));
        $this->assertSame(3, $response->json('data.contract_version'));
        $this->actingAsMcp($owner, ['identity:read']);
        $this->assertNotContains('finance:processor_movements:read', $this->getJson('/api/v1/context')->assertOk()->json('data.workspaces.0.capabilities'));
        $member = User::factory()->create();
        $workspace->memberships()->create(['user_id' => $member->id, 'role' => 'member']);
        $this->actingAsMcp($member, ['identity:read', 'finance:read']);
        $this->assertNotContains('finance:processor_movements:read', $this->getJson('/api/v1/context')->assertOk()->json('data.workspaces.0.capabilities'));
    }

    public function test_subsecond_and_equal_timestamp_rows_are_never_lost_while_paging(): void
    {
        [$owner, $workspace, $payment, $first] = $this->fixture();
        $second = $this->movement($workspace, $payment, 'synthetic_entry_second');
        $third = $this->movement($workspace, $payment, 'synthetic_entry_third');
        foreach ([[$first, '2030-01-20 12:00:00.123455'], [$second, '2030-01-20 12:00:00.123456'], [$third, '2030-01-20 12:00:00.123456']] as [$row, $time]) {
            DB::table('processor_movements')->where('workspace_id', $workspace->id)->where('id', $row->id)->update(['updated_at' => $time]);
        }
        $this->actingAsMcp($owner, ['finance:read']);
        $this->readClock();
        $seen = [];
        $query = ['per_page' => 1];
        for ($page = 0; $page < 3; $page++) {
            $response = $this->getJson($this->path($workspace, $query))->assertOk();
            $seen = [...$seen, ...array_column($response->json('data'), 'id')];
            $cursor = $response->json('meta.next_cursor');
            if ($cursor === null) {
                break;
            }
            $query['cursor'] = $cursor;
        }
        $this->assertSame([$third->public_id, $second->public_id, $first->public_id], $seen);
        $this->assertNull($cursor);
    }

    public function test_watermark_and_inclusive_lower_bound_preserve_microsecond_boundary_rows(): void
    {
        [$owner, $workspace, $payment, $boundary] = $this->fixture();
        $later = $this->movement($workspace, $payment, 'synthetic_entry_later');
        DB::table('processor_movements')->where('workspace_id', $workspace->id)->where('id', $boundary->id)->update(['updated_at' => '2030-01-20 12:03:00.000000']);
        DB::table('processor_movements')->where('workspace_id', $workspace->id)->where('id', $later->id)->update(['updated_at' => '2030-01-20 12:03:00.000001']);
        $this->actingAsMcp($owner, ['finance:read']);
        $this->readClock();
        $first = $this->getJson($this->path($workspace))->assertOk();
        $this->assertSame([$boundary->public_id], array_column($first->json('data'), 'id'));
        $this->travelTo('2030-01-20 12:10:00');
        $next = $this->getJson($this->path($workspace, ['updated_since' => $first->json('meta.watermark')]))->assertOk();
        $this->assertSame([$later->public_id, $boundary->public_id], array_column($next->json('data'), 'id'));
    }

    public function test_cursors_are_bound_to_workspace_and_immutable_filters(): void
    {
        [$owner, $workspace, $payment] = $this->fixture();
        $this->movement($workspace, $payment, 'synthetic_entry_second');
        $this->actingAsMcp($owner, ['finance:read']);
        $this->readClock();
        $cursor = $this->getJson($this->path($workspace, ['per_page' => 1]))->assertOk()->json('meta.next_cursor');
        $this->getJson($this->path($workspace, ['cursor' => $cursor, 'provider' => 'stripe']))->assertUnprocessable();
        [, $foreign] = $this->fixture('foreign');
        $foreign->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
        $this->getJson($this->path($foreign, ['cursor' => $cursor]))->assertUnprocessable();
    }

    #[DataProvider('invalidFilters')]
    public function test_unknown_mutable_and_invalid_filters_are_refused(string $field, mixed $value): void
    {
        [$owner, $workspace] = $this->fixture();
        $this->actingAsMcp($owner, ['finance:read']);
        $this->getJson($this->path($workspace, [$field => $value]))->assertUnprocessable()->assertJsonValidationErrors($field);
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function invalidFilters(): iterable
    {
        foreach (['page', 'needs_review', 'review_reason', 'account_key', 'unknown'] as $field) {
            yield $field => [$field, '1'];
        }
        yield 'provider' => ['provider', 'future-provider'];
        yield 'kind' => ['kind', 'future-kind'];
        yield 'too many rows' => ['per_page', 101];
        yield 'zero rows' => ['per_page', 0];
        yield 'bad payment' => ['payment_id', 'not-a-uuid'];
    }

    public function test_immutable_filters_keep_tenant_isolation_and_reads_do_not_write(): void
    {
        [$owner, $workspace, $payment, $movement] = $this->fixture();
        [, $foreign, , $foreignMovement] = $this->fixture('foreign');
        $otherPayment = $payment->replicate(['public_id', 'provider_payment_identifier']);
        $otherPayment->provider_payment_identifier = 'pi_synthetic_second_payment';
        $otherPayment->save();
        $this->movement($workspace, $otherPayment, 'synthetic_other_payment');
        $beforeMovement = $movement->fresh()->getAttributes();
        $beforePayment = $payment->fresh()->getAttributes();
        $this->actingAsMcp($owner, ['finance:read']);
        $this->readClock();
        $query = ['provider' => 'stripe', 'kind' => 'refund', 'payment_id' => $payment->public_id, 'occurred_from' => '2029-12-31', 'occurred_to' => '2029-12-31'];
        $matched = $this->getJson($this->path($workspace, $query))->assertOk();
        $this->assertSame([$movement->public_id], array_column($matched->json('data'), 'id'));
        foreach ([['provider' => 'square'], ['kind' => 'fee'], ['payment_id' => $foreignMovement->payment->public_id], ['occurred_from' => '2030-01-01'], ['occurred_to' => '2029-12-30']] as $filter) {
            $this->getJson($this->path($workspace, $filter))->assertOk()->assertJsonCount(0, 'data');
        }
        $this->getJson($this->path($workspace, ['occurred_from' => '2030-01-01', 'occurred_to' => '2029-12-31']))->assertUnprocessable();
        $this->getJson($this->path($foreign))->assertNotFound();
        $this->assertSame($beforeMovement, $movement->fresh()->getAttributes());
        $this->assertSame($beforePayment, $payment->fresh()->getAttributes());
        $this->assertSame(3, ProcessorMovement::query()->count());
    }

    public function test_review_conflicts_are_redelivered_without_changing_original_facts(): void
    {
        [$owner, $workspace, $payment, $movement] = $this->fixture();
        $this->actingAsMcp($owner, ['finance:read']);
        $this->readClock();
        $first = $this->getJson($this->path($workspace))->assertOk();
        $this->movement($workspace, $payment, 'synthetic_entry_private', -101);
        $this->travelTo('2030-01-20 12:10:00');
        $next = $this->getJson($this->path($workspace, ['updated_since' => $first->json('meta.watermark')]))->assertOk();
        $this->assertSame($movement->public_id, $next->json('data.0.id'));
        $this->assertNotSame($first->json('data.0.version'), $next->json('data.0.version'));
        $this->assertSame(-100, $next->json('data.0.principal_minor'));
        $this->assertTrue($next->json('data.0.needs_review'));
        $this->assertSame('immutable_facts_changed', $next->json('data.0.review_reason'));
    }

    public function test_public_safe_integer_edges_are_serialized_exactly(): void
    {
        [$owner, $workspace, $payment] = $this->fixture();
        foreach ([-ProcessorMovementFacts::MAX_MINOR, ProcessorMovementFacts::MAX_MINOR] as $minor) {
            $this->movement($workspace, $payment, 'synthetic_edge_'.($minor < 0 ? 'negative' : 'positive'), $minor, 0);
        }
        $this->actingAsMcp($owner, ['finance:read']);
        $this->readClock();
        $response = $this->getJson($this->path($workspace))->assertOk();
        $rows = $response->json('data');
        $this->assertSame(ProcessorMovementFacts::MAX_MINOR, $rows[0]['principal_minor']);
        $this->assertSame(-ProcessorMovementFacts::MAX_MINOR, $rows[1]['principal_minor']);
        $this->assertSchema($response->json());
    }

    /** @return array{User, Workspace, ClientInvoicePayment, ProcessorMovement} */
    private function fixture(string $suffix = 'owned'): array
    {
        $owner = User::factory()->create();
        $workspace = Workspace::query()->create(['name' => 'Synthetic movement workspace', 'slug' => 'synthetic-movement-'.$suffix, 'timezone' => 'America/Los_Angeles']);
        $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic movement company', 'slug' => 'synthetic-movement-'.$suffix]);
        $invoice = ClientInvoice::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'invoice_number' => 'SYNTHETIC-MOVE-'.$suffix, 'currency' => 'USD']);
        $payment = ClientInvoicePayment::query()->create(['workspace_id' => $workspace->id, 'client_invoice_id' => $invoice->id, 'provider' => 'stripe', 'provider_payment_identifier' => 'pi_synthetic_'.$suffix, 'status' => 'succeeded', 'method' => 'card', 'amount' => 1000, 'currency' => 'USD']);
        $movement = $this->movement($workspace, $payment, 'synthetic_entry_private');

        return [$owner, $workspace, $payment, $movement];
    }

    private function movement(Workspace $workspace, ClientInvoicePayment $payment, string $identifier, int $principal = -100, int $fee = -3): ProcessorMovement
    {
        $facts = new ProcessorMovementFacts(provider: 'stripe', identifier: $identifier, paymentIdentifiers: [$payment->provider_payment_identifier], sourceIdentifier: 'synthetic_refund_private',
            kind: $principal < 0 ? ProcessorMovementKind::Refund : ProcessorMovementKind::RefundReversal,
            principalMinor: $principal, feeMinor: $fee, netMinor: $principal - $fee, currency: 'USD', occurredAt: CarbonImmutable::parse('2030-01-01T00:30:00.123456Z'), dateBasis: 'stripe_balance_created');
        app(ProcessorMovementRecorder::class)->record($workspace, $payment->id, $facts, 'synthetic_private_account', true);

        return ProcessorMovement::query()->where('workspace_id', $workspace->id)->where('provider_entry_identifier', $identifier)->firstOrFail();
    }

    private function readClock(): void
    {
        $this->travelTo('2030-01-20 12:05:00');
    }

    /** @param  array<string, mixed>  $query */
    private function path(Workspace $workspace, array $query = []): string
    {
        return '/api/v1/workspaces/'.$workspace->public_id.'/processor-movements'.($query === [] ? '' : '?'.http_build_query($query));
    }

    /** @param  array<string, mixed>  $response */
    private function assertSchema(array $response): void
    {
        $this->assertSame([], (new SchemaValidator)->validateAgainstJsonSchema($response, AgentApiResponseSchemaCatalog::schema('FinanceProcessorMovementListResponse')));
    }
}
