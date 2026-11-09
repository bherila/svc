<?php

namespace Tests\Feature\Http;

use App\Http\Middleware\SecurityHeaders;
use App\Support\Http\CspMode;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

#[CoversClass(SecurityHeaders::class)]
#[CoversClass(CspMode::class)]
final class ContentSecurityPolicyTest extends TestCase
{
    protected function tearDown(): void
    {
        @unlink(self::viteHotFile());
        parent::tearDown();
    }

    public function test_every_page_carries_a_fresh_nonce_that_matches_its_inline_script_and_its_policy(): void
    {
        $nonces = [];
        foreach ([$this->get('/'), $this->get('/')] as $response) {
            $policy = (string) $response->headers->get('Content-Security-Policy-Report-Only');
            $nonce = Str::match("/script-src [^;]*'nonce-([A-Za-z0-9]{40})'/", $policy);
            $this->assertNotSame('', $nonce);

            // Every executable <script> in the page carries the policy's nonce; the Inertia page data is JSON, never run.
            preg_match_all('/<script\b([^>]*)>/i', (string) $response->getContent(), $tags);
            $executable = array_filter($tags[1], static fn (string $attributes): bool => ! str_contains($attributes, 'application/json'));
            $this->assertNotEmpty($executable);
            foreach ($executable as $attributes) {
                $this->assertStringContainsString('nonce="'.$nonce.'"', $attributes);
            }
            $nonces[] = $nonce;
        }

        $this->assertNotSame($nonces[0], $nonces[1]);
    }

    public function test_the_vite_dev_server_scripts_carry_the_nonce_too(): void
    {
        file_put_contents(self::viteHotFile(), 'http://[::1]:5173');
        $this->withVite();
        $response = $this->get('/');
        $policy = (string) $response->headers->get('Content-Security-Policy-Report-Only');
        $nonce = Str::match("/'nonce-([A-Za-z0-9]{40})'/", $policy);
        $html = (string) $response->getContent();

        // The React Refresh preamble is inline; the client and entry scripts are modules from the dev origin.
        $this->assertStringContainsString('http://[::1]:5173/@vite/client', $html);
        preg_match_all('/<script\b([^>]*)>/i', $html, $tags);
        $executable = array_filter($tags[1], static fn (string $attributes): bool => ! str_contains($attributes, 'application/json'));
        $this->assertGreaterThanOrEqual(3, count($executable));
        foreach ($executable as $attributes) {
            $this->assertStringContainsString('nonce="'.$nonce.'"', $attributes);
        }
        $this->assertStringContainsString("script-src 'self' 'nonce-{$nonce}'", $policy);
        $this->assertStringContainsString('http://[::1]:5173', $policy);
    }

    public function test_script_src_has_no_unsafe_inline_in_any_mode(): void
    {
        foreach (['report-only' => 'Content-Security-Policy-Report-Only', 'enforce' => 'Content-Security-Policy'] as $mode => $header) {
            config(['svc.security.csp_mode' => $mode]);
            $policy = (string) $this->get('/')->headers->get($header);

            $scripts = Str::match('/script-src ([^;]*);/', $policy);
            $this->assertNotSame('', $scripts, $mode);
            $this->assertStringNotContainsString("'unsafe-inline'", $scripts, $mode);
            $this->assertStringNotContainsString("'unsafe-eval'", $policy, $mode);
        }
    }

    public function test_enforce_mode_sends_the_enforcing_header_and_not_the_report_only_one(): void
    {
        config(['svc.security.csp_mode' => 'enforce']);
        $response = $this->get('/');

        $this->assertStringStartsWith("default-src 'self'; script-src", (string) $response->headers->get('Content-Security-Policy'));
        $response->assertHeaderMissing('Content-Security-Policy-Report-Only');
    }

    public function test_report_only_mode_is_the_default_and_sends_only_the_report_only_header(): void
    {
        $this->assertSame('report-only', config('svc.security.csp_mode'));
        $response = $this->get('/');

        $this->assertNotNull($response->headers->get('Content-Security-Policy-Report-Only'));
        $response->assertHeaderMissing('Content-Security-Policy');
    }

