<?php

namespace Tests\Feature;

use App\Models\ClientCompany;
use App\Models\ClientProject;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LogTimeEntryCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_resolves_workspace_and_project_by_slug_and_returns_json(): void
    {
        $user = User::factory()->create(['email' => 'worker@synthetic.test']);
        $workspace = Workspace::query()->create(['name' => 'Synthetic Command Workspace', 'slug' => 'command-workspace']);
        $workspace->memberships()->create(['user_id' => $user->id, 'role' => 'member']);
        $company = ClientCompany::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Synthetic Command Client',
            'slug' => 'command-client',
        ]);
        ClientProject::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'name' => 'Website Refresh',
        ]);

        $command = $this->artisan('engagement:log-time', [
            'workspace' => 'command-workspace',
            'project' => 'website-refresh',
            'minutes' => '45',
            'description' => 'Synthetic CLI work',
            '--user' => 'worker@synthetic.test',
            '--worked-on' => '2026-08-15',
            '--billable' => true,
            '--deferred' => true,
            '--rate' => '15000',
            '--currency' => 'USD',
            '--format' => 'json',
        ]);
        $command->expectsOutputToContain('"minutes":45')
            ->assertSuccessful();
        $command->run();

        $this->assertDatabaseHas('client_time_entries', [
            'workspace_id' => $workspace->id,
            'client_project_id' => ClientProject::query()->sole()->id,
            'minutes' => 45,
            'is_billable' => true,
            'is_deferred' => true,
            'billing_rate_amount' => 15000,
            'currency' => 'USD',
        ]);
    }

    public function test_command_rejects_invalid_duration_and_rate_currency(): void
    {
        $user = User::factory()->create(['email' => 'worker2@synthetic.test']);
        $workspace = Workspace::query()->create(['name' => 'Synthetic Validation Workspace', 'slug' => 'validation-workspace']);
        $workspace->memberships()->create(['user_id' => $user->id, 'role' => 'member']);
        $company = ClientCompany::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Synthetic Validation Client',
            'slug' => 'validation-client',
        ]);
        ClientProject::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'name' => 'Validation Project',
        ]);

        $this->artisan('engagement:log-time', [
            'workspace' => 'validation-workspace',
            'project' => 'validation-project',
            'minutes' => '0',
            'description' => 'Invalid synthetic duration',
            '--user' => 'worker2@synthetic.test',
            '--format' => 'json',
        ])->expectsOutputToContain('between 1 and 1440')
            ->assertFailed();

        $this->artisan('engagement:log-time', [
            'workspace' => 'validation-workspace',
            'project' => 'validation-project',
            'minutes' => '15',
            'description' => 'Missing currency',
            '--user' => 'worker2@synthetic.test',
            '--rate' => '10000',
            '--format' => 'json',
        ])->expectsOutput('Currency is required when a billing rate is supplied.')
            ->assertFailed();

        $this->assertDatabaseCount('client_time_entries', 0);
    }
}
