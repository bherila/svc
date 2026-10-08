<?php

namespace Tests\Feature\AgentApi;

use App\Models\ClientCompany;
use App\Models\ClientCompanyMembership;
use App\Models\ClientProject;
use App\Models\ClientProposal;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Authorization\PortalAccess;
use App\Services\Engagement\ProposalWorkflow;
use App\Support\AgentApi\AgentApiResponseSchemaCatalog;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\Concurrency\LockOrderRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mcp\Capability\Discovery\SchemaValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CallsMcp;
use Tests\Concerns\WritesLegacyCrossTenantRows;
use Tests\TestCase;

final class AgentProposalApiTest extends TestCase
{
    use CallsMcp;
    use RefreshDatabase;
    use WritesLegacyCrossTenantRows;

    protected function setUp(): void
    {
        parent::setUp();
        config(['agent_api.writes_enabled' => true, 'agent_api.proposal_writes_enabled' => true]);
    }

    public function test_public_client_revision_can_create_a_proposal_and_replay_through_mcp(): void
    {
        [$workspace, $company, , $owner] = $this->fixture();
        $this->agent($owner, ['mcp:use', 'clients:read', 'proposals:read', 'proposals:write']);
        $read = $this->getJson('/api/v1/workspaces/'.$workspace->public_id.'/clients/'.$company->public_id)->assertOk()->json('data');
        $this->assertIsString($read['version']);
        $session = $this->initialize();
        $mcp = $this->callTool($session, 'clients.get', ['workspace_id' => $workspace->public_id, 'client_id' => $company->public_id]);
        $this->assertSame($read, $mcp['result']['structuredContent']['data']);
        $body = ['expected_version' => $read['version']] + $this->facts($company);
        $created = $this->postJson($this->url($workspace), $body, ['Idempotency-Key' => 'synthetic-public-client-revision'])->assertCreated()->json('data');
        $replay = $this->callTool($session, 'proposals.create', ['workspace_id' => $workspace->public_id, 'idempotency_key' => 'synthetic-public-client-revision', ...$body]);
        $this->assertFalse($replay['result']['isError'] ?? true, json_encode($replay));
        $this->assertSame($created, $replay['result']['structuredContent']['data']);
        $this->assertDatabaseCount('client_proposals', 1);
        foreach (['success', 'replay'] as $outcome) {
            $this->assertDatabaseHas('agent_mutation_audits', ['operation' => 'proposals.create', 'outcome' => $outcome]);
        }
    }

