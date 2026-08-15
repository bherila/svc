<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class IssuePersonalAccessTokenCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_issues_one_expiring_token_for_the_exact_user_with_explicit_abilities(): void
    {
        $user = User::factory()->create(['email' => 'token-owner@synthetic.test']);
        $otherUser = User::factory()->create(['email' => 'other-user@synthetic.test']);
        $expiresAt = Carbon::now()->addDays(7)->startOfMinute();

        $exitCode = Artisan::call('svc:auth:issue-token', [
            'user_public_id' => $user->public_id,
            'name' => 'Synthetic reconciliation client',
            'abilities' => ['finance.read', 'finance.reconcile', 'finance.read'],
            '--expires-at' => $expiresAt->toIso8601String(),
        ]);

        $output = Artisan::output();
        preg_match('/^Token \(shown once\): ([^\r\n]+)$/m', $output, $matches);

        $this->assertSame(0, $exitCode);
        $this->assertCount(2, $matches);
        $plainTextToken = $matches[1];
        $token = PersonalAccessToken::query()->sole();

        $this->assertSame($user->id, $token->tokenable_id);
        $this->assertSame(User::class, $token->tokenable_type);
        $this->assertSame('Synthetic reconciliation client', $token->name);
        $this->assertSame(['finance.read', 'finance.reconcile'], $token->abilities);
        $this->assertTrue($token->expires_at->equalTo($expiresAt));
        $this->assertStringContainsString('|', $plainTextToken);
        $this->assertSame(hash('sha256', explode('|', $plainTextToken, 2)[1]), $token->token);
        $this->assertNotSame($plainTextToken, $token->token);
        $this->assertTrue($token->can('finance.read'));
        $this->assertTrue($token->can('finance.reconcile'));
        $this->assertTrue($token->cant('finance.write'));
        $this->assertArrayNotHasKey('token', $token->toArray());
        $this->assertSame(1, substr_count($output, $plainTextToken));
        $this->assertStringNotContainsString($user->email, $output);
        $this->assertNotSame($otherUser->id, $token->tokenable_id);
    }

    public function test_it_rejects_missing_or_non_future_expiration_without_issuing_a_token(): void
    {
        $user = User::factory()->create(['email' => 'token-validation@synthetic.test']);

        Artisan::call('svc:auth:issue-token', [
            'user_public_id' => $user->public_id,
            'name' => 'Missing expiry',
            'abilities' => ['finance.read'],
        ]);

        $this->assertSame(0, PersonalAccessToken::query()->count());

        $exitCode = Artisan::call('svc:auth:issue-token', [
            'user_public_id' => $user->public_id,
            'name' => 'Expired token',
            'abilities' => ['finance.read'],
            '--expires-at' => Carbon::now()->subMinute()->toIso8601String(),
        ]);

        $this->assertSame(2, $exitCode);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertStringNotContainsString('token-validation@synthetic.test', Artisan::output());
    }

    public function test_it_requires_a_valid_exact_public_uuid_and_explicit_ability(): void
    {
        $exitCode = Artisan::call('svc:auth:issue-token', [
            'user_public_id' => 'not-a-uuid',
            'name' => 'Invalid user',
            'abilities' => [],
            '--expires-at' => Carbon::now()->addHour()->toIso8601String(),
        ]);

        $this->assertSame(2, $exitCode);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_named_tokens_can_be_revoked_without_exposing_secret_or_email(): void
    {
        $user = User::factory()->create(['email' => 'revoke-owner@synthetic.test']);
        $first = $user->createToken('Finance integration', ['finance.read'], now()->addDay());
        $second = $user->createToken('Finance integration', ['finance.read'], now()->addDay());

        $ambiguous = Artisan::call('svc:auth:revoke-token', [
            'user_public_id' => $user->public_id,
            'name' => 'Finance integration',
        ]);
        $this->assertSame(1, $ambiguous);
        $this->assertDatabaseCount('personal_access_tokens', 2);

        $revoked = Artisan::call('svc:auth:revoke-token', [
            'user_public_id' => $user->public_id,
            'name' => 'Finance integration',
            '--all' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $revoked);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertStringNotContainsString($first->plainTextToken, $output);
        $this->assertStringNotContainsString($second->plainTextToken, $output);
        $this->assertStringNotContainsString($user->email, $output);
    }
}
