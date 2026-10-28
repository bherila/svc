<?php

namespace Tests\Feature\Mcp;

use App\Services\AgentApi\Operations\AgentOperationCatalog;
use App\Services\Mcp\AgentMcpToolSchemas;
use App\Support\AgentApi\AgentApiResponseSchemaCatalog;
use Bherila\McpLaravelBridge\Capabilities\Operation;
use Bherila\McpLaravelBridge\Capabilities\OperationRegistry;
use Tests\TestCase;

/**
 * Every MCP read tool that reaches a REST operation takes what that operation
 * takes and promises what it answers (#459 item 7). A tool's input is its own
 * declaration - an inline schema or its handler's signature - so it can drift
 * from the route's parameters; this pins the two together, and the output is
 * the route's response component itself.
 */
final class AgentMcpReadToolSchemaContractTest extends TestCase
{
    /**
     * REST parameters an MCP tool takes under another name, by tool, then by
     * the REST name. A path parameter is otherwise `<name>_id` and a query
     * parameter its own name.
     *
     * @var array<string, array<string, string>>
     */
    private const array RENAMED = [
        'attachments.list' => ['recordType' => 'record_type', 'recordPublicId' => 'record_id'],
        'collaboration.attachments.list' => ['recordType' => 'record_type', 'recordPublicId' => 'record_id'],
        'collaboration.attachments.download_url' => ['recordType' => 'record_type', 'recordPublicId' => 'record_id'],
        'collaboration.comments.list' => ['parentType' => 'parent_type', 'parentPublicId' => 'parent_id'],
        'square.series.get' => ['series' => 'series_key'],
        'invoices.square_covered_payments' => ['squareInvoice' => 'square_invoice_id'],
        'finance.invoice_payments.list' => ['invoice' => 'invoice_id'],
    ];

    /**
     * REST parameters no MCP tool takes, with the reason.
     *
     * @var array<string, array<string, string>>
     */
    private const array REST_ONLY = [
        'invoices.list' => [
            'updated_since' => 'Incremental sync for the website and finance clients; an agent pages the list.',
            'watermark' => 'Incremental sync for the website and finance clients; an agent pages the list.',
        ],
        'clients.list' => [
            'updated_since' => 'Incremental sync for the website and finance clients; an agent pages the list.',
            'watermark' => 'Incremental sync for the website and finance clients; an agent pages the list.',
        ],
    ];

    public function test_each_read_tool_takes_exactly_its_routes_parameters(): void
    {
        $document = $this->document();
        $schemas = new AgentMcpToolSchemas;
        $checked = 0;
        foreach ($this->readTools() as $operation) {
            $input = $schemas->input($operation);
            // A tool with no arguments declares its properties as an empty object.
            $properties = (array) ($input['properties'] ?? []);
            $required = $input['required'] ?? [];
            sort($required);
            $expected = [];
            $expectedRequired = [];
            foreach ($operation->rest?->parameters ?? [] as $parameter) {
                $parameter = is_string($parameter) ? $document['components']['parameters'][$parameter] : $parameter;
                if (! in_array($parameter['in'], ['path', 'query'], true) || isset(self::REST_ONLY[$operation->id][$parameter['name']])) {
                    continue;
                }
                $name = self::RENAMED[$operation->id][$parameter['name']]
                    ?? ($parameter['in'] === 'query' || str_ends_with($parameter['name'], '_id') ? $parameter['name'] : $parameter['name'].'_id');
                $expected[$name] = $this->comparable($parameter['schema'] ?? [], $document);
                if (($parameter['required'] ?? false) === true) {
                    $expectedRequired[] = $name;
                }
            }
            sort($expectedRequired);
            $actual = array_map(fn (array $schema): array => $this->comparable($schema, $document), $properties);
            ksort($expected);
            ksort($actual);
            $this->assertSame($expected, $actual, $operation->id);
            $this->assertSame($expectedRequired, $required, $operation->id);
            $this->assertFalse($input['additionalProperties'] ?? true, $operation->id);
            $checked++;
        }
        $this->assertGreaterThanOrEqual(60, $checked);
    }

