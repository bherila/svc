<?php

namespace Tests\Feature\AgentApi;

use App\Models\ClientCompany;
use App\Models\ClientProject;
use App\Models\User;
use App\Models\Workspace;
use App\Support\AgentApi\AgentApiVersion;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CallsMcp;
use Tests\TestCase;

final class AgentProjectNameCollisionTest extends TestCase
{
    use CallsMcp, RefreshDatabase;

    private const MESSAGE = 'This client already has a project with this name.';

    private User $owner;

    private Workspace $workspace;

    private ClientCompany $company;

    private ClientProject $project;

    private ClientProject $sibling;

    protected function setUp(): void
    {
        parent::setUp();
        URL::forceRootUrl('http://localhost');
        config(['app.url' => 'http://localhost', 'agent_api.writes_enabled' => true, 'agent_api.project_writes_enabled' => true]);
        $this->owner = User::factory()->create();
        $this->workspace = Workspace::query()->create(['name' => 'Synthetic project names', 'slug' => 'synthetic-project-names']);
        $this->workspace->memberships()->create(['user_id' => $this->owner->id, 'role' => 'owner']);
        $this->company = ClientCompany::query()->create(['workspace_id' => $this->workspace->id, 'name' => 'Synthetic client', 'slug' => 'synthetic-client']);
        $this->project = $this->company->projects()->create(['workspace_id' => $this->workspace->id, 'name' => 'Synthetic current project']);
        $this->sibling = $this->company->projects()->create(['workspace_id' => $this->workspace->id, 'name' => 'Synthetic sibling project']);
        $this->actingAsMcp($this->owner, ['mcp:use', 'clients:read', 'projects:read', 'projects:write']);
    }

    public static function writes(): iterable
    {
        yield 'create' => [true];
        yield 'rename' => [false];
    }

    #[DataProvider('writes')]
    public function test_rest_duplicate_project_names_are_validation_errors_and_failed_keys_can_be_retried(bool $create): void
    {
        $version = AgentApiVersion::for($this->project);
        $body = $this->body($create);
        $method = $create ? 'POST' : 'PATCH';
        $path = $this->path($create);
        $this->json($method, $path, $body, ['Idempotency-Key' => 'synthetic-duplicate-name'])->assertUnprocessable()
            ->assertJsonValidationErrors(['name'])->assertJsonPath('errors.name.0', self::MESSAGE);
        $this->assertUnchanged($version);
        $this->assertDatabaseHas('agent_mutation_audits', ['workspace_id' => $this->workspace->id, 'operation' => $this->operation($create), 'outcome' => 'failed', 'error_category' => 'validation']);
        $this->sibling->update(['name' => 'Synthetic released name']);
        $created = $this->json($method, $path, $body, ['Idempotency-Key' => 'synthetic-duplicate-name'])->assertStatus($create ? 201 : 200)->json('data');
        $replayed = $this->json($method, $path, $body, ['Idempotency-Key' => 'synthetic-duplicate-name'])->assertStatus($create ? 201 : 200)->json('data');
        $this->assertSame($created, $replayed);
        $this->assertDatabaseCount('client_projects', $create ? 3 : 2);
        $this->assertDatabaseCount('agent_mutation_receipts', 1);
        foreach (['success', 'replay'] as $outcome) {
            $this->assertDatabaseHas('agent_mutation_audits', ['workspace_id' => $this->workspace->id, 'operation' => $this->operation($create), 'outcome' => $outcome]);
        }
    }

    #[DataProvider('writes')]
    public function test_mcp_duplicate_project_names_are_actionable_and_failed_keys_can_be_retried(bool $create): void
    {
        $version = AgentApiVersion::for($this->project);
        $arguments = ['workspace_id' => $this->workspace->public_id, 'idempotency_key' => 'synthetic-mcp-duplicate-name', ...$this->body($create)];
        if (! $create) {
            $arguments['project_id'] = $this->project->public_id;
        }
        $session = $this->initialize();
        $failed = $this->callTool($session, $this->operation($create), $arguments);
        $this->assertTrue($failed['result']['isError'] ?? false, json_encode($failed));
        $this->assertStringContainsString(self::MESSAGE, json_encode($failed, JSON_THROW_ON_ERROR));
        $this->assertUnchanged($version);
        $this->assertDatabaseHas('agent_mutation_audits', ['workspace_id' => $this->workspace->id, 'operation' => $this->operation($create), 'outcome' => 'failed', 'error_category' => 'validation']);
        $this->sibling->update(['name' => 'Synthetic released name']);
        $created = $this->callTool($session, $this->operation($create), $arguments);
        $this->assertFalse($created['result']['isError'] ?? true, json_encode($created));
        $replayed = $this->callTool($session, $this->operation($create), $arguments);
        $this->assertFalse($replayed['result']['isError'] ?? true, json_encode($replayed));
        $this->assertSame($created['result']['structuredContent'], $replayed['result']['structuredContent']);
        $this->assertDatabaseCount('client_projects', $create ? 3 : 2);
        $this->assertDatabaseCount('agent_mutation_receipts', 1);
        foreach (['success', 'replay'] as $outcome) {
            $this->assertDatabaseHas('agent_mutation_audits', ['workspace_id' => $this->workspace->id, 'operation' => $this->operation($create), 'outcome' => $outcome]);
        }
    }

