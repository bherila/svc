<?php

namespace App\Support\AgentApi\Presenters;

use App\Models\ClientTimeEntry;
use App\Models\Workspace;
use App\Support\AgentApi\AgentApiVersion;

final class AgentTimeEntryPresenter
{
    /** @return array<string, mixed> */
    public function present(
        Workspace $workspace,
        ClientTimeEntry $entry,
        bool $includeFinancials = false,
        bool $useClientDescription = false,
    ): array {
        $payload = [
            'id' => $entry->public_id,
            'project_id' => $entry->project->public_id,
            'task_id' => $entry->task?->public_id,
            'worked_on' => $entry->worked_on->toDateString(),
            'minutes' => $entry->minutes,
            'description' => $useClientDescription
                ? $entry->client_visible_description
                : $entry->description,
            'is_billable' => $entry->is_billable,
            'is_deferred' => $entry->is_deferred,
            'status' => $entry->status,
            'version' => AgentApiVersion::for($entry),
            'web_url' => route('workspaces.operations', $workspace).'?time_entry='.$entry->public_id,
        ];

        if ($includeFinancials) {
            $payload['billing_rate_amount'] = $entry->billing_rate_amount;
            $payload['currency'] = $entry->currency;
        }

        return $payload;
    }
}
