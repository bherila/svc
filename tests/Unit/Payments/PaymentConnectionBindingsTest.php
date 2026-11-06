<?php

namespace Tests\Unit\Payments;

use App\Services\AgentApi\Operations\AgentDeploymentFlags;
use App\Services\Payments\WorkspacePaymentConnections;
use Closure;
use Tests\TestCase;

/**
 * How the application wires payment connections. Nothing here touches the
 * database: the services are only resolved, and the probe only inspected.
 */
final class PaymentConnectionBindingsTest extends TestCase
{
    public function test_connections_are_read_once_per_scope_and_fresh_in_the_next(): void
    {
        $first = $this->app->make(WorkspacePaymentConnections::class);

        $this->assertSame($first, $this->app->make(WorkspacePaymentConnections::class), 'One instance within a request.');

        $this->app->forgetScopedInstances();

        $this->assertNotSame($first, $this->app->make(WorkspacePaymentConnections::class), 'A new request reads afresh.');
    }

    public function test_the_square_connections_probe_is_bound_for_the_flags_to_ask(): void
    {
        $this->assertTrue($this->app->bound(AgentDeploymentFlags::SQUARE_CONNECTIONS_PROBE));
        $this->assertInstanceOf(Closure::class, $this->app->make(AgentDeploymentFlags::SQUARE_CONNECTIONS_PROBE));
    }
}