    public function test_each_read_tool_promises_exactly_its_routes_response(): void
    {
        $schemas = new AgentMcpToolSchemas;
        foreach ($this->readTools() as $operation) {
            $this->assertSame(AgentApiResponseSchemaCatalog::forOperation($operation->id), $schemas->output($operation), $operation->id);
        }
    }

    public function test_the_resources_that_return_a_route_representation_promise_exactly_it(): void
    {
        $schemas = new AgentMcpToolSchemas;
        $catalog = app(AgentOperationCatalog::class);
        $registry = app(OperationRegistry::class);
        foreach (['resources.current_context' => ['ContextResponse', false], 'resources.agreement' => ['AgreementResponse', true]] as $id => [$component, $manager]) {
            $operation = $registry->find($id);
            $this->assertNotNull($operation, $id);
            $this->assertNull($operation->rest, $id);
            $this->assertSame(AgentApiResponseSchemaCatalog::schema($component), $schemas->output($operation), $id);
            $this->assertSame($manager, $catalog->isManagerOnly($id), $id);
        }
        $this->assertSame(AgentApiResponseSchemaCatalog::forOperation('context.get'), AgentApiResponseSchemaCatalog::schema('ContextResponse'));
        $this->assertSame(AgentApiResponseSchemaCatalog::forOperation('agreements.get'), AgentApiResponseSchemaCatalog::schema('AgreementResponse'));
    }

    public function test_every_exception_names_a_parameter_its_route_still_takes(): void
    {
        $document = $this->document();
        $tools = collect($this->readTools())->keyBy('id');
        foreach (['renamed' => self::RENAMED, 'REST only' => self::REST_ONLY] as $list => $exceptions) {
            foreach ($exceptions as $id => $parameters) {
                $this->assertTrue($tools->has($id), "{$list}: {$id}");
                $names = array_map(
                    static fn (string|array $parameter): string => is_string($parameter) ? $document['components']['parameters'][$parameter]['name'] : $parameter['name'],
                    $tools[$id]->rest->parameters,
                );
                foreach (array_keys($parameters) as $name) {
                    $this->assertContains($name, $names, "{$list}: {$id} {$name}");
                }
            }
        }
    }

    /** @return list<Operation> */
    private function readTools(): array
    {
        config(['agent_api.writes_enabled' => true]);

        return array_values(array_filter(
            app(OperationRegistry::class)->all(),
            static fn (Operation $operation): bool => $operation->rest !== null && $operation->mcp !== null && $operation->effect->readOnly(),
        ));
    }

    /**
     * A parameter's schema as both sides can state it: a tool's optional
     * argument is nullable and defaults to null where the route's is simply
     * absent, and the route may name an enum by reference.
     *
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    private function comparable(array $schema, array $document): array
    {
        if (isset($schema['$ref'])) {
            $schema = $document['components']['schemas'][substr($schema['$ref'], strlen('#/components/schemas/'))];
        }
        if (is_array($schema['type'] ?? null)) {
            $types = array_values(array_filter($schema['type'], static fn (string $type): bool => $type !== 'null'));
            $schema['type'] = count($types) === 1 ? $types[0] : $types;
        }
        if (isset($schema['enum'])) {
            $schema['enum'] = array_values(array_filter($schema['enum'], static fn (mixed $value): bool => $value !== null));
        }
        unset($schema['default'], $schema['description']);
        if (isset($schema['properties'])) {
            $schema['properties'] = array_map(fn (array $property): array => $this->comparable($property, $document), $schema['properties']);
        }
        ksort($schema);

        return $schema;
    }

    /** @return array<string, mixed> */
    private function document(): array
    {
        return json_decode((string) file_get_contents(public_path('openapi/svc-agent-v1.json')), true, flags: JSON_THROW_ON_ERROR);
    }
}
