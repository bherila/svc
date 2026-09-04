<?php

namespace App\Queries\Expenses;

use App\Exceptions\CrossTenantReference;
use App\Models\ClientCompany;
use App\Models\ClientExpense;
use App\Models\ClientProject;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Expenses\ExpenseStatus;
use App\Support\Expenses\NewExpense;
use Illuminate\Database\Eloquent\Builder;

/**
 * Every read and write of `client_expenses`, scoped to one workspace.
 *
 * The point of the type is that the workspace is supplied once, at
 * construction, and nothing that follows can widen it. Expenses are money owed
 * back to whoever paid it and they carry a client's receipts, so the surfaces
 * that will read them - a manager list, an approval screen, the invoicing hook
 * in #75's third slice - should be reading through a scope none of them wrote.
 *
 * That is not a stylistic preference. The single most-repeated finding across
 * this port was a tenant-owned row reached on its parent's authority, fixed nine
 * times in nine places. `docs/client-management/tenant-foreign-keys.md` records
 * the schema half of the answer; this is the application half for this table,
 * arriving with it rather than after the third surface has already gone its own
 * way.
 *
 * Nothing here transitions an expense. Approval, and the claim/release rules
 * that go with regenerating a draft invoice, wait for the centralized lock
 * discipline in #117.
 */
final class WorkspaceExpenses
{
    public function __construct(private readonly Workspace $workspace) {}

    /**
     * Expenses in this workspace, and nothing else.
     *
     * @return Builder<ClientExpense>
     */
    public function query(): Builder
    {
        return ClientExpense::query()->where('workspace_id', $this->workspace->id);
    }

    /**
     * One expense by its public id, or null.
     *
     * Null covers both "no such expense" and "that expense is another
     * tenant's", because a caller must not be able to tell the two apart: a
     * boundary that answers differently for a row it refuses to show has
     * confirmed the row exists.
     */
    public function find(string $publicId): ?ClientExpense
    {
        return $this->query()->where('public_id', $publicId)->first();
    }

    /**
     * Record a draft expense against a company, optionally attributed to one of
     * its projects.
     *
     * The company and the project are checked against this workspace here, and
     * the project against the company, before anything is written. The database
     * refuses a cross-tenant company on its own - `cex_ws_company_fk` - but it
     * cannot refuse a cross-tenant project: that column is nullable with an
     * `ON DELETE SET NULL` rule, which InnoDB will not carry inside a composite
     * key over the NOT NULL `workspace_id` (errno 1830). So the project check is
     * this method's to make, and the exemption is registered in
     * `App\Support\Tenancy\TenantReferenceInventory` so the audit command keeps
     * counting it.
     *
     * The company check is not redundant beside its key either. It names the
     * mistake where it was made instead of surfacing a driver-level constraint
     * error from three layers down, and it still holds on a database migrated
     * from before those keys existed.
     *
     * Expenses start as drafts. Passing an approved one in is not possible:
     * {@see NewExpense} carries no status.
     */
    public function record(
        ClientCompany $company,
        ?ClientProject $project,
        NewExpense $expense,
        ?User $recordedBy = null,
    ): ClientExpense {
        if ($company->workspace_id !== $this->workspace->id) {
            throw new CrossTenantReference('That client company belongs to another workspace.');
        }

        if ($project !== null && $project->workspace_id !== $this->workspace->id) {
            throw new CrossTenantReference('That project belongs to another workspace.');
        }

        if ($project !== null && $project->client_company_id !== $company->id) {
            throw new CrossTenantReference('That project belongs to another client company.');
        }

        return ClientExpense::query()->create([
            ...$expense->attributes(),
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $company->id,
            'client_project_id' => $project?->id,
            'created_by_user_id' => $recordedBy?->id,
            'status' => ExpenseStatus::Draft->value,
        ]);
    }
}
