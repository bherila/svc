<?php

namespace Tests\Feature\AgentApi;

use App\Models\ClientCompany;
use App\Models\ClientContact;
use App\Models\User;
use App\Models\Workspace;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\Concurrency\LockOrderRecorder;
use App\Support\Concurrency\LockResource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\CallsMcp;
use Tests\TestCase;

final class BusinessContactParityTest extends TestCase
{
    use CallsMcp;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['agent_api.writes_enabled' => true, 'agent_api.client_writes_enabled' => true]);
    }

    public function test_mcp_preserves_maximum_unicode_contact_notes_on_create_update_and_read(): void
    {
        [$workspace, $owner, $company] = $this->fixture();
        $this->actingAsMcp($owner, ['mcp:use', 'identity:read', 'clients:read', 'clients:write']);
        $session = $this->initialize();
        $identity = ['workspace_id' => $workspace->public_id, 'client_id' => $company->public_id];
        $notes = str_repeat('🚀', 20000);
        $created = $this->callTool($session, 'clients.contacts.create', [...$identity,
            'idempotency_key' => 'synthetic-unicode-contact', 'first_name' => 'Synthetic Unicode', 'notes' => $notes]);
        $this->assertFalse($created['result']['isError'] ?? true, json_encode($created));
        $contact = $created['result']['structuredContent']['data'];
        $this->assertSame($notes, $contact['notes']);
        $replacement = str_repeat('🧭', 20000);
        $updated = $this->callTool($session, 'clients.contacts.update', [...$identity,
            'contact_id' => $contact['id'], 'idempotency_key' => 'synthetic-unicode-contact-update',
            'expected_version' => $contact['version'], 'notes' => $replacement]);
        $this->assertFalse($updated['result']['isError'] ?? true, json_encode($updated));
        $this->assertSame($replacement, $updated['result']['structuredContent']['data']['notes']);
        $listed = $this->callTool($session, 'clients.contacts.list', [...$identity, 'limit' => 1]);
        $this->assertFalse($listed['result']['isError'] ?? true, json_encode($listed));
        $this->assertSame($replacement, $listed['result']['structuredContent']['data'][0]['notes']);
    }

    public function test_default_contact_pages_fit_mcp_and_traverse_all_large_notes_without_skips(): void
    {
        [$workspace, $owner, $company, $base] = $this->fixture();
        $this->actingAsMcp($owner, ['mcp:use', 'identity:read', 'clients:read']);
        $notes = str_repeat('🚀', 20000);
        $expected = [];
        for ($i = 0; $i < 9; $i++) {
            $expected[] = ClientContact::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id,
                'first_name' => 'Synthetic large notes '.$i, 'notes' => $notes])->public_id;
        }
        $session = $this->initialize();
        $seen = [];
        $cursor = null;
        for ($page = 0; $page < 10; $page++) {
            $arguments = ['workspace_id' => $workspace->public_id, 'client_id' => $company->public_id];
            if ($cursor !== null) {
                $arguments['cursor'] = $cursor;
            }
            $reply = $this->callTool($session, 'clients.contacts.list', $arguments);
            $this->assertFalse($reply['result']['isError'] ?? true, json_encode($reply));
            $this->assertLessThanOrEqual(config('agent_api.mcp_max_result_bytes'), strlen(json_encode($reply['result'], JSON_THROW_ON_ERROR)));
            $result = $reply['result']['structuredContent'];
            $rest = $this->getJson($base.($cursor === null ? '' : '?cursor='.urlencode($cursor)))->assertOk();
            $this->assertSame($result['data'], $rest->json('data'));
            $this->assertSame($result['meta']['next_cursor'] === null, $rest->json('meta.next_cursor') === null);
            $this->assertNotEmpty($result['data']);
            foreach ($result['data'] as $contact) {
                $this->assertSame($notes, $contact['notes']);
                $seen[] = $contact['id'];
            }
            $cursor = $result['meta']['next_cursor'];
            if ($cursor === null) {
                break;
            }
        }
        $this->assertNull($cursor, 'A bounded traversal must finish.');
        $this->assertSame($expected, $seen);
    }

    public function test_rest_and_mcp_share_contact_lifecycle_receipts_and_preserve_unedited_fields(): void
    {
        Mail::fake();
        Notification::fake();
        [$workspace, $owner, $company, $base] = $this->fixture();
        $this->actingAsMcp($owner, ['mcp:use', 'identity:read', 'clients:read', 'clients:write']);
        $body = ['first_name' => 'Synthetic', 'last_name' => 'Contact', 'phone' => '555-0110', 'email' => 'contact@example.test', 'notes' => 'Private business note'];
        $created = $this->withHeader('Idempotency-Key', 'contact-create')->postJson($base, $body)->assertCreated();
        $id = $created->json('data.id');
        $session = $this->initialize();
        $replay = $this->callTool($session, 'clients.contacts.create', ['workspace_id' => $workspace->public_id, 'client_id' => $company->public_id, 'idempotency_key' => 'contact-create', ...$body]);
        $this->assertFalse($replay['result']['isError'] ?? true, json_encode($replay));
        $this->assertSame($created->json('data'), $replay['result']['structuredContent']['data']);
        $update = ['expected_version' => $created->json('data.version'), 'phone' => null];
        $updated = $this->withHeader('Idempotency-Key', 'contact-update')->patchJson($base.'/'.$id, $update)->assertOk()
            ->assertJsonPath('data.phone', null)->assertJsonPath('data.first_name', 'Synthetic')->assertJsonPath('data.notes', 'Private business note');
        $mcpUpdate = $this->callTool($session, 'clients.contacts.update', ['workspace_id' => $workspace->public_id, 'client_id' => $company->public_id, 'contact_id' => $id, 'idempotency_key' => 'contact-update', ...$update]);
        $this->assertFalse($mcpUpdate['result']['isError'] ?? true, json_encode($mcpUpdate));
        $this->assertSame($updated->json('data'), $mcpUpdate['result']['structuredContent']['data']);
        $this->withHeader('Idempotency-Key', 'contact-empty-name')->patchJson($base.'/'.$id, ['expected_version' => $updated->json('data.version'), 'first_name' => null, 'last_name' => null])->assertUnprocessable();
        $archive = ['expected_version' => $updated->json('data.version'), 'confirm' => true];
        $archived = $this->withHeader('Idempotency-Key', 'contact-archive')->postJson($base.'/'.$id.'/archive', $archive)->assertOk()->assertJsonPath('data.is_archived', true);
        $archiveReplay = $this->callTool($session, 'clients.contacts.archive', ['workspace_id' => $workspace->public_id, 'client_id' => $company->public_id, 'contact_id' => $id, 'idempotency_key' => 'contact-archive', ...$archive]);
        $this->assertFalse($archiveReplay['result']['isError'] ?? true, json_encode($archiveReplay));
        $this->assertSame($archived->json('data'), $archiveReplay['result']['structuredContent']['data']);
        $restored = $this->callTool($session, 'clients.contacts.restore', ['workspace_id' => $workspace->public_id, 'client_id' => $company->public_id, 'contact_id' => $id, 'idempotency_key' => 'contact-restore', 'expected_version' => $archived->json('data.version')]);
        $this->assertFalse($restored['result']['isError'] ?? true, json_encode($restored));
        $this->assertFalse($restored['result']['structuredContent']['data']['is_archived']);
        $detail = $this->callTool($session, 'clients.contacts.get', ['workspace_id' => $workspace->public_id, 'client_id' => $company->public_id, 'contact_id' => $id]);
        $this->assertSame($restored['result']['structuredContent']['data'], $detail['result']['structuredContent']['data']);
        $listed = $this->callTool($session, 'clients.contacts.list', ['workspace_id' => $workspace->public_id, 'client_id' => $company->public_id]);
        $this->assertSame($id, $listed['result']['structuredContent']['data'][0]['id']);
        $this->assertDatabaseCount('client_contacts', 1);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('workspace_memberships', 1);
        $this->assertDatabaseCount('client_company_memberships', 0);
        $this->assertDatabaseCount('client_portal_invitations', 0);
        Mail::assertNothingSent();
        Notification::assertNothingSent();
    }

    public function test_dual_managers_use_portal_agent_authority_while_native_cli_remains_internal(): void
    {
        [$workspace, $owner, $company, $base] = $this->fixture();
        $this->actingAsMcp($owner, ['mcp:use', 'identity:read', 'clients:read', 'clients:write']);
        $body = ['first_name' => 'Synthetic private contact', 'phone' => '555-0130'];
        $created = $this->withHeader('Idempotency-Key', 'synthetic-before-portal')->postJson($base, $body)->assertCreated();
        $id = $created->json('data.id');
        $company->portalUsers()->attach($owner->id, ['role' => 'client', 'access_scope' => 'company']);
        $this->getJson($base)->assertNotFound();
        $this->getJson($base.'/'.$id)->assertNotFound();
        $this->withHeader('Idempotency-Key', 'synthetic-before-portal')->postJson($base, $body)->assertForbidden();
        $this->withHeader('Idempotency-Key', 'synthetic-dual-edit')->patchJson($base.'/'.$id, ['expected_version' => $created->json('data.version'), 'phone' => '555-0131'])->assertForbidden();
        $reply = $this->callTool($this->initialize(), 'clients.contacts.get', ['workspace_id' => $workspace->public_id, 'client_id' => $company->public_id, 'contact_id' => $id]);
        $this->assertTrue($reply['result']['isError']);
        $this->assertSame('555-0130', ClientContact::query()->where('workspace_id', $workspace->id)->where('public_id', $id)->sole()->phone);
        $this->assertSame(0, Artisan::call('svc:clients:contacts', [
            'operation' => 'get', 'workspace' => $workspace->public_id, 'company' => $company->public_id,
            '--actor' => $owner->public_id, '--contact' => $id, '--format' => 'json',
        ]));
        $this->assertSame('555-0130', json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['data']['phone']);
        $this->assertDatabaseCount('client_contacts', 1);
        $this->assertDatabaseCount('client_company_memberships', 1);
        $input = tempnam(sys_get_temp_dir(), 'svc-contact-dual-cli-');
        try {
            file_put_contents($input, json_encode(['first_name' => 'Synthetic native CLI contact'], JSON_THROW_ON_ERROR));
            $command = ['operation' => 'create', 'workspace' => $workspace->public_id, 'company' => $company->public_id,
                '--actor' => $owner->public_id, '--input' => $input, '--key' => 'synthetic-dual-native', '--format' => 'json'];
            $this->assertSame(0, Artisan::call('svc:clients:contacts', $command));
            $this->assertTrue(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['dry_run']);
            $this->assertDatabaseCount('client_contacts', 1);
            $this->assertSame(0, Artisan::call('svc:clients:contacts', $command + ['--apply' => true]));
            $this->assertDatabaseCount('client_contacts', 2);
            $this->assertDatabaseCount('client_company_memberships', 1);
        } finally {
            unlink($input);
        }
    }

    public function test_versions_scope_validation_and_revoked_replays_are_enforced(): void
    {
        [$workspace, $owner, $company, $base] = $this->fixture();
        $this->actingAsMcp($owner, ['clients:read', 'clients:write']);
        $created = $this->withHeader('Idempotency-Key', 'scoped-contact')->postJson($base, ['first_name' => 'Synthetic'])->assertCreated();
        $id = $created->json('data.id');
        $version = $created->json('data.version');
        $this->withHeader('Idempotency-Key', 'bad-contact-field')->patchJson($base.'/'.$id, ['expected_version' => $version, 'user_id' => $owner->public_id])->assertUnprocessable();
        $this->withHeader('Idempotency-Key', 'unconfirmed-contact-archive')->postJson($base.'/'.$id.'/archive', ['expected_version' => $version])->assertUnprocessable();
        $other = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Other synthetic client', 'slug' => 'other-synthetic']);
        $this->getJson(str_replace($company->public_id, $other->public_id, $base).'/'.$id)->assertNotFound();
        $foreignWorkspace = Workspace::query()->create(['name' => 'Other synthetic workspace', 'slug' => 'foreign-contacts', 'timezone' => 'UTC']);
        $foreignWorkspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
        $foreignCompany = ClientCompany::query()->create(['workspace_id' => $foreignWorkspace->id, 'name' => 'Foreign synthetic company', 'slug' => 'foreign-company']);
        $foreignContact = ClientContact::query()->create(['workspace_id' => $foreignWorkspace->id, 'client_company_id' => $foreignCompany->id, 'first_name' => 'Other tenant contact']);
        $this->getJson($base.'/'.$foreignContact->public_id)->assertNotFound();
        $this->withHeader('Idempotency-Key', 'wrong-tenant-contact')->patchJson($base.'/'.$foreignContact->public_id, ['expected_version' => AgentApiVersion::for($foreignContact), 'phone' => '555-0118'])->assertNotFound();
        $this->getJson(str_replace($company->public_id, $foreignCompany->public_id, $base).'/'.$foreignContact->public_id)->assertNotFound();
        $this->withHeader('Idempotency-Key', 'update-contact')->patchJson($base.'/'.$id, ['expected_version' => $version, 'title' => 'Synthetic title'])->assertOk();
        $this->withHeader('Idempotency-Key', 'stale-contact')->patchJson($base.'/'.$id, ['expected_version' => $version, 'title' => 'Other title'])->assertConflict();
        $this->withHeader('Idempotency-Key', 'scoped-contact')->postJson($base, ['first_name' => 'Different synthetic'])->assertConflict();
        $workspace->memberships()->where('user_id', $owner->id)->update(['role' => 'member']);
        $this->getJson($base.'/'.$id)->assertNotFound();
        $this->withHeader('Idempotency-Key', 'scoped-contact')->postJson($base, ['first_name' => 'Synthetic'])->assertForbidden();
        $this->assertSame(1, ClientContact::query()->where('workspace_id', $workspace->id)->count());
        $this->assertNull($foreignContact->fresh()->phone);
    }

    public function test_contact_pages_are_bounded_and_cursors_cannot_cross_clients_or_filters(): void
    {
        [$workspace, $owner, $company, $base] = $this->fixture();
        $this->actingAsMcp($owner, ['clients:read']);
        foreach ([false, true, false] as $index => $archived) {
            ClientContact::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'first_name' => 'Synthetic '.$index, 'is_archived' => $archived]);
        }
        $first = $this->getJson($base.'?limit=1')->assertOk()->assertJsonCount(1, 'data');
        $cursor = $first->json('meta.next_cursor');
        $this->assertIsString($cursor);
        $this->getJson($base.'?limit=1&cursor='.urlencode($cursor))->assertOk()->assertJsonCount(1, 'data');
        $this->getJson($base.'?status=active')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson($base.'?status=archived')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson($base.'?status=archived&cursor='.urlencode($cursor))->assertUnprocessable();
        $other = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Other synthetic', 'slug' => 'other-synthetic']);
        $this->getJson(str_replace($company->public_id, $other->public_id, $base).'?cursor='.urlencode($cursor))->assertUnprocessable();
        $this->getJson($base.'?limit=101')->assertUnprocessable();
    }

    public function test_cli_preview_rolls_back_receipts_and_apply_uses_the_same_versioned_operation(): void
    {
        [$workspace, $owner, $company] = $this->fixture();
        config(['agent_api.writes_enabled' => false, 'agent_api.client_writes_enabled' => false]);
        $input = tempnam(sys_get_temp_dir(), 'svc-synthetic-contact-');
        file_put_contents($input, json_encode(['first_name' => 'Synthetic CLI', 'email' => 'cli@example.test']));
        try {
            $arguments = ['operation' => 'create', 'workspace' => $workspace->public_id, 'company' => $company->public_id, '--actor' => $owner->public_id, '--key' => 'synthetic-cli-create', '--input' => $input, '--format' => 'json'];
            $this->artisan('svc:clients:contacts', $arguments)->assertSuccessful();
            $this->assertDatabaseCount('client_contacts', 0);
            $this->assertDatabaseCount('agent_mutation_receipts', 0);
            LockOrderRecorder::start();
            try {
                $this->artisan('svc:clients:contacts', [...$arguments, '--apply' => true])->assertSuccessful();
                $sequence = LockOrderRecorder::sequences()[0];
                $this->assertContains(LockResource::Workspace, $sequence);
                $this->assertContains(LockResource::WorkspaceMembership, $sequence);
            } finally {
                LockOrderRecorder::stop();
            }
            $this->artisan('svc:clients:contacts', [...$arguments, '--apply' => true])->assertSuccessful();
            $this->assertDatabaseCount('client_contacts', 1);
            $contact = ClientContact::query()->sole();
            file_put_contents($input, json_encode(['expected_version' => AgentApiVersion::for($contact), 'phone' => '555-0109']));
            $this->artisan('svc:clients:contacts', [...$arguments, 'operation' => 'update', '--contact' => $contact->public_id, '--key' => 'synthetic-cli-update', '--apply' => true])->assertSuccessful();
            $this->assertSame('555-0109', $contact->fresh()->phone);
            $this->assertSame('Synthetic CLI', $contact->fresh()->first_name);
        } finally {
            unlink($input);
        }
    }

    /** @return array{Workspace, User, ClientCompany, string} */
    private function fixture(): array
    {
        $owner = User::factory()->create();
        $workspace = Workspace::query()->create(['name' => 'Synthetic business contacts', 'slug' => 'synthetic-contacts', 'timezone' => 'UTC']);
        $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic client', 'slug' => 'synthetic-client']);

        return [$workspace, $owner, $company, '/api/v1/workspaces/'.$workspace->public_id.'/clients/'.$company->public_id.'/contacts'];
    }
}
