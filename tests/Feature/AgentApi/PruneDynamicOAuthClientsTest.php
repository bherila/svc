<?php

namespace Tests\Feature\AgentApi;

use App\Console\Commands\OAuth\PruneDynamicOAuthClientsCommand;
use App\Models\User;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class PruneDynamicOAuthClientsTest extends TestCase
{
    use RefreshDatabase;

    public function test_pruning_removes_only_stale_unused_dynamic_clients_and_their_inactive_credentials(): void
    {
        $stale = $this->client('Stale dynamic', now()->subDays(45));
        $recent = $this->client('Recent dynamic', now()->subDays(5));
        $static = $this->client('Static client', null);
        $active = $this->client('Active dynamic', now()->subDays(45));
        $refreshActive = $this->client('Refresh-active dynamic', now()->subDays(45));
        $user = User::factory()->create();
        $inactiveToken = $this->token($stale->id, $user->id, true, now()->subDay());
        Passport::refreshToken()->forceFill(['id' => Str::random(80), 'access_token_id' => $inactiveToken, 'revoked' => true, 'expires_at' => now()->subDay()])->save();
        $activeToken = $this->token($active->id, $user->id, false, now()->addHour());
        Passport::refreshToken()->forceFill(['id' => Str::random(80), 'access_token_id' => $activeToken, 'revoked' => false, 'expires_at' => now()->addDays(10)])->save();
        $expiredAccess = $this->token($refreshActive->id, $user->id, false, now()->subHour());
        Passport::refreshToken()->forceFill(['id' => Str::random(80), 'access_token_id' => $expiredAccess, 'revoked' => false, 'expires_at' => now()->addDays(10)])->save();

        $this->artisan('svc:oauth:prune-dynamic-clients', ['--apply' => true, '--days' => 30])->assertSuccessful();

        $this->assertDatabaseMissing('oauth_clients', ['id' => $stale->id]);
        $this->assertDatabaseMissing('oauth_access_tokens', ['id' => $inactiveToken]);
        $this->assertDatabaseHas('oauth_clients', ['id' => $recent->id]);
        $this->assertDatabaseHas('oauth_clients', ['id' => $static->id]);
        $this->assertDatabaseHas('oauth_clients', ['id' => $active->id]);
        $this->assertDatabaseHas('oauth_clients', ['id' => $refreshActive->id]);
        $this->assertDatabaseHas('oauth_access_tokens', ['id' => $activeToken]);
    }

    public function test_prune_pretend_and_invalid_retention_are_non_destructive(): void
    {
        $stale = $this->client('Pretend dynamic', now()->subDays(45));

        $this->artisan('svc:oauth:prune-dynamic-clients', ['--days' => 30, '--pretend' => true])
            ->expectsOutput('Would prune 1 stale dynamic OAuth client(s).')
            ->assertSuccessful();
        $this->artisan('svc:oauth:prune-dynamic-clients', ['--apply' => true, '--days' => 0])->assertExitCode(2);
        $this->assertDatabaseHas('oauth_clients', ['id' => $stale->id]);
    }

    public function test_pruning_defers_when_an_active_refresh_token_has_lost_its_access_row(): void
    {
        $client = $this->client('Refresh without access row', now()->subDays(45));
        $user = User::factory()->create();
        $accessToken = $this->token($client->id, $user->id, false, now()->subHour());
        Passport::refreshToken()->forceFill([
            'id' => Str::random(80),
            'access_token_id' => $accessToken,
            'revoked' => false,
            'expires_at' => now()->addDays(10),
        ])->save();
        Passport::token()->newQuery()->whereKey($accessToken)->delete();

        $this->artisan('svc:oauth:prune-dynamic-clients', ['--apply' => true, '--days' => 30])
            ->expectsOutput('An active refresh credential has no access-token row; pruning was deferred.')
            ->assertSuccessful();

        $this->assertDatabaseHas('oauth_clients', ['id' => $client->id]);
    }

    public function test_the_scheduler_runs_the_vendor_prune_and_not_a_copy_of_it(): void
    {
        $commands = collect(app(Schedule::class)->events())
            ->map(fn (Event $event): string => (string) $event->command)
            ->filter(fn (string $command): bool => str_contains($command, 'prune-dynamic-clients'))
            ->values();

        $this->assertCount(1, $commands);
        $this->assertStringContainsString(PruneDynamicOAuthClientsCommand::VENDOR_COMMAND, $commands->sole());
    }

    public static function names(): iterable
    {
        yield 'alias' => ['svc:oauth:prune-dynamic-clients'];
        yield 'vendor' => [PruneDynamicOAuthClientsCommand::VENDOR_COMMAND];
    }

    #[DataProvider('names')]
    public function test_both_names_read_the_one_retention_setting(string $name): void
    {
        config(['bherila-auth.oauth_server.dynamic_clients.retention_days' => 60]);
        $kept = $this->client('Inside a 60-day window', now()->subDays(45));
        $pruned = $this->client('Outside a 60-day window', now()->subDays(75));

        $this->artisan($name, $name === PruneDynamicOAuthClientsCommand::VENDOR_COMMAND ? [] : ['--apply' => true])->expectsOutput('Pruned 1 stale dynamic OAuth client(s).')->assertSuccessful();

        $this->assertDatabaseHas('oauth_clients', ['id' => $kept->id]);
        $this->assertDatabaseMissing('oauth_clients', ['id' => $pruned->id]);
    }

    public function test_the_alias_keeps_a_client_whose_authorization_is_in_progress(): void
    {
        $client = $this->client('Mid-authorization dynamic', now()->subDays(45));
        $user = User::factory()->create();
        Passport::authCode()->forceFill([
            'id' => Str::random(80), 'user_id' => $user->id, 'client_id' => $client->id,
            'scopes' => '[]', 'revoked' => false, 'expires_at' => now()->addMinutes(5),
        ])->saveOrFail();

        $this->artisan('svc:oauth:prune-dynamic-clients', ['--apply' => true, '--days' => 30])
            ->expectsOutput('Pruned 0 stale dynamic OAuth client(s).')
            ->assertSuccessful();

        $this->assertDatabaseHas('oauth_clients', ['id' => $client->id]);
    }

    private function client(string $name, mixed $registeredAt): Client
    {
        $client = Passport::client();
        $client->forceFill([
            'id' => (string) Str::uuid(),
            'name' => $name,
            'secret' => null,
            'provider' => null,
            'redirect_uris' => ['http://localhost/callback'],
            'grant_types' => ['authorization_code', 'refresh_token'],
            'revoked' => false,
            'dynamically_registered_at' => $registeredAt,
            'last_used_at' => $registeredAt,
        ])->saveOrFail();

        return $client;
    }

    private function token(string $clientId, int $userId, bool $revoked, mixed $expiresAt): string
    {
        $id = Str::random(80);
        Passport::token()->forceFill([
            'id' => $id,
            'user_id' => $userId,
            'client_id' => $clientId,
            'scopes' => [],
            'revoked' => $revoked,
            'expires_at' => $expiresAt,
        ])->save();

        return $id;
    }
}
