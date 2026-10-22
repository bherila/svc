<?php

namespace Tests\Feature\Tasks\Organization;

use App\Models\ClientCompany;
use App\Models\ClientCompanyMembership;
use App\Models\ClientProject;
use App\Models\ClientTask;
use App\Models\ClientTaskGraphState;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AgentApi\AgentTaskOrganizationMutationAction;
use App\Services\Tasks\Organization\TaskOrganizationAction;
use App\Services\Tasks\Organization\TaskOrganizationCommand;
use App\Services\Tasks\WorkspaceTaskRepository;
use App\Support\AgentApi\AgentApiVersion;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Process\Process;
use Tests\Concerns\UsesAProbeDatabase;
use Tests\TestCase;

final class TaskOrganizationConcurrencyTest extends TestCase
{
    use UsesAProbeDatabase;

    public static function retainedState(): iterable
    {
        yield 'first state committed after old snapshot' => [false];
        yield 'existing state changed after old snapshot' => [true];
    }

    #[DataProvider('retainedState')]
    public function test_current_state_and_first_state_creation_override_old_repeatable_read_snapshots(bool $existing): void
    {
        $original = $this->bootRace();
        try {
            [$workspace, $project, $owner] = $this->fixture();
            $action = app(TaskOrganizationAction::class);
            if ($existing) {
                $action->perform($owner, $workspace, $project->public_id, TaskOrganizationCommand::fromInput('lists.create',
                    ['name' => 'Synthetic initial list', 'expected_graph_version' => $action->inspect($owner, $workspace, $project->public_id)->graphVersion()]));
            }
            $graph = $action->inspect($owner, $workspace, $project->public_id);
            DB::transaction(function () use ($workspace, $project, $owner, $existing, $graph, $action): void {
                $this->assertSame($existing ? 1 : 0, ClientTaskGraphState::query()->count());
                $worker = $this->worker($workspace, $project, $owner, 'list', ['name' => 'Synthetic racing list', 'expected_graph_version' => $graph->graphVersion()]);
                $worker->mustRun();
                $this->assertSame("committed\n", $worker->getOutput());
                $this->assertSame($existing ? 1 : 0, ClientTaskGraphState::query()->count(), 'Ordinary reads must retain the old RR snapshot.');
                $current = $action->inspect($owner, $workspace, $project->public_id, current: true);
                $this->assertSame($existing ? 2 : 1, $current->stateVersion);
                $this->assertNotSame($graph->graphVersion(), $current->graphVersion());
                try {
                    $action->perform($owner, $workspace, $project->public_id, TaskOrganizationCommand::fromInput('lists.create',
                        ['name' => 'Synthetic stale attempt', 'expected_graph_version' => $graph->graphVersion()]));
                    $this->fail('A stale graph snapshot was accepted.');
                } catch (HttpExceptionInterface $exception) {
                    $this->assertSame(409, $exception->getStatusCode());
                }
            });
            $this->assertDatabaseCount('client_task_graph_states', 1);
            $this->assertDatabaseCount('client_task_lists', $existing ? 2 : 1);
        } finally {
            config(['database.default' => $original]);
        }
    }

    public static function agentPublication(): iterable
    {
        yield 'fresh write' => [false];
        yield 'receipt replay' => [true];
    }

    #[DataProvider('agentPublication')]
    public function test_new_company_portal_membership_blocks_publication_even_when_preflight_holds_an_old_snapshot(bool $replay): void
    {
        $original = $this->bootRace();
        try {
            [$workspace, $project, $owner] = $this->fixture();
            $action = app(TaskOrganizationAction::class);
            $writes = app(AgentTaskOrganizationMutationAction::class);
            $input = ['name' => 'Synthetic replay list', 'expected_graph_version' => $action->inspect($owner, $workspace, $project->public_id)->graphVersion()];
            if ($replay) {
                $writes->execute($owner, $workspace, 'synthetic-org-race-client', 'synthetic-org-replay', $project->public_id, 'lists.create', $input);
            }
            DB::transaction(function () use ($workspace, $project, $owner, $writes, $input): void {
                $this->assertSame(0, ClientCompanyMembership::query()->count());
                $worker = $this->worker($workspace, $project, $owner, 'portal', [], $owner);
                $worker->mustRun();
                $this->assertSame("committed\n", $worker->getOutput());
                $this->assertSame(0, ClientCompanyMembership::query()->count(), 'The preflight must still see the original RR snapshot.');
                try {
                    $writes->execute($owner, $workspace, 'synthetic-org-race-client', 'synthetic-org-replay', $project->public_id, 'lists.create', $input);
                    $this->fail('Receipt replay ignored a current Portal membership.');
                } catch (HttpExceptionInterface $exception) {
                    $this->assertSame(403, $exception->getStatusCode());
                }
            });
            $this->assertDatabaseCount('client_task_lists', $replay ? 1 : 0);
            $this->assertDatabaseCount('client_task_graph_states', $replay ? 1 : 0);
            $this->assertDatabaseCount('agent_mutation_receipts', $replay ? 1 : 0);
        } finally {
            config(['database.default' => $original]);
        }
    }

