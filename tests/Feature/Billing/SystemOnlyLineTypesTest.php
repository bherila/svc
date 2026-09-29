<?php

namespace Tests\Feature\Billing;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientProject;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\InvoiceLifecycleService;
use App\Support\AgentApi\AgentApiScopes;
use App\Support\Billing\InvoiceLineType;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
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

    /** @return iterable<string, array{string}> */
    public static function systemOnly(): iterable
    {
        foreach (InvoiceLineType::systemOnlyValues() as $type) {
            yield $type => [$type];
        }
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
        $this->expectExceptionMessage('written only by invoice generation');

        try {
            app(InvoiceLifecycleService::class)->createDraft($this->workspace, $this->company, ['currency' => 'USD', 'invoice_number' => 'GRAN-SVC-1'], [$this->line($type)]);
        } finally {
            $this->assertSame(0, ClientInvoice::query()->count());
        }
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

    /** @return array<string, mixed> */
    private function line(string $type): array
    {
        return ['type' => $type, 'description' => 'Carried deferred work applied to retainer (9:15)', 'quantity' => '1', 'unit_amount' => 0, 'tax_amount' => 0];
    }
}