    public function test_create_send_accept_share_the_web_workflow_and_are_idempotent_audited_and_versioned(): void
    {
        [$workspace, $company, $project, $owner] = $this->fixture();
        $this->agent($owner);
        $body = $this->facts($company) + ['project_id' => $project->public_id];
        $created = $this->withHeader('Idempotency-Key', 'synthetic-create')->postJson($this->url($workspace), $body)->assertCreated();
        $this->schema('proposals.create', $created->json());
        $id = $created->json('data.id');
        $version = $created->json('data.version');
        $created->assertJsonPath('data.status', 'draft')->assertJsonPath('data.total_amount', 1875)->assertJsonPath('data.project_id', $project->public_id);
        $this->postJson($this->url($workspace), $body)->assertCreated()->assertJsonPath('data.id', $id);
        $this->assertDatabaseCount('client_proposals', 1);
        $changed = $body;
        $changed['title'] = 'Changed synthetic proposal';
        $this->postJson($this->url($workspace), $changed)->assertConflict();
        $sentBody = ['expected_version' => $version, 'confirm' => true];
        $sent = $this->withHeader('Idempotency-Key', 'synthetic-send')->postJson($this->url($workspace, $id).'/send', $sentBody)->assertOk();
        $sent->assertJsonPath('data.status', 'sent')->assertJsonPath('data.is_visible_to_client', true);
        $this->assertNotSame($version, $sent->json('data.version'));
        $this->postJson($this->url($workspace, $id).'/send', $sentBody)->assertOk();
        $this->withHeader('Idempotency-Key', 'synthetic-stale-send')->postJson($this->url($workspace, $id).'/send', $sentBody)->assertConflict();
        $portal = $this->portal($company);
        $this->agent($portal, ['proposals:read', 'proposals:accept']);
        $this->getJson($this->url($workspace, $id))->assertOk()->assertJsonPath('data.version', $sent->json('data.version'));
        $acceptBody = ['expected_version' => $sent->json('data.version'), 'confirm' => true, 'signer_name' => 'Synthetic Signer', 'signer_title' => 'Synthetic Director'];
        $accepted = $this->withHeader('Idempotency-Key', 'synthetic-accept')->postJson($this->url($workspace, $id).'/accept', $acceptBody)->assertOk();
        $this->schema('proposals.accept', $accepted->json());
        $accepted->assertJsonPath('data.status', 'accepted');
        $this->assertNotSame($sent->json('data.version'), $accepted->json('data.version'));
        $this->postJson($this->url($workspace, $id).'/accept', $acceptBody)->assertOk();
        $this->postJson($this->url($workspace, $id).'/accept', array_replace($acceptBody, ['signer_name' => 'Another synthetic signer']))->assertConflict();
        $this->assertDatabaseCount('client_agreements', 1);
        $this->assertDatabaseHas('client_agreements', ['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'status' => 'active', 'signer_name' => 'Synthetic Signer', 'signed_by_user_id' => $portal->id]);
        $this->assertDatabaseHas('client_agreement_recurring_items', ['workspace_id' => $workspace->id, 'description' => 'Synthetic work', 'amount' => 1250]);
        foreach (['proposals.create', 'proposals.send', 'proposals.accept'] as $operation) {
            foreach (['success', 'replay'] as $outcome) {
                $this->assertDatabaseHas('agent_mutation_audits', ['workspace_id' => $workspace->id, 'operation' => $operation, 'outcome' => $outcome]);
            }
        }
        $this->assertDatabaseHas('agent_mutation_audits', ['operation' => 'proposals.send', 'outcome' => 'failed', 'error_category' => 'conflict']);
    }

    public function test_create_checks_company_version_and_scopes_company_and_project_references(): void
    {
        [$workspace, $company, , $owner] = $this->fixture();
        [, $foreignCompany, $foreignProject] = $this->fixture();
        $otherCompany = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Other synthetic company', 'slug' => 'other-'.Str::uuid()]);
        $otherProject = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $otherCompany->id, 'name' => 'Other synthetic project']);
        $this->agent($owner);
        foreach ([$foreignProject, $otherProject] as $project) {
            $this->withHeader('Idempotency-Key', 'foreign-project-'.$project->public_id)->postJson($this->url($workspace), $this->facts($company) + ['project_id' => $project->public_id])->assertNotFound();
        }
        $this->withHeader('Idempotency-Key', 'foreign-company')->postJson($this->url($workspace), $this->facts($foreignCompany))->assertNotFound();
        $body = $this->facts($company);
        $company->update(['name' => 'Revised synthetic company']);
        $this->withHeader('Idempotency-Key', 'stale-company')->postJson($this->url($workspace), $body)->assertConflict();
        $this->assertDatabaseCount('client_proposals', 0);
        $this->assertDatabaseCount('agent_mutation_receipts', 0);
    }

    #[DataProvider('requiredInputs')]
    public function test_mutations_require_keys_versions_confirmation_and_explicit_signer(string $operation, string $missing): void
    {
        [$workspace, $company, , $owner] = $this->fixture();
        $this->agent($owner);
        $proposal = $this->proposal($workspace, $company, $owner, sent: $operation === 'accept');
        $body = $operation === 'create' ? $this->facts($company) : ['expected_version' => AgentApiVersion::for($proposal), 'confirm' => true];
        if ($operation === 'accept') {
            $body['signer_name'] = 'Synthetic Signer';
        }
        if ($missing !== 'key') {
            unset($body[$missing]);
            $this->withHeader('Idempotency-Key', 'missing-'.$operation.'-'.$missing);
        }
        $url = $operation === 'create' ? $this->url($workspace) : $this->url($workspace, $proposal->public_id).'/'.$operation;
        $this->postJson($url, $body)->assertUnprocessable();
        $this->assertDatabaseCount('client_agreements', 0);
        $this->assertDatabaseCount('agent_mutation_receipts', 0);
    }

    public function test_proposal_lifecycle_requires_literal_boolean_confirmation(): void
    {
        [$workspace, $company, , $owner] = $this->fixture();
        $this->agent($owner);
        foreach (['send', 'accept'] as $operation) {
            $proposal = $this->proposal($workspace, $company, $owner, sent: $operation === 'accept');
            foreach ([false, 'true', 'yes', 'on', 1, '1'] as $index => $confirmation) {
                $this->withHeader('Idempotency-Key', 'synthetic-confirm-'.$operation.'-'.$index)
                    ->postJson($this->url($workspace, $proposal->public_id).'/'.$operation, [
                        'expected_version' => AgentApiVersion::for($proposal), 'confirm' => $confirmation,
                        ...($operation === 'accept' ? ['signer_name' => 'Synthetic signer'] : []),
                    ])->assertUnprocessable();
            }
            $this->assertSame($operation === 'accept' ? 'sent' : 'draft', $proposal->fresh()->status);
        }
        $this->assertDatabaseCount('agent_mutation_receipts', 0);
        $this->assertDatabaseCount('client_agreements', 0);
    }

