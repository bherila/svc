<?php

namespace Tests\Feature\Access;

use App\Models\AccessAuditEvent;
use App\Models\ClientCompany;
use App\Models\ClientCompanyMembership;
use App\Models\ClientProject;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsAccessFixtures;
use Tests\TestCase;

/**
 * Portal access from a client's settings page: who can use the client's portal,
 * what each of them reaches, and adding, narrowing and removing them, all
 * through the access boundary.
 */
final class ClientPortalAccessScreenTest extends TestCase
{
    use BuildsAccessFixtures;
    use RefreshDatabase;

    private Workspace $workspace;

    private User $owner;

    private User $admin;

    private User $member;

    private ClientCompany $company;

    private ClientProject $alpha;

    private ClientProject $beta;

    private User $contact;

    protected function setUp(): void
    {
        parent::setUp();

        config(['bherila-auth.oauth_client.provider' => 'example']);
        $this->workspace = Workspace::query()->create(['name' => 'Portal', 'slug' => 'portal']);
        $this->owner = $this->staff($this->workspace, 'owner');
        $this->admin = $this->staff($this->workspace, 'admin');
        $this->member = $this->staff($this->workspace, 'member');
        $this->company = $this->company($this->workspace, 'portal-client');
        $this->alpha = $this->project($this->company, 'Alpha');
        $this->beta = $this->project($this->company, 'Beta');
        $this->contact = User::factory()->create(['name' => 'Example Contact', 'email' => 'contact@client.example.test', 'oauth_provider' => 'example', 'oauth_subject' => 'contact-subject']);
    }

