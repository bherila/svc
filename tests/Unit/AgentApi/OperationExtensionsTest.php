<?php

namespace Tests\Unit\AgentApi;

use App\Services\AgentApi\Operations\AgentDeploymentFlags;
use App\Services\AgentApi\Operations\AgentOperationCatalog;
use App\Support\AgentApi\AgentApiResponseSchemaCatalog;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\TestCase;

/**
 * `x-svc-flags` and `x-mcp-tool` are generated from each operation's
 * declaration (#459 item 6), so the document cannot name a switch the
 * operation does not depend on, or a tool that does not reach it.
 */
final class OperationExtensionsTest extends TestCase
{
    public function test_a_cutover_is_listed_after_every_cutover_it_nests_inside(): void
    {
        $this->assertSame([], AgentDeploymentFlags::environmentSwitches([]));
        $this->assertSame(['AGENT_API_WRITES_ENABLED'], AgentDeploymentFlags::environmentSwitches([AgentDeploymentFlags::WRITES]));
        $this->assertSame(['AGENT_API_WRITES_ENABLED', 'AGENT_API_INVOICE_WRITES_ENABLED'], AgentDeploymentFlags::environmentSwitches([AgentDeploymentFlags::INVOICES]));
        // Time-entry writes have their own emergency cutoff, outside the workflow cutover.
        $this->assertSame(['AGENT_API_TIME_ENTRY_WRITES_ENABLED'], AgentDeploymentFlags::environmentSwitches([AgentDeploymentFlags::TIME_ENTRIES]));
        $this->assertSame(['AGENT_API_WRITES_ENABLED', 'AGENT_API_TIME_ENTRY_WRITES_ENABLED'], AgentDeploymentFlags::environmentSwitches([AgentDeploymentFlags::WRITES, AgentDeploymentFlags::TIME_ENTRIES]));
        $this->assertSame(['SVC_PORTAL_INVITATIONS_ENABLED'], AgentDeploymentFlags::environmentSwitches([AgentDeploymentFlags::PORTAL_INVITATIONS]));
        $this->assertSame(
            ['AGENT_API_WRITES_ENABLED', 'AGENT_API_CLIENT_WRITES_ENABLED', 'SVC_PORTAL_INVITATIONS_ENABLED'],
            AgentDeploymentFlags::environmentSwitches([AgentDeploymentFlags::PORTAL_INVITATION_WRITES]),
        );
        // A switch named twice is listed once, where it first applies.
        $this->assertSame(['AGENT_API_WRITES_ENABLED', 'AGENT_API_CLIENT_WRITES_ENABLED'], AgentDeploymentFlags::environmentSwitches([AgentDeploymentFlags::WRITES, AgentDeploymentFlags::CLIENTS, AgentDeploymentFlags::WRITES]));
        // Neither the MCP switches nor the Square precondition is an installation cutover.
        $this->assertSame(['AGENT_API_WRITES_ENABLED'], AgentDeploymentFlags::environmentSwitches([AgentDeploymentFlags::WRITES, AgentDeploymentFlags::SQUARE, AgentDeploymentFlags::MCP, AgentDeploymentFlags::MCP_SWITCH.'mcp.write']));
    }

    public function test_every_cutover_names_its_environment_variable(): void
    {
        foreach (AgentDeploymentFlags::cutovers() as $cutover) {
            $switches = AgentDeploymentFlags::environmentSwitches([$cutover]);
            $this->assertNotSame([], $switches, $cutover);
            $this->assertMatchesRegularExpression('/^(AGENT_API|SVC)_[A-Z_]+_ENABLED$/', $switches[array_key_last($switches)], $cutover);
        }
    }

    public function test_every_documented_operation_carries_its_generated_extensions(): void
    {
        $previous = Container::getInstance();
        new Application(dirname(__DIR__, 3));
        AgentApiResponseSchemaCatalog::flush();
        try {
            $operations = (new AgentOperationCatalog)->registry()->all();
            $documented = 0;
            foreach ($operations as $operation) {
                if ($operation->rest === null) {
                    $this->assertSame([], $operation->extensions, $operation->id);

                    continue;
                }
                $expected = ['x-svc-flags' => AgentDeploymentFlags::environmentSwitches($operation->requirement->flags)];
                if ($operation->mcpName() !== null) {
                    $expected['x-mcp-tool'] = $operation->mcpName();
                }
                $this->assertSame($expected, $operation->extensions, $operation->id);
                $documented++;
            }
            $this->assertGreaterThan(130, $documented);
        } finally {
            AgentApiResponseSchemaCatalog::flush();
            Container::setInstance($previous);
        }
    }
}
