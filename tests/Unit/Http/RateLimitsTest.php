<?php

namespace Tests\Unit\Http;

use App\Providers\AppServiceProvider;
use App\Support\Http\RateLimits;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

#[CoversClass(RateLimits::class)]
#[CoversClass(AppServiceProvider::class)]
final class RateLimitsTest extends TestCase
{
    public function test_every_named_limiter_is_registered_with_its_default_ceiling_per_minute(): void
    {
        $this->assertSame(['sign-in', 'portal', 'portal-writes', 'payment-intents', 'invitations', 'csp-reports', 'public-packages'], array_keys(RateLimits::DEFAULTS));

        foreach (RateLimits::DEFAULTS as $name => $default) {
            $limiter = RateLimiter::limiter($name);
            $this->assertNotNull($limiter, $name);
            $limit = $limiter($this->request('198.51.100.7'));
            $this->assertInstanceOf(Limit::class, $limit);
            $this->assertSame($default, $limit->maxAttempts, $name);
            $this->assertSame(60, $limit->decaySeconds, $name);
        }
    }

    public function test_the_ceiling_follows_configuration(): void
    {
        config(['svc.rate_limits.portal' => '12']);

        $this->assertSame(12, RateLimiter::limiter('portal')($this->request('198.51.100.7'))->maxAttempts);
        $this->assertSame(RateLimits::DEFAULTS['sign-in'], RateLimiter::limiter('sign-in')($this->request('198.51.100.7'))->maxAttempts);
    }

    public function test_an_anonymous_request_is_keyed_by_limiter_and_address(): void
    {
        $this->assertSame('portal|ip:198.51.100.7', RateLimiter::limiter('portal')($this->request('198.51.100.7'))->key);
        $this->assertSame('portal-writes|ip:198.51.100.7', RateLimiter::limiter('portal-writes')($this->request('198.51.100.7'))->key);
        $this->assertNotSame(
            RateLimiter::limiter('portal')($this->request('198.51.100.7'))->key,
            RateLimiter::limiter('portal')($this->request('198.51.100.8'))->key,
        );
    }

    public function test_a_signed_in_person_is_keyed_by_identity_but_sign_in_stays_per_address(): void
    {
        $request = $this->request('198.51.100.7');
        $request->setUserResolver(static fn (): object => new class
        {
            public function getAuthIdentifier(): int
            {
                return 42;
            }
        });

        $this->assertSame('portal|user:42', RateLimiter::limiter('portal')($request)->key);
        $this->assertSame('invitations|user:42', RateLimiter::limiter('invitations')($request)->key);
        $this->assertSame('sign-in|ip:198.51.100.7', RateLimiter::limiter('sign-in')($request)->key);
    }

    public function test_a_missing_address_shares_one_unknown_bucket(): void
    {
        $request = Request::create('/x');
        $request->server->remove('REMOTE_ADDR');

        $this->assertSame('portal|ip:unknown', RateLimiter::limiter('portal')($request)->key);
    }

    public function test_attempts_ignore_settings_that_are_not_positive_numbers(): void
    {
        foreach ([null, '', '0', '0.5', '-3', 'many', 0, -1] as $bad) {
            config(['svc.rate_limits.invitations' => $bad]);
            $this->assertSame(30, RateLimits::attempts('invitations'), var_export($bad, true));
        }
        config(['svc.rate_limits.invitations' => '1']);
        $this->assertSame(1, RateLimits::attempts('invitations'));
        config(['svc.rate_limits.invitations' => '12.9']);
        $this->assertSame(12, RateLimits::attempts('invitations'));
        config(['svc.rate_limits.invitations' => 99]);
        $this->assertSame(99, RateLimits::attempts('invitations'));
    }

    private function request(string $address): Request
    {
        return Request::create('/x', 'GET', server: ['REMOTE_ADDR' => $address]);
    }
}
