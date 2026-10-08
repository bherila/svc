<?php

namespace Tests\Feature\AgentApi;

use App\Models\ClientAgreement;
use App\Models\ClientBillingSchedule;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Support\AgentApi\AgentApiVersion;
use Carbon\Carbon;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CallsMcp;
use Tests\TestCase;

final class AgentBillingScheduleParityTest extends TestCase
{
    use CallsMcp, RefreshDatabase;

    private User $owner;

    private Workspace $workspace;

    private ClientCompany $company;

    private ClientAgreement $agreement;

    protected function setUp(): void
    {
        parent::setUp();
        URL::forceRootUrl('http://localhost');
        config(['app.url' => 'http://localhost', 'agent_api.writes_enabled' => true, 'agent_api.invoice_writes_enabled' => true]);
        $this->travelTo(Carbon::parse('2026-10-08 12:00:00'));
        $this->owner = User::factory()->create();
        $this->workspace = Workspace::query()->create(['name' => 'Synthetic schedules', 'slug' => 'synthetic-schedules']);
        WorkspaceMembership::query()->create(['workspace_id' => $this->workspace->id, 'user_id' => $this->owner->id, 'role' => 'owner']);
        $this->company = ClientCompany::query()->create(['workspace_id' => $this->workspace->id, 'name' => 'Synthetic client', 'slug' => 'synthetic-client']);
        $this->agreement = ClientAgreement::query()->create(['workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id, 'title' => 'Synthetic agreement', 'currency' => 'USD', 'billing_cadence' => 'monthly', 'status' => 'active', 'starts_on' => '2026-01-01']);
        $this->actingAsMcp($this->owner, ['mcp:use', 'billing:read', 'billing:write', 'billing:deliver']);
    }

    public function test_public_agreement_revision_can_create_a_schedule_and_replay_through_mcp(): void
    {
        $path = '/api/v1/workspaces/'.$this->workspace->public_id.'/agreements/'.$this->agreement->public_id;
        $read = $this->getJson($path)->assertOk()->json('data');
        $this->assertIsString($read['version']);
        $session = $this->initialize();
        $mcp = $this->callTool($session, 'agreements.get', ['workspace_id' => $this->workspace->public_id, 'agreement_id' => $this->agreement->public_id]);
        $this->assertSame($read, $mcp['result']['structuredContent']['data']);
        $body = ['expected_version' => $read['version']] + $this->body();
        $created = $this->postJson($this->base(), $body, ['Idempotency-Key' => 'synthetic-public-agreement-revision'])->assertCreated()->json('data');
        $replay = $this->callTool($session, 'billing_schedules.create', ['workspace_id' => $this->workspace->public_id, 'idempotency_key' => 'synthetic-public-agreement-revision', ...$body]);
        $this->assertFalse($replay['result']['isError'] ?? true, json_encode($replay));
        $this->assertSame($created, $replay['result']['structuredContent']['data']);
        $this->assertDatabaseCount('client_billing_schedules', 1);
        foreach (['success', 'replay'] as $outcome) {
            $this->assertDatabaseHas('agent_mutation_audits', ['operation' => 'billing_schedules.create', 'outcome' => $outcome]);
        }
    }

    public function test_schedule_workspace_refusals_match_without_scopes_or_enabled_writes(): void
    {
        config(['app.debug' => false]);
        $stranger = User::factory()->create();
        $missing = Str::uuid()->toString();
        $schedule = $this->schedule();
        $requests = [['GET', '', []], ['GET', '/'.$schedule->public_id, []], ['POST', '', $this->body()], ['POST', '/'.$schedule->public_id.'/generate', ['expected_version' => AgentApiVersion::for($schedule), 'confirm' => true]]];
        foreach ([[['billing:read', 'billing:write', 'billing:deliver'], true], [[], true], [['billing:read', 'billing:write', 'billing:deliver'], false]] as [$scopes, $writes]) {
            $this->actingAsMcp($stranger, $scopes);
            config(['agent_api.writes_enabled' => $writes, 'agent_api.invoice_writes_enabled' => $writes]);
            foreach ($requests as [$method, $suffix, $body]) {
                $known = $this->json($method, $this->base().$suffix, $body)->assertNotFound();
                $unknown = $this->json($method, '/api/v1/workspaces/'.$missing.'/billing-schedules'.$suffix, $body)->assertNotFound();
                $this->assertSame($known->json(), $unknown->json());
                $this->assertSame($known->headers->get('Cache-Control'), $unknown->headers->get('Cache-Control'));
                $this->assertStringContainsString('no-store', $unknown->headers->get('Cache-Control'));
            }
        }
        $this->assertDatabaseCount('agent_mutation_receipts', 0);
    }

    public function test_schedule_writes_require_readable_revisions(): void
    {
        $schedule = $this->schedule();
        $this->actingAsMcp($this->owner, ['mcp:use', 'billing:write', 'billing:deliver']);
        $this->postJson($this->base(), $this->body(), ['Idempotency-Key' => 'synthetic-unreadable-create'])->assertForbidden();
        $this->postJson($this->base().'/'.$schedule->public_id.'/generate', ['expected_version' => AgentApiVersion::for($schedule), 'confirm' => true], ['Idempotency-Key' => 'synthetic-unreadable-generate'])->assertForbidden();
        $session = $this->initialize();
        $this->assertNotContains('billing_schedules.create', $this->toolNames($session));
        $this->assertNotContains('billing_schedules.generate', $this->toolNames($session));
        $this->assertDatabaseCount('agent_mutation_receipts', 0);
    }

    public function test_rest_create_and_mcp_retry_share_one_receipt_and_audit(): void
    {
        $body = $this->body();
        $first = $this->withHeader('Idempotency-Key', 'synthetic-create')->postJson($this->base(), $body)->assertCreated()->json('data');
        $session = $this->initialize();
        $result = $this->callTool($session, 'billing_schedules.create', ['workspace_id' => $this->workspace->public_id, 'idempotency_key' => 'synthetic-create', ...$body]);
        $this->assertFalse($result['result']['isError'] ?? true, json_encode($result));
        $this->assertSame($first, $result['result']['structuredContent']['data']);
        $this->assertDatabaseCount('client_billing_schedules', 1);
        $this->assertDatabaseCount('agent_mutation_receipts', 1);
        $this->assertDatabaseHas('agent_mutation_audits', ['operation' => 'billing_schedules.create', 'outcome' => 'success']);
        $this->assertDatabaseHas('agent_mutation_audits', ['operation' => 'billing_schedules.create', 'outcome' => 'replay']);
        $this->withHeader('Idempotency-Key', 'synthetic-create')->postJson($this->base(), [...$body, 'due_days' => 31])->assertConflict();
        $this->withHeader('Idempotency-Key', 'another-create')->postJson($this->base(), $body)->assertConflict()->assertJsonPath('message', 'This agreement already has a billing schedule; read it before generating invoices.');
    }

    public function test_a_real_agreement_unique_collision_is_a_conflict_and_rolls_back_the_receipt(): void
    {
        $armed = true;
        DB::listen(function (QueryExecuted $query) use (&$armed): void {
            if (! $armed || ! str_starts_with($query->sql, 'select exists(') || ! str_contains($query->sql, 'client_billing_schedules')) {
                return;
            }
            // The probe has already returned false. Force a real database
            // constraint failure at insertion, rather than mocking an exception.
            $armed = false;
            $this->schedule();
        });
        $this->postJson($this->base(), $this->body(), ['Idempotency-Key' => 'synthetic-colliding-create'])
            ->assertConflict()->assertJsonPath('message', 'This agreement already has a billing schedule; read it before generating invoices.');
        $this->assertFalse($armed);
        $this->assertDatabaseCount('client_billing_schedules', 0);
        $this->assertDatabaseCount('agent_mutation_receipts', 0);
        $this->assertDatabaseHas('agent_mutation_audits', ['operation' => 'billing_schedules.create', 'outcome' => 'failed', 'error_category' => 'conflict']);
        $this->postJson($this->base(), $this->body(), ['Idempotency-Key' => 'synthetic-colliding-create'])->assertCreated();
        $this->assertDatabaseCount('client_billing_schedules', 1);
        $this->assertDatabaseCount('agent_mutation_receipts', 1);
    }

    public function test_an_unrelated_public_id_unique_collision_is_not_misclassified_as_an_agreement_conflict(): void
    {
        config(['app.debug' => false]);
        $otherAgreement = $this->agreement->replicate(['public_id']);
        $otherAgreement->save();
        $existing = $this->schedule(['client_agreement_id' => $otherAgreement->id]);
        ClientBillingSchedule::creating(function (ClientBillingSchedule $schedule) use ($existing): void {
            $schedule->setAttribute('public_id', $existing->public_id);
        });
        $this->postJson($this->base(), $this->body(), ['Idempotency-Key' => 'synthetic-unrelated-collision'])->assertInternalServerError();
        $this->assertDatabaseCount('client_billing_schedules', 1);
        $this->assertDatabaseCount('agent_mutation_receipts', 0);
        $this->assertDatabaseHas('agent_mutation_audits', ['operation' => 'billing_schedules.create', 'outcome' => 'failed', 'error_category' => 'internal']);
    }

    public static function named_create_constraints(): iterable
    {
        yield 'MariaDB named agreement key' => ['billing_schedule_agreement_unique', [], 409, 'conflict'];
        yield 'PostgreSQL named agreement key' => ['billing_schedule_agreement_unique', ['workspace_id', 'client_agreement_id'], 409, 'conflict'];
        yield 'another named key must not fall back to columns' => ['synthetic_unrelated_unique', ['workspace_id', 'client_agreement_id'], 500, 'internal'];
    }

    #[DataProvider('named_create_constraints')]
    public function test_named_create_constraint_metadata_is_classified_narrowly(string $index, array $columns, int $status, string $category): void
    {
        config(['app.debug' => false]);
        $collision = (new UniqueConstraintViolationException(DB::getDefaultConnection(), 'insert into client_billing_schedules', [], new \PDOException('Synthetic named constraint failure')))
            ->setIndex($index)->setColumns($columns);
        ClientBillingSchedule::creating(static fn () => throw $collision);
        $this->postJson($this->base(), $this->body(), ['Idempotency-Key' => 'synthetic-named-collision'])->assertStatus($status);
        $this->assertDatabaseCount('client_billing_schedules', 0);
        $this->assertDatabaseCount('agent_mutation_receipts', 0);
        $this->assertDatabaseHas('agent_mutation_audits', ['operation' => 'billing_schedules.create', 'outcome' => 'failed', 'error_category' => $category]);
    }

    public function test_generate_issues_due_invoices_and_replays_across_transports(): void
    {
        $schedule = $this->schedule();
        $body = ['expected_version' => AgentApiVersion::for($schedule), 'confirm' => true];
        $session = $this->initialize();
        $result = $this->callTool($session, 'billing_schedules.generate', ['workspace_id' => $this->workspace->public_id, 'schedule_id' => $schedule->public_id, 'idempotency_key' => 'synthetic-generate', ...$body]);
        $this->assertFalse($result['result']['isError'] ?? true, json_encode($result));
        $data = $result['result']['structuredContent']['data'];
        $this->assertCount(1, $data['invoices']);
        $this->assertSame('issued', $data['invoices'][0]['status']);
        $this->assertSame('2026-11-01', $data['schedule']['next_run_on']);
        $this->assertNotSame($body['expected_version'], $data['schedule']['version']);
        $this->withHeader('Idempotency-Key', 'synthetic-generate')->postJson($this->base().'/'.$schedule->public_id.'/generate', $body)->assertOk()->assertExactJson(['data' => $data]);
        $this->assertDatabaseCount('client_invoices', 1);
        $invoice = ClientInvoice::query()->sole();
        $this->assertSame($this->workspace->id, $invoice->workspace_id);
        $this->assertSame($schedule->id, $invoice->client_billing_schedule_id);
        $this->assertSame(10000, $invoice->total_amount);
        $this->assertDatabaseHas('agent_mutation_audits', ['operation' => 'billing_schedules.generate', 'outcome' => 'success']);
        $this->assertDatabaseHas('agent_mutation_audits', ['operation' => 'billing_schedules.generate', 'outcome' => 'replay']);
    }

    public function test_stale_versions_and_unconfirmed_generation_write_nothing(): void
    {
        $body = $this->body();
        $this->agreement->forceFill(['title' => 'Synthetic newer agreement'])->save();
        $this->withHeader('Idempotency-Key', 'stale-parent')->postJson($this->base(), $body)->assertConflict();
        $this->assertDatabaseCount('client_billing_schedules', 0);
        $schedule = $this->schedule();
        $url = $this->base().'/'.$schedule->public_id.'/generate';
        $version = AgentApiVersion::for($schedule);
        foreach ([[], ...array_map(static fn (mixed $value): array => ['confirm' => $value], [false, 'true', 'yes', 'on', 1, '1'])] as $i => $confirmation) {
            $this->withHeader('Idempotency-Key', 'no-confirm-'.$i)->postJson($url, ['expected_version' => $version, ...$confirmation])->assertUnprocessable();
        }
        $schedule->forceFill(['due_days' => 31])->save();
        $this->withHeader('Idempotency-Key', 'stale-schedule')->postJson($url, ['expected_version' => $version, 'confirm' => true])->assertConflict();
        $this->assertDatabaseCount('client_invoices', 0);
        $this->assertSame('2026-10-01', $schedule->fresh()->next_run_on->toDateString());
        $this->assertDatabaseHas('agent_mutation_audits', ['operation' => 'billing_schedules.generate', 'outcome' => 'failed', 'error_category' => 'conflict']);
    }

    public function test_billing_schedule_reads_are_scoped_and_filter_standard_boolean_queries(): void
    {
        $active = $this->schedule();
        $second = $this->agreement->replicate(['public_id']);
        $second->save();
        $inactive = $this->schedule(['is_active' => false, 'client_agreement_id' => $second->id]);
        $this->getJson($this->base().'?is_active=true')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $active->public_id)->assertJsonPath('data.0.version', AgentApiVersion::for($active));
        $this->getJson($this->base().'?is_active=false')->assertOk()->assertJsonPath('data.0.id', $inactive->public_id);
        $this->getJson($this->base().'/'.$active->public_id)->assertOk()->assertJsonPath('data.agreement_id', $this->agreement->public_id);
        $this->getJson($this->base().'?is_active=invalid')->assertUnprocessable();
        $foreign = Workspace::query()->create(['name' => 'Synthetic other tenant', 'slug' => 'synthetic-other']);
        $foreignCompany = ClientCompany::query()->create(['workspace_id' => $foreign->id, 'name' => 'Synthetic foreign client', 'slug' => 'synthetic-foreign']);
        $foreignAgreement = $this->agreement->replicate(['public_id']);
        $foreignAgreement->forceFill(['workspace_id' => $foreign->id, 'client_company_id' => $foreignCompany->id])->save();
        $foreignSchedule = $this->schedule(['workspace_id' => $foreign->id, 'client_company_id' => $foreignCompany->id, 'client_agreement_id' => $foreignAgreement->id]);
        $this->getJson($this->base().'/'.$foreignSchedule->public_id)->assertNotFound();
        $this->withHeader('Idempotency-Key', 'foreign-generate')->postJson($this->base().'/'.$foreignSchedule->public_id.'/generate', ['expected_version' => AgentApiVersion::for($foreignSchedule), 'confirm' => true])->assertNotFound();
        $otherCompany = ClientCompany::query()->create(['workspace_id' => $this->workspace->id, 'name' => 'Synthetic wrong parent', 'slug' => 'synthetic-wrong-parent']);
        $third = $this->agreement->replicate(['public_id']);
        $third->save();
        $broken = $this->schedule(['client_company_id' => $otherCompany->id, 'client_agreement_id' => $third->id]);
        $this->getJson($this->base().'/'.$broken->public_id)->assertNotFound();
        $this->withHeader('Idempotency-Key', 'broken-generate')->postJson($this->base().'/'.$broken->public_id.'/generate', ['expected_version' => AgentApiVersion::for($broken), 'confirm' => true])->assertNotFound();
        $this->withHeader('Idempotency-Key', 'wrong-chain')->postJson($this->base(), [...$this->body(), 'company_id' => $otherCompany->public_id])->assertNotFound();
        $this->getJson($this->base())->assertOk()->assertJsonCount(2, 'data');
        $this->assertDatabaseCount('client_invoices', 0);
    }

    public function test_roles_scopes_and_replay_authorization_are_enforced(): void
    {
        $body = $this->body();
        $this->withHeader('Idempotency-Key', 'revoked-retry')->postJson($this->base(), $body)->assertCreated();
        WorkspaceMembership::query()->where('workspace_id', $this->workspace->id)->where('user_id', $this->owner->id)->update(['role' => 'member']);
        $this->withHeader('Idempotency-Key', 'revoked-retry')->postJson($this->base(), $body)->assertForbidden();
        $this->getJson($this->base())->assertNotFound();
        $this->actingAsMcp($this->owner, ['mcp:use', 'billing:read']);
        $this->withHeader('Idempotency-Key', 'missing-scope')->postJson($this->base(), $body)->assertForbidden();
        $session = $this->initialize();
        $this->assertNotContains('billing_schedules.create', $this->toolNames($session));
        $this->assertNotContains('billing_schedules.generate', $this->toolNames($session));
        $this->assertDatabaseCount('client_billing_schedules', 1);
    }

    public function test_inactive_and_not_due_schedules_do_not_issue_or_advance(): void
    {
        foreach ([['is_active' => false], ['next_run_on' => '2026-11-01']] as $i => $attributes) {
            $agreement = $this->agreement->replicate(['public_id']);
            $agreement->save();
            $schedule = $this->schedule([...$attributes, 'client_agreement_id' => $agreement->id]);
            $version = AgentApiVersion::for($schedule);
            $this->withHeader('Idempotency-Key', Str::uuid()->toString())->postJson($this->base().'/'.$schedule->public_id.'/generate', ['expected_version' => $version, 'confirm' => true])->assertOk()->assertJsonCount(0, 'data.invoices')->assertJsonPath('data.schedule.version', $version);
        }
        $this->assertDatabaseCount('client_invoices', 0);
    }

    public function test_create_validation_rejects_system_lines_and_invalid_cadence_without_writes(): void
    {
        foreach ([['cadence' => 'weekly'], ['line_template' => []], ['due_days' => 366], ['next_run_on' => 'not-a-date'], ['expected_version' => 'bad'], ['line_template' => [['type' => 'retainer', 'description' => 'Synthetic system line', 'quantity' => '1', 'unit_amount' => 100]]]] as $i => $bad) {
            $this->withHeader('Idempotency-Key', 'invalid-'.$i)->postJson($this->base(), [...$this->body(), ...$bad])->assertUnprocessable();
        }
        $this->assertDatabaseCount('client_billing_schedules', 0);
        $this->assertDatabaseCount('agent_mutation_receipts', 0);
        $this->assertDatabaseHas('agent_mutation_audits', ['operation' => 'billing_schedules.create', 'outcome' => 'failed', 'error_category' => 'validation']);
    }

    /** @return array<string, mixed> */
    private function body(): array
    {
        return ['company_id' => $this->company->public_id, 'client_agreement' => $this->agreement->public_id, 'expected_version' => AgentApiVersion::for($this->agreement), 'cadence' => 'monthly', 'next_run_on' => '2026-10-01', 'due_days' => 30, 'currency' => 'USD', 'line_template' => [['type' => 'service', 'description' => 'Synthetic service', 'quantity' => '1', 'unit_amount' => 10000]]];
    }

    /** @param array<string, mixed> $attributes */
    private function schedule(array $attributes = []): ClientBillingSchedule
    {
        $data = $this->body();
        unset($data['company_id'], $data['client_agreement'], $data['expected_version']);

        return ClientBillingSchedule::query()->create([...$data, 'workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id, 'client_agreement_id' => $this->agreement->id, ...$attributes]);
    }

    private function base(): string
    {
        return '/api/v1/workspaces/'.$this->workspace->public_id.'/billing-schedules';
    }
}
