<?php

namespace Tests\Feature\Tenancy;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientProject;
use App\Models\ClientTask;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Every route that reaches a client's record by id refuses a member who
 * cannot reach that client.
 *
 * This exists because the same defect shipped twice. #157 narrowed the client
 * directory to the projects a member holds, and both times the *list* was
 * scoped while something that reads one record by id was not: the company
 * switcher published every client's name on every screen, and the invoice
 * routes served any client's invoice - and its PDF - to anyone who passed the
 * workspace gate. Both were found by review rather than by CI.
 *
 * A citation-style registry would not have caught either, because the failure
 * is a route nobody thought about. So this enumerates the routes from the
 * router at runtime and drives each one: a new route binding a client-owned
 * record is covered the moment it is declared, and there is nothing to
 * remember to register.
 *
 * The viewer is a workspace member with no project membership at all. Under
 * #157 they reach no client, so every route here must refuse them - which
 * makes one assertion true of the whole surface rather than a rule restated
 * per screen.
 */
final class TenantRouteReachabilityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Routes that legitimately answer a member who reaches no client.
     *
     * An entry here is a claim that a screen may answer someone with no
     * relationship to that client, and it should have to be argued for in
     * review rather than added quietly.
     *
     * `clients.manage` is the one such claim. It is not a screen: it is the
     * compatibility redirect left behind when the Manage tab became Client
     * settings, and it rewrites the URL without reading anything. The reader
     * arrives at `clients.settings`, which is in this sweep and refuses them.
     * A redirect that discloses nothing but the shape of a URL the caller
     * already typed is not a disclosure.
     *
     * @var list<string>
     */
    private const EXEMPT = ['clients.manage'];

    public function test_every_client_scoped_get_route_refuses_an_unreachable_viewer(): void
    {
        [$viewer, $bindings] = $this->workspaceWithAnUnreachableClient();

        $served = [];

        foreach ($this->clientScopedGetRoutes() as $name => $uri) {
            if (in_array($name, self::EXEMPT, true)) {
                continue;
            }

            $path = $this->substitute($uri, $bindings);
            $status = $this->actingAs($viewer)->get($path)->getStatusCode();

            if ($status < 400) {
                $served[] = sprintf('%s (%s) answered %d', $name, $path, $status);
            }
        }

        $this->assertSame([], $served, sprintf(
            "These routes served a client's record to a member who reaches no project of that client:\n\n%s\n\n".
            'Apply the same reachability rule the list applies - `ProjectAccess::reachableCompanyIds()` - '.
            'or add the route to EXEMPT with an argument for why this disclosure is intended.',
            implode("\n", $served),
        ));
    }

    /**
     * The same question for the routes that write.
     *
     * Weaker by necessity: a write reached with no payload can be refused by
     * validation before authorization is consulted, so a 422 here does not
     * prove the check ran. What it does prove is that nothing was written -
     * which is the half that matters for a member with no relationship to the
     * client - so a 2xx or a redirect is the failure, and anything else is not.
     *
     * Kept separate from the GET sweep so the weaker guarantee is not mistaken
     * for the stronger one.
     */
    public function test_no_client_scoped_write_route_succeeds_for_an_unreachable_viewer(): void
    {
        [$viewer, $bindings] = $this->workspaceWithAnUnreachableClient();

        $succeeded = [];

        foreach ($this->clientScopedRoutes(['POST', 'PUT', 'PATCH', 'DELETE']) as $name => $route) {
            if (in_array($name, self::EXEMPT, true)) {
                continue;
            }

            [$method, $uri] = $route;
            $path = $this->substitute($uri, $bindings);

            $status = $this->actingAs($viewer)
                ->call($method, $path, [], [], [], ['HTTP_ACCEPT' => 'application/json'])
                ->getStatusCode();

            if ($status < 400 || $status === 302) {
                $succeeded[] = sprintf('%s %s (%s) answered %d', $method, $name, $path, $status);
            }
        }

        $this->assertSame([], $succeeded, sprintf(
            "These write routes did not refuse a member who reaches no project of that client:\n\n%s\n\n".
            'A write reaching a client nobody has given this member is the same defect as a read of one.',
            implode("\n", $succeeded),
        ));
    }

    /**
     * A workspace, a client with every kind of record, and a member of that
     * workspace who holds no project membership.
     *
     * Under #157 they reach no client in it, which makes one assertion true of
     * every route rather than a rule restated per screen.
     *
     * @return array{User, array<string, string>}
     */
    private function workspaceWithAnUnreachableClient(): array
    {
        $workspace = Workspace::query()->create(['name' => 'Sweep', 'slug' => 'sweep-workspace']);
        $company = ClientCompany::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Sweep Client',
            'slug' => 'sweep-client',
        ]);
        $project = ClientProject::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'name' => 'Sweep Project',
            'status' => 'active',
        ]);
        $agreement = ClientAgreement::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'title' => 'Sweep Agreement',
            'status' => 'active',
            'currency' => 'USD',
            'starts_on' => '2026-01-01',
        ]);
        $invoice = ClientInvoice::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'invoice_number' => 'SWEEP-1',
            'status' => 'issued',
            'currency' => 'USD',
            'subtotal_amount' => 1000,
            'tax_amount' => 0,
            'total_amount' => 1000,
            'balance_amount' => 1000,
        ]);
        $task = ClientTask::query()->create([
            'workspace_id' => $workspace->id,
            'client_project_id' => $project->id,
            'title' => 'Sweep Task',
            'status' => 'open',
        ]);

        // A member of the workspace with no project membership: under #157 they
        // reach no client in it.
        $viewer = User::factory()->create();
        $workspace->memberships()->create(['user_id' => $viewer->id, 'role' => 'member']);

        $bindings = [
            'workspace' => $workspace->public_id,
            'clientCompany' => $company->public_id,
            'clientProject' => $project->public_id,
            'clientAgreement' => $agreement->public_id,
            'clientInvoice' => $invoice->public_id,
            'clientTask' => $task->public_id,
        ];

        return [$viewer, $bindings];
    }

    /**
     * @return array<string, string>
     */
    private function clientScopedGetRoutes(): array
    {
        return array_map(
            static fn (array $route): string => $route[1],
            $this->clientScopedRoutes(['GET']),
        );
    }

    /**
     * The routes under test, read from the router rather than listed here.
     *
     * @param  list<string>  $methods
     * @return array<string, array{string, string}>
     */
    private function clientScopedRoutes(array $methods): array
    {
        $routes = [];

        foreach (Route::getRoutes() as $route) {
            if (! $route instanceof RoutingRoute) {
                continue;
            }

            $matched = array_values(array_intersect($methods, $route->methods()));

            if ($matched === []) {
                continue;
            }

            $uri = $route->uri();
            $name = $route->getName();

            if ($name === null || ! str_starts_with($uri, 'workspaces/{workspace}')) {
                continue;
            }

            // Only routes that name a client-owned record. A workspace-wide
            // screen is a different question, answered by its own tests.
            if (preg_match('/\{client(Company|Invoice|Project|Agreement|Task)\}/', $uri) !== 1) {
                continue;
            }

            $routes[$name] = [$matched[0], $uri];
        }

        return $routes;
    }

    /** @param array<string, string> $bindings */
    private function substitute(string $uri, array $bindings): string
    {
        return '/'.str_replace(
            array_map(static fn (string $key): string => '{'.$key.'}', array_keys($bindings)),
            array_values($bindings),
            $uri,
        );
    }

    /** The sweep is worthless if it silently matches nothing. */
    public function test_the_sweep_covers_the_routes_it_claims_to(): void
    {
        $routes = $this->clientScopedGetRoutes();

        $this->assertGreaterThanOrEqual(7, count($routes), 'The route scan found fewer routes than exist, so it is not scanning.');
        $this->assertArrayHasKey('clients.invoice', $routes, 'The invoice detail route is the one this sweep was written for.');
        $this->assertArrayHasKey('clients.show', $routes);
    }
}
