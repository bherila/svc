<?php

namespace Tests\Feature\AgentApi;

use App\Models\AgentPrincipal;
use App\Models\ClientCompany;
use App\Models\ClientProject;
use App\Models\ClientProjectMembership;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Support\AgentApi\AgentApiScopes;
use App\Support\AgentApi\AgentApiVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

final class AgentTimeEntryMutationTest extends TestCase
{
    use RefreshDatabase;

    public function test_contributor_can_idempotently_log_then_edit_and_delete_their_draft_only(): void
    {
        config([
            'agent_api.writes_enabled' => false,
            'agent_api.time_entry_writes_enabled' => true,
        ]);
        [$workspace, $project] = $this->project();
        $contributor = User::factory()->create();
        $other = User::factory()->create();
        $this->member($workspace, $contributor);
        $this->member($workspace, $other);
        ClientProjectMembership::query()->create(['workspace_id' => $workspace->id, 'client_project_id' => $project->id, 'user_id' => $contributor->id, 'role' => 'contributor']);
        $this->actingAsAgent($contributor, [AgentApiScopes::TIME_WRITE]);
        $payload = ['entries' => [[
            'project_id' => $project->public_id,
            'worked_on' => '2026-08-23',
            'minutes' => 45,
            'description' => 'Draft work',
            'is_visible_to_client' => true,
            'client_visible_description' => 'Client-safe work',
        ]]];

        $first = $this->withHeader('Idempotency-Key', 'time-log-1')->postJson("/api/v1/workspaces/{$workspace->public_id}/time-entries", $payload)->assertCreated();
        $entry = $first->json('data.0');
        $this->withHeader('Idempotency-Key', 'time-log-1')->postJson("/api/v1/workspaces/{$workspace->public_id}/time-entries", $payload)->assertCreated()->assertJsonPath('data.0.id', $entry['id']);
        $this->assertDatabaseCount('client_time_entries', 1);
        $this->withHeader('Idempotency-Key', 'time-log-1')->postJson("/api/v1/workspaces/{$workspace->public_id}/time-entries", ['entries' => [[...$payload['entries'][0], 'minutes' => 46]]])->assertConflict();

        $updated = $this->withHeader('Idempotency-Key', 'time-update-1')->patchJson("/api/v1/workspaces/{$workspace->public_id}/time-entries/{$entry['id']}", ['expected_version' => $entry['version'], 'minutes' => 60])->assertOk()->json('data');
        $this->assertDatabaseHas('client_time_entries', ['public_id' => $entry['id'], 'client_visible_description' => 'Client-safe work']);
        $this->withHeader('Idempotency-Key', 'time-update-stale')->patchJson("/api/v1/workspaces/{$workspace->public_id}/time-entries/{$entry['id']}", ['expected_version' => $entry['version'], 'minutes' => 90])->assertConflict();
        $cleared = $this->withHeader('Idempotency-Key', 'time-update-clear')->patchJson("/api/v1/workspaces/{$workspace->public_id}/time-entries/{$entry['id']}", [
            'expected_version' => $updated['version'],
            'is_visible_to_client' => false,
            'client_visible_description' => null,
        ])->assertOk()->json('data');
        $this->assertDatabaseHas('client_time_entries', ['public_id' => $entry['id'], 'client_visible_description' => null]);
        $this->withHeader('Idempotency-Key', 'time-delete-1')->deleteJson("/api/v1/workspaces/{$workspace->public_id}/time-entries/{$entry['id']}", ['expected_version' => $cleared['version']])
            ->assertOk()->assertJsonPath('data.deleted_id', $entry['id']);
        $this->assertSoftDeleted('client_time_entries', ['public_id' => $entry['id']]);
        foreach (['time_entries.log', 'time_entries.update', 'time_entries.delete'] as $operation) {
            $this->assertDatabaseHas('agent_mutation_audits', ['operation' => $operation, 'outcome' => 'success']);
        }
        $this->assertDatabaseHas('agent_mutation_audits', ['operation' => 'time_entries.log', 'outcome' => 'replay']);
        $this->assertDatabaseHas('agent_mutation_audits', ['operation' => 'time_entries.update', 'outcome' => 'failed', 'error_category' => 'conflict']);
    }

