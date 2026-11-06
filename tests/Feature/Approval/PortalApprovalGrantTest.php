<?php

namespace Tests\Feature\Approval;

use App\Models\AccessAuditEvent;
use App\Models\ClientCompany;
use App\Models\ClientCompanyMembership;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AgentApi\AgentPortalContactReadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsAccessFixtures;
use Tests\TestCase;

/**
 * The control behind a portal contact's "Can approve time and expenses": a
 * manager sets it from the client's settings page through the same
 * `clients.portal_access.update` operation REST and MCP use. The grant decides
 * either way, no grant falls back to the billing contact, and nobody approves
 * their own work however they were allowed to approve.
 */
final class PortalApprovalGrantTest extends TestCase
{
    use BuildsAccessFixtures;
    use RefreshDatabase;

    private Workspace $workspace;

    private User $owner;

    private User $member;

    private ClientCompany $company;

    private User $billing;

    private User $other;

    protected function setUp(): void
    {
        parent::setUp();
        config(['bherila-auth.oauth_client.provider' => 'example', 'agent_api.writes_enabled' => false, 'agent_api.client_writes_enabled' => false]);
        $this->workspace = Workspace::query()->create(['name' => 'Synthetic Grant WS', 'slug' => 'synthetic-grants']);
        $this->owner = $this->staff($this->workspace, 'owner');
        $this->member = $this->staff($this->workspace, 'member');
        $this->company = ClientCompany::query()->create([
            'workspace_id' => $this->workspace->id, 'name' => 'Synthetic Granting Client', 'slug' => 'synthetic-granting',
            'billing_email' => 'ap@synthetic.example.test',
        ]);
        $this->project($this->company, 'Synthetic Project');
        $this->billing = $this->contact('ap@synthetic.example.test');
        $this->other = $this->contact('other@synthetic.example.test');
    }

    public function test_the_settings_page_shows_the_stored_grant_and_the_effective_state_per_contact(): void
    {
        $this->actingAs($this->owner)->get($this->settings())->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('portal.members.0.can_approve_time_and_expenses', null)
            ->where('portal.members.0.approval', [
                'allowed' => true, 'basis' => 'billing_contact',
                'label' => 'Billing contact (default)', 'default_label' => 'Billing contact (default)',
            ])
            ->where('portal.members.1.can_approve_time_and_expenses', null)
            ->where('portal.members.1.approval', [
                'allowed' => false, 'basis' => 'not_billing_contact',
                'label' => 'Not the billing contact (default)', 'default_label' => 'Not the billing contact (default)',
            ]));

        $this->change($this->owner, $this->other, true)->assertOk();
        $this->change($this->owner, $this->billing, false)->assertOk();

