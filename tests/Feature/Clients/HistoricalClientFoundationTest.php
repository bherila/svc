<?php

namespace Tests\Feature\Clients;

use App\Models\ClientCompany;
use App\Models\ClientCompanyMembership;
use App\Models\ClientContact;
use App\Models\User;
use App\Models\Workspace;
use App\Support\AgentApi\AgentApiVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class HistoricalClientFoundationTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private Workspace $workspace;

    private ClientCompany $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = User::factory()->create();
        $this->workspace = Workspace::query()->create(['name' => 'Synthetic History', 'slug' => 'synthetic-history']);
        $this->workspace->memberships()->create(['user_id' => $this->manager->id, 'role' => 'owner']);
        $this->company = ClientCompany::query()->create(['workspace_id' => $this->workspace->id, 'name' => 'Synthetic Client', 'slug' => 'synthetic-client']);
    }

    public function test_company_profile_is_editable_without_changing_billing_or_issuing_access(): void
    {
        $this->profilePatch([
            'billing_address' => '1 Example Lane', 'phone' => '+1 555 0100',
            'website_url' => 'https://client.example.test', 'tax_id' => 'SYNTHETIC-TAX',
            'currency' => 'USD', 'notes' => 'Operator-only profile notes',
        ])->assertOk();

        $fresh = $this->company->fresh();
        $this->assertSame('1 Example Lane', $fresh->billing_address);
        $this->assertSame('SYNTHETIC-TAX', $fresh->tax_id);
        $this->assertSame('USD', $fresh->currency);
        $this->assertFalse($fresh->automatic_invoice_email_enabled);
        $this->assertTrue($fresh->is_active);
        $this->assertArrayNotHasKey('notes', $fresh->toArray());
        $this->assertArrayNotHasKey('tax_id', $fresh->toArray());
        $this->actingAs($this->manager)->get(route('clients.settings', [$this->workspace, $this->company]))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('company_profile.billing_address', '1 Example Lane')
            ->where('company_profile.notes', 'Operator-only profile notes')
            ->where('profile_action', route('agent-api.v1.clients.update', [$this->workspace, $this->company], absolute: false)));
    }

    public function test_profile_partial_edit_preserves_fields_not_supplied_and_can_clear_a_field(): void
    {
        $this->company->update(['billing_address' => 'Original synthetic address', 'phone' => '555-0101']);
        $this->profilePatch(['tax_id' => 'NEW-SYNTHETIC'])->assertOk();
        $this->assertSame('Original synthetic address', $this->company->fresh()->billing_address);
        $this->assertSame('555-0101', $this->company->fresh()->phone);
        $this->profilePatch(['phone' => null])->assertOk();
        $this->assertNull($this->company->fresh()->phone);
        $this->profilePatch(['website_url' => 'javascript:alert(1)', 'currency' => 'not-a-currency'])
            ->assertUnprocessable()->assertJsonValidationErrors(['website_url', 'currency']);
    }

    public function test_creating_and_archiving_contacts_creates_no_login_access_invitation_or_mail(): void
    {
        Mail::fake();
        Notification::fake();
        $initialUsers = User::query()->count();
        $this->browser($this->manager)->withHeader('Idempotency-Key', 'synthetic-forged-contact')->postJson($this->contactsUrl(), $this->details() + [
            'workspace_id' => 999999, 'client_company_id' => 999999, 'user_id' => $this->manager->id, 'role' => 'owner',
        ])->assertUnprocessable();
        $this->withHeader('Idempotency-Key', 'synthetic-create-contact')->postJson($this->contactsUrl(), $this->details())->assertCreated();
        $contact = ClientContact::query()->where('workspace_id', $this->workspace->id)->firstOrFail();
        $this->assertSame($this->company->id, $contact->client_company_id);
        $this->assertSame('casey@example.test', $contact->email);
        $this->assertSame($initialUsers, User::query()->count());
        $this->assertSame(0, ClientCompanyMembership::query()->count());
        $this->assertDatabaseCount('client_portal_invitations', 0);
        $this->assertDatabaseCount('jobs', 0);
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        Notification::assertNothingSent();

        $version = AgentApiVersion::for($contact);
        $url = $this->contactsUrl($contact);
        $this->withHeader('Idempotency-Key', 'synthetic-archive-contact')->postJson($url.'/archive', ['expected_version' => $version, 'confirm' => true])->assertOk();
        $this->assertTrue($contact->fresh()->is_archived);
        $this->assertSame(2, $contact->fresh()->lock_version);
        $this->withHeader('Idempotency-Key', 'synthetic-stale-contact')->patchJson($url, ['notes' => 'Stale write', 'expected_version' => $version])->assertConflict();
        $this->assertSame('Synthetic historical contact', $contact->fresh()->notes);
    }

    public function test_settings_expose_contact_details_only_to_workspace_managers(): void
    {
        $contact = ClientContact::query()->create(['workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id] + $this->details());
        $this->actingAs($this->manager)->get(route('clients.settings', [$this->workspace, $this->company]))
            ->assertOk()->assertInertia(fn (Assert $page) => $page->has('contacts', 1)
            ->where('contacts.0.email', 'casey@example.test')
            ->where('contacts.0.update_action', route('agent-api.v1.clients.contacts.update', [$this->workspace, $this->company, $contact], absolute: false))
            ->where('contacts.0.version', AgentApiVersion::for($contact)));
        $member = User::factory()->create();
        $this->workspace->memberships()->create(['user_id' => $member->id, 'role' => 'member']);
        $this->actingAs($member)->get(route('clients.settings', [$this->workspace, $this->company]))->assertForbidden();
        $this->browser($member)->withHeader('Idempotency-Key', 'synthetic-refused-contact')->postJson($this->contactsUrl(), $this->details())->assertForbidden();
        $this->withHeader('Idempotency-Key', 'synthetic-refused-profile')->patchJson($this->companyUrl(), ['notes' => 'Forbidden', 'expected_version' => AgentApiVersion::for($this->company)])->assertForbidden();
    }

    public function test_contact_cannot_be_edited_through_another_company_or_workspace(): void
    {
        $sibling = ClientCompany::query()->create(['workspace_id' => $this->workspace->id, 'name' => 'Sibling', 'slug' => 'sibling']);
        $contact = ClientContact::query()->create(['workspace_id' => $this->workspace->id, 'client_company_id' => $sibling->id] + $this->details());
        $body = ['notes' => 'Foreign write', 'expected_version' => AgentApiVersion::for($contact)];
        $this->browser($this->manager)->withHeader('Idempotency-Key', 'synthetic-sibling-contact')->patchJson($this->contactsUrl($contact), $body)->assertNotFound();
        $foreign = Workspace::query()->create(['name' => 'Other Workspace', 'slug' => 'other-workspace']);
        $foreign->memberships()->create(['user_id' => $this->manager->id, 'role' => 'owner']);
        $this->withHeader('Idempotency-Key', 'synthetic-foreign-contact')->patchJson(route('agent-api.v1.clients.contacts.update', [$foreign, $this->company, $contact]), $body)->assertNotFound();
        $this->withHeader('Idempotency-Key', 'synthetic-foreign-profile')->patchJson(route('agent-api.v1.clients.update', [$foreign, $this->company]), ['notes' => 'Foreign write', 'expected_version' => AgentApiVersion::for($this->company)])->assertNotFound();
        $this->assertSame('Synthetic historical contact', $contact->fresh()->notes);
        $this->assertNull($this->company->fresh()->notes);
    }

    public function test_browser_settings_publish_versions_and_use_the_api_with_agent_cutovers_off(): void
    {
        config(['agent_api.writes_enabled' => false, 'agent_api.client_writes_enabled' => false]);
        $version = AgentApiVersion::for($this->company);
        $this->browser($this->manager)->get(route('clients.settings', [$this->workspace, $this->company]))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('company.version', $version)
            ->where('company.update_url', route('agent-api.v1.clients.update', [$this->workspace, $this->company], absolute: false))
            ->where('workspaceNavigation.create_client_url', route('agent-api.v1.clients.store', $this->workspace, absolute: false)));
        $body = ['expected_version' => $version, 'notes' => 'Synthetic browser profile edit'];
        $this->withHeader('Idempotency-Key', 'synthetic-browser-profile')->patchJson($this->companyUrl(), $body)->assertOk();
        $this->patchJson($this->companyUrl(), $body)->assertOk();
        $this->withHeader('Idempotency-Key', 'synthetic-stale-browser-profile')->patchJson($this->companyUrl(), $body)->assertConflict();
        $this->assertSame('Synthetic browser profile edit', $this->company->fresh()->notes);
        $this->assertDatabaseCount('agent_mutation_receipts', 1);
        foreach (['clients.store', 'clients.update', 'clients.profile.update', 'contacts.store', 'contacts.update'] as $retired) {
            $this->assertFalse(Route::has($retired), $retired);
        }
    }

    public function test_dual_role_manager_keeps_internal_contact_access_through_the_browser_session(): void
    {
        config(['agent_api.writes_enabled' => false, 'agent_api.client_writes_enabled' => false]);
        $this->company->portalUsers()->attach($this->manager->id, ['workspace_id' => $this->workspace->id, 'role' => 'client']);
        $created = $this->browser($this->manager)->withHeader('Idempotency-Key', 'synthetic-dual-browser-create')
            ->postJson($this->contactsUrl(), $this->details())->assertCreated();
        $contact = ClientContact::query()->where('workspace_id', $this->workspace->id)->sole();
        $url = $this->contactsUrl($contact);
        $this->getJson($url)->assertOk()->assertJsonPath('data.id', $contact->public_id);
        $updated = $this->withHeader('Idempotency-Key', 'synthetic-dual-browser-update')->patchJson($url,
            ['expected_version' => $created->json('data.version'), 'notes' => 'Synthetic internal manager edit'])->assertOk();
        $archived = $this->withHeader('Idempotency-Key', 'synthetic-dual-browser-archive')->postJson($url.'/archive',
            ['expected_version' => $updated->json('data.version'), 'confirm' => true])->assertOk()->assertJsonPath('data.is_archived', true);
        $this->withHeader('Idempotency-Key', 'synthetic-dual-browser-restore')->postJson($url.'/restore',
            ['expected_version' => $archived->json('data.version')])->assertOk()->assertJsonPath('data.is_archived', false);
        $this->assertSame('Synthetic internal manager edit', $contact->fresh()->notes);
        $this->assertDatabaseCount('client_contacts', 1);
        $this->assertDatabaseCount('users', 1);
    }

    private function browser(User $user): static
    {
        return $this->actingAs($user, 'web')->withCredentials()->withUnencryptedCookie((string) config('session.cookie'), str()->random(40));
    }

    private function companyUrl(): string
    {
        return route('agent-api.v1.clients.update', [$this->workspace, $this->company]);
    }

    private function contactsUrl(?ClientContact $contact = null): string
    {
        return $contact === null
            ? route('agent-api.v1.clients.contacts.store', [$this->workspace, $this->company])
            : route('agent-api.v1.clients.contacts.update', [$this->workspace, $this->company, $contact]);
    }

    private function profilePatch(array $fields): TestResponse
    {
        return $this->browser($this->manager)->withHeader('Idempotency-Key', 'synthetic-profile-'.str()->uuid())->patchJson($this->companyUrl(), $fields + ['expected_version' => AgentApiVersion::for($this->company->fresh())]);
    }

    /** @return array<string, mixed> */
    private function details(): array
    {
        return ['first_name' => 'Casey', 'last_name' => 'Example', 'title' => 'Historical contributor',
            'email' => 'casey@example.test', 'phone' => '555-0102', 'notes' => 'Synthetic historical contact', 'is_archived' => false];
    }
}