    public function test_signed_in_browser_reads_proposals_while_agent_writes_are_disabled(): void
    {
        [$workspace, $company, , $owner] = $this->fixture();
        $proposal = $this->proposal($workspace, $company, $owner);
        config(['agent_api.writes_enabled' => false, 'agent_api.proposal_writes_enabled' => false]);
        $this->actingAs($owner)->withCredentials()
            ->withUnencryptedCookie((string) config('session.cookie'), Str::random(40));
        $this->getJson($this->url($workspace))->assertOk()->assertJsonPath('data.0.id', $proposal->public_id);
        $this->getJson($this->url($workspace, $proposal->public_id))->assertOk()->assertJsonPath('data.id', $proposal->public_id);
    }

    public function test_proposal_workspace_refusals_match_without_scopes_or_enabled_writes(): void
    {
        config(['app.debug' => false]);
        [, , , $owner] = $this->fixture();
        [$foreign, $company, , $foreignOwner] = $this->fixture();
        $proposal = $this->proposal($foreign, $company, $foreignOwner, sent: true);
        $missing = Str::uuid()->toString();
        $requests = [['GET', '', []], ['GET', '/'.$proposal->public_id, []], ['POST', '', $this->facts($company)],
            ['POST', '/'.$proposal->public_id.'/send', ['expected_version' => AgentApiVersion::for($proposal), 'confirm' => true]],
            ['POST', '/'.$proposal->public_id.'/accept', ['expected_version' => AgentApiVersion::for($proposal), 'confirm' => true, 'signer_name' => 'Synthetic signer']]];
        foreach ([[['proposals:read', 'proposals:write', 'proposals:accept', 'clients:read'], true], [[], true], [['proposals:read', 'proposals:write', 'proposals:accept', 'clients:read'], false]] as [$scopes, $writes]) {
            $this->agent($owner, $scopes);
            config(['agent_api.writes_enabled' => $writes, 'agent_api.proposal_writes_enabled' => $writes]);
            foreach ($requests as [$method, $path, $body]) {
                $known = $this->json($method, '/api/v1/workspaces/'.$foreign->public_id.'/proposals'.$path, $body, ['Idempotency-Key' => 'synthetic-foreign'])->assertNotFound();
                $unknown = $this->json($method, '/api/v1/workspaces/'.$missing.'/proposals'.$path, $body, ['Idempotency-Key' => 'synthetic-missing'])->assertNotFound();
                $this->assertSame($known->json(), $unknown->json());
                $this->assertSame($known->headers->get('Cache-Control'), $unknown->headers->get('Cache-Control'));
                $this->assertStringContainsString('no-store', $unknown->headers->get('Cache-Control'));
            }
        }
        $this->assertDatabaseCount('agent_mutation_receipts', 0);
    }

    public function test_proposal_writes_require_the_read_scope_for_their_revision(): void
    {
        [$workspace, $company, , $owner] = $this->fixture();
        $proposal = $this->proposal($workspace, $company, $owner, sent: true);
        foreach (['create', 'send', 'accept'] as $operation) {
            $this->agent($owner, ['proposals:write', 'proposals:accept']);
            $body = $operation === 'create' ? $this->facts($company) : ['expected_version' => AgentApiVersion::for($proposal), 'confirm' => true, ...($operation === 'accept' ? ['signer_name' => 'Synthetic signer'] : [])];
            $url = $operation === 'create' ? $this->url($workspace) : $this->url($workspace, $proposal->public_id).'/'.$operation;
            $this->withHeader('Idempotency-Key', 'synthetic-read-scope-'.$operation)->postJson($url, $body)->assertForbidden();
        }
        $this->assertDatabaseCount('agent_mutation_receipts', 0);
    }

    public static function requiredInputs(): array
    {
        return [['create', 'key'], ['create', 'expected_version'], ['send', 'key'], ['send', 'expected_version'], ['send', 'confirm'], ['accept', 'key'], ['accept', 'expected_version'], ['accept', 'confirm'], ['accept', 'signer_name']];
    }

