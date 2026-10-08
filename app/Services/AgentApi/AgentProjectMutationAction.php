<?php

namespace App\Services\AgentApi;

use App\Models\ClientCompany;
use App\Models\ClientProject;
use App\Models\User;
use App\Models\Workspace;
use App\Rules\NormalizableRepository;
use App\Services\WorkspaceProjectMutationAction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Validator;

final class AgentProjectMutationAction
{
    public function __construct(private readonly WorkspaceProjectMutationAction $projects, private readonly AgentMutationExecutor $mutations) {}

    /** @param array<string, mixed> $payload */
    public function run(User $actor, Workspace $workspace, string $clientId, string $key, string $operation, ?string $projectId, array $payload): ClientProject
    {
        $ids = $this->mutations->run($actor, $workspace, $clientId, $operation, $key, ['project_id' => $projectId, 'body' => $payload], function () use ($actor, $workspace, $operation, $projectId, $payload): array {
            $this->projects->requireManager($actor, $workspace);
            $rules = [
                'expected_version' => ['required', 'string', 'size:64'],
                'confirm' => ['required', 'boolean', 'accepted'],
            ];
            $rules += match ($operation) {
                'projects.create' => [
                    'company_id' => ['required', 'uuid'],
                    'name' => ['required', 'string', 'max:160'],
                    'description' => ['nullable', 'string', 'max:5000'],
                    'repository' => ['nullable', 'string', 'max:255', new NormalizableRepository],
                    'is_visible_to_client' => ['sometimes', 'boolean'],
                ],
                'projects.update' => [
                    'name' => ['sometimes', 'required', 'string', 'max:160'],
                    'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
                    'repository' => ['sometimes', 'nullable', 'string', 'max:255', new NormalizableRepository],
                    'status' => ['sometimes', 'required', 'in:active,archived'],
                    'is_visible_to_client' => ['sometimes', 'boolean'],
                ],
                'projects.archive' => [],
                'projects.members.update' => ['user_id' => ['required', 'uuid'], 'role' => ['required', 'in:owner,manager,contributor,viewer,none']],
                default => throw new \InvalidArgumentException('Unsupported project mutation.'),
            };
            Validator::make(['body' => $payload], ['body' => ['required', 'array:'.implode(',', array_keys($rules))]])->validate();
            $data = Validator::make($payload, $rules)->validate();
            abort_unless($data['confirm'] === true, 422, 'Explicit confirmation is required.');
            if ($operation === 'projects.create') {
                $company = ClientCompany::query()->where('workspace_id', $workspace->id)->where('public_id', $data['company_id'])->firstOrFail();
                $project = $this->projects->create($actor, $workspace, $company, $data, $data['expected_version']);
            } else {
                $record = ClientProject::query()->where('workspace_id', $workspace->id)->where('public_id', $projectId)->firstOrFail();
                $project = $operation === 'projects.members.update'
                    ? $this->projects->updateAccess($actor, $workspace, $record, $data['user_id'], $data['role'], $data['expected_version'])
                    : $this->projects->update($actor, $workspace, $record, $operation === 'projects.archive' ? ['status' => 'archived'] : $data, $data['expected_version']);
            }

            return [$project->public_id];
        }, function (array $ids) use ($actor, $workspace): void {
            $this->projects->requireManager($actor, $workspace);
            $count = ClientProject::query()->where('workspace_id', $workspace->id)->whereIn('public_id', $ids)
                ->whereHas('clientCompany', fn (Builder $company): Builder => $company->where('workspace_id', $workspace->id))->count();
            abort_unless($count === count($ids), 404);
        });

        return ClientProject::query()->where('workspace_id', $workspace->id)->where('public_id', $ids[0] ?? null)
            ->whereHas('clientCompany', fn (Builder $company): Builder => $company->where('workspace_id', $workspace->id))
            ->with('clientCompany')->firstOrFail();
    }
}
