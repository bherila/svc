<?php

namespace Tests\Feature\Engagement;

use App\Models\ClientCompany;
use App\Models\ClientProject;
use App\Models\ClientProjectMembership;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\AgentApi\TimeEntryCreationParityTest;
use Tests\TestCase;

/**
 * `svc:engagement:log-time` creates through the same operation as
 * `time_entries.log` (#460). The transport parity itself is pinned in
 * {@see TimeEntryCreationParityTest}; this covers what
 * only the console has: its lookups, its defaults and its exit codes.
 */
final class LogTimeEntryCommandTest extends TestCase
{
    use RefreshDatabase;

    public static function names(): iterable
    {
        yield 'primary' => ['svc:engagement:log-time'];
        yield 'legacy' => ['engagement:log-time'];
        yield 'short' => ['svc:log-time'];
    }

    #[DataProvider('names')]
    public function test_it_logs_by_slug_and_prints_json_under_every_name(string $name): void
    {
        [$workspace, $project] = $this->fixture('manager');

        $this->artisan($name, ['--apply' => true,
            'workspace' => 'synthetic-command-workspace',
            'project' => 'website-refresh',
            'minutes' => '45',
            'description' => '  Synthetic CLI work  ',
            '--user' => 'worker@example.test',
            '--worked-on' => now()->toDateString(),
            '--billable' => true,
            '--deferred' => true,
            '--rate' => '15000',
            '--currency' => 'USD',
            '--format' => 'json',
        ])->expectsOutputToContain('"minutes":45')->assertSuccessful();

        $entry = ClientTimeEntry::query()->where('workspace_id', $workspace->id)->sole();
        $this->assertSame($project->id, $entry->client_project_id);
        $this->assertSame(45, $entry->minutes);
        $this->assertSame('Synthetic CLI work', $entry->description);
        $this->assertTrue($entry->is_billable);
        $this->assertTrue($entry->is_deferred);
        $this->assertSame(15000, $entry->billing_rate_amount);
        $this->assertSame('explicit', $entry->billing_rate_source);
        $this->assertSame('USD', $entry->currency);
        $this->assertFalse($entry->is_visible_to_client);
        $this->assertSame('draft', $entry->status);
    }

    public function test_the_default_preview_runs_the_shared_rules_but_keeps_no_entry(): void
    {
        [$workspace, $project] = $this->fixture('manager');
        $arguments = ['workspace' => $workspace->public_id, 'project' => $project->public_id,
            'minutes' => '45', 'description' => 'Synthetic preview', '--user' => 'worker@example.test', '--format' => 'json'];
        $this->artisan('svc:engagement:log-time', $arguments)->expectsOutputToContain('"dry_run":true')->assertSuccessful();
        $this->assertDatabaseCount('client_time_entries', 0);
        $this->artisan('svc:engagement:log-time', [...$arguments, '--rate' => '-1'])->assertExitCode(2);
        $this->assertDatabaseCount('client_time_entries', 0);
        $this->artisan('svc:engagement:log-time', [...$arguments, '--apply' => true])->expectsOutputToContain('"dry_run":false')->assertSuccessful();
        $this->assertSame(1, ClientTimeEntry::query()->where('workspace_id', $workspace->id)->count());
    }

    public function test_today_is_the_workspace_day_not_the_utc_day(): void
    {
        [$workspace] = $this->fixture('contributor', 'Pacific/Honolulu');
        // 07:00 UTC is still the previous evening in Honolulu.
        $this->travelTo(CarbonImmutable::parse('2026-10-19 07:00:00', 'UTC'));

        $this->artisan('svc:engagement:log-time', ['--apply' => true,
            'workspace' => $workspace->public_id, 'project' => 'Website Refresh', 'minutes' => '30',
            'description' => 'Synthetic evening work', '--user' => 'worker@example.test',
        ])->assertSuccessful();

        $this->assertSame('2026-10-18', ClientTimeEntry::query()->where('workspace_id', $workspace->id)->sole()->worked_on->toDateString());
    }

