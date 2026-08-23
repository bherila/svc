<?php

namespace App\Support\AgentApi;

use InvalidArgumentException;
use JsonException;

/** Packages OpenAPI response components as standalone MCP JSON Schemas. */
final class AgentApiResponseSchemaCatalog
{
    private const string DOCUMENT = 'openapi/svc-agent-v1.json';

    private const string REF_PREFIX = '#/components/schemas/';

    /** @var array<string, mixed>|null */
    private static ?array $document = null;

    /** @var array<string, string>|null */
    private static ?array $operationComponents = null;

    /** @var array<string, string>|null */
    private static ?array $requestComponents = null;

    /** @var array<string, list<string>>|null */
    private static ?array $operationScopes = null;

    /** @var array<string, array<string, mixed>> */
    private static array $packaged = [];

    /** @return array<string, mixed> */
    public static function schema(string $component): array
    {
        return self::$packaged[$component] ??= self::package($component);
    }

    /** @return list<string> */
    public static function componentIds(): array
    {
        return array_keys(self::components());
    }

    /** @return array<string, mixed> */
    public static function forOperation(string $operationId): array
    {
        return self::schema(self::operationComponent($operationId));
    }

    public static function operationComponent(string $operationId): string
    {
        $components = self::operationComponents();
        if (! isset($components[$operationId])) {
            throw new InvalidArgumentException("No agent API response schema is declared for operation [{$operationId}].");
        }

        return $components[$operationId];
    }

    /** @return array<string, mixed> */
    public static function requestForOperation(string $operationId): array
    {
        $components = self::requestComponents();
        if (! isset($components[$operationId])) {
            throw new InvalidArgumentException("No agent API request schema is declared for operation [{$operationId}].");
        }

        return self::schema($components[$operationId]);
    }

    /** @return list<string> */
    public static function scopesForOperation(string $operationId): array
    {
        if (self::$operationScopes === null) {
            self::$operationScopes = [];
            foreach (self::document()['paths'] ?? [] as $operations) {
                foreach (is_array($operations) ? $operations : [] as $operation) {
                    $id = is_array($operation) ? ($operation['operationId'] ?? null) : null;
                    $scopes = is_array($operation) ? ($operation['security'][0]['oauth2'] ?? null) : null;
                    if (is_string($id) && is_array($scopes) && array_is_list($scopes)) {
                        self::$operationScopes[$id] = array_values(array_filter($scopes, 'is_string'));
                    }
                }
            }
        }
        if (! array_key_exists($operationId, self::$operationScopes)) {
            throw new InvalidArgumentException("No OAuth scopes are declared for operation [{$operationId}].");
        }

        return self::$operationScopes[$operationId];
    }

    public static function flush(): void
    {
        self::$document = null;
        self::$operationComponents = null;
        self::$requestComponents = null;
        self::$operationScopes = null;
        self::$packaged = [];
    }

    /** @return array<string, string> */
    private static function operationComponents(): array
    {
        if (self::$operationComponents !== null) {
            return self::$operationComponents;
        }

        $map = [];
        $paths = self::document()['paths'] ?? [];
        foreach (is_array($paths) ? $paths : [] as $operations) {
            foreach (is_array($operations) ? $operations : [] as $operation) {
                if (! is_array($operation) || ! is_string($operation['operationId'] ?? null)) {
                    continue;
                }
                $component = self::successComponent($operation);
                if ($component !== null) {
                    $map[$operation['operationId']] = $component;
                }
            }
        }

        return self::$operationComponents = $map;
    }

    /** @return array<string, string> */
    private static function requestComponents(): array
    {
        if (self::$requestComponents !== null) {
            return self::$requestComponents;
        }

        $map = [];
        $paths = self::document()['paths'] ?? [];
        foreach (is_array($paths) ? $paths : [] as $operations) {
            foreach (is_array($operations) ? $operations : [] as $operation) {
                $operationId = is_array($operation) ? ($operation['operationId'] ?? null) : null;
                $ref = is_array($operation)
                    ? ($operation['requestBody']['content']['application/json']['schema']['$ref'] ?? null)
                    : null;
                if (is_string($operationId) && is_string($ref) && str_starts_with($ref, self::REF_PREFIX)) {
                    $map[$operationId] = substr($ref, strlen(self::REF_PREFIX));
                }
            }
        }

        return self::$requestComponents = $map;
    }

