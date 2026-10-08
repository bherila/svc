<?php

namespace App\Services\Expenses;

use App\Models\ClientCompany;
use App\Models\ClientExpense;
use App\Models\ClientProject;
use App\Models\User;
use App\Models\Workspace;
use App\Queries\Expenses\WorkspaceExpenses;
use App\Support\Expenses\NewExpense;

/** Shared web and API expense operations; lifecycle and locks belong to the scoped DAO. */
final class ExpenseAction
{
    public function record(Workspace $workspace, ClientCompany $company, ?ClientProject $project, NewExpense $facts, User $actor): ClientExpense
    {
        return (new WorkspaceExpenses($workspace))->record($company, $project, $facts, $actor);
    }

    public function update(Workspace $workspace, string $id, NewExpense $facts, ?string $projectId, bool $reattribute, ?string $expectedVersion = null): ClientExpense
    {
        $expense = $this->find($workspace, $id);

        return (new WorkspaceExpenses($workspace))->update($expense, $facts,
            $reattribute ? $this->project($workspace, $expense->client_company_id, $projectId) : null, $reattribute, $expectedVersion);
    }

    public function approve(Workspace $workspace, string $id, User $actor, ?string $expectedVersion = null): ClientExpense
    {
        return (new WorkspaceExpenses($workspace))->approve($this->find($workspace, $id), $actor, $expectedVersion);
    }

    public function unapprove(Workspace $workspace, string $id, ?string $expectedVersion = null): ClientExpense
    {
        return (new WorkspaceExpenses($workspace))->unapprove($this->find($workspace, $id), $expectedVersion);
    }

    public function discard(Workspace $workspace, string $id, ?string $expectedVersion = null, bool $draftOnly = false): void
    {
        (new WorkspaceExpenses($workspace))->discard($this->find($workspace, $id), $expectedVersion, $draftOnly);
    }

    private function find(Workspace $workspace, string $id): ClientExpense
    {
        return (new WorkspaceExpenses($workspace))->query()->where('public_id', $id)->firstOrFail();
    }

    private function project(Workspace $workspace, int $companyId, ?string $id): ?ClientProject
    {
        return $id === null ? null : ClientProject::query()->where('workspace_id', $workspace->id)
            ->where('client_company_id', $companyId)->where('public_id', $id)->firstOrFail();
    }
}
