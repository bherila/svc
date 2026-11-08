<?php

namespace Tests\Feature\Http;

use App\Http\Middleware\SecurityHeaders;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Tests\TestCase;

final class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    private const array ALWAYS = [
        'X-Content-Type-Options' => 'nosniff',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'X-Frame-Options' => 'DENY',
        'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), usb=(), interest-cohort=()',
    ];

    protected function tearDown(): void
    {
        @unlink(public_path('hot'));
        parent::tearDown();
    }

    public function test_a_web_page_carries_every_header_and_a_report_only_policy(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        foreach (self::ALWAYS as $name => $value) {
            $response->assertHeader($name, $value);
        }
        $response->assertHeaderMissing('Content-Security-Policy');
        $this->assertStringStartsWith('default-src \'self\'; script-src', (string) $response->headers->get('Content-Security-Policy-Report-Only'));
    }

    public function test_the_signed_in_portal_and_workspace_pages_carry_them_too(): void
    {
        $response = $this->actingAs(User::factory()->create())->get('/portal/'.Str::uuid());

        foreach (self::ALWAYS as $name => $value) {
            $response->assertHeader($name, $value);
        }
    }

    public function test_api_and_webhook_responses_carry_the_headers_but_no_page_policy(): void
    {
        $responses = [
            $this->getJson('/api/v1/workspaces'),
            $this->postJson('/api/webhooks/stripe', []),
            $this->postJson('/api/webhooks/square', []),
            $this->getJson('/api/openapi.json'),
        ];

        foreach ($responses as $response) {
            foreach (self::ALWAYS as $name => $value) {
                $response->assertHeader($name, $value);
            }
            $response->assertHeaderMissing('Content-Security-Policy-Report-Only');
        }
    }

    public function test_strict_transport_security_is_sent_only_over_https_and_without_preload(): void
    {
        $this->get('/')->assertHeaderMissing('Strict-Transport-Security');

        $secure = $this->get('https://localhost/');
        $secure->assertHeader('Strict-Transport-Security', 'max-age=31536000');
        $this->assertStringNotContainsString('preload', (string) $secure->headers->get('Strict-Transport-Security'));
        $this->assertStringNotContainsString('includeSubDomains', (string) $secure->headers->get('Strict-Transport-Security'));
    }

    public function test_hsts_can_be_switched_off_or_shortened(): void
    {
        config(['svc.security.hsts_max_age' => 0]);
        $this->get('https://localhost/')->assertHeaderMissing('Strict-Transport-Security');

        config(['svc.security.hsts_max_age' => 600]);
        $this->get('https://localhost/')->assertHeader('Strict-Transport-Security', 'max-age=600');
    }

    public function test_the_policy_allowlist_covers_stripe(): void
    {
        config(['bherila-auth.oauth_client.base_url' => 'https://identity.example.test']);
        $policy = (string) $this->get('/')->headers->get('Content-Security-Policy-Report-Only');

        $this->assertMatchesRegularExpression("/script-src 'self' 'nonce-[A-Za-z0-9]{40}' https:\/\/js\.stripe\.com https:\/\/\*\.js\.stripe\.com;/", $policy);
        $this->assertStringContainsString('connect-src \'self\' https://api.stripe.com;', $policy);
        $this->assertStringContainsString('frame-src https://js.stripe.com https://*.js.stripe.com https://hooks.stripe.com;', $policy);
        $this->assertStringContainsString('img-src \'self\' data: blob: https://*.stripe.com;', $policy);
        $this->assertStringContainsString('font-src \'self\' data:;', $policy);
        $this->assertStringContainsString("style-src 'self' 'unsafe-inline';", $policy);
        $this->assertStringContainsString("object-src 'none'; base-uri 'self'; form-action 'self' https://identity.example.test", $policy);
        $this->assertStringNotContainsString('ws://', $policy);
        $this->assertStringEndsWith('; report-uri /api/csp-reports; report-to csp-endpoint', $policy);
    }

    public function test_the_vite_dev_server_is_allowed_only_while_it_is_running(): void
    {
        file_put_contents(public_path('hot'), "http://[::1]:5173\n");
        $policy = (string) $this->get('/')->headers->get('Content-Security-Policy-Report-Only');

        $this->assertMatchesRegularExpression("/script-src 'self' 'nonce-[A-Za-z0-9]{40}' https:\/\/js\.stripe\.com https:\/\/\*\.js\.stripe\.com http:\/\/\[::1\]:5173;/", $policy);
        $this->assertStringContainsString("connect-src 'self' https://api.stripe.com http://[::1]:5173 ws://[::1]:5173;", $policy);

        file_put_contents(public_path('hot'), 'https://dev.example.test');
        $this->assertStringContainsString('wss://dev.example.test', (string) $this->get('/')->headers->get('Content-Security-Policy-Report-Only'));

        file_put_contents(public_path('hot'), 'javascript:alert(1)');
        $this->assertStringNotContainsString('javascript', (string) $this->get('/')->headers->get('Content-Security-Policy-Report-Only'));

        unlink(public_path('hot'));
        $this->assertStringNotContainsString('5173', (string) $this->get('/')->headers->get('Content-Security-Policy-Report-Only'));
    }

    public function test_a_report_endpoint_is_added_only_when_it_is_well_formed(): void
    {
        config(['svc.security.csp_report_uri' => 'https://reports.example.test/csp']);
        $this->assertStringEndsWith('; report-uri https://reports.example.test/csp; report-to csp-endpoint', (string) $this->get('/')->headers->get('Content-Security-Policy-Report-Only'));

        config(['svc.security.csp_report_uri' => '/csp-reports']);
        $this->assertStringEndsWith('; report-uri /csp-reports; report-to csp-endpoint', (string) $this->get('/')->headers->get('Content-Security-Policy-Report-Only'));

        foreach (['http://reports.example.test/csp', 'https://reports.example.test/csp; script-src *', "/x\ny", 'javascript:1'] as $bad) {
            config(['svc.security.csp_report_uri' => $bad]);
            $this->assertStringNotContainsString('report-uri', (string) $this->get('/')->headers->get('Content-Security-Policy-Report-Only'), $bad);
        }
    }

    public function test_a_header_a_controller_already_chose_is_kept(): void
    {
        $response = new Response('<html></html>', 200, [
            'Content-Type' => 'text/html',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Referrer-Policy' => 'no-referrer',
            'Strict-Transport-Security' => 'max-age=1',
            'Content-Security-Policy-Report-Only' => 'default-src none',
        ]);
        $request = Request::create('https://svc.example.test/x');

        $result = (new SecurityHeaders)->handle($request, fn (): Response => $response);

        $this->assertSame('SAMEORIGIN', $result->headers->get('X-Frame-Options'));
        $this->assertSame('no-referrer', $result->headers->get('Referrer-Policy'));
        $this->assertSame('max-age=1', $result->headers->get('Strict-Transport-Security'));
        $this->assertSame('default-src none', $result->headers->get('Content-Security-Policy-Report-Only'));
        $this->assertSame('nosniff', $result->headers->get('X-Content-Type-Options'));
    }
}
