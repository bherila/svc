<?php

namespace App\Services\Engagement;

use App\Models\ClientProject;
use App\Models\ClientTask;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\WorkspaceAuthorization;

class TimeEntryWorkflow
{
    public function __construct(private readonly WorkspaceAuthorization $workspaceAuthorization) {}

    /** @param array<string, mixed> $attributes */
    public function create(
        Workspace $workspace,
        ClientProject $project,
        User $worker,
        array $attributes,
        ?ClientTask $task = null,
    ): ClientTimeEntry {
        $company = $project->clientCompany;

        if (! $this->workspaceAuthorization->isOwnedBy($workspace, $project)
            || ! $this->workspaceAuthorization->isOwnedBy($workspace, $company)) {
            throw new EngagementException('The client project does not belong to this workspace.');
        }

        if ($task !== null && (! $this->workspaceAuthorization->isOwnedBy($workspace, $task) || $task->client_project_id !== $project->id)) {
            throw new EngagementException('The client task does not belong to this project and workspace.');
        }

        if (! $workspace->memberships()->where('user_id', $worker->id)->exists()) {
            throw new EngagementException('The worker is not a member of this workspace.');
        }

        // A client-visible entry with no client-facing description would show
        // the internal note, which is not written for a client to read. The
        // agent API refuses the same shape; both doors have to agree or the
        // operator screen becomes the way around the rule.
        $visibleToClient = (bool) ($attributes['is_visible_to_client'] ?? false);
        $clientDescription = $attributes['client_visible_description'] ?? null;

        if ($visibleToClient && (! is_string($clientDescription) || trim($clientDescription) === '')) {
            throw new EngagementException('Client-visible time requires an explicit client-facing description.');
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
            'client_visible_description' => $clientDescription,
            'is_visible_to_client' => $visibleToClient,
            'is_billable' => $attributes['is_billable'] ?? true,
            'is_deferred' => $attributes['is_deferred'] ?? false,
            'billing_rate_amount' => $attributes['billing_rate_amount'] ?? null,
            // A rate supplied at creation is recorded, not inferred.
            'billing_rate_source' => isset($attributes['billing_rate_amount']) ? 'explicit' : null,
            // Never null. `InvoiceFromTimeService` bills only entries whose
            // currency matches the invoice's, so a rate-bearing entry with no
            // currency is approved, billable, and then silently skipped by
            // every invoice - the agent API's create path has always defaulted
            // this, and only the web path left the hole.
            'currency' => isset($attributes['currency'])
                ? strtoupper($attributes['currency'])
                : $workspace->default_currency,
            'status' => 'draft',
        ]);
    }
}