    #[DataProvider('refusedRoles')]
    public function test_only_workspace_managers_create_or_send(string $role): void
    {
        [$workspace, $company, , $owner] = $this->fixture();
        $actor = User::factory()->create();
        if ($role === 'portal') {
            $actor = $this->portal($company);
        } else {
            $workspace->memberships()->create(['user_id' => $actor->id, 'role' => $role]);
        }
        $proposal = $this->proposal($workspace, $company, $owner);
        $this->agent($actor);
        $this->withHeader('Idempotency-Key', 'role-create')->postJson($this->url($workspace), $this->facts($company))->assertForbidden();
        $this->withHeader('Idempotency-Key', 'role-send')->postJson($this->url($workspace, $proposal->public_id).'/send', ['expected_version' => AgentApiVersion::for($proposal), 'confirm' => true])->assertForbidden();
        $this->assertSame('draft', $proposal->fresh()->status);
        $this->assertDatabaseHas('agent_mutation_audits', ['operation' => 'proposals.send', 'outcome' => 'failed', 'error_category' => 'forbidden']);
    }

    public static function refusedRoles(): array
    {
        return [['member'], ['subcontractor'], ['portal']];
    }

    #[DataProvider('operations')]
    public function test_scopes_are_explicit_and_older_scopes_never_authorize_proposals(string $operation): void
    {
        [$workspace, $company, , $owner] = $this->fixture();
        $proposal = $this->proposal($workspace, $company, $owner, sent: true);
        $this->agent($owner, ['projects:read', 'tasks:write', 'clients:read', 'clients:write', 'billing:read', 'billing:deliver']);
        $url = $this->url($workspace, in_array($operation, ['create', 'list'], true) ? null : $proposal->public_id);
        if (in_array($operation, ['send', 'accept'], true)) {
            $url .= '/'.$operation;
        }
        if (in_array($operation, ['list', 'get'], true)) {
            $this->getJson($url)->assertForbidden();
        } else {
            $this->withHeader('Idempotency-Key', 'scope-'.$operation)->postJson($url, $this->facts($company))->assertForbidden();
        }
    }

    public static function operations(): array
    {
        return [['create'], ['send'], ['accept'], ['list'], ['get']];
    }

    public function test_portal_company_and_project_grants_apply_to_reads_acceptance_and_replay(): void
    {
        [$workspace, $company, $project, $owner] = $this->fixture();
        $allowed = $this->proposal($workspace, $company, $owner, $project, true);
        $companyWide = $this->proposal($workspace, $company, $owner, sent: true);
        $hidden = $this->proposal($workspace, $company, $owner);
        $otherProject = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Hidden synthetic project']);
        $denied = $this->proposal($workspace, $company, $owner, $otherProject, true);
        [$foreign, $foreignCompany, , $foreignOwner] = $this->fixture();
        $foreignProposal = $this->proposal($foreign, $foreignCompany, $foreignOwner, sent: true);
        $portal = $this->portal($company, $project);
        $this->agent($portal);
        $list = $this->getJson($this->url($workspace))->assertOk();
        $this->assertEqualsCanonicalizing([$allowed->public_id, $companyWide->public_id], array_column($list->json('data'), 'id'));
        foreach ([$hidden, $denied, $foreignProposal] as $record) {
            $this->getJson($this->url($workspace, $record->public_id))->assertNotFound();
            $this->withHeader('Idempotency-Key', 'denied-'.$record->public_id)->postJson($this->url($workspace, $record->public_id).'/accept', ['expected_version' => AgentApiVersion::for($record), 'confirm' => true, 'signer_name' => 'Synthetic Signer'])->assertNotFound();
        }
        $this->getJson($this->url($foreign))->assertNotFound();
        $body = ['expected_version' => AgentApiVersion::for($allowed), 'confirm' => true, 'signer_name' => 'Synthetic Signer'];
        $this->withHeader('Idempotency-Key', 'authorized-accept')->postJson($this->url($workspace, $allowed->public_id).'/accept', $body)->assertOk();
        DB::table('client_portal_project_access')->where('workspace_id', $workspace->id)->delete();
        $this->postJson($this->url($workspace, $allowed->public_id).'/accept', $body)->assertNotFound();
        $this->assertDatabaseCount('client_agreements', 1);
        $this->assertDatabaseHas('agent_mutation_audits', ['operation' => 'proposals.accept', 'outcome' => 'failed', 'error_category' => 'not_found']);
    }