    public function test_the_currency_defaults_to_the_workspace_currency(): void
    {
        [$workspace] = $this->fixture('manager');

        $this->artisan('svc:engagement:log-time', ['--apply' => true,
            'workspace' => $workspace->public_id, 'project' => 'website-refresh', 'minutes' => '15',
            'description' => 'Synthetic priced work', '--user' => 'worker@example.test', '--rate' => '10000',
        ])->assertSuccessful();

        $entry = ClientTimeEntry::query()->where('workspace_id', $workspace->id)->sole();
        $this->assertSame(10000, $entry->billing_rate_amount);
        $this->assertSame('CAD', $entry->currency);
    }

    public static function approvers(): iterable
    {
        yield 'project manager' => ['member', 'manager'];
        yield 'project owner' => ['member', 'owner'];
        yield 'workspace admin' => ['admin', null];
        yield 'workspace owner' => ['owner', null];
    }

    #[DataProvider('approvers')]
    public function test_anyone_who_may_approve_the_time_may_name_its_rate(string $workspaceRole, ?string $projectRole): void
    {
        [$workspace] = $this->fixture($projectRole);
        $workspace->memberships()->update(['role' => $workspaceRole]);

        $this->artisan('svc:engagement:log-time', ['--apply' => true,
            'workspace' => $workspace->public_id, 'project' => 'website-refresh', 'minutes' => '15',
            'description' => 'Synthetic priced work', '--user' => 'worker@example.test', '--rate' => '12000',
        ])->assertSuccessful();

        $this->assertSame(12000, ClientTimeEntry::query()->where('workspace_id', $workspace->id)->sole()->billing_rate_amount);
    }

    public function test_billable_is_the_default_as_on_rest_and_no_billable_opts_out(): void
    {
        [$workspace] = $this->fixture('contributor');
        $arguments = [
            'workspace' => $workspace->public_id, 'project' => 'website-refresh', 'minutes' => '15',
            'description' => 'Synthetic work', '--user' => 'worker@example.test',
        ];

        $this->artisan('svc:engagement:log-time', ['--apply' => true] + ($arguments))->assertSuccessful();
        $this->artisan('svc:engagement:log-time', ['--apply' => true] + ($arguments + ['--billable' => true]))->assertSuccessful();
        $this->artisan('svc:engagement:log-time', ['--apply' => true] + ($arguments + ['--no-billable' => true]))->assertSuccessful();

        $this->assertSame([true, true, false], ClientTimeEntry::query()->where('workspace_id', $workspace->id)->orderBy('id')->pluck('is_billable')->all());
    }

    public function test_client_facing_text_makes_the_entry_visible_unless_it_is_internal(): void
    {
        [$workspace] = $this->fixture('contributor');
        $arguments = [
            'workspace' => $workspace->public_id, 'project' => 'website-refresh', 'minutes' => '15',
            'description' => 'Synthetic internal note', '--user' => 'worker@example.test',
            '--client-description' => 'Synthetic client summary',
        ];

        $this->artisan('svc:engagement:log-time', ['--apply' => true] + ($arguments))->assertSuccessful();
        $this->artisan('svc:engagement:log-time', ['--apply' => true] + ($arguments + ['--internal' => true]))->assertSuccessful();

        $entries = ClientTimeEntry::query()->where('workspace_id', $workspace->id)->orderBy('id')->get();
        $this->assertSame([true, false], $entries->pluck('is_visible_to_client')->all());
        $this->assertSame(['Synthetic client summary', 'Synthetic client summary'], $entries->pluck('client_visible_description')->all());
    }

