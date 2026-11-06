<?php

namespace Tests\Feature\Approval;

use App\Models\ClientCompany;
use App\Models\ClientProject;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Support\AgentApi\AgentApiScopes;
use App\Support\AgentApi\AgentApiVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CallsMcp;
use Tests\TestCase;

/**
 * Nobody approves their own time, by any route an operator or an agent has:
 * REST and MCP `time_entries.approve`, and `time_entries.log` with the
 * deprecated `approve: true`. The portal and payment routes are pinned in
 * {@see ClientApprovalTest}.
 */
final class NoSelfApprovalPathsTest extends TestCase
{
    use CallsMcp;
    use RefreshDatabase;

    private Workspace $workspace;

    private ClientProject $project;

    private User $manager;

    private User $colleague;

    protected function setUp(): void
    {
        parent::setUp();
        config(['agent_api.mcp_enabled' => true, 'agent_api.writes_enabled' => true, 'agent_api.time_entry_writes_enabled' => true]);

        $this->workspace = Workspace::query()->create(['name' => 'Synthetic Approvals WS', 'slug' => 'synthetic-no-self-approval']);
        $company = ClientCompany::query()->create(['workspace_id' => $this->workspace->id, 'name' => 'Synthetic Client', 'slug' => 'synthetic-no-self-client']);
        $this->project = ClientProject::query()->create(['workspace_id' => $this->workspace->id, 'client_company_id' => $company->id, 'name' => 'Synthetic Project']);
        $this->manager = User::factory()->create();
        $this->colleague = User::factory()->create();
        $this->workspace->memberships()->create(['user_id' => $this->manager->id, 'role' => 'owner']);
        $this->workspace->memberships()->create(['user_id' => $this->colleague->id, 'role' => 'member']);
        $this->actingAsMcp($this->manager, array_keys(AgentApiScopes::descriptions()));
    }

    /** @return list<array{string}> */
    public static function transports(): array
    {
        return [['rest'], ['mcp']];
    }

    private function entry(User $author, string $day = '2026-10-14'): ClientTimeEntry
    {
        return ClientTimeEntry::query()->create([
            'workspace_id' => $this->workspace->id, 'client_company_id' => $this->project->client_company_id, 'client_project_id' => $this->project->id,
            'user_id' => $author->id, 'worked_on' => $day, 'minutes' => 60, 'description' => 'Synthetic work', 'status' => 'draft',
            'is_billable' => false, 'currency' => 'USD',
        ])->refresh();
    }

    /**
     * @param  list<ClientTimeEntry>  $entries
     * @return array{int, array<string, mixed>} status (or 0 for an MCP error) and body
     */
    private function approve(string $transport, array $entries, string $key): array
    {
        $payload = array_map(fn (ClientTimeEntry $entry): array => ['id' => $entry->public_id, 'expected_version' => AgentApiVersion::for($entry)], $entries);

        if ($transport === 'rest') {
            $response = $this->withHeader('Idempotency-Key', $key)->postJson("/api/v1/workspaces/{$this->workspace->public_id}/time-entries/approve", ['entries' => $payload]);

            return [$response->status(), (array) $response->json()];
        }

        $result = $this->callTool($this->initialize(), 'time_entries.approve', ['workspace_id' => $this->workspace->public_id, 'entries' => $payload, 'idempotency_key' => $key])['result'];

        return [$result['isError'] ? 0 : 200, $result];
    }

    #[DataProvider('transports')]
    public function test_a_manager_cannot_approve_their_own_entry(string $transport): void
    {
        $own = $this->entry($this->manager);

        [$status, $body] = $this->approve($transport, [$own], 'self-approve-1');

        $this->assertContains($status, [0, 409]);
        $this->assertStringContainsString('cannot approve your own time', json_encode($body, JSON_THROW_ON_ERROR));
        $fresh = $own->fresh();
        $this->assertSame('draft', $fresh->status);
        $this->assertNull($fresh->approved_by_user_id);
        $this->assertNull($fresh->approved_at);
    }

