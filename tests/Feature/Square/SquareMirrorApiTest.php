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
use App\Services\Square\Mirror\SquareMirrorSync;
use App\Support\AgentApi\AgentApiResponseSchemaCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Mcp\Capability\Discovery\SchemaValidator;
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

    public function test_mirror_reads_filter_page_and_answer_alike_over_rest_and_mcp_without_reaching_square(): void
    {
        $this->syncOnce();
        $calls = count(Http::recorded());
        $this->actingAsMcp($this->owner, ['mcp:use', 'identity:read', 'billing:read']);
        $session = $this->initialize();

        $reads = [
            'square.mirror.invoices.list' => ['mirror/invoices', []],
            'square.series.list' => ['series', []],
            'square.series.get' => ['series/000001', ['series_key' => '000001']],
        ];
        $answers = [];
        foreach ($reads as $tool => [$path, $arguments]) {
            $rest = $this->getJson($this->url($path))->assertOk()->json();
            $mcp = $this->callTool($session, $tool, ['workspace_id' => $this->workspace->public_id, ...$arguments]);
            $this->assertFalse($mcp['result']['isError'] ?? false, $tool.': '.json_encode($mcp));
            $this->assertSame($rest, $mcp['result']['structuredContent'], $tool);
            $this->assertSame([], (new SchemaValidator)->validateAgainstJsonSchema($rest, AgentApiResponseSchemaCatalog::forOperation($tool)), $tool);
            $answers[$tool] = $rest;
        }
        $this->assertCount($calls, Http::recorded(), 'Mirror reads never reach Square.');

        $invoices = $answers['square.mirror.invoices.list'];
        $this->assertSame(['inv:0-fixture1', 'inv:0-fixture2', 'inv:0-fixture3'], array_column($invoices['data'], 'square_invoice_id'));
        $this->assertSame('2026-10-12T18:00:00Z', $invoices['meta']['last_synced_at']);
        $this->assertSame('completed', $invoices['meta']['last_run']['status']);
        $first = $invoices['data'][0];
        $this->assertSame(['000001', 1, 'UNPAID', 12_345, 0, '2026-10-15', true, null], [$first['series_key'], $first['series_occurrence'], $first['status'], $first['total_amount'], $first['paid_amount'], $first['due_on'], $first['autopay'], $first['payment_ids']]);
        $this->assertSame('https://squareupsandbox.com/pay-invoice/inv:0-fixture1', $first['public_url']);
        $this->assertSame([['relative_scheduled_days' => -1, 'status' => 'PENDING', 'sent_at' => null]], $first['reminders']);
        $this->assertSame(['pay_fixture_1'], $invoices['data'][1]['payment_ids']);

        $series = $answers['square.series.list']['data'];
        $this->assertSame(['000001'], array_column($series, 'series_key'));
        $this->assertSame(['cust_fixture_a', 2, 'USD', 12_345, ['PAID' => 1, 'UNPAID' => 1]], [$series[0]['customer_id'], $series[0]['invoice_count'], $series[0]['currency'], $series[0]['amount'], $series[0]['statuses']]);
        $this->assertSame(['inv:0-fixture1', 'inv:0-fixture2'], array_column($answers['square.series.get']['data']['members'], 'square_invoice_id'));
        $this->assertFalse($answers['square.series.get']['data']['members_truncated']);

        foreach ([
            [['status' => 'PAID'], ['inv:0-fixture2']],
            [['series_key' => '000001'], ['inv:0-fixture1', 'inv:0-fixture2']],
            [['customer_id' => 'cust_fixture_a', 'location_id' => 'LOCFIXTURE01'], ['inv:0-fixture1', 'inv:0-fixture2', 'inv:0-fixture3']],
            [['customer_id' => 'cust_fixture_b'], []],
            [['due_from' => '2026-10-15', 'due_to' => '2026-10-15'], ['inv:0-fixture1', 'inv:0-fixture2', 'inv:0-fixture3']],
            [['due_to' => '2026-10-14'], []],
        ] as [$query, $expected]) {
            $page = $this->getJson($this->url('mirror/invoices?'.http_build_query($query)))->assertOk();
            $this->assertSame($expected, array_column($page->json('data'), 'square_invoice_id'), json_encode($query));
        }

        $page = $this->getJson($this->url('mirror/invoices?limit=2'))->assertOk();
        $this->assertSame(['inv:0-fixture1', 'inv:0-fixture2'], array_column($page->json('data'), 'square_invoice_id'));
        $rest = $this->getJson($this->url('mirror/invoices?limit=2&cursor='.urlencode((string) $page->json('meta.next_cursor'))))->assertOk();
        $this->assertSame(['inv:0-fixture3'], array_column($rest->json('data'), 'square_invoice_id'));
        $this->assertNull($rest->json('meta.next_cursor'));
        // A cursor belongs to its query.
        $this->getJson($this->url('mirror/invoices?limit=2&status=PAID&cursor='.urlencode((string) $page->json('meta.next_cursor'))))->assertStatus(422);

        foreach (['status=paid', 'series_key=12345', 'series_key=000001-R', 'location_id=..%2Fx', 'due_from=2026-10-15&due_to=2026-10-01', 'due_from=yesterday', 'limit=101', 'status[]=PAID'] as $invalid) {
            $this->getJson($this->url('mirror/invoices?'.$invalid))->assertStatus(422);
        }
        $this->getJson($this->url('series?limit=51'))->assertStatus(422);
        $this->getJson($this->url('series/999999'))->assertNotFound();
        $this->getJson($this->url('series/abc'))->assertNotFound();
        $this->assertCount($calls, Http::recorded());
    }

    public function test_series_pages_by_key_and_reads_are_for_managers_of_the_bound_workspace_only(): void
    {
        $invoices = [];
        foreach (['000001', '000002', '000003'] as $index => $key) {
            $invoices[] = self::squareInvoice("inv:0-series{$index}", "{$key}-R-0001");
        }
        $this->serveSquareAccount(invoices: $invoices);
        $this->syncOnce();
        $this->actingAsMcp($this->owner, ['billing:read']);

        $first = $this->getJson($this->url('series?limit=2'))->assertOk();
        $this->assertSame(['000001', '000002'], array_column($first->json('data'), 'series_key'));
        $second = $this->getJson($this->url('series?limit=2&cursor='.urlencode((string) $first->json('meta.next_cursor'))))->assertOk();
        $this->assertSame(['000003'], array_column($second->json('data'), 'series_key'));
        $this->assertNull($second->json('meta.next_cursor'));

        $member = User::factory()->create();
        WorkspaceMembership::query()->create(['workspace_id' => $this->workspace->id, 'user_id' => $member->id, 'role' => 'member']);
        $this->actingAsMcp($member, ['billing:read']);
        $this->getJson($this->url('mirror/invoices'))->assertForbidden();
        $this->getJson($this->url('series'))->assertForbidden();

        $other = Workspace::query()->create(['name' => 'Synthetic unbound reads', 'slug' => 'synthetic-unbound-reads']);
        WorkspaceMembership::query()->create(['workspace_id' => $other->id, 'user_id' => $this->owner->id, 'role' => 'owner']);
        $this->actingAsMcp($this->owner, ['billing:read']);
        $this->getJson($this->url('mirror/invoices', $other))->assertNotFound();
        $this->actingAsMcp($this->owner, ['identity:read']);
        $this->getJson($this->url('mirror/invoices'))->assertForbidden();
        config(['services.square.access_token' => null]);
        $this->actingAsMcp($this->owner, ['billing:read']);
        $this->getJson($this->url('mirror/invoices'))->assertNotFound();
    }

    private function syncOnce(): void
    {
        $sync = app(SquareMirrorSync::class);
        $sync->perform($this->workspace, $sync->request($this->workspace, SquareSyncRun::TRIGGER_SCHEDULE));
    }

    private function url(string $path, ?Workspace $workspace = null): string
    {
        return '/api/v1/workspaces/'.($workspace ?? $this->workspace)->public_id.'/square/'.$path;
    }
}