    public function test_opposite_moves_serialize_and_only_one_can_commit_from_the_same_graph_revision(): void
    {
        $original = $this->bootRace();
        $workers = [];
        try {
            [$workspace, $project, $owner] = $this->fixture();
            $first = ClientTask::query()->create(['workspace_id' => $workspace->id, 'client_project_id' => $project->id, 'title' => 'Synthetic first']);
            $second = ClientTask::query()->create(['workspace_id' => $workspace->id, 'client_project_id' => $project->id, 'title' => 'Synthetic second']);
            $action = app(TaskOrganizationAction::class);
            $graph = $action->inspect($owner, $workspace, $project->public_id);
            DB::transaction(function () use ($workspace, $project, $owner, $first, $second, $graph, &$workers): void {
                app(WorkspaceTaskRepository::class)->lockWorkspace($workspace);
                foreach ([[$first, $second], [$second, $first]] as [$task, $parent]) {
                    $worker = $this->worker($workspace, $project, $owner, 'move', ['task_id' => $task->public_id, 'parent_id' => $parent->public_id, 'list_id' => null,
                        'expected_version' => AgentApiVersion::for($task), 'expected_graph_version' => $graph->graphVersion()]);
                    $workers[] = $worker;
                    $worker->start();
                    $this->assertTrue($worker->waitUntil(static fn (string $type, string $output): bool => str_contains($output, "ready\n")));
                    $this->assertTrue($worker->isRunning(), 'The writer must wait behind the workspace mutex.');
                }
            });
            $outputs = [];
            foreach ($workers as $worker) {
                $this->assertSame(0, $worker->wait(), $worker->getErrorOutput());
                $outputs[] = str_replace("ready\n", '', $worker->getOutput());
            }
            sort($outputs);
            $this->assertSame(["committed\n", "refused:409\n"], $outputs);
            $current = $action->inspect($owner, $workspace, $project->public_id, current: true);
            $current->assertValid();
            $this->assertCount(1, array_filter($current->tasks, static fn (array $task): bool => $task['parent'] !== null));
            $this->assertSame(1, $current->stateVersion);
        } finally {
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
            config(['database.default' => $original]);
        }
    }

    private function bootRace(): string
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Real task graph interleavings require MariaDB.');
        }
        $this->bootProbeDatabase('organization_race');
        Artisan::call('migrate', ['--database' => 'organization_race', '--force' => true]);
        $original = config('database.default');
        config(['database.default' => 'organization_race', 'task_organization.enabled' => true]);
        DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        DB::statement('SET SESSION innodb_snapshot_isolation=OFF');

        return $original;
    }

    /** @return array{Workspace,ClientProject,User} */
    private function fixture(): array
    {
        $workspace = Workspace::query()->create(['name' => 'Synthetic graph race', 'slug' => 'synthetic-graph-race']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic graph company', 'slug' => 'synthetic-graph-company']);
        $project = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Synthetic graph project']);
        $owner = User::factory()->create();
        $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);

        return [$workspace, $project, $owner];
    }

    /** @param array<string,mixed> $body */
    private function worker(Workspace $workspace, ClientProject $project, User $owner, string $operation, array $body, ?User $actor = null): Process
    {
        return new Process([PHP_BINARY, '-d', 'memory_limit=1G', base_path('tests/Fixtures/Tasks/task-organization-worker.php')], base_path(),
            ['APP_ENV' => 'testing', 'LOG_CHANNEL' => 'null'], json_encode([
                'connection' => config('database.connections.organization_race'), 'workspace' => $workspace->id,
                'owner' => $owner->id, 'project' => $project->public_id, 'actor' => $actor?->id,
                'operation' => $operation, 'body' => $body,
            ], JSON_THROW_ON_ERROR)."\n", 45);
    }
}