    public function test_web_acceptance_enforces_the_same_portal_project_scope(): void
    {
        [$workspace, $company, $project, $owner] = $this->fixture();
        $other = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Unassigned synthetic project']);
        $proposal = $this->proposal($workspace, $company, $owner, $other, true);
        $portal = $this->portal($company, $project);
        $this->actingAs($portal)->postJson('/portal/'.$company->public_id.'/proposals/'.$proposal->public_id.'/accept', ['signer_name' => 'Synthetic Signer'])->assertNotFound();
        $this->assertDatabaseCount('client_agreements', 0);
    }

    public function test_acceptance_refuses_false_confirmation_stale_version_expiry_and_plain_workspace_members(): void
    {
        [$workspace, $company, , $owner] = $this->fixture();
        $proposal = $this->proposal($workspace, $company, $owner, sent: true);
        $this->agent($owner);
        $body = ['expected_version' => AgentApiVersion::for($proposal), 'confirm' => true, 'signer_name' => 'Synthetic Signer'];
        $this->withHeader('Idempotency-Key', 'false-confirm')->postJson($this->url($workspace, $proposal->public_id).'/accept', array_replace($body, ['confirm' => false]))->assertUnprocessable();
        $proposal->update(['title' => 'Updated synthetic proposal']);
        $this->withHeader('Idempotency-Key', 'stale-accept')->postJson($this->url($workspace, $proposal->public_id).'/accept', $body)->assertConflict();
        $proposal->update(['valid_until' => '2000-01-01']);
        $body['expected_version'] = AgentApiVersion::for($proposal->fresh());
        $this->withHeader('Idempotency-Key', 'expired-accept')->postJson($this->url($workspace, $proposal->public_id).'/accept', $body)->assertUnprocessable()->assertJsonPath('message', 'This proposal has expired.');
        $member = User::factory()->create();
        $workspace->memberships()->create(['user_id' => $member->id, 'role' => 'member']);
        $this->agent($member);
        $this->withHeader('Idempotency-Key', 'member-accept')->postJson($this->url($workspace, $proposal->public_id).'/accept', $body)->assertNotFound();
        $this->assertDatabaseCount('client_agreements', 0);
        $this->assertDatabaseCount('agent_mutation_receipts', 0);
    }

    public function test_manager_replay_rechecks_role_and_portal_replay_rechecks_membership(): void
    {
        [$workspace, $company, , $owner] = $this->fixture();
        $this->agent($owner);
        $body = $this->facts($company);
        $this->withHeader('Idempotency-Key', 'revoke-create')->postJson($this->url($workspace), $body)->assertCreated();
        $workspace->memberships()->where('user_id', $owner->id)->update(['role' => 'member']);
        $this->postJson($this->url($workspace), $body)->assertForbidden();
        $this->assertDatabaseCount('client_proposals', 1);
    }

    public function test_pagination_cursors_are_bound_to_workspace_and_filters(): void
    {
        [$workspace, $company, , $owner] = $this->fixture();
        $this->proposal($workspace, $company, $owner);
        $this->proposal($workspace, $company, $owner);
        $this->agent($owner);
        $first = $this->getJson($this->url($workspace).'?limit=1')->assertOk();
        $this->schema('proposals.list', $first->json());
        $cursor = $first->json('meta.next_cursor');
        $this->assertIsString($cursor);
        $second = $this->getJson($this->url($workspace).'?limit=1&cursor='.urlencode($cursor))->assertOk();
        $this->assertNotSame($first->json('data.0.id'), $second->json('data.0.id'));
        $second->assertJsonPath('meta.next_cursor', null);
        $this->getJson($this->url($workspace).'?status=sent&cursor='.urlencode($cursor))->assertUnprocessable();
        $this->getJson($this->url($workspace).'?limit=101')->assertUnprocessable();
    }

