<?php

namespace Tests\Feature;

use App\Models\ClientAgreement;
use App\Models\ClientBillingSchedule;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientProject;
use App\Models\ClientProjectMembership;
use App\Models\ClientProposal;
use App\Models\ClientTask;
use App\Models\ClientTaskAssignment;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Support\AgentApi\ProjectRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\AssertsSurfaceIsolation;
use Tests\Concerns\WritesLegacyCrossTenantRows;
use Tests\TestCase;

/**
 * The workspace tier: Dashboard (the retired Operations screen's successor),
 * the cross-client Invoices and Time lists, Finance, and who may open each.
 *
 * The isolation tests are the point. Every list here is read across clients, so
 * each one is checked against a second workspace holding rows of the same kind
 * that must never appear, and against viewers who must not see it at all.
 */
class WorkspaceDashboardTest extends TestCase
{
    use AssertsSurfaceIsolation;
    use RefreshDatabase;
    use WritesLegacyCrossTenantRows;

    private User $owner;

    private User $member;

    private Workspace $workspace;

    private ClientCompany $company;

    private ClientProject $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->member = User::factory()->create();
        $this->workspace = Workspace::query()->create(['name' => 'Synthetic Dashboard', 'slug' => 'synthetic-dashboard']);
        $this->workspace->memberships()->create(['user_id' => $this->owner->id, 'role' => 'owner']);
        $this->workspace->memberships()->create(['user_id' => $this->member->id, 'role' => 'member']);
        [$this->company, $this->project] = $this->clientWithProject($this->workspace, 'Synthetic Client');
        ClientProjectMembership::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_project_id' => $this->project->id,
            'user_id' => $this->member->id,
            'role' => ProjectRole::Contributor->value,
        ]);
    }

    /** @return array{ClientCompany, ClientProject} */
    private function clientWithProject(Workspace $workspace, string $name): array
    {
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => $name, 'slug' => str($name)->slug().'-'.uniqid()]);
        $project = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => "$name Project", 'status' => 'active']);

        return [$company, $project];
    }

    private function invoice(Workspace $workspace, ClientCompany $company, string $number, string $status, ?string $due, int $balance = 10000): ClientInvoice
    {
        return ClientInvoice::query()->create([
            'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'invoice_number' => $number,
            'status' => $status, 'currency' => 'USD', 'issue_date' => '2026-08-01', 'due_date' => $due,
            'subtotal_amount' => $balance, 'tax_amount' => 0, 'total_amount' => $balance, 'paid_amount' => 0, 'balance_amount' => $balance,
        ]);
    }

    private function time(Workspace $workspace, ClientCompany $company, ClientProject $project, User $user, string $description, string $status = 'draft'): ClientTimeEntry
    {
        return ClientTimeEntry::query()->create([
            'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'client_project_id' => $project->id,
            'user_id' => $user->id, 'worked_on' => '2026-08-20', 'minutes' => 90, 'description' => $description, 'status' => $status,
        ]);
    }

    private function path(string $suffix = 'dashboard'): string
    {
        return "/workspaces/{$this->workspace->public_id}/{$suffix}";
    }

    private function seedQueue(): void
    {
        $this->invoice($this->workspace, $this->company, 'SYN-OVERDUE', 'issued', '2020-01-01');
        $this->invoice($this->workspace, $this->company, 'SYN-DRAFT', 'draft', null);
        $this->invoice($this->workspace, $this->company, 'SYN-PAID', 'paid', '2020-01-01', 0);
        $this->time($this->workspace, $this->company, $this->project, $this->owner, 'Owner draft work');
        ClientProposal::query()->create(['workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id, 'title' => 'Synthetic Proposal', 'currency' => 'USD', 'status' => 'sent', 'sent_at' => now()]);
        $agreement = ClientAgreement::query()->create(['workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id, 'title' => 'Synthetic Agreement', 'starts_on' => '2024-01-01', 'currency' => 'USD', 'billing_cadence' => 'monthly', 'status' => 'active']);
        ClientBillingSchedule::query()->create([
            'workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id, 'client_agreement_id' => $agreement->id,
            'cadence' => 'monthly', 'next_run_on' => '2020-02-01', 'due_days' => 30, 'currency' => 'USD', 'is_active' => true,
            'line_template' => [['type' => 'service', 'description' => 'Synthetic service', 'quantity' => '1', 'unit_amount' => 10000]],
        ]);
    }

    public function test_an_owner_sees_the_whole_action_queue_and_the_revenue_slot(): void
    {
        $this->seedQueue();

        $this->actingAs($this->owner)->get($this->path())->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('workspaces/dashboard')
            ->where('audience', 'workspace')
            ->where('revenue.slot', 'revenue')
            ->has('queue', 5)
            ->where('queue.0.kind', 'overdue_invoice')
            ->where('queue.0.title', 'Invoice SYN-OVERDUE')
            ->where('queue.0.client', 'Synthetic Client')
            ->where('queue.0.amount', 10000)
            ->where('queue.0.href', fn (string $href): bool => str_starts_with($href, "/workspaces/{$this->workspace->public_id}/clients/{$this->company->public_id}/invoices/"))
            ->where('queue.1.kind', 'schedule_due')
            ->where('queue.2.kind', 'draft_invoice')
            ->where('queue.3.kind', 'unapproved_time')
            ->where('queue.3.count', 1)
            ->where('queue.4.kind', 'proposal_awaiting'));
    }

    public function test_an_empty_workspace_has_an_empty_queue(): void
    {
        $this->actingAs($this->owner)->get($this->path())->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('queue', 0));
    }

    public function test_a_member_sees_only_their_own_queue_and_no_revenue_data_at_all(): void
    {
        $this->seedQueue();
        $this->time($this->workspace, $this->company, $this->project, $this->member, 'Member draft work');
        $task = ClientTask::query()->create(['workspace_id' => $this->workspace->id, 'client_project_id' => $this->project->id, 'title' => 'Synthetic member task', 'status' => 'open']);
        ClientTaskAssignment::query()->create(['workspace_id' => $this->workspace->id, 'client_task_id' => $task->id, 'user_id' => $this->member->id]);
        $unassigned = ClientTask::query()->create(['workspace_id' => $this->workspace->id, 'client_project_id' => $this->project->id, 'title' => 'Someone else task', 'status' => 'open']);
        ClientTaskAssignment::query()->create(['workspace_id' => $this->workspace->id, 'client_task_id' => $unassigned->id, 'user_id' => $this->owner->id]);

        $response = $this->actingAs($this->member)->get($this->path())->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->where('audience', 'member')
            ->missing('revenue')
            ->has('queue', 2)
            ->where('queue.0.kind', 'my_draft_time')
            ->where('queue.0.count', 1)
            ->where('queue.1.kind', 'my_task')
            ->where('queue.1.title', 'Synthetic member task'));

        // Nothing billing-shaped reaches a member's browser: no amounts, no
        // invoice numbers, no other person's time or tasks.
        $props = json_encode($response->viewData('page')['props'], JSON_THROW_ON_ERROR);
        foreach (['SYN-OVERDUE', 'SYN-DRAFT', 'Owner draft work', 'Someone else task', 'Synthetic Proposal', '"amount":1', 'revenue'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $props);
        }
        foreach ($response->viewData('page')['props']['queue'] as $row) {
            $this->assertNull($row['amount']);
            $this->assertNull($row['currency']);
        }
    }

    public function test_a_member_outside_every_project_has_an_empty_queue(): void
    {
        $this->seedQueue();
        $stranger = User::factory()->create();
        $this->workspace->memberships()->create(['user_id' => $stranger->id, 'role' => 'member']);

        $this->actingAs($stranger)->get($this->path())->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('queue', 0)->missing('revenue'));
    }

    public function test_another_workspaces_rows_never_appear(): void
    {
        $foreign = Workspace::query()->create(['name' => 'Synthetic Foreign', 'slug' => 'synthetic-foreign']);
        [$foreignCompany, $foreignProject] = $this->clientWithProject($foreign, 'Foreign Client');
        $this->invoice($foreign, $foreignCompany, 'FOREIGN-OVERDUE', 'issued', '2020-01-01');
        $this->invoice($foreign, $foreignCompany, 'FOREIGN-DRAFT', 'draft', null);
        $this->time($foreign, $foreignCompany, $foreignProject, $this->owner, 'Foreign draft work');
        ClientProposal::query()->create(['workspace_id' => $foreign->id, 'client_company_id' => $foreignCompany->id, 'title' => 'Foreign Proposal', 'currency' => 'USD', 'status' => 'sent']);

        foreach (['dashboard', 'all/invoices', 'all/time', 'finance'] as $suffix) {
            $body = (string) $this->actingAs($this->owner)->get($this->path($suffix))->assertOk()->getContent();
            foreach (['FOREIGN-', 'Foreign Client', 'Foreign draft work', 'Foreign Proposal'] as $leak) {
                $this->assertStringNotContainsString($leak, $body, "$suffix leaked $leak");
            }
        }

        // And a row naming another tenant's company is dropped rather than shown.
        $this->writingLegacyCrossTenantRows(fn () => $this->invoice($this->workspace, $foreignCompany, 'CROSS-TENANT', 'issued', '2020-01-01'));
        $this->actingAs($this->owner)->get($this->path())->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('queue', 0));
    }

    public function test_the_queue_does_not_query_once_per_row(): void
    {
        $this->seedQueue();
        $n = 0;
        $this->assertQueryCountIndependentOfRows(
            fn () => $this->actingAs($this->owner)->get($this->path())->assertOk(),
            function () use (&$n): void {
                for ($i = 0; $i < 6; $i++) {
                    [$company, $project] = $this->clientWithProject($this->workspace, 'Bulk Client '.($n++));
                    $this->invoice($this->workspace, $company, 'BULK-'.$n, 'issued', '2020-01-01');
                    $this->invoice($this->workspace, $company, 'BULKD-'.$n, 'draft', null);
                    $this->time($this->workspace, $company, $project, $this->owner, 'bulk');
                }
            },
        );
    }

    public function test_the_member_queue_does_not_query_once_per_row(): void
    {
        $this->time($this->workspace, $this->company, $this->project, $this->member, 'Member work');
        $n = 0;
        $this->assertQueryCountIndependentOfRows(
            fn () => $this->actingAs($this->member)->get($this->path())->assertOk(),
            function () use (&$n): void {
                for ($i = 0; $i < 5; $i++) {
                    $task = ClientTask::query()->create(['workspace_id' => $this->workspace->id, 'client_project_id' => $this->project->id, 'title' => 'Bulk task '.($n++), 'status' => 'open']);
                    ClientTaskAssignment::query()->create(['workspace_id' => $this->workspace->id, 'client_task_id' => $task->id, 'user_id' => $this->member->id]);
                }
            },
        );
    }

    public function test_viewers_outside_the_workspace_cannot_open_the_tier(): void
    {
        $outsider = User::factory()->create();

        foreach (['dashboard', 'all/invoices', 'all/time', 'finance'] as $suffix) {
            $this->actingAs($outsider)->get($this->path($suffix))->assertForbidden();
        }

        auth()->logout();
        $this->get($this->path())->assertRedirect();
    }

    public function test_a_portal_user_has_no_workspace_tier(): void
    {
        $client = User::factory()->create();
        $this->company->portalUsers()->attach($client, ['role' => 'client']);

        foreach (['dashboard', 'all/invoices', 'all/time', 'finance'] as $suffix) {
            $this->actingAs($client)->get($this->path($suffix))->assertForbidden();
        }
    }

    public function test_only_owners_and_admins_open_invoices_time_and_finance(): void
    {
        $this->invoice($this->workspace, $this->company, 'SYN-LISTED', 'issued', '2026-09-01');
        $this->time($this->workspace, $this->company, $this->project, $this->member, 'Listed work');

        foreach (['all/invoices', 'all/time', 'finance'] as $suffix) {
            $this->actingAs($this->member)->get($this->path($suffix))->assertForbidden();
        }

        $this->actingAs($this->owner)->get($this->path('all/invoices'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('workspaces/invoices')->has('invoices', 1)->where('invoices.0.client', 'Synthetic Client'));
        $this->actingAs($this->owner)->get($this->path('all/time'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('workspaces/time')->has('entries', 1)->where('entries.0.project', 'Synthetic Client Project'));
        $this->actingAs($this->owner)->get($this->path('finance'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('workspaces/finance')->has('open_invoices', 1)->where('open_invoices.0.invoice_number', 'SYN-LISTED'));
    }

    public function test_navigation_tabs_are_chosen_per_role_on_the_server(): void
    {
        $destinations = fn (User $user) => $this->actingAs($user)->get($this->path())->assertOk()->viewData('page')['props']['workspaceNavigation']['workspace_destinations'];

        $owner = $destinations($this->owner);
        $this->assertSame("/workspaces/{$this->workspace->public_id}/dashboard", $owner['dashboard']);
        foreach (['clients', 'invoices', 'time', 'finance'] as $tab) {
            $this->assertNotNull($owner[$tab], $tab);
        }

        $member = $destinations($this->member);
        $this->assertNotNull($member['dashboard']);
        $this->assertNotNull($member['clients']);
        foreach (['invoices', 'time', 'finance'] as $tab) {
            $this->assertNull($member[$tab], $tab);
        }
    }

    public function test_the_client_tier_offers_agreements_and_files_to_managers_only(): void
    {
        $tabs = fn (User $user) => $this->actingAs($user)->get("/workspaces/{$this->workspace->public_id}/clients/{$this->company->public_id}")->assertOk()->viewData('page')['props']['workspaceNavigation']['clients'][0]['destinations'];

        $owner = $tabs($this->owner);
        $this->assertStringEndsWith('#agreements', $owner['agreements']);
        $this->assertNotNull($owner['files']);
        $this->assertNotNull($owner['settings']);

        $member = $tabs($this->member);
        $this->assertNotNull($member['agreements']);
        $this->assertNull($member['files']);
        $this->assertNull($member['settings']);
    }

    public function test_the_old_operations_url_redirects_to_the_dashboard(): void
    {
        $this->actingAs($this->owner)->get($this->path('operations'))
            ->assertRedirect(route('workspaces.dashboard', $this->workspace));
    }

    public function test_entering_a_workspace_with_no_client_to_go_to_lands_on_the_dashboard(): void
    {
        // Two clients and nothing remembered: there is nothing to choose for the
        // reader, so the front page of the workspace is the answer.
        ClientCompany::query()->create(['workspace_id' => $this->workspace->id, 'name' => 'Second Client', 'slug' => 'second-client']);

        $this->actingAs($this->owner)->get("/workspaces/{$this->workspace->public_id}")
            ->assertRedirect("/workspaces/{$this->workspace->public_id}/dashboard");
    }
}
