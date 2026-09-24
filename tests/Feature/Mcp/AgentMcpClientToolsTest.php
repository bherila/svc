<?php

namespace Tests\Feature\Mcp;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientProject;
use App\Models\User;
use App\Models\Workspace;
use App\Support\AgentApi\AgentApiScopes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CallsMcp;
use Tests\TestCase;

final class AgentMcpClientToolsTest extends TestCase
{
    use CallsMcp;
    use RefreshDatabase;

    private const array READ_SCOPES = [
        AgentApiScopes::MCP_USE,
        AgentApiScopes::IDENTITY_READ,
        AgentApiScopes::CLIENTS_READ,
        AgentApiScopes::BILLING_READ,
    ];

    public function test_a_manager_lists_and_reads_clients_with_their_delivery_settings(): void
    {
        [$workspace, $owner] = $this->workspaceWithOwner();
        $active = $this->client($workspace, 'Synthetic Active Client', billingEmail: 'billing@synthetic.example.test');
        $archived = $this->client($workspace, 'Synthetic Archived Client', active: false);
        $this->actingAsMcp($owner, self::READ_SCOPES);
        $session = $this->initialize();

        $all = $this->callTool($session, 'clients.list', ['workspace_id' => $workspace->public_id]);
        $this->assertFalse($all['result']['isError']);
        $this->assertEqualsCanonicalizing(
            [$active->public_id, $archived->public_id],
            array_column($all['result']['structuredContent']['data'], 'id'),
        );

        $onlyArchived = $this->callTool($session, 'clients.list', ['workspace_id' => $workspace->public_id, 'status' => 'archived']);
        $this->assertSame([$archived->public_id], array_column($onlyArchived['result']['structuredContent']['data'], 'id'));

        $one = $this->callTool($session, 'clients.get', ['workspace_id' => $workspace->public_id, 'client_id' => $active->public_id]);
        $this->assertSame([
            'id' => $active->public_id,
            'name' => 'Synthetic Active Client',
            'billing_email' => 'billing@synthetic.example.test',
            'is_active' => true,
            'automatic_invoice_email_enabled' => false,
            'automatic_invoice_email_delay_days' => null,
        ], $one['result']['structuredContent']['data']);
    }

    public function test_pagination_walks_every_client_exactly_once(): void
    {
        [$workspace, $owner] = $this->workspaceWithOwner();
        $ids = collect(range(1, 3))->map(fn (int $n): string => $this->client($workspace, "Synthetic Client {$n}")->public_id)->all();
        $this->actingAsMcp($owner, self::READ_SCOPES);
        $session = $this->initialize();

        $seen = [];
        $cursor = null;
        do {
            $page = $this->callTool($session, 'clients.list', ['workspace_id' => $workspace->public_id, 'limit' => 1, 'cursor' => $cursor])['result']['structuredContent'];
            $this->assertCount(1, $page['data']);
            $seen[] = $page['data'][0]['id'];
            $cursor = $page['meta']['next_cursor'];
        } while ($cursor !== null);

        $this->assertSame($ids, $seen);
    }

    public function test_a_client_in_another_workspace_is_not_reachable_by_id_or_listing(): void
    {
        [$workspace, $owner] = $this->workspaceWithOwner();
        [$other] = $this->workspaceWithOwner();
        $mine = $this->client($workspace, 'Synthetic Mine');
        $foreign = $this->client($other, 'Synthetic Foreign');
        $this->actingAsMcp($owner, self::READ_SCOPES);
        $session = $this->initialize();

        $listed = $this->callTool($session, 'clients.list', ['workspace_id' => $workspace->public_id]);
        $this->assertSame([$mine->public_id], array_column($listed['result']['structuredContent']['data'], 'id'));

        $this->assertToolFailed($this->callTool($session, 'clients.get', ['workspace_id' => $workspace->public_id, 'client_id' => $foreign->public_id]));
        $this->assertToolFailed($this->callTool($session, 'clients.list', ['workspace_id' => $other->public_id]));
    }

