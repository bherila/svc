<?php

namespace Tests\Feature\AgentApi;

use App\Models\AgentMutationAudit;
use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientCompanyMembership;
use App\Models\ClientInvoice;
use App\Models\ClientInvoiceLine;
use App\Models\ClientProject;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\InvoiceCorrectionService;
use App\Support\AgentApi\AgentApiResponseSchemaCatalog;
use App\Support\AgentApi\AgentApiVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mcp\Capability\Discovery\SchemaValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CallsMcp;
use Tests\Concerns\WritesLegacyCrossTenantRows;
use Tests\TestCase;

final class AgentInvoiceOperationsParityTest extends TestCase
{
    use CallsMcp;
    use RefreshDatabase;
    use WritesLegacyCrossTenantRows;

    private const array SCOPES = ['mcp:use', 'identity:read', 'billing:read', 'billing:write', 'billing:deliver'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-08 12:00:00');
        config(['app.url' => 'https://svc.synthetic.example.test', 'agent_api.writes_enabled' => true, 'agent_api.invoice_writes_enabled' => true]);
    }

    /** @return iterable<string, array{string}> */
    public static function writes(): iterable
    {
        foreach (['invoices.hold_delivery', 'invoices.release_delivery', 'invoices.add_time', 'invoices.generate_period'] as $operation) {
            yield $operation => [$operation];
        }
    }

    #[DataProvider('writes')]
    public function test_rest_and_mcp_share_idempotent_results_and_audit(string $operation): void
    {
        [$workspace, $owner, $company, $agreement, $invoice, $entry] = $this->fixture();
        $this->actingAsMcp($owner, self::SCOPES);
        [$path, $body, $arguments, $status] = $this->requestFor($operation, $agreement, $invoice, $entry);
        $validator = new SchemaValidator;
        $this->assertSame([], $validator->validateAgainstJsonSchema($body, AgentApiResponseSchemaCatalog::requestForOperation($operation)));
        $first = $this->postJson($this->base($workspace).$path, $body, ['Idempotency-Key' => 'synthetic-shared'])->assertStatus($status)->json();
        $this->assertSame([], $validator->validateAgainstJsonSchema($first, AgentApiResponseSchemaCatalog::forOperation($operation)));
        $session = $this->initialize();
        $retry = $this->callTool($session, $operation, ['workspace_id' => $workspace->public_id, ...$arguments, ...$body, 'idempotency_key' => 'synthetic-shared']);
        $this->assertFalse($retry['result']['isError'] ?? true, json_encode($retry));
        $this->assertSame($first, $retry['result']['structuredContent']);
        $this->assertStringStartsWith('https://svc.synthetic.example.test/workspaces/', $first['data']['web_url']);
        $this->assertSame(1, AgentMutationAudit::query()->where('workspace_id', $workspace->id)->where('operation', $operation)->where('outcome', 'success')->count());
        $this->assertSame(1, AgentMutationAudit::query()->where('workspace_id', $workspace->id)->where('operation', $operation)->where('outcome', 'replay')->count());
        $this->postJson($this->base($workspace).$path, $body, ['Idempotency-Key' => 'synthetic-shared'])->assertStatus($status);
        $this->postJson($this->base($workspace).$path, ['expected_version' => str_repeat('f', 64)] + $body, ['Idempotency-Key' => 'synthetic-shared'])->assertConflict();
        $record = ClientInvoice::query()->where('workspace_id', $workspace->id)->where('public_id', $first['data']['id'])->firstOrFail();
        if ($operation === 'invoices.hold_delivery') {
            $this->assertSame('held', $record->automatic_delivery_status);
            $this->getJson($this->base($workspace).'invoices/'.$record->public_id)->assertOk()->assertJsonPath('data.automatic_delivery_status', 'held')->assertJsonPath('data.automatic_delivery_due_at', $record->automatic_delivery_due_at->toISOString());
        } elseif ($operation === 'invoices.release_delivery') {
            $this->assertSame('scheduled', $record->automatic_delivery_status);
            $this->getJson($this->base($workspace).'invoices/'.$record->public_id)->assertOk()->assertJsonPath('data.automatic_delivery_status', 'scheduled');
        } elseif ($operation === 'invoices.add_time') {
            $this->assertSame(10000, $record->total_amount);
            $this->assertSame(1, $entry->invoiceLines()->count());
        } else {
            $this->assertSame('cadence_period', $record->invoice_kind);
            $this->assertSame('2026-10-01', $record->cycle_start->toDateString());
            $this->assertSame('2026-09-01', $record->service_period_start->toDateString());
            $this->assertSame('2026-09-30', $record->service_period_end->toDateString());
            $this->postJson($this->base($workspace).$path, $body, ['Idempotency-Key' => 'different-key-same-period'])->assertConflict();
        }
    }

