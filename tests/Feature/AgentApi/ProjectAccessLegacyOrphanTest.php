<?php

namespace Tests\Feature\AgentApi;

use App\Models\ClientCompany;
use App\Models\ClientProject;
use App\Models\ClientProjectMembership;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Services\Authorization\ProjectAccess;
use App\Support\AgentApi\ProjectRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Tests\TestCase;

final class ProjectAccessLegacyOrphanTest extends TestCase
{
    use RefreshDatabase;

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_lingering_project_rows_never_grant_access_without_workspace_membership(): void
    {
        $workspace = Workspace::query()->create(['name' => 'Legacy', 'slug' => 'legacy']);
        $company = ClientCompany::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Legacy Client',
            'slug' => 'legacy-client',
        ]);
        $project = ClientProject::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'name' => 'Legacy Project',
        ]);
        $users = [];

        foreach (ProjectRole::cases() as $role) {
            $user = User::factory()->create();
            WorkspaceMembership::query()->create([
                'workspace_id' => $workspace->id,
                'user_id' => $user->id,
                'role' => 'member',
            ]);
            ClientProjectMembership::query()->create([
                'workspace_id' => $workspace->id,
                'client_project_id' => $project->id,
                'user_id' => $user->id,
                'role' => $role,
            ]);
            $users[] = $user;
        }

        Schema::disableForeignKeyConstraints();
        try {
            WorkspaceMembership::query()->where('workspace_id', $workspace->id)->delete();
        } finally {
            Schema::enableForeignKeyConstraints();
        }

        $this->assertSame(count(ProjectRole::cases()), ClientProjectMembership::query()->count());
        foreach ($users as $user) {
            $this->assertNull(app(ProjectAccess::class)->projectRole($user, $project));
            $this->assertFalse(app(ProjectAccess::class)->canView($user, $project));
            $this->assertFalse(app(ProjectAccess::class)->canManageTasks($user, $project));
            $this->assertFalse(app(ProjectAccess::class)->canApproveTime($user, $project));
        }
    }

    public function beginDatabaseTransaction(): void
    {
        // This isolated process intentionally needs foreign-key enforcement toggled
        // to reproduce project rows left behind by a legacy membership removal.
        // SQLite refuses to change `PRAGMA foreign_keys` inside a transaction, so
        // there can be no transaction to roll back - which means this test has to
        // clean up after itself. See tearDown.
    }

    /**
     * Everything above is committed, so it has to be removed by hand.
     *
     * On in-memory SQLite this was invisible: the separate process gets its own
     * database and the committed rows die with it. Against a shared server they
     * survive, and every later test sees a workspace and a client company it
     * did not create - which is how one uncleaned test failed eight others.
     */
    protected function tearDown(): void
    {
        Schema::disableForeignKeyConstraints();

        try {
            foreach (['client_project_memberships', 'workspace_memberships', 'client_projects', 'client_companies', 'workspaces', 'users'] as $table) {
                DB::table($table)->delete();
            }
        } finally {
            Schema::enableForeignKeyConstraints();
        }

        parent::tearDown();
    }
}