    #[DataProvider('cutovers')]
    public function test_cutovers_withhold_writes_and_capabilities_while_reads_remain(bool $outer, bool $inner): void
    {
        [$workspace, $company, , $owner] = $this->fixture();
        config(['agent_api.writes_enabled' => $outer, 'agent_api.proposal_writes_enabled' => $inner]);
        $this->agent($owner, ['identity:read', 'mcp:use', 'clients:read', 'proposals:read', 'proposals:write', 'proposals:accept']);
        $this->withHeader('Idempotency-Key', 'cutover-create')->postJson($this->url($workspace), $this->facts($company))->assertNotFound();
        $this->getJson($this->url($workspace))->assertOk();
        $proposal = $this->proposal($workspace, $company, $owner, sent: true);
        foreach (['send', 'accept'] as $operation) {
            $this->withHeader('Idempotency-Key', 'cutover-'.$operation)->postJson($this->url($workspace, $proposal->public_id).'/'.$operation, ['expected_version' => AgentApiVersion::for($proposal), 'confirm' => true, 'signer_name' => 'Synthetic signer'])->assertNotFound();
        }
        $session = $this->initialize();
        $names = $this->toolNames($session);
        $this->assertContains('proposals.list', $names);
        foreach (['proposals.create', 'proposals.send', 'proposals.accept'] as $name) {
            $this->assertNotContains($name, $names);
        }
        $context = $this->getJson('/api/v1/context')->assertOk()->json('data.workspaces.0.capabilities');
        $this->assertContains('proposals:read', $context);
        $this->assertNotContains('proposals:write', $context);
        $this->assertNotContains('proposals:accept', $context);
    }

    public static function cutovers(): array
    {
        return [[false, false], [false, true], [true, false]];
    }

    public function test_mcp_create_send_accept_and_reads_use_the_same_contract_and_confirmation(): void
    {
        [$workspace, $company, , $owner] = $this->fixture();
        $this->agent($owner, ['mcp:use', 'proposals:read', 'proposals:write', 'proposals:accept', 'clients:read']);
        $session = $this->initialize();
        $created = $this->callTool($session, 'proposals.create', $this->facts($company) + ['workspace_id' => $workspace->public_id, 'idempotency_key' => 'mcp-proposal-create']);
        $this->assertArrayNotHasKey('error', $created);
        $this->assertFalse($created['result']['isError'] ?? false, json_encode($created));
        $data = $created['result']['structuredContent']['data'];
        $this->schema('proposals.create', ['data' => $data]);
        $arguments = ['workspace_id' => $workspace->public_id, 'proposal_id' => $data['id'], 'expected_version' => $data['version'], 'confirm' => false, 'idempotency_key' => 'mcp-proposal-send'];
        $refused = $this->callTool($session, 'proposals.send', $arguments);
        $this->assertTrue(isset($refused['error']) || ($refused['result']['isError'] ?? false));
        $arguments['confirm'] = true;
        $sent = $this->callTool($session, 'proposals.send', $arguments);
        $sentData = $sent['result']['structuredContent']['data'];
        $this->assertSame('sent', $sentData['status']);
        $read = $this->callTool($session, 'proposals.get', ['workspace_id' => $workspace->public_id, 'proposal_id' => $data['id']]);
        $this->assertSame($sentData, $read['result']['structuredContent']['data']);
        $accept = ['workspace_id' => $workspace->public_id, 'proposal_id' => $data['id'], 'expected_version' => $sentData['version'], 'confirm' => true, 'signer_name' => 'Synthetic offline signer', 'idempotency_key' => 'mcp-proposal-accept'];
        $accepted = $this->callTool($session, 'proposals.accept', $accept);
        $this->assertSame('accepted', $accepted['result']['structuredContent']['data']['status']);
        $this->callTool($session, 'proposals.accept', $accept);
        $this->assertDatabaseCount('client_agreements', 1);
        $this->assertDatabaseHas('agent_mutation_audits', ['operation' => 'proposals.accept', 'outcome' => 'replay']);
    }

    public function test_every_proposal_contract_declares_explicit_oauth_and_api_token_alternatives(): void
    {
        $document = json_decode((string) file_get_contents(public_path('openapi/svc-agent-v1.json')), true, flags: JSON_THROW_ON_ERROR);
        $operations = [];
        foreach ($document['paths'] as $path) {
            foreach ($path as $operation) {
                if (str_starts_with($operation['operationId'], 'proposals.')) {
                    $operations[$operation['operationId']] = $operation['security'];
                }
            }
        }
        foreach (['list' => ['proposals:read'], 'get' => ['proposals:read'], 'create' => ['proposals:write', 'clients:read'], 'send' => ['proposals:write', 'proposals:read'], 'accept' => ['proposals:accept', 'proposals:read']] as $operation => $scopes) {
            $this->assertSame([['oauth2' => $scopes], ['apiToken' => []]], $operations['proposals.'.$operation]);
        }
    }

