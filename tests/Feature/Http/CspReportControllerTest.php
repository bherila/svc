<?php

namespace Tests\Feature\Http;

use App\Http\Controllers\CspReportController;
use App\Support\Http\RateLimits;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

#[CoversClass(CspReportController::class)]
#[CoversClass(RateLimits::class)]
final class CspReportControllerTest extends TestCase
{
    private const string URI = '/api/csp-reports';

    /** @var list<array{string, array<string, mixed>}> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->logged = [];
        Log::shouldReceive('warning')->andReturnUsing(function (string $message, array $context = []): void {
            $this->logged[] = [$message, $context];
        });
        Log::shouldReceive('error', 'info', 'debug', 'notice', 'critical', 'alert', 'emergency', 'channel', 'stack')->andReturnSelf();
    }

    /**
     * @param  array<mixed>|string  $payload
     * @param  array<string, string>  $headers
     * @return TestResponse<Response>
     */
    private function report(string $type, array|string $payload, array $headers = []): TestResponse
    {
        $body = is_string($payload) ? $payload : json_encode($payload, JSON_THROW_ON_ERROR);

        return $this->call('POST', self::URI, [], [], [], $this->transform(['CONTENT_TYPE' => $type, ...$headers]), $body);
    }

    /**
     * @param  array<string, string>  $headers
     * @return array<string, string>
     */
    private function transform(array $headers): array
    {
        $server = [];
        foreach ($headers as $name => $value) {
            $server[$name === 'CONTENT_TYPE' ? $name : 'HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $server;
    }

    public function test_a_legacy_report_is_summarised_to_the_log_and_answered_with_nothing(): void
    {
        $response = $this->report('application/csp-report', ['csp-report' => [
            'document-uri' => 'https://svc.example.test/workspaces/0b3c1c8e-6f0e-4d3e-9a52-0123456789ab/clients?token=secret#frag',
            'effective-directive' => 'script-src-elem',
            'violated-directive' => 'script-src-elem',
            'blocked-uri' => 'https://evil.example.test:8443/x.js?cookie=abc',
            'original-policy' => "default-src 'self'",
            'referrer' => 'https://elsewhere.example.test/',
        ]]);

        $response->assertNoContent();
        $this->assertSame('', $response->getContent());
        $this->assertSame([['csp.violation', [
            'directive' => 'script-src-elem',
            'blocked' => 'evil.example.test',
            'path' => '/workspaces/{id}/clients',
        ]]], $this->logged);
    }

    public function test_a_reporting_api_batch_is_read_and_capped_at_ten_reports(): void
    {
        $reports = [];
        foreach (range(1, 12) as $number) {
            $reports[] = ['type' => 'csp-violation', 'url' => 'https://svc.example.test/', 'body' => [
                'effectiveDirective' => 'img-src',
                'blockedURL' => 'https://img'.$number.'.example.test/p.png',
                'documentURL' => 'https://svc.example.test/dashboard',
            ]];
        }
        $reports[] = ['type' => 'deprecation', 'body' => ['id' => 'x']];

        $this->report('application/reports+json', $reports)->assertNoContent();

        $this->assertCount(CspReportController::MAX_REPORTS, $this->logged);
        $this->assertSame(['directive' => 'img-src', 'blocked' => 'img1.example.test', 'path' => '/dashboard'], $this->logged[0][1]);
        $this->assertSame('img10.example.test', $this->logged[9][1]['blocked']);
    }

    public function test_a_media_type_parameter_is_ignored_and_other_types_are_refused(): void
    {
        $this->report('application/csp-report; charset=utf-8', ['csp-report' => ['effective-directive' => 'font-src']])->assertNoContent();
        $this->assertCount(1, $this->logged);

        foreach (['application/json', 'text/plain', 'multipart/form-data', 'application/x-www-form-urlencoded', ''] as $type) {
            $this->report($type, ['csp-report' => ['effective-directive' => 'font-src']])->assertStatus(415);
        }
        $this->assertCount(1, $this->logged);
    }

    public function test_a_body_over_eight_kib_is_refused_unread(): void
    {
        $padding = str_repeat('a', CspReportController::MAX_BYTES);

        $this->report('application/csp-report', ['csp-report' => ['effective-directive' => 'font-src', 'x' => $padding]])->assertStatus(413);
        $this->assertCount(0, $this->logged);

        // A declared length over the cap is refused even when the transport delivered less.
        $this->report('application/csp-report', ['csp-report' => ['effective-directive' => 'font-src']], ['Content-Length' => (string) (CspReportController::MAX_BYTES + 1)])->assertStatus(413);
        $this->assertCount(0, $this->logged);
    }

    public function test_a_body_exactly_at_the_cap_is_read(): void
    {
        $base = json_encode(['csp-report' => ['effective-directive' => 'font-src', 'x' => '']], JSON_THROW_ON_ERROR);
        $padding = str_repeat('a', CspReportController::MAX_BYTES - strlen($base));
        $body = json_encode(['csp-report' => ['effective-directive' => 'font-src', 'x' => $padding]], JSON_THROW_ON_ERROR);
        $this->assertSame(CspReportController::MAX_BYTES, strlen($body));

        $this->report('application/csp-report', $body)->assertNoContent();
        $this->assertCount(1, $this->logged);
    }

    public function test_malformed_bodies_are_refused_without_an_echo(): void
    {
        foreach (['not json', '', '"a string"', '123', '{"csp-report": ', str_repeat('[', 20).str_repeat(']', 20)] as $body) {
            $response = $this->report('application/csp-report', $body);
            $response->assertStatus(400);
            $this->assertSame('', $response->getContent());
        }
        $this->assertCount(0, $this->logged);
    }

    public function test_unexpected_shapes_log_nothing_or_only_safe_unknowns(): void
    {
        $this->report('application/reports+json', [['type' => 'network-error', 'body' => []], 'nope', 7, ['type' => 'csp-violation', 'body' => 'str']])->assertNoContent();
        $this->report('application/csp-report', ['csp-report' => 'str'])->assertNoContent();
        $this->assertCount(0, $this->logged);

        $this->report('application/csp-report', ['csp-report' => []])->assertNoContent();
        $this->assertSame(['directive' => 'unknown', 'blocked' => 'unknown', 'path' => 'unknown'], $this->logged[0][1]);
    }

    public function test_every_logged_field_is_reduced_to_a_short_fixed_alphabet(): void
    {
        $this->report('application/csp-report', ['csp-report' => [
            'effective-directive' => "script-src'; DROP TABLE users;--",
            'blocked-uri' => 'javascript:alert(1)',
            'document-uri' => 'https://svc.example.test/a b/<script>/'.str_repeat('x', 400),
        ]])->assertNoContent();
        $this->report('application/csp-report', ['csp-report' => [
            'violated-directive' => "style-src 'self' https://fonts.bunny.net",
            'blocked-uri' => 'https://'.str_repeat('h', 200).'.example.test/x',
            'document-uri' => 'not a url',
        ]])->assertNoContent();
        $this->report('application/csp-report', ['csp-report' => [
            'effective-directive' => str_repeat('a', 41),
            'blocked-uri' => 'inline',
            'document-uri' => 'https://svc.example.test',
        ]])->assertNoContent();
        $this->report('application/csp-report', ['csp-report' => ['blocked-uri' => 'EVAL', 'effective-directive' => ['x'], 'document-uri' => ['x']]])->assertNoContent();

        $this->assertSame('unknown', $this->logged[0][1]['directive']);
        $this->assertSame('other', $this->logged[0][1]['blocked']);
        $this->assertLessThanOrEqual(120, strlen($this->logged[0][1]['path']));
        $this->assertMatchesRegularExpression('#^/a\?b/\?script\?/x+\z#', $this->logged[0][1]['path']);
        $this->assertSame('style-src', $this->logged[1][1]['directive']);
        $this->assertSame('other', $this->logged[1][1]['blocked']);
        $this->assertSame('unknown', $this->logged[1][1]['path']);
        $this->assertSame('unknown', $this->logged[2][1]['directive']);
        $this->assertSame('inline', $this->logged[2][1]['blocked']);
        $this->assertSame('unknown', $this->logged[2][1]['path']);
        $this->assertSame(['directive' => 'unknown', 'blocked' => 'eval', 'path' => 'unknown'], $this->logged[3][1]);
    }

    public function test_nothing_the_browser_sent_is_stored_beyond_the_three_summary_fields(): void
    {
        $this->report('application/csp-report', ['csp-report' => [
            'document-uri' => 'https://user:pass@svc.example.test/pay?client_secret=cs_test_synthetic',
            'blocked-uri' => 'https://user:pass@cdn.example.test/x?token=synthetic',
            'effective-directive' => 'script-src',
            'script-sample' => 'synthetic-secret-sample',
            'source-file' => 'https://svc.example.test/app.js',
        ]])->assertNoContent();

        $this->assertCount(1, $this->logged);
        $line = json_encode($this->logged[0], JSON_THROW_ON_ERROR);
        $this->assertSame(['directive', 'blocked', 'path'], array_keys($this->logged[0][1]));
        foreach (['user', 'pass', 'client_secret', 'cs_test', 'token', 'synthetic'] as $secret) {
            $this->assertStringNotContainsString($secret, $line);
        }
        $this->assertSame('/pay', $this->logged[0][1]['path']);
        $this->assertSame('cdn.example.test', $this->logged[0][1]['blocked']);
    }

    public function test_it_needs_no_session_no_token_and_is_not_behind_csrf(): void
    {
        $route = Route::getRoutes()->match(Request::create(self::URI, 'POST'));
        $middleware = $this->app['router']->gatherRouteMiddleware($route);

        $this->assertNotContains(PreventRequestForgery::class, $middleware);
        $this->assertNotContains(ValidateCsrfToken::class, $middleware);
        $this->assertNotContains(StartSession::class, $middleware);
        $this->assertContains(ThrottleRequests::class.':csp-reports', $middleware);
        $this->assertNotContains('auth', $middleware);

        $this->report('application/csp-report', ['csp-report' => ['effective-directive' => 'font-src']])->assertNoContent();
    }

    public function test_it_is_rate_limited_by_its_named_limiter_per_address(): void
    {
        $this->assertSame(120, RateLimits::DEFAULTS['csp-reports']);
        config(['svc.rate_limits.csp-reports' => 2]);
        RateLimiter::clear('csp-reports|ip:127.0.0.1');

        $this->report('application/csp-report', ['csp-report' => []])->assertNoContent();
        $this->report('application/csp-report', ['csp-report' => []])->assertNoContent();
        $this->report('application/csp-report', ['csp-report' => []])->assertStatus(429);
        $this->assertCount(2, $this->logged);

        RateLimiter::clear('csp-reports|ip:127.0.0.1');
    }

    public function test_only_post_is_accepted_and_a_get_never_logs(): void
    {
        $this->get(self::URI)->assertStatus(405);
        $this->assertCount(0, $this->logged);
    }
}
