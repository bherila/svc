<?php

namespace App\Services\Mcp;

final class AgentMcpPrompts
{
    /** @return array{user: string} */
    public function logTimeAcrossProjects(): array
    {
        return ['user' => implode(' ', [
            'Help me log completed time in SVC across one or more projects.',
            'Begin with context.get and select a workspace ID returned by it; never invent an ID.',
            'Use projects.list to match every project and tasks.list only when a task association is requested or useful. Ask before proceeding if a project or task name is ambiguous.',
            'Translate each duration to whole minutes and preserve the user\'s stated work date and description. Call time_entries.log once for up to 20 entries using a stable, task-specific idempotency_key.',
            'If retrying the identical request, reuse that key; if any entry changes, use a new key. Do not approve time unless the user separately asks for approval.',
            'Report the created entry IDs, project names, dates, minutes, descriptions, and billable/client-visible choices.',
        ])];
    }

    /** @return array{user: string} */
    public function prepareInvoiceSafely(): array
    {
        return ['user' => implode(' ', [
            'Help me prepare an SVC invoice safely.',
            'Call context.get, select only returned IDs, and inspect the relevant project, approved time, and any existing invoice before writing.',
            'Create or update a draft using explicit time-entry IDs and manual lines with a stable idempotency key. Show the complete draft and current opaque version to the user.',
            'Do not issue, send, void, discard, or initiate any browser payment flow without a separate explicit confirmation for that exact action and current version.',
        ])];
    }
}