    public function test_portal_mcp_principal_can_accept_but_cannot_discover_manager_writes(): void
    {
        [$workspace, $company, , $owner] = $this->fixture();
        $proposal = $this->proposal($workspace, $company, $owner, sent: true);
        $portal = $this->portal($company);
        $this->agent($portal, ['mcp:use', 'proposals:read', 'proposals:write', 'proposals:accept', 'clients:read']);
        $session = $this->initialize();
        $names = $this->toolNames($session);
        $this->assertContains('proposals.accept', $names);
        $this->assertNotContains('proposals.create', $names);
        $this->assertNotContains('proposals.send', $names);
        $listed = $this->callTool($session, 'proposals.list', ['workspace_id' => $workspace->public_id]);
        $this->assertSame($proposal->public_id, $listed['result']['structuredContent']['data'][0]['id']);
        $accepted = $this->callTool($session, 'proposals.accept', ['workspace_id' => $workspace->public_id, 'proposal_id' => $proposal->public_id, 'expected_version' => AgentApiVersion::for($proposal), 'confirm' => true, 'signer_name' => 'Synthetic portal signer', 'idempotency_key' => 'portal-mcp-accept']);
        $this->assertSame('accepted', $accepted['result']['structuredContent']['data']['status']);
        $this->assertDatabaseHas('client_agreements', ['signed_by_user_id' => $portal->id, 'signer_name' => 'Synthetic portal signer']);
    }

    public function test_malformed_legacy_memberships_projects_and_items_do_not_cross_tenants(): void
    {
        [$workspace, $company, , $owner] = $this->fixture();
        [$foreign, , $foreignProject] = $this->fixture();
        $proposal = $this->proposal($workspace, $company, $owner, sent: true);
        $portal = $this->portal($company);
        $this->writingLegacyCrossTenantRows(function () use ($workspace, $foreign, $proposal, $portal): void {
            ClientCompanyMembership::query()->where('workspace_id', $workspace->id)->where('user_id', $portal->id)->update(['workspace_id' => $foreign->id]);
            $proposal->items()->create(['workspace_id' => $foreign->id, 'description' => 'Foreign synthetic secret', 'quantity' => 1, 'unit_amount' => 9999, 'cadence' => 'monthly', 'sort_order' => 2]);
        });
        $this->agent($portal);
        $this->getJson($this->url($workspace))->assertNotFound();
        $this->withHeader('Idempotency-Key', 'malformed-member')->postJson($this->url($workspace, $proposal->public_id).'/accept', ['expected_version' => AgentApiVersion::for($proposal), 'confirm' => true, 'signer_name' => 'Synthetic signer'])->assertNotFound();
        $this->agent($owner);
        $this->getJson($this->url($workspace, $proposal->public_id))->assertOk()->assertJsonCount(1, 'data.items')->assertJsonPath('data.total_amount', 1875);
        $this->withHeader('Idempotency-Key', 'malformed-item-accept')->postJson($this->url($workspace, $proposal->public_id).'/accept', ['expected_version' => AgentApiVersion::for($proposal), 'confirm' => true, 'signer_name' => 'Synthetic signer'])->assertOk();
        $this->assertDatabaseCount('client_agreement_recurring_items', 1);
        $malformed = $this->proposal($workspace, $company, $owner);
        $this->writingLegacyCrossTenantRows(function () use ($malformed, $foreignProject): void {
            $malformed->forceFill(['client_project_id' => $foreignProject->id])->save();
        });
        $this->withHeader('Idempotency-Key', 'malformed-parent-send')->postJson($this->url($workspace, $malformed->public_id).'/send', ['expected_version' => AgentApiVersion::for($malformed), 'confirm' => true])->assertNotFound();
        $this->assertSame('draft', $malformed->fresh()->status);
    }

