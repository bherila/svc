<?php

namespace Tests\Feature\AgentApi;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientCompanyActivity;
use App\Models\ClientInvoice;
use App\Models\ClientInvoiceLine;
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
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\WritesLegacyCrossTenantRows;
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
    use WritesLegacyCrossTenantRows;

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
            'scheduled_issue_date' => '2026-10-03',
            'notes' => 'Synthetic note kept across regeneration.',
        ], 'details-regenerate')->assertOk();

        $this->entry('2026-09-20', 45);
        $regenerated = app(ClientInvoicingService::class)->generateInvoice(
            $this->company, Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30'), $this->agreement,
        );

        $this->assertSame($draft->id, $regenerated->id, 'The same draft was rebuilt');
        $this->assertSame('2026-10-20', $regenerated->fresh()->due_date?->toDateString());
        $this->assertSame('2026-10-03', $regenerated->fresh()->scheduled_issue_date?->toDateString());
        $this->assertSame('Synthetic note kept across regeneration.', $regenerated->fresh()->notes);
    }

    /**
     * The scheduled issue date (#381) is set, read back, recorded and cleared
     * through the same versioned, idempotent door as the due date, on a
     * generated draft that invoices.update_draft refuses. It is a plan, so it
     * is held to neither the due date nor the issue date.
     */
    public function test_a_scheduled_issue_date_is_set_read_recorded_and_cleared_on_a_generated_draft(): void
    {
        $draft = $this->generatedDraft();
        $dueDate = $draft->due_date?->toDateString();
        $lineIds = $draft->lines()->pluck('public_id')->all();
        $this->actingAsMcp($this->owner, [AgentApiScopes::BILLING_WRITE, AgentApiScopes::BILLING_READ]);

        $body = ['expected_version' => AgentApiVersion::for($draft->fresh()), 'scheduled_issue_date' => '2026-08-31'];
        $updated = $this->details($draft, $body, 'scheduled-set')->assertOk()->json('data');
        $this->details($draft, $body, 'scheduled-set')->assertOk()->assertJsonPath('data.version', $updated['version']);
        $this->assertSame('2026-08-31', $draft->fresh()->scheduled_issue_date?->toDateString());
        $this->assertSame($dueDate, $draft->fresh()->due_date?->toDateString(), 'An omitted due date is left alone');
        $this->assertSame($lineIds, $draft->lines()->pluck('public_id')->all());
        $this->getJson("/api/v1/workspaces/{$this->workspace->public_id}/invoices/{$draft->public_id}")
            ->assertOk()
            ->assertJsonPath('data.scheduled_issue_date', '2026-08-31')
            ->assertJsonPath('data.due_date', $dueDate);
        $activity = ClientCompanyActivity::query()->where('action', 'invoice.details_updated')->sole();
        $this->assertSame(['old' => null, 'new' => '2026-08-31'], $activity->payload['scheduled_issue_date']);
        $this->assertSame(['old' => $dueDate, 'new' => $dueDate], $activity->payload['due_date']);

        $cleared = ['expected_version' => $updated['version'], 'scheduled_issue_date' => null];
        $this->details($draft, $cleared, 'scheduled-clear')->assertOk();
        $this->assertNull($draft->fresh()->scheduled_issue_date);
        $this->details($draft, [...$cleared, 'expected_version' => AgentApiVersion::for($draft->fresh())], 'scheduled-noop')
            ->assertUnprocessable();
        $this->details($draft, ['expected_version' => AgentApiVersion::for($draft->fresh()), 'scheduled_issue_date' => '1 October'], 'scheduled-format')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('scheduled_issue_date');
    }

    /** Creating and replacing an ad-hoc draft carry the date too, and a replacement that omits it keeps it. */
    public function test_create_and_update_draft_carry_a_scheduled_issue_date_over_rest_and_mcp(): void
    {
        $this->actingAsMcp($this->owner, [AgentApiScopes::MCP_USE, AgentApiScopes::BILLING_WRITE, AgentApiScopes::BILLING_READ]);
        $line = ['project_id' => $this->project->public_id, 'type' => 'fee', 'description' => 'Synthetic fee', 'quantity' => '1', 'unit_amount' => 5000];
        $created = $this->withHeader('Idempotency-Key', 'scheduled-create')
            ->postJson("/api/v1/workspaces/{$this->workspace->public_id}/invoices", [
                'company_id' => $this->company->public_id,
                'manual_lines' => [$line],
                'scheduled_issue_date' => '2026-11-01',
            ])->assertCreated()->json('data');
        $draft = ClientInvoice::query()->where('public_id', $created['id'])->sole();
        $this->assertSame('2026-11-01', $draft->scheduled_issue_date?->toDateString());

        $replace = fn (array $extra, string $key) => $this->withHeader('Idempotency-Key', $key)->patchJson(
            "/api/v1/workspaces/{$this->workspace->public_id}/invoices/{$draft->public_id}",
            ['expected_version' => AgentApiVersion::for($draft->fresh()), 'time_entry_ids' => [], 'manual_lines' => [$line], ...$extra],
        )->assertOk();
        $replace([], 'scheduled-replace-keep');
        $this->assertSame('2026-11-01', $draft->fresh()->scheduled_issue_date?->toDateString());
        $replace(['scheduled_issue_date' => null], 'scheduled-replace-clear');
        $this->assertNull($draft->fresh()->scheduled_issue_date);

        $session = $this->initialize();
        $viaMcp = $this->callTool($session, 'invoices.create_draft', [
            'workspace_id' => $this->workspace->public_id,
            'company_id' => $this->company->public_id,
            'idempotency_key' => 'scheduled-create-mcp',
            'manual_lines' => [$line],
            'scheduled_issue_date' => '2026-11-02',
        ]);
        $this->assertFalse($viaMcp['isError'] ?? false, json_encode($viaMcp, JSON_THROW_ON_ERROR));
        $mcpDraft = ClientInvoice::query()->where('public_id', $viaMcp['structuredContent']['data']['id'])->sole();
        $this->assertSame('2026-11-02', $mcpDraft->scheduled_issue_date?->toDateString());

        $cleared = $this->callTool($session, 'invoices.update_details', [
            'workspace_id' => $this->workspace->public_id,
            'invoice_id' => $mcpDraft->public_id,
            'expected_version' => AgentApiVersion::for($mcpDraft->fresh()),
            'idempotency_key' => 'scheduled-clear-mcp',
            'scheduled_issue_date' => null,
        ]);
        $this->assertFalse($cleared['isError'] ?? false, json_encode($cleared, JSON_THROW_ON_ERROR));
        $this->assertNull($mcpDraft->fresh()->scheduled_issue_date, 'An explicit null clears the date rather than being dropped');
    }

    /** The operator's invoice form accepts the same field as invoices.create_draft. */
    public function test_the_operator_invoice_form_stores_a_scheduled_issue_date(): void
    {
        $this->actingAs($this->owner)
            ->postJson(route('svc.billing.invoices.store', [$this->workspace, $this->company]), [
                'invoice_number' => 'INV-SYN-SCHEDULED', 'currency' => 'USD', 'scheduled_issue_date' => '2026-11-01',
                'lines' => [['type' => 'fee', 'description' => 'Synthetic fee', 'quantity' => '1', 'unit_amount' => 5000]],
            ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('2026-11-01', ClientInvoice::query()->where('invoice_number', 'INV-SYN-SCHEDULED')->sole()->scheduled_issue_date?->toDateString());
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

    public function test_mcp_passes_through_stale_versions_and_domain_refusals(): void
    {
        $draft = $this->generatedDraft();
        $originalDueDate = $draft->due_date?->toDateString();
        $this->actingAsMcp($this->owner, [AgentApiScopes::MCP_USE, AgentApiScopes::BILLING_WRITE]);
        $session = $this->initialize();
        $arguments = [
            'workspace_id' => $this->workspace->public_id,
            'invoice_id' => $draft->public_id,
            'expected_version' => str_repeat('0', 64),
            'idempotency_key' => 'synthetic-stale-details',
            'due_date' => '2026-10-20',
        ];

        $stale = $this->callTool($session, 'invoices.update_details', $arguments);
        $this->assertTrue($stale['isError'] ?? false);
        $this->assertStringContainsString('The invoice has changed; read it and retry.', json_encode($stale, JSON_THROW_ON_ERROR));

        $refused = $this->callTool($session, 'invoices.update_details', [
            ...$arguments,
            'expected_version' => AgentApiVersion::for($draft->fresh()),
            'idempotency_key' => 'synthetic-invalid-due-date',
            'due_date' => '2026-09-01',
        ]);
        $this->assertTrue($refused['isError'] ?? false);
        $this->assertStringContainsString('due date cannot precede the issue date', json_encode($refused, JSON_THROW_ON_ERROR));
        $this->assertSame($originalDueDate, $draft->fresh()->due_date?->toDateString());
    }

    public function test_mcp_correction_schema_requires_a_due_date_or_lines(): void
    {
        $this->actingAsMcp($this->owner, [AgentApiScopes::MCP_USE, AgentApiScopes::BILLING_WRITE, AgentApiScopes::BILLING_DELIVER]);
        $session = $this->initialize();
        $tools = $this->postJson('/api/v1/mcp', [
            'jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list', 'params' => [],
        ], ['Mcp-Protocol-Version' => '2025-06-18', 'Mcp-Session-Id' => $session])->assertOk()->json('result.tools');
        $schema = collect($tools)->firstWhere('name', 'invoices.correct')['inputSchema'] ?? null;
        $this->assertIsArray($schema);
        $this->assertSame([['anyOf' => [['required' => ['due_date']], ['required' => ['lines']]]]], $schema['allOf'] ?? null);

        $arguments = [
            'workspace_id' => $this->workspace->public_id,
            'invoice_id' => (string) Str::uuid(),
            'expected_version' => str_repeat('0', 64),
            'idempotency_key' => 'synthetic-empty-correction',
            'confirm' => true,
            'reason' => 'Synthetic correction reason.',
        ];
        $this->postJson('/api/v1/mcp', [
            'jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call',
            'params' => ['name' => 'invoices.correct', 'arguments' => $arguments],
        ], ['Mcp-Protocol-Version' => '2025-06-18', 'Mcp-Session-Id' => $session])
            ->assertOk()->assertJsonPath('error.code', -32602);
        $this->assertDatabaseCount('agent_mutation_audits', 0);
    }

    /** The operator's invoice screen shows the date and offers the form that changes it. */
    public function test_the_invoice_screen_carries_the_scheduled_issue_date_and_the_form_to_change_it(): void
    {
        $draft = $this->generatedDraft();
        $draft->forceFill(['scheduled_issue_date' => '2026-10-03'])->save();

        $this->actingAs($this->owner)
            ->get(route('clients.invoice', [$this->workspace, $this->company, $draft]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('clients/invoice')
                ->where('invoice.scheduled_issue_date', '2026-10-03')
                ->where('actions.update_details', route('agent-api.v1.invoices.update_details', [$this->workspace, $draft->public_id], absolute: false))
            );
    }

    /** MCP advertises the same "at least one field" rule REST enforces, so a client can validate before calling. */
    public function test_the_mcp_schema_requires_a_due_date_a_scheduled_issue_date_or_notes(): void
    {
        $this->actingAsMcp($this->owner, [AgentApiScopes::MCP_USE, AgentApiScopes::BILLING_WRITE]);
        $session = $this->initialize();
        $tools = $this->postJson('/api/v1/mcp', [
            'jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list', 'params' => [],
        ], ['Mcp-Protocol-Version' => '2025-06-18', 'Mcp-Session-Id' => $session])->assertOk()->json('result.tools');
        $schema = collect($tools)->firstWhere('name', 'invoices.update_details')['inputSchema'] ?? null;

        $this->assertIsArray($schema);
        $this->assertSame([['anyOf' => [['required' => ['due_date']], ['required' => ['scheduled_issue_date']], ['required' => ['notes']]]]], $schema['allOf'] ?? null);
    }

    /** An invoice in another workspace is not found through this one, and is not changed. */
    public function test_both_operations_refuse_another_workspaces_invoice(): void
    {
        $foreignOwner = User::factory()->create(['email' => 'foreign-'.Str::random(8).'@synthetic.test']);
        $foreignWorkspace = Workspace::query()->create(['name' => 'Synthetic Foreign', 'slug' => 'synthetic-foreign-'.Str::random(8)]);
        $foreignWorkspace->memberships()->create(['user_id' => $foreignOwner->id, 'role' => 'owner']);
        $foreignCompany = ClientCompany::query()->create([
            'workspace_id' => $foreignWorkspace->id,
            'name' => 'Synthetic Foreign Client',
            'slug' => 'synthetic-foreign-client-'.Str::random(8),
        ]);
        $lifecycle = app(InvoiceLifecycleService::class);
        $foreignDraft = $lifecycle->createDraft($foreignWorkspace, $foreignCompany, [
            'invoice_number' => 'INV-FOREIGN-DRAFT', 'currency' => 'USD', 'issue_date' => '2026-09-15', 'due_date' => '2026-10-15',
        ], [['type' => 'fee', 'description' => 'Foreign service', 'quantity' => '1', 'unit_amount' => 5000, 'tax_amount' => 0]]);
        $foreignIssued = $lifecycle->issue($lifecycle->createDraft($foreignWorkspace, $foreignCompany, [
            'invoice_number' => 'INV-FOREIGN-ISSUED', 'currency' => 'USD', 'issue_date' => '2026-09-15', 'due_date' => '2026-10-15',
        ], [['type' => 'fee', 'description' => 'Foreign service', 'quantity' => '1', 'unit_amount' => 5000, 'tax_amount' => 0]]), $foreignWorkspace);

        $this->actingAsMcp($this->owner, [AgentApiScopes::BILLING_WRITE, AgentApiScopes::BILLING_DELIVER]);
        $this->details($foreignDraft, [
            'expected_version' => AgentApiVersion::for($foreignDraft->fresh()),
            'due_date' => '2026-10-30',
        ], 'foreign-details')->assertNotFound();
        $this->correct($foreignIssued, [
            'expected_version' => AgentApiVersion::for($foreignIssued->fresh()),
            'reason' => 'Synthetic cross-tenant attempt.',
            'confirm' => true,
            'due_date' => '2026-10-30',
        ], 'foreign-correct')->assertNotFound();

        $this->assertSame('2026-10-15', $foreignDraft->fresh()->due_date?->toDateString());
        $this->assertSame('2026-10-15', $foreignIssued->fresh()->due_date?->toDateString());
        $this->assertSame(1, $foreignIssued->fresh()->document_revision);
    }

    /** A legacy line stamped to another workspace is not loaded through this invoice. */
    public function test_the_updated_draft_is_returned_without_another_workspaces_rows(): void
    {
        $draft = $this->generatedDraft();
        $foreign = Workspace::query()->create(['name' => 'Synthetic Legacy', 'slug' => 'synthetic-legacy-'.Str::random(8)]);
        $template = $draft->lines()->firstOrFail();
        $this->writingLegacyCrossTenantRows(fn () => ClientInvoiceLine::query()->create([
            ...collect($template->getAttributes())->except(['id', 'public_id', 'created_at', 'updated_at'])->all(),
            'workspace_id' => $foreign->id,
            'description' => 'Stamped to another workspace',
        ]));

        $updated = app(InvoiceLifecycleService::class)->updateDraftDetails(
            $draft->fresh(),
            $this->workspace,
            AgentApiVersion::for($draft->fresh()),
            ['due_date' => '2026-10-20'],
        );

        $this->assertNotContains('Stamped to another workspace', $updated->lines->pluck('description')->all());
        $this->assertSame($this->company->id, $updated->clientCompany?->id);
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
