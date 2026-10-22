<?php

namespace Tests\Feature\Tasks;

use App\Models\ClientCompany;
use App\Models\ClientCompanyMembership;
use App\Models\ClientProject;
use App\Models\ClientProjectMembership;
use App\Models\ClientTaskAssignment;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AgentApi\AgentTaskMutationAction;
use App\Services\Tasks\TaskCreateData;
use App\Services\Tasks\TaskPatchData;
use App\Services\WorkspaceTaskMutationAction;
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

    public static function interleavings(): iterable
    {
        yield 'target revoked after snapshot' => ['target_revoked', 422];
        yield 'actor demoted after snapshot' => ['actor_demoted', 403];
        yield 'removed assignment cannot masquerade as retained' => ['assignment_removed_and_target_revoked', 422];
        yield 'response includes assignments added after snapshot' => ['assignment_added', null];
        yield 'new portal grant blocks agent create' => ['portal_before_create', 403];
        yield 'new portal grant blocks agent update' => ['portal_before_update', 403];
        yield 'new portal grant blocks receipt replay' => ['portal_before_replay', 403];
        yield 'new company and portal grant override old snapshot' => ['new_company_portal_before_update', 403];
    }

    #[DataProvider('interleavings')]
    public function test_current_locked_access_and_assignment_facts_override_old_snapshots(string $operation, ?int $expectedRefusal): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('The task assignment snapshot race requires MariaDB.');
        }
        $this->bootProbeDatabase('task_race');
        Artisan::call('migrate', ['--database' => 'task_race', '--force' => true]);
        $original = config('database.default');
        config(['database.default' => 'task_race']);
        try {
            DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            DB::statement('SET SESSION innodb_snapshot_isolation=OFF');
            $workspace = Workspace::query()->create(['name' => 'Synthetic task race', 'slug' => 'synthetic-task-race']);
            $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic client', 'slug' => 'synthetic-client']);
            $project = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Synthetic project']);
            $owner = User::factory()->create();
            $actor = User::factory()->create();
            $target = User::factory()->create();
            foreach ([$owner, $actor, $target] as $user) {
                $workspace->memberships()->create(['user_id' => $user->id, 'role' => $user->id === $owner->id ? 'owner' : 'member']);
            }
            foreach ([[$actor, 'manager'], [$target, 'contributor']] as [$user, $role]) {
                ClientProjectMembership::query()->create(['workspace_id' => $workspace->id, 'client_project_id' => $project->id, 'user_id' => $user->id, 'role' => $role]);
            }
            $action = app(WorkspaceTaskMutationAction::class);
            $task = $action->create($owner, $workspace, $project->public_id, TaskCreateData::fromValidated([
                'title' => 'Synthetic race task', 'assignee_ids' => $operation === 'assignment_removed_and_target_revoked' ? [$target->public_id] : [],
            ]));
            $agent = app(AgentTaskMutationAction::class);
            $payload = ['expected_version' => AgentApiVersion::for($task), 'title' => 'Synthetic pregrant edit'];
            if ($operation === 'portal_before_replay') {
                $agent->update($actor, $workspace, 'synthetic-race-client', 'synthetic-race-receipt', $task->public_id, $payload);
            }
            $refusal = null;
            $presentedAssignments = null;
            DB::transaction(function () use ($operation, $workspace, $project, $owner, $actor, $target, $task, $action, $agent, $payload, &$refusal, &$presentedAssignments): void {
                // Ordinary reads establish a snapshot before the independent writer commits.
                $this->assertSame(2, ClientProjectMembership::query()->where('workspace_id', $workspace->id)->count());
                $originalAssignmentCount = ClientTaskAssignment::query()->where('workspace_id', $workspace->id)->where('client_task_id', $task->id)->count();
                $this->assertSame(0, ClientCompanyMembership::query()->where('workspace_id', $workspace->id)->where('user_id', $actor->id)->count());
                $worker = new Process([PHP_BINARY, base_path('tests/Fixtures/Tasks/task-planning-race-worker.php')], base_path(),
                    ['APP_ENV' => 'testing', 'LOG_CHANNEL' => 'null'], json_encode([
                        'connection' => config('database.connections.task_race'), 'workspace' => $workspace->id, 'project' => $project->id,
                        'owner' => $owner->id, 'actor' => $actor->public_id, 'target' => $target->public_id,
                        'task' => $task->public_id, 'operation' => $operation,
                    ], JSON_THROW_ON_ERROR)."\n", 20);
                $worker->mustRun();
                $this->assertSame("committed\n", $worker->getOutput());
                $this->assertSame($originalAssignmentCount, ClientTaskAssignment::query()->where('workspace_id', $workspace->id)->where('client_task_id', $task->id)->count(), 'The outer transaction must still hold its old snapshot.');
                $this->assertSame(0, ClientCompanyMembership::query()->where('workspace_id', $workspace->id)->where('user_id', $actor->id)->count(), 'A normal portal read must retain the old snapshot.');
                try {
                    if (str_contains($operation, 'portal')) {
                        if ($operation === 'portal_before_create') {
                            $agent->create($actor, $workspace, 'synthetic-race-client', 'synthetic-race-create', $project->public_id, ['title' => 'Synthetic refused task']);
                        } else {
                            $agent->update($actor, $workspace, 'synthetic-race-client', 'synthetic-race-receipt', $task->public_id, $payload);
                        }

                        return;
                    }
                    $changes = ['title' => 'Synthetic current edit'];
                    if ($operation !== 'assignment_added') {
                        $changes['assignee_ids'] = [$target->public_id];
                    }
                    $updated = $action->update($actor, $workspace, $task->public_id, TaskPatchData::fromValidated($changes));
                    $presentedAssignments = $updated->assignments->map(fn ($assignment): string => $assignment->user->public_id)->all();
                } catch (ValidationException) {
                    $refusal = 422;
                } catch (HttpExceptionInterface $exception) {
                    $refusal = $exception->getStatusCode();
                }
            });
            $this->assertSame($expectedRefusal, $refusal);
            DB::purge('task_race');
            $this->assertSame($operation === 'assignment_added' ? 1 : 0, ClientTaskAssignment::query()->where('workspace_id', $workspace->id)->count());
            if ($operation === 'assignment_added') {
                $this->assertSame([$target->public_id], $presentedAssignments);
            }
        } finally {
            config(['database.default' => $original]);
        }
    }
}
