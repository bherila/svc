<?php

namespace Tests\Feature\AgentApi;

use App\Models\ClientCompany;
use App\Models\User;
use App\Models\Workspace;
use App\Support\AgentApi\AgentApiVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CallsMcp;
use Tests\TestCase;

final class ClientCompanyProfileParityTest extends TestCase
{
    use CallsMcp;
    use RefreshDatabase;

    public function test_profile_fields_share_rest_and_mcp_validation_and_partial_updates(): void
    {
        config(['agent_api.writes_enabled' => true, 'agent_api.client_writes_enabled' => true]);
        $owner = User::factory()->create();
        $workspace = Workspace::query()->create(['name' => 'Synthetic profile workspace', 'slug' => 'synthetic-profile', 'timezone' => 'UTC']);
        $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic company', 'slug' => 'synthetic-company', 'phone' => '555-0100']);
        $this->actingAsMcp($owner, ['mcp:use', 'identity:read', 'clients:read', 'clients:write']);
        $path = '/api/v1/workspaces/'.$workspace->public_id.'/clients/'.$company->public_id;
        $body = ['expected_version' => AgentApiVersion::for($company), 'billing_address' => '1 Example Lane', 'website_url' => 'https://client.example', 'notes' => 'Synthetic private profile'];
        $updated = $this->withHeader('Idempotency-Key', 'synthetic-profile-update')->patchJson($path, $body)->assertOk();
        $updated->assertJsonPath('data.billing_address', '1 Example Lane')->assertJsonPath('data.phone', '555-0100')->assertJsonPath('data.notes', 'Synthetic private profile');
        $session = $this->initialize();
        $replay = $this->callTool($session, 'clients.update', ['workspace_id' => $workspace->public_id, 'client_id' => $company->public_id, 'idempotency_key' => 'synthetic-profile-update', ...$body]);
        $this->assertFalse($replay['result']['isError'] ?? true, json_encode($replay));
        $this->assertSame($updated->json('data'), $replay['result']['structuredContent']['data']);
        $this->withHeader('Idempotency-Key', 'synthetic-profile-clear')->patchJson($path, ['expected_version' => $updated->json('data.version'), 'phone' => null])->assertOk()->assertJsonPath('data.phone', null)->assertJsonPath('data.billing_address', '1 Example Lane');
        $this->withHeader('Idempotency-Key', 'synthetic-profile-invalid')->patchJson($path, ['expected_version' => AgentApiVersion::for($company->fresh()), 'website_url' => 'javascript:alert(1)', 'currency' => 'usd'])->assertUnprocessable();
        $this->assertSame('https://client.example', $company->fresh()->website_url);
    }

    public function test_mcp_preserves_valid_maximum_unicode_profile_fields(): void
    {
        config(['agent_api.writes_enabled' => true, 'agent_api.client_writes_enabled' => true]);
        $owner = User::factory()->create();
        $workspace = Workspace::query()->create(['name' => 'Synthetic Unicode profile', 'slug' => 'synthetic-unicode-profile']);
        $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
        $this->actingAsMcp($owner, ['mcp:use', 'identity:read', 'clients:read', 'clients:write']);
        $notes = str_repeat('🚀', 20000);
        $address = str_repeat('🧭', 10000);
        $reply = $this->callTool($this->initialize(), 'clients.create', ['workspace_id' => $workspace->public_id,
            'idempotency_key' => 'synthetic-max-unicode-profile', 'name' => 'Synthetic Unicode company',
            'notes' => $notes, 'billing_address' => $address]);
        $this->assertFalse($reply['result']['isError'] ?? true, json_encode($reply));
        $company = $reply['result']['structuredContent']['data'];
        $this->assertSame($notes, $company['notes']);
        $this->assertSame($address, $company['billing_address']);
        $this->assertSame(1, ClientCompany::query()->where('workspace_id', $workspace->id)->count());
    }

    public function test_creation_keeps_profile_fields_and_manager_only_reads(): void
    {
        config(['agent_api.writes_enabled' => true, 'agent_api.client_writes_enabled' => true]);
        $owner = User::factory()->create();
        $workspace = Workspace::query()->create(['name' => 'Synthetic create workspace', 'slug' => 'synthetic-create', 'timezone' => 'UTC']);
        $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
        $this->actingAsMcp($owner, ['mcp:use', 'identity:read', 'clients:read', 'clients:write']);
        $path = '/api/v1/workspaces/'.$workspace->public_id.'/clients';
        $created = $this->withHeader('Idempotency-Key', 'synthetic-profile-create')->postJson($path, ['name' => 'Synthetic profile client', 'phone' => '555-0101', 'tax_id' => 'SYNTHETIC-ONLY', 'currency' => 'USD'])->assertCreated();
        $created->assertJsonPath('data.phone', '555-0101')->assertJsonPath('data.currency', 'USD')->assertJsonPath('data.tax_id', 'SYNTHETIC-ONLY');
        $mcp = $this->callTool($this->initialize(), 'clients.create', [
            'workspace_id' => $workspace->public_id, 'idempotency_key' => 'synthetic-mcp-profile-create',
            'name' => 'Synthetic MCP profile client', 'billing_address' => '2 Example Lane',
            'phone' => '555-0102', 'website_url' => 'https://mcp-client.example',
            'tax_id' => 'SYNTHETIC-MCP', 'currency' => 'EUR', 'notes' => 'Synthetic MCP notes',
        ]);
        $this->assertFalse($mcp['result']['isError'] ?? true, json_encode($mcp));
        $profile = $mcp['result']['structuredContent']['data'];
        $this->assertSame('2 Example Lane', $profile['billing_address']);
        $this->assertSame('555-0102', $profile['phone']);
        $this->assertSame('https://mcp-client.example', $profile['website_url']);
        $this->assertSame('SYNTHETIC-MCP', $profile['tax_id']);
        $this->assertSame('EUR', $profile['currency']);
        $this->assertSame('Synthetic MCP notes', $profile['notes']);
        $member = User::factory()->create();
        $workspace->memberships()->create(['user_id' => $member->id, 'role' => 'member']);
        $this->actingAsMcp($member, ['clients:read']);
        $this->getJson($path.'/'.$created->json('data.id'))->assertNotFound();
    }
}
