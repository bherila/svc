<?php

namespace Tests\Feature\Portal;

use App\Models\ClientCompany;
use App\Models\ClientCompanyMembership;
use App\Models\ClientProject;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Services\Authorization\PortalAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The portal authorised at company level and filtered only on
 * is_visible_to_client, so a client saw every visible project their company
 * owned regardless of which ones they belonged to.
 */
final class PortalProjectScopingTest extends TestCase
{
    use RefreshDatabase;

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
