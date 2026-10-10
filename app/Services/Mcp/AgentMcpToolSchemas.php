<?php

namespace App\Services\Mcp;

use App\Support\AgentApi\AgentApiResponseSchemaCatalog;
use Bherila\McpLaravelBridge\Capabilities\Operation;
use Bherila\McpLaravelBridge\Capabilities\OperationToolFactory;

/**
 * An MCP tool's input and output schemas, from its operation.
 *
 * A schema the operation declares inline is used as written. Otherwise the
 * package derives it: the handler's reflected signature with the REST request
 * body merged over it, and the documented success response.
 */
final class AgentMcpToolSchemas
{
    private readonly OperationToolFactory $tools;

    public function __construct()
    {
        $this->tools = new OperationToolFactory(AgentApiResponseSchemaCatalog::catalog());
    }

    /** @return array<string, mixed> */
    public function input(Operation $operation): array
    {
        return is_array($operation->input) ? $operation->input : $this->tools->inputSchema($operation);
    }

    /** @return array<string, mixed>|null */
    public function output(Operation $operation): ?array
    {
        return is_array($operation->output) ? $operation->output : $this->tools->outputSchema($operation);
    }
}
