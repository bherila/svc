<?php

namespace Tests\Feature\AgentApi;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientProject;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Support\AgentApi\AgentApiVersion;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\Concerns\CallsMcp;
use Tests\TestCase;

final class DeferredBuyDownParityTest extends TestCase
{
    use CallsMcp, RefreshDatabase;

    private User $owner;

    private Workspace $workspace;

    private ClientAgreement $agreement;

    private ClientTimeEntry $entry;

    protected function setUp(): void
    {
        parent::setUp();
        config(['agent_api.writes_enabled' => true, 'agent_api.invoice_writes_enabled' => true, 'agent_api.client_writes_enabled' => true]);
        $this->travelTo(Carbon::parse('2026-06-15 12:00:00'));
        $this->owner = User::factory()->create(['email' => 'buydown-parity@example.test']);
        $this->workspace = Workspace::query()->create(['name' => 'Synthetic parity', 'slug' => 'buydown-parity']);
        $this->workspace->memberships()->create(['user_id' => $this->owner->id, 'role' => 'owner']);
        $company = ClientCompany::query()->create(['workspace_id' => $this->workspace->id, 'name' => 'Synthetic company', 'slug' => 'buydown-parity']);
        $project = ClientProject::query()->create(['workspace_id' => $this->workspace->id, 'client_company_id' => $company->id, 'name' => 'Synthetic project']);
        $this->agreement = ClientAgreement::query()->create(['workspace_id' => $this->workspace->id, 'client_company_id' => $company->id,
            'title' => 'Synthetic retainer', 'status' => 'active', 'currency' => 'USD', 'billing_cadence' => 'monthly',
            'starts_on' => '2026-01-01', 'hourly_rate_amount' => 10000, 'retainer_minutes' => 600]);
        $this->entry = ClientTimeEntry::query()->create(['workspace_id' => $this->workspace->id, 'client_company_id' => $company->id,
            'client_project_id' => $project->id, 'user_id' => $this->owner->id, 'worked_on' => '2026-01-01', 'minutes' => 60,
            'description' => 'Synthetic deferred work', 'is_billable' => true, 'is_deferred' => true, 'status' => 'approved', 'currency' => 'USD']);
        $this->actingAsMcp($this->owner, ['mcp:use', 'billing:read', 'billing:write', 'clients:write']);
    }

