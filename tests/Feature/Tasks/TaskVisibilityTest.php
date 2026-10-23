<?php

namespace Tests\Feature\Tasks;

use App\Models\ClientCompany;
use App\Models\ClientCompanyMembership;
use App\Models\ClientProject;
use App\Models\ClientTask;
use App\Models\ClientTaskList;
use App\Models\User;
use App\Models\Workspace;
use App\Queries\ClientHome\PortalClientHomeQuery;
use App\Services\Tasks\TaskAncestryFacts;
use App\Services\Tasks\TaskAncestryRepository;
use App\Services\Tasks\TaskVisibility;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\AssertsSurfaceIsolation;
use Tests\Concerns\WritesLegacyCrossTenantRows;
use Tests\TestCase;

final class TaskVisibilityTest extends TestCase
{
    use AssertsSurfaceIsolation;
    use RefreshDatabase;
    use WritesLegacyCrossTenantRows;

    public static function organizations(): iterable
    {
        yield 'flat public' => ['flat', true, true];
        yield 'private child' => ['private_child', true, false];
        yield 'private parent' => ['private_parent', true, false];
        yield 'private grandparent' => ['private_grandparent', true, false];
        yield 'public list' => ['public_list', true, true];
        yield 'private list' => ['private_list', true, false];
        yield 'deleted list' => ['deleted_list', false, false];
        yield 'missing list' => ['missing_list', false, false];
        yield 'missing parent' => ['missing_parent', false, false];
        yield 'other project parent' => ['foreign_project_parent', false, false];
        yield 'other project list' => ['foreign_project_list', false, false];
        yield 'other workspace parent' => ['foreign_workspace_parent', false, false];
        yield 'other workspace list' => ['foreign_workspace_list', false, false];
        yield 'self cycle' => ['self_cycle', false, false];
        yield 'two task cycle' => ['cycle', false, false];
        yield 'depth eight' => ['depth_8', true, true];
        yield 'depth nine' => ['depth_9', false, false];
        yield 'child carries a list' => ['child_list', false, false];
    }

