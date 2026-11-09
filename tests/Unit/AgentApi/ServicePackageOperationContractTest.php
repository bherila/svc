<?php

namespace Tests\Unit\AgentApi;

use App\Services\AgentApi\Operations\AgentDeploymentFlags;
use App\Services\AgentApi\Operations\AgentOperationCatalog;
use App\Services\AgentApi\Operations\ServicePackageOperations;
use App\Services\Mcp\AgentMcpPackageTools;
use App\Support\AgentApi\AgentApiResponseSchemaCatalog;
use App\Support\Packages\PackageRefusal;
use App\Support\Packages\ServicePackageRules;
use Bherila\McpLaravelBridge\Capabilities\Effect;
use Bherila\McpLaravelBridge\Capabilities\IdempotencyKey;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\TestCase;
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

    /**
     * Enrollments are the owners' and admins'; a client's own services are
     * the one family a portal contact is offered (API-5). Accepting and adding
     * are not agent operations at all.
     */
    #[Requirement('API-1', 'API-2', 'API-5')]
    public function test_each_enrollment_and_client_service_operation_is_declared_once(): void
    {
        $catalog = new AgentOperationCatalog;
        $contract = $catalog->contract();
        foreach ([
            'enrollments.list' => ['GET', 'enrollments.index', Effect::Read, '/workspaces/{workspace}/enrollments', true],
            'enrollments.get' => ['GET', 'enrollments.show', Effect::Read, '/workspaces/{workspace}/enrollments/{enrollment}', true],
            'enrollments.activate' => ['POST', 'enrollments.activate', Effect::ExternalWrite, '/workspaces/{workspace}/enrollments/{enrollment}/activate', true],
            'client_services.list' => ['GET', 'client-services.index', Effect::Read, '/workspaces/{workspace}/client-services', false],
            'client_services.get' => ['GET', 'client-services.show', Effect::Read, '/workspaces/{workspace}/client-services/{service}', false],
        ] as $id => [$method, $route, $effect, $path, $manager]) {
            $this->assertNull($catalog->registry()->find($id), "{$id} exists only while service packages are on");
            $operation = $contract->find($id);
            $this->assertNotNull($operation, $id);
            $write = $method !== 'GET';
            $this->assertSame($effect, $operation->effect, $id);
            $this->assertSame($manager, $catalog->isManagerOnly($id), $id);
            $this->assertSame([AgentMcpPackageTools::class, ServicePackageOperations::DEFINITIONS[$id][0]], $operation->mcp?->handler, $id);
            $this->assertSame('agent-api.v1.'.$route, $operation->rest?->routeName, $id);
            $this->assertSame($path, $operation->rest->path, $id);
            $this->assertSame($write ? ['packages:write', 'packages:read'] : ['packages:read'], $operation->requirement->scopes, $id);
            $this->assertSame($write, $operation->safety->expectedVersion, $id);
            $this->assertSame($write, $operation->safety->confirm, $id);
        }
        $this->assertSame(['client_services.list', 'client_services.get'], ServicePackageOperations::CLIENT_OPERATIONS);
        foreach (['enrollments.accept', 'client_services.add', 'client_services.accept'] as $absent) {
            $this->assertNull($contract->find($absent), $absent.' is the portal\'s, not an agent operation');
        }
        // What a client reads carries none of the operator's settings.
        $terms = array_keys(ServicePackageOperations::schema('ClientServiceTerms')['properties']);
        $this->assertSame([], array_values(array_intersect($terms, ['invoice_issuing', 'payment_lapse_days', 'require_autopay', 'intake_questions'])));
        $this->assertSame(['data', 'addable_packages', 'meta'], ServicePackageOperations::schema('ClientServiceListResponse')['required']);
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
        $checked = json_decode((string) file_get_contents(dirname(__DIR__, 3).'/public/openapi/svc-agent-v1.json'), true, flags: JSON_THROW_ON_ERROR)['components']['schemas'];
        foreach (ServicePackageOperations::components() as $component) {
            $this->assertSame(ServicePackageOperations::schema($component), $checked[$component] ?? null, "{$component}: run php scripts/generate-package-contract.php");
        }
    }
}
