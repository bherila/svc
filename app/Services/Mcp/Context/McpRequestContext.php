<?php

namespace App\Services\Mcp\Context;

use App\Models\Workspace;

/** One immutable, request-bound context for MCP discovery and execution. */
final readonly class McpRequestContext
{
    public function __construct(
        public McpPrincipal $principal,
        public string $requestId,
        public ?Workspace $workspace = null,
    ) {}

    public function forWorkspace(Workspace $workspace): self
    {
        return new self($this->principal, $this->requestId, $workspace);
    }
}
