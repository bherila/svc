<?php

namespace Tests\Feature\AgentApi;

use App\Http\Middleware\EnsureAgentWorkspaceVisible;
use App\Models\AgentMutationAudit;
use App\Models\AgentMutationReceipt;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CallsMcp;
use Tests\TestCase;

/**
 * Every workspace route conceals a workspace the caller cannot see the same
 * way, before anything else runs (#459 item 5): `routes/api.php` binds each
 * one through a single helper, and this holds the router to it.
 */
final class WorkspaceConcealmentTest extends TestCase
{
    use CallsMcp;
    use RefreshDatabase;

    private const string ID = '00000000-0000-4000-8000-000000000001';

    public function test_every_workspace_route_conceals_an_unseen_workspace_and_no_other_route_does(): void
    {
        $router = app('router');
        $workspaceRoutes = 0;
        foreach ($router->getRoutes()->getRoutes() as $route) {
            /** @var Route $route */
            if (! str_starts_with($route->uri(), 'api/v1/')) {
                continue;
            }
            $description = implode('|', $route->methods()).' '.$route->uri();
            $conceals = in_array(EnsureAgentWorkspaceVisible::class, $router->gatherRouteMiddleware($route), true);
            if (str_contains($route->uri(), '{workspace}')) {
                $this->assertTrue($conceals, "{$description} must be bound through \$workspaceOperation.");
                $this->assertNotNull($route->getMissing(), "{$description} must answer a missing record as an unseen workspace.");
                $workspaceRoutes++;
            } else {
                $this->assertFalse($conceals, $description);
            }
        }
        $this->assertGreaterThan(130, $workspaceRoutes);
    }

    /** @return iterable<string, array{string, string}> */
    public static function previouslyUnconcealed(): iterable
    {
        $id = self::ID;
        foreach ([
            ['GET', 'summary'], ['GET', 'projects'], ['GET', "projects/{$id}"], ['GET', 'tasks'], ['GET', "tasks/{$id}"], ['GET', 'time-entries'],
            ['GET', 'payments'], ['POST', 'payments'], ['PATCH', "payments/{$id}"], ['POST', "payments/{$id}/status"], ['POST', "payments/{$id}/refunds"],
            ['POST', "payments/{$id}/refunds/{$id}/reversal"], ['GET', 'invoices'], ['GET', "invoices/{$id}"], ['GET', 'expenses'],
            ['POST', 'time-entries/approve'], ['POST', 'invoices'], ['PATCH', "invoices/{$id}"], ['PATCH', "invoices/{$id}/details"],
            ['POST', "invoices/{$id}/correct"], ['POST', "invoices/{$id}/discard"], ['POST', "invoices/{$id}/send"], ['POST', "invoices/{$id}/void"],
        ] as [$method, $path]) {
            yield "{$method} {$path}" => [$method, $path];
        }
    }

    /**
     * These routes found an unseen workspace in their own services: reads
     * answered 404, but writes answered 403 (and approving time 422) for a
     * workspace that exists and 404 for one that does not. Now another
     * tenant's workspace and a made-up one are indistinguishable, and nothing
     * is reserved or audited for either.
     */
    #[DataProvider('previouslyUnconcealed')]
    public function test_another_tenants_workspace_answers_as_an_unknown_one_and_writes_nothing(string $method, string $path): void
    {
        config(['agent_api.writes_enabled' => true, 'agent_api.invoice_writes_enabled' => true, 'agent_api.payment_writes_enabled' => true, 'agent_api.expense_writes_enabled' => true]);
        $mine = Workspace::query()->create(['name' => 'Synthetic mine', 'slug' => 'synthetic-concealment-mine']);
        $theirs = Workspace::query()->create(['name' => 'Synthetic theirs', 'slug' => 'synthetic-concealment-theirs']);
        $owner = User::factory()->create();
        $mine->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
        $theirs->memberships()->create(['user_id' => User::factory()->create()->id, 'role' => 'owner']);
        $this->actingAsMcp($owner, ['identity:read', 'projects:read', 'tasks:read', 'time:read', 'time:approve', 'payments:read', 'payments:record', 'billing:read', 'billing:write', 'billing:deliver', 'expenses:read']);

        $answers = [];
        foreach ([$theirs->public_id, '00000000-0000-4000-8000-0000000000ff'] as $workspace) {
            $response = $this->json($method, '/api/v1/workspaces/'.$workspace.'/'.$path, [], ['Idempotency-Key' => 'synthetic-concealment-'.$workspace]);
            $response->assertNotFound();
            $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
            $answers[] = $response->json('message');
        }
        $this->assertSame('No query results for model [App\\Models\\Workspace].', $answers[0]);
        $this->assertSame($answers[0], $answers[1], 'An unseen workspace is not told apart from one that does not exist.');
        $this->assertSame(0, AgentMutationReceipt::query()->count());
        $this->assertSame(0, AgentMutationAudit::query()->count());
    }
}
