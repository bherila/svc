<?php

namespace Tests\Feature\Square;

use App\Models\ClientCompany;
use App\Models\ClientCompanyMembership;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Concerns\CallsMcp;
use Tests\Concerns\FakesSquare;
use Tests\TestCase;

/**
 * The read-only Square pass-through over REST and MCP (#438), against
 * Http::fake and synthetic records only.
 */
final class SquarePassThroughTest extends TestCase
{
    use CallsMcp;
    use FakesSquare;
    use RefreshDatabase;

    private const array TOOLS = [
        'square.customers.get', 'square.customers.list', 'square.customers.search', 'square.invoices.get', 'square.invoices.list',
        'square.invoices.search', 'square.locations.list', 'square.orders.get', 'square.payments.get', 'square.payments.list',
    ];

    private User $owner;

    private Workspace $workspace;

    private Workspace $otherWorkspace;

    /** @var list<string> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Sleep::fake();
        config(['app.debug' => false]);
        Event::listen(MessageLogged::class, function (MessageLogged $event): void {
            $this->logged[] = $event->message.' '.json_encode($event->context);
        });
        $this->owner = User::factory()->create();
        $this->workspace = Workspace::query()->create(['name' => 'Synthetic Square workspace', 'slug' => 'synthetic-square']);
        WorkspaceMembership::query()->create(['workspace_id' => $this->workspace->id, 'user_id' => $this->owner->id, 'role' => 'owner']);
        // The same manager runs a second workspace, which Square is not connected to.
        $this->otherWorkspace = Workspace::query()->create(['name' => 'Synthetic other workspace', 'slug' => 'synthetic-other']);
        WorkspaceMembership::query()->create(['workspace_id' => $this->otherWorkspace->id, 'user_id' => $this->owner->id, 'role' => 'owner']);
        $this->connectSquare($this->workspace);
        $this->actingAsMcp($this->owner, ['mcp:use', 'identity:read', 'billing:read']);
    }

    protected function tearDown(): void
    {
        foreach ($this->logged as $line) {
            $this->assertStringNotContainsString(self::SQUARE_TOKEN, $line);
        }
        parent::tearDown();
    }

    public function test_rest_and_mcp_answer_every_read_with_the_same_allow_listed_shapes(): void
    {
        $this->fakeSquare();
        $session = $this->initialize();
        $this->assertSame(self::TOOLS, array_values(array_filter($this->sortedToolNames($session), static fn (string $name): bool => str_starts_with($name, 'square.'))));

        $answers = [];
        foreach ($this->reads() as $tool => [$path, $query]) {
            $rest = $this->getJson($this->url($path, $query))->assertOk()->json();
            $mcp = $this->callTool($session, $tool, ['workspace_id' => $this->workspace->public_id, ...$query]);
            $this->assertFalse($mcp['result']['isError'] ?? false, $tool.': '.json_encode($mcp));
            $this->assertSame($rest, $mcp['result']['structuredContent'], $tool);
            $answers[$tool] = $rest;
        }

        $invoices = $answers['square.invoices.list'];
        $this->assertSame(['000001', 1, null, null], [$invoices['data'][0]['series_key'], $invoices['data'][0]['series_occurrence'], $invoices['data'][1]['series_key'], $invoices['data'][1]['series_occurrence']]);
        $this->assertSame('000001', $answers['square.invoices.get']['data']['series_key']);
        $this->assertSame('000001', $answers['square.invoices.search']['data'][0]['series_key']);
        $this->assertSame(['amount' => 12_345, 'currency' => 'USD'], $invoices['data'][0]['payment_requests'][0]['computed_amount_money']);
        $this->assertTrue($invoices['data'][0]['payment_requests'][0]['card_on_file']);
        $this->assertSame('https://squareupsandbox.com/pay-invoice/inv:0-fixture1', $invoices['data'][0]['public_url']);
        $this->assertSame(['status' => 'CAPTURED', 'entry_method' => 'ON_FILE', 'card_brand' => 'VISA', 'card_type' => 'CREDIT', 'last_4' => '1111'], $answers['square.payments.get']['data']['card']);
        $this->assertSame('next-customers', $answers['square.customers.list']['meta']['next_cursor']);
        $this->assertNull($answers['square.customers.search']['meta']['next_cursor']);

        // Redaction: card fingerprints, BINs, stored-card ids, receipt links,
        // version tokens, addresses and private notes never leave svc.
        $encoded = json_encode($answers, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        foreach (['fingerprint', 'sq-1-fixturefingerprint', '411111', '"bin"', 'ccof:', 'card_id', 'receipt_url', 'fixture-receipt-token', 'fixture-version-token', 'exp_month', 'Synthetic Way', 'Private operator note', 'MERCHANTFIXTURE', self::SQUARE_TOKEN] as $secret) {
            $this->assertStringNotContainsString($secret, $encoded, $secret);
        }
        // Nothing in Square was asked to change.
        Http::assertSentCount(2 * count($this->reads()));
        Http::assertNotSent(static fn (Request $request): bool => $request->method() !== 'GET' && ! str_ends_with(parse_url($request->url(), PHP_URL_PATH) ?: '', '/search'));
    }

    public function test_pagination_passes_cursors_and_bounded_limits_through(): void
    {
        $this->fakeSquare();

        $page = $this->getJson($this->url('invoices', ['location_id' => 'LOCFIXTURE01', 'limit' => '10', 'cursor' => 'cursor-from-square']))->assertOk();
        $this->assertSame('next-invoices', $page->json('meta.next_cursor'));
        Http::assertSent(static fn (Request $request): bool => str_contains($request->url(), '/v2/invoices?')
            && str_contains($request->url(), 'cursor=cursor-from-square') && str_contains($request->url(), 'limit=10'));

        $search = $this->getJson($this->url('invoices/search', ['location_id' => 'LOCFIXTURE01', 'customer_id' => 'cust_fixture_a', 'sort_order' => 'asc', 'cursor' => 'cursor-two']))->assertOk();
        $this->assertSame('000001', $search->json('data.0.series_key'));
        Http::assertSent(static fn (Request $request): bool => $request->method() === 'POST' && $request->data() === [
            'cursor' => 'cursor-two',
            'limit' => 25,
            'query' => ['filter' => ['location_ids' => ['LOCFIXTURE01'], 'customer_ids' => ['cust_fixture_a']], 'sort' => ['field' => 'INVOICE_SORT_DATE', 'order' => 'ASC']],
        ]);

        foreach ([
            ['invoices', ['location_id' => 'LOCFIXTURE01', 'limit' => '51']],
            ['payments', ['limit' => '0']],
            ['customers', ['limit' => '101']],
            ['customers', ['cursor' => str_repeat('c', 2049)]],
            ['customers', ['cursor' => 'has spaces']],
            ['invoices', []],
            ['customers/search', []],
            ['customers/search', ['email' => 'avery@example.test', 'match' => 'regex']],
            ['customers/search', ['phone' => 'call me']],
            ['payments', ['begin_time' => 'last tuesday']],
            ['payments', ['begin_time' => '2026-01-01 00:00:00']],
            ['payments', ['sort_order' => 'sideways']],
            ['invoices/search', ['location_id' => 'LOCFIXTURE01', 'customer_id' => '../x']],
        ] as [$path, $query]) {
            $this->assertSame(422, $this->getJson($this->url($path, $query))->status(), $path.' '.json_encode($query));
        }
        $this->getJson($this->url('invoices/inv:0-fixture1%0A', []))->assertNotFound();
        Http::assertSentCount(2);

        $session = $this->initialize();
        $refused = $this->callTool($session, 'square.customers.search', ['workspace_id' => $this->workspace->public_id]);
        $this->assertTrue($refused['result']['isError'] ?? false);
        $this->assertStringStartsWith('The request was not valid:', $refused['result']['content'][0]['text']);
        // MCP arguments are not trimmed: a trailing newline is not a Square id or time.
        foreach ([['square.invoices.get', ['invoice_id' => "inv:0-fixture1\n"]], ['square.payments.list', ['begin_time' => "2026-01-01T00:00:00Z\n"]]] as [$tool, $arguments]) {
            $call = $this->mcp(['jsonrpc' => '2.0', 'id' => 8, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => ['workspace_id' => $this->workspace->public_id, ...$arguments]]], $session);
            $this->assertTrue($call->json('result.isError') === true || $call->json('error') !== null, $tool);
        }
        Http::assertSentCount(2);
    }

    public function test_with_no_token_the_feature_is_off_everywhere(): void
    {
        Http::fake();
        $this->connectSquare($this->workspace, ['access_token' => null]);

        foreach ($this->reads() as [$path, $query]) {
            $this->getJson($this->url($path, $query))->assertNotFound();
        }
        $session = $this->initialize();
        $this->assertSame([], array_values(array_filter($this->sortedToolNames($session), static fn (string $name): bool => str_starts_with($name, 'square.'))));
        $withheld = collect($this->getJson('/api/v1/context')->assertOk()->json('data.withheld_tools'))->keyBy('name');
        foreach (self::TOOLS as $tool) {
            $this->assertSame(['name' => $tool, 'reason' => 'deployment_disabled'], $withheld[$tool]);
        }
        $call = $this->callTool($session, 'square.locations.list', ['workspace_id' => $this->workspace->public_id]);
        $this->assertArrayNotHasKey('structuredContent', $call['result'] ?? []);

        // The signed-in website gets no bypass: there is nothing to call.
        Http::assertNothingSent();
    }

    public function test_only_the_bound_workspace_can_read_square(): void
    {
        Http::fake();

        foreach ($this->reads() as [$path, $query]) {
            $this->getJson($this->url($path, $query, $this->otherWorkspace))
                ->assertNotFound()
                ->assertJson(['message' => 'Square is not connected to this workspace.']);
        }
        $session = $this->initialize();
        foreach ($this->reads() as $tool => [, $query]) {
            $call = $this->callTool($session, $tool, ['workspace_id' => $this->otherWorkspace->public_id, ...$query]);
            $this->assertTrue($call['result']['isError'] ?? false, $tool);
            $this->assertSame('Square is not connected to this workspace.', $call['result']['content'][0]['text']);
        }

        // Binding to no workspace at all switches the feature off.
        $this->connectSquare(null);
        $this->getJson($this->url('locations', []))->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_reads_need_billing_read_and_a_manager_of_the_bound_workspace(): void
    {
        Http::fake();

        // A credential without billing:read is refused by the operation gate.
        $this->actingAsMcp($this->owner, ['mcp:use', 'clients:read', 'billing:write']);
        foreach ($this->reads() as [$path, $query]) {
            $this->getJson($this->url($path, $query))->assertForbidden();
        }
        $this->assertNotContains('square.locations.list', $this->sortedToolNames($this->initialize()));

        // A workspace member who manages nothing is refused, and not offered the tools.
        $member = User::factory()->create();
        WorkspaceMembership::query()->create(['workspace_id' => $this->workspace->id, 'user_id' => $member->id, 'role' => 'member']);
        $this->actingAsMcp($member, ['mcp:use', 'billing:read']);
        foreach ($this->reads() as [$path, $query]) {
            $this->getJson($this->url($path, $query))->assertForbidden()->assertJson(['message' => 'Square data is available to workspace managers only.']);
        }
        $this->assertNotContains('square.locations.list', $this->sortedToolNames($this->initialize()));

        // A manager elsewhere is offered the tools but still refused here.
        $elsewhere = Workspace::query()->create(['name' => 'Synthetic third workspace', 'slug' => 'synthetic-third']);
        WorkspaceMembership::query()->create(['workspace_id' => $elsewhere->id, 'user_id' => $member->id, 'role' => 'admin']);
        $session = $this->initialize();
        $call = $this->callTool($session, 'square.locations.list', ['workspace_id' => $this->workspace->public_id]);
        $this->assertTrue($call['result']['isError'] ?? false);
        $this->assertSame('Square data is available to workspace managers only.', $call['result']['content'][0]['text']);

        // Another tenant, and a client portal user, cannot see the workspace at all.
        $outsider = User::factory()->create();
        $portal = User::factory()->create();
        $company = ClientCompany::query()->create(['workspace_id' => $this->workspace->id, 'name' => 'Synthetic client', 'slug' => 'synthetic-client']);
        ClientCompanyMembership::query()->create(['client_company_id' => $company->id, 'user_id' => $portal->id, 'role' => 'client']);
        foreach ([$outsider, $portal] as $user) {
            $this->actingAsMcp($user, ['mcp:use', 'billing:read']);
            foreach ($this->reads() as [$path, $query]) {
                $status = $this->getJson($this->url($path, $query))->status();
                $this->assertContains($status, [403, 404], $path);
            }
        }

        Http::assertNothingSent();
    }

    public function test_each_workspace_has_a_shared_per_minute_budget_across_transports(): void
    {
        $this->fakeSquare();
        $this->connectSquare($this->workspace, ['rate_limit_per_minute' => 2]);

        $this->getJson($this->url('locations', []))->assertOk();
        $session = $this->initialize();
        $this->assertFalse($this->callTool($session, 'square.locations.list', ['workspace_id' => $this->workspace->public_id])['result']['isError'] ?? false);
        $limited = $this->getJson($this->url('locations', []))->assertTooManyRequests();
        $this->assertStringStartsWith('Too many Square reads for this workspace.', (string) $limited->json('message'));
        $this->assertNotNull($limited->headers->get('Retry-After'));
        $call = $this->callTool($session, 'square.locations.list', ['workspace_id' => $this->workspace->public_id]);
        $this->assertStringStartsWith('Too many Square reads for this workspace.', $call['result']['content'][0]['text']);
        Http::assertSentCount(2);
    }

    public function test_the_credential_never_reaches_a_response_or_the_log(): void
    {
        $session = $this->initialize();
        $cases = [
            // Square echoing the credential in a returned field is refused outright.
            'echoed' => [Http::response(['locations' => [['id' => 'LOCFIXTURE01', 'name' => 'Bearer '.self::SQUARE_TOKEN]]]), 502],
            'refused' => [Http::response(['errors' => [['category' => 'AUTHENTICATION_ERROR', 'code' => 'UNAUTHORIZED', 'detail' => 'Token '.self::SQUARE_TOKEN.' is revoked']]], 401), 502],
            'rejected' => [Http::response(['errors' => [['category' => 'INVALID_REQUEST_ERROR', 'code' => 'BAD_REQUEST', 'detail' => self::SQUARE_TOKEN]]], 400), 422],
            'unreachable' => [static fn () => throw new ConnectionException('Could not reach '.self::SQUARE_BASE.' with Bearer '.self::SQUARE_TOKEN), 503],
        ];
        $current = null;
        Http::fake(static function (Request $request) use (&$current) {
            return is_callable($current) ? $current($request) : $current;
        });
        foreach ($cases as $case => [$response, $status]) {
            $current = $response;
            $rest = $this->getJson($this->url('locations', []))->assertStatus($status);
            $this->assertStringNotContainsString(self::SQUARE_TOKEN, (string) $rest->getContent(), $case);
            $this->assertStringNotContainsString('Bearer', (string) $rest->getContent(), $case);
            $mcp = $this->mcp(['jsonrpc' => '2.0', 'id' => 7, 'method' => 'tools/call', 'params' => ['name' => 'square.locations.list', 'arguments' => ['workspace_id' => $this->workspace->public_id]]], $session);
            $this->assertTrue($mcp->json('result.isError'), $case);
            $this->assertStringNotContainsString(self::SQUARE_TOKEN, (string) $mcp->getContent(), $case);
        }
        // tearDown asserts no log line carried it either.
    }

    public function test_square_not_found_is_a_plain_not_found(): void
    {
        Http::fake([self::SQUARE_BASE.'/*' => Http::response(['errors' => [['category' => 'INVALID_REQUEST_ERROR', 'code' => 'NOT_FOUND']]], 404)]);

        $this->getJson($this->url('payments/pay_fixture_missing', []))->assertNotFound()->assertJson(['message' => 'Square has no record with that id.']);
        Http::assertSentCount(1);
    }

    /** @return array<string, array{0: string, 1: array<string, string>}> tool => [path, query] */
    private function reads(): array
    {
        return [
            'square.locations.list' => ['locations', []],
            'square.customers.list' => ['customers', ['limit' => 5]],
            'square.customers.search' => ['customers/search', ['email' => 'avery@example.test', 'match' => 'fuzzy']],
            'square.customers.get' => ['customers/cust_fixture_a', ['customer_id' => 'cust_fixture_a']],
            'square.invoices.list' => ['invoices', ['location_id' => 'LOCFIXTURE01']],
            'square.invoices.search' => ['invoices/search', ['location_id' => 'LOCFIXTURE01', 'customer_id' => 'cust_fixture_a']],
            'square.invoices.get' => ['invoices/inv:0-fixture1', ['invoice_id' => 'inv:0-fixture1']],
            'square.orders.get' => ['orders/order_fixture_1', ['order_id' => 'order_fixture_1']],
            'square.payments.list' => ['payments', ['begin_time' => '2026-10-01T00:00:00Z', 'end_time' => '2026-11-01T00:00:00-06:00', 'location_id' => 'LOCFIXTURE01']],
            'square.payments.get' => ['payments/pay_fixture_1', ['payment_id' => 'pay_fixture_1']],
        ];
    }

