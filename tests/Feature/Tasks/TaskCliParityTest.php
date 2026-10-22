<?php

namespace Tests\Feature\Tasks;

use App\Models\ClientCompany;
use App\Models\ClientProject;
use App\Models\ClientTask;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class TaskCliParityTest extends TestCase
{
    use RefreshDatabase;

    public function test_cli_previews_and_applies_the_shared_task_lifecycle_with_versions_and_receipts(): void
    {
        config(['agent_api.writes_enabled' => false]);
        $actor = User::factory()->create();
        $workspace = Workspace::query()->create(['name' => 'Synthetic CLI tasks', 'slug' => 'synthetic-cli-tasks']);
        $workspace->memberships()->create(['user_id' => $actor->id, 'role' => 'owner']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic company', 'slug' => 'synthetic-cli-company']);
        $project = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Synthetic project']);
        $input = tempnam(sys_get_temp_dir(), 'svc-task-cli-');
        $base = ['workspace' => $workspace->public_id, '--actor' => $actor->public_id, '--format' => 'json'];
        try {
            file_put_contents($input, json_encode(['title' => 'Synthetic planned task', 'starts_on' => '2026-10-22', 'due_on' => '2026-10-25', 'assignee_ids' => [$actor->public_id]], JSON_THROW_ON_ERROR));
            $create = $base + ['operation' => 'create', '--project' => $project->public_id, '--input' => $input, '--key' => 'synthetic-cli-create'];
            $this->assertSame(0, Artisan::call('svc:tasks', $create));
            $this->assertTrue(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['dry_run']);
            $this->assertDatabaseCount('client_tasks', 0);
            $this->assertDatabaseCount('client_task_assignments', 0);
            $this->assertDatabaseCount('agent_mutation_receipts', 0);
            $this->assertSame(0, Artisan::call('svc:tasks', $create + ['--apply' => true]));
            $created = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertFalse($created['dry_run']);
            $this->assertSame('2026-10-25', $created['data']['due_on']);
            $this->assertSame($actor->public_id, $created['data']['assignees'][0]['id']);
            $this->assertSame(0, Artisan::call('svc:tasks', $create + ['--apply' => true]));
            $this->assertSame($created['data']['id'], json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['data']['id']);
            $this->assertDatabaseCount('client_tasks', 1);
            file_put_contents($input, json_encode(['expected_version' => $created['data']['version'], 'due_on' => null, 'assignee_ids' => []], JSON_THROW_ON_ERROR));
            $update = $base + ['operation' => 'update', '--task' => $created['data']['id'], '--input' => $input, '--key' => 'synthetic-cli-update', '--apply' => true];
            $this->assertSame(0, Artisan::call('svc:tasks', $update));
            $updated = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['data'];
            $this->assertNull($updated['due_on']);
            $this->assertSame([], $updated['assignees']);
            $this->assertSame('2026-10-22', $updated['starts_on']);
            $this->assertSame(1, Artisan::call('svc:tasks', array_replace($update, ['--key' => 'synthetic-stale-version'])));
            $this->assertSame(0, Artisan::call('svc:tasks', $base + ['operation' => 'get', '--task' => $created['data']['id']]));
            $this->assertSame($updated, json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['data']);
            file_put_contents($input, json_encode(['title' => 'Synthetic planned task', 'starts_on' => '2026-10-22', 'due_on' => '2026-10-25', 'assignee_ids' => [$actor->public_id]], JSON_THROW_ON_ERROR));
            $workspace->memberships()->where('user_id', $actor->id)->update(['role' => 'member']);
            $this->assertSame(1, Artisan::call('svc:tasks', $create + ['--apply' => true]));
            $this->assertSame(1, ClientTask::query()->where('workspace_id', $workspace->id)->count());
        } finally {
            unlink($input);
        }
    }
}