        $this->actingAs($this->owner)->get($this->settings())->assertInertia(fn (Assert $page) => $page
            ->where('portal.members.0.can_approve_time_and_expenses', false)
            ->where('portal.members.0.approval.label', 'Cannot approve (set by a manager)')
            // What returning to the default would mean stays visible next to an explicit setting.
            ->where('portal.members.0.approval.default_label', 'Billing contact (default)')
            ->where('portal.members.1.can_approve_time_and_expenses', true)
            ->where('portal.members.1.approval.label', 'Can approve (set by a manager)')
            ->where('portal.members.1.approval.default_label', 'Not the billing contact (default)'));
    }

    public function test_a_grant_lets_another_contact_approve_and_is_audited(): void
    {
        $row = $this->hours();
        $this->approve($this->other, ['time_ids' => [$row->public_id]])->assertForbidden();

        $this->change($this->owner, $this->other, true)->assertOk()
            ->assertJsonPath('data.can_approve_time_and_expenses', true)
            ->assertJsonPath('data.approval.basis', 'granted')
            ->assertJsonPath('data.approval.allowed', true);

        $this->approve($this->other, ['time_ids' => [$row->public_id]])->assertSessionHasNoErrors();
        $this->assertNotNull($row->fresh()->client_approved_at);

        $event = AccessAuditEvent::query()->sole();
        $this->assertSame(['portal.approval_changed', $this->owner->id, $this->other->id, $this->company->public_id], [$event->action, $event->actor_user_id, $event->subject_user_id, $event->client_company_public_id]);
        $this->assertSame(['can_approve_time_and_expenses' => null], $event->before_state);
        $this->assertSame(['can_approve_time_and_expenses' => true], $event->after_state);
        $this->assertNotNull($event->operation_id);
    }

    public function test_an_explicit_no_denies_even_the_billing_contact(): void
    {
        $row = $this->hours();
        $this->change($this->owner, $this->billing, false)->assertOk()
            ->assertJsonPath('data.approval.allowed', false)
            ->assertJsonPath('data.approval.basis', 'denied');

        $this->actingAs($this->billing)->get('/portal/'.$this->company->public_id)->assertInertia(fn (Assert $page) => $page->where('to_approve', null));
        $this->approve($this->billing, ['time_ids' => [$row->public_id]])->assertForbidden();
        $this->assertNull($row->fresh()->client_approved_at);
    }

    public function test_null_returns_a_contact_to_the_default_and_leaving_it_out_changes_nothing(): void
    {
        $this->change($this->owner, $this->billing, false)->assertOk();
        $this->assertFalse($this->membership($this->billing)->can_approve_time_and_expenses);

        // Left out: the grant stays, and so does the audit trail.
        $this->send($this->owner, 'PATCH', $this->urlOf($this->billing), ['scope' => 'company', 'expected_version' => $this->version($this->billing), 'confirm' => true])->assertOk()
            ->assertJsonPath('data.can_approve_time_and_expenses', false);
        $this->assertSame(1, AccessAuditEvent::query()->count());

        $this->change($this->owner, $this->billing, null)->assertOk()
            ->assertJsonPath('data.can_approve_time_and_expenses', null)
            ->assertJsonPath('data.approval.basis', 'billing_contact');
        $this->assertNull($this->membership($this->billing)->can_approve_time_and_expenses);

        $last = AccessAuditEvent::query()->orderByDesc('id')->firstOrFail();
        $this->assertSame([['can_approve_time_and_expenses' => false], ['can_approve_time_and_expenses' => null]], [$last->before_state, $last->after_state]);
    }

    public function test_setting_what_is_already_set_writes_and_audits_nothing(): void
    {
        $this->change($this->owner, $this->other, null)->assertOk();
        $this->change($this->owner, $this->other, true)->assertOk();
        $this->change($this->owner, $this->other, true)->assertOk();

        $this->assertSame(1, AccessAuditEvent::query()->count());
    }

    public function test_the_grant_and_the_scope_change_together_and_are_audited_separately(): void
    {
        $alpha = $this->project($this->company, 'Alpha');

        $this->send($this->owner, 'PATCH', $this->urlOf($this->other), [
            'scope' => 'projects', 'projects' => [$alpha->public_id], 'can_approve_time_and_expenses' => true,
            'expected_version' => $this->version($this->other), 'confirm' => true,
        ])->assertOk()->assertJsonPath('data.scope', 'projects')->assertJsonPath('data.can_approve_time_and_expenses', true);

        $this->assertSame(['portal.scope_changed', 'portal.approval_changed'], AccessAuditEvent::query()->orderBy('id')->pluck('action')->all());
    }

    public function test_only_a_boolean_or_null_is_accepted(): void
    {
        foreach (['maybe', [true], 'yes please'] as $junk) {
            $this->change($this->owner, $this->other, $junk)->assertUnprocessable()->assertJsonValidationErrors('can_approve_time_and_expenses');
        }

        $this->assertNull($this->membership($this->other)->can_approve_time_and_expenses);
        $this->assertSame(0, AccessAuditEvent::query()->count());
    }

    /** @return array<string, array{0: string, 1: int}> */
    public static function outsiders(): array
    {
        return ['member' => ['member', 403], 'stranger' => ['stranger', 404]];
    }

    #[DataProvider('outsiders')]
    public function test_a_member_or_a_stranger_cannot_set_the_grant(string $who, int $status): void
    {
        $this->change($who === 'member' ? $this->member : User::factory()->create(), $this->other, true)->assertStatus($status);

        $this->assertNull($this->membership($this->other)->can_approve_time_and_expenses);
        $this->assertSame(0, AccessAuditEvent::query()->count());
    }

    public function test_a_manager_of_another_workspace_cannot_reach_this_clients_contacts(): void
    {
        $elsewhere = Workspace::query()->create(['name' => 'Elsewhere', 'slug' => 'elsewhere-grants']);
        $foreignOwner = $this->staff($elsewhere, 'owner');
        $body = $this->body(true, $this->version($this->other));

        // Their own workspace, this workspace's client: not found.
        $this->send($foreignOwner, 'PATCH', "/api/v1/workspaces/{$elsewhere->public_id}/clients/{$this->company->public_id}/portal-access/{$this->other->public_id}", $body)->assertNotFound();

        $this->assertNull($this->membership($this->other)->can_approve_time_and_expenses);
        $this->assertSame(0, AccessAuditEvent::query()->count());
    }

    public function test_a_manager_cannot_set_the_grant_of_a_contact_of_another_workspaces_client(): void
    {
        $elsewhere = Workspace::query()->create(['name' => 'Elsewhere', 'slug' => 'elsewhere-grants']);
        $foreignContact = $this->contact('foreign@synthetic.example.test', $this->company($elsewhere, 'elsewhere-client'));

        $this->send($this->owner, 'PATCH', "/api/v1/workspaces/{$this->workspace->public_id}/clients/{$this->company->public_id}/portal-access/{$foreignContact->public_id}", $this->body(true, str_repeat('0', 64)))->assertNotFound();

        $this->assertNull(ClientCompanyMembership::query()->where('user_id', $foreignContact->id)->sole()->can_approve_time_and_expenses);
        $this->assertSame(0, AccessAuditEvent::query()->count());
    }

    public function test_a_grant_on_one_client_does_not_reach_a_second_client_of_the_same_person(): void
    {
        $second = ClientCompany::query()->create(['workspace_id' => $this->workspace->id, 'name' => 'Second', 'slug' => 'second-granting', 'billing_email' => 'someone@synthetic.example.test']);
        ClientCompanyMembership::query()->create(['client_company_id' => $second->id, 'user_id' => $this->other->id, 'role' => 'client', 'access_scope' => 'company']);

        $this->change($this->owner, $this->other, true)->assertOk();

        $this->assertNull(ClientCompanyMembership::query()->where('client_company_id', $second->id)->where('user_id', $this->other->id)->sole()->can_approve_time_and_expenses);
    }

    public function test_a_grant_never_lets_anyone_approve_their_own_work(): void
    {
        $person = $this->contact('worker@synthetic.example.test');
        $this->addStaff($this->workspace, $person, 'member');
        $own = $this->hours(['user_id' => $person->id]);
        $theirs = $this->hours();

        $this->change($this->owner, $person, true)->assertOk();

        $this->approve($person, ['time_ids' => [$own->public_id]])->assertSessionHasErrors('approval');
        $this->assertNull($own->fresh()->client_approved_at);
        $this->approve($person, ['time_ids' => [$theirs->public_id]])->assertSessionHasNoErrors();
        $this->assertNotNull($theirs->fresh()->client_approved_at);
    }

    private function contact(string $email, ?ClientCompany $company = null): User
    {
        $company ??= $this->company;
        $user = User::factory()->create(['email' => $email, 'oauth_provider' => 'example', 'oauth_subject' => 'subject-'.Str::random(8)]);
        ClientCompanyMembership::query()->create(['client_company_id' => $company->id, 'user_id' => $user->id, 'role' => 'client', 'access_scope' => 'company']);

        return $user;
    }

    /** @param array<string, mixed> $overrides */
    private function hours(array $overrides = []): ClientTimeEntry
    {
        $worker = User::factory()->create();
        $this->addStaff($this->workspace, $worker, 'member');

        return ClientTimeEntry::query()->create($overrides + [
            'workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id,
            'client_project_id' => $this->company->projects()->firstOrFail()->id,
            'user_id' => $worker->id, 'worked_on' => '2026-10-14', 'minutes' => 60,
            'description' => 'Synthetic INTERNAL note', 'client_visible_description' => 'Synthetic client text',
            'is_visible_to_client' => true, 'is_billable' => true, 'status' => 'approved',
            'billing_rate_amount' => 12345, 'currency' => 'USD',
        ]);
    }

    /** @param array<string, mixed> $data */
    private function approve(User $user, array $data): TestResponse
    {
        return $this->actingAs($user)->from('/portal/x')->post('/portal/'.$this->company->public_id.'/approvals/approve', $data);
    }

    private function change(User $actor, User $contact, mixed $grant): TestResponse
    {
        return $this->send($actor, 'PATCH', $this->urlOf($contact), $this->body($grant, $this->version($contact)));
    }

    /** @return array<string, mixed> */
    private function body(mixed $grant, string $version): array
    {
        return ['scope' => 'company', 'can_approve_time_and_expenses' => $grant, 'expected_version' => $version, 'confirm' => true];
    }

    /** @param array<string, mixed> $body */
    private function send(User $actor, string $method, string $url, array $body): TestResponse
    {
        // A browser request, as the settings page makes it: the session cookie, not a bearer token.
        return $this->actingAs($actor)->withCredentials()->withUnencryptedCookie((string) config('session.cookie'), Str::random(40))
            ->withHeader('Idempotency-Key', 'synthetic-'.Str::uuid())->json($method, $url, $body);
    }

    private function membership(User $user): ClientCompanyMembership
    {
        return ClientCompanyMembership::query()->where('client_company_id', $this->company->id)->where('user_id', $user->id)->sole();
    }

    private function version(User $contact): string
    {
        return (string) app(AgentPortalContactReadService::class)->contact($this->workspace, $this->company, $contact->public_id)['version'];
    }

    private function urlOf(User $contact): string
    {
        return "/api/v1/workspaces/{$this->workspace->public_id}/clients/{$this->company->public_id}/portal-access/{$contact->public_id}";
    }

    private function settings(): string
    {
        return "/workspaces/{$this->workspace->public_id}/clients/{$this->company->public_id}/settings";
    }
}
