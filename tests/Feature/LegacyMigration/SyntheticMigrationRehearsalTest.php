<?php

namespace Tests\Feature\LegacyMigration;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SyntheticMigrationRehearsalTest extends TestCase
{
    use RefreshDatabase;

    public function test_rehearsal_applies_twice_verifies_idempotency_and_removes_artifacts(): void
    {
        $sentinel = User::factory()->create(['email' => 'local-sentinel@synthetic.test']);
        $temporaryDirectoriesBefore = glob(sys_get_temp_dir().'/svc-legacy-rehearsal-*') ?: [];

        $exit = Artisan::call('svc:migrate:legacy:rehearse', ['--format' => 'json']);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit, Artisan::output());
        $this->assertTrue($payload['ok']);
        $this->assertTrue($payload['redacted']);
        $this->assertSame('synthetic-disposable', $payload['mode']);
        $this->assertSame(5, $payload['source_rows']);
        $this->assertNotEmpty($payload['canonical_fingerprint']);
        $this->assertNotEmpty($payload['first_run']['run_public_id']);
        $this->assertNotSame($payload['first_run']['run_public_id'], $payload['second_run']['run_public_id']);
        $this->assertSame('completed', $payload['first_run']['status']);
        $this->assertSame('completed', $payload['second_run']['status']);
        $this->assertNotContains(false, $payload['checks'], true);
        $this->assertSame('removed', $payload['artifacts']);
        $this->assertSame($temporaryDirectoriesBefore, glob(sys_get_temp_dir().'/svc-legacy-rehearsal-*') ?: []);

        $this->assertSame(1, DB::table('users')->count());
        $this->assertSame($sentinel->public_id, User::query()->sole()->public_id);
        $this->assertDatabaseCount('legacy_migration_runs', 0);
        $this->assertDatabaseCount('client_companies', 0);
    }

    public function test_rehearsal_refuses_non_disposable_environments(): void
    {
        $originalEnvironment = $this->app['env'];
        $this->app['env'] = 'production';

        try {
            $this->artisan('svc:migrate:legacy:rehearse', ['--format' => 'json'])
                ->assertFailed()
                ->expectsOutput('{"ok":false,"reason_code":"non_disposable_environment","redacted":true}');
        } finally {
            $this->app['env'] = $originalEnvironment;
        }
    }

    public function test_rehearsal_rejects_unknown_output_formats_without_creating_artifacts(): void
    {
        $temporaryDirectoriesBefore = glob(sys_get_temp_dir().'/svc-legacy-rehearsal-*') ?: [];

        $this->artisan('svc:migrate:legacy:rehearse', ['--format' => 'xml'])
            ->assertFailed()
            ->expectsOutput('Synthetic rehearsal could not run: invalid_format');

        $this->assertSame($temporaryDirectoriesBefore, glob(sys_get_temp_dir().'/svc-legacy-rehearsal-*') ?: []);
    }
}
