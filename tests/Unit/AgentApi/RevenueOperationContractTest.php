<?php

namespace Tests\Unit\AgentApi;

use App\Services\AgentApi\Operations\AgentDeploymentFlags;
use App\Services\AgentApi\Operations\AgentOperationCatalog;
use App\Services\AgentApi\Operations\AgentRestDocumentation;
use App\Services\AgentApi\Operations\RevenueOperations;
use App\Services\Mcp\AgentMcpRevenueTools;
use App\Support\AgentApi\AgentApiResponseSchemaCatalog;
use Bherila\McpLaravelBridge\Capabilities\Effect;
use Bherila\McpLaravelBridge\Capabilities\SchemaRef;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\TestCase;

/**
 * The revenue planning reads' declarations: one manager-only REST read each,
 * reached by the MCP tool of the same name under `revenue:read`, with exactly
 * the prose, parameters and refusals the checked OpenAPI document publishes,
 * without booting the application.
 */
final class RevenueOperationContractTest extends TestCase
{
    /** @var array<string, array{string, string, string, string, list<string>}> operation => [path segment, handler, title, response, query parameters] */
    private const array READS = [
        'revenue.summary' => ['summary', 'summary', 'Read revenue key figures', 'RevenueSummaryResponse', ['basis', 'include_expenses', 'include_projected_overage', 'client_id']],
        'revenue.monthly' => ['monthly', 'monthly', 'Read monthly revenue by client', 'RevenueMonthlyResponse', ['basis', 'include_expenses', 'include_projected_overage', 'band', 'client_id']],
        'revenue.projection' => ['projection', 'projection', 'Read the per-agreement revenue projection', 'RevenueProjectionResponse', ['include_projected_overage', 'client_id']],
        'revenue.clients' => ['clients', 'clients', 'Read the client revenue roster', 'RevenueClientsResponse', ['basis', 'include_expenses', 'client_id']],
    ];

    private const array PARAMETERS = [
        'basis' => ['name' => 'basis', 'in' => 'query', 'description' => 'Which money past months count.', 'schema' => ['type' => 'string', 'enum' => ['invoiced', 'collected'], 'default' => 'invoiced']],
        'include_expenses' => ['name' => 'include_expenses', 'in' => 'query', 'description' => 'Count pass-through expenses.', 'schema' => ['type' => 'boolean', 'default' => true]],
        'include_projected_overage' => ['name' => 'include_projected_overage', 'in' => 'query', 'description' => 'Add the projected overage band.', 'schema' => ['type' => 'boolean', 'default' => false]],
        'band' => ['name' => 'band', 'in' => 'query', 'description' => 'Narrow each row to one band.', 'schema' => ['type' => 'string', 'enum' => ['all', 'recurring', 'overage_and_other', 'expenses'], 'default' => 'all']],
        'client_id' => ['name' => 'client_id', 'in' => 'query', 'description' => 'One client of the workspace.', 'schema' => ['type' => 'string', 'format' => 'uuid']],
    ];

    private const array ERRORS = [
        401 => 'Not signed in, or the token is missing, expired or revoked.',
        403 => 'The token lacks revenue:read.',
        404 => 'The workspace is not visible to the caller, the caller is not a workspace owner or admin, or client_id names no client of this workspace.',
        422 => 'A query parameter is invalid (errors names it).',
        429 => 'Too many requests (retry after Retry-After).',
    ];

    public function test_the_definitions_are_pinned(): void
    {
        $this->assertSame(array_keys(self::READS), array_keys(RevenueOperations::DEFINITIONS));
        foreach (self::READS as $id => [$segment, $handler, $title, $response, $query]) {
            $this->assertSame([$handler, $title, $response, $query], RevenueOperations::DEFINITIONS[$id], $id);
            $this->assertSame(['revenue.'.$segment, '/workspaces/{workspace}/revenue/'.$segment, [200]], RevenueOperations::ROUTES[$id], $id);
        }
    }

