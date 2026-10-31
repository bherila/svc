<?php

namespace Tests\Feature\Tasks;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\ClientCompany;
use App\Models\ClientCompanyMembership;
use App\Models\ClientProject;
use App\Models\ClientProjectMembership;
use App\Models\ClientTask;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class TaskWebPaginationTest extends TestCase
{
    use RefreshDatabase;

    public static function staffRoles(): iterable
    {
        yield 'workspace owner' => ['workspace_owner'];
        yield 'project manager' => ['manager'];
        yield 'project contributor' => ['contributor'];
        yield 'project viewer' => ['viewer'];
    }

    #[DataProvider('staffRoles')]
    public function test_native_project_preview_and_task_pages_are_bounded_for_current_viewers(string $role): void
    {
        [$workspace, $company, $project, $actor, $ids] = $this->fixture($role);
        $this->actingAs($actor);
        $href = route('clients.tasks', [$workspace, $company, 'project' => $project->public_id]);
        $this->get(route('clients.project', [$workspace, $company, $project]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('tasks', 25)->where('tasks_next_href', $href)
                ->where('tasks.0.id', $ids[0])->where('tasks.24.id', $ids[24]));
        $response = $this->get($href)->assertOk();
        $response->assertInertia(fn (Assert $page) => $page->has('tasks', 50)->where('tasks.0.id', $ids[0])
            ->where('tasks.49.id', $ids[49])->where('pagination.previous_href', null));
        $next = $response->inertiaProps('pagination.next_href');
        $this->assertIsString($next);
        $this->assertStringContainsString('project='.$project->public_id, $next);
        $last = $this->get($next)->assertOk();
        $last->assertInertia(fn (Assert $page) => $page->has('tasks', 3)->where('tasks.0.id', $ids[50])
            ->where('tasks.2.id', $ids[52])->where('pagination.next_href', null));
        $this->assertIsString($last->inertiaProps('pagination.previous_href'));
        if (in_array($role, ['contributor', 'viewer'], true)) {
            $response->assertInertia(fn (Assert $page) => $page->where('task_management', null)->where('tasks.0.detail_url', null));
            $this->get(route('tasks.show', [$workspace, $this->taskById($workspace, $ids[0])]))->assertForbidden();
        }
    }

    #[DataProvider('creationBoundaries')]
    public function test_session_creation_publishes_new_continuation_links_in_the_partial_refresh(int $limit, string $pageName, string $continuation): void
    {
        [$workspace, $company, $project, $actor, $ids] = $this->fixture('workspace_owner');
        ClientTask::query()->where('workspace_id', $workspace->id)->whereNotIn('public_id', array_slice($ids, 0, $limit))->delete();
        $pageUrl = $pageName === 'clients/project' ? route('clients.project', [$workspace, $company, $project]) : route('clients.tasks', [$workspace, $company]);
        $this->actingAs($actor, 'web')->get($pageUrl)->assertOk()->assertInertia(fn (Assert $page) => $page->where($continuation, null));
        $this->actingAs($actor, 'web')->withCredentials()->withUnencryptedCookie((string) config('session.cookie'), Str::random(40))
            ->withHeader('Idempotency-Key', 'synthetic-boundary-'.$limit)
            ->postJson(route('agent-api.v1.tasks.store', [$workspace, $project->public_id]), ['title' => 'ZZZ Synthetic newly overflowed task'])->assertCreated();
        $refresh = $this->actingAs($actor, 'web')->withHeaders([
            'X-Inertia' => 'true',
            'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(Request::create('/')) ?? '',
            'X-Inertia-Partial-Component' => $pageName,
            'X-Inertia-Partial-Data' => 'tasks,task_management,tasks_next_href,pagination',
        ])->get($pageUrl)->assertOk()->assertJsonCount($limit, 'props.tasks');
        $next = data_get($refresh->json('props'), $continuation);
        $this->assertIsString($next);
        $this->withoutHeader('X-Inertia')->withoutHeader('X-Inertia-Version')->withoutHeader('X-Inertia-Partial-Component')->withoutHeader('X-Inertia-Partial-Data');
        $this->get($next)->assertOk()->assertSee('ZZZ Synthetic newly overflowed task');
    }

    public static function creationBoundaries(): iterable
    {
        yield 'project preview crosses 25' => [25, 'clients/project', 'tasks_next_href'];
        yield 'company task list crosses 50' => [50, 'clients/tasks', 'pagination.next_href'];
    }

    public function test_project_filter_and_pagination_never_expand_the_actor_company_or_workspace_scope(): void
    {
        [$workspace, $company, $project, $actor, $ids] = $this->fixture('viewer');
        $otherCompany = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic other company secret', 'slug' => 'synthetic-'.Str::uuid()]);
        $other = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $otherCompany->id, 'name' => 'Synthetic foreign filter secret']);
        ClientTask::query()->create(['workspace_id' => $workspace->id, 'client_project_id' => $other->id, 'title' => 'Synthetic leaked task secret']);
        $this->actingAs($actor)->get(route('clients.tasks', [$workspace, $company, 'project' => $other->public_id]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('tasks', 50)->where('tasks.0.id', $ids[0])->where('filters.project_id', null)
                ->where('pagination.next_href', fn ($href): bool => is_string($href) && ! str_contains($href, $other->public_id)));
        $this->get(route('clients.project', [$workspace, $company, $other]))->assertNotFound();
        $this->get(route('clients.tasks', [$workspace, $otherCompany, 'page' => 2]))->assertNotFound();
    }

    public function test_portal_task_pagination_is_bounded_and_keeps_private_rows_out_before_the_limit(): void
    {
        [$workspace, $company, $project, , $ids] = $this->fixture('viewer');
        $portal = User::factory()->create();
        ClientCompanyMembership::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'user_id' => $portal->id, 'role' => 'client']);
        for ($index = 0; $index < 5; $index++) {
            ClientTask::query()->create(['workspace_id' => $workspace->id, 'client_project_id' => $project->id, 'title' => 'AAA Synthetic private task '.$index, 'is_visible_to_client' => false]);
        }
        $response = $this->actingAs($portal)->get(route('portal.tasks', [$company, 'project' => $project->public_id]))->assertOk();
        $response->assertInertia(fn (Assert $page) => $page->has('tasks', 50)->where('tasks.0.id', $ids[0])->where('tasks.49.id', $ids[49]));
        $next = $response->inertiaProps('pagination.next_href');
        $this->assertIsString($next);
        $this->assertStringContainsString('/portal/', $next);
        $this->get($next)->assertOk()->assertInertia(fn (Assert $page) => $page->has('tasks', 3)->where('tasks.0.id', $ids[50])
            ->where('pagination.next_href', null));
    }

    /** @return array{Workspace,ClientCompany,ClientProject,User,list<string>} */
    private function fixture(string $role): array
    {
        $workspace = Workspace::query()->create(['name' => 'Synthetic native task pagination', 'slug' => 'synthetic-'.Str::uuid()]);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic task pagination company', 'slug' => 'synthetic-'.Str::uuid()]);
        $project = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Synthetic task pagination project', 'is_visible_to_client' => true]);
        $actor = User::factory()->create();
        $workspace->memberships()->create(['user_id' => $actor->id, 'role' => $role === 'workspace_owner' ? 'owner' : 'member']);
        if ($role !== 'workspace_owner') {
            ClientProjectMembership::query()->create(['workspace_id' => $workspace->id, 'client_project_id' => $project->id, 'user_id' => $actor->id, 'role' => $role]);
        }
        $ids = [];
        for ($index = 0; $index < 53; $index++) {
            // Duplicate title/status values prove the final ID ordering is
            // stable across page boundaries on both supported database engines.
            $ids[] = ClientTask::query()->create(['workspace_id' => $workspace->id, 'client_project_id' => $project->id,
                'title' => 'Synthetic duplicate task title', 'status' => 'open', 'is_visible_to_client' => true])->public_id;
        }

        return [$workspace, $company, $project, $actor, $ids];
    }

    private function taskById(Workspace $workspace, string $id): ClientTask
    {
        return ClientTask::query()->where('workspace_id', $workspace->id)->where('public_id', $id)->firstOrFail();
    }
}
