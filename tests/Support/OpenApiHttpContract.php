<?php

namespace Tests\Support;

use App\Support\AgentApi\AgentOpenApi;
use Bherila\McpLaravelBridge\Mcp\LocalOnlySchemaValidator;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Assert;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Checks actual feature-test exchanges, including refusals, against OpenAPI. */
final class OpenApiHttpContract
{
    private static ?array $document = null;

    private static ?LocalOnlySchemaValidator $validator = null;

    /** @var array<string, array<string, mixed>> */
    private static array $schemas = [];

    public static function assertExchange(TestResponse $response): void
    {
        $request = $response->baseRequest;
        if ($request === null || ! $request->is('api/v1', 'api/v1/*')) {
            return;
        }
        // MCP is the JSON-RPC transport, not a REST operation. Its envelopes
        // and tool schemas have their own protocol and contract assertions.
        if ($request->is('api/v1/mcp')) {
            return;
        }

        $route = $request->route();
        if ($route === null) {
            Assert::assertContains($response->status(), [404, 405], 'An unrouted API request must be refused.');

            return;
        }
        $document = self::document();
        $path = substr('/'.$route->uri(), strlen('/api/v1')) ?: '/';
        $method = strtolower($request->method());
        // HEAD uses GET's contract but carries no response body.
        $operation = $document['paths'][$path][$method === 'head' ? 'get' : $method] ?? null;
        Assert::assertIsArray($operation, strtoupper($method).' '.$path.' is missing from OpenAPI.');
        $label = $operation['operationId'];
        $success = $response->status() >= 200 && $response->status() < 300;

        // Negative feature cases intentionally send malformed inputs. Validate
        // every accepted request; the response contract also covers refusals.
        if ($success) {
            foreach ($operation['parameters'] ?? [] as $parameter) {
                $parameter = self::resolve($parameter);
                $name = $parameter['name'];
                $value = match ($parameter['in']) {
                    'path' => $route->originalParameter($name),
                    'query' => $request->query($name),
                    'header' => $request->header($name),
                    default => null,
                };
                if ($value === null) {
                    Assert::assertFalse($parameter['required'] ?? false, "{$label} accepted a missing {$parameter['in']} parameter {$name}.");

                    continue;
                }
                $schema = $parameter['schema'];
                // Path/query/header values are strings on the HTTP wire.
                $value = self::parameterValue($value, self::resolve($schema));
                self::assertSchema($value, $schema, "{$label} request {$parameter['in']} {$name}");
            }
            if (isset($operation['requestBody'])) {
                $body = self::resolve($operation['requestBody']);
                $mediaType = strtolower(explode(';', (string) $request->header('Content-Type'))[0]);
                Assert::assertArrayHasKey($mediaType, $body['content'], "{$label} accepted undocumented request content type {$mediaType}.");
                $data = $mediaType === 'application/json'
                    ? json_decode($request->getContent(), false, flags: JSON_THROW_ON_ERROR)
                    : self::formData($request->request->all() + $request->allFiles());
                self::assertSchema($data, $body['content'][$mediaType]['schema'], "{$label} request body");
            }
        }

        $status = (string) $response->status();
        $definition = $operation['responses'][$status]
            ?? $operation['responses'][substr($status, 0, 1).'XX']
            ?? $operation['responses']['default'] ?? null;
        Assert::assertIsArray($definition, "{$label} returned undocumented status {$status}.");
        $definition = self::resolve($definition);
        if ($method === 'head' || in_array($response->status(), [204, 304], true)) {
            return;
        }
        $mediaType = strtolower(explode(';', (string) $response->headers->get('Content-Type'))[0]);
        Assert::assertArrayHasKey($mediaType, $definition['content'] ?? [], "{$label} returned undocumented {$status} content type {$mediaType}.");
        if ($mediaType === 'application/json') {
            self::assertSchema(json_decode($response->getContent(), false, flags: JSON_THROW_ON_ERROR), $definition['content'][$mediaType]['schema'], "{$label} response {$status}");
        } elseif (! $response->baseResponse instanceof StreamedResponse) {
            self::assertSchema($response->getContent(), $definition['content'][$mediaType]['schema'], "{$label} response {$status}");
        }
    }

    /** @return array<string, mixed> */
    private static function document(): array
    {
        return self::$document ??= json_decode((string) file_get_contents(public_path(AgentOpenApi::CHECKED_DOCUMENT)), true, flags: JSON_THROW_ON_ERROR);
    }

    /** Resolve only local OpenAPI references; never fetch an external URL. */
    private static function resolve(array $node): array
    {
        $seen = [];
        while (isset($node['$ref'])) {
            $reference = $node['$ref'];
            Assert::assertStringStartsWith('#/', $reference);
            Assert::assertArrayNotHasKey($reference, $seen, 'Cyclic OpenAPI reference.');
            $seen[$reference] = true;
            $target = self::document();
            foreach (explode('/', substr($reference, 2)) as $part) {
                $part = str_replace(['~1', '~0'], ['/', '~'], $part);
                Assert::assertArrayHasKey($part, $target, "Missing OpenAPI reference {$reference}.");
                $target = $target[$part];
            }
            $node = array_diff_key($node, ['$ref' => true]) + $target;
        }

        return $node;
    }

    private static function parameterValue(mixed $value, array $schema): mixed
    {
        if (! is_string($value)) {
            return $value;
        }
        if (($schema['type'] ?? null) === 'integer' && preg_match('/^-?\d+$/D', $value)) {
            return (int) $value;
        }
        if (($schema['type'] ?? null) === 'boolean') {
            return match ($value) {
                '1', 'true' => true,
                '0', 'false' => false,
                default => $value,
            };
        }

        return $value;
    }

    private static function formData(mixed $value): mixed
    {
        if ($value instanceof UploadedFile) {
            return $value->getClientOriginalName();
        }
        if (is_array($value)) {
            $converted = array_map(self::formData(...), $value);

            return array_is_list($converted) && $converted !== [] ? $converted : (object) $converted;
        }

        return $value;
    }

    /** Include only reachable local components to keep per-request checks bounded. */
    private static function collectComponents(array $schema, array &$components): void
    {
        foreach ($schema as $key => $value) {
            if ($key === '$ref' && is_string($value) && str_starts_with($value, '#/components/schemas/')) {
                $name = substr($value, strlen('#/components/schemas/'));
                if (! array_key_exists($name, $components)) {
                    $components[$name] = self::resolve(['$ref' => $value]);
                    self::collectComponents($components[$name], $components);
                }
            } elseif (is_array($value)) {
                self::collectComponents($value, $components);
            }
        }
    }

    private static function assertSchema(mixed $value, array $schema, string $label): void
    {
        $key = json_encode($schema, JSON_THROW_ON_ERROR);
        if (! isset(self::$schemas[$key])) {
            $components = [];
            self::collectComponents($schema, $components);
            // Wrapping preserves an empty array on the wire. The SDK's
            // top-level PHP-array convenience would otherwise turn [] into {}.
            self::$schemas[$key] = [
                'type' => 'object',
                'required' => ['value'],
                'properties' => ['value' => $schema],
                'components' => ['schemas' => $components === [] ? (object) [] : $components],
            ];
        }
        $errors = (self::$validator ??= new LocalOnlySchemaValidator)->validateAgainstJsonSchema((object) ['value' => $value], self::$schemas[$key]);
        Assert::assertSame([], $errors, $label.' violates OpenAPI: '.json_encode($errors, JSON_THROW_ON_ERROR));
    }
}
