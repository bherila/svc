<?php

namespace App\Services\Mcp;

use Closure;

final readonly class AgentMcpToolDefinition
{
    /** @param array{0: object|string, 1: string}|Closure $handler */
    public function __construct(
        public string $name,
        public string $title,
        public string $description,
        public array|Closure $handler,
        public string $operationId,
        public bool $readOnly = true,
        public bool $destructive = false,
        public bool $idempotent = true,
    ) {}
}
