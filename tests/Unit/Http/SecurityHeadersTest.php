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

        // A null reaching a string function is a deprecation in PHP; make it fail the test so a
        // missing setting is proven handled, not merely tolerated.
        set_error_handler(static function (int $level, string $message): never {
            throw new \ErrorException($message, 0, $level);
        }, E_DEPRECATED);
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
        $this->assertStringEndsWith("form-action 'self' https://identity.example.test; report-uri /csp-reports", $policy);
    }

    public function test_surrounding_whitespace_in_the_hot_file_is_ignored(): void
    {
        file_put_contents(public_path('hot'), "\n  http://[::1]:5173 \n\n");

        $this->assertStringContainsString('ws://[::1]:5173;', (new SecurityHeaders)->contentSecurityPolicy());
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

    public function test_the_policy_is_report_only_html_only_and_switchable(): void
    {
        $html = new Response('<p>x</p>', 200, ['Content-Type' => 'text/html; charset=UTF-8']);
        $headers = $this->pass('http://svc.example.test/x', $html)->headers;
        $this->assertTrue($headers->has('Content-Security-Policy-Report-Only'));
        $this->assertFalse($headers->has('Content-Security-Policy'));

        $this->assertFalse($this->pass('http://svc.example.test/x', new Response('x', 200, ['Content-Type' => 'text/plain']))->headers->has('Content-Security-Policy-Report-Only'));
        $this->assertFalse($this->pass('http://svc.example.test/x', new Response('x', 200, ['Content-Type' => 'application/json; text/html']))->headers->has('Content-Security-Policy-Report-Only'));

        config(['svc.security.csp_report_only' => false]);
        $this->assertFalse($this->pass('http://svc.example.test/x', new Response('<p>x</p>', 200, ['Content-Type' => 'text/html']))->headers->has('Content-Security-Policy-Report-Only'));
    }

    public function test_the_policy_text_is_exactly_the_documented_allowlist(): void
    {
        config(['bherila-auth.oauth_client.base_url' => 'https://identity.example.test:8443/base']);

        $this->assertSame(
            "default-src 'self'; "
            ."script-src 'self' 'unsafe-inline' https://js.stripe.com; "
            ."style-src 'self' 'unsafe-inline' https://fonts.bunny.net; "
            ."img-src 'self' data: blob: https://*.stripe.com; "
            ."font-src 'self' data: https://fonts.bunny.net; "
            ."connect-src 'self' https://api.stripe.com; "
            .'frame-src https://js.stripe.com https://hooks.stripe.com; '
            ."object-src 'none'; "
            ."base-uri 'self'; "
            ."form-action 'self' https://identity.example.test:8443",
            (new SecurityHeaders)->contentSecurityPolicy(),
        );
    }

    public function test_an_unusable_provider_address_adds_no_form_target(): void
    {
        foreach (['', 'not a url', 'ftp://identity.example.test', '//identity.example.test', 'https:///path'] as $bad) {
            config(['bherila-auth.oauth_client.base_url' => $bad]);
            $this->assertStringEndsWith("base-uri 'self'; form-action 'self'", (new SecurityHeaders)->contentSecurityPolicy(), $bad);
        }

        config(['bherila-auth.oauth_client.base_url' => 'http://localhost:9000']);
        $this->assertStringEndsWith("form-action 'self' http://localhost:9000", (new SecurityHeaders)->contentSecurityPolicy());
    }

    public function test_the_dev_server_joins_the_policy_only_while_its_hot_file_names_a_web_address(): void
    {
        file_put_contents(public_path('hot'), "http://[::1]:5173\n");
        $policy = (new SecurityHeaders)->contentSecurityPolicy();
        $this->assertStringContainsString("script-src 'self' 'unsafe-inline' https://js.stripe.com http://[::1]:5173;", $policy);
        $this->assertStringContainsString("style-src 'self' 'unsafe-inline' https://fonts.bunny.net http://[::1]:5173;", $policy);
        $this->assertStringContainsString("img-src 'self' data: blob: https://*.stripe.com http://[::1]:5173;", $policy);
        $this->assertStringContainsString("font-src 'self' data: https://fonts.bunny.net http://[::1]:5173;", $policy);
        $this->assertStringContainsString("connect-src 'self' https://api.stripe.com http://[::1]:5173 ws://[::1]:5173;", $policy);

        file_put_contents(public_path('hot'), 'https://dev.example.test');
        $this->assertStringContainsString('https://api.stripe.com https://dev.example.test wss://dev.example.test;', (new SecurityHeaders)->contentSecurityPolicy());

        foreach (['javascript:alert(1)', 'ftp://dev.example.test', 'nonsense', 'http://'] as $bad) {
            file_put_contents(public_path('hot'), $bad);
            $this->assertStringNotContainsString('dev.example', (new SecurityHeaders)->contentSecurityPolicy(), $bad);
            $this->assertStringContainsString("connect-src 'self' https://api.stripe.com;", (new SecurityHeaders)->contentSecurityPolicy(), $bad);
        }

        unlink(public_path('hot'));
        $this->assertStringContainsString("connect-src 'self' https://api.stripe.com;", (new SecurityHeaders)->contentSecurityPolicy());
    }

    public function test_a_report_endpoint_is_appended_only_when_well_formed(): void
    {
        foreach (['https://reports.example.test/csp', 'https://reports.example.test:8443/csp?x=1', '/csp-reports'] as $good) {
            config(['svc.security.csp_report_uri' => $good]);
            $this->assertStringEndsWith("; report-uri {$good}", (new SecurityHeaders)->contentSecurityPolicy(), $good);
        }

        foreach ([null, '', 'http://reports.example.test/csp', 'https://reports.example.test', 'https://reports.example.test/csp; script-src *', "/a\nb", '/a b', 'javascript:1', '//evil.example.test/x'] as $bad) {
            config(['svc.security.csp_report_uri' => $bad]);
            $this->assertStringNotContainsString('report-uri', (new SecurityHeaders)->contentSecurityPolicy(), var_export($bad, true));
        }
    }

    private function pass(string $url, ?SymfonyResponse $response = null): SymfonyResponse
    {
        $response ??= new Response('ok', 200, ['Content-Type' => 'text/plain']);

        return (new SecurityHeaders)->handle(Request::create($url), static fn (): SymfonyResponse => $response);
    }
}
