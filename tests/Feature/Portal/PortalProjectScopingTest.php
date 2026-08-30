<?php

namespace Tests\Feature\Portal;

use App\Models\AgentPrincipal;
use App\Models\ClientCompany;
use App\Models\ClientCompanyMembership;
use App\Models\ClientProject;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Services\Authorization\AgentAccess;
use App\Services\Authorization\PortalAccess;
use App\Support\AgentApi\AgentApiScopes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;
use Tests\Concerns\WritesLegacyCrossTenantRows;
use Tests\TestCase;

/**
 * The portal authorised at company level and filtered only on
 * is_visible_to_client, so a client saw every visible project their company
 * owned regardless of which ones they belonged to.
 */
final class PortalProjectScopingTest extends TestCase
{
    use RefreshDatabase;
    use WritesLegacyCrossTenantRows;

    private Workspace $workspace;

    private ClientCompany $company;

    private ClientProject $theirs;

    private ClientProject $someoneElses;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workspace = Workspace::query()->create(['name' => 'Portal', 'slug' => 'portal']);
        $this->company = ClientCompany::query()->create([
            'workspace_id' => $this->workspace->id, 'name' => 'Portal Client', 'slug' => 'portal-client',
        ]);
        $this->theirs = $this->project('Theirs');
        $this->someoneElses = $this->project('Someone Elses');
    }

    public function test_a_project_scoped_client_sees_only_their_own_projects(): void
    {
        $user = $this->portalUser(ClientCompanyMembership::SCOPE_PROJECTS);
        $this->grantProject($user, $this->theirs);

        $names = $this->projectNamesFor($user);

        $this->assertSame(['Theirs'], $names);
        $this->assertNotContains('Someone Elses', $names);
    }

    public function test_a_project_scoped_client_with_no_memberships_sees_nothing(): void
    {
        $user = $this->portalUser(ClientCompanyMembership::SCOPE_PROJECTS);

        $this->assertSame([], $this->projectNamesFor($user));
    }

    public function test_a_company_scoped_client_still_sees_the_whole_company(): void
    {
        // The default. Narrowing is opt-in, so existing portal users are unaffected.
        $user = $this->portalUser(ClientCompanyMembership::SCOPE_COMPANY);

        $this->assertEqualsCanonicalizing(['Theirs', 'Someone Elses'], $this->projectNamesFor($user));
    }

    public function test_a_workspace_member_is_never_narrowed(): void
    {
        $staff = User::factory()->create();
        WorkspaceMembership::query()->create([
            'workspace_id' => $this->workspace->id, 'user_id' => $staff->id, 'role' => 'admin',
        ]);

        $this->assertEqualsCanonicalizing(['Theirs', 'Someone Elses'], $this->projectNamesFor($staff));
    }

    public function test_a_stranger_cannot_open_the_portal_at_all(): void
    {
        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->get("/portal/{$this->company->public_id}")
            ->assertForbidden();
    }

    public function test_client_visible_time_is_shown_without_rates(): void
    {
        $user = $this->portalUser(ClientCompanyMembership::SCOPE_COMPANY);
        $this->timeEntry($this->theirs, visible: true, description: 'Internal note', clientDescription: 'Shown to the client');
        $this->timeEntry($this->theirs, visible: false, description: 'Internal only');

        $project = collect($this->portalProps($user)['company']['projects'])
            ->firstWhere('name', 'Theirs');

        $this->assertCount(1, $project['time_entries']);
        $this->assertSame('Shown to the client', $project['time_entries'][0]['description']);
        $this->assertArrayNotHasKey('billing_rate_amount', $project['time_entries'][0]);
        $this->assertArrayNotHasKey('subcontractor_cost_amount', $project['time_entries'][0]);
    }

    public function test_time_without_a_client_safe_description_is_withheld(): void
    {
        $user = $this->portalUser(ClientCompanyMembership::SCOPE_COMPANY);
        // Marked visible, but nobody wrote a client-facing description. The
        // internal note may say anything, so it must not stand in for one.
        $this->timeEntry($this->theirs, visible: true, description: 'Chasing their unpaid invoice');

        $project = collect($this->portalProps($user)['company']['projects'])
            ->firstWhere('name', 'Theirs');

        $this->assertNull($project['time_entries'][0]['description']);
    }

    public function test_a_client_cannot_write_time(): void
    {
        $user = $this->portalUser(ClientCompanyMembership::SCOPE_COMPANY);

        $this->actingAs($user)->postJson(
            "/workspaces/{$this->workspace->public_id}/projects/{$this->theirs->public_id}/time-entries",
            ['worked_on' => '2026-03-14', 'minutes' => 60, 'description' => 'Trying to log time'],
        )->assertForbidden();

        $this->assertSame(0, ClientTimeEntry::query()->count());
    }

    public function test_an_operator_can_narrow_and_restore_portal_access(): void
    {
        $user = $this->portalUser(ClientCompanyMembership::SCOPE_COMPANY);

        $this->artisan('svc:portal:project-access', [
            'company' => $this->company->public_id,
            'email' => $user->email,
            '--project' => [$this->theirs->public_id],
        ])->assertSuccessful();

        $this->assertSame(['Theirs'], $this->projectNamesFor($user));

        $this->artisan('svc:portal:project-access', [
            'company' => $this->company->public_id,
            'email' => $user->email,
            '--company-wide' => true,
        ])->assertSuccessful();

        $this->assertEqualsCanonicalizing(['Theirs', 'Someone Elses'], $this->projectNamesFor($user));
    }

    public function test_an_operator_cannot_grant_another_companys_project(): void
    {
        $user = $this->portalUser(ClientCompanyMembership::SCOPE_COMPANY);

        $elsewhere = Workspace::query()->create(['name' => 'Elsewhere', 'slug' => 'elsewhere']);
        $elsewhereCompany = ClientCompany::query()->create([
            'workspace_id' => $elsewhere->id, 'name' => 'Elsewhere Client', 'slug' => 'elsewhere-client',
        ]);
        $foreign = ClientProject::query()->create([
            'workspace_id' => $elsewhere->id, 'client_company_id' => $elsewhereCompany->id,
            'name' => 'Foreign', 'is_visible_to_client' => true,
        ]);

        $this->artisan('svc:portal:project-access', [
            'company' => $this->company->public_id,
            'email' => $user->email,
            '--project' => [$foreign->public_id],
        ])->assertFailed();
    }

    public function test_a_project_scoped_client_cannot_reach_an_ungranted_projects_attachment(): void
    {
        $user = $this->portalUser(ClientCompanyMembership::SCOPE_PROJECTS);
        $this->grantProject($user, $this->theirs);

        $access = app(PortalAccess::class);

        // Scoping the page is not enough: a held attachment URL resolves through
        // this path, so the same decision has to hold here.
        $this->assertTrue($access->canViewProject($user, $this->theirs));
        $this->assertFalse($access->canViewProject($user, $this->someoneElses));
    }

    /** @return list<string> */
    private function projectNamesFor(User $user): array
    {
        return array_values(array_map(
            static fn (array $project): string => $project['name'],
            $this->portalProps($user)['company']['projects'],
        ));
    }

    /** @return array<string, mixed> */
    private function portalProps(User $user): array
    {
        $response = $this->actingAs($user)->get("/portal/{$this->company->public_id}");
        $response->assertOk();

        /** @var array<string, mixed> $props */
        $props = $response->viewData('page')['props'];

        return $props;
    }

    /**
     * The narrowing has to hold on every surface, not just the page.
     *
     * It was wired into the portal view and the attachment resolver and not into
     * the read API, which authorised portal callers at company level. A user
     * granted one project could list every client-visible project, task and
     * approved time entry the company had - the same hole, one route across.
     */
    public function test_the_read_api_honours_project_scoping(): void
    {
        $user = $this->portalUser(ClientCompanyMembership::SCOPE_PROJECTS);
        $this->grantProject($user, $this->theirs);

        $mine = $this->timeEntry($this->theirs, true, 'Mine', 'Mine for the client');
        $this->timeEntry($this->someoneElses, true, 'Not mine', 'Not mine for the client');

        Passport::actingAs(AgentPrincipal::query()->findOrFail($user->id), [AgentApiScopes::TIME_READ, AgentApiScopes::PROJECTS_READ]);

        $projects = $this->getJson("/api/v1/workspaces/{$this->workspace->public_id}/projects")
            ->assertOk()->json('data');
        $this->assertSame(
            [$this->theirs->public_id],
            array_map(static fn (array $row): string => $row['id'], $projects),
            'The read API listed a project this user was never granted',
        );

        $entries = $this->getJson("/api/v1/workspaces/{$this->workspace->public_id}/time-entries")
            ->assertOk()->json('data');
        $this->assertSame(
            [$mine->public_id],
            array_map(static fn (array $row): string => $row['id'], $entries),
            "The read API returned another project's time",
        );
    }

    /**
     * A membership whose own workspace is another tenant's grants nothing.
     *
     * `client_company_memberships` became tenant-owned in #113, and the composite
     * key makes this row unstorable - but a database migrated from before it can
     * hold one, and these reads are what would consume it. Every one of them
     * matched on company and user alone, so the row was honoured on its company
     * link while its stated owner was somewhere else entirely.
     *
     * The company is this workspace's throughout; only the membership disagrees.
     */
    public function test_a_membership_owned_by_another_workspace_grants_no_portal_access(): void
    {
        $foreign = Workspace::query()->create(['name' => 'Foreign portal', 'slug' => 'foreign-portal']);
        $user = User::factory()->create();

        $membership = $this->writingLegacyCrossTenantRows(function () use ($foreign, $user): ClientCompanyMembership {
            $membership = ClientCompanyMembership::query()->create([
                'client_company_id' => $this->company->id,
                'user_id' => $user->id,
                'role' => 'client',
                'access_scope' => ClientCompanyMembership::SCOPE_COMPANY,
            ]);

            $membership->forceFill(['workspace_id' => $foreign->id])->save();

            return $membership;
        });

        $this->assertSame($foreign->id, $membership->fresh()?->workspace_id);

        // visibleProjectIds(): a company-scoped membership returns null, meaning
        // unrestricted. Read on the company alone, this foreign row would say so.
        $this->assertSame([], app(PortalAccess::class)->visibleProjectIds($this->company, $user));
        $this->assertFalse(app(PortalAccess::class)->canViewProject($user, $this->theirs));

        // constrainProjectQuery(): the unrestricted-membership subquery.
        $this->assertSame([], $this->constrainedProjectNames($user));
    }

    /**
     * The narrowed branch of `constrainProjectQuery()` joins the grant to its
     * membership, and that join was on the key alone.
     */
    public function test_a_grant_held_by_a_foreign_membership_does_not_widen_a_project_query(): void
    {
        $foreign = Workspace::query()->create(['name' => 'Foreign grant', 'slug' => 'foreign-grant']);
        $user = User::factory()->create();

        $this->writingLegacyCrossTenantRows(function () use ($foreign, $user): void {
            $membership = ClientCompanyMembership::query()->create([
                'client_company_id' => $this->company->id,
                'user_id' => $user->id,
                'role' => 'client',
                'access_scope' => ClientCompanyMembership::SCOPE_PROJECTS,
            ]);
            $membership->forceFill(['workspace_id' => $foreign->id])->save();

            DB::table('client_portal_project_access')->insert([
                'workspace_id' => $foreign->id,
                'client_company_membership_id' => $membership->id,
                'client_project_id' => $this->theirs->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->assertSame([], $this->constrainedProjectNames($user));
    }

    /**
     * The CLI grants and revokes portal access, so it must not find that row
     * either - it would report on, and rewrite, another tenant's membership.
     */
    public function test_the_portal_access_command_refuses_a_membership_from_another_workspace(): void
    {
        $foreign = Workspace::query()->create(['name' => 'Foreign cli', 'slug' => 'foreign-cli']);
        $user = User::factory()->create();

        $this->writingLegacyCrossTenantRows(function () use ($foreign, $user): void {
            $membership = ClientCompanyMembership::query()->create([
                'client_company_id' => $this->company->id,
                'user_id' => $user->id,
                'role' => 'client',
                'access_scope' => ClientCompanyMembership::SCOPE_COMPANY,
            ]);
            $membership->forceFill(['workspace_id' => $foreign->id])->save();
        });

        $this->artisan('svc:portal:project-access', [
            'company' => $this->company->public_id,
            'email' => $user->email,
            '--show' => true,
        ])->assertExitCode(1);
    }

    /**
     * The workspace-level authorization reads had the same gap, from the other side.
     *
     * `AgentAccess` and the invoice index filtered on the *company's* workspace,
     * which a membership claiming another tenant satisfies as long as the company
     * it names is here. Both halves are now one definition -
     * `AgentAccess::portalCompanyIdsIn()` - and the Agent API, the invoice list
     * and the invoice authorization all route through it.
     */
    public function test_a_membership_owned_by_another_workspace_authorizes_nothing(): void
    {
        $foreign = Workspace::query()->create(['name' => 'Foreign agent', 'slug' => 'foreign-agent']);
        $user = User::factory()->create();

        $this->writingLegacyCrossTenantRows(function () use ($foreign, $user): void {
            $membership = ClientCompanyMembership::query()->create([
                'client_company_id' => $this->company->id,
                'user_id' => $user->id,
                'role' => 'client',
                'access_scope' => ClientCompanyMembership::SCOPE_COMPANY,
            ]);
            $membership->forceFill(['workspace_id' => $foreign->id])->save();
        });

        $access = app(AgentAccess::class);

        $this->assertSame([], $access->portalCompanyIdsIn($user, $this->workspace));
        $this->assertFalse($access->isWorkspaceClient($user, $this->workspace));
        $this->assertFalse($access->canViewWorkspace($user, $this->workspace));
    }

    /**
     * And the mirror: a membership of this workspace naming a company elsewhere.
     */
    public function test_a_membership_naming_a_company_elsewhere_authorizes_nothing(): void
    {
        $foreign = Workspace::query()->create(['name' => 'Elsewhere agent', 'slug' => 'elsewhere-agent']);
        $foreignCompany = ClientCompany::query()->create([
            'workspace_id' => $foreign->id, 'name' => 'Elsewhere Agent Client', 'slug' => 'elsewhere-agent-client',
        ]);
        $user = User::factory()->create();

        $this->writingLegacyCrossTenantRows(function () use ($foreignCompany, $user): void {
            $membership = ClientCompanyMembership::query()->create([
                'client_company_id' => $foreignCompany->id,
                'user_id' => $user->id,
                'role' => 'client',
                'access_scope' => ClientCompanyMembership::SCOPE_COMPANY,
            ]);
            $membership->forceFill(['workspace_id' => $this->workspace->id])->save();
        });

        $access = app(AgentAccess::class);

        $this->assertSame([], $access->portalCompanyIdsIn($user, $this->workspace));
        $this->assertFalse($access->canViewWorkspace($user, $this->workspace));
    }

    public function test_a_consistent_membership_still_authorizes_its_own_workspace(): void
    {
        $user = $this->portalUser(ClientCompanyMembership::SCOPE_COMPANY);
        $access = app(AgentAccess::class);

        $this->assertSame([$this->company->id], $access->portalCompanyIdsIn($user, $this->workspace));
        $this->assertTrue($access->canViewWorkspace($user, $this->workspace));
    }

    /** @return list<string> */
    private function constrainedProjectNames(User $user): array
    {
        return app(PortalAccess::class)
            ->constrainProjectQuery(ClientProject::query(), $user)
            ->pluck('name')
            ->all();
    }

    private function project(string $name): ClientProject
    {
        return ClientProject::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'name' => $name,
            'is_visible_to_client' => true,
        ]);
    }

    private function portalUser(string $scope): User
    {
        $user = User::factory()->create();
        ClientCompanyMembership::query()->create([
            'client_company_id' => $this->company->id,
            'user_id' => $user->id,
            'role' => 'client',
            'access_scope' => $scope,
        ]);

        return $user;
    }

    private function grantProject(User $user, ClientProject $project): void
    {
        $membership = ClientCompanyMembership::query()
            ->where('client_company_id', $this->company->id)
            ->where('user_id', $user->id)
            ->firstOrFail();

        DB::table('client_portal_project_access')->insert([
            'workspace_id' => $this->workspace->id,
            'client_company_membership_id' => $membership->id,
            'client_project_id' => $project->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function timeEntry(ClientProject $project, bool $visible, string $description, ?string $clientDescription = null): ClientTimeEntry
    {
        return ClientTimeEntry::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'client_project_id' => $project->id,
            'user_id' => User::factory()->create()->id,
            'worked_on' => '2026-03-14',
            'minutes' => 60,
            'description' => $description,
            'client_visible_description' => $clientDescription,
            'is_billable' => true,
            'is_visible_to_client' => $visible,
            'status' => 'approved',
            'currency' => 'USD',
        ]);
    }
}
