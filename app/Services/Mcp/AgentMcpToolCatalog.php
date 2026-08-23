<?php

namespace App\Services\Mcp;

/** A deliberately fixed allow-list for the Agent MCP release. */
final class AgentMcpToolCatalog
{
    /** @return list<AgentMcpToolDefinition> */
    public function definitions(AgentMcpReadTools $tools, AgentMcpWriteTools $writes): array
    {
        $definitions = [
            $this->tool('context.get', 'Get context', 'Get the authorized identity, workspaces, roles, and capabilities. Call this before selecting a workspace.', $tools, 'context'),
            $this->tool('operations.summary', 'Get workspace summary', 'Get the role- and scope-filtered operational summary for one workspace.', $tools, 'summary'),
            $this->tool('projects.list', 'List projects', 'List authorized projects with bounded cursor pagination.', $tools, 'projects'),
            $this->tool('projects.get', 'Get project', 'Get one authorized project and its visible tasks.', $tools, 'project'),
            $this->tool('tasks.list', 'List tasks', 'List authorized tasks with bounded cursor pagination.', $tools, 'tasks'),
            $this->tool('tasks.get', 'Get task', 'Get one authorized task.', $tools, 'task'),
            $this->tool('time_entries.list', 'List time entries', 'List authorized time entries with bounded cursor pagination.', $tools, 'timeEntries'),
            $this->tool('invoices.list', 'List invoices', 'List authorized invoices with bounded cursor pagination.', $tools, 'invoices'),
            $this->tool('invoices.get', 'Get invoice', 'Get one authorized invoice. The response includes a browser URL; payment is not an MCP operation.', $tools, 'invoice'),
        ];
        if ((bool) config('agent_api.writes_enabled')) {
            $definitions = [...$definitions,
                new AgentMcpToolDefinition('time_entries.log', 'Log time', 'Idempotently log up to 20 completed time entries.', [$writes, 'timeEntriesLog'], 'time_entries.log', false, false, true),
                new AgentMcpToolDefinition('time_entries.update', 'Update draft time', 'Update an authorized draft time entry using its current version.', [$writes, 'timeEntriesUpdate'], 'time_entries.update', false, false, true),
                new AgentMcpToolDefinition('time_entries.delete', 'Delete draft time', 'Soft-delete an authorized draft time entry using its current version.', [$writes, 'timeEntriesDelete'], 'time_entries.delete', false, true, true),
                new AgentMcpToolDefinition('time_entries.approve', 'Approve time', 'Approve a bounded batch of draft time entries after version checks.', [$writes, 'timeEntriesApprove'], 'time_entries.approve', false, false, true),
                new AgentMcpToolDefinition('tasks.create', 'Create task', 'Create a task in an authorized project.', [$writes, 'tasksCreate'], 'tasks.create', false, false, true),
                new AgentMcpToolDefinition('tasks.update', 'Update task', 'Update an authorized task using its current version.', [$writes, 'tasksUpdate'], 'tasks.update', false, false, true),
                new AgentMcpToolDefinition('invoices.create_draft', 'Create invoice draft', 'Create a draft from explicit manual lines and/or explicit approved time.', [$writes, 'invoicesCreateDraft'], 'invoices.create_draft', false, false, true),
                new AgentMcpToolDefinition('invoices.update_draft', 'Update invoice draft', 'Replace a draft invoice selection and lines using its current version.', [$writes, 'invoicesUpdateDraft'], 'invoices.update_draft', false, false, true),
                new AgentMcpToolDefinition('invoices.discard_draft', 'Discard invoice draft', 'Discard a draft and release its selected time only after explicit confirmation.', [$writes, 'invoicesDiscardDraft'], 'invoices.discard_draft', false, true, true),
                new AgentMcpToolDefinition('invoices.issue', 'Issue invoice', 'Issue a draft invoice only after explicit confirmation.', [$writes, 'invoicesIssue'], 'invoices.issue', false, false, true),
                new AgentMcpToolDefinition('invoices.send', 'Send invoice', 'Queue delivery to explicit recipients only after confirmation.', [$writes, 'invoicesSend'], 'invoices.send', false, false, true),
                new AgentMcpToolDefinition('invoices.void', 'Void invoice', 'Void an invoice only after explicit confirmation and reason.', [$writes, 'invoicesVoid'], 'invoices.void', false, true, true),
            ];
        }

        return $definitions;
    }

    private function tool(string $name, string $title, string $description, AgentMcpReadTools $tools, string $method): AgentMcpToolDefinition
    {
        return new AgentMcpToolDefinition($name, $title, $description, [$tools, $method], $name);
    }
}
