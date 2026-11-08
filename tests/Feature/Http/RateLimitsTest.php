<?php

namespace Tests\Feature\Http;

use App\Models\User;
use App\Support\Http\RateLimits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

final class RateLimitsTest extends TestCase
{
    use RefreshDatabase;

    public function test_sign_in_routes_answer_429_after_the_ceiling_per_address(): void
    {
        config(['svc.rate_limits.sign-in' => 3]);

        foreach (['/login', '/oauth/redirect', '/oauth/callback'] as $index => $path) {
            // Each route has its own bucket per address only through the shared limiter key,
            // so a fresh address per path keeps the three assertions independent.
            $address = ['REMOTE_ADDR' => '198.51.100.'.($index + 1)];
            for ($attempt = 1; $attempt <= 3; $attempt++) {
                $this->assertNotSame(429, $this->call('GET', $path, [], [], [], $address)->status(), $path.' attempt '.$attempt);
            }
            $this->call('GET', $path, [], [], [], $address)->assertStatus(429)->assertHeader('Retry-After');
        }
    }

    public function test_sign_out_shares_the_sign_in_ceiling(): void
    {
        config(['svc.rate_limits.sign-in' => 2]);
        $address = ['REMOTE_ADDR' => '203.0.113.9'];

        $this->call('POST', '/logout', [], [], [], $address);
        $this->call('POST', '/logout', [], [], [], $address);
        $this->call('POST', '/logout', [], [], [], $address)->assertStatus(429);
        // Another address is unaffected.
        $this->assertNotSame(429, $this->call('POST', '/logout', [], [], [], ['REMOTE_ADDR' => '203.0.113.10'])->status());
    }

    public function test_portal_reads_answer_429_after_the_ceiling_per_person(): void
    {
        config(['svc.rate_limits.portal' => 3]);
        $person = User::factory()->create();
        $other = User::factory()->create();
        $path = '/portal/'.Str::uuid().'/invoices';

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->assertNotSame(429, $this->actingAs($person)->get($path)->status());
        }
        $this->actingAs($person)->get($path)->assertStatus(429);
        // The bucket is the person's, not the address's: a different person on the same address is served.
        $this->assertNotSame(429, $this->actingAs($other)->get($path)->status());
    }

    public function test_portal_writes_have_their_own_lower_ceiling(): void
    {
        config(['svc.rate_limits.portal-writes' => 2, 'svc.rate_limits.portal' => 50]);
        $person = User::factory()->create();
        $company = (string) Str::uuid();

        $this->actingAs($person)->post("/portal/{$company}/autopay");
        $this->actingAs($person)->post("/portal/{$company}/autopay");
        $this->actingAs($person)->post("/portal/{$company}/autopay")->assertStatus(429);
        // Reading is untouched by the write ceiling.
        $this->assertNotSame(429, $this->actingAs($person)->get("/portal/{$company}/invoices")->status());
    }

    public function test_payment_intent_creation_answers_429_after_the_ceiling(): void
    {
        config(['svc.rate_limits.payment-intents' => 2]);
        $person = User::factory()->create();
        $path = '/workspaces/'.Str::uuid().'/invoices/'.Str::uuid().'/stripe-payment-intent';

        $this->actingAs($person)->postJson($path);
        $this->actingAs($person)->postJson($path);
        $this->actingAs($person)->postJson($path)->assertStatus(429);
    }

    public function test_shared_project_invitation_decisions_answer_429_after_the_ceiling(): void
    {
        config(['svc.rate_limits.invitations' => 2]);
        $person = User::factory()->create();
        $path = '/shared-project-invitations/'.Str::uuid().'/accept';

        $this->actingAs($person)->post($path);
        $this->actingAs($person)->post($path);
        $this->actingAs($person)->post($path)->assertStatus(429);
    }

    public function test_every_default_is_generous_and_a_bad_setting_falls_back_to_it(): void
    {
        foreach (RateLimits::DEFAULTS as $name => $default) {
            $this->assertGreaterThanOrEqual(30, $default, $name);
            $this->assertSame($default, RateLimits::attempts($name));
            foreach (['0', '-5', 'many', ''] as $bad) {
                config(['svc.rate_limits.'.$name => $bad]);
                $this->assertSame($default, RateLimits::attempts($name), $name.' with '.var_export($bad, true));
            }
            config(['svc.rate_limits.'.$name => '7']);
            $this->assertSame(7, RateLimits::attempts($name));
        }
    }

    public function test_processor_webhooks_are_never_throttled_by_the_application(): void
    {
        $checked = 0;
        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/webhooks/stripe') && ! str_starts_with($route->uri(), 'api/webhooks/square')) {
                continue;
            }
            $checked++;
            foreach ($route->gatherMiddleware() as $middleware) {
                $this->assertFalse(is_string($middleware) && (str_starts_with($middleware, 'throttle') || str_starts_with($middleware, ThrottleRequests::class)), $route->uri());
            }
        }
        $this->assertSame(4, $checked);
    }

    public function test_every_portal_route_is_throttled_and_every_write_has_the_writes_ceiling(): void
    {
        $portal = 0;
        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'portal/')) {
                continue;
            }
            $portal++;
            $middleware = $route->gatherMiddleware();
            $this->assertContains('throttle:portal', $middleware, $route->uri());
            $isWrite = array_diff($route->methods(), ['GET', 'HEAD']) !== [];
            $this->assertSame($isWrite, in_array('throttle:portal-writes', $middleware, true), $route->uri());
        }
        $this->assertGreaterThan(20, $portal);
    }
}