    #[DataProvider('writes')]
    public function test_writes_refuse_scope_role_missing_key_stale_version_and_cutovers(string $operation): void
    {
        [$workspace, $owner, , $agreement, $invoice, $entry] = $this->fixture();
        [$path, $body] = $this->requestFor($operation, $agreement, $invoice, $entry);
        $url = $this->base($workspace).$path;
        $this->actingAsMcp($owner, ['billing:read']);
        $this->postJson($url, $body, ['Idempotency-Key' => 'wrong-scope'])->assertForbidden();
        $member = User::factory()->create();
        $workspace->memberships()->create(['user_id' => $member->id, 'role' => 'member']);
        $this->actingAsMcp($member, self::SCOPES);
        $this->postJson($url, $body, ['Idempotency-Key' => 'wrong-role'])->assertForbidden();
        $this->actingAsMcp($owner, self::SCOPES);
        $this->postJson($url, $body)->assertUnprocessable();
        $this->postJson($url, $body + ['status' => 'paid'], ['Idempotency-Key' => 'unexpected-field'])->assertUnprocessable();
        $this->postJson($url, ['expected_version' => str_repeat('0', 64)] + $body, ['Idempotency-Key' => 'stale'])->assertConflict();
        [, , $arguments] = $this->requestFor($operation, $agreement, $invoice, $entry);
        $refusal = $this->callTool($this->initialize(), $operation, ['workspace_id' => $workspace->public_id, ...$arguments, ...$body, 'expected_version' => str_repeat('0', 64), 'idempotency_key' => 'mcp-stale']);
        $this->assertTrue($refusal['result']['isError'] ?? false, json_encode($refusal));
        $withoutVersion = $body;
        unset($withoutVersion['expected_version']);
        $this->postJson($url, $withoutVersion, ['Idempotency-Key' => 'missing-version'])->assertUnprocessable();
        config(['agent_api.invoice_writes_enabled' => false]);
        $this->postJson($url, $body, ['Idempotency-Key' => 'nested-off'])->assertNotFound();
        config(['agent_api.invoice_writes_enabled' => true, 'agent_api.writes_enabled' => false]);
        $this->postJson($url, $body, ['Idempotency-Key' => 'outer-off'])->assertNotFound();
        $this->assertSame(0, AgentMutationAudit::query()->where('outcome', 'success')->count());
    }

    #[DataProvider('writes')]
    public function test_foreign_records_are_never_written(string $operation): void
    {
        [$workspace, $owner] = $this->fixture();
        [$foreign, , , $agreement, $invoice, $entry] = $this->fixture();
        $this->actingAsMcp($owner, self::SCOPES);
        [$path, $body] = $this->requestFor($operation, $agreement, $invoice, $entry);
        $version = AgentApiVersion::for($invoice->fresh());
        $this->postJson($this->base($workspace).$path, $body, ['Idempotency-Key' => 'foreign'])->assertNotFound();
        $this->assertSame($version, AgentApiVersion::for($invoice->fresh()));
        $this->assertSame(1, ClientInvoice::query()->where('workspace_id', $foreign->id)->count());
        $this->assertSame(0, $entry->invoiceLines()->count());
    }

    #[DataProvider('writes')]
    public function test_versioned_writes_require_read_scope_in_rest_and_mcp(string $operation): void
    {
        [$workspace, $owner, , $agreement, $invoice, $entry] = $this->fixture();
        [$path, $body, $arguments] = $this->requestFor($operation, $agreement, $invoice, $entry);
        $this->actingAsMcp($owner, ['mcp:use', 'identity:read', 'billing:write', 'billing:deliver']);
        $this->postJson($this->base($workspace).$path, $body, ['Idempotency-Key' => 'synthetic-without-read'])->assertForbidden();
        $refusal = $this->callTool($this->initialize(), $operation, ['workspace_id' => $workspace->public_id, ...$arguments, ...$body, 'idempotency_key' => 'synthetic-without-read']);
        $this->assertTrue(isset($refusal['error']) || ($refusal['result']['isError'] ?? false), json_encode($refusal));
        $this->assertDatabaseCount('agent_mutation_receipts', 0);
        $this->assertSame(0, $entry->invoiceLines()->count());
    }

    public function test_new_routes_conceal_inaccessible_workspaces_like_missing_workspaces(): void
    {
        config(['app.debug' => false]);
        [, $owner] = $this->fixture();
        [$foreign, , , $agreement, $invoice, $entry] = $this->fixture();
        $this->actingAsMcp($owner, self::SCOPES);
        $missing = Str::uuid()->toString();
        $requests = [['GET', 'invoices/'.$invoice->public_id.'/pdf-link', []], ['GET', 'invoices/'.$invoice->public_id.'/pdf', []], ['GET', 'billing-audits/stale-and-missing', []]];
        foreach (array_keys(iterator_to_array(self::writes())) as $operation) {
            [$path, $body] = $this->requestFor($operation, $agreement, $invoice, $entry);
            $requests[] = ['POST', $path, $body];
        }
        foreach (['full-scopes', 'missing-scopes', 'writes-disabled'] as $mode) {
            $this->actingAsMcp($owner, $mode === 'missing-scopes' ? ['mcp:use'] : self::SCOPES);
            config(['agent_api.writes_enabled' => $mode !== 'writes-disabled', 'agent_api.invoice_writes_enabled' => $mode !== 'writes-disabled']);
            foreach ($requests as [$method, $path, $body]) {
                $existing = $this->json($method, '/api/v1/workspaces/'.$foreign->public_id.'/'.$path, $body, ['Idempotency-Key' => 'synthetic-foreign-'.$mode])->assertNotFound();
                $unknown = $this->json($method, '/api/v1/workspaces/'.$missing.'/'.$path, $body, ['Idempotency-Key' => 'synthetic-missing-'.$mode])->assertNotFound();
                $this->assertSame($existing->json(), $unknown->json());
                $this->assertSame($existing->headers->get('Cache-Control'), $unknown->headers->get('Cache-Control'));
                $this->assertStringContainsString('no-store', $unknown->headers->get('Cache-Control'));
            }
        }
        $this->assertDatabaseCount('agent_mutation_receipts', 0);
    }