    public function test_the_documentation_is_exactly_what_the_checked_document_publishes(): void
    {
        $document = json_decode((string) file_get_contents(dirname(__DIR__, 3).'/public/openapi/svc-agent-v1.json'), true, flags: JSON_THROW_ON_ERROR);
        $documentation = RevenueOperations::documentation();
        $this->assertSame(array_keys(self::READS), array_keys($documentation));

        foreach (self::READS as $id => [$segment, , $title, $response, $query]) {
            $documented = $documentation[$id];
            $published = $document['paths']['/workspaces/{workspace}/revenue/'.$segment]['get'];
            $this->assertSame(['method', 'scopes', 'summary', 'description', 'parameters', 'response', 'response_description', 'errors'], array_keys($documented), $id);
            $this->assertSame('GET', $documented['method'], $id);
            $this->assertSame(['revenue:read'], $documented['scopes'], $id);
            $this->assertSame([['oauth2' => ['revenue:read']], ['apiToken' => []]], $published['security'], $id);
            $this->assertSame($title, $documented['summary'], $id);
            $this->assertSame($title, $documented['response_description'], $id);
            $this->assertSame($title, $published['responses'][200]['description'], $id);
            $this->assertSame($response, $documented['response'], $id);
            $this->assertSame($published['description'], $documented['description'], $id);
            $this->assertSame(self::ERRORS, $documented['errors'], $id);
            $expected = ['WorkspaceId', ...array_map(static fn (string $name): array => self::PARAMETERS[$name], $query)];
            $this->assertSame($expected, $documented['parameters'], $id);
            $this->assertSame([['$ref' => '#/components/parameters/WorkspaceId'], ...array_slice($expected, 1)], $published['parameters'], $id);
            // The shared rules, with the method's constants spelled out.
            $this->assertStringContainsString('trailing 6-month average of billed overage amount, zero below 3 months of history.', $documented['description'], $id);
            $this->assertStringStartsNotWith('Workspace owners/admins only', $documented['description'], $id);
            $this->assertStringEndsWith('Computed in the request; nothing is stored.', $documented['description'], $id);
        }
        $this->assertStringStartsWith('The client roster: per client its MRR', $documentation['revenue.clients']['description']);
        $this->assertStringContainsString('whether it ends within 60 days. Totals for the roster are included. client_id narrows to one client. Workspace owners/admins only;', $documentation['revenue.clients']['description']);
    }

    public function test_each_read_is_a_manager_only_rest_read_its_mcp_tool_reaches(): void
    {
        $previous = Container::getInstance();
        new Application(dirname(__DIR__, 3));
        AgentApiResponseSchemaCatalog::flush();
        try {
            $catalog = new AgentOperationCatalog;
            foreach (self::READS as $id => [$segment, $handler, $title]) {
                $operation = $catalog->registry()->find($id);
                $this->assertNotNull($operation, $id);
                $this->assertSame($title, $operation->title, $id);
                $this->assertSame(Effect::Read, $operation->effect, $id);
                $this->assertTrue($operation->isIdempotent(), $id);
                $this->assertTrue($catalog->isManagerOnly($id), $id);
                $this->assertSame(['revenue:read'], $operation->requirement->scopes, $id);
                $this->assertSame([AgentDeploymentFlags::MCP, AgentDeploymentFlags::MCP_SWITCH.'mcp.read.revenue', AgentDeploymentFlags::MCP_SWITCH.$id], $operation->requirement->flags, $id);
                $this->assertSame(['GET', '/workspaces/{workspace}/revenue/'.$segment, 'agent-api.v1.revenue.'.$segment, ['workspace'], [200]],
                    [$operation->rest?->method, $operation->rest?->path, $operation->rest?->routeName, $operation->rest?->pathParameters, $operation->rest?->successStatuses], $id);
                $this->assertSame([AgentMcpRevenueTools::class, $handler], $operation->mcp?->handler, $id);
                $this->assertEquals(SchemaRef::responseOf($id), $operation->output, $id);
                $this->assertSame(AgentRestDocumentation::operations()[$id]['description'], $operation->description, $id);
                $this->assertSame(['x-svc-flags' => [], 'x-mcp-tool' => $id], $operation->extensions, $id);
            }
        } finally {
            AgentApiResponseSchemaCatalog::flush();
            Container::setInstance($previous);
        }
    }
}
