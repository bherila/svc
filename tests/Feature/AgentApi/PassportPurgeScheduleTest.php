<?php

namespace Tests\Feature\AgentApi;

use App\Models\User;
use App\Services\Credentials\CredentialRevoker;
use Carbon\CarbonInterval;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * The scheduled `passport:purge` (#458): expired rows only, kept a day past
 * the refresh-token lifetime after their own expiry, so the purge never
 * leaves a live refresh token without the access token that names its owner
 * and client, and never removes a revoked row while it is still evidence.
 */
final class PassportPurgeScheduleTest extends TestCase
{
    use RefreshDatabase;

    private const string COMMAND = 'passport:purge --expired --hours=744';

    public function test_the_purge_runs_daily_on_expired_rows_kept_past_the_refresh_token_lifetime(): void
    {
        $events = array_values(array_filter(app(Schedule::class)->events(), static fn (ScheduledEvent $event): bool => str_contains((string) $event->command, 'passport:purge')));

        $this->assertCount(1, $events);
        $this->assertStringEndsWith(self::COMMAND, (string) $events[0]->command);
        $this->assertSame('0 0 * * *', $events[0]->expression);
        $this->assertTrue($events[0]->withoutOverlapping);
        $this->assertSame(30, $events[0]->expiresAt);

        // The retention outlasts every refresh token, with a day to spare: an
        // access token is purged only once each refresh token issued with it
        // has expired. Lengthening the refresh lifetime without the purge fails here.
        $retained = CarbonInterval::hours(744)->totalSeconds;
        $refreshLifetime = CarbonInterval::instance(Passport::refreshTokensExpireIn())->totalSeconds;
        $this->assertGreaterThanOrEqual($refreshLifetime + 86400, $retained);
    }

    public function test_it_removes_only_rows_whose_refresh_lineage_has_lapsed(): void
    {
        $this->freezeSecond();
        $person = User::factory()->create();
        $client = (string) Str::uuid();
        // Long gone: a revoked connection whose refresh token and code expired over 31 days ago.
        $stale = $this->accessToken($person, $client, now()->subHours(745), revoked: true);
        $staleRefresh = $this->refreshToken($stale, now()->subHours(745), revoked: true);
        $staleCode = $this->authCode($person, $client, now()->subHours(745));
        // The deployment smoke's: revoked an hour ago, kept for the investigation.
        $smoke = $this->accessToken($person, $client, now()->subHour(), revoked: true);
        // A legacy connection: its access token long expired, its refresh token
        // (which records no owner) still live, and reachable only through it.
        $legacy = $this->accessToken($person, $client, now()->subHours(700));
        $legacyRefresh = $this->refreshToken($legacy, now()->addHours(20));

        $this->assertSame(0, Artisan::call(self::COMMAND));

        $this->assertFalse(Passport::token()->newQuery()->whereKey($stale)->exists());
        $this->assertFalse(Passport::refreshToken()->newQuery()->whereKey($staleRefresh)->exists());
        $this->assertFalse(Passport::authCode()->newQuery()->whereKey($staleCode)->exists());
        $this->assertSame([$legacy, $smoke], Passport::token()->newQuery()->orderBy('expires_at')->pluck('id')->all());
        $this->assertSame([$legacyRefresh], Passport::refreshToken()->newQuery()->pluck('id')->all());
        $this->assertSame(0, app(CredentialRevoker::class)->unattributedRefreshTokens());
        $this->assertSame(0, Artisan::call('svc:oauth:prune-dynamic-clients', ['--apply' => true]));
        $this->assertStringNotContainsString('deferred', Artisan::output());

        // Passport's default takes revoked rows at once and expired ones after
        // a week: the smoke's evidence is gone, and the legacy refresh token
        // is left belonging to no one a revocation by person can name.
        $this->assertSame(0, Artisan::call('passport:purge'));

        $this->assertFalse(Passport::token()->newQuery()->exists());
        $this->assertSame(1, app(CredentialRevoker::class)->unattributedRefreshTokens());
        $this->assertSame(0, Artisan::call('svc:oauth:prune-dynamic-clients', ['--apply' => true]));
        $this->assertStringContainsString('deferred', Artisan::output());
    }

    private function accessToken(User $user, string $client, \DateTimeInterface $expiresAt, bool $revoked = false): string
    {
        $id = Str::random(80);
        Passport::token()->forceFill([
            'id' => $id, 'user_id' => $user->id, 'client_id' => $client, 'scopes' => [],
            'revoked' => $revoked, 'expires_at' => $expiresAt,
        ])->saveOrFail();

        return $id;
    }

    private function refreshToken(string $accessTokenId, \DateTimeInterface $expiresAt, bool $revoked = false): string
    {
        $id = Str::random(80);
        Passport::refreshToken()->forceFill([
            'id' => $id, 'access_token_id' => $accessTokenId, 'revoked' => $revoked, 'expires_at' => $expiresAt,
        ])->saveOrFail();

        return $id;
    }

    private function authCode(User $user, string $client, \DateTimeInterface $expiresAt): string
    {
        $id = Str::random(80);
        Passport::authCode()->forceFill([
            'id' => $id, 'user_id' => $user->id, 'client_id' => $client, 'scopes' => '[]',
            'revoked' => false, 'expires_at' => $expiresAt,
        ])->saveOrFail();

        return $id;
    }
}
