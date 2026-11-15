<?php

namespace Tests\Unit\AgentApi;

use App\Services\AgentApi\Operations\AgentOperationCatalog;
use App\Support\AgentApi\AgentApiResponseSchemaCatalog;
use Bherila\McpLaravelBridge\Capabilities\OperationToolFactory;
use Illuminate\Container\Container;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Mcp\Capability\Discovery\SchemaValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

/** Contracts a client consumes from MCP discovery, including JSON object boundaries. */
final class InlineOperationSchemaConsumerTest extends TestCase
{
    private ?Container $previous = null;

    private Application $application;

    private ?string $fixtureDirectory = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previous = Container::getInstance();
        $this->application = new Application(dirname(__DIR__, 3));
        AgentApiResponseSchemaCatalog::flush();
    }

    protected function tearDown(): void
    {
        AgentApiResponseSchemaCatalog::flush();
        Container::setInstance($this->previous);
        if ($this->fixtureDirectory !== null) {
            (new Filesystem)->deleteDirectory($this->fixtureDirectory);
        }
        parent::tearDown();
    }

    public function test_preview_discovery_accepts_selection_and_excludes_creation_only_fields(): void
    {
        $schema = $this->wireSchema('deferred_backlog.preview');
        $this->assertSame(['workspace_id', 'agreement_id'], $schema->required);
        foreach (['hours', 'amount', 'round_up', 'discount_amount', 'discount_reason'] as $field) {
            $this->assertObjectHasProperty($field, $schema->properties);
        }
        $this->assertObjectNotHasProperty('invoice_id', $schema->properties);
        $this->assertObjectNotHasProperty('expected_backlog_version', $schema->properties);
        $selection = ['workspace_id' => self::WORKSPACE, 'agreement_id' => self::AGREEMENT, 'hours' => 2];
        $validator = new SchemaValidator;
        $this->assertSame([], $validator->validateAgainstJsonSchema((object) $selection, $schema));
        foreach (['invoice_id' => self::AGREEMENT, 'expected_backlog_version' => str_repeat('a', 64)] as $field => $value) {
            $this->assertNotSame([], $validator->validateAgainstJsonSchema((object) [...$selection, $field => $value], $schema));
        }
    }

    /** @return array<string, array{string, list<string>, array<string, mixed>}> */
    public static function clientWrites(): array
    {
        return [
            'create' => ['clients.create', ['workspace_id', 'idempotency_key', 'name'], ['name' => 'Synthetic client']],
            'update' => ['clients.update', ['workspace_id', 'client_id', 'idempotency_key', 'expected_version'], [
                'client_id' => self::AGREEMENT, 'expected_version' => str_repeat('a', 64),
            ]],
        ];
    }

    #[DataProvider('clientWrites')]
    public function test_client_write_discovery_flattens_business_fields_and_preserves_required_guards(string $operation, array $required, array $body): void
    {
        $schema = $this->wireSchema($operation);
        foreach (['name', 'billing_address', 'phone', 'website_url', 'tax_id', 'currency', 'notes'] as $field) {
            $this->assertObjectHasProperty($field, $schema->properties, $operation);
        }
        $this->assertObjectNotHasProperty('0', $schema->properties, 'A nested body array is not a field an MCP client can send.');
        $actual = $schema->required;
        sort($actual);
        sort($required);
        $this->assertSame($required, $actual);
        $payload = ['workspace_id' => self::WORKSPACE, 'idempotency_key' => 'synthetic-client-write', 'billing_address' => 'Synthetic address', ...$body];
        $validator = new SchemaValidator;
        $this->assertSame([], $validator->validateAgainstJsonSchema((object) $payload, $schema));
        foreach ($required as $field) {
            $missing = $payload;
            unset($missing[$field]);
            $this->assertNotSame([], $validator->validateAgainstJsonSchema((object) $missing, $schema), $field);
        }
    }

    public function test_discovery_still_exports_a_preview_when_the_document_has_empty_object_properties(): void
    {
        $this->useEmptyRequestObject('DeferredBuyDownCreateRequest');
        $schema = $this->wireSchema('deferred_backlog.preview');
        $this->assertObjectHasProperty('workspace_id', $schema->properties);
        $this->assertObjectHasProperty('agreement_id', $schema->properties);
        $this->assertObjectNotHasProperty('invoice_id', $schema->properties);
        $this->assertSame([], (new SchemaValidator)->validateAgainstJsonSchema((object) [
            'workspace_id' => self::WORKSPACE, 'agreement_id' => self::AGREEMENT,
        ], $schema));
    }

    public function test_discovery_still_exports_a_client_write_when_the_document_has_empty_object_properties(): void
    {
        $this->useEmptyRequestObject('clients_createRequest');
        $schema = $this->wireSchema('clients.create');
        $this->assertObjectHasProperty('workspace_id', $schema->properties);
        $this->assertObjectHasProperty('idempotency_key', $schema->properties);
        // The real handler still requires the name even when the declared body is empty.
        $this->assertContains('name', $schema->required);
        $this->assertSame([], (new SchemaValidator)->validateAgainstJsonSchema((object) [
            'workspace_id' => self::WORKSPACE, 'idempotency_key' => 'synthetic-client-write', 'name' => 'Synthetic client',
        ], $schema));
    }

    private const string WORKSPACE = '00000000-0000-4000-8000-000000000001';

    private const string AGREEMENT = '00000000-0000-4000-8000-000000000002';

    private function wireSchema(string $id): stdClass
    {
        $operation = (new AgentOperationCatalog)->contract()->find($id);
        $this->assertNotNull($operation);
        $tool = (new OperationToolFactory(AgentApiResponseSchemaCatalog::catalog()))->tool($operation);
        $wire = json_decode(json_encode($tool, JSON_THROW_ON_ERROR), flags: JSON_THROW_ON_ERROR);
        $this->assertInstanceOf(stdClass::class, $wire->inputSchema);
        $this->assertInstanceOf(stdClass::class, $wire->inputSchema->properties);

        return $wire->inputSchema;
    }

    private function useEmptyRequestObject(string $component): void
    {
        // Preserve the document's JSON kinds. Empty properties are a legitimate
        // schema object, which the shared catalog deliberately retains as {}.
        $document = json_decode(file_get_contents($this->application->publicPath('openapi/svc-agent-v1.json')), flags: JSON_THROW_ON_ERROR);
        $document->components->schemas->{$component} = (object) [
            'type' => 'object', 'additionalProperties' => false, 'properties' => new stdClass, 'required' => [],
        ];
        $this->fixtureDirectory = sys_get_temp_dir().'/svc-inline-schema-'.bin2hex(random_bytes(6));
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->fixtureDirectory.'/openapi');
        file_put_contents($this->fixtureDirectory.'/openapi/svc-agent-v1.json', json_encode($document, JSON_THROW_ON_ERROR));
        $this->application->usePublicPath($this->fixtureDirectory);
        AgentApiResponseSchemaCatalog::flush();
    }
}