    public static function refusals(): iterable
    {
        yield 'zero minutes' => ['contributor', ['minutes' => '0'], 2, 'The minutes field must be at least 1.'];
        yield 'fractional minutes' => ['contributor', ['minutes' => '1.5'], 2, 'The minutes field must be an integer.'];
        yield 'too many minutes' => ['contributor', ['minutes' => '1441'], 2, 'The minutes field must not be greater than 1440.'];
        yield 'blank description' => ['contributor', ['description' => '   '], 2, 'The description field is required.'];
        yield 'malformed date' => ['contributor', ['--worked-on' => '2026-02-30'], 2, 'The worked on field must match the format Y-m-d.'];
        yield 'date outside the sheet' => ['contributor', ['--worked-on' => '2020-01-01'], 2, 'The worked on field must be a date after or equal to'];
        yield 'contributor rate' => ['contributor', ['--rate' => '10000'], 2, 'Only a project manager can set the rate time bills at.'];
        yield 'negative rate' => ['manager', ['--rate' => '-1'], 2, 'The billing rate amount field must be at least 0.'];
        yield 'lowercase currency' => ['manager', ['--currency' => 'usd'], 2, 'The currency field format is invalid.'];
        yield 'viewer' => ['viewer', [], 1, 'The worker may not log time on this project.'];
        yield 'no project role' => [null, [], 1, 'Project not found in the requested workspace.'];
        yield 'unknown task' => ['manager', ['--task' => '00000000-0000-4000-8000-000000000000'], 1, 'Task not found in the requested workspace.'];
        yield 'malformed task' => ['manager', ['--task' => 'not-a-uuid'], 2, 'The task id field must be a valid UUID.'];
        yield 'unknown worker' => ['manager', ['--user' => 'nobody@example.test'], 1, 'Worker not found.'];
        yield 'no worker' => ['manager', ['--user' => ''], 1, 'The --user option is required.'];
        yield 'unknown project' => ['manager', ['project' => 'no-such-project'], 1, 'Project not found.'];
        yield 'unknown workspace' => ['manager', ['workspace' => 'no-such-workspace'], 1, 'Workspace not found.'];
        yield 'billable both ways' => ['manager', ['--billable' => true, '--no-billable' => true], 2, 'Give --billable or --no-billable, not both.'];
        yield 'bad format' => ['manager', ['--format' => 'yaml'], 2, 'The --format option must be text or json.'];
    }

    /** @param array<string, string> $override */
    #[DataProvider('refusals')]
    public function test_refusals_write_nothing_and_exit_as_the_api_answers(?string $role, array $override, int $exit, string $message): void
    {
        [$workspace] = $this->fixture($role);

        $this->artisan('svc:engagement:log-time', ['--apply' => true] + ($override + [
            'workspace' => $workspace->public_id, 'project' => 'website-refresh', 'minutes' => '30',
            'description' => 'Synthetic refused work', '--user' => 'worker@example.test',
        ]))->expectsOutputToContain($message)->assertExitCode($exit);

        $this->assertDatabaseCount('client_time_entries', 0);
    }

    public function test_a_member_of_another_workspace_is_refused(): void
    {
        [$workspace] = $this->fixture('manager');
        User::factory()->create(['email' => 'outsider@example.test']);

        $this->artisan('svc:engagement:log-time', ['--apply' => true,
            'workspace' => $workspace->public_id, 'project' => 'website-refresh', 'minutes' => '30',
            'description' => 'Synthetic outsider work', '--user' => 'outsider@example.test',
        ])->expectsOutputToContain('The worker is not a member of this workspace.')->assertFailed();

        $this->assertDatabaseCount('client_time_entries', 0);
    }

    /** @return array{Workspace, ClientProject} */
    private function fixture(?string $projectRole, string $timezone = 'UTC'): array
    {
        $user = User::factory()->create(['email' => 'worker@example.test']);
        $workspace = Workspace::query()->create([
            'name' => 'Synthetic Command Workspace', 'slug' => 'synthetic-command-workspace',
            'default_currency' => 'CAD', 'timezone' => $timezone,
        ]);
        $workspace->memberships()->create(['user_id' => $user->id, 'role' => 'member']);
        $company = ClientCompany::query()->create([
            'workspace_id' => $workspace->id, 'name' => 'Synthetic Command Client', 'slug' => 'synthetic-command-client',
        ]);
        $project = ClientProject::query()->create([
            'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Website Refresh',
        ]);
        if ($projectRole !== null) {
            ClientProjectMembership::query()->create([
                'workspace_id' => $workspace->id, 'client_project_id' => $project->id, 'user_id' => $user->id, 'role' => $projectRole,
            ]);
        }

        return [$workspace, $project];
    }
}
