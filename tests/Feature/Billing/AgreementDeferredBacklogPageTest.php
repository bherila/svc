<?php

namespace Tests\Feature\Billing;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientProject;
use App\Models\ClientProjectMembership;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\DeferredBacklogReadService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The admin agreement page shows a manager the deferred backlog (#366); a
 * client portal reader of the same agreement never receives it.
 */
final class AgreementDeferredBacklogPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_manager_sees_the_backlog_the_read_service_computes(): void
    {
        $this->travelTo(Carbon::parse('2026-05-20 12:00:00'));
        [$admin, , $workspace, $company, $agreement] = $this->tenant();

        $expected = app(DeferredBacklogReadService::class)->trailing($workspace, $agreement);
        $this->assertEquals(2.5, $expected['hours']);
        $this->assertSame(['2026-03', '2026-04', '2026-05'], array_column($expected['trend'], 'period'));

        $this->actingAs($admin)
            ->get("/workspaces/{$workspace->public_id}/clients/{$company->public_id}/agreements/{$agreement->public_id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('clients/agreement')
                ->where('deferred_backlog.hours', 2.5)
                ->where('deferred_backlog.entry_count', 2)
                ->where('deferred_backlog.amount_at_hourly_rate', ['currency' => 'USD', 'amount' => 37500])
                ->where('deferred_backlog.trend', fn ($trend): bool => $trend->pluck('hours_added')->all() == [1.5, 1, 0] && $trend->pluck('period')->all() === ['2026-03', '2026-04', '2026-05'])
                ->missing('deferred_backlog.entry_ids'));
    }

    public function test_a_member_without_management_and_a_portal_client_receive_none(): void
    {
        [, $clientUser, $workspace, $company, $agreement, $project] = $this->tenant();
        // A project member may read a project-scoped agreement, and still has
        // no authority over - nor sight of - the client's agreement-rate price.
        $agreement->forceFill(['client_project_id' => $project->id])->save();
        $member = User::factory()->create();
        $workspace->memberships()->create(['user_id' => $member->id, 'role' => 'member']);
        ClientProjectMembership::query()->create([
            'workspace_id' => $workspace->id,
            'client_project_id' => $project->id,
            'user_id' => $member->id,
            'role' => 'contributor',
        ]);

        $this->actingAs($member)
            ->get("/workspaces/{$workspace->public_id}/clients/{$company->public_id}/agreements/{$agreement->public_id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('clients/agreement')
                ->where('actions.update', null)
                ->where('deferred_backlog', null));

        $this->actingAs($clientUser)
            ->get("/portal/{$company->public_id}/agreements/{$agreement->public_id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('clients/agreement')
                ->where('deferred_backlog', null));
    }

    /** @return array{User, User, Workspace, ClientCompany, ClientAgreement, ClientProject} */
    private function tenant(): array
    {
        $admin = User::factory()->create(['email' => 'backlog-admin@synthetic.test']);
        $clientUser = User::factory()->create(['email' => 'backlog-client@synthetic.test']);
        $workspace = Workspace::query()->create(['name' => 'Synthetic Backlog Page', 'slug' => 'synthetic-backlog-page']);
        $workspace->memberships()->create(['user_id' => $admin->id, 'role' => 'admin']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic Backlog Client', 'slug' => 'synthetic-backlog-client']);
        $company->portalUsers()->attach($clientUser, ['role' => 'client']);
        $project = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Synthetic Backlog Project']);
        $agreement = ClientAgreement::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'title' => 'Synthetic backlog agreement',
            'starts_on' => '2026-01-01',
            'status' => 'active',
            'is_visible_to_client' => true,
            'currency' => 'USD',
            'billing_cadence' => 'monthly',
            'retainer_minutes' => 600,
            'hourly_rate_amount' => 15000,
        ]);
        foreach ([[90, '2026-03-02'], [60, '2026-04-14']] as [$minutes, $workedOn]) {
            ClientTimeEntry::query()->create([
                'workspace_id' => $workspace->id,
                'client_company_id' => $company->id,
                'client_project_id' => $project->id,
                'user_id' => $admin->id,
                'worked_on' => $workedOn,
                'minutes' => $minutes,
                'description' => 'Synthetic deferred work',
                'is_billable' => true,
                'is_deferred' => true,
                'status' => 'approved',
                'billing_rate_amount' => 15000,
                'currency' => 'USD',
            ]);
        }

        return [$admin, $clientUser, $workspace, $company, $agreement, $project];
    }
}
