<?php

namespace Tests\Feature\Tasks\Planning;

use App\Models\ClientCompany;
use App\Models\ClientCompanyMembership;
use App\Models\ClientContact;
use App\Models\ClientProject;
use App\Models\ClientTask;
use App\Models\ClientTaskLabel;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AgentApi\AgentTaskPlanningMutationAction;
use App\Services\Tasks\Organization\TaskOrganizationRepository;
use App\Services\Tasks\Planning\TaskPlanningCommand;
use App\Services\Tasks\Planning\TaskPlanningMutationAction;
use App\Services\Tasks\Planning\TaskPlanningRepository;
use App\Services\Tasks\WorkspaceTaskRepository;
use App\Support\AgentApi\AgentApiVersion;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Process\Process;
use Tests\Concerns\UsesAProbeDatabase;
use Tests\TestCase;

final class TaskPlanningConcurrencyTest extends TestCase
{
    use UsesAProbeDatabase;

    public function test_opposite_edges_from_one_revision_serialize_and_only_one_can_commit(): void
    {
        $original = $this->bootRace();
        $workers = [];
        try {
            [$workspace, $project, $actor, $first, $second] = $this->fixture();
            $organization = app(TaskOrganizationRepository::class);
            $version = $organization->graph($organization->load($workspace, $project, false))->graphVersion();
            DB::transaction(function () use ($workspace, $actor, $first, $second, $version, &$workers): void {
                app(WorkspaceTaskRepository::class)->lockWorkspace($workspace);
                foreach ([[$first, $second], [$second, $first]] as [$task, $prerequisite]) {
                    $worker = $this->worker($workspace, $actor, 'dependency', $task->public_id,
                        ['prerequisite_ids' => [$prerequisite->public_id], 'expected_version' => AgentApiVersion::for($task), 'expected_graph_version' => $version]);
                    $workers[] = $worker;
                    $worker->start();
                    $this->assertTrue($worker->waitUntil(static fn (string $type, string $output): bool => str_contains($output, "ready\n")));
                    $this->assertTrue($worker->isRunning(), 'Both edge writers must wait behind the actual workspace mutex.');
                }
            });
            $results = [];
            foreach ($workers as $worker) {
                $this->assertSame(0, $worker->wait(), $worker->getErrorOutput());
                $results[] = str_replace("ready\n", '', $worker->getOutput());
            }
            sort($results);
            $this->assertSame(["committed\n", "refused:409\n"], $results);
            $records = $organization->load($workspace, $project, true);
            $graph = app(TaskPlanningRepository::class)->dependencyGraph($workspace, $records, static fn () => null);
            $this->assertCount(1, $graph->edges());
            $this->assertSame(1, $records->state->lock_version);
            $this->assertSame(2, $first->fresh()->lock_version);
            $this->assertSame(2, $second->fresh()->lock_version);
        } finally {
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
            config(['database.default' => $original]);
        }
    }

    public function test_contact_archival_committed_after_an_old_snapshot_blocks_new_responsibility(): void
    {
        $original = $this->bootRace();
        try {
            [$workspace, $project, $actor, $task, , $contact] = $this->fixture();
            DB::transaction(function () use ($workspace, $project, $actor, $task, $contact): void {
                $this->assertFalse(ClientContact::query()->where('workspace_id', $workspace->id)->whereKey($contact->id)->sole()->is_archived);
                $worker = $this->worker($workspace, $actor, 'archive', $contact->public_id,
                    ['expected_version' => AgentApiVersion::for($contact), 'confirm' => true], $project->clientCompany->public_id);
                $worker->mustRun();
                $this->assertSame("committed\n", $worker->getOutput());
                $this->assertFalse(ClientContact::query()->where('workspace_id', $workspace->id)->whereKey($contact->id)->sole()->is_archived, 'An ordinary read must retain the old snapshot.');
                try {
                    app(TaskPlanningMutationAction::class)->perform($actor, $workspace, $task->public_id, TaskPlanningCommand::fromInput('contacts.replace',
                        ['contact_ids' => [$contact->public_id], 'expected_version' => AgentApiVersion::for($task)]));
                    $this->fail('A stale contact snapshot admitted a newly archived contact.');
                } catch (ValidationException $exception) {
                    $this->assertNotEmpty($exception->errors());
                }
            });
            $this->assertTrue($contact->fresh()->is_archived);
            $this->assertDatabaseCount('client_task_contact_responsibilities', 0);
            $this->assertDatabaseCount('client_task_graph_states', 0);
        } finally {
            config(['database.default' => $original]);
        }
    }

