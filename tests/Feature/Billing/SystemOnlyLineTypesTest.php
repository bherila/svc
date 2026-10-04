<?php

namespace Tests\Feature\Billing;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientInvoiceLine;
use App\Models\ClientProject;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\InvoiceLifecycleService;
use App\Support\AgentApi\AgentApiScopes;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\Billing\InvoiceKind;
use App\Support\Billing\InvoiceLineType;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The line types the capacity ledger reads as money state are the generator's
 * alone: every manual door refuses them, and nothing is written.
 */
final class SystemOnlyLineTypesTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Workspace $workspace;

    private ClientCompany $company;

    private ClientProject $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $this->workspace = Workspace::query()->create(['name' => 'System lines', 'slug' => 'system-lines-'.Str::lower(Str::random(6))]);
        $this->workspace->memberships()->create(['user_id' => $this->owner->id, 'role' => 'owner']);
        $this->company = ClientCompany::query()->create(['workspace_id' => $this->workspace->id, 'name' => 'Granite Loop', 'slug' => 'granite-loop']);
        $this->project = ClientProject::query()->create(['workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id, 'name' => 'Loop']);
    }

    /**
     * Named here rather than read from systemOnlyValues(), so dropping a type
     * from that list fails these tests instead of silently shrinking them.
     *
     * - `credit`: issuing spends a client's overpayment credit pool by the
     *   negative credit lines on the draft, so a hand-typed one is a credit
     *   spend nobody reconciled (#349).
     * - `expense`: read by the expense-claim code as a billed expense, with
     *   no claim behind a hand-typed one (#349).
     * - `retainer`, `prior_month_retainer`, `prior_month_billable`,
     *   `additional_hours`: the generator's wording, read back by the
     *   cycle-sold, capacity and overage code (#349). Production held these
     *   only on generated cadence invoices when they were refused.
     *
     * @return iterable<string, array{string}>
     */
    public static function systemOnly(): iterable
    {
        foreach ([
            'carried_deferred_applied', 'carried_deferred_billed', 'credit', 'expense',
            'retainer', 'prior_month_retainer', 'prior_month_billable', 'additional_hours',
        ] as $type) {
            yield $type => [$type];
        }
    }

    public function test_every_refused_type_is_listed_as_system_only(): void
    {
        $this->assertEqualsCanonicalizing(
            array_map(static fn (array $case): string => $case[0], iterator_to_array(self::systemOnly(), false)),
            InvoiceLineType::systemOnlyValues(),
        );
    }

    #[DataProvider('systemOnly')]
    public function test_the_operator_invoice_form_refuses_it(string $type): void
    {
        $this->actingAs($this->owner)
            ->postJson(route('svc.billing.invoices.store', [$this->workspace, $this->company]), [
                'invoice_number' => 'GRAN-MANUAL-1', 'currency' => 'USD',
                'lines' => [$this->line($type)],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('lines.0.type');

        $this->assertSame(0, ClientInvoice::query()->count());
    }

    #[DataProvider('systemOnly')]
    public function test_the_agent_invoice_api_refuses_it(string $type): void
    {
        config(['agent_api.writes_enabled' => true, 'agent_api.invoice_writes_enabled' => true]);
        $this->actingAsMcp($this->owner, [AgentApiScopes::BILLING_WRITE]);

        $this->withHeader('Idempotency-Key', 'manual-'.$type)
            ->postJson("/api/v1/workspaces/{$this->workspace->public_id}/invoices", [
                'company_id' => $this->company->public_id,
                'manual_lines' => [['project_id' => $this->project->public_id] + $this->line($type)],
            ])
            ->assertStatus(422);

        $this->assertSame(0, ClientInvoice::query()->count());
    }

    #[DataProvider('systemOnly')]
    public function test_a_billing_schedule_template_refuses_it(string $type): void
    {
        $agreement = ClientAgreement::query()->create([
            'workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id, 'title' => 'Retainer',
            'status' => 'active', 'currency' => 'USD', 'starts_on' => '2026-01-01', 'billing_cadence' => 'monthly',
        ]);

        $this->actingAs($this->owner)
            ->postJson(route('svc.billing.schedules.store', [$this->workspace, $this->company]), [
                'client_agreement' => $agreement->public_id, 'cadence' => 'monthly', 'next_run_on' => '2026-10-01',
                'due_days' => 14, 'currency' => 'USD', 'line_template' => [$this->line($type)],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('line_template.0.type');
    }

    /** Behind the doors, the service refuses it too - whatever calls it. */
    #[DataProvider('systemOnly')]
    public function test_the_draft_service_refuses_it(string $type): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('by invoice generation');

        try {
            app(InvoiceLifecycleService::class)->createDraft($this->workspace, $this->company, ['currency' => 'USD', 'invoice_number' => 'GRAN-SVC-1'], [$this->line($type)]);
        } finally {
            $this->assertSame(0, ClientInvoice::query()->count());
        }
    }

    /**
     * The agent update replaces every line, and a generated line's time links
     * with it, so a generated draft is refused whole and keeps its lines;
     * it is regenerated instead (#349).
     */
    public function test_an_agent_update_refuses_a_generated_draft(): void
    {
        $draft = $this->draft();
        ClientInvoiceLine::query()->create([
            'workspace_id' => $this->workspace->id, 'client_invoice_id' => $draft->id, 'type' => 'retainer',
            'description' => 'Generated', 'quantity' => 1, 'unit_amount' => 0, 'tax_amount' => 0, 'total_amount' => 0, 'sort_order' => 2,
        ]);
        $draft->forceFill(['invoice_kind' => 'cadence_period'])->save();

        $this->agentUpdate($draft->refresh(), [$this->line('adjustment')])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Only an ad-hoc draft invoice can be edited here. Regenerate a generated draft instead.']);

        $this->assertSame(['adjustment', 'retainer'], $draft->lines()->orderBy('sort_order')->pluck('type')->all());
    }

    #[DataProvider('systemOnly')]
    public function test_an_agent_update_of_an_ad_hoc_draft_refuses_it(string $type): void
    {
        $draft = $this->draft();

        $this->agentUpdate($draft, [$this->line($type)])->assertStatus(422);

        $this->assertSame(['adjustment'], $draft->lines()->pluck('type')->all());
    }

    public function test_an_agent_update_of_an_ad_hoc_draft_still_edits_it(): void
    {
        $draft = $this->draft();

        $this->agentUpdate($draft, [$this->line('adjustment'), $this->line('milestone')])->assertOk();

        $this->assertEqualsCanonicalizing(['adjustment', 'milestone'], $draft->lines()->pluck('type')->all());
    }

    /** @return iterable<string, array{string, string}> */
    public static function kindsAndStatuses(): iterable
    {
        foreach (InvoiceKind::cases() as $kind) {
            foreach (['draft', 'issued'] as $status) {
                yield "{$kind->value} {$status}" => [$kind->value, $status];
            }
        }
        yield 'unrecognised kind draft' => ['legacy_kind', 'draft'];
    }

    /**
     * An agent reads `editable` before choosing `invoices.update_draft` over
     * regeneration, so it must say exactly what the update accepts (#364).
     */
    #[DataProvider('kindsAndStatuses')]
    public function test_the_agent_read_reports_editable_exactly_when_the_update_accepts_it(string $kind, string $status): void
    {
        $invoice = $this->draft();
        $invoice->forceFill(['invoice_kind' => $kind, 'status' => $status])->save();

        $this->actingAsMcp($this->owner, [AgentApiScopes::BILLING_READ]);
        $read = $this->getJson("/api/v1/workspaces/{$this->workspace->public_id}/invoices/{$invoice->public_id}")->assertOk();
        $accepted = $this->agentUpdate($invoice->refresh(), [$this->line('adjustment')])->isOk();

        $this->assertSame($accepted, $read->json('data.editable'));
        $this->assertSame($invoice->invoiceKindValue(), $read->json('data.invoice_kind'));
        $this->assertSame($status === 'draft' && $kind === InvoiceKind::AdHoc->value, $accepted);
    }

    public function test_an_ordinary_manual_line_is_still_accepted(): void
    {
        $this->actingAs($this->owner)
            ->postJson(route('svc.billing.invoices.store', [$this->workspace, $this->company]), [
                'invoice_number' => 'GRAN-MANUAL-2', 'currency' => 'USD',
                'lines' => [$this->line('adjustment')],
            ])
            ->assertSuccessful();

        $this->assertSame(1, ClientInvoice::query()->count());
    }

    private function draft(): ClientInvoice
    {
        return app(InvoiceLifecycleService::class)->createDraft(
            $this->workspace, $this->company, ['currency' => 'USD', 'invoice_number' => 'GRAN-EDIT-'.uniqid()], [$this->line('adjustment')],
        );
    }

    /** @param  list<array<string, mixed>>  $lines */
    private function agentUpdate(ClientInvoice $draft, array $lines): TestResponse
    {
        config(['agent_api.writes_enabled' => true, 'agent_api.invoice_writes_enabled' => true]);
        $this->actingAsMcp($this->owner, [AgentApiScopes::BILLING_WRITE]);

        return $this->withHeader('Idempotency-Key', 'update-'.$draft->id.'-'.md5(serialize($lines)))
            ->patchJson("/api/v1/workspaces/{$this->workspace->public_id}/invoices/{$draft->public_id}", [
                'expected_version' => AgentApiVersion::for($draft),
                'time_entry_ids' => [],
                'manual_lines' => array_map(fn (array $line): array => ['project_id' => $this->project->public_id] + $line, $lines),
            ]);
    }

    /** @return array<string, mixed> */
    private function line(string $type): array
    {
        return ['type' => $type, 'description' => 'Carried deferred work applied to retainer (9:15)', 'quantity' => '1', 'unit_amount' => 0, 'tax_amount' => 0];
    }
}