    /** @param array<string, mixed> $operation */
    private static function successComponent(array $operation): ?string
    {
        $responses = $operation['responses'] ?? [];
        foreach (['200', '201', '202'] as $status) {
            $ref = is_array($responses)
                ? ($responses[$status]['content']['application/json']['schema']['$ref'] ?? null)
                : null;
            if (is_string($ref) && str_starts_with($ref, self::REF_PREFIX)) {
                return substr($ref, strlen(self::REF_PREFIX));
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    private static function package(string $component): array
    {
        $components = self::components();
        if (! isset($components[$component])) {
            throw new InvalidArgumentException("Unknown agent API response schema [{$component}].");
        }

        $reachable = [];
        self::collect($component, $components, $reachable);
        $schema = self::rewriteSchema($components[$component]);
        $referenced = $reachable;
        unset($referenced[$component]);
        $defs = [];
        foreach ($referenced as $name => $_) {
            $defs[$name] = self::rewriteSchema($components[$name]);
        }
        if (self::referencesRoot($component, $referenced, $components)) {
            $defs[$component] = self::rewriteSchema($components[$component]);
        }
        if ($defs !== []) {
            ksort($defs);
            $schema['$defs'] = $defs;
        }

        return $schema;
    }

    /**
     * @param  array<string, array<string, mixed>>  $components
     * @param  array<string, true>  $seen
     */
    private static function collect(string $component, array $components, array &$seen): void
    {
        if (isset($seen[$component])) {
            return;
        }
        $seen[$component] = true;
        foreach (self::refsIn($components[$component] ?? []) as $ref) {
            if (! isset($components[$ref])) {
                throw new InvalidArgumentException("Dangling response schema reference [{$ref}].");
            }
            self::collect($ref, $components, $seen);
        }
    }

    /**
     * @param  array<string, true>  $referenced
     * @param  array<string, array<string, mixed>>  $components
     */
    private static function referencesRoot(string $root, array $referenced, array $components): bool
    {
        foreach ([...array_keys($referenced), $root] as $name) {
            if (in_array($root, self::refsIn($components[$name] ?? []), true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>|list<mixed>  $node
     * @return list<string>
     */
    private static function refsIn(array $node): array
    {
        $refs = [];
        foreach ($node as $key => $value) {
            if ($key === '$ref' && is_string($value) && str_starts_with($value, self::REF_PREFIX)) {
                $refs[] = substr($value, strlen(self::REF_PREFIX));

                continue;
            }
            if (is_array($value)) {
                $refs = [...$refs, ...self::refsIn($value)];
            }
        }

        return array_values(array_unique($refs));
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    private static function rewriteSchema(array $node): array
    {
        $rewritten = [];
        foreach ($node as $key => $value) {
            if ($key === '$ref' && is_string($value) && str_starts_with($value, self::REF_PREFIX)) {
                $rewritten[$key] = '#/$defs/'.substr($value, strlen(self::REF_PREFIX));

                continue;
            }
            $rewritten[$key] = self::rewriteValue($value);
        }

        return $rewritten;
    }

    private static function rewriteValue(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(self::rewriteValue(...), $value);
        }

        return self::rewriteSchema($value);
    }

    /** @return array<string, array<string, mixed>> */
    private static function components(): array
    {
        $schemas = self::document()['components']['schemas'] ?? null;
        if (! is_array($schemas) || $schemas === []) {
            throw new InvalidArgumentException('The agent API OpenAPI document declares no response schemas.');
        }

        return $schemas;
    }

    /** @return array<string, mixed> */
    private static function document(): array
    {
        if (self::$document !== null) {
            return self::$document;
        }

        $contents = file_get_contents(public_path(self::DOCUMENT));
        if ($contents === false) {
            throw new InvalidArgumentException('The agent API OpenAPI document is missing or unreadable.');
        }

        try {
            $document = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException('The agent API OpenAPI document is not valid JSON.');
        }
        if (! is_array($document)) {
            throw new InvalidArgumentException('The agent API OpenAPI document is not an object.');
        }

        return self::$document = $document;
    }
}
