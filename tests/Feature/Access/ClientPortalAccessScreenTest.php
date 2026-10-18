<?php

namespace Tests\Feature\Access;

use App\Models\AccessAuditEvent;
use App\Models\ClientCompany;
use App\Models\ClientCompanyMembership;
use App\Models\ClientProject;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Access\WorkspaceAccess;
use App\Services\AgentApi\AgentPortalContactReadService;
use App\Support\Access\AccessMutationContext;
use App\Support\Access\PortalAccessScope;
use App\Support\AgentApi\FirstPartySession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsAccessFixtures;
use Tests\TestCase;

/**
 * Portal access from a client's settings page: who can use the client's portal,
 * what each of them reaches, and adding, narrowing and removing them. The page
 * sends each change to the `/api/v1` operation agents use, on its session
 * (#385), so the same rules, receipts and audit trail apply.
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

        // Agent writes switched off: the website does not depend on them.
        config(['bherila-auth.oauth_client.provider' => 'example', 'agent_api.writes_enabled' => false, 'agent_api.client_writes_enabled' => false]);
        $this->workspace = Workspace::query()->create(['name' => 'Portal', 'slug' => 'portal']);
        $this->owner = $this->staff($this->workspace, 'owner');
        $this->admin = $this->staff($this->workspace, 'admin');
        $this->member = $this->staff($this->workspace, 'member');
        $this->company = $this->company($this->workspace, 'portal-client');
        $this->alpha = $this->project($this->company, 'Alpha');
        $this->beta = $this->project($this->company, 'Beta');
        $this->contact = User::factory()->create(['name' => 'Example Contact', 'email' => 'contact@client.example.test', 'oauth_provider' => 'example', 'oauth_subject' => 'contact-subject']);
    }

    public function test_settings_lists_each_portal_user_as_the_api_presents_them_with_same_origin_api_urls(): void
    {
        $this->addPortal($this->contact, 'projects', [$this->beta, $this->alpha]);
        $other = User::factory()->create(['name' => 'Whole Client']);
        $this->addPortal($other, 'company');
        $contactUrl = $this->base().'/'.$this->contact->public_id;

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
                    'version' => $this->version($this->contact),
                    'projects' => collect([$this->alpha, $this->beta])->sortBy('id')->map(fn (ClientProject $project): array => [
                        'id' => $project->public_id, 'name' => $project->name,
                        'href' => "/api/v1/workspaces/{$this->workspace->public_id}/projects/{$project->public_id}",
                    ])->values()->all(),
                    'update_href' => $contactUrl,
                    'revoke_href' => $contactUrl,
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
        $this->send($this->admin, 'POST', $this->base(), ['email' => 'CONTACT@client.example.test', 'scope' => 'projects', 'projects' => [$this->alpha->public_id], 'confirm' => true])
            ->assertCreated()->assertJsonPath('data.id', $this->contact->public_id);

        $membership = ClientCompanyMembership::query()->where('user_id', $this->contact->id)->sole();
        $this->assertSame('projects', $membership->access_scope);
        $this->assertSame([$this->alpha->id], DB::table('client_portal_project_access')->where('client_company_membership_id', $membership->id)->pluck('client_project_id')->map(intval(...))->all());
        $event = AccessAuditEvent::query()->sole();
        $this->assertSame(['portal.granted', $this->admin->id, $this->company->public_id], [$event->action, $event->actor_user_id, $event->client_company_public_id]);
        $this->assertNotNull($event->operation_id);
        $this->assertDatabaseHas('agent_mutation_receipts', ['operation' => 'clients.portal_access.grant', 'oauth_client_id' => FirstPartySession::CLIENT_ID]);
    }

    public function test_adding_someone_who_cannot_sign_in_or_already_has_access_says_so_and_writes_nothing(): void
    {
        User::factory()->create(['email' => 'unbound@client.example.test', 'oauth_subject' => null]);
        // Codex review on #424: bound, but to a provider sign-in no longer uses.
        User::factory()->create(['email' => 'elsewhere@client.example.test', 'oauth_provider' => 'retired', 'oauth_subject' => 'elsewhere-subject']);
        foreach (['nobody@client.example.test', 'unbound@client.example.test', 'elsewhere@client.example.test'] as $email) {
            $this->send($this->owner, 'POST', $this->base(), ['email' => $email, 'scope' => 'company', 'confirm' => true])
                ->assertUnprocessable()
                ->assertJsonPath('errors.email', ['Nobody signs in with that address yet. Ask them to sign in once, then add them.']);
        }

        $this->addPortal($this->contact, 'company');
        $this->send($this->owner, 'POST', $this->base(), ['email' => $this->contact->email, 'scope' => 'company', 'confirm' => true])
            ->assertUnprocessable()->assertJsonPath('message', 'That person already has portal access to this client; change its scope instead.');

        $this->assertSame(1, ClientCompanyMembership::query()->count());
        $this->assertSame(0, AccessAuditEvent::query()->count());
    }

    public function test_a_project_of_another_client_is_refused(): void
    {
        $foreign = $this->project($this->company($this->workspace, 'other-client'), 'Foreign');

        $this->send($this->owner, 'POST', $this->base(), ['email' => $this->contact->email, 'scope' => 'projects', 'projects' => [$foreign->public_id], 'confirm' => true])
            ->assertUnprocessable()->assertJsonPath('message', 'One or more projects do not belong to that client.');

        $this->assertSame(0, ClientCompanyMembership::query()->count());
    }

    public function test_the_scope_must_agree_with_its_projects_and_be_confirmed(): void
    {
        $this->send($this->owner, 'POST', $this->base(), ['email' => $this->contact->email, 'scope' => 'projects', 'projects' => [], 'confirm' => true])
            ->assertUnprocessable()->assertJsonPath('errors.projects', ['Choose at least one project, or give access to the whole client.']);
        $this->send($this->owner, 'POST', $this->base(), ['email' => $this->contact->email, 'scope' => 'company', 'projects' => [$this->alpha->public_id], 'confirm' => true])
            ->assertUnprocessable()->assertJsonPath('errors.projects', ['Access to the whole client is not limited to projects.']);
        $this->send($this->owner, 'POST', $this->base(), ['email' => $this->contact->email, 'scope' => 'company'])
            ->assertUnprocessable()->assertJsonValidationErrors('confirm');

        $this->assertSame(0, ClientCompanyMembership::query()->count());
    }

    public function test_narrowing_widening_and_removing_portal_access(): void
    {
        $this->addPortal($this->contact, 'company');
        $url = $this->base().'/'.$this->contact->public_id;

        $this->send($this->owner, 'PATCH', $url, ['scope' => 'projects', 'projects' => [$this->beta->public_id], 'expected_version' => $this->version($this->contact), 'confirm' => true])
            ->assertOk()->assertJsonPath('data.scope', 'projects');
        $membership = ClientCompanyMembership::query()->where('user_id', $this->contact->id)->sole();
        $this->assertSame('projects', $membership->access_scope);
        $this->assertSame([$this->beta->id], DB::table('client_portal_project_access')->where('client_company_membership_id', $membership->id)->pluck('client_project_id')->map(intval(...))->all());

        $this->send($this->owner, 'PATCH', $url, ['scope' => 'company', 'expected_version' => $this->version($this->contact), 'confirm' => true])->assertOk();
        $this->assertSame('company', $membership->fresh()->access_scope);
        $this->assertSame(0, DB::table('client_portal_project_access')->count());

        $this->send($this->owner, 'DELETE', $url, ['expected_version' => $this->version($this->contact), 'confirm' => true])
            ->assertOk()->assertJsonPath('data.revoked', true);
        $this->assertNull(ClientCompanyMembership::query()->where('user_id', $this->contact->id)->first());
        $this->assertSame(['portal.scope_changed', 'portal.scope_changed', 'portal.revoked'], AccessAuditEvent::query()->orderBy('id')->pluck('action')->all());
    }

    public function test_a_change_from_a_stale_page_is_refused_and_changes_nothing(): void
    {
        $this->addPortal($this->contact, 'company');
        $url = $this->base().'/'.$this->contact->public_id;
        $rendered = $this->version($this->contact);
        // Someone else narrows it after this page rendered.
        app(WorkspaceAccess::class)->changePortalScope(AccessMutationContext::user($this->owner), $this->company, $this->contact, PortalAccessScope::Projects, [$this->alpha->public_id]);

        $this->send($this->admin, 'PATCH', $url, ['scope' => 'company', 'expected_version' => $rendered, 'confirm' => true])->assertConflict();
        $this->send($this->admin, 'DELETE', $url, ['expected_version' => $rendered, 'confirm' => true])->assertConflict();

        $this->assertSame('projects', ClientCompanyMembership::query()->sole()->access_scope);
        $this->assertSame(1, AccessAuditEvent::query()->count());
    }

    public function test_changing_someone_without_portal_access_to_this_client_is_not_found(): void
    {
        $this->addPortal($this->contact, 'company', [], $this->company($this->workspace, 'other-client'));
        $url = $this->base().'/'.$this->contact->public_id;
        $version = str_repeat('0', 64);

        $this->send($this->owner, 'PATCH', $url, ['scope' => 'company', 'expected_version' => $version, 'confirm' => true])->assertNotFound();
        $this->send($this->owner, 'DELETE', $url, ['expected_version' => $version, 'confirm' => true])->assertNotFound();
        $this->assertSame(1, ClientCompanyMembership::query()->count());
    }

    /** @return array<string, array{0: string, 1: int}> */
    public static function outsiders(): array
    {
        // A member is refused; a stranger cannot see the workspace at all.
        return ['member' => ['member', 403], 'stranger' => ['stranger', 404]];
    }

    #[DataProvider('outsiders')]
    public function test_a_member_or_a_stranger_manages_nobodys_portal_access(string $who, int $status): void
    {
        $this->addPortal($this->contact, 'company');
        $url = $this->base().'/'.$this->contact->public_id;
        $actor = $who === 'member' ? $this->member : User::factory()->create();
        $version = $this->version($this->contact);

        $this->send($actor, 'POST', $this->base(), ['email' => $this->contact->email, 'scope' => 'company', 'confirm' => true])->assertStatus($status);
        $this->send($actor, 'PATCH', $url, ['scope' => 'projects', 'projects' => [$this->alpha->public_id], 'expected_version' => $version, 'confirm' => true])->assertStatus($status);
        $this->send($actor, 'DELETE', $url, ['expected_version' => $version, 'confirm' => true])->assertStatus($status);

        $this->assertSame('company', ClientCompanyMembership::query()->sole()->access_scope);
        $this->assertSame(0, AccessAuditEvent::query()->count());
    }

    /** @param array<string, mixed> $body */
    private function send(User $actor, string $method, string $url, array $body): TestResponse
    {
        // A browser request: the session cookie, not a bearer token. One
        // actor per test: the test process keeps the API guard's user.
        return $this->actingAs($actor)->withCredentials()->withUnencryptedCookie((string) config('session.cookie'), Str::random(40))
            ->withHeader('Idempotency-Key', 'synthetic-'.Str::uuid())->json($method, $url, $body);
    }

    private function version(User $contact): string
    {
        return (string) app(AgentPortalContactReadService::class)->contact($this->workspace, $this->company, $contact->public_id)['version'];
    }

    private function settings(): string
    {
        return "/workspaces/{$this->workspace->public_id}/clients/{$this->company->public_id}/settings";
    }

    private function base(): string
    {
        return "/api/v1/workspaces/{$this->workspace->public_id}/clients/{$this->company->public_id}/portal-access";
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