    public function test_settings_lists_each_portal_user_with_what_they_reach_and_where_to_change_it(): void
    {
        $this->addPortal($this->contact, 'projects', [$this->beta, $this->alpha]);
        $other = User::factory()->create(['name' => 'Whole Client']);
        $this->addPortal($other, 'company');

        $this->actingAs($this->admin)->get($this->settings())->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('clients/settings')
                ->where('portal.add_url', $this->base())
                ->has('portal.members', 2)
                ->where('portal.members.0', [
                    'id' => $this->contact->public_id,
                    'name' => 'Example Contact',
                    'email' => 'contact@client.example.test',
                    'scope' => 'projects',
                    'projects' => [$this->alpha->public_id, $this->beta->public_id],
                    'url' => $this->base().'/'.$this->contact->public_id,
                ])
                ->where('portal.members.1.scope', 'company')
                ->where('portal.members.1.projects', []));
    }

    public function test_settings_lists_no_portal_user_of_another_client_or_workspace(): void
    {
        $otherCompany = $this->company($this->workspace, 'other-client');
        $this->addPortal($this->contact, 'company', [], $otherCompany);
        $elsewhere = Workspace::query()->create(['name' => 'Elsewhere', 'slug' => 'elsewhere']);
        $this->addPortal(User::factory()->create(), 'company', [], $this->company($elsewhere, 'elsewhere-client'));

        $this->actingAs($this->owner)->get($this->settings())->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('portal.members', []));
    }

    public function test_an_admin_adds_a_contact_by_the_address_they_sign_in_with(): void
    {
        $this->actingAs($this->admin)->from($this->settings())
            ->post($this->base(), ['email' => 'CONTACT@client.example.test', 'scope' => 'projects', 'projects' => [$this->alpha->public_id]])
            ->assertRedirect($this->settings())->assertSessionHas('status', 'Portal access given.');

        $membership = ClientCompanyMembership::query()->where('user_id', $this->contact->id)->sole();
        $this->assertSame('projects', $membership->access_scope);
        $this->assertSame([$this->alpha->id], DB::table('client_portal_project_access')->where('client_company_membership_id', $membership->id)->pluck('client_project_id')->map(intval(...))->all());
        $event = AccessAuditEvent::query()->sole();
        $this->assertSame(['portal.granted', $this->admin->id, $this->company->public_id], [$event->action, $event->actor_user_id, $event->client_company_public_id]);
    }

    public function test_adding_someone_who_cannot_sign_in_or_already_has_access_says_so_and_writes_nothing(): void
    {
        User::factory()->create(['email' => 'unbound@client.example.test', 'oauth_subject' => null]);
        // Codex review on #424: bound, but to a provider sign-in no longer uses.
        User::factory()->create(['email' => 'elsewhere@client.example.test', 'oauth_provider' => 'retired', 'oauth_subject' => 'elsewhere-subject']);
        foreach (['nobody@client.example.test', 'unbound@client.example.test', 'elsewhere@client.example.test'] as $email) {
            $this->actingAs($this->owner)->from($this->settings())->post($this->base(), ['email' => $email, 'scope' => 'company'])
                ->assertRedirect($this->settings())
                ->assertSessionHasErrors(['email' => 'Nobody signs in with that address yet. Ask them to sign in once, then add them.']);
        }

        $this->addPortal($this->contact, 'company');
        $this->actingAs($this->owner)->from($this->settings())->post($this->base(), ['email' => $this->contact->email, 'scope' => 'company'])
            ->assertSessionHasErrors(['email' => 'That person already has portal access to this client; change its scope instead.']);

        $this->assertSame(1, ClientCompanyMembership::query()->count());
        $this->assertSame(0, AccessAuditEvent::query()->count());
    }

    public function test_a_project_of_another_client_is_refused(): void
    {
        $foreign = $this->project($this->company($this->workspace, 'other-client'), 'Foreign');

        $this->actingAs($this->owner)->from($this->settings())
            ->post($this->base(), ['email' => $this->contact->email, 'scope' => 'projects', 'projects' => [$foreign->public_id]])
            ->assertSessionHasErrors(['email' => 'One or more projects do not belong to that client.']);

        $this->assertSame(0, ClientCompanyMembership::query()->count());
    }

    public function test_the_scope_must_agree_with_its_projects(): void
    {
        $this->actingAs($this->owner)->from($this->settings())
            ->post($this->base(), ['email' => $this->contact->email, 'scope' => 'projects', 'projects' => []])
            ->assertSessionHasErrors(['projects' => 'Choose at least one project, or give access to the whole client.']);
        $this->actingAs($this->owner)->from($this->settings())
            ->post($this->base(), ['email' => $this->contact->email, 'scope' => 'company', 'projects' => [$this->alpha->public_id]])
            ->assertSessionHasErrors(['projects' => 'Access to the whole client is not limited to projects.']);

        $this->assertSame(0, ClientCompanyMembership::query()->count());
    }

    public function test_narrowing_widening_and_removing_portal_access(): void
    {
        $this->addPortal($this->contact, 'company');
        $url = $this->base().'/'.$this->contact->public_id;

        $this->actingAs($this->owner)->from($this->settings())->put($url, ['scope' => 'projects', 'projects' => [$this->beta->public_id]])
            ->assertRedirect($this->settings())->assertSessionHas('status', 'Portal access updated.');
        $membership = ClientCompanyMembership::query()->where('user_id', $this->contact->id)->sole();
        $this->assertSame('projects', $membership->access_scope);
        $this->assertSame([$this->beta->id], DB::table('client_portal_project_access')->where('client_company_membership_id', $membership->id)->pluck('client_project_id')->map(intval(...))->all());

        $this->actingAs($this->owner)->from($this->settings())->put($url, ['scope' => 'company'])->assertSessionHas('status', 'Portal access updated.');
        $this->assertSame('company', $membership->fresh()->access_scope);
        $this->assertSame(0, DB::table('client_portal_project_access')->count());

        $this->actingAs($this->owner)->from($this->settings())->delete($url)->assertRedirect($this->settings())->assertSessionHas('status', 'Portal access removed.');
        $this->assertNull(ClientCompanyMembership::query()->where('user_id', $this->contact->id)->first());
        $this->assertSame(['portal.scope_changed', 'portal.scope_changed', 'portal.revoked'], AccessAuditEvent::query()->orderBy('id')->pluck('action')->all());
    }

    public function test_changing_someone_without_portal_access_to_this_client_is_not_found(): void
    {
        $this->addPortal($this->contact, 'company', [], $this->company($this->workspace, 'other-client'));
        $url = $this->base().'/'.$this->contact->public_id;

        $this->actingAs($this->owner)->put($url, ['scope' => 'company'])->assertNotFound();
        $this->actingAs($this->owner)->delete($url)->assertNotFound();
        $this->assertSame(1, ClientCompanyMembership::query()->count());
    }

    public function test_a_member_or_a_stranger_manages_nobodys_portal_access(): void
    {
        $this->addPortal($this->contact, 'company');
        $url = $this->base().'/'.$this->contact->public_id;
        $stranger = User::factory()->create();

        foreach ([$this->member, $stranger] as $actor) {
            $this->actingAs($actor)->post($this->base(), ['email' => $this->contact->email, 'scope' => 'company'])->assertForbidden();
            $this->actingAs($actor)->put($url, ['scope' => 'projects', 'projects' => [$this->alpha->public_id]])->assertForbidden();
            $this->actingAs($actor)->delete($url)->assertForbidden();
        }

        $this->assertSame('company', ClientCompanyMembership::query()->sole()->access_scope);
        $this->assertSame(0, AccessAuditEvent::query()->count());
    }

    private function settings(): string
    {
        return "/workspaces/{$this->workspace->public_id}/clients/{$this->company->public_id}/settings";
    }

    private function base(): string
    {
        return "/workspaces/{$this->workspace->public_id}/clients/{$this->company->public_id}/portal-access";
    }

    /** @param list<ClientProject> $projects */
    private function addPortal(User $user, string $scope, array $projects = [], ?ClientCompany $company = null): void
    {
        $company ??= $this->company;
        $membership = new ClientCompanyMembership;
        $membership->forceFill(['workspace_id' => $company->workspace_id, 'client_company_id' => $company->id, 'user_id' => $user->id, 'role' => 'client', 'access_scope' => $scope])->save();
        foreach ($projects as $project) {
            DB::table('client_portal_project_access')->insert(['workspace_id' => $company->workspace_id, 'client_company_membership_id' => $membership->id,
                'client_project_id' => $project->id, 'created_at' => now(), 'updated_at' => now()]);
        }
    }
}
