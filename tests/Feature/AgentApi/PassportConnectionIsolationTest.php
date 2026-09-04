<?php

namespace Tests\Feature\AgentApi;

use App\Http\Controllers\Api\V1\AgentConnectionController;
use App\Models\AgentPrincipal;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use Tests\TestCase;

final class PassportConnectionIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_oauth_metadata_migration_uses_the_configured_passport_connection(): void
    {
        $this->configurePassportConnection();
        $schema = Schema::connection('passport_test');
        foreach (['oauth_clients', 'oauth_auth_codes', 'oauth_access_tokens', 'oauth_refresh_tokens'] as $tableName) {
            $schema->create($tableName, static function (Blueprint $table): void {
                $table->string('id')->primary();
            });
        }

        $migration = require database_path('migrations/2026_09_02_000000_add_oauth_server_metadata.php');
        $this->assertSame('passport_test', $migration->getConnection());
        $migration->up();

        $this->assertTrue($schema->hasColumns('oauth_clients', ['dynamically_registered_at', 'last_used_at', 'scopes']));
        $this->assertTrue($schema->hasColumn('oauth_auth_codes', 'resource_uri'));
        $this->assertTrue($schema->hasColumn('oauth_access_tokens', 'resource_uri'));
        $this->assertTrue($schema->hasColumn('oauth_refresh_tokens', 'resource_uri'));
    }

    public function test_connection_revocation_is_atomic_on_the_configured_passport_connection(): void
    {
        $this->configurePassportConnection();
        $this->createConnectionTables();
        $user = User::factory()->create();
        $principal = AgentPrincipal::query()->findOrFail($user->id);
        $clientId = (string) Str::uuid();
        Passport::client()->forceFill([
            'id' => $clientId,
            'name' => 'Passport connection test',
            'secret' => null,
            'provider' => 'agent-principals',
            'redirect_uris' => [],
            'grant_types' => ['authorization_code', 'refresh_token'],
            'revoked' => false,
        ])->saveOrFail();
        $tokenId = Str::random(80);
        Passport::token()->forceFill([
            'id' => $tokenId,
            'user_id' => $user->id,
            'client_id' => $clientId,
            'scopes' => [],
            'revoked' => false,
            'expires_at' => now()->addHour(),
        ])->saveOrFail();
        Passport::refreshToken()->forceFill([
            'id' => Str::random(80),
            'access_token_id' => $tokenId,
            'revoked' => false,
            'expires_at' => now()->addDay(),
        ])->saveOrFail();

        $refreshUpdateTransactionLevels = [];
        DB::listen(static function (QueryExecuted $query) use (&$refreshUpdateTransactionLevels): void {
            if ($query->connectionName === 'passport_test'
                && str_starts_with($query->sql, 'update')
                && str_contains($query->sql, 'oauth_refresh_tokens')) {
                $refreshUpdateTransactionLevels[] = $query->connection->transactionLevel();
            }
        });
        $request = Request::create("/api/v1/connections/{$tokenId}", 'DELETE');
        $request->setUserResolver(static fn (): AgentPrincipal => $principal);

        (new AgentConnectionController)->destroy($request, $tokenId);

        $this->assertSame([1], $refreshUpdateTransactionLevels);
        $this->assertTrue((bool) Passport::token()->newQuery()->findOrFail($tokenId)->revoked);
        $this->assertTrue((bool) Passport::refreshToken()->newQuery()->where('access_token_id', $tokenId)->sole()->revoked);
    }

    private function configurePassportConnection(): void
    {
        config([
            'database.connections.passport_test' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
            'passport.connection' => 'passport_test',
        ]);
        DB::purge('passport_test');
    }

    private function createConnectionTables(): void
    {
        $schema = Schema::connection('passport_test');
        $schema->create('oauth_clients', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->nullableMorphs('owner');
            $table->string('name');
            $table->string('secret')->nullable();
            $table->string('provider')->nullable();
            $table->text('redirect_uris');
            $table->text('grant_types');
            $table->boolean('revoked');
            $table->timestamps();
        });
        $schema->create('oauth_access_tokens', static function (Blueprint $table): void {
            $table->char('id', 80)->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->foreignUuid('client_id');
            $table->string('name')->nullable();
            $table->text('scopes')->nullable();
            $table->boolean('revoked');
            $table->timestamps();
            $table->dateTime('expires_at')->nullable();
        });
        $schema->create('oauth_refresh_tokens', static function (Blueprint $table): void {
            $table->char('id', 80)->primary();
            $table->char('access_token_id', 80)->index();
            $table->boolean('revoked');
            $table->dateTime('expires_at')->nullable();
        });
    }
}