    #[DataProvider('organizations')]
    public function test_sql_and_pure_facts_agree_and_conceal_invalid_lineage(string $case, bool $valid, bool $visible): void
    {
        [$workspace, $company, $project, , $viewer] = $this->fixture();
        $leaf = $this->task($workspace, $project, 'Synthetic leaf');
        $parent = $this->task($workspace, $project, 'Synthetic parent secret');
        $list = $this->list($workspace, $project, $case !== 'private_list');
        $this->writingLegacyCrossTenantRows(function () use ($case, $workspace, $project, $leaf, $parent, $list): void {
            if (in_array($case, ['private_parent', 'private_grandparent', 'public_list', 'private_list', 'deleted_list', 'missing_list', 'child_list', 'cycle'], true)) {
                $this->structure($workspace, $leaf, ['parent_task_id' => $parent->id]);
            }
            if ($case === 'private_child') {
                $leaf->update(['is_visible_to_client' => false]);
            } elseif ($case === 'private_parent') {
                $parent->update(['is_visible_to_client' => false]);
            } elseif ($case === 'private_grandparent') {
                $grandparent = $this->task($workspace, $project, 'Synthetic private grandparent secret', false);
                $this->structure($workspace, $parent, ['parent_task_id' => $grandparent->id]);
            } elseif (in_array($case, ['public_list', 'private_list', 'deleted_list', 'missing_list', 'child_list'], true)) {
                $this->structure($workspace, $parent, ['client_task_list_id' => $case === 'missing_list' ? 999999 : $list->id]);
                if ($case === 'deleted_list') {
                    $list->delete();
                } elseif ($case === 'child_list') {
                    $this->structure($workspace, $leaf, ['client_task_list_id' => $list->id]);
                }
            } elseif ($case === 'missing_parent') {
                $this->structure($workspace, $leaf, ['parent_task_id' => 999999]);
            } elseif (str_starts_with($case, 'foreign_')) {
                [$foreignWorkspace, , $foreignProject] = $this->fixture();
                if (str_contains($case, 'project')) {
                    $foreignProject = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $project->client_company_id, 'name' => 'Synthetic other project', 'is_visible_to_client' => true]);
                    $foreignWorkspace = $workspace;
                }
                $attributes = str_ends_with($case, 'parent')
                    ? ['parent_task_id' => $this->task($foreignWorkspace, $foreignProject, 'Synthetic foreign task secret')->id]
                    : ['client_task_list_id' => $this->list($foreignWorkspace, $foreignProject)->id];
                $this->structure($workspace, $leaf, $attributes);
            } elseif ($case === 'self_cycle' || $case === 'cycle') {
                $this->structure($workspace, $case === 'cycle' ? $parent : $leaf, ['parent_task_id' => $leaf->id]);
            } elseif (str_starts_with($case, 'depth_')) {
                $node = $leaf;
                for ($depth = 0; $depth < (int) substr($case, 6); $depth++) {
                    $ancestor = $this->task($workspace, $project, 'Synthetic ancestor '.$depth);
                    $this->structure($workspace, $node, ['parent_task_id' => $ancestor->id]);
                    $node = $ancestor;
                }
            }
        });

        $visibility = app(TaskVisibility::class);
        $this->assertSame($visible, $visibility->constrainPortal(ClientTask::query()->whereKey($leaf->id), $workspace, $viewer, $company)->exists());
        $this->assertSame($visible ? [$leaf->id => true] : [], $visibility->portalVisibleIds($workspace, $viewer, [$leaf->id], $company));
        $facts = app(TaskAncestryRepository::class)->factsBatch($workspace, [$leaf->id])[$leaf->id];
        $this->assertSame($valid, $facts->validOrganization);
        $this->assertSame($visible, $facts->visibleToClient);
        $this->assertSame($valid ? 'valid' : 'invalid', $facts->organizationState());
        $this->assertSame(['validOrganization' => $valid, 'visibleToClient' => $visible], get_object_vars($facts));
    }

    public function test_portal_api_list_detail_cursor_embed_native_list_and_home_apply_ancestry_before_limits(): void
    {
        [$workspace, $company, $project, , $viewer] = $this->fixture();
        $parent = $this->task($workspace, $project, 'Synthetic private parent secret', false);
        $secrets = [$parent->title];
        for ($index = 0; $index < 8; $index++) {
            $child = $this->task($workspace, $project, 'Synthetic hidden descendant secret '.$index);
            $this->structure($workspace, $child, ['parent_task_id' => $parent->id]);
            $secrets[] = $child->title;
        }
        $visible = $this->task($workspace, $project, 'Synthetic visible unlisted control');
        $this->actingAsMcp($viewer, ['tasks:read', 'projects:read']);
        $base = '/api/v1/workspaces/'.$workspace->public_id.'/';
        $response = $this->getJson($base.'tasks?limit=1')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $visible->public_id)->assertJsonPath('meta.next_cursor', null);
        $this->assertJsonPayloadOmits($response, $secrets, $visible->title);
        $response = $this->getJson($base.'projects/'.$project->public_id)->assertOk()->assertJsonCount(1, 'data.tasks');
        $this->assertJsonPayloadOmits($response, $secrets, $visible->title);
        $this->getJson($base.'tasks/'.$child->public_id)->assertNotFound();
        $home = app(PortalClientHomeQuery::class)->for($company, $viewer);
        $this->assertSame([$visible->public_id], array_column($home->toArray()['open_tasks'], 'id'));
        $response = $this->actingAs($viewer)->get(route('portal.tasks', $company))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('tasks', 1)->where('tasks.0.id', $visible->public_id));
        $this->assertInertiaPayloadOmits($response, $secrets, $visible->title);
    }

    public function test_native_owner_preview_preserves_its_authority_while_dual_agent_uses_exact_project_grants(): void
    {
        [$workspace, $company, $project, $owner] = $this->fixture();
        $task = $this->task($workspace, $project, 'Synthetic native preview control');
        // Native preview has never required the owner to be a portal member.
        $this->actingAs($owner)->get(route('portal.tasks', $company))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('tasks', 1)->where('tasks.0.id', $task->public_id));
        $this->assertSame([$task->public_id], array_column(app(PortalClientHomeQuery::class)->for($company, $owner)->toArray()['open_tasks'], 'id'));
        $membership = ClientCompanyMembership::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id,
            'user_id' => $owner->id, 'role' => 'client', 'access_scope' => ClientCompanyMembership::SCOPE_PROJECTS]);
        $visibility = app(TaskVisibility::class);
        $this->assertSame([], $visibility->portalVisibleIds($workspace, $owner, [$task->id]));
        DB::table('client_portal_project_access')->insert(['workspace_id' => $workspace->id, 'client_company_membership_id' => $membership->id, 'client_project_id' => $project->id]);
        $this->assertSame([$task->id => true], $visibility->portalVisibleIds($workspace, $owner, [$task->id]));
        $this->assertSame([], $visibility->portalVisibleIds($workspace, $owner, []));
    }

    public function test_batch_queries_do_not_grow_with_visible_task_count_and_have_a_fixed_depth_bound(): void
    {
        [$workspace, $company, $project, , $viewer] = $this->fixture();
        $list = $this->list($workspace, $project);
        $root = $this->task($workspace, $project, 'Synthetic query root');
        $this->structure($workspace, $root, ['client_task_list_id' => $list->id]);
        $ids = [$root->id];
        $this->assertQueryCountIndependentOfRows(function () use ($workspace, $company, $viewer, &$ids): void {
            $this->assertCount(count($ids), app(TaskVisibility::class)->portalVisibleIds($workspace, $viewer, $ids, $company));
            $facts = app(TaskAncestryRepository::class)->factsBatch($workspace, $ids);
            $this->assertCount(count($ids), $facts);
            foreach ($facts as $fact) {
                $this->assertTrue($fact->visibleToClient);
            }
        }, function () use ($workspace, $project, $list, &$ids): void {
            for ($index = 0; $index < 20; $index++) {
                $task = $this->task($workspace, $project, 'Synthetic bounded task '.$index);
                $this->structure($workspace, $task, ['client_task_list_id' => $list->id]);
                $ids[] = $task->id;
            }
        });
        $leaf = $root;
        for ($depth = 0; $depth < TaskAncestryFacts::MAX_DEPTH; $depth++) {
            $child = $this->task($workspace, $project, 'Synthetic deep task '.$depth);
            $this->structure($workspace, $child, ['parent_task_id' => $leaf->id]);
            $leaf = $child;
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->assertTrue(app(TaskAncestryRepository::class)->factsBatch($workspace, [$leaf->id])[$leaf->id]->visibleToClient);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertCount(TaskAncestryFacts::MAX_DEPTH + 2, $queries); // Initial rows, eight ancestors, one list.
    }

    public function test_same_project_constraints_refuse_foreign_edges_and_deletion_cannot_publish_children(): void
    {
        [$workspace, $company, $project] = $this->fixture();
        $other = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Synthetic foreign project']);
        $parent = $this->task($workspace, $other, 'Synthetic foreign parent');
        $list = $this->list($workspace, $other);
        $child = $this->task($workspace, $project, 'Synthetic constrained child');
        foreach ([['parent_task_id' => $parent->id], ['client_task_list_id' => $list->id]] as $attributes) {
            try {
                $this->structure($workspace, $child, $attributes);
                $this->fail('A same-workspace but different-project organization edge was accepted.');
            } catch (QueryException $exception) {
                $this->assertStringContainsString('constraint', strtolower($exception->getMessage()));
            }
        }
        $parent = $this->task($workspace, $project, 'Synthetic private constrained parent', false);
        $list = $this->list($workspace, $project, false);
        $this->structure($workspace, $parent, ['client_task_list_id' => $list->id]);
        $this->structure($workspace, $child, ['parent_task_id' => $parent->id]);
        foreach ([['client_tasks', $parent->id], ['client_task_lists', $list->id]] as [$table, $id]) {
            try {
                DB::table($table)->where('workspace_id', $workspace->id)->where('id', $id)->delete();
                $this->fail('Deleting a disclosure ancestor was accepted.');
            } catch (QueryException $exception) {
                $this->assertStringContainsString('constraint', strtolower($exception->getMessage()));
            }
        }
        $this->assertSame($parent->id, $child->fresh()->parent_task_id);
    }

    public function test_audit_detects_legacy_same_workspace_cross_project_edges_without_printing_records(): void
    {
        [$workspace, $company, $project] = $this->fixture();
        $other = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Synthetic audit other project']);
        $parent = $this->task($workspace, $other, 'Synthetic audited foreign parent secret');
        $child = $this->task($workspace, $project, 'Synthetic audited child secret');
        $this->writingLegacyCrossTenantRows(fn () => $this->structure($workspace, $child, ['parent_task_id' => $parent->id]));
        $this->assertSame(1, Artisan::call('svc:schema:audit-tenant-fks', ['--format' => 'json']));
        $report = Artisan::output();
        $this->assertStringNotContainsString($parent->title, $report);
        $this->assertStringNotContainsString($parent->public_id, $report);
        $rows = json_decode($report, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(1, $rows['summary']['violating_rows']);
        $this->assertSame(1, $rows['summary']['violating_references']);
    }

    public function test_reader_schema_preserves_flat_tasks_and_defaults_new_lists_private(): void
    {
        [$workspace, , $project] = $this->fixture();
        $task = $this->task($workspace, $project, 'Synthetic flat compatibility control')->fresh();
        $this->assertNull($task->parent_task_id);
        $this->assertNull($task->client_task_list_id);
        $list = ClientTaskList::query()->create(['workspace_id' => $workspace->id, 'client_project_id' => $project->id, 'name' => 'Synthetic default private list'])->fresh();
        $this->assertFalse($list->is_visible_to_client);
        $this->assertSame(0, $list->lock_version);
        $task->fill(['parent_task_id' => $task->id, 'client_task_list_id' => $list->id]);
        $this->assertNull($task->parent_task_id);
        $this->assertNull($task->client_task_list_id);
        $this->assertArrayNotHasKey('parent_task_id', $task->toArray());
        $this->assertArrayNotHasKey('client_task_list_id', $task->toArray());
    }

    public function test_existing_task_writes_cannot_enable_organization_before_the_reader_rollout(): void
    {
        config(['agent_api.writes_enabled' => true]);
        [$workspace, , $project, $owner] = $this->fixture();
        $parent = $this->task($workspace, $project, 'Synthetic protected parent', false);
        $list = $this->list($workspace, $project, false);
        $this->actingAsMcp($owner, ['tasks:read', 'tasks:write']);
        $base = '/api/v1/workspaces/'.$workspace->public_id.'/tasks';
        $unadvertised = ['parent_id' => $parent->public_id, 'list_id' => $list->public_id,
            'parent_task_id' => $parent->id, 'client_task_list_id' => $list->id];
        $created = $this->withHeader('Idempotency-Key', 'synthetic-graph-reader-create')->postJson(
            '/api/v1/workspaces/'.$workspace->public_id.'/projects/'.$project->public_id.'/tasks',
            ['title' => 'Synthetic flat-only HTTP task', ...$unadvertised],
        )->assertCreated();
        $id = $created->json('data.id');
        $this->withHeader('Idempotency-Key', 'synthetic-graph-reader-update')->patchJson($base.'/'.$id,
            ['expected_version' => $created->json('data.version'), 'title' => 'Synthetic flat-only updated task', ...$unadvertised])->assertOk();
        $this->assertDatabaseHas('client_tasks', ['workspace_id' => $workspace->id, 'public_id' => $id,
            'title' => 'Synthetic flat-only updated task', 'parent_task_id' => null, 'client_task_list_id' => null]);
        foreach (['parent_id', 'list_id', 'parent_task_id', 'client_task_list_id'] as $field) {
            $created->assertJsonMissingPath('data.'.$field);
        }
    }

    public function test_visibility_plan_uses_bounded_key_lookups_for_every_ancestor(): void
    {
        [$workspace, $company, $project, , $viewer] = $this->fixture();
        $task = $this->task($workspace, $project, 'Synthetic measured query control');
        $query = app(TaskVisibility::class)->constrainPortal(ClientTask::query(), $workspace, $viewer, $company)
            ->where('client_tasks.client_project_id', $project->id)->orderBy('client_tasks.id')->limit(100);
        $sqlite = DB::connection()->getDriverName() === 'sqlite';
        $plan = DB::select(($sqlite ? 'explain query plan ' : 'explain ').$query->toSql(), $query->getBindings());
        $encoded = json_encode($plan, JSON_THROW_ON_ERROR);
        for ($level = 0; $level <= TaskAncestryFacts::MAX_DEPTH; $level++) {
            $alias = 'task_ancestor_'.$level;
            $entries = array_filter($plan, fn ($row): bool => $sqlite ? str_contains($row->detail, $alias.' ') : $row->table === $alias);
            $this->assertCount(1, $entries, $alias.' lookup missing from measured plan: '.$encoded);
            $entry = array_values($entries)[0];
            if ($sqlite) {
                $this->assertStringContainsString('SEARCH ', $entry->detail);
                $this->assertStringContainsString('PRIMARY KEY', $entry->detail);
            } else {
                // MariaDB may add a rowid filter to the same unique lookup.
                $this->assertContains(explode('|', $entry->type)[0], ['eq_ref', 'const'], $encoded);
                $this->assertSame('DEPENDENT SUBQUERY', $entry->select_type);
                $this->assertSame(1, (int) $entry->rows);
            }
        }
        $this->assertSame([$task->id => true], app(TaskVisibility::class)->portalVisibleIds($workspace, $viewer, [$task->id]));
    }

    /** @return array{Workspace,ClientCompany,ClientProject,User,User} */
    private function fixture(): array
    {
        $workspace = Workspace::query()->create(['name' => 'Synthetic organization workspace', 'slug' => 'synthetic-'.Str::uuid()]);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic organization company', 'slug' => 'synthetic-'.Str::uuid()]);
        $project = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Synthetic organization project', 'is_visible_to_client' => true]);
        $owner = User::factory()->create();
        $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
        $viewer = User::factory()->create();
        ClientCompanyMembership::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'user_id' => $viewer->id, 'role' => 'client']);

        return [$workspace, $company, $project, $owner, $viewer];
    }

    private function task(Workspace $workspace, ClientProject $project, string $title, bool $visible = true): ClientTask
    {
        return ClientTask::query()->create(['workspace_id' => $workspace->id, 'client_project_id' => $project->id, 'title' => $title, 'is_visible_to_client' => $visible]);
    }

    private function list(Workspace $workspace, ClientProject $project, bool $visible = true): ClientTaskList
    {
        return ClientTaskList::query()->create(['workspace_id' => $workspace->id, 'client_project_id' => $project->id, 'name' => 'Synthetic list secret', 'is_visible_to_client' => $visible]);
    }

    /** @param array<string, int|null> $attributes */
    private function structure(Workspace $workspace, ClientTask $task, array $attributes): void
    {
        DB::table('client_tasks')->where('workspace_id', $workspace->id)->where('id', $task->id)->update($attributes);
    }
}
