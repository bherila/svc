<?php

namespace Tests\Unit\AgentApi;

use App\Services\AgentApi\Operations\AgentOperationCatalog;
use App\Services\AgentApi\Operations\ServicePackageOperations;
use App\Support\AgentApi\AgentApiResponseSchemaCatalog;
use Bherila\McpLaravelBridge\Capabilities\Effect;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The MCP effect of every service package tool, from what the operation does
 * and not from the catalog's own match: an effect a client reads to decide
 * whether to ask before calling.
 */
#[CoversClass(AgentOperationCatalog::class)]
final class PackageOperationEffectTest extends TestCase
{
    /**
     * Reads change nothing; a write that only changes this workspace's rows is
     * a local write; one that emails or invites someone is an external write;
     * erasing contact details, or retiring a package for good, is destructive.
     */
    private const array EFFECTS = [
        'packages.list' => Effect::Read,
        'packages.get' => Effect::Read,
        'packages.create' => Effect::LocalWrite,
        'packages.update' => Effect::LocalWrite,
        // Publishing flips a draft to published and emails no one.
        'packages.publish' => Effect::LocalWrite,
        // A retired package never returns to sale (PKG-6; there is no un-retire).
        'packages.retire' => Effect::Destructive,
        'package_requests.list' => Effect::Read,
        'package_requests.get' => Effect::Read,
        // Invites through the identity provider or emails the contact.
        'package_requests.approve' => Effect::ExternalWrite,
        // Emails the visitor.
        'package_requests.decline' => Effect::ExternalWrite,
        // Erasing a request's contact details cannot be undone.
        'package_requests.erase' => Effect::Destructive,
        'enrollments.list' => Effect::Read,
        'enrollments.get' => Effect::Read,
        // Activation emails the client.
        'enrollments.activate' => Effect::ExternalWrite,
        'client_services.list' => Effect::Read,
        'client_services.get' => Effect::Read,
        'cancellation_requests.list' => Effect::Read,
        'cancellation_requests.get' => Effect::Read,
        // A cancellation decision, a scheduled end and its undo each email the client.
        'cancellation_requests.approve' => Effect::ExternalWrite,
        'cancellation_requests.decline' => Effect::ExternalWrite,
        'agreements.schedule_end' => Effect::ExternalWrite,
        'agreements.undo_scheduled_end' => Effect::ExternalWrite,
        'package_migrations.preview' => Effect::Read,
        // Scheduling or cancelling a move emails each client.
        'package_migrations.schedule' => Effect::ExternalWrite,
        'package_migrations.list' => Effect::Read,
        'package_migrations.get' => Effect::Read,
        'package_migrations.cancel' => Effect::ExternalWrite,
    ];

    /** @return array<string, array{string, Effect}> */
    public static function operations(): array
    {
        $cases = [];
        foreach (self::EFFECTS as $id => $effect) {
            $cases[$id] = [$id, $effect];
        }

        return $cases;
    }

    public function test_every_package_operation_has_a_decided_effect(): void
    {
        $this->assertEqualsCanonicalizing(array_keys(ServicePackageOperations::DEFINITIONS), array_keys(self::EFFECTS));
    }

    #[DataProvider('operations')]
    public function test_a_package_tool_carries_the_effect_of_what_it_does(string $id, Effect $effect): void
    {
        $previous = Container::getInstance();
        $app = new Application(dirname(__DIR__, 3));
        $app->instance('config', new Repository(['svc' => ['service_packages' => ['enabled' => true]]]));
        AgentApiResponseSchemaCatalog::flush();
        try {
            $operation = (new AgentOperationCatalog)->registry()->find($id);

            $this->assertNotNull($operation, $id);
            $this->assertSame($effect, $operation->effect, $id);
        } finally {
            AgentApiResponseSchemaCatalog::flush();
            Container::setInstance($previous);
        }
    }
}
