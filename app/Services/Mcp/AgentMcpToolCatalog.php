<?php

namespace App\Services\Mcp;

use Bherila\McpLaravelBridge\Mcp\ToolDefinition;

/**
 * A deliberately fixed allow-list for the Agent MCP release.
 *
 * Separate cutovers follow the blast radius of each write surface. Time writes have their own authoritative emergency cutoff;
 * `AGENT_API_WRITES_ENABLED` is the workflow cutover; and the invoice tools
 * sit behind a second flag inside it, so agent-assisted time approval no
 * longer arrives with agent-initiated invoice delivery attached (#242). Expense
 * writes also require their own nested cutover; listing expenses remains read-only.
 *
 * A tool withheld here is withheld everywhere it matters: the MCP surface
 * never lists or dispatches it, `AgentMcpServerFactory` drops any prompt whose
 * `requiredCapabilities` name it, `AgentCapabilities` stops advertising the
 * matching capability, and the REST routes carry the same middleware.
 */
final class AgentMcpToolCatalog
{
    /** @return list<ToolDefinition> */
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
            $this->tool('expenses.list', 'List expenses', 'List authorized expenses with bounded cursor pagination. Unattributed expenses are visible only to workspace managers.', $tools, 'expensesList'),
            $this->tool('invoices.list', 'List invoices', 'List authorized invoices with bounded cursor pagination.', $tools, 'invoices'),
            $this->tool('invoices.get', 'Get invoice', 'Get one authorized invoice. The response includes a browser URL; payment is not an MCP operation.', $tools, 'invoice'),
        ];
        if ($this->writesEnabled() && (bool) config('agent_api.expense_writes_enabled')) {
            $definitions = [...$definitions,
                new ToolDefinition('expenses.log', 'Record expenses', 'Idempotently record up to 20 draft expenses as a workspace manager. Amounts use minor units; no receipt attachment or approval.', [$writes, 'expensesLog'], 'expenses.log', false, false, true),
                new ToolDefinition('expenses.update', 'Update draft expense', 'Replace draft expense facts using the current version. Only workspace managers may write.', [$writes, 'expensesUpdate'], 'expenses.update', false, false, true),
                new ToolDefinition('expenses.delete', 'Delete draft expense', 'Soft-delete a draft expense using its current version. Approved, invoiced and unknown statuses are refused.', [$writes, 'expensesDelete'], 'expenses.delete', false, true, true),
            ];
        }
        if ($this->timeEntryWritesEnabled()) {
            $definitions = [...$definitions,
                new ToolDefinition('time_entries.log', 'Log time', 'Idempotently log up to 20 completed time entries. Explicit billing rates require time:approve and a project approver role.', [$writes, 'timeEntriesLog'], 'time_entries.log', false, false, true),
                new ToolDefinition('time_entries.update', 'Update editable time', 'Update authorized draft time, or approved time on a regenerable draft invoice, using its current version.', [$writes, 'timeEntriesUpdate'], 'time_entries.update', false, false, true),
                new ToolDefinition('time_entries.delete', 'Delete editable time', 'Soft-delete authorized draft time, or approved time on a regenerable draft invoice, using its current version.', [$writes, 'timeEntriesDelete'], 'time_entries.delete', false, true, true),
            ];
        }
        if ($this->writesEnabled()) {
            $definitions = [...$definitions,
                new ToolDefinition('time_entries.approve', 'Approve time', 'Approve a bounded batch of draft time entries after version checks.', [$writes, 'timeEntriesApprove'], 'time_entries.approve', false, false, true),
                new ToolDefinition('tasks.create', 'Create task', 'Create a task in an authorized project.', [$writes, 'tasksCreate'], 'tasks.create', false, false, true),
                new ToolDefinition('tasks.update', 'Update task', 'Update an authorized task using its current version.', [$writes, 'tasksUpdate'], 'tasks.update', false, false, true),
            ];
        }
        if ($this->invoiceWritesEnabled()) {
            $definitions = [...$definitions,
                new ToolDefinition('invoices.create_draft', 'Create invoice draft', 'Create a draft from explicit manual lines and/or explicit approved time.', [$writes, 'invoicesCreateDraft'], 'invoices.create_draft', false, false, true),
                new ToolDefinition('invoices.update_draft', 'Update invoice draft', 'Replace a draft invoice selection and lines using its current version.', [$writes, 'invoicesUpdateDraft'], 'invoices.update_draft', false, false, true),
                new ToolDefinition('invoices.discard_draft', 'Discard invoice draft', 'Discard a draft and release its selected time only after explicit confirmation.', [$writes, 'invoicesDiscardDraft'], 'invoices.discard_draft', false, true, true),
                new ToolDefinition('invoices.issue', 'Issue invoice', 'Issue a draft invoice only after explicit confirmation.', [$writes, 'invoicesIssue'], 'invoices.issue', false, false, true),
                new ToolDefinition('invoices.send', 'Send invoice', 'Queue delivery to explicit recipients only after confirmation.', [$writes, 'invoicesSend'], 'invoices.send', false, false, true),
                new ToolDefinition('invoices.void', 'Void invoice', 'Void an invoice only after explicit confirmation and reason.', [$writes, 'invoicesVoid'], 'invoices.void', false, true, true),
            ];
        }

        return $definitions;
    }

    private function writesEnabled(): bool
    {
        return (bool) config('agent_api.writes_enabled');
    }

    /**
     * Nested inside the cutover above rather than independent of it.
     *
     * `AGENT_API_WRITES_ENABLED` keeps meaning what it means today for tasks
     * and time approval, so turning it off still withdraws everything; the
     * invoice flag only ever narrows further. The two are read together rather
     * than the invoice one standing alone, because an agent that may not
     * approve time has no business issuing an invoice built from it.
     */
    private function invoiceWritesEnabled(): bool
    {
        return $this->writesEnabled() && (bool) config('agent_api.invoice_writes_enabled');
    }

    private function timeEntryWritesEnabled(): bool
    {
        return (bool) config('agent_api.time_entry_writes_enabled');
    }

    private function tool(string $name, string $title, string $description, AgentMcpReadTools $tools, string $method): ToolDefinition
    {
        return new ToolDefinition($name, $title, $description, [$tools, $method], $name);
    }
}