    public function test_a_plain_member_cannot_read_clients(): void
    {
        [$workspace] = $this->workspaceWithOwner();
        $member = User::factory()->create();
        $workspace->memberships()->create(['user_id' => $member->id, 'role' => 'member']);
        $client = $this->client($workspace, 'Synthetic Hidden');
        $this->actingAsMcp($member, self::READ_SCOPES);
        $session = $this->initialize();

        $this->assertNotContains('clients.list', $this->toolNames($session));
        $this->assertToolFailed($this->callTool($session, 'clients.get', ['workspace_id' => $workspace->public_id, 'client_id' => $client->public_id]));
    }

    public function test_a_connection_without_the_clients_scope_neither_sees_nor_calls_the_tools(): void
    {
        [$workspace, $owner] = $this->workspaceWithOwner();
        $client = $this->client($workspace, 'Synthetic Scoped');
        $this->actingAsMcp($owner, [AgentApiScopes::MCP_USE, AgentApiScopes::IDENTITY_READ, AgentApiScopes::BILLING_READ]);
        $session = $this->initialize();

        $names = $this->toolNames($session);
        $this->assertNotContains('clients.list', $names);
        $this->assertNotContains('clients.get', $names);
        $this->assertToolFailed($this->callTool($session, 'clients.get', ['workspace_id' => $workspace->public_id, 'client_id' => $client->public_id]));
    }

    public function test_an_agreement_names_the_client_and_project_it_belongs_to(): void
    {
        [$workspace, $owner] = $this->workspaceWithOwner();
        $client = $this->client($workspace, 'Synthetic Named Client');
        $project = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $client->id, 'name' => 'Synthetic Project']);
        $scoped = $this->agreement($workspace, $client, $project);
        $companyWide = $this->agreement($workspace, $client, null);
        $this->actingAsMcp($owner, self::READ_SCOPES);
        $session = $this->initialize();

        $get = fn (ClientAgreement $agreement): array => $this->callTool($session, 'agreements.get', ['workspace_id' => $workspace->public_id, 'agreement_id' => $agreement->public_id])['result']['structuredContent']['data'];

        $this->assertSame($client->public_id, $get($scoped)['client_id']);
        $this->assertSame('Synthetic Named Client', $get($scoped)['client_name']);
        $this->assertSame($project->public_id, $get($scoped)['project_id']);
        $this->assertSame($client->public_id, $get($companyWide)['client_id']);
        $this->assertNull($get($companyWide)['project_id'], 'a company-wide agreement has no project');
    }

    /** @param array<string, mixed> $body */
    private function assertToolFailed(array $body): void
    {
        $this->assertTrue(
            isset($body['error']) || ($body['result']['isError'] ?? false) === true,
            'Expected the call to be refused, got: '.json_encode($body),
        );
    }

    /** @return array{0: Workspace, 1: User} */
    private function workspaceWithOwner(): array
    {
        $owner = User::factory()->create();
        $workspace = Workspace::query()->create(['name' => 'Synthetic Clients Workspace', 'slug' => 'synthetic-clients-'.uniqid()]);
        $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);

        return [$workspace, $owner];
    }

    private function client(Workspace $workspace, string $name, ?string $billingEmail = null, bool $active = true): ClientCompany
    {
        return ClientCompany::query()->create([
            'workspace_id' => $workspace->id,
            'name' => $name,
            'slug' => 'synthetic-client-'.uniqid(),
            'billing_email' => $billingEmail,
            'is_active' => $active,
        ]);
    }

    private function agreement(Workspace $workspace, ClientCompany $client, ?ClientProject $project): ClientAgreement
    {
        return ClientAgreement::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $client->id,
            'client_project_id' => $project?->id,
            'title' => 'Synthetic agreement',
            'status' => 'active',
            'currency' => 'USD',
            'billing_cadence' => 'monthly',
            'starts_on' => '2026-01-01',
        ]);
    }
}
