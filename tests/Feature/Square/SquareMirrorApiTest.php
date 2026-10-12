<?php

namespace Tests\Feature\Square;

use App\Models\AgentMutationReceipt;
use App\Models\ClientCompany;
use App\Models\ClientCompanyMembership;
use App\Models\SquareInvoiceSnapshot;
use App\Models\SquareSyncRun;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Concerns\CallsMcp;
use Tests\Concerns\FakesSquare;
use Tests\Concerns\ServesSyntheticSquareAccount;
use Tests\TestCase;

/**
 * The Square mirror's operations over REST and MCP (#438), against Http::fake
 * and synthetic records only.
 */
final class SquareMirrorApiTest extends TestCase
{
    use CallsMcp;
    use FakesSquare;
    use RefreshDatabase;
    use ServesSyntheticSquareAccount;

    private User $owner;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Sleep::fake();
        Date::setTestNow('2026-10-12 18:00:00');
        config(['app.debug' => false, 'agent_api.writes_enabled' => true]);
        $this->owner = User::factory()->create();
        $this->workspace = Workspace::query()->create(['name' => 'Synthetic mirror API workspace', 'slug' => 'synthetic-mirror-api']);
        WorkspaceMembership::query()->create(['workspace_id' => $this->workspace->id, 'user_id' => $this->owner->id, 'role' => 'owner']);
        $this->connectSquare($this->workspace);
        $this->serveSquareAccount();
    }

    public function test_a_sync_over_rest_refreshes_the_mirror_and_a_retry_returns_the_same_run(): void
    {
        $this->actingAsMcp($this->owner, ['billing:read', 'billing:write']);

        $first = $this->postJson($this->url('sync'), [], ['Idempotency-Key' => 'sync-one'])->assertOk();

        $this->assertSame('completed', $first->json('data.status'));
        $this->assertSame('request', $first->json('data.trigger'));
        $this->assertSame('2026-09-16', $first->json('data.square_version'));
        $this->assertSame([3, 3, 2], [$first->json('data.invoices_seen'), $first->json('data.invoices_changed'), $first->json('data.customers_seen')]);
        $this->assertNull($first->json('data.error'));
        $this->assertSame(3, SquareInvoiceSnapshot::query()->where('workspace_id', $this->workspace->id)->count());
        $calls = count(Http::recorded());

        $retry = $this->postJson($this->url('sync'), [], ['Idempotency-Key' => 'sync-one'])->assertOk();

        $this->assertSame($first->json('data'), $retry->json('data'));
        $this->assertCount($calls, Http::recorded(), 'A replayed sync must not read Square again.');
        $this->assertSame(1, SquareSyncRun::query()->count());
        $this->assertSame(1, AgentMutationReceipt::query()->where('operation', 'square.sync')->where('status', 'completed')->count());
        Http::assertNotSent(static fn (Request $request): bool => $request->method() !== 'GET');
    }

    public function test_mcp_runs_the_same_sync_and_a_square_outage_is_a_failed_run_not_an_error(): void
    {
        $this->actingAsMcp($this->owner, ['mcp:use', 'identity:read', 'billing:read', 'billing:write']);
        $session = $this->initialize();
        $this->assertContains('square.sync', $this->toolNames($session));

        $result = $this->callTool($session, 'square.sync', ['workspace_id' => $this->workspace->public_id, 'idempotency_key' => 'mcp-sync']);

        $this->assertFalse($result['result']['isError'] ?? false, json_encode($result));
        $this->assertSame('completed', $result['result']['structuredContent']['data']['status']);

        Date::setTestNow('2026-10-12 18:01:00');
        $this->serveSquareAccount(invoicePages: [[self::squareInvoice()]], loopCursor: true);
        $failed = $this->callTool($session, 'square.sync', ['workspace_id' => $this->workspace->public_id, 'idempotency_key' => 'mcp-sync-two']);
        $this->assertSame(['failed', 'UNREADABLE', 'Square returned a response svc cannot read.'], [
            $failed['result']['structuredContent']['data']['status'],
            $failed['result']['structuredContent']['data']['error_code'],
            $failed['result']['structuredContent']['data']['error'],
        ]);
    }

    public function test_manual_syncs_are_rate_limited_per_workspace(): void
    {
        $this->actingAsMcp($this->owner, ['billing:read', 'billing:write']);
        foreach (range(1, 4) as $attempt) {
            $this->postJson($this->url('sync'), [], ['Idempotency-Key' => "sync-{$attempt}"])->assertOk();
        }

        $limited = $this->postJson($this->url('sync'), [], ['Idempotency-Key' => 'sync-5'])->assertStatus(429);

        $this->assertStringStartsWith('Square was synced recently.', (string) $limited->json('message'));
        $this->assertNotNull($limited->headers->get('Retry-After'));
        $this->assertSame(4, SquareSyncRun::query()->count());
        // A replay is not a new sync and is not limited.
        $this->postJson($this->url('sync'), [], ['Idempotency-Key' => 'sync-1'])->assertOk();
    }

    public function test_only_a_manager_of_the_bound_workspace_with_billing_write_may_sync(): void
    {
        $member = User::factory()->create();
        WorkspaceMembership::query()->create(['workspace_id' => $this->workspace->id, 'user_id' => $member->id, 'role' => 'member']);
        $portal = User::factory()->create();
        $company = ClientCompany::query()->create(['workspace_id' => $this->workspace->id, 'name' => 'Synthetic portal client', 'slug' => 'synthetic-portal-client']);
        ClientCompanyMembership::query()->create(['workspace_id' => $this->workspace->id, 'client_company_id' => $company->id, 'user_id' => $portal->id]);
        $other = Workspace::query()->create(['name' => 'Synthetic unbound workspace', 'slug' => 'synthetic-unbound-api']);
        WorkspaceMembership::query()->create(['workspace_id' => $other->id, 'user_id' => $this->owner->id, 'role' => 'owner']);

        $this->actingAsMcp($this->owner, ['billing:read']);
        $this->postJson($this->url('sync'), [], ['Idempotency-Key' => 'read-only'])->assertForbidden();
        $this->actingAsMcp($member, ['billing:read', 'billing:write']);
        $this->postJson($this->url('sync'), [], ['Idempotency-Key' => 'member'])->assertForbidden();
        $this->actingAsMcp($portal, ['billing:read', 'billing:write']);
        $this->assertContains($this->postJson($this->url('sync'), [], ['Idempotency-Key' => 'portal'])->status(), [403, 404]);
        $this->actingAsMcp($this->owner, ['billing:read', 'billing:write']);
        $this->postJson($this->url('sync', $other), [], ['Idempotency-Key' => 'unbound'])->assertNotFound();

        config(['agent_api.writes_enabled' => false]);
        $this->postJson($this->url('sync'), [], ['Idempotency-Key' => 'writes-off'])->assertNotFound();
        config(['agent_api.writes_enabled' => true, 'services.square.access_token' => null]);
        $this->postJson($this->url('sync'), [], ['Idempotency-Key' => 'unconfigured'])->assertNotFound();

        $this->assertSame(0, SquareSyncRun::query()->count());
        Http::assertNothingSent();
    }

    private function url(string $path, ?Workspace $workspace = null): string
    {
        return '/api/v1/workspaces/'.($workspace ?? $this->workspace)->public_id.'/square/'.$path;
    }
}