    public function test_off_mode_sends_no_policy_and_no_reporting_endpoint(): void
    {
        config(['svc.security.csp_mode' => 'off']);
        $response = $this->get('/');

        $response->assertHeaderMissing('Content-Security-Policy');
        $response->assertHeaderMissing('Content-Security-Policy-Report-Only');
        $response->assertHeaderMissing('Reporting-Endpoints');
    }

    /** A typo must neither disable the policy nor start blocking pages. */
    #[DataProvider('unrecognisedModes')]
    public function test_an_unrecognised_mode_is_report_only(mixed $value): void
    {
        config(['svc.security.csp_mode' => $value]);
        $response = $this->get('/');

        $this->assertNotNull($response->headers->get('Content-Security-Policy-Report-Only'));
        $response->assertHeaderMissing('Content-Security-Policy');
    }

    /** @return array<string, array{mixed}> */
    public static function unrecognisedModes(): array
    {
        return ['typo' => ['enforced'], 'empty' => [''], 'null' => [null], 'bool' => [true], 'number' => [1]];
    }

    public function test_a_mode_is_read_without_regard_to_case_or_padding(): void
    {
        $this->assertSame(CspMode::Enforce, CspMode::fromConfig(' ENFORCE '));
        $this->assertSame(CspMode::Off, CspMode::fromConfig('Off'));
        $this->assertSame(CspMode::ReportOnly, CspMode::fromConfig('report-only'));
        $this->assertNull(CspMode::Off->header());
        $this->assertSame('Content-Security-Policy', CspMode::Enforce->header());
        $this->assertSame('Content-Security-Policy-Report-Only', CspMode::ReportOnly->header());
    }

    public function test_stripe_is_allowed_where_its_documentation_requires(): void
    {
        $policy = (string) $this->get('/')->headers->get('Content-Security-Policy-Report-Only');

        $directive = static function (string $name) use ($policy): string {
            return Str::match('/(?:^|; )'.$name.' ([^;]*)/', $policy);
        };

        foreach (['https://js.stripe.com', 'https://*.js.stripe.com'] as $host) {
            $this->assertStringContainsString($host, $directive('script-src'));
            $this->assertStringContainsString($host, $directive('frame-src'));
        }
        $this->assertStringContainsString('https://api.stripe.com', $directive('connect-src'));
        $this->assertStringContainsString('https://hooks.stripe.com', $directive('frame-src'));
        $this->assertStringContainsString('https://*.stripe.com', $directive('img-src'));
        $this->assertSame("'none'", $directive('object-src'));
    }

    public function test_the_reporting_endpoint_is_advertised_beside_the_policy(): void
    {
        $response = $this->get('/');

        $response->assertHeader('Reporting-Endpoints', 'csp-endpoint="/api/csp-reports"');
        $this->assertStringContainsString('report-to csp-endpoint', (string) $response->headers->get('Content-Security-Policy-Report-Only'));

        config(['svc.security.csp_report_uri' => '']);
        $silent = $this->get('/');
        $silent->assertHeaderMissing('Reporting-Endpoints');
        $this->assertStringNotContainsString('report-', (string) $silent->headers->get('Content-Security-Policy-Report-Only'));
    }

    public function test_json_and_webhook_responses_carry_no_page_policy_in_any_mode(): void
    {
        config(['svc.security.csp_mode' => 'enforce']);

        foreach ([$this->getJson('/api/v1/workspaces'), $this->postJson('/api/webhooks/stripe', []), $this->postJson('/api/webhooks/square', [])] as $response) {
            $response->assertHeaderMissing('Content-Security-Policy');
            $response->assertHeaderMissing('Reporting-Endpoints');
        }
    }

    public function test_a_policy_a_controller_chose_itself_is_not_replaced(): void
    {
        config(['svc.security.csp_mode' => 'enforce']);
        $response = (new SecurityHeaders)->handle(Request::create('/x'), static fn () => new Response('<p>x</p>', 200, [
            'Content-Type' => 'text/html',
            'Content-Security-Policy' => "default-src 'none'",
            'Reporting-Endpoints' => 'mine="/m"',
        ]));

        $this->assertSame("default-src 'none'", $response->headers->get('Content-Security-Policy'));
        $this->assertSame('mine="/m"', $response->headers->get('Reporting-Endpoints'));
    }
}