    /**
     * The agent door refuses a payload that names a subject, and attributes the
     * entry to the token holder.
     *
     * The web door is pinned for the same property in `TimeSheetTest`, and the
     * two doors reach it differently: this one validates `entries.*` against an
     * explicit key allowlist, so an unknown field is a 422; the web request
     * simply never reads one, so it is dropped. Refusal is the stronger of the
     * two and is asserted as refusal rather than flattened into "the subject is
     * ignored", which would pass on either mechanism and notice neither
     * changing.
     *
     * Both are asserted because #101's whole subject is these two doors having
     * drifted apart on authorization while nothing compared them - and "a
     * contributor may log time for themselves" is only safe while neither door
     * takes a subject. This is what would have to give first.
     */
    public function test_the_agent_door_refuses_to_log_time_for_another_user(): void
    {
        config([
            'agent_api.writes_enabled' => false,
            'agent_api.time_entry_writes_enabled' => true,
        ]);
        [$workspace, $project] = $this->project();
        $contributor = User::factory()->create();
        $other = User::factory()->create();
        $this->member($workspace, $contributor);
        $this->member($workspace, $other);
        ClientProjectMembership::query()->create(['workspace_id' => $workspace->id, 'client_project_id' => $project->id, 'user_id' => $contributor->id, 'role' => 'contributor']);
        $this->actingAsAgent($contributor, [AgentApiScopes::TIME_WRITE]);

        $named = [
            'project_id' => $project->public_id,
            'worked_on' => '2026-08-23',
            'minutes' => 45,
            'description' => 'Logged for someone else',
        ];

        foreach (['user_id' => $other->id, 'user' => $other->public_id] as $field => $value) {
            $this->withHeader('Idempotency-Key', "time-log-subject-{$field}")
                ->postJson("/api/v1/workspaces/{$workspace->public_id}/time-entries", [
                    'entries' => [[...$named, $field => $value]],
                ])
                ->assertUnprocessable();
        }

        $this->assertDatabaseCount('client_time_entries', 0);

        // The same request without the subject is accepted, and lands on the
        // token holder - so the refusals above are about the extra field and
        // not about something else wrong with the payload.
        $this->withHeader('Idempotency-Key', 'time-log-self')
            ->postJson("/api/v1/workspaces/{$workspace->public_id}/time-entries", ['entries' => [$named]])
            ->assertCreated();

        $this->assertDatabaseHas('client_time_entries', ['user_id' => $contributor->id]);
        $this->assertDatabaseMissing('client_time_entries', ['user_id' => $other->id]);
    }

