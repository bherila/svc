<?php

namespace Tests\Feature\AgentApi;

use App\Http\Middleware\LogRejectedBearer;
use App\Models\User;
use App\Support\AgentApi\AgentApiScopes;
use DateTimeImmutable;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\Http\Middleware\CheckToken;
use Laravel\Passport\Passport;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\ResourceServer;
use Monolog\Formatter\JsonFormatter;
use Monolog\Formatter\LineFormatter;
use Monolog\Level;
use Monolog\LogRecord;
use Symfony\Component\HttpFoundation\Response;
use Tests\Concerns\FakesProviderIdentity;
use Tests\Concerns\IssuesAgentOAuthTokens;
use Tests\TestCase;

/**
 * Every bearer the agent API (REST and MCP) refuses leaves one structured log
 * line: why, and a fingerprint of what was presented - its length, its first
 * characters, whether it is shaped like a JWT - never the token itself (#441).
 */
final class RejectedBearerLogTest extends TestCase
{
    use FakesProviderIdentity;
    use IssuesAgentOAuthTokens;
    use RefreshDatabase;

    private const string MESSAGE = 'Agent API bearer rejected';

    /** @var list<MessageLogged> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->forgetInstance(AuthorizationServer::class);
        $this->app->forgetInstance(ResourceServer::class);
        config(['agent_api.writes_enabled' => true, 'agent_api.workspace_writes_enabled' => true]);
        Log::listen(function (MessageLogged $message): void {
            $this->logged[] = $message;
        });
    }

    public function test_a_refresh_token_sent_as_the_bearer_is_logged_as_a_non_jwt_with_its_length_and_prefix(): void
    {
        $tokens = $this->issueAgentTokens(User::factory()->create(), 'mcp', [AgentApiScopes::MCP_USE, AgentApiScopes::IDENTITY_READ]);
        $refresh = $tokens['refresh_token'];
        $this->assertSame(0, substr_count($refresh, '.'), 'A refresh token is not a JWT');

        $this->mcpWith($refresh, ['X-Request-Id' => 'req-synthetic-mcp-0001'])->assertUnauthorized();
        $this->restWith($refresh, ['X-Request-Id' => 'req-synthetic-rest-0001'])->assertUnauthorized();

        $lines = $this->rejections();
        $this->assertCount(2, $lines);
        foreach ([['agent-api.v1.mcp', 'req-synthetic-mcp-0001'], ['agent-api.v1.context', 'req-synthetic-rest-0001']] as $index => [$route, $requestId]) {
            $this->assertSame([
                'reason' => 'invalid_structure',
                'status' => 401,
                'token_length' => strlen($refresh),
                'token_prefix' => substr($refresh, 0, 8),
                'jwt_shaped' => false,
                'client_id' => null,
                'request_id' => $requestId,
                'route' => $route,
            ], $lines[$index]);
        }
        $this->assertNoLogLineContains([$refresh]);
    }

    public function test_an_expired_access_token_names_its_client_because_its_signature_verifies(): void
    {
        $token = $this->handBuiltAccessToken('client-fixture-a', new DateTimeImmutable('-1 hour'));

        $this->restWith($token)->assertUnauthorized();

        $line = $this->soleRejection();
        $this->assertSame('expired', $line['reason']);
        $this->assertTrue($line['jwt_shaped']);
        $this->assertSame(strlen($token), $line['token_length']);
        $this->assertSame(substr($token, 0, 8), $line['token_prefix']);
        $this->assertSame('client-fixture-a', $line['client_id']);
        $this->assertNull($line['request_id'], 'No request id was sent');
    }

    public function test_a_forged_signature_is_logged_without_trusting_its_client_claim(): void
    {
        $token = $this->handBuiltAccessToken('client-fixture-a', new DateTimeImmutable('+10 minutes'), $this->strangerPrivateKey());

        $this->mcpWith($token)->assertUnauthorized();

        $line = $this->soleRejection();
        $this->assertSame('bad_signature', $line['reason']);
        $this->assertTrue($line['jwt_shaped']);
        $this->assertNull($line['client_id'], 'An unverified audience claim is not a client id');
    }

    public function test_each_refusal_of_an_issued_token_names_its_reason_and_client(): void
    {
        $user = User::factory()->create();
        $rest = $this->issueAgentTokens($user, 'rest', [AgentApiScopes::IDENTITY_READ]);
        $mcp = $this->issueAgentTokens($user, 'mcp', [AgentApiScopes::MCP_USE, AgentApiScopes::IDENTITY_READ]);

        $this->restWith($rest['access_token'])->assertOk();
        $this->assertSame([], $this->rejections(), 'An accepted bearer is not logged');

        // A credential issued for the other resource, each way round.
        $this->mcpWith($rest['access_token'])->assertUnauthorized();
        $this->restWith($mcp['access_token'])->assertUnauthorized();
        // Authenticated, but without the operation's scope.
        $this->bearer($rest['access_token'])->withHeader('Idempotency-Key', 'synthetic-scope-0001')
            ->postJson('/api/v1/workspaces', ['name' => 'Synthetic refused workspace'])
            ->assertForbidden();
        // Disconnected.
        Passport::token()->newQuery()->where('client_id', $rest['client_id'])->update(['revoked' => true]);
        $this->restWith($rest['access_token'])->assertUnauthorized();

        $this->assertSame([
            ['wrong_audience', 401, $rest['client_id'], 'agent-api.v1.mcp'],
            ['wrong_audience', 401, $mcp['client_id'], 'agent-api.v1.context'],
            ['insufficient_scope', 403, $rest['client_id'], 'agent-api.v1.workspaces.store'],
            ['revoked', 401, $rest['client_id'], 'agent-api.v1.context'],
        ], array_map(
            static fn (array $line): array => [$line['reason'], $line['status'], $line['client_id'], $line['route']],
            $this->rejections(),
        ));
        $this->assertNoLogLineContains([$rest['access_token'], $mcp['access_token']]);
    }

    public function test_an_identity_the_provider_ended_is_logged_as_identity_refused(): void
    {
        $this->fakeProviderIdentity();
        $user = $this->boundUser('person-ended');
        $this->providerIdentity('person-ended', 2);
        $this->signInThroughProvider('person-ended', 2);
        $tokens = $this->issueAgentTokens($user, 'rest', [AgentApiScopes::IDENTITY_READ, AgentApiScopes::WORKSPACES_CREATE]);
        $this->disableProviderIdentity('person-ended');

        // A write asks the provider freshly; the read after it rides that answer.
        $this->bearer($tokens['access_token'])->withHeader('Idempotency-Key', 'synthetic-ended-0001')
            ->postJson('/api/v1/workspaces', ['name' => 'Synthetic ended workspace'])
            ->assertUnauthorized();
        $this->restWith($tokens['access_token'])->assertUnauthorized();

        $this->assertSame(
            [['identity_refused', $tokens['client_id']], ['identity_refused', $tokens['client_id']]],
            array_map(static fn (array $line): array => [$line['reason'], $line['client_id']], $this->rejections()),
        );
    }

    public function test_a_retry_loop_is_rate_limited_per_client_and_per_address(): void
    {
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $this->restWith('def50200'.str_repeat('0a', 40))->assertUnauthorized();
        }
        $this->assertCount(LogRejectedBearer::LINES_PER_MINUTE, $this->rejections(), 'One address, no client: limited');

        $this->restWith('def50200'.str_repeat('0b', 40), ['REMOTE_ADDR' => '192.0.2.7'])->assertUnauthorized();
        $this->assertCount(LogRejectedBearer::LINES_PER_MINUTE + 1, $this->rejections(), 'Another address has its own budget');

        $expiredA = $this->handBuiltAccessToken('client-fixture-a', new DateTimeImmutable('-1 hour'));
        $expiredB = $this->handBuiltAccessToken('client-fixture-b', new DateTimeImmutable('-1 hour'));
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $this->restWith($expiredA)->assertUnauthorized();
        }
        $this->restWith($expiredB)->assertUnauthorized();

        $byClient = array_count_values(array_filter(array_column($this->rejections(), 'client_id')));
        $this->assertSame(['client-fixture-a' => LogRejectedBearer::LINES_PER_MINUTE, 'client-fixture-b' => 1], $byClient);

        $this->travel(61)->seconds();
        $this->restWith($expiredA)->assertUnauthorized();
        $this->assertSame(LogRejectedBearer::LINES_PER_MINUTE + 1, array_count_values(array_filter(array_column($this->rejections(), 'client_id')))['client-fixture-a']);
    }

    public function test_no_log_line_ever_contains_a_full_token(): void
    {
        $user = User::factory()->create();
        $tokens = $this->issueAgentTokens($user, 'rest', [AgentApiScopes::IDENTITY_READ]);
        $expired = $this->handBuiltAccessToken('client-fixture-a', new DateTimeImmutable('-1 hour'));
        $forged = $this->handBuiltAccessToken('client-fixture-b', new DateTimeImmutable('+10 minutes'), $this->strangerPrivateKey());
        $presented = [
            $tokens['refresh_token'],
            $expired,
            $forged,
            // Short enough that a fixed-length prefix would be the whole token.
            'Q7z',
            'Zq9xK2w',
            'Xv8Rk3Pq',
            'Xv8Rk3Pq9',
            'Kq3v.Wz8p.Rt5m',
            '12|'.Str::random(40),
            "bad\x01chars\"and'quotes\u{e9}tail-1234567890",
        ];

        foreach ($presented as $index => $token) {
            // Each from its own address, so the per-address limit keeps every line.
            $address = '192.0.2.'.($index + 10);
            $this->restWith($token, ['REMOTE_ADDR' => $address])->assertUnauthorized();
            $this->mcpWith($token, ['REMOTE_ADDR' => $address])->assertUnauthorized();
            $this->bearer($token, $address)->getJson('/api/v1/workspaces/'.Str::uuid().'/invoice-payments')->assertUnauthorized();
        }

        $this->assertCount(count($presented) * 3, $this->rejections());
        foreach ($this->rejections() as $line) {
            $this->assertLessThanOrEqual(8, strlen($line['token_prefix']));
            $this->assertLessThan($line['token_length'], strlen($line['token_prefix']) * 2, 'At most half of any token is shown');
        }
        $segments = [];
        foreach ([$tokens['access_token'], $expired, $forged] as $jwt) {
            [, $payload, $signature] = explode('.', $jwt);
            $segments[] = $payload;
            $segments[] = $signature;
        }
        $this->assertNoLogLineContains([...$presented, ...$segments, $tokens['access_token']]);
    }

    public function test_requests_without_a_bearer_and_refusals_unrelated_to_it_log_nothing(): void
    {
        $tokens = $this->issueAgentTokens(User::factory()->create(), 'rest', [AgentApiScopes::IDENTITY_READ, AgentApiScopes::CLIENTS_READ]);

        $this->getJson('/api/v1/context')->assertUnauthorized();
        $this->postJson('/api/v1/mcp', [], ['Mcp-Protocol-Version' => '2025-06-18'])->assertUnauthorized();
        // A workspace the caller cannot see is concealed, not a credential problem.
        $this->bearer($tokens['access_token'])->getJson('/api/v1/workspaces/'.Str::uuid().'/clients')->assertNotFound();

        $this->assertSame([], $this->rejections());
    }

    /**
     * The property this rests on: every API route that authenticates a bearer
     * runs the logging middleware before anything that can refuse one, so no
     * refusal happens where it cannot see the token or the response.
     */
    public function test_every_api_route_that_checks_a_bearer_logs_its_refusals(): void
    {
        $router = $this->app['router'];
        $checked = [];
        foreach ($router->getRoutes()->getRoutes() as $route) {
            // Bearers are accepted only under /api; elsewhere `auth` is the browser session.
            if (! str_starts_with($route->uri(), 'api/')) {
                continue;
            }
            $middleware = $router->gatherRouteMiddleware($route);
            $refusing = array_keys(array_filter($middleware, self::refusesABearer(...)));
            if ($refusing === []) {
                continue;
            }
            $logger = array_search(LogRejectedBearer::class, $middleware, true);
            $this->assertIsInt($logger, "{$this->describe($route)} refuses bearers without logging them");
            $this->assertLessThan(min($refusing), $logger, "{$this->describe($route)} logs after a refusal can happen");
            $checked[] = (string) $route->getName();
        }

        $this->assertGreaterThan(100, count($checked));
        foreach (['agent-api.v1.mcp', 'agent-api.v1.context', 'agent-api.v1.connections.destroy', 'agent-api.v1.workspaces.store', 'api.v1.invoice-payments.index'] as $name) {
            $this->assertContains($name, $checked);
        }
    }