    public static function agentOperations(): iterable
    {
        yield 'label fresh' => ['labels.replace', false];
        yield 'label replay' => ['labels.replace', true];
        yield 'contacts fresh' => ['contacts.replace', false];
        yield 'contacts replay' => ['contacts.replace', true];
    }

    #[DataProvider('agentOperations')]
    public function test_current_new_company_portal_identity_blocks_agent_writes_and_replays(string $operation, bool $replay): void
    {
        $original = $this->bootRace();
        try {
            [$workspace, , $actor, $task, , $contact, $label] = $this->fixture();
            $input = ['expected_version' => AgentApiVersion::for($task)] + ($operation === 'labels.replace' ? ['label_ids' => [$label->public_id]] : ['contact_ids' => [$contact->public_id]]);
            $writes = app(AgentTaskPlanningMutationAction::class);
            if ($replay) {
                $writes->execute($actor, $workspace, 'synthetic-race-client', 'synthetic-race-key', $operation, $task->public_id, $input);
            }
            DB::transaction(function () use ($workspace, $actor, $task, $operation, $input, $writes): void {
                $this->assertSame(0, ClientCompanyMembership::query()->where('workspace_id', $workspace->id)->count());
                $worker = $this->worker($workspace, $actor, 'portal', null, []);
                $worker->mustRun();
                $this->assertSame("committed\n", $worker->getOutput());
                $this->assertSame(0, ClientCompanyMembership::query()->where('workspace_id', $workspace->id)->count(), 'The old RR snapshot must still hide the grant and company.');
                try {
                    $writes->execute($actor, $workspace, 'synthetic-race-client', 'synthetic-race-key', $operation, $task->public_id, $input);
                    $this->fail('A new Portal identity was ignored.');
                } catch (HttpExceptionInterface $exception) {
                    $this->assertSame(403, $exception->getStatusCode());
                }
            });
            $this->assertDatabaseCount('agent_mutation_receipts', $replay ? 1 : 0);
            $this->assertDatabaseCount('client_task_graph_states', $replay ? 1 : 0);
            $this->assertDatabaseCount($operation === 'labels.replace' ? 'client_task_label_assignments' : 'client_task_contact_responsibilities', $replay ? 1 : 0);
        } finally {
            config(['database.default' => $original]);
        }
    }

    private function bootRace(): string
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Actual planning interleavings require MariaDB.');
        }
        $original = config('database.default');
        $this->bootProbeDatabase('planning_race');
        $this->assertSame(0, Artisan::call('migrate', ['--database' => 'planning_race', '--force' => true]));
        config(['database.default' => 'planning_race', 'task_planning_details.enabled' => true]);
        DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        DB::statement('SET SESSION innodb_snapshot_isolation=OFF');

        return $original;
    }

    private function fixture(): array
    {
        $workspace = Workspace::query()->create(['name' => 'Synthetic planning race', 'slug' => 'synthetic-planning-race']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic company', 'slug' => 'synthetic-race-company']);
        $project = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Synthetic project']);
        $actor = User::factory()->create();
        $workspace->memberships()->create(['user_id' => $actor->id, 'role' => 'owner']);
        $first = ClientTask::query()->create(['workspace_id' => $workspace->id, 'client_project_id' => $project->id, 'title' => 'Synthetic first']);
        $second = ClientTask::query()->create(['workspace_id' => $workspace->id, 'client_project_id' => $project->id, 'title' => 'Synthetic second']);
        $contact = ClientContact::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'first_name' => 'Synthetic contact']);
        $label = ClientTaskLabel::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic label', 'name_key' => hash('sha256', 'synthetic label')]);

        return [$workspace, $project, $actor, $first, $second, $contact, $label];
    }

    private function worker(Workspace $workspace, User $actor, string $operation, ?string $target, array $body, ?string $company = null): Process
    {
        return new Process([PHP_BINARY, '-d', 'memory_limit=1G', base_path('tests/Fixtures/Tasks/task-planning-worker.php')], base_path(),
            ['APP_ENV' => 'testing', 'LOG_CHANNEL' => 'null'], json_encode(['connection' => config('database.connections.planning_race'),
                'workspace' => $workspace->id, 'actor' => $actor->id, 'operation' => $operation, 'target' => $target, 'body' => $body, 'company' => $company], JSON_THROW_ON_ERROR)."\n", 45);
    }
}