    public function test_client_visible_time_requires_explicit_client_facing_text(): void
    {
        config(['agent_api.writes_enabled' => true]);
        [$workspace, $project] = $this->project();
        $contributor = User::factory()->create();
        $this->member($workspace, $contributor);
        ClientProjectMembership::query()->create(['workspace_id' => $workspace->id, 'client_project_id' => $project->id, 'user_id' => $contributor->id, 'role' => 'contributor']);
        $this->actingAsAgent($contributor, [AgentApiScopes::TIME_WRITE]);

        $this->withHeader('Idempotency-Key', 'visible-without-text')->postJson("/api/v1/workspaces/{$workspace->public_id}/time-entries", ['entries' => [[
            'project_id' => $project->public_id,
            'worked_on' => '2026-08-23',
            'minutes' => 30,
            'description' => 'Internal implementation detail',
            'is_visible_to_client' => true,
        ]]])->assertUnprocessable()->assertJsonPath('message', 'Client-visible time requires an explicit client-facing description.');

        $entry = ClientTimeEntry::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $project->client_company_id,
            'client_project_id' => $project->id,
            'user_id' => $contributor->id,
            'worked_on' => '2026-08-23',
            'minutes' => 30,
            'description' => 'Internal implementation detail',
        ]);
        $this->withHeader('Idempotency-Key', 'visible-update-without-text')->patchJson("/api/v1/workspaces/{$workspace->public_id}/time-entries/{$entry->public_id}", [
            'expected_version' => AgentApiVersion::for($entry),
            'is_visible_to_client' => true,
        ])->assertUnprocessable()->assertJsonPath('message', 'Client-visible time requires an explicit client-facing description.');
    }

    public function test_time_write_cutoff_overrides_the_broad_write_flag(): void
    {
        config([
            'agent_api.writes_enabled' => true,
            'agent_api.time_entry_writes_enabled' => false,
        ]);
        [$workspace, $project] = $this->project();
        $contributor = User::factory()->create();
        $this->member($workspace, $contributor);
        ClientProjectMembership::query()->create(['workspace_id' => $workspace->id, 'client_project_id' => $project->id, 'user_id' => $contributor->id, 'role' => 'contributor']);
        $this->actingAsAgent($contributor, [AgentApiScopes::TIME_WRITE]);

        $this->withHeader('Idempotency-Key', 'disabled-time-write')->postJson(
            "/api/v1/workspaces/{$workspace->public_id}/time-entries",
            ['entries' => [[
                'project_id' => $project->public_id,
                'worked_on' => '2026-08-23',
                'minutes' => 30,
                'description' => 'Must stay disabled',
            ]]],
        )->assertNotFound();
        $this->assertDatabaseCount('client_time_entries', 0);
    }

    public function test_time_logging_rejects_invalid_currency_codes(): void
    {
        config([
            'agent_api.writes_enabled' => false,
            'agent_api.time_entry_writes_enabled' => true,
        ]);
        [$workspace, $project] = $this->project();
        $contributor = User::factory()->create();
        $this->member($workspace, $contributor);
        ClientProjectMembership::query()->create(['workspace_id' => $workspace->id, 'client_project_id' => $project->id, 'user_id' => $contributor->id, 'role' => 'contributor']);
        $this->actingAsAgent($contributor, [AgentApiScopes::TIME_WRITE]);

        $this->withHeader('Idempotency-Key', 'invalid-time-currency')->postJson(
            "/api/v1/workspaces/{$workspace->public_id}/time-entries",
            ['entries' => [[
                'project_id' => $project->public_id,
                'worked_on' => '2026-08-23',
                'minutes' => 30,
                'description' => 'Invalid currency',
                'currency' => '$$$',
            ]]],
        )->assertUnprocessable()->assertJsonValidationErrors('entries.0.currency');
        $this->assertDatabaseCount('client_time_entries', 0);
        $this->assertDatabaseHas('agent_mutation_audits', [
            'operation' => 'time_entries.log',
            'outcome' => 'failed',
            'error_category' => 'validation',
        ]);
    }

    public function test_invalid_time_update_is_audited_inside_the_mutation_boundary(): void
    {
        config([
            'agent_api.writes_enabled' => false,
            'agent_api.time_entry_writes_enabled' => true,
        ]);
        [$workspace, $project] = $this->project();
        $contributor = User::factory()->create();
        $this->member($workspace, $contributor);
        ClientProjectMembership::query()->create([
            'workspace_id' => $workspace->id,
            'client_project_id' => $project->id,
            'user_id' => $contributor->id,
            'role' => 'contributor',
        ]);
        $entry = ClientTimeEntry::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $project->client_company_id,
            'client_project_id' => $project->id,
            'user_id' => $contributor->id,
            'worked_on' => '2026-08-23',
            'minutes' => 30,
            'description' => 'Existing draft',
        ]);
        $this->actingAsAgent($contributor, [AgentApiScopes::TIME_WRITE]);

        $this->withHeader('Idempotency-Key', 'invalid-time-update')
            ->patchJson("/api/v1/workspaces/{$workspace->public_id}/time-entries/{$entry->public_id}", [
                'expected_version' => AgentApiVersion::for($entry),
                'minutes' => 0,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('minutes');

        $this->assertDatabaseHas('agent_mutation_audits', [
            'operation' => 'time_entries.update',
            'outcome' => 'failed',
            'error_category' => 'validation',
        ]);
    }

    public function test_project_viewer_cannot_log_time(): void
    {
        config(['agent_api.writes_enabled' => true]);
        [$workspace, $project] = $this->project();
        $viewer = User::factory()->create();
        $this->member($workspace, $viewer);
        ClientProjectMembership::query()->create(['workspace_id' => $workspace->id, 'client_project_id' => $project->id, 'user_id' => $viewer->id, 'role' => 'viewer']);
        $legacyDraft = ClientTimeEntry::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $project->client_company_id,
            'client_project_id' => $project->id,
            'user_id' => $viewer->id,
            'worked_on' => '2026-08-22',
            'minutes' => 15,
            'description' => 'Created before role downgrade',
        ]);
        $this->actingAsAgent($viewer, [AgentApiScopes::TIME_WRITE]);

        $this->withHeader('Idempotency-Key', 'viewer-time-denied')->postJson("/api/v1/workspaces/{$workspace->public_id}/time-entries", ['entries' => [[
            'project_id' => $project->public_id,
            'worked_on' => '2026-08-23',
            'minutes' => 30,
            'description' => 'Viewer may not log',
        ]]])->assertForbidden();
        $this->withHeader('Idempotency-Key', 'viewer-time-update-denied')->patchJson("/api/v1/workspaces/{$workspace->public_id}/time-entries/{$legacyDraft->public_id}", [
            'expected_version' => AgentApiVersion::for($legacyDraft),
            'minutes' => 30,
        ])->assertForbidden();
    }

    public function test_approved_time_cannot_be_updated_or_deleted(): void
    {
        config([
            'agent_api.writes_enabled' => false,
            'agent_api.time_entry_writes_enabled' => true,
        ]);
        [$workspace, $project] = $this->project();
        $contributor = User::factory()->create();
        $this->member($workspace, $contributor);
        ClientProjectMembership::query()->create(['workspace_id' => $workspace->id, 'client_project_id' => $project->id, 'user_id' => $contributor->id, 'role' => 'contributor']);
        $entry = ClientTimeEntry::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $project->client_company_id,
            'client_project_id' => $project->id,
            'user_id' => $contributor->id,
            'worked_on' => '2026-08-23',
            'minutes' => 30,
            'description' => 'Approved work',
            'status' => 'approved',
        ]);
        $this->actingAsAgent($contributor, [AgentApiScopes::TIME_WRITE]);

        $this->withHeader('Idempotency-Key', 'approved-time-update')->patchJson(
            "/api/v1/workspaces/{$workspace->public_id}/time-entries/{$entry->public_id}",
            ['expected_version' => AgentApiVersion::for($entry), 'minutes' => 45],
        )->assertConflict()->assertJsonPath('message', 'Only draft time entries can be changed.');
        $this->withHeader('Idempotency-Key', 'approved-time-delete')->deleteJson(
            "/api/v1/workspaces/{$workspace->public_id}/time-entries/{$entry->public_id}",
            ['expected_version' => AgentApiVersion::for($entry)],
        )->assertConflict()->assertJsonPath('message', 'Only draft time entries can be changed.');
        $this->assertNotSoftDeleted('client_time_entries', ['id' => $entry->id]);
    }

    public function test_project_manager_can_approve_but_contributor_cannot(): void
    {
        config(['agent_api.writes_enabled' => true]);
        [$workspace, $project] = $this->project();
        $worker = User::factory()->create();
        $manager = User::factory()->create();
        $this->member($workspace, $worker);
        $this->member($workspace, $manager);
        ClientProjectMembership::query()->create(['workspace_id' => $workspace->id, 'client_project_id' => $project->id, 'user_id' => $worker->id, 'role' => 'contributor']);
        ClientProjectMembership::query()->create(['workspace_id' => $workspace->id, 'client_project_id' => $project->id, 'user_id' => $manager->id, 'role' => 'manager']);
        $entry = ClientTimeEntry::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $project->client_company_id, 'client_project_id' => $project->id, 'user_id' => $worker->id, 'worked_on' => '2026-08-23', 'minutes' => 30, 'description' => 'Review me', 'currency' => 'USD']);
        $this->actingAsAgent($worker, [AgentApiScopes::TIME_APPROVE]);
        $this->withHeader('Idempotency-Key', 'time-approve-denied')->postJson("/api/v1/workspaces/{$workspace->public_id}/time-entries/approve", ['entries' => [['id' => $entry->public_id, 'expected_version' => AgentApiVersion::for($entry)]]])->assertForbidden();
        $this->actingAsAgent($manager, [AgentApiScopes::TIME_APPROVE]);
        $this->withHeader('Idempotency-Key', 'time-approve-1')->postJson("/api/v1/workspaces/{$workspace->public_id}/time-entries/approve", ['entries' => [[
            'id' => $entry->public_id,
            'expected_version' => AgentApiVersion::for($entry),
            'billing_rate_amount' => 17500,
            'currency' => 'USD',
        ]]])->assertOk();
        $this->assertDatabaseHas('client_time_entries', ['public_id' => $entry->public_id, 'status' => 'approved', 'billing_rate_amount' => 17500, 'currency' => 'USD']);
        $this->assertDatabaseHas('agent_mutation_audits', ['operation' => 'time_entries.approve', 'outcome' => 'failed', 'error_category' => 'forbidden']);
        $this->assertDatabaseHas('agent_mutation_audits', ['operation' => 'time_entries.approve', 'outcome' => 'success']);
    }

    /** @return array{Workspace, ClientProject} */
    private function project(): array
    {
        $workspace = Workspace::query()->create(['name' => 'Mutation Workspace', 'slug' => 'mutation-workspace']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Mutation Client', 'slug' => 'mutation-client']);

        return [$workspace, ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Mutation Project'])];
    }

    private function member(Workspace $workspace, User $user): void
    {
        WorkspaceMembership::query()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id, 'role' => 'member']);
    }

    /** @param list<string> $scopes */
    private function actingAsAgent(User $user, array $scopes): void
    {
        Passport::actingAs(AgentPrincipal::query()->findOrFail($user->id), $scopes);
    }
}