    public function test_a_project_grant_on_another_company_membership_does_not_authorize_proposals(): void
    {
        [$workspace, $company, $project, $owner] = $this->fixture();
        $other = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Other synthetic company', 'slug' => 'other-'.Str::uuid()]);
        $portal = $this->portal($company, $project);
        $membership = ClientCompanyMembership::query()->where('workspace_id', $workspace->id)->where('client_company_id', $company->id)->where('user_id', $portal->id)->firstOrFail();
        ClientCompanyMembership::query()->create(['client_company_id' => $other->id, 'user_id' => $portal->id, 'role' => 'client', 'access_scope' => 'projects']);
        $otherProject = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $other->id, 'name' => 'Unassigned synthetic project']);
        $proposal = $this->proposal($workspace, $other, $owner, $otherProject, true);
        $this->writingLegacyCrossTenantRows(function () use ($workspace, $membership, $otherProject): void {
            DB::table('client_portal_project_access')->insert(['workspace_id' => $workspace->id, 'client_company_membership_id' => $membership->id, 'client_project_id' => $otherProject->id, 'created_at' => now(), 'updated_at' => now()]);
        });
        $visible = app(PortalAccess::class)->constrainProjectQuery(ClientProject::query()->where('workspace_id', $workspace->id), $portal)->pluck('id')->all();
        $this->assertSame([$project->id], $visible);
        $this->agent($portal);
        $this->getJson($this->url($workspace))->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($this->url($workspace, $proposal->public_id))->assertNotFound();
        $this->withHeader('Idempotency-Key', 'cross-company-grant')->postJson($this->url($workspace, $proposal->public_id).'/accept', ['expected_version' => AgentApiVersion::for($proposal), 'confirm' => true, 'signer_name' => 'Synthetic signer'])->assertNotFound();
        $this->assertDatabaseCount('client_agreements', 0);
    }

    public function test_acceptance_records_proposal_then_company_locks_before_visibility_snapshot(): void
    {
        [$workspace, $company, , $owner] = $this->fixture();
        $proposal = $this->proposal($workspace, $company, $owner, sent: true);
        $this->agent($owner);
        LockOrderRecorder::start();
        try {
            $this->withHeader('Idempotency-Key', 'record-accept-locks')->postJson($this->url($workspace, $proposal->public_id).'/accept', ['expected_version' => AgentApiVersion::for($proposal), 'confirm' => true, 'signer_name' => 'Synthetic signer'])->assertOk();
            $sequence = array_map(fn ($resource): string => $resource->value, LockOrderRecorder::sequences()[0]);
            $this->assertContains('client_proposals', $sequence);
            $this->assertContains('client_companies', $sequence);
            $this->assertLessThan(array_search('client_companies', $sequence, true), array_search('client_proposals', $sequence, true));
        } finally {
            LockOrderRecorder::stop();
        }
    }

    /** @return array{Workspace,ClientCompany,ClientProject,User} */
    private function fixture(): array
    {
        $workspace = Workspace::query()->create(['name' => 'Synthetic proposal workspace', 'slug' => 'synthetic-'.Str::uuid()]);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic proposal client', 'slug' => 'synthetic-'.Str::uuid()]);
        $project = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Synthetic proposal project']);
        $owner = User::factory()->create();
        $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);

        return [$workspace, $company, $project, $owner];
    }

    private function portal(ClientCompany $company, ?ClientProject $project = null): User
    {
        $user = User::factory()->create();
        $membership = ClientCompanyMembership::query()->create(['client_company_id' => $company->id, 'user_id' => $user->id, 'role' => 'member', 'access_scope' => $project === null ? 'company' : 'projects']);
        if ($project !== null) {
            DB::table('client_portal_project_access')->insert(['workspace_id' => $company->workspace_id, 'client_company_membership_id' => $membership->id, 'client_project_id' => $project->id, 'created_at' => now(), 'updated_at' => now()]);
        }

        return $user;
    }

    /** @param list<string> $scopes */
    private function agent(User $actor, array $scopes = ['proposals:read', 'proposals:write', 'proposals:accept', 'clients:read']): void
    {
        $this->actingAsMcp($actor, $scopes);
    }

    private function url(Workspace $workspace, ?string $id = null): string
    {
        return '/api/v1/workspaces/'.$workspace->public_id.'/proposals'.($id === null ? '' : '/'.$id);
    }

    /** @return array<string,mixed> */
    private function facts(ClientCompany $company): array
    {
        return ['company_id' => $company->public_id, 'expected_version' => AgentApiVersion::for($company), 'title' => 'Synthetic proposal', 'summary' => 'Synthetic scope', 'terms' => 'Synthetic terms', 'currency' => 'USD', 'items' => [['description' => 'Synthetic work', 'quantity' => 1.5, 'unit_amount' => 1250, 'cadence' => 'monthly']]];
    }

    private function proposal(Workspace $workspace, ClientCompany $company, User $owner, ?ClientProject $project = null, bool $sent = false): ClientProposal
    {
        $workflow = app(ProposalWorkflow::class);
        $proposal = $workflow->create($workspace, $company, $project, $owner, $this->facts($company));

        return $sent ? $workflow->send($proposal) : $proposal;
    }

    /** @param array<string,mixed> $body */
    private function schema(string $operation, array $body): void
    {
        $this->assertSame([], (new SchemaValidator)->validateAgainstJsonSchema($body, AgentApiResponseSchemaCatalog::forOperation($operation)));
    }
}