    private static function refusesABearer(string $middleware): bool
    {
        return str_starts_with($middleware, Authenticate::class)
            || str_starts_with($middleware, CheckToken::class)
            || str_starts_with($middleware, CheckAbilities::class)
            || str_starts_with($middleware, 'Bherila\\McpLaravelBridge\\Http\\GateOperation');
    }

    private function describe(Route $route): string
    {
        return implode('|', $route->methods()).' '.$route->uri();
    }

    /**
     * @param  array<string, string>  $headers
     * @return TestResponse<Response>
     */
    private function restWith(string $token, array $headers = []): TestResponse
    {
        $address = $headers['REMOTE_ADDR'] ?? '127.0.0.1';
        unset($headers['REMOTE_ADDR']);

        return $this->bearer($token, $address)->withHeaders($headers)->getJson('/api/v1/context');
    }

    /**
     * @param  array<string, string>  $headers
     * @return TestResponse<Response>
     */
    private function mcpWith(string $token, array $headers = []): TestResponse
    {
        $address = $headers['REMOTE_ADDR'] ?? '127.0.0.1';
        unset($headers['REMOTE_ADDR']);

        return $this->bearer($token, $address)->withHeaders(['Mcp-Protocol-Version' => '2025-06-18', ...$headers])->postJson('/api/v1/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 'SVC test', 'version' => '1']],
        ]);
    }

    private function bearer(string $token, string $address = '127.0.0.1'): static
    {
        Auth::forgetGuards();
        $this->defaultHeaders = [];

        return $this->withServerVariables(['REMOTE_ADDR' => $address])->withHeader('Authorization', 'Bearer '.$token);
    }

    /** @return list<array<string, mixed>> */
    private function rejections(): array
    {
        return array_values(array_map(
            static fn (MessageLogged $message): array => $message->context,
            array_filter($this->logged, static fn (MessageLogged $message): bool => $message->message === self::MESSAGE),
        ));
    }

    /** @return array<string, mixed> */
    private function soleRejection(): array
    {
        $lines = $this->rejections();
        $this->assertCount(1, $lines);

        return $lines[0];
    }

    /**
     * Each line as the log formatters write it - the plain line and the JSON
     * one, stack traces and previous exceptions included - so a token reaching
     * a log through any exception is caught too.
     *
     * @param  list<string>  $secrets
     */
    private function assertNoLogLineContains(array $secrets): void
    {
        $this->assertNotSame([], $this->logged);
        $line = new LineFormatter(allowInlineLineBreaks: true, includeStacktraces: true);
        $json = (new JsonFormatter)->includeStacktraces();
        foreach ($this->logged as $message) {
            $record = new LogRecord(new DateTimeImmutable, 'testing', Level::fromName($message->level), $message->message, $message->context);
            $text = $line->format($record).$json->format($record);
            foreach ($secrets as $secret) {
                $this->assertStringNotContainsString($secret, $text, 'A log line carries a whole token or JWT segment');
            }
        }
    }
}
