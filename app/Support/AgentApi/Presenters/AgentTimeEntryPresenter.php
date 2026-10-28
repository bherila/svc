<?php

namespace App\Support\AgentApi\Presenters;

use App\Models\ClientTimeEntry;
use App\Models\Workspace;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\AgentApi\TimeEntryAllocation;

final class AgentTimeEntryPresenter
{
    /** Pure summary preserves the caller's explicit internal/client description policy.
     * @return array<string, mixed> */
    public static function summary(ClientTimeEntry $entry, bool $useClientDescription = false, bool $compact = false): array
    {
        return ['id' => $entry->public_id, 'worked_on' => $entry->worked_on->toDateString(),
            'minutes' => $entry->minutes, 'description' => $compact ? null : ($useClientDescription ? $entry->client_visible_description : $entry->description),
            'status' => $entry->status, 'is_billable' => $entry->is_billable, 'is_deferred' => $entry->is_deferred];
    }

    /** @return array<string, \Closure> */
    public static function authorRelations(Workspace $workspace): array
    {
        return [
            'user' => fn ($users) => $users->whereHas('workspaces', fn ($workspaces) => $workspaces->whereKey($workspace->id)),
            'user.workspaces' => fn ($workspaces) => $workspaces->whereKey($workspace->id),
        ];
    }

    /** @return array<string, mixed> */
    public function present(
        Workspace $workspace,
        ClientTimeEntry $entry,
        bool $includeFinancials = false,
        bool $useClientDescription = false,
        bool $compact = false,
    ): array {
        $entry->loadMissing(TimeEntryAllocation::relations($workspace->id));
        $entry->loadMissing(self::authorRelations($workspace));
        $author = $entry->user;
        $authorId = $author !== null && $author->workspaces->contains('id', $workspace->id) ? $author->public_id : null;
        $allocation = TimeEntryAllocation::fromEntry($entry);
        $task = $entry->task;
        // The reader scopes this optional relation through task disclosure.
        // An old malformed same-workspace cross-project link must also be null.
        $taskId = $task !== null && (int) $task->workspace_id === (int) $workspace->id
            && (int) $entry->workspace_id === (int) $workspace->id
            && (int) $task->client_project_id === (int) $entry->client_project_id ? $task->public_id : null;
        $payload = [
            'invoice_id' => $allocation->invoiceId,
            'allocation_state' => $allocation->state,
            ...self::summary($entry, $useClientDescription, $compact),
            'author_id' => $authorId,
            'project_id' => $entry->project->public_id,
            'task_id' => $taskId,
            'is_visible_to_client' => $entry->is_visible_to_client,
            'client_visible_description' => $compact ? null : $entry->client_visible_description,
            'version' => AgentApiVersion::for($entry),
            'web_url' => route('workspaces.operations', $workspace).'?time_entry='.$entry->public_id,
        ];

        if ($includeFinancials) {
            $payload['billing_rate_amount'] = $entry->billing_rate_amount;
            $payload['currency'] = $entry->currency;
            $payload['subcontractor_billing_mode'] = $entry->subcontractor_billing_mode?->value;
            $payload['subcontractor_cost_amount'] = $entry->subcontractor_cost_amount;
            $payload['subcontractor_cost_currency'] = $entry->subcontractor_cost_currency;
        }

        return $payload;
    }
}
