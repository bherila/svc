<?php

namespace Tests\Concerns;

use App\Services\AgentApi\Operations\AgentAvailability;
use App\Services\AgentApi\Operations\AgentOperationCatalog;
use App\Services\Mcp\AgentMcpToolSchemas;
use Bherila\McpLaravelBridge\Capabilities\McpKind;
use Bherila\McpLaravelBridge\Capabilities\Operation;

/** Reads the operation registry the way the MCP server does. */
trait InspectsAgentOperations
{
    /**
     * MCP tools the deployment serves under the current configuration - its
     * cutovers and MCP switches, before any caller's scopes or role.
     *
     * @return list<string>
     */
    protected function deployedToolNames(): array
    {
        $flags = app(AgentAvailability::class)->agentFlags();
        $names = [];
        foreach ($this->mcpTools() as $operation) {
            if (array_filter($operation->requirement->flags, static fn (string $flag): bool => ! $flags->enabled($flag)) === []) {
                $names[] = (string) $operation->mcpName();
            }
        }

        return $names;
    }

    /** @return list<Operation> */
    protected function mcpTools(): array
    {
        return array_values(array_filter(
            app(AgentOperationCatalog::class)->registry()->all(),
            static fn (Operation $operation): bool => $operation->mcp?->kind === McpKind::Tool,
        ));
    }

    protected function agentOperation(string $id): Operation
    {
        $operation = app(AgentOperationCatalog::class)->registry()->find($id);
        $this->assertNotNull($operation, $id);

        return $operation;
    }

    /** @return array<string, mixed> */
    protected function toolInputSchema(string $id): array
    {
        return app(AgentMcpToolSchemas::class)->input($this->agentOperation($id));
    }

    /** @return array<string, mixed>|null */
    protected function toolOutputSchema(string $id): ?array
    {
        return app(AgentMcpToolSchemas::class)->output($this->agentOperation($id));
    }
}
