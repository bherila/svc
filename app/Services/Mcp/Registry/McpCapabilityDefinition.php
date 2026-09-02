<?php

namespace App\Services\Mcp\Registry;

use Closure;

/**
 * One reviewed public MCP contract, shared by discovery and execution.
 *
 * @param  array{0: object|string, 1: string}|Closure  $handler
 * @param  array<string, mixed>  $inputSchema
 * @param  array<string, mixed>  $outputSchema
 * @param  list<string>  $requiredScopes
 */
final readonly class McpCapabilityDefinition
{
    public function __construct(
        public McpCapabilityKind $kind,
        public string $name,
        public string $title,
        public string $description,
        /** @var array{0: object|string, 1: string}|Closure */
        public array|Closure $handler,
        /** @var array<string, mixed> */
        public array $inputSchema,
        /** @var array<string, mixed> */
        public array $outputSchema,
        /** @var list<string> */
        public array $requiredScopes,
        public ?string $policyAbility,
        public bool $requiresWorkspace,
        public bool $readOnly,
        public bool $idempotent,
        public bool $destructive,
        public string $rateLimitBucket,
        public string $auditClassification,
        public string $featureFlag,
        public ?string $deprecatedSince = null,
        public ?string $replacement = null,
        public ?string $uri = null,
    ) {}
}