    #[DataProvider('writes')]
    public function test_browser_duplicate_project_names_return_to_the_form_with_a_name_error(bool $create): void
    {
        $version = AgentApiVersion::for($this->project);
        $path = $create
            ? route('projects.store', [$this->workspace, $this->company])
            : route('projects.update', [$this->workspace, $this->company, $this->project]);
        $body = ['name' => $this->sibling->name, 'description' => null, 'repository' => null, 'status' => 'active', 'is_visible_to_client' => true, 'lock_version' => $this->project->lock_version];
        $this->actingAs($this->owner)->from('/synthetic-project-form')->json($create ? 'POST' : 'PATCH', $path, $body, ['Accept' => 'text/html'])
            ->assertRedirect('/synthetic-project-form')->assertSessionHasErrors(['name' => self::MESSAGE]);
        $this->assertUnchanged($version);
        $this->assertDatabaseCount('agent_mutation_audits', 0);
    }

    public static function constraints(): iterable
    {
        $constraints = [
            'named project key' => ['client_projects_client_company_id_name_unique', [], 422, 'validation'],
            'SQLite project columns' => [null, ['client_company_id', 'name'], 422, 'validation'],
            'unrelated named key overrides matching columns' => ['synthetic_unrelated_unique', ['client_company_id', 'name'], 500, 'internal'],
            'unrelated public ID columns' => [null, ['public_id'], 500, 'internal'],
        ];
        foreach ($constraints as $name => $constraint) {
            foreach ([true, false] as $create) {
                yield $name.($create ? ' create' : ' rename') => [$create, ...$constraint];
            }
        }
    }

    /** @param list<string> $columns */
    #[DataProvider('constraints')]
    public function test_constraint_metadata_is_classified_narrowly(bool $create, ?string $index, array $columns, int $status, string $category): void
    {
        config(['app.debug' => false]);
        $version = AgentApiVersion::for($this->project);
        $collision = (new UniqueConstraintViolationException(DB::getDefaultConnection(), $create ? 'insert into client_projects' : 'update client_projects', [], new \PDOException('Synthetic project constraint failure')))
            ->setIndex($index)->setColumns($columns);
        if ($create) {
            ClientProject::creating(static fn () => throw $collision);
        } else {
            ClientProject::updating(static fn () => throw $collision);
        }
        $this->json($create ? 'POST' : 'PATCH', $this->path($create), [...$this->body($create), 'name' => 'Synthetic unused name'], ['Idempotency-Key' => 'synthetic-named-project-collision'])->assertStatus($status);
        $this->assertUnchanged($version);
        $this->assertDatabaseHas('agent_mutation_audits', ['workspace_id' => $this->workspace->id, 'operation' => $this->operation($create), 'outcome' => 'failed', 'error_category' => $category]);
    }

    public function test_an_actual_public_id_collision_is_not_misclassified_as_a_name_error(): void
    {
        config(['app.debug' => false]);
        $version = AgentApiVersion::for($this->project);
        ClientProject::creating(function (ClientProject $project): void {
            $project->setAttribute('public_id', $this->project->public_id);
        });
        $this->postJson($this->path(true), [...$this->body(true), 'name' => 'Synthetic unused name'], ['Idempotency-Key' => 'synthetic-project-public-id-collision'])->assertInternalServerError();
        $this->assertUnchanged($version);
        $this->assertDatabaseHas('agent_mutation_audits', ['workspace_id' => $this->workspace->id, 'operation' => 'projects.create', 'outcome' => 'failed', 'error_category' => 'internal']);
    }

    /** @return array<string,mixed> */
    private function body(bool $create): array
    {
        return ['name' => $this->sibling->name, 'expected_version' => AgentApiVersion::for($create ? $this->company : $this->project), 'confirm' => true]
            + ($create ? ['company_id' => $this->company->public_id] : []);
    }

    private function path(bool $create): string
    {
        return '/api/v1/workspaces/'.$this->workspace->public_id.'/projects'.($create ? '' : '/'.$this->project->public_id);
    }

    private function operation(bool $create): string
    {
        return $create ? 'projects.create' : 'projects.update';
    }

    private function assertUnchanged(string $version): void
    {
        $this->assertDatabaseCount('client_projects', 2);
        $this->assertDatabaseCount('agent_mutation_receipts', 0);
        $this->assertSame('Synthetic current project', $this->project->fresh()->name);
        $this->assertSame($version, AgentApiVersion::for($this->project->fresh()));
    }
}
