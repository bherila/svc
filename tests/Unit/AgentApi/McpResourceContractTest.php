<?php

namespace Tests\Unit\AgentApi;

use App\Services\AgentApi\Operations\AgentOperationCatalog;
use App\Support\AgentApi\AgentApiResponseSchemaCatalog;
use Bherila\McpLaravelBridge\Capabilities\SchemaRef;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\TestCase;

/**
 * The MCP resources that hand back a REST representation promise exactly
 * that representation (#459 item 7), without booting the application.
 */
final class McpResourceContractTest extends TestCase
{
    public function test_the_context_and_agreement_resources_promise_their_routes_representation(): void
    {
        $previous = Container::getInstance();
        new Application(dirname(__DIR__, 3));
        AgentApiResponseSchemaCatalog::flush();
        try {
            $catalog = new AgentOperationCatalog;
            $registry = $catalog->registry();
            foreach ([
                'resources.current_context' => [SchemaRef::responseOf('context.get'), ['identity:read'], false],
                'resources.agreement' => [SchemaRef::openApi('AgreementResponse'), ['billing:read'], true],
            ] as $id => [$output, $scopes, $manager]) {
                $operation = $registry->find($id);
                $this->assertNotNull($operation, $id);
                $this->assertNull($operation->rest, $id);
                $this->assertEquals($output, $operation->output, $id);
                $this->assertSame($scopes, $operation->requirement->scopes, $id);
                $this->assertSame($manager, $catalog->isManagerOnly($id), $id);
            }
            // The agreement resource is agreements.get's representation, by another name.
            $this->assertSame(AgentApiResponseSchemaCatalog::operationComponent('agreements.get'), 'AgreementResponse');
        } finally {
            AgentApiResponseSchemaCatalog::flush();
            Container::setInstance($previous);
        }
    }
}
