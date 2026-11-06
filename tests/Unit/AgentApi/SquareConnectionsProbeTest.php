<?php

namespace Tests\Unit\AgentApi;

use App\Services\AgentApi\Operations\AgentDeploymentFlags;
use App\Services\Mcp\McpFeatureFlags;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;

/**
 * The Square deployment flag is the environment's answer first, then whatever
 * the application's connections probe says. Both are exercised on a bare
 * container: no application, no provider, no database.
 */
final class SquareConnectionsProbeTest extends TestCase
{
    public function test_the_probe_decides_the_flag_only_while_the_environment_has_no_square(): void
    {
        $this->onBareContainer(function (Container $container): void {
            $flags = AgentDeploymentFlags::forAgents(new McpFeatureFlags);

            // No probe is bound, as in a unit test: the environment's answer alone.
            $this->assertFalse($flags->enabled(AgentDeploymentFlags::SQUARE));

            $container->bind(AgentDeploymentFlags::SQUARE_CONNECTIONS_PROBE, static fn (): \Closure => static fn (): bool => true);
            $this->assertTrue($flags->enabled(AgentDeploymentFlags::SQUARE), 'A workspace connection turns Square on with the environment unconfigured.');

            $container->bind(AgentDeploymentFlags::SQUARE_CONNECTIONS_PROBE, static fn (): \Closure => static fn (): bool => false);
            $this->assertFalse($flags->enabled(AgentDeploymentFlags::SQUARE), 'No connection anywhere leaves it off.');
        });
    }

    public function test_an_environment_configured_for_square_needs_no_probe(): void
    {
        $this->onBareContainer(function (Container $container): void {
            $container->make('config')->set('services.square.access_token', 'fixture-only-read-token');
            $container->make('config')->set('services.square.workspace', 'fixture-workspace');
            $container->bind(AgentDeploymentFlags::SQUARE_CONNECTIONS_PROBE, static fn (): \Closure => static fn (): bool => false);

            $this->assertTrue(AgentDeploymentFlags::forAgents(new McpFeatureFlags)->enabled(AgentDeploymentFlags::SQUARE));
        });
    }

    private function onBareContainer(callable $assertions): void
    {
        $previous = Container::getInstance();
        $container = new Container;
        Container::setInstance($container);
        $container->instance('config', new Repository([
            'agent_api' => ['writes_enabled' => true],
            'services' => ['square' => ['access_token' => null, 'workspace' => null, 'version' => '2026-09-16']],
        ]));
        try {
            $assertions($container);
        } finally {
            Container::setInstance($previous);
        }
    }
}