    #[DataProvider('transports')]
    public function test_one_of_their_own_entries_refuses_the_whole_batch(string $transport): void
    {
        $own = $this->entry($this->manager);
        $theirs = $this->entry($this->colleague);

        [$status] = $this->approve($transport, [$theirs, $own], 'self-approve-batch');

        $this->assertContains($status, [0, 409]);
        $this->assertSame('draft', $theirs->fresh()->status, 'Nothing is half approved.');
        $this->assertSame('draft', $own->fresh()->status);
    }

    #[DataProvider('transports')]
    public function test_a_colleagues_entry_is_still_approved_by_the_manager(string $transport): void
    {
        $theirs = $this->entry($this->colleague);

        [$status] = $this->approve($transport, [$theirs], 'approve-colleague');

        $this->assertSame(200, $status);
        $this->assertSame('approved', $theirs->fresh()->status);
        $this->assertSame($this->manager->id, $theirs->fresh()->approved_by_user_id);
    }

    #[DataProvider('transports')]
    public function test_the_colleague_cannot_approve_the_managers_entry_either_way_round(string $transport): void
    {
        // Authorship, not rank, decides: a second owner approves the first's time...
        $other = User::factory()->create();
        $this->workspace->memberships()->create(['user_id' => $other->id, 'role' => 'owner']);
        $own = $this->entry($this->manager);
        $this->actingAsMcp($other, array_keys(AgentApiScopes::descriptions()));

        [$status] = $this->approve($transport, [$own], 'second-owner-approves');
        $this->assertSame(200, $status);

        // ...and cannot approve their own.
        $mine = $this->entry($other);
        [$status] = $this->approve($transport, [$mine], 'second-owner-self');
        $this->assertContains($status, [0, 409]);
        $this->assertSame('draft', $mine->fresh()->status);
    }

    #[DataProvider('transports')]
    public function test_logging_with_approve_true_is_accepted_and_approves_nothing(string $transport): void
    {
        $entry = ['project_id' => $this->project->public_id, 'worked_on' => '2026-10-14', 'minutes' => 30, 'description' => 'Synthetic logged work'];

        if ($transport === 'rest') {
            $body = $this->withHeader('Idempotency-Key', 'log-approve-noop')->postJson("/api/v1/workspaces/{$this->workspace->public_id}/time-entries", ['approve' => true, 'entries' => [$entry]])
                ->assertCreated()->json();
        } else {
            $result = $this->callTool($this->initialize(), 'time_entries.log', ['workspace_id' => $this->workspace->public_id, 'idempotency_key' => 'log-approve-noop', 'approve' => true, 'entries' => [$entry]])['result'];
            $this->assertFalse($result['isError']);
            $body = $result['structuredContent'];
        }

        $id = $body['data'][0]['id'];
        $this->assertSame(['requested' => true, 'applied' => false, 'skipped_ids' => [$id]], array_diff_key($body['meta']['approval'], ['note' => true]));
        $this->assertStringContainsString('draft invoice', $body['meta']['approval']['note']);
        $this->assertDatabaseHas('client_time_entries', ['public_id' => $id, 'status' => 'draft', 'approved_by_user_id' => null, 'approved_at' => null]);
        $this->assertDatabaseMissing('agent_mutation_audits', ['operation' => 'time_entries.approve']);
    }

    public function test_the_time_sheet_does_not_offer_approval_of_your_own_entry(): void
    {
        $own = $this->entry($this->manager);
        $theirs = $this->entry($this->colleague);
        $company = $this->project->clientCompany;

        $this->actingAs($this->manager, 'web')->get("/workspaces/{$this->workspace->public_id}/clients/{$company->public_id}/time")->assertOk()
            ->assertInertia(function ($page) use ($own, $theirs): void {
                $rows = collect($page->toArray()['props']['months'])->flatMap(fn (array $month): array => $month['entries'])->keyBy('id');
                $this->assertFalse($rows[$own->public_id]['can_approve']);
                $this->assertTrue($rows[$theirs->public_id]['can_approve']);
            });
    }
}