    public function test_public_agreement_reads_supply_the_version_for_period_generation_and_replay(): void
    {
        [$workspace, $owner, , $agreement] = $this->fixture();
        $this->actingAsMcp($owner, self::SCOPES);
        $read = $this->getJson($this->base($workspace).'agreements/'.$agreement->public_id)->assertOk()->json('data');
        $this->assertSame($agreement->public_id, $read['id']);
        $this->assertArrayHasKey('version', $read);
        $this->assertIsString($read['version']);
        $this->assertSame(64, strlen($read['version']));
        $listed = $this->getJson($this->base($workspace).'agreements')->assertOk()->json('data.0');
        $this->assertSame($read['version'], $listed['version']);
        $session = $this->initialize();
        $mcpRead = $this->callTool($session, 'agreements.get', ['workspace_id' => $workspace->public_id, 'agreement_id' => $read['id']]);
        $this->assertFalse($mcpRead['result']['isError'] ?? true, json_encode($mcpRead));
        $this->assertSame($read, $mcpRead['result']['structuredContent']['data']);
        $body = ['expected_version' => $read['version'], 'period_start' => '2026-10-01', 'confirm' => true];
        $generated = $this->postJson($this->base($workspace).'agreements/'.$read['id'].'/invoices', $body, ['Idempotency-Key' => 'synthetic-public-parent-version'])->assertCreated()->json();
        $replay = $this->callTool($session, 'invoices.generate_period', ['workspace_id' => $workspace->public_id, 'agreement_id' => $read['id'], ...$body, 'idempotency_key' => 'synthetic-public-parent-version']);
        $this->assertFalse($replay['result']['isError'] ?? true, json_encode($replay));
        $this->assertSame($generated, $replay['result']['structuredContent']);
        $this->assertSame(1, AgentMutationAudit::query()->where('workspace_id', $workspace->id)->where('operation', 'invoices.generate_period')->where('outcome', 'success')->count());
        $this->assertSame(1, AgentMutationAudit::query()->where('workspace_id', $workspace->id)->where('operation', 'invoices.generate_period')->where('outcome', 'replay')->count());
    }

    public function test_release_and_generation_require_confirmation(): void
    {
        [$workspace, $owner, , $agreement, $invoice, $entry] = $this->fixture();
        $this->actingAsMcp($owner, self::SCOPES);
        foreach (['invoices.release_delivery', 'invoices.generate_period'] as $operation) {
            [$path, $body] = $this->requestFor($operation, $agreement, $invoice, $entry);
            foreach ([false, 1, '1', 'yes', 'true', 0, null] as $index => $confirmation) {
                $this->postJson($this->base($workspace).$path, ['confirm' => $confirmation] + $body, ['Idempotency-Key' => 'unconfirmed-'.$operation.'-'.$index])->assertUnprocessable();
            }
        }
    }

    public function test_generated_add_time_regenerates_the_period_and_refuses_outside_work(): void
    {
        [$workspace, $owner, $company, $agreement, $placeholder, $firstEntry] = $this->fixture();
        $firstEntry->delete();
        $this->actingAsMcp($owner, self::SCOPES);
        $body = ['expected_version' => AgentApiVersion::for($agreement), 'period_start' => '2026-10-01', 'confirm' => true];
        $id = $this->postJson($this->base($workspace).'agreements/'.$agreement->public_id.'/invoices', $body, ['Idempotency-Key' => 'generated-before-time'])->assertCreated()->json('data.id');
        $generated = ClientInvoice::query()->where('workspace_id', $workspace->id)->where('public_id', $id)->firstOrFail();
        $entry = $this->entry($workspace, $company, $firstEntry->client_project_id, '2026-09-20');
        $this->postJson($this->base($workspace).'invoices/'.$id.'/time', ['expected_version' => AgentApiVersion::for($generated), 'time_entry_ids' => [$entry->public_id]], ['Idempotency-Key' => 'add-generated'])->assertOk();
        $this->assertSame(1, $entry->invoiceLines()->where('client_invoice_id', $generated->id)->count());
        $outside = $this->entry($workspace, $company, $entry->client_project_id, '2026-10-02');
        $this->postJson($this->base($workspace).'invoices/'.$id.'/time', ['expected_version' => AgentApiVersion::for($generated->fresh()), 'time_entry_ids' => [$outside->public_id]], ['Idempotency-Key' => 'outside'])->assertUnprocessable();
        $this->assertSame(0, $outside->invoiceLines()->count());
    }

