<?php

namespace Tests\Feature\AgentApi;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientCompanyActivity;
use App\Models\ClientInvoice;
use App\Models\ClientProject;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\ClientInvoicingService;
use App\Services\Billing\InvoiceEmailService;
use App\Services\Billing\InvoiceLifecycleService;
use App\Support\AgentApi\AgentApiScopes;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\Billing\InvoiceEmailDraft;
use Carbon\Carbon;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * A draft's due date, and an issued invoice's audited correction, through the API.
 *
 * Neither the website nor the API could change a generated draft's due date:
 * `invoices.update_draft` replaces lines and so refuses generated drafts, and
 * the website's correction only accepts issued invoices. A generated draft with
 * no issue date whose due date had passed could therefore never be issued,
 * because issuing dates it today and refuses a due date before that.
 */
final class AgentInvoiceDetailsAndCorrectionTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Workspace $workspace;

    private ClientCompany $company;

    private ClientProject $project;

    protected function setUp(): void
    {
        parent::setUp();
        config(['agent_api.writes_enabled' => true, 'agent_api.invoice_writes_enabled' => true]);
        Mail::fake();
        $this->owner = User::factory()->create(['email' => 'operator-'.Str::random(8).'@synthetic.test']);
        $this->workspace = Workspace::query()->create(['name' => 'Synthetic Details', 'slug' => 'synthetic-details-'.Str::random(8)]);
        $this->workspace->memberships()->create(['user_id' => $this->owner->id, 'role' => 'owner']);
        $this->company = ClientCompany::query()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Synthetic Details Client',
            'slug' => 'synthetic-details-client-'.Str::random(8),
            'billing_email' => 'billing@synthetic.test',
        ]);
        $this->project = ClientProject::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'name' => 'Synthetic Project',
        ]);
    }

    public function test_a_stale_generated_draft_gets_a_new_due_date_and_can_then_be_issued(): void
    {
        $draft = $this->generatedDraft();
        // The stuck shape: no issue date, so issuing dates it today, and a due
        // date that today has already passed.
        $draft->forceFill(['issue_date' => null, 'due_date' => '2026-09-30'])->save();
        $lineIds = $draft->lines()->pluck('public_id')->all();
        $this->travelTo(Carbon::parse('2026-10-08 12:00:00'));

        try {
            app(InvoiceLifecycleService::class)->issue($draft->fresh(), $this->workspace);
            $this->fail('A draft due before today was issued today.');
        } catch (DomainException $refused) {
            $this->assertStringContainsString('due date cannot precede the issue date', $refused->getMessage());
        }

        $this->actingAsMcp($this->owner, [AgentApiScopes::BILLING_WRITE]);
        $body = ['expected_version' => AgentApiVersion::for($draft->fresh()), 'due_date' => '2026-10-15'];
        $updated = $this->details($draft, $body, 'details-stale')
            ->assertOk()
            ->assertJsonPath('data.status', 'draft')
            ->json('data');
        $this->assertSame('2026-10-15', $draft->fresh()->due_date?->toDateString());
        // An identical retry replays the first result instead of writing again.
        $this->details($draft, $body, 'details-stale')->assertOk()->assertJsonPath('data.version', $updated['version']);

        $this->assertSame($lineIds, $draft->lines()->pluck('public_id')->all(), 'Lines are untouched');
        $this->assertDatabaseHas('agent_mutation_audits', ['operation' => 'invoices.update_details', 'outcome' => 'success']);
        $activity = ClientCompanyActivity::query()->where('action', 'invoice.details_updated')->sole();
        $this->assertSame($this->owner->id, $activity->actor_user_id);
        $this->assertSame(['old' => '2026-09-30', 'new' => '2026-10-15'], $activity->payload['due_date']);

        $issued = app(InvoiceLifecycleService::class)->issue($draft->fresh(), $this->workspace);
        $this->assertSame('issued', $issued->status);
        $this->assertSame('2026-10-08', $issued->issue_date?->toDateString());
        $this->assertSame('2026-10-15', $issued->due_date?->toDateString());
    }

    /**
     * The nearest independent constraint: regeneration rewrites a generated
     * draft in place, so a due date set here must survive it rather than
     * silently reverting the next time time is logged against the period.
     */
    public function test_a_due_date_set_on_a_generated_draft_survives_regeneration(): void
    {
        $draft = $this->generatedDraft();
        $this->actingAsMcp($this->owner, [AgentApiScopes::BILLING_WRITE]);
        $this->details($draft, [
            'expected_version' => AgentApiVersion::for($draft->fresh()),
            'due_date' => '2026-10-20',
            'notes' => 'Synthetic note kept across regeneration.',
        ], 'details-regenerate')->assertOk();

        $this->entry('2026-09-20', 45);
        $regenerated = app(ClientInvoicingService::class)->generateInvoice(
            $this->company, Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30'), $this->agreement,
        );

        $this->assertSame($draft->id, $regenerated->id, 'The same draft was rebuilt');
        $this->assertSame('2026-10-20', $regenerated->fresh()->due_date?->toDateString());
        $this->assertSame('Synthetic note kept across regeneration.', $regenerated->fresh()->notes);
    }

    public function test_details_updates_are_refused_for_bad_input_stale_versions_issued_invoices_and_missing_scope(): void
    {
        $draft = $this->generatedDraft();
        $version = AgentApiVersion::for($draft->fresh());

        $this->actingAsMcp($this->owner, [AgentApiScopes::BILLING_READ]);
        $this->details($draft, ['expected_version' => $version, 'due_date' => '2026-10-20'], 'details-scope')->assertForbidden();

        $this->actingAsMcp($this->owner, [AgentApiScopes::BILLING_WRITE]);
        $this->details($draft, ['expected_version' => $version], 'details-empty')->assertUnprocessable();
        $this->details($draft, ['expected_version' => str_repeat('0', 64), 'due_date' => '2026-10-20'], 'details-stale-version')
            ->assertStatus(409);
        // Generated monthly drafts are dated the first of the month they sell.
        $this->details($draft, ['expected_version' => $version, 'due_date' => '2026-09-30'], 'details-before-issue')
            ->assertUnprocessable();
        $this->details($draft, ['expected_version' => $version, 'due_date' => $draft->fresh()->due_date?->toDateString()], 'details-noop')
            ->assertUnprocessable();
        $this->assertSame($version, AgentApiVersion::for($draft->fresh()), 'Nothing was written');

        $this->travelTo(Carbon::parse('2026-10-02 12:00:00'));
        $issued = app(InvoiceLifecycleService::class)->issue($draft->fresh(), $this->workspace);
        $this->details($issued, ['expected_version' => AgentApiVersion::for($issued->fresh()), 'due_date' => '2026-10-20'], 'details-issued')
            ->assertUnprocessable()
            ->assertJsonFragment(['message' => 'Only a draft invoice\'s details can be changed here. Correct an issued invoice instead.']);
    }

    public function test_a_due_date_only_correction_keeps_every_line_and_advances_the_revision(): void
    {
        $invoice = $this->issuedInvoice();
        $line = $invoice->lines()->sole();
        $this->actingAsMcp($this->owner, [AgentApiScopes::BILLING_DELIVER]);

        $corrected = $this->correct($invoice, [
            'expected_version' => AgentApiVersion::for($invoice->fresh()),
            'reason' => 'Synthetic due date agreed with the client.',
            'confirm' => true,
            'due_date' => '2026-11-01',
        ], 'correct-due')->assertOk()->json('data');
        $this->assertSame('2026-11-01', $invoice->fresh()->due_date?->toDateString());

        $fresh = $invoice->fresh();
        $this->assertSame(2, $fresh->document_revision);
        $this->assertSame(12500, $fresh->total_amount);
        $this->assertSame('Synthetic service', $line->fresh()->description);
        $this->assertSame($corrected['version'], AgentApiVersion::for($fresh));
        $activity = ClientCompanyActivity::query()->where('action', 'invoice.corrected')->sole();
        $this->assertSame(1, $activity->payload['from_revision']);
        $this->assertSame(2, $activity->payload['to_revision']);
        $this->assertTrue($activity->payload['due_date_changed']);
        $this->assertSame([], $activity->payload['lines']);
        $this->assertDatabaseHas('agent_mutation_audits', ['operation' => 'invoices.correct', 'outcome' => 'success']);
    }

    public function test_a_correction_can_reword_a_line_and_change_operator_authored_money(): void
    {
        $invoice = $this->issuedInvoice();
        $line = $invoice->lines()->sole();
        $this->actingAsMcp($this->owner, [AgentApiScopes::BILLING_DELIVER]);

        $this->correct($invoice, [
            'expected_version' => AgentApiVersion::for($invoice->fresh()),
            'reason' => 'Synthetic rate correction.',
            'confirm' => true,
            'lines' => [[
                'id' => $line->public_id,
                'description' => 'Corrected synthetic service',
                'quantity' => '1',
                'unit_amount' => 13000,
                'tax_amount' => 0,
            ]],
        ], 'correct-line')->assertOk();
        $this->assertSame(13000, $invoice->fresh()->total_amount);

        $this->assertSame('Corrected synthetic service', $line->fresh()->description);
        $this->assertSame('2026-10-15', $invoice->fresh()->due_date?->toDateString(), 'An omitted due date is kept');
    }

    public function test_corrections_need_confirmation_the_deliver_scope_a_current_version_and_an_unsent_invoice(): void
    {
        $invoice = $this->issuedInvoice();
        $version = AgentApiVersion::for($invoice->fresh());
        $body = ['expected_version' => $version, 'reason' => 'Synthetic.', 'confirm' => true, 'due_date' => '2026-11-01'];

        $this->actingAsMcp($this->owner, [AgentApiScopes::BILLING_WRITE]);
        $this->correct($invoice, $body, 'correct-scope')->assertForbidden();

        $this->actingAsMcp($this->owner, [AgentApiScopes::BILLING_DELIVER]);
        $this->correct($invoice, [...$body, 'confirm' => false], 'correct-unconfirmed')->assertUnprocessable();
        $this->correct($invoice, [...$body, 'expected_version' => str_repeat('0', 64)], 'correct-stale')->assertStatus(409);
        $this->assertSame(1, $invoice->fresh()->document_revision);

        app(InvoiceEmailService::class)->send(
            $invoice->fresh(),
            InvoiceEmailDraft::of(['billing@synthetic.test'], [], 'Invoice', null),
            $this->workspace,
        );
        $this->correct($invoice, [...$body, 'expected_version' => AgentApiVersion::for($invoice->fresh())], 'correct-sent')
            ->assertUnprocessable();
        $this->assertSame('2026-10-15', $invoice->fresh()->due_date?->toDateString());
    }

    public function test_both_operations_are_reachable_as_mcp_tools(): void
    {
        $draft = $this->generatedDraft();
        $this->actingAsMcp($this->owner, [AgentApiScopes::MCP_USE, AgentApiScopes::BILLING_WRITE, AgentApiScopes::BILLING_DELIVER]);
        $session = $this->initialize();

        $details = $this->callTool($session, 'invoices.update_details', [
            'workspace_id' => $this->workspace->public_id,
            'invoice_id' => $draft->public_id,
            'expected_version' => AgentApiVersion::for($draft->fresh()),
            'idempotency_key' => 'mcp-details',
            'due_date' => '2026-10-20',
        ]);
        $this->assertFalse($details['isError'] ?? false, json_encode($details, JSON_THROW_ON_ERROR));
        $this->assertSame('2026-10-20', $draft->fresh()->due_date?->toDateString());

        $invoice = $this->issuedInvoice();
        $correct = $this->callTool($session, 'invoices.correct', [
            'workspace_id' => $this->workspace->public_id,
            'invoice_id' => $invoice->public_id,
            'confirm' => true,
            'expected_version' => AgentApiVersion::for($invoice->fresh()),
            'reason' => 'Synthetic due date agreed with the client.',
            'idempotency_key' => 'mcp-correct',
            'due_date' => '2026-11-01',
        ]);
        $this->assertFalse($correct['isError'] ?? false, json_encode($correct, JSON_THROW_ON_ERROR));
        $this->assertSame('2026-11-01', $invoice->fresh()->due_date?->toDateString());
        $this->assertSame(2, $invoice->fresh()->document_revision);
    }

    private ClientAgreement $agreement;

    private function generatedDraft(): ClientInvoice
    {
        $this->agreement = ClientAgreement::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'title' => 'Synthetic retainer',
            'status' => 'active',
            'currency' => 'USD',
            'starts_on' => '2026-01-01',
            'retainer_minutes' => 600,
            'retainer_amount' => 375000,
            'hourly_rate_amount' => 37500,
            'billing_cadence' => 'monthly',
            'rollover_months' => 1,
        ]);
        $this->entry('2026-09-15', 90);

        return app(ClientInvoicingService::class)->generateInvoice(
            $this->company, Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30'), $this->agreement,
        );
    }

    private function issuedInvoice(): ClientInvoice
    {
        $draft = app(InvoiceLifecycleService::class)->createDraft($this->workspace, $this->company, [
            'invoice_number' => 'INV-SYN-'.Str::upper(Str::random(6)),
            'currency' => 'USD',
            'issue_date' => '2026-09-15',
            'due_date' => '2026-10-15',
        ], [[
            'type' => 'fee',
            'description' => 'Synthetic service',
            'quantity' => '1',
            'unit_amount' => 12500,
            'tax_amount' => 0,
        ]]);

        return app(InvoiceLifecycleService::class)->issue($draft, $this->workspace);
    }

    private function entry(string $workedOn, int $minutes): void
    {
        ClientTimeEntry::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'client_project_id' => $this->project->id,
            'user_id' => $this->owner->id,
            'worked_on' => $workedOn,
            'minutes' => $minutes,
            'description' => 'Synthetic work',
            'is_billable' => true,
            'is_deferred' => false,
            'status' => 'approved',
            'billing_rate_amount' => 37500,
            'currency' => 'USD',
        ]);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return TestResponse<Response>
     */
    private function details(ClientInvoice $invoice, array $body, string $key): TestResponse
    {
        return $this->withHeader('Idempotency-Key', $key)->patchJson(
            "/api/v1/workspaces/{$this->workspace->public_id}/invoices/{$invoice->public_id}/details",
            $body,
        );
    }

    /**
     * @param  array<string, mixed>  $body
     * @return TestResponse<Response>
     */
    private function correct(ClientInvoice $invoice, array $body, string $key): TestResponse
    {
        return $this->withHeader('Idempotency-Key', $key)->postJson(
            "/api/v1/workspaces/{$this->workspace->public_id}/invoices/{$invoice->public_id}/correct",
            $body,
        );
    }

    private function initialize(): string
    {
        $session = $this->postJson('/api/v1/mcp', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 'SVC test', 'version' => '1']],
        ], ['Mcp-Protocol-Version' => '2025-06-18'])->assertOk()->headers->get('Mcp-Session-Id');
        $this->assertIsString($session);

        return $session;
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function callTool(string $session, string $name, array $arguments): array
    {
        $result = $this->postJson('/api/v1/mcp', [
            'jsonrpc' => '2.0', 'id' => Str::uuid()->toString(), 'method' => 'tools/call',
            'params' => ['name' => $name, 'arguments' => $arguments],
        ], ['Mcp-Protocol-Version' => '2025-06-18', 'Mcp-Session-Id' => $session])->assertOk()->json('result');
        $this->assertIsArray($result);

        return $result;
    }
}
