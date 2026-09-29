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
     *
     * @return iterable<string, array{string}>
     */
    public static function systemOnly(): iterable
    {
        foreach (['carried_deferred_applied', 'carried_deferred_billed', 'credit', 'expense'] as $type) {
            yield $type => [$type];
        }
    }

    /**
     * Generator-worded types the capacity and overage code reads back (#349).
     * Refused on a new manual line; a draft that already carries one keeps it.
     * Production held these only on generated cadence invoices when they were
     * refused.
     *
     * @return iterable<string, array{string}>
     */
    public static function generatorOwned(): iterable
    {
        foreach (['retainer', 'prior_month_retainer', 'additional_hours'] as $type) {
            yield $type => [$type];
        }
    }

    /** @return iterable<string, array{string}> */
    public static function manuallyRefused(): iterable
    {
        yield from self::systemOnly();
        yield from self::generatorOwned();
    }

    public function test_every_refused_type_is_listed_as_system_only(): void
    {
        $this->assertEqualsCanonicalizing(
            array_map(static fn (array $case): string => $case[0], iterator_to_array(self::systemOnly(), false)),
            InvoiceLineType::systemOnlyValues(),
        );
        $this->assertEqualsCanonicalizing(
            array_map(static fn (array $case): string => $case[0], iterator_to_array(self::generatorOwned(), false)),
            InvoiceLineType::generatorOwnedValues(),
        );
    }

    #[DataProvider('manuallyRefused')]
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

    #[DataProvider('manuallyRefused')]
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

    #[DataProvider('manuallyRefused')]
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
    #[DataProvider('manuallyRefused')]
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

    /** An agent editing a generated draft keeps the generator's lines. */
    #[DataProvider('generatorOwned')]
    public function test_an_agent_update_keeps_a_type_the_draft_already_carries(string $type): void
    {
        $draft = $this->draftCarrying($type);

        $this->agentUpdate($draft, [$this->line($type), $this->line('adjustment')])->assertOk();

        $this->assertSame(1, $draft->lines()->where('type', $type)->count());
        $this->assertSame(1, $draft->lines()->where('type', 'adjustment')->count());
    }

    /** But a draft that did not carry the type cannot gain it by hand. */
    #[DataProvider('generatorOwned')]
    public function test_an_agent_update_cannot_add_a_type_the_draft_did_not_carry(string $type): void
    {
        $draft = $this->draftCarrying(null);

        $this->agentUpdate($draft, [$this->line($type)])->assertStatus(422);

        $this->assertSame(0, $draft->lines()->where('type', $type)->count());
        $this->assertSame(1, $draft->lines()->where('type', 'adjustment')->count());
    }

    /** The system-only types stay refused on update, carried or not. */
    #[DataProvider('systemOnly')]
    public function test_an_agent_update_refuses_a_system_only_type(string $type): void
    {
        $draft = $this->draftCarrying(null);

        $this->agentUpdate($draft, [$this->line($type)])->assertStatus(422);

        $this->assertSame(0, $draft->lines()->where('type', $type)->count());
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

    /** An ad-hoc draft with an ordinary line, plus a generator-written line of `$type`. */
    private function draftCarrying(?string $type): ClientInvoice
    {
        $draft = app(InvoiceLifecycleService::class)->createDraft(
            $this->workspace, $this->company, ['currency' => 'USD', 'invoice_number' => 'GRAN-EDIT-'.($type ?? 'none')], [$this->line('adjustment')],
        );
        if ($type !== null) {
            // As the generator writes it: directly, not through a manual door.
            ClientInvoiceLine::query()->create([
                'workspace_id' => $this->workspace->id, 'client_invoice_id' => $draft->id, 'type' => $type,
                'description' => 'Generated', 'quantity' => 1, 'unit_amount' => 0, 'tax_amount' => 0, 'total_amount' => 0, 'sort_order' => 2,
            ]);
        }

        return $draft->refresh();
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
