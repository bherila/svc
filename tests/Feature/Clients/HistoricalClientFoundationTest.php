<?php

namespace Tests\Feature\Clients;

use App\Models\ClientCompany;
use App\Models\ClientCompanyMembership;
use App\Models\ClientContact;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
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
        $this->actingAs($this->manager)->patch(route('clients.profile.update', [$this->workspace, $this->company]), [
            'billing_address' => '1 Example Lane', 'phone' => '+1 555 0100',
            'website_url' => 'https://client.example.test', 'tax_id' => 'SYNTHETIC-TAX',
            'currency' => 'USD', 'notes' => 'Operator-only profile notes',
            'automatic_invoice_email_enabled' => true, 'is_active' => false,
        ])->assertRedirect();

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
            ->where('profile_action', route('clients.profile.update', [$this->workspace, $this->company])));
    }

    public function test_profile_partial_edit_preserves_fields_not_supplied_and_can_clear_a_field(): void
    {
        $this->company->update(['billing_address' => 'Original synthetic address', 'phone' => '555-0101']);
        $url = route('clients.profile.update', [$this->workspace, $this->company]);
        $this->actingAs($this->manager)->patch($url, ['tax_id' => 'NEW-SYNTHETIC'])->assertRedirect();
        $this->assertSame('Original synthetic address', $this->company->fresh()->billing_address);
        $this->assertSame('555-0101', $this->company->fresh()->phone);
        $this->patch($url, ['phone' => null])->assertRedirect();
        $this->assertNull($this->company->fresh()->phone);
        $this->patch($url, ['website_url' => 'javascript:alert(1)', 'currency' => 'not-a-currency'])
            ->assertSessionHasErrors(['website_url', 'currency']);
    }

    public function test_creating_and_archiving_contacts_creates_no_login_access_invitation_or_mail(): void
    {
        Mail::fake();
        Notification::fake();
        $initialUsers = User::query()->count();
        $this->actingAs($this->manager)->post(route('contacts.store', [$this->workspace, $this->company]), $this->details() + [
            'workspace_id' => 999999, 'client_company_id' => 999999, 'user_id' => $this->manager->id, 'role' => 'owner',
        ])->assertRedirect();
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

        $url = route('contacts.update', [$this->workspace, $this->company, $contact]);
        $this->patch($url, array_replace($this->details(), ['is_archived' => true, 'lock_version' => 1]))->assertRedirect();
        $this->assertTrue($contact->fresh()->is_archived);
        $this->assertSame(2, $contact->fresh()->lock_version);
        $this->patch($url, array_replace($this->details(), ['notes' => 'Stale write', 'lock_version' => 1]))->assertStatus(409);
        $this->assertSame('Synthetic historical contact', $contact->fresh()->notes);
    }

    public function test_settings_expose_contact_details_only_to_workspace_managers(): void
    {
        $contact = ClientContact::query()->create(['workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id] + $this->details());
        $this->actingAs($this->manager)->get(route('clients.settings', [$this->workspace, $this->company]))
            ->assertOk()->assertInertia(fn (Assert $page) => $page->has('contacts', 1)
            ->where('contacts.0.email', 'casey@example.test')
            ->where('contacts.0.update_action', route('contacts.update', [$this->workspace, $this->company, $contact])));
        $member = User::factory()->create();
        $this->workspace->memberships()->create(['user_id' => $member->id, 'role' => 'member']);
        $this->actingAs($member)->get(route('clients.settings', [$this->workspace, $this->company]))->assertForbidden();
        $this->post(route('contacts.store', [$this->workspace, $this->company]), $this->details())->assertForbidden();
        $this->patch(route('clients.profile.update', [$this->workspace, $this->company]), ['notes' => 'Forbidden'])->assertForbidden();
    }

    public function test_contact_cannot_be_edited_through_another_company_or_workspace(): void
    {
        $sibling = ClientCompany::query()->create(['workspace_id' => $this->workspace->id, 'name' => 'Sibling', 'slug' => 'sibling']);
        $contact = ClientContact::query()->create(['workspace_id' => $this->workspace->id, 'client_company_id' => $sibling->id] + $this->details());
        $this->actingAs($this->manager)->patch(route('contacts.update', [$this->workspace, $this->company, $contact]), $this->details() + ['lock_version' => 1])->assertNotFound();
        $foreign = Workspace::query()->create(['name' => 'Other Workspace', 'slug' => 'other-workspace']);
        $foreign->memberships()->create(['user_id' => $this->manager->id, 'role' => 'owner']);
        $this->patch(route('contacts.update', [$foreign, $this->company, $contact]), $this->details() + ['lock_version' => 1])->assertNotFound();
        $this->patch(route('clients.profile.update', [$foreign, $this->company]), ['notes' => 'Foreign write'])->assertNotFound();
        $this->assertSame('Synthetic historical contact', $contact->fresh()->notes);
        $this->assertNull($this->company->fresh()->notes);
    }

    /** @return array<string, mixed> */
    private function details(): array
    {
        return ['first_name' => 'Casey', 'last_name' => 'Example', 'title' => 'Historical contributor',
            'email' => 'casey@example.test', 'phone' => '555-0102', 'notes' => 'Synthetic historical contact', 'is_archived' => false];
    }
}
