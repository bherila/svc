<?php

namespace Tests\Feature\Access;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use BWH\Auth\OAuth\PendingAccount;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Concerns\BuildsAccessFixtures;
use Tests\Concerns\UsesAProbeDatabase;
use Tests\TestCase;

final class OAuthProvisioningRaceTest extends TestCase
{
    use BuildsAccessFixtures;
    use UsesAProbeDatabase;

    /** @return array<string, array{string, bool}> */
    public static function collisions(): array
    {
        return [
            'delegated placeholder is resolved' => ['pending', true],
            'email claimed before recovery is refused' => ['claimed-email', false],
            'matching signed-in account is accepted' => ['matching', true],
            'different non-placeholder account is refused' => ['different', false],
        ];
    }

    #[DataProvider('collisions')]
    public function test_first_sign_in_handles_an_account_committed_after_its_missing_subject_read(string $collision, bool $accepted): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('The committed provisioning race requires MariaDB.');
        }
        $this->bootProbeDatabase('login_race');
        Artisan::call('migrate', ['--database' => 'login_race', '--force' => true]);
        $original = config('database.default');
        config([
            'database.default' => 'login_race',
            'bherila-auth.oauth_client.provider' => 'example-provider',
            'bherila-auth.oauth_client.base_url' => 'https://identity.example.test',
            'bherila-auth.oauth_client.client_id' => 'synthetic-client',
            'bherila-auth.oauth_client.client_secret' => 'synthetic-secret',
            'bherila-auth.oauth_client.redirect_uri' => 'https://app.example.test/oauth/callback',
        ]);
        try {
            // Missing-key gap locks would serialize the inserts under REPEATABLE READ.
            // READ COMMITTED lets the other connection commit first and exercises the
            // collision recovery rather than making the worker wait on our gap lock.
            DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
            $workspace = Workspace::query()->create(['name' => 'Synthetic login race', 'slug' => 'synthetic-login-race']);
            $manager = User::factory()->create(['oauth_provider' => 'example-provider', 'oauth_subject' => 'manager-subject']);
            $this->addStaff($workspace, $manager, 'owner');
            $ran = false;
            User::creating(function (User $user) use (&$ran, $workspace, $collision): void {
                if ($ran || $user->oauth_subject !== 'new-subject') {
                    return;
                }
                $ran = true;
                $worker = new Process([PHP_BINARY, base_path('tests/Fixtures/Access/login-provisioning-race-worker.php')], base_path(),
                    ['APP_ENV' => 'testing', 'LOG_CHANNEL' => 'null', 'BCRYPT_ROUNDS' => '4'], json_encode([
                        'connection' => config('database.connections.login_race'),
                        'workspace' => $workspace->public_id, 'collision' => $collision,
                    ], JSON_THROW_ON_ERROR)."\n", 20);
                $worker->mustRun();
                $this->assertSame("committed\n", $worker->getOutput());
            });
            Http::fake([
                'https://identity.example.test/oauth/token' => Http::response(['access_token' => 'synthetic-access-token']),
                'https://identity.example.test/api/oauth/user' => Http::response([
                    'sub' => 'new-subject', 'name' => 'Synthetic Person', 'email' => 'person@example.test',
                ]),
            ]);
            $response = $this->withSession([
                'oauth.login.state' => 'synthetic-state', 'oauth.login.code_verifier' => 'synthetic-verifier',
            ])->get('/oauth/callback?state=synthetic-state&code=synthetic-code');
            $this->assertTrue($ran, 'The second connection must commit before the first insert.');
            $bound = User::query()->where('oauth_provider', 'example-provider')->where('oauth_subject', 'new-subject')->sole();
            if ($accepted) {
                $response->assertRedirect(route('workspaces.index'));
                $this->assertAuthenticatedAs($bound);
                $this->assertSame('Synthetic Person', $bound->name);
                $this->assertSame('person@example.test', $bound->email);
                $this->assertNotNull($bound->email_verified_at);
            } else {
                $response->assertConflict();
                $this->assertGuest();
                $this->assertSame($collision === 'claimed-email' ? PendingAccount::email('example-provider', 'new-subject') : 'different@example.test', $bound->email);
            }
            if (in_array($collision, ['pending', 'claimed-email'], true)) {
                $this->assertSame('member', WorkspaceMembership::query()->where('workspace_id', $workspace->id)->where('user_id', $bound->id)->value('role'));
            }
        } finally {
            config(['database.default' => $original]);
        }
    }
}
