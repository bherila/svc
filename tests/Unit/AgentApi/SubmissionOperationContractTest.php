<?php

namespace Tests\Unit\AgentApi;

use App\Services\AgentApi\Operations\AgentDeploymentFlags;
use App\Services\AgentApi\Operations\AgentOperationCatalog;
use App\Services\AgentApi\Operations\SubmissionOperations;
use App\Services\Mcp\AgentMcpSubmissionTools;
use App\Support\AgentApi\AgentApiResponseSchemaCatalog;
use Bherila\McpLaravelBridge\Capabilities\Effect;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * The submission operations' declarations, pinned against the checked OpenAPI
 * document and the registry: two reads and two time-entry-cutover writes, each
 * reached by an MCP tool of the same name.
 */
final class SubmissionOperationContractTest extends TestCase
{
    /** @var array<string, array{string, string, string, int, string, string|null, bool, list<string>}> */
    private const array OPERATIONS = [
        'submissions.list' => ['GET', '/workspaces/{workspace}/submissions', 'submissions.index', 200, 'SubmissionListResponse', null, false, ['time:read']],
        'submissions.submit' => ['POST', '/workspaces/{workspace}/submissions', 'submissions.submit', 200, 'SubmissionResultResponse', 'SubmissionRequest', true, ['time:write']],
        'submissions.withdraw' => ['POST', '/workspaces/{workspace}/submissions/withdraw', 'submissions.withdraw', 200, 'SubmissionResultResponse', 'SubmissionRequest', true, ['time:write']],
        'timesheets.approved' => ['GET', '/workspaces/{workspace}/timesheets/approved', 'timesheets.approved', 200, 'ApprovedTimesheetResponse', null, false, ['time:read']],
    ];

    /** @return array<string, mixed> */
    private function document(): array
    {
        return json_decode((string) file_get_contents(dirname(__DIR__, 3).'/public/openapi/svc-agent-v1.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_the_routes_and_definitions_are_pinned(): void
    {
        $this->assertSame(array_keys(self::OPERATIONS), array_keys(SubmissionOperations::ROUTES));
        $this->assertSame(array_keys(self::OPERATIONS), array_keys(SubmissionOperations::DEFINITIONS));
        foreach (self::OPERATIONS as $id => [, $path, $route, $status, $response, $request]) {
            $this->assertSame([$route, $path, [$status]], SubmissionOperations::ROUTES[$id], $id);
            $this->assertSame($response, SubmissionOperations::DEFINITIONS[$id][2], $id);
            $this->assertSame($request, SubmissionOperations::DEFINITIONS[$id][3], $id);
        }
        $this->assertSame(['SubmissionListResponse', 'SubmissionResultResponse', 'SubmissionRequest', 'ApprovedTimesheetResponse'], SubmissionOperations::components());
    }

    public function test_every_component_is_exactly_what_the_checked_document_publishes(): void
    {
        $schemas = $this->document()['components']['schemas'];
        foreach (SubmissionOperations::components() as $component) {
            $this->assertSame($schemas[$component], SubmissionOperations::schema($component), $component);
        }
        $this->expectException(InvalidArgumentException::class);
        SubmissionOperations::schema('SubmissionNothing');
    }

    public function test_the_documentation_is_what_the_checked_document_publishes(): void
    {
        $document = $this->document();
        $documentation = SubmissionOperations::documentation();
        $this->assertSame(array_keys(self::OPERATIONS), array_keys($documentation));

        foreach (self::OPERATIONS as $id => [$method, $path, , $status, $response, $request, $write, $scopes]) {
            $documented = $documentation[$id];
            $published = $document['paths'][$path][strtolower($method)];
            $this->assertSame($method, $documented['method'], $id);
            $this->assertSame($scopes, $documented['scopes'], $id);
            $this->assertSame([['oauth2' => $scopes], ['apiToken' => []]], $published['security'], $id);
            $this->assertSame(SubmissionOperations::DEFINITIONS[$id][1], $documented['summary'], $id);
            $this->assertSame($documented['description'], $published['description'], $id);
            $this->assertSame('#/components/schemas/'.$response, $published['responses'][$status]['content']['application/json']['schema']['$ref'], $id);
            $this->assertSame($request, $documented['request'] ?? null, $id);
            $names = array_map(static fn (string|array $parameter): string => is_string($parameter) ? $parameter : $parameter['name'], $documented['parameters']);
            $this->assertSame($write ? ['WorkspaceId', 'IdempotencyKey'] : ['WorkspaceId', 'period', 'project_id'], $names, $id);
            $codes = array_keys($documented['errors']);
            $this->assertSame($write ? [401, 403, 404, 422, 429, 409] : [401, 403, 404, 422, 429], $codes, $id);
            foreach ($codes as $code) {
                $this->assertSame($documented['errors'][$code], $published['responses'][$code]['description'], "$id $code");
            }
            $this->assertStringContainsString('Nothing about money crosses to a reviewer', $documented['description'], $id);
            $this->assertSame($write && str_starts_with($id, 'submissions.'), str_contains($documented['description'], 'AGENT_API_TIME_ENTRY_WRITES_ENABLED'), $id);
        }
    }

    public function test_each_operation_is_a_rest_operation_its_mcp_tool_reaches(): void
    {
        $previous = Container::getInstance();
        new Application(dirname(__DIR__, 3));
        AgentApiResponseSchemaCatalog::flush();
        try {
            $catalog = new AgentOperationCatalog;
            foreach (self::OPERATIONS as $id => [$method, $path, $route, $status, , , $write, $scopes]) {
                $operation = $catalog->registry()->find($id);
                $this->assertNotNull($operation, $id);
                $this->assertSame($write ? Effect::LocalWrite : Effect::Read, $operation->effect, $id);
                $this->assertTrue($operation->isIdempotent(), $id);
                $this->assertFalse($catalog->isManagerOnly($id), $id);
                $this->assertSame($scopes, $operation->requirement->scopes, $id);
                $this->assertSame(
                    [...($write ? [AgentDeploymentFlags::TIME_ENTRIES] : []), AgentDeploymentFlags::MCP, AgentDeploymentFlags::MCP_SWITCH.($write ? 'mcp.write' : 'mcp.read'), AgentDeploymentFlags::MCP_SWITCH.$id],
                    $operation->requirement->flags,
                    $id,
                );
                $this->assertSame([$method, $path, 'agent-api.v1.'.$route, [$status]],
                    [$operation->rest?->method, $operation->rest?->path, $operation->rest?->routeName, $operation->rest?->successStatuses], $id);
                $this->assertSame([AgentMcpSubmissionTools::class, SubmissionOperations::DEFINITIONS[$id][0]], $operation->mcp?->handler, $id);
                $this->assertSame($write, $operation->input !== null, $id);
            }
        } finally {
            AgentApiResponseSchemaCatalog::flush();
            Container::setInstance($previous);
        }
    }
}
