<?php

namespace App\Support\AgentApi;

use Bherila\McpLaravelBridge\OpenApi\SchemaCatalog;

/** Application path facade over the shared OpenAPI contract resolver. */
final class AgentApiResponseSchemaCatalog
{
    private static ?SchemaCatalog $catalog = null;

    /** @return array<string, mixed> */
    public static function schema(string $component): array
    {
        return self::catalog()->schema($component);
    }

    /** @return list<string> */
    public static function componentIds(): array
    {
        return self::catalog()->componentIds();
    }

    /** @return array<string, mixed> */
    public static function forOperation(string $operationId): array
    {
        return self::catalog()->forOperation($operationId);
    }

    public static function operationComponent(string $operationId): string
    {
        return self::catalog()->operationComponent($operationId);
    }

    /** @return array<string, mixed> */
    public static function requestForOperation(string $operationId): array
    {
        return self::catalog()->requestForOperation($operationId);
    }

    /** @return list<string> */
    public static function scopesForOperation(string $operationId): array
    {
        return self::catalog()->scopesForOperation($operationId);
    }

    public static function flush(): void
    {
        self::$catalog?->flush();
        self::$catalog = null;
    }

    private static function catalog(): SchemaCatalog
    {
        return self::$catalog ??= new SchemaCatalog(public_path('openapi/svc-agent-v1.json'));
    }
}