    /** @param array<string, mixed> $query */
    private function url(string $path, array $query, ?Workspace $workspace = null): string
    {
        // A get's id travels in the path; everything else is a query parameter.
        if (preg_match('~^(customers|invoices|orders|payments)/(?!search$)~', $path, $match) === 1) {
            unset($query[rtrim($match[1], 's').'_id']);
        }

        return '/api/v1/workspaces/'.($workspace ?? $this->workspace)->public_id.'/square/'.$path.($query === [] ? '' : '?'.http_build_query($query));
    }

    /** @return list<string> */
    private function sortedToolNames(string $session): array
    {
        $names = $this->toolNames($session);
        sort($names);

        return $names;
    }

    private function fakeSquare(): void
    {
        Http::fake(static function (Request $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            return Http::response(match (true) {
                $path === '/v2/locations' => ['locations' => [self::squareLocation()]],
                $path === '/v2/customers' => ['customers' => [self::squareCustomer()], 'cursor' => 'next-customers'],
                $path === '/v2/customers/search' => ['customers' => [self::squareCustomer()]],
                str_starts_with($path, '/v2/customers/') => ['customer' => self::squareCustomer()],
                $path === '/v2/invoices' => ['invoices' => [self::squareInvoice(), self::squareInvoice('inv:0-fixture2', '000002')], 'cursor' => 'next-invoices'],
                $path === '/v2/invoices/search' => ['invoices' => [self::squareInvoice()]],
                str_starts_with($path, '/v2/invoices/') => ['invoice' => self::squareInvoice()],
                str_starts_with($path, '/v2/orders/') => ['order' => self::squareOrder()],
                $path === '/v2/payments' => ['payments' => [self::squarePayment()]],
                str_starts_with($path, '/v2/payments/') => ['payment' => self::squarePayment()],
                default => ['errors' => [['category' => 'INVALID_REQUEST_ERROR', 'code' => 'NOT_FOUND']]],
            });
        });
    }
}
