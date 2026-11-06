<?php

namespace Tests\Feature\Billing;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientProject;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\OwnTables;
use Tests\TestCase;

/**
 * "View proforma invoice": the period preview drawn as the invoice document.
 */
class InvoiceProformaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-08 12:00:00');
    }

    /** @return array{Workspace, User, ClientCompany, ClientAgreement, ClientProject} */
    private function fixture(array $terms = []): array
    {
        $owner = User::factory()->create();
        $workspace = Workspace::query()->create(['name' => 'Synthetic Proforma Workspace', 'slug' => 'synthetic-proforma-'.uniqid()]);
        $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic Proforma Client', 'slug' => 'synthetic-proforma-client', 'billing_email' => 'billing@synthetic.example.test']);
        $project = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Synthetic Proforma Project']);
        $agreement = ClientAgreement::query()->create($terms + ['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'title' => 'Synthetic Retainer', 'status' => 'active', 'starts_on' => '2026-01-01', 'billing_cadence' => 'monthly', 'currency' => 'USD', 'hourly_rate_amount' => 10000, 'retainer_minutes' => 600, 'retainer_amount' => 100000]);

        return [$workspace, $owner, $company, $agreement, $project];
    }

    private function work(Workspace $workspace, ClientCompany $company, ClientProject $project, string $date, int $minutes): void
    {
        ClientTimeEntry::query()->create(['workspace_id' => $workspace->id, 'user_id' => $workspace->memberships()->value('user_id'), 'client_company_id' => $company->id, 'client_project_id' => $project->id, 'worked_on' => $date, 'minutes' => $minutes, 'description' => 'Synthetic internal approved work', 'status' => 'approved', 'is_billable' => true, 'is_deferred' => false, 'billing_rate_amount' => 10000, 'currency' => 'USD']);
    }

    private function url(Workspace $workspace, ClientCompany $company, ClientAgreement $agreement): string
    {
        return route('clients.proforma', [$workspace, $company, $agreement], absolute: false);
    }

    /** @return array<string, string> */
    private function fingerprint(): array
    {
        $fingerprint = [];
        foreach (OwnTables::names() as $name) {
            // The request itself records who last opened which workspace.
            if (in_array($name, ['users', 'sessions'], true)) {
                continue;
            }
            $fingerprint[$name] = hash('sha256', json_encode(DB::table($name)->get()->map(static fn (object $row): array => (array) $row)->sortBy(static fn (array $row): string => json_encode($row, JSON_THROW_ON_ERROR))->values()->all(), JSON_THROW_ON_ERROR));
        }

        return $fingerprint;
    }

    public function test_the_proforma_is_the_next_invoice_as_a_stamped_document_and_writes_nothing(): void
    {
        [$workspace, $owner, $company, $agreement, $project] = $this->fixture();
        $this->work($workspace, $company, $project, '2026-09-10', 900);
        $this->work($workspace, $company, $project, '2026-09-20', 600);
        $before = $this->fingerprint();

        $this->actingAs($owner)->get($this->url($workspace, $company, $agreement))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('clients/proforma')
                ->where('reason', null)
                ->where('period_start', '2026-10-01')
                ->where('document.mode', 'proforma')
                ->where('document.stamp', 'PROFORMA · NOT AN INVOICE')
                ->where('document.audience', 'client')
                ->where('document.status', 'draft')
                ->where('document.pay_url', null)
                ->where('document.sections.0.key', 'time')
                ->has('document.statement.sections')
                ->where('document.totals.total', fn (int $total): bool => $total > 100000));

        $this->assertSame($before, $this->fingerprint(), 'A proforma is never stored.');
        $this->assertSame(0, ClientInvoice::query()->where('workspace_id', $workspace->id)->count());
    }

    public function test_the_proforma_is_the_client_copy_so_no_internal_note_is_in_it(): void
    {
        [$workspace, $owner, $company, $agreement, $project] = $this->fixture();
        $this->work($workspace, $company, $project, '2026-09-10', 1500);

        $payload = $this->actingAs($owner)->get($this->url($workspace, $company, $agreement))->assertOk()->original->getData()['page']['props'];

        $this->assertStringNotContainsString('Synthetic internal approved work', json_encode($payload, JSON_THROW_ON_ERROR));
        $this->assertSame('client', $payload['document']['audience']);
    }

    public function test_a_period_that_already_has_an_invoice_says_so_instead_of_previewing(): void
    {
        [$workspace, $owner, $company, $agreement] = $this->fixture();
        ClientInvoice::query()->create([
            'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'client_agreement_id' => $agreement->id,
            'invoice_number' => 'INV-SYN-PRO-9', 'status' => 'issued', 'currency' => 'USD', 'cycle_start' => '2026-10-01', 'cycle_end' => '2026-10-31',
            'service_period_start' => '2026-09-01', 'service_period_end' => '2026-09-30', 'invoice_kind' => 'cadence_period',
            'subtotal_amount' => 0, 'tax_amount' => 0, 'total_amount' => 0, 'paid_amount' => 0, 'balance_amount' => 0,
        ]);

        $this->actingAs($owner)->get($this->url($workspace, $company, $agreement))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('document', null)
                ->where('reason', 'The current period already has an invoice, so there is nothing left to preview.'));
    }

    public function test_an_agreement_that_has_not_started_has_no_proforma(): void
    {
        [$workspace, $owner, $company, $agreement] = $this->fixture(['starts_on' => '2027-01-01']);

        $this->actingAs($owner)->get($this->url($workspace, $company, $agreement))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('document', null)
                ->where('reason', 'This agreement has not started yet, so there is no invoice to preview.'));
    }

    public function test_only_a_manager_may_preview_and_only_inside_the_workspace(): void
    {
        [$workspace, $owner, $company, $agreement] = $this->fixture();
        $member = User::factory()->create();
        $workspace->memberships()->create(['user_id' => $member->id, 'role' => 'member']);
        [$otherWorkspace, $otherOwner, $otherCompany, $otherAgreement] = $this->fixture();

        $this->actingAs($member)->get($this->url($workspace, $company, $agreement))->assertForbidden();
        // Another workspace's agreement through this workspace's own URL.
        $this->actingAs($owner)->get($this->url($workspace, $company, $otherAgreement))->assertNotFound();
        // This workspace's agreement under another client of the same workspace.
        $sibling = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic Sibling Client', 'slug' => 'synthetic-sibling-client']);
        $this->actingAs($owner)->get($this->url($workspace, $sibling, $agreement))->assertNotFound();
        $this->assertNotSame($otherOwner->id, $owner->id);
        $this->assertNotSame($otherWorkspace->id, $workspace->id);
        $this->assertNotSame($otherCompany->id, $company->id);
    }

    public function test_the_client_overview_offers_one_link_per_recurring_agreement(): void
    {
        [$workspace, $owner, $company, $agreement] = $this->fixture();
        ClientAgreement::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'title' => 'Synthetic one-time', 'status' => 'draft', 'starts_on' => '2026-01-01', 'billing_cadence' => 'monthly', 'currency' => 'USD', 'retainer_minutes' => 60, 'retainer_amount' => 1000]);

        $this->actingAs($owner)->get(route('clients.show', [$workspace, $company], absolute: false))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('proformas', 1)
                ->where('proformas.0.id', $agreement->public_id)
                ->where('proformas.0.title', 'Synthetic Retainer')
                ->where('proformas.0.href', $this->url($workspace, $company, $agreement)));
    }
}
