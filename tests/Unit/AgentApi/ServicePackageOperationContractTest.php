<?php

namespace Tests\Unit\AgentApi;

use App\Http\Middleware\EnsureServicePackagesEnabled;
use App\Services\AgentApi\Operations\AgentDeploymentFlags;
use App\Services\AgentApi\Operations\AgentOperationCatalog;
use App\Services\AgentApi\Operations\ServicePackageOperations;
use App\Services\Mcp\AgentMcpPackageTools;
use App\Support\AgentApi\AgentApiResponseSchemaCatalog;
use App\Support\Packages\PackageRefusal;
use App\Support\Packages\ServicePackageRules;
use Bherila\McpLaravelBridge\Capabilities\Effect;
use Bherila\McpLaravelBridge\Capabilities\IdempotencyKey;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;
use Tests\Support\Requirement;

/**
 * The service package and sign-up request operations, declared once (API-1) and read without
 * booting the application. A bare container has no configuration, so the
 * installation's registry leaves them out and the contract has them.
 */
final class ServicePackageOperationContractTest extends TestCase
{
    private ?Container $previous = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previous = Container::getInstance();
        new Application(dirname(__DIR__, 3));
        AgentApiResponseSchemaCatalog::flush();
    }

    protected function tearDown(): void
    {
        AgentApiResponseSchemaCatalog::flush();
        Container::setInstance($this->previous);
        parent::tearDown();
    }

    #[Requirement('API-1', 'API-2', 'API-6')]
    public function test_each_operation_is_declared_once_with_its_route_scopes_switches_and_safety(): void
    {
        $catalog = new AgentOperationCatalog;
        $contract = $catalog->contract();
        $base = '/workspaces/{workspace}/packages';
        foreach ([
            'packages.list' => ['GET', 'index', Effect::Read, [200], $base, 'ServicePackageListResponse'],
            'packages.get' => ['GET', 'show', Effect::Read, [200], $base.'/{package}', 'ServicePackageResponse'],
            'packages.create' => ['POST', 'store', Effect::LocalWrite, [201], $base, 'ServicePackageResponse'],
            'packages.update' => ['PATCH', 'update', Effect::LocalWrite, [200], $base.'/{package}', 'ServicePackageResponse'],
            'packages.publish' => ['POST', 'publish', Effect::LocalWrite, [200], $base.'/{package}/publish', 'ServicePackageResponse'],
            'packages.retire' => ['POST', 'retire', Effect::Destructive, [200], $base.'/{package}/retire', 'ServicePackageResponse'],
        ] as $id => [$method, $route, $effect, $statuses, $path, $response]) {
            $this->assertNull($catalog->registry()->find($id), "{$id} exists only while service packages are on");
            $operation = $contract->find($id);
            $this->assertNotNull($operation, $id);
            $write = $method !== 'GET';
            $this->assertSame($effect, $operation->effect, $id);
            $this->assertTrue($operation->idempotent, $id);
            $this->assertTrue($catalog->isManagerOnly($id), $id);
            $this->assertSame([AgentMcpPackageTools::class, ServicePackageOperations::DEFINITIONS[$id][0]], $operation->mcp?->handler, $id);
            $this->assertSame($method, $operation->rest?->method, $id);
            $this->assertSame('agent-api.v1.packages.'.$route, $operation->rest->routeName, $id);
            $this->assertSame($path, $operation->rest->path, $id);
            $this->assertSame($statuses, $operation->rest->successStatuses, $id);
            $this->assertSame($write ? ['packages:write', 'packages:read'] : ['packages:read'], $operation->requirement->scopes, $id);
            $this->assertSame([
                ...($write ? [AgentDeploymentFlags::PACKAGES] : []), AgentDeploymentFlags::SERVICE_PACKAGES,
                'mcp', 'mcp.switch:'.($write ? 'mcp.write' : 'mcp.read'), 'mcp.switch:'.$id,
            ], $operation->requirement->flags, $id);
            $this->assertSame(['x-svc-flags' => $write
                ? ['AGENT_API_WRITES_ENABLED', 'AGENT_API_PACKAGE_WRITES_ENABLED', 'SVC_SERVICE_PACKAGES_ENABLED']
                : ['SVC_SERVICE_PACKAGES_ENABLED'], 'x-mcp-tool' => $id], $operation->extensions, $id);
            $this->assertSame($write ? IdempotencyKey::HeaderAndArgument : IdempotencyKey::None, $operation->safety->idempotencyKey, $id);
            $this->assertSame(in_array($id, ['packages.update', 'packages.publish', 'packages.retire'], true), $operation->safety->expectedVersion, $id);
            $this->assertSame(in_array($id, ['packages.publish', 'packages.retire'], true), $operation->safety->confirm, $id);
            $this->assertSame($response, ServicePackageOperations::DEFINITIONS[$id][3], $id);
        }
        $this->assertTrue(AgentDeploymentFlags::isAvailabilityPrecondition(AgentDeploymentFlags::SERVICE_PACKAGES));
        $this->assertNotContains(AgentDeploymentFlags::SERVICE_PACKAGES, AgentDeploymentFlags::cutovers());
        // The product switch is named once and the flags after it still are.
        $this->assertSame(
            ['SVC_SERVICE_PACKAGES_ENABLED', 'AGENT_API_WRITES_ENABLED', 'AGENT_API_PACKAGE_WRITES_ENABLED'],
            AgentDeploymentFlags::environmentSwitches([AgentDeploymentFlags::SERVICE_PACKAGES, AgentDeploymentFlags::PACKAGES]),
        );
    }

    public function test_the_registry_and_the_contract_are_each_built_once(): void
    {
        $catalog = new AgentOperationCatalog;

        $this->assertSame($catalog->registry(), $catalog->registry());
        $this->assertSame($catalog->contract(), $catalog->contract());
        $this->assertNotSame($catalog->registry(), $catalog->contract());
    }

    #[Requirement('API-6', 'X-1')]
    public function test_the_installation_offers_the_operations_exactly_while_its_switch_is_on(): void
    {
        $app = new Application(dirname(__DIR__, 3));
        $app->instance('config', new Repository([]));
        $this->assertFalse(EnsureServicePackagesEnabled::enabled(), 'a switch that is not configured is off');

        $app->instance('config', new Repository(['svc' => ['service_packages' => ['enabled' => true]]]));
        $on = new AgentOperationCatalog;
        $this->assertNotNull($on->registry()->find('packages.list'));
        $this->assertSame($on->registry(), $on->contract());

        $app->instance('config', new Repository(['svc' => ['service_packages' => ['enabled' => false]]]));
        $off = new AgentOperationCatalog;
        $this->assertNull($off->registry()->find('packages.list'));
        $this->assertNotNull($off->contract()->find('packages.list'));
    }

    /** Package requests are read from their declaration, so a document generated before the package paths existed still builds. */
    public function test_the_contract_reads_package_requests_without_the_checked_document(): void
    {
        $base = sys_get_temp_dir().'/svc-package-contract-'.bin2hex(random_bytes(6));
        $files = new Filesystem;
        $files->ensureDirectoryExists($base.'/public/openapi');
        try {
            $document = self::checkedDocument();
            foreach ($document['paths'] as $path => $methods) {
                if (str_contains($path, '/packages')) {
                    unset($document['paths'][$path]);
                }
            }
            $files->put($base.'/public/openapi/svc-agent-v1.json', json_encode($document, JSON_THROW_ON_ERROR));
            new Application($base);
            AgentApiResponseSchemaCatalog::flush();

            $contract = (new AgentOperationCatalog)->contract();

            $this->assertTrue($contract->find('packages.update')?->safety->expectedVersion);
            $this->assertTrue($contract->find('packages.publish')?->safety->confirm);
        } finally {
            $files->deleteDirectory($base);
        }
    }

    public function test_the_family_adds_exactly_these_components(): void
    {
        $this->assertSame([
            'ServicePackage', 'ServicePackageVersion', 'ServicePackageTerms',
            'ServicePackageResponse', 'ServicePackageListResponse',
            'ServicePackageCreateRequest', 'ServicePackageUpdateRequest', 'ServicePackageConfirmRequest',
            'ServicePackageRequest', 'ServicePackageEnrollmentSummary', 'ServicePackageRequestResponse',
            'ServicePackageRequestListResponse', 'ServicePackageRequestApprovalResponse',
            'ServicePackageRequestApproveRequest', 'ServicePackageRequestDeclineRequest',
        ], ServicePackageOperations::components());
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown service package component ServicePackageSecret.');

        ServicePackageOperations::schema('ServicePackageSecret');
    }

    /** What the code documents is what the checked document publishes, operation by operation. */
    #[Requirement('API-1')]
    public function test_the_documentation_is_what_the_checked_document_publishes(): void
    {
        $published = [];
        foreach (self::checkedDocument()['paths'] as $path => $methods) {
            foreach ($methods as $method => $operation) {
                if (is_array($operation) && isset($operation['operationId'])) {
                    $published[$operation['operationId']] = [$path, $method, $operation];
                }
            }
        }
        $reference = static fn (string $ref, string $kind): string => substr($ref, strlen("#/components/{$kind}/"));

        $documentation = ServicePackageOperations::documentation();
        $this->assertSame(array_keys(ServicePackageOperations::DEFINITIONS), array_keys($documentation));
        foreach ($documentation as $id => $declared) {
            [$path, $method, $operation] = $published[$id];
            $this->assertSame(ServicePackageOperations::ROUTES[$id][1], $path, $id);
            $responses = $operation['responses'];
            $status = ServicePackageOperations::ROUTES[$id][2][0];
            $success = $responses[$status];
            unset($responses[$status], $responses['default']);

            $this->assertSame([
                'method' => strtoupper($method),
                'scopes' => $operation['security'][0]['oauth2'],
                'summary' => ServicePackageOperations::DEFINITIONS[$id][1],
                'description' => $operation['description'],
                'parameters' => array_map(static fn (array $parameter): string|array => isset($parameter['$ref']) ? $reference($parameter['$ref'], 'parameters') : $parameter, $operation['parameters']),
                'response' => $reference($success['content']['application/json']['schema']['$ref'], 'schemas'),
                'response_description' => $success['description'],
                'errors' => array_map(static fn (array $response): string => $response['description'], $responses),
                ...(isset($operation['requestBody']) ? ['request' => $reference($operation['requestBody']['content']['application/json']['schema']['$ref'], 'schemas')] : []),
            ], $declared, $id);
        }
    }

    public function test_only_a_write_has_a_body(): void
    {
        $this->assertSame(ServicePackageOperations::schema('ServicePackageUpdateRequest'), ServicePackageOperations::requestSchema('packages.update'));
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Operation [packages.list] takes no body.');

        ServicePackageOperations::bodyFields('packages.list');
    }

    public function test_a_read_has_no_request_schema(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Operation [packages.get] takes no body.');

        ServicePackageOperations::requestSchema('packages.get');
    }

    public function test_rules_translate_to_json_schema_bounds_by_type(): void
    {
        $this->assertSame([
            'tags' => ['type' => 'array', 'items' => ['type' => 'string', 'minLength' => 1], 'minItems' => 1, 'maxItems' => 3],
            'pair' => ['type' => 'array', 'items' => ['type' => 'integer', 'minimum' => 5, 'maximum' => 5], 'minItems' => 2, 'maxItems' => 2],
            'code' => ['type' => 'string', 'minLength' => 3, 'maxLength' => 3],
            'weight' => ['type' => ['number', 'null'], 'exclusiveMinimum' => 0, 'maximum' => 10],
            'level' => ['type' => 'integer', 'enum' => ['1', '2']],
            'choice' => ['enum' => ['a', 'b', null], 'type' => ['string', 'null']],
            'anything' => [],
            'slug' => ['type' => 'string', 'pattern' => '^[a-z]+$', 'minLength' => 1],
            'item' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['a'], 'properties' => [
                'a' => ['type' => 'string', 'minLength' => 1],
                'b' => ['type' => ['boolean', 'null']],
            ]],
        ], ServicePackageOperations::fromRules([
            'tags' => ['array', 'min:1', 'max:3'],
            'tags.*' => ['required', 'string'],
            'pair' => ['array', 'size:2'],
            'pair.*' => ['integer', 'size:5'],
            'code' => ['string', 'size:3'],
            'weight' => ['nullable', 'numeric', 'gt:0', 'max:10'],
            'level' => ['integer', 'in:1,2'],
            'choice' => ['nullable', 'in:a,b'],
            'anything' => ['required'],
            'slug' => ['required', 'string', 'regex:/^[a-z]+$/'],
            'item' => ['array:a,b'],
            'item.a' => ['required', 'string'],
            'item.b' => ['nullable', 'boolean'],
        ]));
    }

    /** @return iterable<string, array{list<mixed>, string}> */
    public static function untranslatableRules(): iterable
    {
        yield 'an unknown rule' => [['email'], 'Rule [email] for [field] has no schema translation.'];
        yield 'a rule object that is not a string' => [[new stdClass], 'Rule for [field] has no schema translation.'];
        yield 'a bound with no meaning for its type' => [['string', 'gt:0'], 'Bound [gt] for [field] has no schema translation.'];
        yield 'a pattern with flags' => [['string', 'regex:/^a$/i'], 'Pattern for [field] is not a /-delimited regex without flags.'];
        yield 'a pattern with other delimiters' => [['string', 'regex:#^a$#'], 'Pattern for [field] is not a /-delimited regex without flags.'];
        yield 'a pattern not opened by its delimiter' => [['string', 'regex:a/b/'], 'Pattern for [field] is not a /-delimited regex without flags.'];
    }

    /** @param list<mixed> $rules */
    #[DataProvider('untranslatableRules')]
    public function test_a_rule_the_contract_cannot_say_fails_the_generation(array $rules, string $message): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage($message);

        ServicePackageOperations::fromRules(['field' => $rules]);
    }

    #[Requirement('API-1', 'API-2', 'API-6')]
    public function test_each_request_operation_is_declared_once_with_its_route_scopes_switches_and_safety(): void
    {
        $catalog = new AgentOperationCatalog;
        $contract = $catalog->contract();
        $base = '/workspaces/{workspace}/package-requests';
        foreach ([
            'package_requests.list' => ['GET', 'index', Effect::Read, $base, 'ServicePackageRequestListResponse', null],
            'package_requests.get' => ['GET', 'show', Effect::Read, $base.'/{request}', 'ServicePackageRequestResponse', null],
            'package_requests.approve' => ['POST', 'approve', Effect::ExternalWrite, $base.'/{request}/approve', 'ServicePackageRequestApprovalResponse', 'ServicePackageRequestApproveRequest'],
            'package_requests.decline' => ['POST', 'decline', Effect::ExternalWrite, $base.'/{request}/decline', 'ServicePackageRequestResponse', 'ServicePackageRequestDeclineRequest'],
            'package_requests.erase' => ['POST', 'erase', Effect::Destructive, $base.'/{request}/erase', 'ServicePackageRequestResponse', 'ServicePackageConfirmRequest'],
        ] as $id => [$method, $route, $effect, $path, $response, $request]) {
            $this->assertNull($catalog->registry()->find($id), "{$id} exists only while service packages are on");
            $operation = $contract->find($id);
            $this->assertNotNull($operation, $id);
            $write = $method !== 'GET';
            $this->assertSame($effect, $operation->effect, $id);
            $this->assertTrue($catalog->isManagerOnly($id), $id);
            $this->assertSame([AgentMcpPackageTools::class, ServicePackageOperations::DEFINITIONS[$id][0]], $operation->mcp?->handler, $id);
            // The website links to these route names.
            $this->assertSame('agent-api.v1.package-requests.'.$route, $operation->rest?->routeName, $id);
            $this->assertSame($method, $operation->rest->method, $id);
            $this->assertSame($path, $operation->rest->path, $id);
            $this->assertSame([200], $operation->rest->successStatuses, $id);
            $this->assertSame($write ? ['packages:write', 'packages:read'] : ['packages:read'], $operation->requirement->scopes, $id);
            $this->assertSame([
                ...($write ? [AgentDeploymentFlags::PACKAGES] : []), AgentDeploymentFlags::SERVICE_PACKAGES,
                'mcp', 'mcp.switch:'.($write ? 'mcp.write' : 'mcp.read'), 'mcp.switch:'.$id,
            ], $operation->requirement->flags, $id);
            $this->assertSame($write ? IdempotencyKey::HeaderAndArgument : IdempotencyKey::None, $operation->safety->idempotencyKey, $id);
            // Every decision is versioned and confirmed (API-2).
            $this->assertSame($write, $operation->safety->expectedVersion, $id);
            $this->assertSame($write, $operation->safety->confirm, $id);
            $this->assertSame([$response, $request], [ServicePackageOperations::DEFINITIONS[$id][3], ServicePackageOperations::DEFINITIONS[$id][4]], $id);
        }

        $approve = ServicePackageOperations::schema('ServicePackageRequestApproveRequest');
        $decline = ServicePackageOperations::schema('ServicePackageRequestDeclineRequest');
        $this->assertSame(['expected_version', 'confirm'], $approve['required']);
        $this->assertSame(['expected_version', 'confirm'], $decline['required']);
        $this->assertSame(['type' => ['string', 'null'], 'format' => 'uuid'], $approve['properties']['client_id']);
        // The decline reason's schema is the workflow's own rule.
        $this->assertSame(['type' => ['string', 'null'], 'maxLength' => 1000], $decline['properties']['reason']);
        $this->assertSame(['expected_version', 'confirm'], ServicePackageOperations::bodyFields('package_requests.erase'));

        // Visitor text has one place in the shape (API-3), and there is no invitation in it (ADM-5).
        $presented = ServicePackageOperations::schema('ServicePackageRequest');
        $this->assertSame(['id', 'status', 'package', 'submitted_at', 'decided_at', 'decline_reason', 'client', 'suggested_client', 'erased_at', 'untrusted', 'version'], $presented['required']);
        $this->assertSame(['company_name', 'contact_name', 'email', 'phone', 'answers'], $presented['properties']['untrusted']['oneOf'][0]['required']);
        $this->assertSame(['id', 'status', 'client', 'project_id', 'agreement_id'], ServicePackageOperations::schema('ServicePackageEnrollmentSummary')['required']);
        $this->assertSame(['data', 'enrollment'], ServicePackageOperations::schema('ServicePackageRequestApprovalResponse')['required']);
        foreach (['ServicePackageRequest', 'ServicePackageEnrollmentSummary', 'ServicePackageRequestApprovalResponse'] as $component) {
            $this->assertStringNotContainsString('invitation', strtolower(json_encode(ServicePackageOperations::schema($component), JSON_THROW_ON_ERROR)), $component);
        }
    }

    /** The request schemas are the workflow's own rules, so the contract accepts exactly what it validates. */
    public function test_the_request_schemas_are_generated_from_the_package_rules(): void
    {
        $create = ServicePackageOperations::schema('ServicePackageCreateRequest');
        $update = ServicePackageOperations::schema('ServicePackageUpdateRequest');
        $confirm = ServicePackageOperations::schema('ServicePackageConfirmRequest');
        $fields = [...array_keys(ServicePackageRules::package(creating: true)), ...ServicePackageRules::termKeys()];

        $this->assertSame($fields, array_keys($create['properties']));
        $this->assertSame(['expected_version', ...$fields], array_keys($update['properties']));
        $this->assertFalse($create['additionalProperties']);
        // Everything else has a default, or is the draft's when updating.
        $this->assertSame(['link_name', 'name'], $create['required']);
        $this->assertSame(['expected_version'], $update['required']);
        $this->assertSame(['expected_version', 'confirm'], $confirm['required']);
        $this->assertSame(['type' => 'boolean', 'const' => true], $confirm['properties']['confirm']);
        $this->assertSame(['expected_version', 'confirm'], ServicePackageOperations::bodyFields('packages.publish'));
        $this->assertSame(ServicePackageOperations::bodyFields('packages.publish'), ServicePackageOperations::bodyFields('packages.retire'));

        $properties = $create['properties'];
        $this->assertSame(['type' => 'string', 'pattern' => '^[a-z0-9]+(?:-[a-z0-9]+)*$', 'minLength' => 2, 'maxLength' => 64], $properties['link_name']);
        $this->assertSame(['type' => 'string', 'maxLength' => 200, 'minLength' => 1], $properties['name']);
        $this->assertSame(['type' => ['string', 'null'], 'maxLength' => 300], $properties['summary']);
        $this->assertSame(['type' => 'string', 'pattern' => '^[A-Z]{3}$', 'minLength' => 3, 'maxLength' => 3], $properties['currency']);
        $this->assertSame(['enum' => ['monthly', 'quarterly', 'semi_annual', 'annual', 'one_time'], 'type' => 'string'], $properties['billing_cadence']);
        $this->assertSame(['type' => ['integer', 'null'], 'minimum' => 0, 'maximum' => 120], $properties['rollover_months']);
        $this->assertSame(['type' => 'integer', 'minimum' => 1, 'maximum' => 90], $properties['payment_lapse_days']);
        $this->assertSame(['type' => 'boolean'], $properties['require_autopay']);
        $this->assertSame(['enum' => ['automatic', 'draft'], 'type' => 'string'], $properties['invoice_issuing']);

        $component = $properties['components'];
        $this->assertSame('array', $component['type']);
        $this->assertSame(ServicePackageRules::MAX_COMPONENTS, $component['maxItems']);
        $this->assertSame(['kind', 'description', 'amount'], $component['items']['required']);
        $this->assertFalse($component['items']['additionalProperties']);
        $this->assertSame(['enum' => ['monthly', 'quarterly', 'semi_annual', 'annual', 'one_time', null], 'type' => ['string', 'null']], $component['items']['properties']['cadence']);
        $this->assertSame(['type' => ['number', 'null'], 'exclusiveMinimum' => 0, 'maximum' => 999999], $component['items']['properties']['quantity']);
        // A question's key is derived from its label when it is left out.
        $questions = $properties['intake_questions'];
        $this->assertSame(['label', 'kind'], $questions['items']['required']);
        $this->assertSame(['type' => 'string', 'pattern' => '^[a-z0-9_]+$', 'maxLength' => 40, 'minLength' => 1], $questions['items']['properties']['key']);
    }

    public function test_the_response_names_every_refusal_code_and_no_secret(): void
    {
        $package = ServicePackageOperations::schema('ServicePackage');
        $this->assertSame(
            array_map(static fn (PackageRefusal $refusal): string => $refusal->value, PackageRefusal::cases()),
            $package['properties']['publish_refusals']['items']['properties']['code']['enum'],
        );
        $this->assertSame(['id', 'link_name', 'status', 'is_listed', 'portal_addable', 'public_url', 'published', 'draft', 'publish_refusals', 'version', 'created_at', 'updated_at'], $package['required']);
        $this->assertSame(['data', 'meta'], ServicePackageOperations::schema('ServicePackageListResponse')['required']);
        $this->assertSame(ServicePackageOperations::MAX_LIMIT, ServicePackageOperations::schema('ServicePackageListResponse')['properties']['data']['maxItems']);
        $checked = self::checkedDocument()['components']['schemas'];
        foreach (ServicePackageOperations::components() as $component) {
            $this->assertSame(ServicePackageOperations::schema($component), $checked[$component] ?? null, "{$component}: run php scripts/generate-package-contract.php");
        }
    }

    /** @return array<string, mixed> */
    private static function checkedDocument(): array
    {
        return json_decode((string) file_get_contents(dirname(__DIR__, 3).'/public/openapi/svc-agent-v1.json'), true, flags: JSON_THROW_ON_ERROR);
    }
}
