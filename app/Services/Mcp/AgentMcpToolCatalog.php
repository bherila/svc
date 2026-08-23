<?php

namespace App\Services\Mcp;

/** A deliberately fixed, read-only allow-list for the first MCP release. */
final class AgentMcpToolCatalog
{
    /** @return list<AgentMcpToolDefinition> */
    public function definitions(AgentMcpReadTools $tools): array
    {
        return [
            $this->tool('context.get', 'Get context', 'Get the authorized identity, workspaces, roles, and capabilities. Call this before selecting a workspace.', $tools, 'context'),
            $this->tool('operations.summary', 'Get workspace summary', 'Get the role-filtered operational summary for one workspace.', $tools, 'summary'),
            $this->tool('projects.list', 'List projects', 'List authorized projects with bounded cursor pagination.', $tools, 'projects'),
            $this->tool('projects.get', 'Get project', 'Get one authorized project and its visible tasks.', $tools, 'project'),
            $this->tool('tasks.list', 'List tasks', 'List authorized tasks with bounded cursor pagination.', $tools, 'tasks'),
            $this->tool('tasks.get', 'Get task', 'Get one authorized task.', $tools, 'task'),
            $this->tool('time_entries.list', 'List time entries', 'List authorized time entries with bounded cursor pagination.', $tools, 'timeEntries'),
            $this->tool('invoices.list', 'List invoices', 'List authorized invoices with bounded cursor pagination.', $tools, 'invoices'),
            $this->tool('invoices.get', 'Get invoice', 'Get one authorized invoice. The response includes a browser URL; payment is not an MCP operation.', $tools, 'invoice'),
        ];
    }

    private function tool(string $name, string $title, string $description, AgentMcpReadTools $tools, string $method): AgentMcpToolDefinition
    {
        return new AgentMcpToolDefinition($name, $title, $description, [$tools, $method], $name);
    }
}
