<?php

namespace App\Services\Engagement;

use App\Models\ClientProject;
use App\Models\ClientTask;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;

class TimeEntryWorkflow
{
    /** @param array<string, mixed> $attributes */
    public function create(
        Workspace $workspace,
        ClientProject $project,
        User $worker,
        array $attributes,
        ?ClientTask $task = null,
    ): ClientTimeEntry {
        $company = $project->clientCompany;

        if ($project->workspace_id !== $workspace->id || $company->workspace_id !== $workspace->id) {
            throw new EngagementException('The client project does not belong to this workspace.');
        }

        if ($task !== null && ($task->workspace_id !== $workspace->id || $task->client_project_id !== $project->id)) {
            throw new EngagementException('The client task does not belong to this project and workspace.');
        }

        if (! $workspace->memberships()->where('user_id', $worker->id)->exists()) {
            throw new EngagementException('The worker is not a member of this workspace.');
        }

        return ClientTimeEntry::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'client_project_id' => $project->id,
            'client_task_id' => $task?->id,
            'user_id' => $worker->id,
            'worked_on' => $attributes['worked_on'],
            'minutes' => $attributes['minutes'],
            'description' => $attributes['description'],
            'is_billable' => $attributes['is_billable'] ?? true,
            'is_deferred' => $attributes['is_deferred'] ?? false,
            'billing_rate_amount' => $attributes['billing_rate_amount'] ?? null,
            'currency' => isset($attributes['currency']) ? strtoupper($attributes['currency']) : null,
            'status' => 'draft',
        ]);
    }
}
