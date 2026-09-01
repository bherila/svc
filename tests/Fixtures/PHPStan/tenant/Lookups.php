<?php

declare(strict_types=1);

namespace Tests\Fixtures\PHPStan\tenant;

use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientProjectMembership;
use App\Models\ExternalImportRun;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvoiceCounter;
use App\Models\WorkspaceMembership;

/**
 * Both sides of the tenant-lookup rule.
 *
 * The allowed cases matter as much as the refused ones: a rule that flagged
 * `$workspace->clientCompanies()->find()` would be turned off rather than
 * obeyed, so the shapes people actually write have to pass.
 */
final class Lookups
{
    /** Refused: a key lookup with no workspace in it. */
    public function unscoped(int $id): ?ClientInvoice
    {
        return ClientInvoice::find($id);
    }

    public function unscopedOrFail(int $id): ClientInvoice
    {
        return ClientInvoice::findOrFail($id);
    }

    /** Refused even without the common trait: the ownership contract decides. */
    public function unscopedImportRun(int $id): ?ExternalImportRun
    {
        return ExternalImportRun::find($id);
    }

    public function unscopedWorkspaceMembership(int $id): ?WorkspaceMembership
    {
        return WorkspaceMembership::find($id);
    }

    public function unscopedProjectMembership(int $id): ?ClientProjectMembership
    {
        return ClientProjectMembership::find($id);
    }

    /** Refused through inherited ownership as well. */
    public function unscopedSubclass(int $id): ?TenantInvoiceSubclass
    {
        return TenantInvoiceSubclass::find($id);
    }

    /** Refused, and worse than a read: an unscoped delete leaves nothing behind. */
    public function unscopedDestroy(int $id): int
    {
        return ClientInvoice::destroy($id);
    }

    /** Allowed: the id is resolved inside a query that names the workspace. */
    public function scoped(Workspace $workspace, int $id): mixed
    {
        return ClientInvoice::query()->where('workspace_id', $workspace->id)->find($id);
    }

    /** Allowed: scoped through the relation, which carries the workspace. */
    public function throughRelation(Workspace $workspace, int $id): mixed
    {
        return $workspace->clientCompanies()->find($id);
    }

    /** Allowed: a model that belongs to no workspace has nothing to scope by. */
    public function notATenantModel(int $id): ?User
    {
        return User::find($id);
    }

    /** Allowed: not a key lookup at all. */
    public function scopedByColumn(Workspace $workspace): mixed
    {
        return ClientInvoice::where('workspace_id', $workspace->id)->get();
    }

    /**
     * Allowed: the primary key *is* the workspace, so the key scopes it.
     *
     * The counter is keyed on `workspace_id`, so this call names one workspace
     * and can reach no other. It is the one bare key lookup that carries a
     * tenant by construction.
     */
    public function keyedByWorkspace(Workspace $workspace): ?WorkspaceInvoiceCounter
    {
        return WorkspaceInvoiceCounter::find($workspace->id);
    }

    /** Allowed: whereKey() is deferred and the executed query is scoped. */
    public function deferredKeyThenScoped(Workspace $workspace, int $id): mixed
    {
        return ClientCompany::whereKey($id)
            ->where('workspace_id', $workspace->id)
            ->first();
    }
}

final class TenantInvoiceSubclass extends ClientInvoice {}
