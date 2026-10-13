<?php

namespace Tests\Unit\AgentApi;

use App\Services\AgentApi\Operations\AgentDeploymentFlags;
use App\Services\AgentApi\Operations\AgentOperationCatalog;
use App\Services\Mcp\AgentMcpPortalInvitationTools;
use App\Support\AgentApi\AgentApiResponseSchemaCatalog;
use Bherila\McpLaravelBridge\Capabilities\Effect;
use Bherila\McpLaravelBridge\Capabilities\IdempotencyKey;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\TestCase;

/** The four portal invitation operations (#430), declared without booting the application. */
final class PortalInvitationOperationContractTest extends TestCase
{
    public function test_invitation_operations_declare_the_transport_authority_and_switch_contract(): void
    {
        $previous = Container::getInstance();
        new Application(dirname(__DIR__, 3));
        AgentApiResponseSchemaCatalog::flush();
        try {
            $catalog = new AgentOperationCatalog;
            $registry = $catalog->registry();
            $base = '/workspaces/{workspace}/clients/{client}/portal-invitations';
            foreach ([
                'list' => ['GET', 'index', Effect::Read, [200], $base, 'PortalInvitationListResponse'],
                'create' => ['POST', 'store', Effect::ExternalWrite, [201], $base, 'PortalInvitationResponse'],
                'resend' => ['POST', 'resend', Effect::ExternalWrite, [200], $base.'/{invitation}/resend', 'PortalInvitationResponse'],
                'revoke' => ['DELETE', 'destroy', Effect::Destructive, [200], $base.'/{invitation}', 'PortalInvitationResponse'],
            ] as $kind => [$method, $suffix, $effect, $statuses, $path, $response]) {
                $id = 'clients.portal_invitations.'.$kind;
                $operation = $registry->find($id);
                $this->assertNotNull($operation, $id);
                $this->assertSame($effect, $operation->effect, $id);
                $this->assertTrue($operation->idempotent, $id);
                $this->assertTrue($catalog->isManagerOnly($id), $id);
                $this->assertSame([AgentMcpPortalInvitationTools::class, $kind], $operation->mcp->handler);
                $this->assertSame($method, $operation->rest->method);
                $this->assertSame('agent-api.v1.clients.portal-invitations.'.$suffix, $operation->rest->routeName);
                $this->assertSame($path, $operation->rest->path);
                $this->assertContains('WorkspaceId', $operation->rest->parameters);
                $this->assertSame($statuses, $operation->rest->successStatuses);
                $this->assertSame($response, AgentApiResponseSchemaCatalog::operationComponent($id));
                $this->assertSame($kind === 'list' ? ['clients:read'] : ['clients:write'], $operation->requirement->scopes);
                $this->assertSame([
                    $kind === 'list' ? AgentDeploymentFlags::PORTAL_INVITATIONS : AgentDeploymentFlags::PORTAL_INVITATION_WRITES,
                    'mcp', 'mcp.switch:'.($kind === 'list' ? 'mcp.read.clients' : 'mcp.write.clients'), 'mcp.switch:'.$id,
                ], $operation->requirement->flags);
                $this->assertSame(['x-mcp-tool' => $id, 'x-svc-flags' => $kind === 'list'
                    ? ['SVC_PORTAL_INVITATIONS_ENABLED']
                    : ['AGENT_API_WRITES_ENABLED', 'AGENT_API_CLIENT_WRITES_ENABLED', 'SVC_PORTAL_INVITATIONS_ENABLED']], $operation->extensions);
                $this->assertSame($kind === 'list' ? IdempotencyKey::None : IdempotencyKey::HeaderAndArgument, $operation->safety->idempotencyKey);
                $this->assertFalse($operation->safety->expectedVersion, $id);

                $input = $operation->input;
                $this->assertIsArray($input);
                $this->assertSame(['type' => 'string', 'format' => 'uuid'], $input['properties']['client_id']);
                $this->assertContains('client_id', $input['required']);
                if ($kind === 'list') {
                    $this->assertSame(['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 25], $input['properties']['limit']);
                    $this->assertSame(['type' => ['string', 'null'], 'maxLength' => 2048], $input['properties']['cursor']);

                    continue;
                }
                $this->assertSame(['type' => 'string', 'minLength' => 1, 'maxLength' => 255], $input['properties']['idempotency_key']);
                $this->assertContains('idempotency_key', $input['required']);
                $this->assertContains('confirm', $input['required']);
                if ($kind === 'create') {
                    $this->assertSame(['confirm', 'scope', 'email'], array_values(array_intersect($input['required'], ['confirm', 'scope', 'email'])));
                    $this->assertArrayNotHasKey('invitation_id', $input['properties']);
                } else {
                    $this->assertSame(['type' => 'string', 'format' => 'uuid'], $input['properties']['invitation_id']);
                    $this->assertContains('invitation_id', $input['required']);
                }
            }

            // The agent shape has nowhere to put the acceptance link.
            $this->assertArrayNotHasKey('link', AgentApiResponseSchemaCatalog::schema('PortalInvitation')['properties']);
            $this->assertFalse(AgentApiResponseSchemaCatalog::schema('PortalInvitation')['additionalProperties']);
        } finally {
            AgentApiResponseSchemaCatalog::flush();
            Container::setInstance($previous);
        }
    }
}
