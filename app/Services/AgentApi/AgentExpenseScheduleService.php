<?php

namespace App\Services\AgentApi;

use App\Models\AgentPrincipal;
use App\Models\ClientCompany;
use App\Models\ClientExpenseSchedule;
use App\Models\ClientProject;
use App\Models\User;
use App\Models\Workspace;
use App\Queries\Expenses\WorkspaceExpenseSchedules;
use App\Services\Authorization\AgentAccess;
use App\Support\AgentApi\AgentApiCursor;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\AgentApi\AgentWriteCutover;
use App\Support\Billing\BillingCadence;
use App\Support\Concurrency\Locks;
use App\Support\Expenses\ExpenseRecurrence;
use App\Support\Expenses\NewExpense;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/** Agent adapter over the same tenant boundary used by the schedule web surface. */
final class AgentExpenseScheduleService
{
    public function __construct(private readonly AgentMutationExecutor $mutations, private readonly AgentAccess $access) {}

    /** @return array<string,mixed> */
    public function listing(User|AgentPrincipal $actor, Workspace $workspace, ?string $companyId, int $limit, ?string $cursor): array
    {
        $this->authorize($actor, $workspace);
        Validator::make(compact('companyId', 'limit', 'cursor'), ['companyId' => ['nullable', 'uuid'], 'limit' => ['integer', 'min:1', 'max:100'], 'cursor' => ['nullable', 'string', 'max:2048']])->validate();
        $query = (new WorkspaceExpenseSchedules($workspace))->query();
        if ($companyId !== null) {
            $company = ClientCompany::query()->where('workspace_id', $workspace->id)->where('public_id', $companyId)->firstOrFail();
            $query->where('client_company_id', $company->id);
        }
        $queryKey = 'expense-schedules|'.$companyId;
        $after = AgentApiCursor::decode($cursor, $workspace->public_id, $queryKey);
        if ($after !== null) {
            $query->where('id', '>', $after);
        }
        $rows = $query->orderBy('id')->limit($limit + 1)->get();
        $more = $rows->count() > $limit;
        if ($more) {
            $rows->pop();
        }
        $last = $rows->last();

        return ['data' => $this->presentMany($workspace, array_values($rows->all())),
            'meta' => ['next_cursor' => $more && $last !== null ? AgentApiCursor::encode($last->id, $workspace->public_id, $queryKey) : null]];
    }

    /** @param array<string,mixed> $payload
     * @param 'create'|'update'|'generate' $operation
     * @return array<string,mixed> */
    public function mutate(User $actor, Workspace $workspace, string $clientId, string $key, string $operation, ?string $id, array $payload): array
    {
        $this->authorize($actor, $workspace);
        abort_unless(AgentWriteCutover::expenses(), 404);
        Validator::make(['key' => $key], ['key' => ['required', 'string', 'max:255']])->validate();
        $ids = $this->mutations->run($actor, $workspace, $clientId, 'expense_schedules.'.$operation, $key,
            ['schedule_id' => $id, 'body' => $payload], function () use ($actor, $workspace, $operation, $id, $payload): array {
                $fields = match ($operation) {
                    'create' => 'company_id,expected_version,project_id,starts_on,cadence,amount,currency,description',
                    'update' => 'expected_version,project_id,active,amount,currency,description',
                    'generate' => 'expected_version,confirm',
                };
                Validator::make(['body' => $payload], ['body' => ['required', 'array:'.$fields]])->validate();
                $rules = ['expected_version' => ['required', 'string', 'size:64']];
                if ($operation !== 'generate') {
                    $rules += ['amount' => ['required', 'integer', 'min:1'], 'currency' => ['required', 'string', 'regex:/^[A-Z]{3}$/'],
                        'description' => ['required', 'string', 'max:2000'], 'project_id' => ['nullable', 'uuid']];
                }
                $rules += match ($operation) {
                    'create' => ['company_id' => ['required', 'uuid'], 'starts_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:1000-01-01', 'before:9999-01-01'], 'cadence' => ['required', Rule::enum(BillingCadence::class)]],
                    'update' => ['active' => ['required', 'boolean']],
                    'generate' => ['confirm' => ['required', static function (string $attribute, mixed $value, Closure $fail): void {
                        if ($value !== true) {
                            $fail('Explicit confirm true is required.');
                        }
                    }]],
                };
                $data = Validator::make($payload, $rules)->validate();
                $schedules = new WorkspaceExpenseSchedules($workspace);
                if ($operation === 'create') {
                    $company = ClientCompany::query()->where('workspace_id', $workspace->id)->where('public_id', $data['company_id'])->tap(Locks::forUpdate())->firstOrFail();
                    abort_unless(AgentApiVersion::matches($company, $data['expected_version']), 409, 'The client has changed; read it and retry.');
                    $schedule = $schedules->create($company, $data['project_id'] ?? null, $this->facts($data, $data['starts_on']), BillingCadence::from($data['cadence']));

                    return [$schedule->public_id];
                }
                abort_unless(is_string($id), 404);
                if ($operation === 'update') {
                    $schedules->update($id, $data['project_id'] ?? null, $this->facts($data, '2000-01-01'), (bool) $data['active'], $data['expected_version']);

                    return [$id];
                }
                $created = $schedules->generateEntries($id, $actor, $data['expected_version']);

                return [$id, ...$created];
            }, function (array $ids) use ($actor, $workspace): void {
                $this->authorize($actor, $workspace);
                abort_unless(AgentWriteCutover::expenses(), 404);
            });
        $schedule = (new WorkspaceExpenseSchedules($workspace))->query()->where('public_id', $ids[0])->firstOrFail();
        $result = ['data' => $this->presentMany($workspace, [$schedule])[0]];
        if ($operation === 'generate') {
            $result['generated_count'] = count($ids) - 1;
        }