    public function test_pdf_link_is_short_lived_authenticated_and_tenant_scoped(): void
    {
        [$workspace, $owner, , , $invoice] = $this->fixture();
        [$foreign, , , , $foreignInvoice] = $this->fixture();
        $this->actingAsMcp($owner, self::SCOPES);
        $session = $this->initialize();
        $result = $this->callTool($session, 'invoices.pdf', ['workspace_id' => $workspace->public_id, 'invoice_id' => $invoice->public_id]);
        $this->assertFalse($result['result']['isError'] ?? true, json_encode($result));
        $url = $result['result']['structuredContent']['data']['url'];
        $this->assertStringStartsWith('https://svc.synthetic.example.test/api/v1/', $url);
        $this->assertStringContainsString('signature=', $url);
        $download = $this->get($url)->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $download->getContent());
        $this->getJson($this->base($workspace).'invoices/'.$foreignInvoice->public_id.'/pdf-link')->assertNotFound();
        $this->getJson($this->base($foreign).'invoices/'.$foreignInvoice->public_id.'/pdf-link')->assertNotFound();
        $this->travel(6)->minutes();
        $this->get($url)->assertForbidden();
    }

    public function test_list_filters_are_shared_by_rest_and_mcp_and_expose_period_facts(): void
    {
        [$workspace, $owner, $company, , $invoice] = $this->fixture();
        [$foreign, , $foreignCompany, , $foreignInvoice] = $this->fixture();
        $facts = ['status' => 'issued', 'invoice_kind' => 'cadence_period', 'issue_date' => '2026-10-01', 'due_date' => '2026-10-07', 'service_period_start' => '2026-09-01', 'service_period_end' => '2026-09-30', 'total_amount' => 1200, 'balance_amount' => 1200];
        $invoice->forceFill($facts)->save();
        $foreignInvoice->forceFill($facts)->save();
        $other = $invoice->replicate(['public_id']);
        $other->forceFill(['invoice_number' => 'SYNTHETIC-SECOND', 'status' => 'paid', 'balance_amount' => 0, 'service_period_start' => null, 'service_period_end' => null])->save();
        $this->actingAsMcp($owner, self::SCOPES);
        $filters = ['company_id' => $company->public_id, 'invoice_kind' => 'cadence_period', 'issue_date_from' => '2026-10-01', 'issue_date_to' => '2026-10-01', 'due_date_from' => '2026-10-07', 'due_date_to' => '2026-10-07', 'service_period_overlaps' => ['from' => '2026-09-30', 'to' => '2026-10-01'], 'collectible' => true, 'overdue' => true];
        $rest = $this->getJson($this->base($workspace).'invoices?'.http_build_query($filters))->assertOk()->json();
        $this->assertSame([$invoice->public_id], array_column($rest['data'], 'id'));
        $this->assertSame($company->name, $rest['data'][0]['company_name']);
        $this->assertSame('2026-09-01', $rest['data'][0]['service_period_start']);
        $this->assertSame('2026-09-30', $rest['data'][0]['service_period_end']);
        $session = $this->initialize();
        $mcp = $this->callTool($session, 'invoices.list', ['workspace_id' => $workspace->public_id, ...$filters]);
        $this->assertFalse($mcp['result']['isError'] ?? true, json_encode($mcp));
        $this->assertSame($rest, $mcp['result']['structuredContent']);
        $detail = $this->getJson($this->base($workspace).'invoices/'.$invoice->public_id)->assertOk()->json('data');
        $this->assertSame($company->name, $detail['company_name']);
        $this->assertSame('2026-09-01', $detail['service_period_start']);
        $this->assertSame([], $this->getJson($this->base($workspace).'invoices?company_id='.$foreignCompany->public_id)->assertOk()->json('data'));
        $this->getJson($this->base($foreign).'invoices')->assertNotFound();
        $this->assertSame([$other->public_id], array_column($this->getJson($this->base($workspace).'invoices?collectible=false&overdue=false')->assertOk()->json('data'), 'id'));
        $this->assertSame(2, count($this->getJson($this->base($workspace).'invoices?due_date_to=2026-10-08')->assertOk()->json('data')));
        $this->getJson($this->base($workspace).'invoices?service_period_overlaps[from]=2026-10-01')->assertUnprocessable();
        $this->getJson($this->base($workspace).'invoices?issue_date_from=2026-10-10&issue_date_to=2026-10-01')->assertUnprocessable();
        $this->getJson($this->base($workspace).'invoices?collectible=invalid')->assertUnprocessable();
        $page = $this->getJson($this->base($workspace).'invoices?limit=1')->assertOk()->json();
        $this->assertNotNull($page['meta']['next_cursor']);
        $this->getJson($this->base($workspace).'invoices?'.http_build_query(['limit' => 1, 'cursor' => $page['meta']['next_cursor'], 'collectible' => true]))->assertUnprocessable();
    }

    public function test_audit_counts_stale_drafts_and_missing_current_periods_without_cross_tenant_disclosure(): void
    {
        [$workspace, $owner, $company, $agreement, $draft] = $this->fixture();
        [$foreign, , , , $foreignDraft] = $this->fixture();
        $draft->forceFill(['due_date' => '2026-10-07', 'balance_amount' => 1200])->save();
        $foreignDraft->forceFill(['due_date' => '2026-10-07', 'balance_amount' => 9900])->save();
        $eur = $draft->replicate(['public_id']);
        $eur->forceFill(['invoice_number' => 'SYNTHETIC-EUR', 'currency' => 'EUR', 'balance_amount' => 700])->save();
        foreach ([['status' => 'issued', 'due_date' => '2026-10-07'], ['status' => 'draft', 'due_date' => '2026-10-08'], ['status' => 'draft', 'due_date' => null]] as $index => $facts) {
            $excluded = $draft->replicate(['public_id']);
            $excluded->forceFill(['invoice_number' => 'SYNTHETIC-EXCLUDED-'.$index, ...$facts])->save();
        }
        $void = $draft->replicate(['public_id']);
        $void->forceFill(['invoice_number' => 'SYNTHETIC-VOID', 'status' => 'void', 'invoice_kind' => 'cadence_period', 'client_agreement_id' => $agreement->id, 'cycle_start' => '2026-10-01'])->save();
        foreach ([['billing_cadence' => 'one_time'], ['status' => 'draft'], ['starts_on' => '2026-11-01'], ['ends_on' => '2026-09-30']] as $facts) {
            $excluded = $agreement->replicate(['public_id']);
            $excluded->forceFill($facts)->save();
        }
        $covered = $agreement->replicate(['public_id']);
        $covered->save();
        $legacy = $draft->replicate(['public_id']);
        $legacy->forceFill(['invoice_number' => 'SYNTHETIC-LEGACY', 'status' => 'issued', 'client_agreement_id' => $covered->id, 'invoice_kind' => null, 'cycle_start' => null, 'service_period_end' => '2026-09-30'])->save();
        $this->actingAsMcp($owner, self::SCOPES);
        $rest = $this->getJson($this->base($workspace).'billing-audits/stale-and-missing')->assertOk()->json();
        $this->assertSame([], (new SchemaValidator)->validateAgainstJsonSchema($rest, AgentApiResponseSchemaCatalog::forOperation('billing_audit.stale_and_missing')));
        $this->assertSame(2, $rest['data']['stale_draft_count']);
        $this->assertSame([['currency' => 'EUR', 'balance_amount' => 700], ['currency' => 'USD', 'balance_amount' => 1200]], $rest['data']['stale_draft_balances']);
        $this->assertSame([$draft->public_id, $eur->public_id], $rest['data']['stale_draft_ids']);
        $this->assertSame(1, $rest['data']['missing_period_count']);
        $this->assertSame([['agreement_id' => $agreement->public_id, 'period_start' => '2026-10-01', 'period_end' => '2026-10-31']], $rest['data']['missing_periods']);
        $session = $this->initialize();
        $mcp = $this->callTool($session, 'billing_audit.stale_and_missing', ['workspace_id' => $workspace->public_id]);
        $this->assertSame($rest, $mcp['result']['structuredContent']);
        $this->getJson($this->base($foreign).'billing-audits/stale-and-missing')->assertNotFound();
        $member = User::factory()->create();
        $workspace->memberships()->create(['user_id' => $member->id, 'role' => 'member']);
        $this->actingAsMcp($member, self::SCOPES);
        $this->getJson($this->base($workspace).'billing-audits/stale-and-missing')->assertNotFound();
        $this->assertNotContains('billing_audit.stale_and_missing', $this->toolNames($this->initialize()));
        $this->actingAsMcp($owner, ['identity:read']);
        $this->getJson($this->base($workspace).'billing-audits/stale-and-missing')->assertForbidden();
    }

    public function test_audit_identifier_lists_are_bounded_while_totals_are_complete(): void
    {
        [$workspace, $owner, , $agreement, $draft] = $this->fixture();
        for ($index = 0; $index < 201; $index++) {
            $copy = $draft->replicate(['public_id']);
            $copy->forceFill(['invoice_number' => 'SYNTHETIC-BOUNDED-'.$index, 'due_date' => '2026-10-07', 'balance_amount' => 10])->save();
            $copy = $agreement->replicate(['public_id']);
            $copy->save();
        }
        $this->actingAsMcp($owner, self::SCOPES);
        DB::enableQueryLog();
        $result = $this->getJson($this->base($workspace).'billing-audits/stale-and-missing')->assertOk()->json('data');
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertSame(201, $result['stale_draft_count']);
        $this->assertSame(202, $result['missing_period_count']);
        $this->assertCount(100, $result['stale_draft_ids']);
        $this->assertCount(100, $result['missing_periods']);
        $this->assertTrue($result['stale_draft_ids_truncated']);
        $this->assertTrue($result['missing_periods_truncated']);
        $invoiceQueries = array_filter($queries, fn (array $query): bool => str_contains($query['query'], 'from "client_invoices"'));
        $this->assertLessThanOrEqual(5, count($invoiceQueries));
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidPeriods(): iterable
    {
        yield 'wrong boundary' => [[], '2026-10-02'];
        yield 'future period' => [[], '2026-11-01'];
        yield 'before agreement' => [[], '2025-12-01'];
        yield 'quarter before agreement' => [['billing_cadence' => 'quarterly', 'starts_on' => '2026-01-15'], '2026-01-01'];
        yield 'terminated' => [['status' => 'terminated'], '2026-10-01'];
        yield 'one time' => [['billing_cadence' => 'one_time'], '2026-10-01'];
        yield 'not begun' => [['starts_on' => '2026-10-15'], '2026-10-01'];
        yield 'after end' => [['ends_on' => '2026-09-30'], '2026-10-01'];
    }

    #[DataProvider('invalidPeriods')]
    public function test_generation_refuses_invalid_periods_without_persistence(array $facts, string $start): void
    {
        [$workspace, $owner, , $agreement] = $this->fixture();
        $agreement->forceFill($facts)->save();
        $this->actingAsMcp($owner, self::SCOPES);
        $this->postJson($this->base($workspace).'agreements/'.$agreement->public_id.'/invoices', ['expected_version' => AgentApiVersion::for($agreement), 'period_start' => $start, 'confirm' => true], ['Idempotency-Key' => 'invalid-period'])->assertUnprocessable();
        $this->assertSame(1, ClientInvoice::query()->where('workspace_id', $workspace->id)->count());
        $this->assertSame(0, AgentMutationAudit::query()->where('operation', 'invoices.generate_period')->where('outcome', 'success')->count());
    }

    /** @return iterable<string, array{string, string, string, string, string}> */
    public static function cadencePeriods(): iterable
    {
        yield 'monthly first partial' => ['monthly', '2026-10-05', '2026-10-01', '2026-09-01', '2026-09-30'];
        yield 'quarterly anchored' => ['quarterly', '2026-01-15', '2026-07-15', '2026-04-15', '2026-07-14'];
        yield 'annual anchored' => ['annual', '2025-01-01', '2026-01-01', '2025-01-01', '2025-12-31'];
    }

    #[DataProvider('cadencePeriods')]
    public function test_generation_uses_native_cadence_and_allows_a_new_draft_after_void(string $cadence, string $starts, string $period, string $serviceStart, string $serviceEnd): void
    {
        [$workspace, $owner, , $agreement] = $this->fixture();
        $agreement->forceFill(['billing_cadence' => $cadence, 'starts_on' => $starts, 'period_retainer_minutes' => 1800, 'period_retainer_amount' => 300000])->save();
        $this->actingAsMcp($owner, self::SCOPES);
        $body = ['expected_version' => AgentApiVersion::for($agreement), 'period_start' => $period, 'confirm' => true];
        $url = $this->base($workspace).'agreements/'.$agreement->public_id.'/invoices';
        $id = $this->postJson($url, $body, ['Idempotency-Key' => 'native-period'])->assertCreated()->json('data.id');
        $draft = ClientInvoice::query()->where('workspace_id', $workspace->id)->where('public_id', $id)->firstOrFail();
        $this->assertSame($period, $draft->cycle_start->toDateString());
        $this->assertSame($serviceStart, $draft->service_period_start->toDateString());
        $this->assertSame($serviceEnd, $draft->service_period_end->toDateString());
        $this->assertSame('draft', $draft->status);
        $draft->forceFill(['status' => 'void'])->save();
        $next = $this->postJson($url, $body, ['Idempotency-Key' => 'after-void'])->assertCreated()->json('data.id');
        $this->assertNotSame($id, $next);
    }

    public function test_reads_and_pdf_obey_current_portal_visibility_and_membership(): void
    {
        [$workspace, , $company, , $invoice] = $this->fixture();
        $portal = User::factory()->create();
        ClientCompanyMembership::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'user_id' => $portal->id, 'role' => 'client', 'access_scope' => 'company']);
        $invoice->forceFill(['status' => 'issued', 'automatic_delivery_status' => 'held', 'automatic_delivery_due_at' => now()->addDay(), 'is_visible_to_client' => true, 'service_period_start' => '2026-09-01', 'service_period_end' => '2026-09-30'])->save();
        $hidden = $invoice->replicate(['public_id']);
        $hidden->forceFill(['invoice_number' => 'SYNTHETIC-HIDDEN', 'is_visible_to_client' => false])->save();
        $draft = $invoice->replicate(['public_id']);
        $draft->forceFill(['invoice_number' => 'SYNTHETIC-DRAFT', 'status' => 'draft'])->save();
        $this->actingAsMcp($portal, self::SCOPES);
        $this->assertSame([$invoice->public_id], array_column($this->getJson($this->base($workspace).'invoices?service_period_overlaps[from]=2026-09-30&service_period_overlaps[to]=2026-10-01')->assertOk()->json('data'), 'id'));
        $url = $this->getJson($this->base($workspace).'invoices/'.$invoice->public_id.'/pdf-link')->assertOk()->json('data.url');
        $this->get($url)->assertOk();
        $this->getJson($this->base($workspace).'invoices/'.$invoice->public_id)->assertOk()->assertJsonPath('data.automatic_delivery_status', null)->assertJsonPath('data.automatic_delivery_due_at', null);
        $this->getJson($this->base($workspace).'invoices/'.$hidden->public_id.'/pdf-link')->assertNotFound();
        $this->getJson($this->base($workspace).'invoices/'.$draft->public_id.'/pdf-link')->assertNotFound();
        $this->get($url.'&extra=tampered')->assertForbidden();
        $invoice->forceFill(['is_visible_to_client' => false])->save();
        $this->get($url)->assertNotFound();
        $invoice->forceFill(['is_visible_to_client' => true])->save();
        ClientCompanyMembership::query()->where('workspace_id', $workspace->id)->where('user_id', $portal->id)->delete();
        $this->get($url)->assertNotFound();
    }

    public function test_generation_reports_canonical_overlap_refusal_and_rolls_back(): void
    {
        [$workspace, $owner, , $agreement, $invoice] = $this->fixture();
        $invoice->forceFill(['status' => 'issued', 'invoice_kind' => 'cadence_period', 'service_period_start' => '2026-09-10', 'service_period_end' => '2026-09-15'])->save();
        $this->actingAsMcp($owner, self::SCOPES);
        $this->postJson($this->base($workspace).'agreements/'.$agreement->public_id.'/invoices', ['expected_version' => AgentApiVersion::for($agreement), 'period_start' => '2026-10-01', 'confirm' => true], ['Idempotency-Key' => 'canonical-overlap'])->assertUnprocessable();
        $this->assertSame(1, ClientInvoice::query()->where('workspace_id', $workspace->id)->count());
        $this->assertSame(0, AgentMutationAudit::query()->where('operation', 'invoices.generate_period')->where('outcome', 'success')->count());
    }

    public function test_browser_session_can_read_pdf_and_audit_and_hold_delivery_with_agent_cutovers_off(): void
    {
        [$workspace, $owner, , , $invoice] = $this->fixture();
        $invoice->forceFill(['status' => 'issued', 'automatic_delivery_status' => 'scheduled', 'automatic_delivery_due_at' => now()->addDay()])->save();
        config(['agent_api.writes_enabled' => false, 'agent_api.invoice_writes_enabled' => false]);
        $this->actingAs($owner)->withCredentials()->withUnencryptedCookie((string) config('session.cookie'), Str::random(40));
        $url = $this->getJson($this->base($workspace).'invoices/'.$invoice->public_id.'/pdf-link')->assertOk()->json('data.url');
        $this->get($url)->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->getJson($this->base($workspace).'billing-audits/stale-and-missing')->assertOk();
        $this->postJson($this->base($workspace).'invoices/'.$invoice->public_id.'/automatic-delivery/hold', ['expected_version' => AgentApiVersion::for($invoice)], ['Idempotency-Key' => 'browser-hold'])->assertOk();
        $this->assertSame('held', $invoice->fresh()->automatic_delivery_status);
    }

    public function test_list_get_and_pdf_ignore_malformed_foreign_company_and_line_references(): void
    {
        [$workspace, $owner, , , $invoice] = $this->fixture();
        [$foreign, , $foreignCompany] = $this->fixture();
        $malformed = $invoice->replicate(['public_id']);
        $this->writingLegacyCrossTenantRows(function () use ($malformed, $invoice, $foreign, $foreignCompany): void {
            $malformed->forceFill(['invoice_number' => 'SYNTHETIC-BAD-COMPANY', 'client_company_id' => $foreignCompany->id])->save();
            ClientInvoiceLine::query()->create(['workspace_id' => $foreign->id, 'client_invoice_id' => $invoice->id, 'type' => 'service', 'description' => 'Synthetic foreign line', 'quantity' => '1', 'unit_amount' => 999, 'total_amount' => 999, 'sort_order' => 1]);
        });
        $this->actingAsMcp($owner, self::SCOPES);
        $this->assertSame([$invoice->public_id], array_column($this->getJson($this->base($workspace).'invoices')->assertOk()->json('data'), 'id'));
        $this->getJson($this->base($workspace).'invoices/'.$malformed->public_id)->assertNotFound();
        $this->getJson($this->base($workspace).'invoices/'.$malformed->public_id.'/pdf-link')->assertNotFound();
        $this->assertSame([], $this->getJson($this->base($workspace).'invoices/'.$invoice->public_id)->assertOk()->json('data.lines'));
        $url = $this->getJson($this->base($workspace).'invoices/'.$invoice->public_id.'/pdf-link')->assertOk()->json('data.url');
        $this->get($url)->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_period_boundaries_use_workspace_calendar_dates_in_both_timezone_directions(): void
    {
        [$workspace, $owner, , $agreement] = $this->fixture();
        $workspace->forceFill(['timezone' => 'Pacific/Kiritimati'])->save();
        $agreement->forceFill(['starts_on' => '2026-10-09', 'billing_cadence' => 'quarterly', 'period_retainer_minutes' => 1800, 'period_retainer_amount' => 300000])->save();
        $this->actingAsMcp($owner, self::SCOPES);
        $this->postJson($this->base($workspace).'agreements/'.$agreement->public_id.'/invoices', ['expected_version' => AgentApiVersion::for($agreement), 'period_start' => '2026-10-09', 'confirm' => true], ['Idempotency-Key' => 'workspace-today'])->assertCreated();
        $workspace->forceFill(['timezone' => 'America/Los_Angeles'])->save();
        $agreement->forceFill(['starts_on' => '2026-01-09'])->save();
        $period = $this->getJson($this->base($workspace).'billing-audits/stale-and-missing')->assertOk()->json('data.missing_periods.0');
        $this->assertSame('2026-07-09', $period['period_start']);
        $this->assertSame('2026-10-08', $period['period_end']);
    }

    public function test_web_delivery_hold_invalidates_an_agent_version_and_revoked_manager_cannot_replay(): void
    {
        [$workspace, $owner, , , $invoice] = $this->fixture();
        $invoice->forceFill(['status' => 'issued', 'automatic_delivery_status' => 'scheduled', 'automatic_delivery_due_at' => now()->addDay()])->save();
        $old = AgentApiVersion::for($invoice);
        app(InvoiceCorrectionService::class)->hold($invoice, $workspace);
        $this->assertNotSame($old, AgentApiVersion::for($invoice->fresh()));
        $this->actingAsMcp($owner, self::SCOPES);
        $url = $this->base($workspace).'invoices/'.$invoice->public_id.'/automatic-delivery/release';
        $this->postJson($url, ['expected_version' => $old, 'confirm' => true], ['Idempotency-Key' => 'web-moved'])->assertConflict();
        $body = ['expected_version' => AgentApiVersion::for($invoice->fresh()), 'confirm' => true];
        $this->postJson($url, $body, ['Idempotency-Key' => 'release-before-revocation'])->assertOk();
        $workspace->memberships()->where('user_id', $owner->id)->update(['role' => 'member']);
        $this->postJson($url, $body, ['Idempotency-Key' => 'release-before-revocation'])->assertForbidden();
        $this->assertSame(0, AgentMutationAudit::query()->where('operation', 'invoices.release_delivery')->where('outcome', 'replay')->count());
    }

    /** @return iterable<string, array{string, bool}> */
    public static function discoveryRoles(): iterable
    {
        yield 'member' => ['member', false];
        yield 'owner' => ['owner', true];
        yield 'admin' => ['admin', true];
    }

    #[DataProvider('discoveryRoles')]
    public function test_new_invoice_write_discovery_matches_manager_authorization(string $role, bool $isManager): void
    {
        [$workspace, $actor] = $this->fixture();
        $workspace->memberships()->where('user_id', $actor->id)->update(['role' => $role]);
        $this->actingAsMcp($actor, self::SCOPES);
        $names = $this->toolNames($this->initialize());
        foreach (array_keys(iterator_to_array(self::writes())) as $operation) {
            if ($isManager) {
                $this->assertContains($operation, $names);
            } else {
                $this->assertNotContains($operation, $names);
            }
        }
        foreach (['invoices.list', 'invoices.get', 'invoices.pdf'] as $read) {
            $this->assertContains($read, $names);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function existingConfirmedOperations(): iterable
    {
        foreach (['issue', 'send', 'void', 'discard', 'correct'] as $operation) {
            yield $operation => [$operation];
        }
    }

    #[DataProvider('existingConfirmedOperations')]
    public function test_existing_irreversible_invoice_routes_require_literal_boolean_confirmation(string $operation): void
    {
        [$workspace, $owner, , , $invoice] = $this->fixture();
        if (! in_array($operation, ['issue', 'discard'], true)) {
            $invoice->forceFill(['status' => 'issued'])->save();
        }
        $version = AgentApiVersion::for($invoice);
        $this->actingAsMcp($owner, self::SCOPES);
        $url = $this->base($workspace).'invoices/'.$invoice->public_id.'/'.$operation;
        foreach ([false, 1, '1', 'yes', 'true', 0, null] as $index => $confirmation) {
            $this->postJson($url, ['expected_version' => $version, 'confirm' => $confirmation, 'reason' => 'Synthetic confirmation refusal', 'due_date' => '2026-10-30', 'recipients' => ['billing@synthetic.example.test']], ['Idempotency-Key' => 'existing-confirm-'.$operation.'-'.$index])->assertUnprocessable();
        }
        $this->assertSame($version, AgentApiVersion::for($invoice->fresh()));
        $this->assertSame(0, AgentMutationAudit::query()->where('outcome', 'success')->count());
    }

    /** @return array{Workspace, User, ClientCompany, ClientAgreement, ClientInvoice, ClientTimeEntry} */
    private function fixture(): array
    {
        $owner = User::factory()->create();
        $workspace = Workspace::query()->create(['name' => 'Synthetic Invoice API', 'slug' => 'synthetic-invoice-api-'.uniqid()]);
        $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic Invoice Client', 'slug' => 'synthetic-client', 'billing_email' => 'billing@synthetic.example.test']);
        $project = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Synthetic Project']);
        $agreement = ClientAgreement::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'title' => 'Synthetic Monthly', 'status' => 'active', 'starts_on' => '2026-01-01', 'billing_cadence' => 'monthly', 'currency' => 'USD', 'hourly_rate_amount' => 10000, 'retainer_minutes' => 600, 'retainer_amount' => 100000]);
        $invoice = ClientInvoice::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'invoice_number' => 'SYNTHETIC-API-001', 'invoice_kind' => 'ad_hoc', 'status' => 'draft', 'currency' => 'USD', 'total_amount' => 0, 'balance_amount' => 0]);

        return [$workspace, $owner, $company, $agreement, $invoice, $this->entry($workspace, $company, $project->id, '2026-09-10')];
    }

    private function entry(Workspace $workspace, ClientCompany $company, int $project, string $date): ClientTimeEntry
    {
        return ClientTimeEntry::query()->create(['workspace_id' => $workspace->id, 'user_id' => $workspace->memberships()->where('role', 'owner')->value('user_id'), 'client_company_id' => $company->id, 'client_project_id' => $project, 'worked_on' => $date, 'minutes' => 60, 'description' => 'Synthetic approved work', 'status' => 'approved', 'is_billable' => true, 'is_deferred' => false, 'billing_rate_amount' => 10000, 'currency' => 'USD']);
    }

    /** @return array{string, array<string, mixed>, array<string, mixed>, int} */
    private function requestFor(string $operation, ClientAgreement $agreement, ClientInvoice $invoice, ClientTimeEntry $entry): array
    {
        if (in_array($operation, ['invoices.hold_delivery', 'invoices.release_delivery'], true)) {
            $invoice->forceFill(['status' => 'issued', 'automatic_delivery_status' => $operation === 'invoices.hold_delivery' ? 'scheduled' : 'held', 'automatic_delivery_due_at' => now()->addDay()])->save();
        }
        $version = ['expected_version' => AgentApiVersion::for($invoice)];
        $id = ['invoice_id' => $invoice->public_id];

        return match ($operation) {
            'invoices.hold_delivery' => ['invoices/'.$invoice->public_id.'/automatic-delivery/hold', $version, $id, 200],
            'invoices.release_delivery' => ['invoices/'.$invoice->public_id.'/automatic-delivery/release', $version + ['confirm' => true], $id, 200],
            'invoices.add_time' => ['invoices/'.$invoice->public_id.'/time', $version + ['time_entry_ids' => [$entry->public_id]], $id, 200],
            'invoices.generate_period' => ['agreements/'.$agreement->public_id.'/invoices', ['expected_version' => AgentApiVersion::for($agreement), 'period_start' => '2026-10-01', 'confirm' => true], ['agreement_id' => $agreement->public_id], 201],
            default => throw new \LogicException('Unknown synthetic operation'),
        };
    }

    private function base(Workspace $workspace): string
    {
        return '/api/v1/workspaces/'.$workspace->public_id.'/';
    }
}
