<?php

namespace Tests\Unit\Http;

use App\Http\Middleware\SecurityHeaders;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Tests\TestCase;

#[CoversClass(SecurityHeaders::class)]
final class SecurityHeadersTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The application defaults to its own collector; the allowlist tests below compare the policy without it.
        config(['svc.security.csp_report_uri' => null]);

        // A null reaching a string function is a deprecation, and reading a missing file a warning; make both fail the test so a
        // missing setting is proven handled, not merely tolerated.
        set_error_handler(static function (int $level, string $message): never {
            throw new \ErrorException($message, 0, $level);
        }, E_DEPRECATED | E_WARNING);
    }

    protected function tearDown(): void
    {
        restore_error_handler();
        @unlink(public_path('hot'));
        parent::tearDown();
    }

    public function test_missing_settings_fall_back_to_the_documented_defaults(): void
    {
        config(['svc.security' => ['csp_report_uri' => null], 'bherila-auth.oauth_client.base_url' => null]);
        $html = new Response('<p>x</p>', 200, ['Content-Type' => 'text/html']);

        $headers = $this->pass('https://svc.example.test/x', $html)->headers;

        $this->assertSame('max-age=31536000', $headers->get('Strict-Transport-Security'));
        $policy = (string) $headers->get('Content-Security-Policy-Report-Only');
        $this->assertStringStartsWith("default-src 'self'; script-src", $policy);
        $this->assertStringEndsWith("form-action 'self'", $policy);
        $this->assertStringNotContainsString('report-uri', $policy);
    }

    public function test_a_fractional_age_counts_as_its_whole_part(): void
    {
        config(['svc.security.hsts_max_age' => '0.5']);
        $this->assertFalse($this->pass('https://svc.example.test/x')->headers->has('Strict-Transport-Security'));

        config(['svc.security.hsts_max_age' => '90.9']);
        $this->assertSame('max-age=90', $this->pass('https://svc.example.test/x')->headers->get('Strict-Transport-Security'));
    }

    public function test_a_response_without_a_content_type_gets_no_policy(): void
    {
        $response = new Response('x');
        $response->headers->remove('Content-Type');

        $this->assertFalse($this->pass('http://svc.example.test/x', $response)->headers->has('Content-Security-Policy-Report-Only'));
    }

    public function test_the_report_endpoint_extends_the_policy_it_does_not_replace_it(): void
    {
        config(['svc.security.csp_report_uri' => '/csp-reports', 'bherila-auth.oauth_client.base_url' => 'https://identity.example.test']);
        $html = new Response('<p>x</p>', 200, ['Content-Type' => 'text/html']);

        $policy = (string) $this->pass('http://svc.example.test/x', $html)->headers->get('Content-Security-Policy-Report-Only');

        $this->assertStringStartsWith("default-src 'self'; script-src", $policy);
        $this->assertStringEndsWith("form-action 'self' https://identity.example.test; report-uri /csp-reports; report-to csp-endpoint", $policy);
    }

    public function test_surrounding_whitespace_in_the_hot_file_is_ignored(): void
    {
        file_put_contents(public_path('hot'), "\n  http://[::1]:5173 \n\n");

        $this->assertStringContainsString('ws://[::1]:5173;', (new SecurityHeaders)->contentSecurityPolicy('N0nce'));
    }

    public function test_the_fixed_headers_are_set_on_any_response(): void
    {
        $headers = $this->pass('http://svc.example.test/x', new Response('{}', 200, ['Content-Type' => 'application/json']))->headers;

        $this->assertSame('nosniff', $headers->get('X-Content-Type-Options'));
        $this->assertSame('strict-origin-when-cross-origin', $headers->get('Referrer-Policy'));
        $this->assertSame('DENY', $headers->get('X-Frame-Options'));
        $this->assertSame('camera=(), microphone=(), geolocation=(), usb=(), interest-cohort=()', $headers->get('Permissions-Policy'));
        $this->assertFalse($headers->has('Strict-Transport-Security'));
        $this->assertFalse($headers->has('Content-Security-Policy-Report-Only'));
        $this->assertFalse($headers->has('Content-Security-Policy'));
    }

    public function test_headers_a_response_chose_are_never_overwritten(): void
    {
        $headers = $this->pass('https://svc.example.test/x', new Response('<p>x</p>', 200, [
            'Content-Type' => 'text/html',
            'X-Content-Type-Options' => 'custom',
            'Referrer-Policy' => 'no-referrer',
            'Permissions-Policy' => 'camera=(self)',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Strict-Transport-Security' => 'max-age=1',
            'Content-Security-Policy-Report-Only' => 'default-src none',
        ]))->headers;

        $this->assertSame('custom', $headers->get('X-Content-Type-Options'));
        $this->assertSame('no-referrer', $headers->get('Referrer-Policy'));
        $this->assertSame('camera=(self)', $headers->get('Permissions-Policy'));
        $this->assertSame('SAMEORIGIN', $headers->get('X-Frame-Options'));
        $this->assertSame('max-age=1', $headers->get('Strict-Transport-Security'));
        $this->assertSame('default-src none', $headers->get('Content-Security-Policy-Report-Only'));
    }

    public function test_hsts_needs_https_and_a_positive_age(): void
    {
        $this->assertSame('max-age=31536000', $this->pass('https://svc.example.test/x')->headers->get('Strict-Transport-Security'));
        $this->assertFalse($this->pass('http://svc.example.test/x')->headers->has('Strict-Transport-Security'));

        config(['svc.security.hsts_max_age' => 90]);
        $this->assertSame('max-age=90', $this->pass('https://svc.example.test/x')->headers->get('Strict-Transport-Security'));

        foreach ([0, -5] as $off) {
            config(['svc.security.hsts_max_age' => $off]);
            $this->assertFalse($this->pass('https://svc.example.test/x')->headers->has('Strict-Transport-Security'), (string) $off);
        }
    }

    public function test_the_policy_is_html_only_and_switchable(): void
    {
        $html = new Response('<p>x</p>', 200, ['Content-Type' => 'text/html; charset=UTF-8']);
        $headers = $this->pass('http://svc.example.test/x', $html)->headers;
        $this->assertTrue($headers->has('Content-Security-Policy-Report-Only'));
        $this->assertFalse($headers->has('Content-Security-Policy'));

        $this->assertFalse($this->pass('http://svc.example.test/x', new Response('x', 200, ['Content-Type' => 'text/plain']))->headers->has('Content-Security-Policy-Report-Only'));
        $this->assertFalse($this->pass('http://svc.example.test/x', new Response('x', 200, ['Content-Type' => 'application/json; text/html']))->headers->has('Content-Security-Policy-Report-Only'));

        config(['svc.security.csp_mode' => 'off']);
        $off = $this->pass('http://svc.example.test/x', new Response('<p>x</p>', 200, ['Content-Type' => 'text/html']))->headers;
        $this->assertFalse($off->has('Content-Security-Policy-Report-Only'));
        $this->assertFalse($off->has('Content-Security-Policy'));
        $this->assertFalse($off->has('Reporting-Endpoints'));
    }

    public function test_the_policy_text_is_exactly_the_documented_allowlist(): void
    {
        config(['bherila-auth.oauth_client.base_url' => 'https://identity.example.test:8443/base']);

        $this->assertSame(
            "default-src 'self'; "
            ."script-src 'self' 'nonce-N0nce' https://js.stripe.com https://*.js.stripe.com; "
            ."style-src 'self' 'unsafe-inline'; "
            ."img-src 'self' data: blob: https://*.stripe.com; "
            ."font-src 'self' data:; "
            ."connect-src 'self' https://api.stripe.com; "
            .'frame-src https://js.stripe.com https://*.js.stripe.com https://hooks.stripe.com; '
            ."object-src 'none'; "
            ."base-uri 'self'; "
            ."form-action 'self' https://identity.example.test:8443",
            (new SecurityHeaders)->contentSecurityPolicy('N0nce'),
        );
    }

    public function test_an_unusable_provider_address_adds_no_form_target(): void
    {
        foreach (['', 'not a url', 'ftp://identity.example.test', '//identity.example.test', 'https:///path'] as $bad) {
            config(['bherila-auth.oauth_client.base_url' => $bad]);
            $this->assertStringEndsWith("base-uri 'self'; form-action 'self'", (new SecurityHeaders)->contentSecurityPolicy('N0nce'), $bad);
        }

        config(['bherila-auth.oauth_client.base_url' => 'http://localhost:9000']);
        $this->assertStringEndsWith("form-action 'self' http://localhost:9000", (new SecurityHeaders)->contentSecurityPolicy('N0nce'));
    }

    public function test_the_dev_server_joins_the_policy_only_while_its_hot_file_names_a_web_address(): void
    {
        file_put_contents(public_path('hot'), "http://[::1]:5173\n");
        $policy = (new SecurityHeaders)->contentSecurityPolicy('N0nce');
        $this->assertStringContainsString("script-src 'self' 'nonce-N0nce' https://js.stripe.com https://*.js.stripe.com http://[::1]:5173;", $policy);
        $this->assertStringContainsString("style-src 'self' 'unsafe-inline' http://[::1]:5173;", $policy);
        $this->assertStringContainsString("img-src 'self' data: blob: https://*.stripe.com http://[::1]:5173;", $policy);
        $this->assertStringContainsString("font-src 'self' data: http://[::1]:5173;", $policy);
        $this->assertStringContainsString("connect-src 'self' https://api.stripe.com http://[::1]:5173 ws://[::1]:5173;", $policy);

        file_put_contents(public_path('hot'), 'https://dev.example.test');
        $this->assertStringContainsString('https://api.stripe.com https://dev.example.test wss://dev.example.test;', (new SecurityHeaders)->contentSecurityPolicy('N0nce'));

        foreach (['javascript:alert(1)', 'ftp://dev.example.test', 'nonsense', 'http://'] as $bad) {
            file_put_contents(public_path('hot'), $bad);
            $this->assertStringNotContainsString('dev.example', (new SecurityHeaders)->contentSecurityPolicy('N0nce'), $bad);
            $this->assertStringContainsString("connect-src 'self' https://api.stripe.com;", (new SecurityHeaders)->contentSecurityPolicy('N0nce'), $bad);
        }

        unlink(public_path('hot'));
        $this->assertStringContainsString("connect-src 'self' https://api.stripe.com;", (new SecurityHeaders)->contentSecurityPolicy('N0nce'));
    }

    public function test_a_report_endpoint_is_appended_only_when_well_formed(): void
    {
        foreach (['https://reports.example.test/csp', 'https://reports.example.test:8443/csp?x=1', '/csp-reports'] as $good) {
            config(['svc.security.csp_report_uri' => $good]);
            $this->assertStringEndsWith("; report-uri {$good}; report-to csp-endpoint", (new SecurityHeaders)->contentSecurityPolicy('N0nce'), $good);
        }

        foreach ([null, '', 'http://reports.example.test/csp', 'https://reports.example.test', 'https://reports.example.test/csp; script-src *', "/a\nb", '/a b', 'javascript:1', '//evil.example.test/x'] as $bad) {
            config(['svc.security.csp_report_uri' => $bad]);
            $this->assertStringNotContainsString('report-uri', (new SecurityHeaders)->contentSecurityPolicy('N0nce'), var_export($bad, true));
        }
    }

    public function test_the_reporting_endpoint_is_advertised_beside_the_policy_and_a_preset_one_is_kept(): void
    {
        $html = static fn (array $headers = []): Response => new Response('<p>x</p>', 200, ['Content-Type' => 'text/html'] + $headers);

        config(['svc.security.csp_report_uri' => '/csp-reports']);
        $this->assertSame('csp-endpoint="/csp-reports"', $this->pass('http://svc.example.test/x', $html())->headers->get('Reporting-Endpoints'));

        config(['svc.security.csp_report_uri' => 'https://reports.example.test:8443/csp?x=1']);
        $this->assertSame('csp-endpoint="https://reports.example.test:8443/csp?x=1"', $this->pass('http://svc.example.test/x', $html())->headers->get('Reporting-Endpoints'));

        $this->assertSame('mine="/m"', $this->pass('http://svc.example.test/x', $html(['Reporting-Endpoints' => 'mine="/m"']))->headers->get('Reporting-Endpoints'));

        foreach ([null, '', 'javascript:1', 'http://reports.example.test/csp'] as $unusable) {
            config(['svc.security.csp_report_uri' => $unusable]);
            $this->assertFalse($this->pass('http://svc.example.test/x', $html())->headers->has('Reporting-Endpoints'), var_export($unusable, true));
        }

        config(['svc.security.csp_report_uri' => '/csp-reports']);
        $this->assertFalse($this->pass('http://svc.example.test/x')->headers->has('Reporting-Endpoints'), 'a non-HTML response advertises nothing');
        config(['svc.security.csp_mode' => 'off']);
        $this->assertFalse($this->pass('http://svc.example.test/x', $html())->headers->has('Reporting-Endpoints'));
    }

    public function test_enforce_mode_sets_the_enforcing_header_only_and_never_overwrites_a_chosen_one(): void
    {
        config(['svc.security.csp_mode' => 'enforce']);
        $headers = $this->pass('http://svc.example.test/x', new Response('<p>x</p>', 200, ['Content-Type' => 'text/html']))->headers;
        $this->assertStringStartsWith("default-src 'self'; script-src 'self' 'nonce-", (string) $headers->get('Content-Security-Policy'));
        $this->assertFalse($headers->has('Content-Security-Policy-Report-Only'));

        $kept = $this->pass('http://svc.example.test/x', new Response('<p>x</p>', 200, ['Content-Type' => 'text/html', 'Content-Security-Policy' => 'default-src none']))->headers;
        $this->assertSame('default-src none', $kept->get('Content-Security-Policy'));
        $this->assertFalse($kept->has('Content-Security-Policy-Report-Only'));
    }

    private function pass(string $url, ?SymfonyResponse $response = null): SymfonyResponse
    {
        $response ??= new Response('ok', 200, ['Content-Type' => 'text/plain']);

        return (new SecurityHeaders)->handle(Request::create($url), static fn (): SymfonyResponse => $response);
    }
}