        return $result;
    }

    /** @param array<string,mixed> $data */
    private function facts(array $data, string $date): NewExpense
    {
        return new NewExpense(CarbonImmutable::parse($date), (int) $data['amount'], (string) $data['currency'], (string) $data['description']);
    }

    private function authorize(User|AgentPrincipal $actor, Workspace $workspace): void
    {
        abort_unless($this->access->canViewWorkspace($actor, $workspace), 404);
        abort_unless($this->access->isWorkspaceManager($actor, $workspace), 403);
    }

    /** @param list<ClientExpenseSchedule> $schedules
     * @return list<array<string,mixed>> */
    private function presentMany(Workspace $workspace, array $schedules): array
    {
        $companies = ClientCompany::query()->where('workspace_id', $workspace->id)
            ->whereIn('id', array_map(fn (ClientExpenseSchedule $row): int => $row->client_company_id, $schedules))->get()->keyBy('id');
        $projects = ClientProject::query()->where('workspace_id', $workspace->id)
            ->whereIn('id', array_values(array_filter(array_map(fn (ClientExpenseSchedule $row): ?int => $row->client_project_id, $schedules))))->get()->keyBy('id');

        return array_map(function (ClientExpenseSchedule $schedule) use ($companies, $projects): array {
            $company = $companies->get($schedule->client_company_id);
            $project = $schedule->client_project_id === null ? null : $projects->get($schedule->client_project_id);
            abort_unless($company instanceof ClientCompany, 404);
            abort_unless($schedule->client_project_id === null || ($project instanceof ClientProject && $project->client_company_id === $company->id), 404);

            return $this->present($schedule, $company, $project);
        }, $schedules);
    }

    /** @return array<string,mixed> */
    private function present(ClientExpenseSchedule $schedule, ClientCompany $company, ?ClientProject $project): array
    {
        return ['id' => $schedule->public_id, 'company_id' => $company->public_id, 'project_id' => $project?->public_id,
            'starts_on' => $schedule->starts_on->toDateString(), 'cadence' => $schedule->cadence, 'amount' => $schedule->amount,
            'currency' => $schedule->currency, 'description' => $schedule->description, 'active' => $schedule->is_active,
            'next_on' => (new ExpenseRecurrence($schedule->starts_on, BillingCadence::from($schedule->cadence)))->occurrence($schedule->next_occurrence)->toDateString(),
            'version' => AgentApiVersion::for($schedule)];
    }
}