    public function test_rest_mcp_and_cli_preview_agree_and_creation_replays_once(): void
    {
        $preview = $this->getJson($this->base().'/preview?hours=1')->assertOk()->json('data');
        $session = $this->initialize();
        $mcp = $this->callTool($session, 'deferred_backlog.preview', ['workspace_id' => $this->workspace->public_id, 'agreement_id' => $this->agreement->public_id, 'hours' => 1]);
        $this->assertSame($preview, $mcp['result']['structuredContent']['data'] ?? $mcp);
        $exit = Artisan::call('svc:billing:deferred-buydown', ['workspace' => $this->workspace->public_id, 'agreement' => $this->agreement->public_id,
            '--user' => $this->owner->public_id, '--hours' => '1', '--format' => 'json']);
        $this->assertSame(0, $exit);
        $this->assertEquals(['dry_run' => true, 'data' => $preview], json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR));
        $this->assertDatabaseCount('client_invoices', 0);
        $body = ['hours' => 1, 'expected_backlog_version' => $preview['backlog_version']];
        $invoice = $this->postJson($this->base().'/invoices', $body, ['Idempotency-Key' => 'synthetic-buydown-parity'])->assertCreated()->json('data');
        $replay = $this->callTool($session, 'invoices.create_deferred_buydown', ['workspace_id' => $this->workspace->public_id,
            'agreement_id' => $this->agreement->public_id, 'idempotency_key' => 'synthetic-buydown-parity', ...$body]);
        $this->assertFalse($replay['result']['isError'] ?? true, json_encode($replay));
        $this->assertSame($invoice, $replay['result']['structuredContent']['data']);
        $this->assertDatabaseCount('client_invoices', 1);
        $this->assertDatabaseCount('agent_mutation_receipts', 1);
        $this->assertDatabaseHas('agent_mutation_audits', ['operation' => 'invoices.create_deferred_buydown', 'outcome' => 'replay']);
        $this->postJson($this->base().'/invoices', $body, ['Idempotency-Key' => 'synthetic-stale-buydown'])->assertConflict();
        $this->assertDatabaseCount('client_invoices', 1);
    }

    public function test_new_routes_conceal_another_tenant_before_flags_and_scopes(): void
    {
        config(['app.debug' => false]);
        $stranger = User::factory()->create();
        $missing = '/api/v1/workspaces/'.Str::uuid().'/agreements/'.$this->agreement->public_id.'/deferred-backlog';
        foreach ([true, false] as $writes) {
            config(['agent_api.writes_enabled' => $writes]);
            $this->actingAsMcp($stranger, []);
            foreach (['GET' => '/preview?hours=1', 'POST' => '/invoices'] as $method => $suffix) {
                $known = $this->json($method, $this->base().$suffix, [])->assertNotFound();
                $unknown = $this->json($method, $missing.$suffix, [])->assertNotFound();
                $this->assertSame($known->json(), $unknown->json());
            }
        }
        $this->assertDatabaseCount('agent_mutation_receipts', 0);
        $this->assertDatabaseCount('agent_mutation_audits', 0);
    }

    public function test_members_cannot_quote_or_buy_and_unknown_fields_are_refused(): void
    {
        $member = User::factory()->create();
        $this->workspace->memberships()->create(['user_id' => $member->id, 'role' => 'member']);
        $this->actingAsMcp($member, ['mcp:use', 'billing:read', 'billing:write']);
        $this->getJson($this->base().'/preview?hours=1')->assertForbidden();
        $this->postJson($this->base().'/invoices', ['hours' => 1], ['Idempotency-Key' => 'synthetic-forbidden-buydown'])->assertForbidden();
        $this->actingAsMcp($this->owner, ['mcp:use', 'billing:read', 'billing:write']);
        $this->getJson($this->base().'/preview?hours=1&entry_ids=ignored')->assertUnprocessable();
        $this->postJson($this->base().'/invoices', ['hours' => 1], ['Idempotency-Key' => 'synthetic-missing-version'])->assertUnprocessable();
        $this->assertDatabaseCount('client_invoices', 0);
    }

    public function test_portal_opt_in_is_versioned_and_readable_through_the_existing_agreement_operations(): void
    {
        $path = '/api/v1/workspaces/'.$this->workspace->public_id.'/agreements/'.$this->agreement->public_id;
        $this->getJson($path)->assertOk()->assertJsonPath('data.show_deferred_backlog_to_client', false);
        $this->patchJson($path, ['expected_version' => AgentApiVersion::for($this->agreement), 'show_deferred_backlog_to_client' => true],
            ['Idempotency-Key' => 'synthetic-opt-in-backlog'])->assertOk()->assertJsonPath('data.show_deferred_backlog_to_client', true);
        $this->assertTrue($this->agreement->fresh()->show_deferred_backlog_to_client);
    }

    public function test_cli_apply_requires_the_preview_version_and_reuses_its_receipt(): void
    {
        $arguments = ['workspace' => $this->workspace->public_id, 'agreement' => $this->agreement->public_id,
            '--user' => $this->owner->public_id, '--hours' => '1', '--format' => 'json'];
        $this->assertSame(2, Artisan::call('svc:billing:deferred-buydown', $arguments + ['--apply' => true]));
        $this->assertDatabaseCount('client_invoices', 0);
        $this->assertSame(0, Artisan::call('svc:billing:deferred-buydown', $arguments));
        $preview = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['data'];
        $apply = $arguments + ['--apply' => true, '--expected-backlog-version' => $preview['backlog_version'], '--idempotency-key' => 'synthetic-cli-buydown'];
        $this->assertSame(0, Artisan::call('svc:billing:deferred-buydown', $apply));
        $created = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertFalse($created['dry_run']);
        $this->assertSame(0, Artisan::call('svc:billing:deferred-buydown', $apply));
        $this->assertSame($created, json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR));
        $this->assertDatabaseCount('client_invoices', 1);
        $this->assertDatabaseCount('agent_mutation_receipts', 1);
    }

    private function base(): string
    {
        return '/api/v1/workspaces/'.$this->workspace->public_id.'/agreements/'.$this->agreement->public_id.'/deferred-backlog';
    }
}
