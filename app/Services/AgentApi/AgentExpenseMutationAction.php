<?php

namespace App\Services\AgentApi;

use App\Exceptions\ExpenseTransitionRefused;
use App\Models\ClientCompany;
use App\Models\ClientProject;
use App\Models\User;
use App\Models\Workspace;
use App\Queries\Expenses\WorkspaceExpenses;
use App\Services\Authorization\AgentAccess;
use App\Support\Expenses\NewExpense;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;

/** Idempotency and authorization adapter; expense lifecycle stays in WorkspaceExpenses. */
final class AgentExpenseMutationAction
{
    public function __construct(private readonly AgentMutationExecutor $mutations, private readonly AgentAccess $access) {}

    /** @param array<string,mixed> $payload
     * @return list<string> */
    public function log(User $user, Workspace $workspace, string $clientId, string $key, array $payload): array
    {
        $this->authorize($user, $workspace);
        Validator::make(['key' => $key], ['key' => ['required', 'string', 'max:255']])->validate();

        return $this->mutations->run($user, $workspace, $clientId, 'expenses.log', $key, $payload, function () use ($user, $workspace, $payload): array {
            Validator::make(['body' => $payload], ['body' => ['required', 'array:entries']])->validate();
            $rules = ['entries' => ['required', 'array', 'min:1', 'max:20'], 'entries.*' => ['required', 'array:company_id,project_id,spent_on,amount,currency,description'], 'entries.*.company_id' => ['required', 'uuid']];
            foreach ($this->rules() as $field => $rule) {
                $rules['entries.*.'.$field] = $rule;
            }
            /** @var array{entries:list<array{company_id:string,project_id?:?string,spent_on:string,amount:int,currency:string,description:string}>} $data */
            $data = Validator::make($payload, $rules)->validate();
            $companies = ClientCompany::query()->where('workspace_id', $workspace->id)
                ->whereIn('public_id', array_column($data['entries'], 'company_id'))->get()->keyBy('public_id');
            $projects = ClientProject::query()->where('workspace_id', $workspace->id)
                ->whereIn('public_id', array_values(array_filter(array_column($data['entries'], 'project_id'))))->get()->keyBy('public_id');
            $expenses = new WorkspaceExpenses($workspace);
            $ids = [];
            foreach ($data['entries'] as $entry) {
                $company = $companies->get($entry['company_id']);
                abort_unless($company instanceof ClientCompany, 404);
                $projectId = $entry['project_id'] ?? null;
                $project = $projectId === null ? null : $projects->get($projectId);
                abort_unless($projectId === null || ($project instanceof ClientProject && $project->client_company_id === $company->id), 404);
                $record = $expenses->record($company, $project, $this->facts($entry), $user);
                $ids[] = $record->public_id;
            }

            return $ids;
        }, fn (array $ids) => $this->authorize($user, $workspace));
    }

    /** @param array<string,mixed> $payload
     * @return list<string> */
    public function update(User $user, Workspace $workspace, string $clientId, string $key, string $expenseId, array $payload): array
    {
        $this->authorize($user, $workspace);
        Validator::make(['key' => $key], ['key' => ['required', 'string', 'max:255']])->validate();

        return $this->mutations->run($user, $workspace, $clientId, 'expenses.update', $key, ['expense_id' => $expenseId, 'body' => $payload], function () use ($workspace, $expenseId, $payload): array {
            Validator::make(['body' => $payload], ['body' => ['required', 'array:expected_version,spent_on,amount,currency,description,project_id']])->validate();
            /** @var array{expected_version:string,project_id?:?string,spent_on:string,amount:int,currency:string,description:string} $data */
            $data = Validator::make($payload, [...$this->rules(), 'expected_version' => ['required', 'string', 'size:64']])->validate();
            $expenses = new WorkspaceExpenses($workspace);
            $record = $expenses->query()->where('public_id', $expenseId)->firstOrFail();
            $reattribute = array_key_exists('project_id', $data);
            try {
                $expenses->update($record, $this->facts($data), $reattribute ? $this->project($workspace, $record->client_company_id, $data['project_id']) : null, $reattribute, $data['expected_version']);
            } catch (ExpenseTransitionRefused $exception) {
                abort(409, $exception->getMessage());
            }

            return [$record->public_id];
        }, fn (array $ids) => $this->authorize($user, $workspace));
    }

    /** @param array<string,mixed> $payload */
    public function delete(User $user, Workspace $workspace, string $clientId, string $key, string $expenseId, array $payload): string
    {
        $this->authorize($user, $workspace);
        Validator::make(['key' => $key], ['key' => ['required', 'string', 'max:255']])->validate();
        $ids = $this->mutations->run($user, $workspace, $clientId, 'expenses.delete', $key, ['expense_id' => $expenseId, 'body' => $payload], function () use ($workspace, $expenseId, $payload): array {
            Validator::make(['body' => $payload], ['body' => ['required', 'array:expected_version']])->validate();
            /** @var array{expected_version:string} $data */
            $data = Validator::make($payload, ['expected_version' => ['required', 'string', 'size:64']])->validate();
            $expenses = new WorkspaceExpenses($workspace);
            $record = $expenses->query()->where('public_id', $expenseId)->firstOrFail();
            try {
                $expenses->discard($record, $data['expected_version'], draftOnly: true);
            } catch (ExpenseTransitionRefused $exception) {
                abort(409, $exception->getMessage());
            }

            return [$record->public_id];
        }, fn (array $ids) => $this->authorize($user, $workspace));

        // The receipt is scoped to actor, workspace, client and operation. A
        // delete retry rechecks manager access without loading the deleted row.
        return $ids[0];
    }

    private function authorize(User $user, Workspace $workspace): void
    {
        abort_unless((bool) config('agent_api.writes_enabled') && (bool) config('agent_api.expense_writes_enabled'), 404);
        abort_unless($this->access->isWorkspaceManager($user, $workspace), 403);
    }

    /** @return array<string,list<string>> */
    private function rules(): array
    {
        return [
            'spent_on' => ['required', 'date_format:Y-m-d'], 'amount' => ['required', 'integer', 'min:1'],
            'currency' => ['required', 'string', 'regex:/^[A-Z]{3}$/'],
            'description' => ['required', 'string', 'max:2000'], 'project_id' => ['sometimes', 'nullable', 'uuid'],
        ];
    }

    /** @param array{spent_on:string,amount:int,currency:string,description:string} $data */
    private function facts(array $data): NewExpense
    {
        return new NewExpense(CarbonImmutable::parse($data['spent_on']), $data['amount'], $data['currency'], $data['description']);
    }

    private function project(Workspace $workspace, int $companyId, ?string $projectId): ?ClientProject
    {
        return $projectId === null ? null : ClientProject::query()
            ->where('workspace_id', $workspace->id)->where('client_company_id', $companyId)
            ->where('public_id', $projectId)->firstOrFail();
    }
}
