<?php

namespace Tests\Concerns;

use App\Exceptions\AccessMutationRefused;
use App\Models\ClientCompany;
use App\Models\ClientProject;
use App\Models\ClientProjectMembership;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Access\AccessRefusal;
use App\Support\AgentApi\ProjectRole;
use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

/** Synthetic staff, clients and projects for access-boundary tests. */
trait BuildsAccessFixtures
{
    /**
     * Run `$change` once, inside the boundary's transaction, right after it
     * takes the workspace access lock and before it re-reads authority.
     *
     * One connection, so the change is part of the boundary's transaction and
     * rolls back with its refusal; what it proves is that the decision is made
     * from what is read under the lock, not from the fast check before it.
     */
    protected function onceTheWorkspaceIsLocked(Closure $change): void
    {
        $baseline = DB::transactionLevel();
        $fired = false;
        DB::listen(function (QueryExecuted $query) use (&$fired, $baseline, $change): void {
            // Either quoting style: SQLite writes "workspaces", MariaDB `workspaces`.
            if ($fired || DB::transactionLevel() <= $baseline || preg_match('/from [`"]workspaces[`"]/i', $query->sql) !== 1) {
                return;
            }
            $fired = true;
            $change();
        });
    }

    protected function assertRefused(AccessRefusal $reason, Closure $operation, string $message = ''): void
    {
        try {
            $operation();
        } catch (AccessMutationRefused $refused) {
            $this->assertSame($reason, $refused->reason, $message);

            return;
        }

        $this->fail(trim("Expected the access change to be refused as {$reason->value}. {$message}"));
    }

    protected function staff(Workspace $workspace, string $role): User
    {
        $user = User::factory()->create();
        $this->addStaff($workspace, $user, $role);

        return $user;
    }

    protected function addStaff(Workspace $workspace, User $user, string $role): void
    {
        $workspace->memberships()->create(['user_id' => $user->id, 'role' => $role]);
    }

    protected function company(Workspace $workspace, string $slug): ClientCompany
    {
        return ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => $slug, 'slug' => $slug]);
    }

    protected function project(ClientCompany $company, string $name): ClientProject
    {
        return ClientProject::query()->create(['workspace_id' => $company->workspace_id, 'client_company_id' => $company->id, 'name' => $name]);
    }

    protected function assign(User $user, ClientProject $project, ProjectRole $role): void
    {
        ClientProjectMembership::query()->create([
            'workspace_id' => $project->workspace_id,
            'client_project_id' => $project->id,
            'user_id' => $user->id,
            'role' => $role,
        ]);
    }
}
