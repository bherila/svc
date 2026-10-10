<?php

namespace Tests\Feature\AgentApi;

use App\Support\AgentApi\AgentApiResponseSchemaCatalog;
use App\Support\AgentApi\AgentApiScopes;
use Bherila\McpLaravelBridge\Capabilities\OperationRegistry;
use Bherila\McpLaravelBridge\OpenApi\OpenApiDocumentBuilder;
use Bherila\McpLaravelBridge\OpenApi\OpenApiSettings;
use LogicException;
use Tests\TestCase;

/**
 * Check mode for generating the agent API contract from the operation
 * registry (#408 item 3, #383).
 *
 * `public/openapi/svc-agent-v1.json` stays the checked contract until the
 * package's OpenApiDocumentBuilder reproduces it byte for byte; only then does
 * SVC switch to generation. Until that day this pins, per operation, which
 * top-level fields the generated operation gets wrong and which operations the
 * builder cannot express at all. The list may only shrink: a new difference
 * fails, and so does one that disappears without the list being updated, so
 * every step towards generation is a reviewed diff.
 *
 * Regenerate with SVC_UPDATE_OPENAPI_GENERATION_CHECK=1.
 */
final class OpenApiGenerationCheckTest extends TestCase
{
    private const string SHIPPED = 'openapi/svc-agent-v1.json';

    private const string DIFFERENCES = 'tests/Fixtures/AgentApi/openapi-generation-differences.json';

    public function test_generated_operations_differ_from_the_contract_only_as_recorded(): void
    {
        $shipped = json_decode((string) file_get_contents(public_path(self::SHIPPED)), true, flags: JSON_THROW_ON_ERROR);
        $builder = $this->builder($shipped);
        $documented = [];
        foreach ($shipped['paths'] as $item) {
            foreach ($item as $operation) {
                if (is_array($operation) && isset($operation['operationId'])) {
                    $documented[$operation['operationId']] = $operation;
                }
            }
        }

        $observed = ['refused' => [], 'differences' => []];
        foreach (app(OperationRegistry::class)->all() as $operation) {
            if ($operation->rest === null) {
                continue;
            }
            $this->assertArrayHasKey($operation->id, $documented, 'Every REST operation is documented');
            try {
                $generated = $builder->operation($operation);
            } catch (LogicException $refused) {
                $observed['refused'][$operation->id] = $refused->getMessage();

                continue;
            }
            $expected = $documented[$operation->id];
            $fields = array_values(array_unique([...array_keys($expected), ...array_keys($generated)]));
            sort($fields);
            foreach ($fields as $field) {
                if (json_encode($expected[$field] ?? null) !== json_encode($generated[$field] ?? null)) {
                    $observed['differences'][] = $operation->id.' '.$field;
                }
            }
        }
        ksort($observed['refused']);
        sort($observed['differences']);

        $path = base_path(self::DIFFERENCES);
        $encoded = json_encode($observed, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
        if (getenv('SVC_UPDATE_OPENAPI_GENERATION_CHECK') === '1') {
            file_put_contents($path, $encoded);
        }
        $this->assertFileExists($path);
        $this->assertSame((string) file_get_contents($path), $encoded, 'The generated operations moved relative to the contract; review and update the recorded differences.');

        if ($observed === ['refused' => [], 'differences' => []]) {
            $this->assertSame([], OpenApiDocumentBuilder::differences($builder->full(), public_path(self::SHIPPED)),
                'Every operation now generates exactly; finish the document-level fields, then switch to generation (#383).');
        }
    }

    /**
     * The builder as SVC would configure it. The installation addresses are
     * the shipped document's own: /api/openapi.json substitutes this
     * installation's at serve time either way.
     *
     * @param  array<string, mixed>  $shipped
     */
    private function builder(array $shipped): OpenApiDocumentBuilder
    {
        $flow = $shipped['components']['securitySchemes']['oauth2']['flows']['authorizationCode'];

        return new OpenApiDocumentBuilder(app(OperationRegistry::class), new OpenApiSettings(
            title: $shipped['info']['title'],
            version: $shipped['info']['version'],
            serverUrl: $shipped['servers'][0]['url'],
            authorizationUrl: $flow['authorizationUrl'],
            tokenUrl: $flow['tokenUrl'],
            scopes: AgentApiScopes::descriptions(),
            connectionScopes: [AgentApiScopes::MCP_USE],
        ), AgentApiResponseSchemaCatalog::catalog());
    }
}
