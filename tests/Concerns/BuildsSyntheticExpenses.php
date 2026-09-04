<?php

namespace Tests\Concerns;

use App\Models\ClientCompany;
use App\Models\ClientExpense;
use App\Models\ClientProject;
use App\Models\User;
use App\Models\Workspace;
use App\Queries\Expenses\WorkspaceExpenses;
use App\Support\Expenses\NewExpense;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Synthetic tenants, clients and expenses for the expense suite.
 *
 * Every value here is obviously invented and stays that way: names carry a
 * `Synthetic` prefix, emails use the reserved `example.test` domain, and amounts
 * are round numbers of minor units. Nothing in this repository may carry a real
 * client, a real receipt or a real address, and a fixture builder is exactly
 * where one would otherwise creep in one careful copy-paste at a time.
 *
 * The builders return the models rather than ids so a test says which workspace
 * a thing belongs to in its own text. Isolation tests are only worth their
 * runtime when the second tenant in them is visibly a second tenant.
 */
trait BuildsSyntheticExpenses
{
    protected function syntheticWorkspace(string $label): Workspace
    {
        return Workspace::query()->create([
            'name' => 'Synthetic '.$label,
            'slug' => 'synthetic-'.Str::slug($label).'-'.Str::lower(Str::random(8)),
        ]);
    }

    protected function syntheticUser(string $label): User
    {
        return User::factory()->create([
            'name' => 'Synthetic '.$label,
            'email' => Str::slug($label).'-'.Str::lower(Str::random(8)).'@example.test',
        ]);
    }

    /**
     * A user who is a member of the workspace, which approval requires.
     *
     * Separate from {@see syntheticUser()} on purpose: the difference between a
     * user and a *member* is the whole of the approver check, so a builder that
     * enrolled everyone would make that check untestable by making every
     * fixture pass it.
     */
    protected function syntheticMember(Workspace $workspace, string $label, string $role = 'admin'): User
    {
        $user = $this->syntheticUser($label);
        $workspace->memberships()->create(['user_id' => $user->id, 'role' => $role]);

        return $user;
    }

    protected function syntheticCompany(Workspace $workspace, string $label): ClientCompany
    {
        return ClientCompany::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Synthetic '.$label.' Client',
            'slug' => 'synthetic-'.Str::slug($label).'-client-'.Str::lower(Str::random(8)),
        ]);
    }

    protected function syntheticProject(ClientCompany $company, string $label): ClientProject
    {
        return ClientProject::query()->create([
            'workspace_id' => $company->workspace_id,
            'client_company_id' => $company->id,
            'name' => 'Synthetic '.$label.' Project',
        ]);
    }

    /** The facts of an expense, without any tenancy: that is the boundary's to supply. */
    protected function syntheticExpenseFacts(
        string $label = 'travel',
        int $amount = 12_500,
        string $currency = 'USD',
        string $spentOn = '2026-08-15',
    ): NewExpense {
        return new NewExpense(
            CarbonImmutable::parse($spentOn),
            $amount,
            $currency,
            'Synthetic '.$label.' expense',
        );
    }

    /** A recorded draft expense, written through the workspace-scoped boundary. */
    protected function recordSyntheticExpense(
        Workspace $workspace,
        ClientCompany $company,
        ?ClientProject $project = null,
        ?NewExpense $facts = null,
        ?User $recordedBy = null,
    ): ClientExpense {
        return (new WorkspaceExpenses($workspace))->record(
            $company,
            $project,
            $facts ?? $this->syntheticExpenseFacts(),
            $recordedBy,
        );
    }
}
