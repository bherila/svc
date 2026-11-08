<?php

namespace Tests\Feature\Search;

use App\Models\ClientCompany;
use App\Models\ClientCompanyMembership;
use App\Models\ClientContact;
use App\Models\ClientInvoice;
use App\Models\ClientProject;
use App\Models\PaletteRecent;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Search\PaletteRecents;
use App\Support\AgentApi\ProjectRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * What the palette offers before anything is typed: commands and recent items.
 *
 * Commands are finished URLs or absent. The test that matters most is the
 * negative one - Revenue must never reach anyone who is not an owner or admin,
 * however much else they may do - and it is run for every kind of viewer the
 * product has, because the Dashboard it sits on is open to all of them.
 */
final class PaletteTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $owner;

    private User $admin;

    private User $member;

    private User $viewer;

    private User $projectOwner;

    private User $client;

    private ClientCompany $acme;

    private ClientCompany $globex;

    private ClientProject $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = Workspace::query()->create(['name' => 'Palette Works', 'slug' => 'palette-works']);
        foreach (['owner', 'admin', 'member', 'viewer', 'projectOwner', 'client'] as $name) {
            $this->{$name} = User::factory()->create(['name' => ucfirst($name).' Synthetic']);
        }
        $this->workspace->memberships()->create(['user_id' => $this->owner->id, 'role' => 'owner']);
        $this->workspace->memberships()->create(['user_id' => $this->admin->id, 'role' => 'admin']);
        $this->workspace->memberships()->create(['user_id' => $this->member->id, 'role' => 'member']);
        $this->workspace->memberships()->create(['user_id' => $this->viewer->id, 'role' => 'member']);
        $this->workspace->memberships()->create(['user_id' => $this->projectOwner->id, 'role' => 'member']);

        $this->acme = ClientCompany::query()->create(['workspace_id' => $this->workspace->id, 'name' => 'Acme Palette', 'slug' => 'acme-palette']);
        $this->globex = ClientCompany::query()->create(['workspace_id' => $this->workspace->id, 'name' => 'Globex Palette', 'slug' => 'globex-palette']);
        $this->project = ClientProject::query()->create(['workspace_id' => $this->workspace->id, 'client_company_id' => $this->acme->id, 'name' => 'Palette Project', 'status' => 'active', 'is_visible_to_client' => true]);
        foreach ([[$this->member, ProjectRole::Contributor], [$this->viewer, ProjectRole::Viewer], [$this->projectOwner, ProjectRole::Owner]] as [$user, $role]) {
            $this->workspace->projectMemberships()->create(['client_project_id' => $this->project->id, 'user_id' => $user->id, 'role' => $role->value]);
        }
        ClientCompanyMembership::query()->create(['workspace_id' => $this->workspace->id, 'client_company_id' => $this->acme->id, 'user_id' => $this->client->id, 'role' => 'client', 'access_scope' => 'company']);
    }

    /** @return array<string, array<string, mixed>> actions by id */
    private function actions(User $viewer, string $path = ''): array
    {
        $json = $this->actingAs($viewer)->getJson('/palette?path='.urlencode($path))->assertOk()->json();

        $byId = [];
        foreach ($json['actions'] as $action) {
            $byId[$action['id']] = $action;
        }

        return $byId;
    }

    private function dashboardPath(): string
    {
        return "/workspaces/{$this->workspace->public_id}/dashboard";
    }

    public function test_an_owner_and_an_admin_are_offered_every_command_including_revenue(): void
    {
        foreach ([$this->owner, $this->admin] as $manager) {
            $actions = $this->actions($manager, $this->dashboardPath());

            $this->assertSame(['log_time', 'new_invoice', 'new_expense', 'payments', 'revenue', 'my_work', 'switch_workspace'], array_keys($actions), $manager->name);
            $this->assertSame("/workspaces/{$this->workspace->public_id}/dashboard", $actions['revenue']['href']);
            $this->assertSame("/workspaces/{$this->workspace->public_id}/payments", $actions['payments']['href']);
            $this->assertSame('/app', $actions['switch_workspace']['href']);
            // No client in context: Log time, New invoice and New expense land on the client picker and say so.
            $this->assertSame("/workspaces/{$this->workspace->public_id}/clients", $actions['new_invoice']['href']);
            $this->assertSame('Choose a client', $actions['new_invoice']['hint']);
            $this->assertSame('Choose a client', $actions['log_time']['hint']);
        }
    }

    public function test_a_client_in_context_makes_the_actions_land_on_that_clients_screens(): void
    {
        $base = "/workspaces/{$this->workspace->public_id}/clients/{$this->acme->public_id}";
        $actions = $this->actions($this->owner, $base);

        $this->assertSame("{$base}/time", $actions['log_time']['href']);
        $this->assertNull($actions['log_time']['hint']);
        $this->assertSame("{$base}/invoices", $actions['new_invoice']['href']);
        $this->assertSame("{$base}/expenses", $actions['new_expense']['href']);
        $this->assertSame("{$base}/settings", $actions['settings']['href']);
    }

    public function test_revenue_never_reaches_anyone_who_is_not_an_owner_or_admin(): void
    {
        $dashboard = "/workspaces/{$this->workspace->public_id}/dashboard";

        foreach ([
            'a plain member' => $this->member,
            'a project viewer' => $this->viewer,
            'a project owner' => $this->projectOwner,
        ] as $who => $user) {
            foreach ([$this->dashboardPath(), "/workspaces/{$this->workspace->public_id}/clients/{$this->acme->public_id}", '', '/app'] as $path) {
                $body = (string) $this->actingAs($user)->getJson('/palette?path='.urlencode($path))->assertOk()->getContent();

                $this->assertStringNotContainsString('revenue', strtolower($body), "{$who} on '{$path}'");
                $this->assertStringNotContainsString($dashboard, $body, "{$who} on '{$path}'");
                $this->assertStringNotContainsString('/payments', $body, "{$who} on '{$path}'");
            }
        }

        // The client contact, too, on the portal and on a staff page they cannot open.
        foreach (["/portal/{$this->acme->public_id}", $this->dashboardPath(), ''] as $path) {
            $body = (string) $this->actingAs($this->client)->getJson('/palette?path='.urlencode($path))->assertOk()->getContent();
            $this->assertStringNotContainsString('revenue', strtolower($body));
            $this->assertStringNotContainsString('/dashboard', $body);
        }
    }

    public function test_a_light_workspace_has_no_revenue_or_invoice_commands_even_for_its_owner(): void
    {
        $this->workspace->forceFill(['edition' => 'light'])->save();

        $actions = $this->actions($this->owner, $this->dashboardPath());

        $this->assertArrayNotHasKey('revenue', $actions);
        $this->assertArrayNotHasKey('new_invoice', $actions);
        $this->assertArrayHasKey('new_expense', $actions);
    }

    public function test_a_scoped_member_is_offered_only_what_they_can_do(): void
    {
        $actions = $this->actions($this->member, $this->dashboardPath());

        $this->assertSame(['log_time', 'my_work', 'switch_workspace'], array_keys($actions));
        $this->assertSame("/workspaces/{$this->workspace->public_id}/my-work", $actions['log_time']['href']);

        // A viewer cannot log time against anything, so the command is not offered.
        $this->assertArrayNotHasKey('log_time', $this->actions($this->viewer, $this->dashboardPath()));
        // A member in a client's context logs time on that client's sheet.
        $this->assertSame("/workspaces/{$this->workspace->public_id}/clients/{$this->acme->public_id}/time",
            $this->actions($this->member, "/workspaces/{$this->workspace->public_id}/clients/{$this->acme->public_id}")['log_time']['href']);
        // And never the client's settings.
        $this->assertArrayNotHasKey('settings', $this->actions($this->projectOwner, "/workspaces/{$this->workspace->public_id}/clients/{$this->acme->public_id}"));
    }

    public function test_a_client_contact_is_offered_the_portal_commands(): void
    {
        ClientInvoice::query()->create([
            'workspace_id' => $this->workspace->id, 'client_company_id' => $this->acme->id, 'invoice_number' => 'PAL-001',
            'status' => 'issued', 'currency' => 'USD', 'issue_date' => '2026-03-01', 'is_visible_to_client' => true,
        ]);

        $actions = $this->actions($this->client, "/portal/{$this->acme->public_id}");

        $this->assertSame(['pay', 'billing', 'invoices', 'approvals', 'switch_workspace'], array_keys($actions));
        $this->assertSame("/portal/{$this->acme->public_id}/invoices", $actions['pay']['href']);
        $this->assertSame("/portal/{$this->acme->public_id}/billing", $actions['billing']['href']);

        $json = $this->actingAs($this->client)->getJson('/palette?path='.urlencode("/portal/{$this->acme->public_id}"))->json();
        $this->assertSame('portal', $json['audience']);
    }

    public function test_pay_is_offered_only_while_there_is_something_to_pay(): void
    {
        $this->assertArrayNotHasKey('pay', $this->actions($this->client, "/portal/{$this->acme->public_id}"));

        ClientInvoice::query()->create([
            'workspace_id' => $this->workspace->id, 'client_company_id' => $this->acme->id, 'invoice_number' => 'PAL-PAID',
            'status' => 'paid', 'currency' => 'USD', 'issue_date' => '2026-03-01', 'is_visible_to_client' => true,
        ]);
        $this->assertArrayNotHasKey('pay', $this->actions($this->client, "/portal/{$this->acme->public_id}"), 'A paid invoice is not payable');

        ClientInvoice::query()->create([
            'workspace_id' => $this->workspace->id, 'client_company_id' => $this->acme->id, 'invoice_number' => 'PAL-HIDDEN',
            'status' => 'issued', 'currency' => 'USD', 'issue_date' => '2026-03-01', 'is_visible_to_client' => false,
        ]);
        $this->assertArrayNotHasKey('pay', $this->actions($this->client, "/portal/{$this->acme->public_id}"), 'An invoice the client cannot see is not theirs to pay');
    }

    public function test_a_client_of_another_company_is_never_pointed_at_this_companys_screens(): void
    {
        $other = User::factory()->create();
        ClientCompanyMembership::query()->create(['workspace_id' => $this->workspace->id, 'client_company_id' => $this->globex->id, 'user_id' => $other->id, 'role' => 'client', 'access_scope' => 'company']);

        $body = (string) $this->actingAs($other)->getJson('/palette?path='.urlencode("/portal/{$this->acme->public_id}"))->assertOk()->getContent();

        $this->assertStringNotContainsString($this->acme->public_id, $body);
        $this->assertStringContainsString($this->globex->public_id, $body, 'Control: their own company is offered');
    }

    public function test_a_workspace_that_is_not_theirs_resolves_to_nothing(): void
    {
        $elsewhere = Workspace::query()->create(['name' => 'Elsewhere Works', 'slug' => 'elsewhere-works']);

        $json = $this->actingAs($this->owner)->getJson('/palette?path='.urlencode("/workspaces/{$elsewhere->public_id}/dashboard"))->assertOk()->json();

        $this->assertNull($json['workspace']);
        $this->assertSame(['switch_workspace'], array_column($json['actions'], 'id'));
        $this->assertStringNotContainsString($elsewhere->public_id, json_encode($json, JSON_THROW_ON_ERROR));
    }

    public function test_someone_with_no_workspace_is_offered_nothing(): void
    {
        $json = $this->actingAs(User::factory()->create())->getJson('/palette')->assertOk()->json();

        $this->assertNull($json['audience']);
        $this->assertSame([], $json['actions']);
        $this->assertSame([], $json['recents']);
    }

    public function test_the_palette_is_closed_to_anonymous_callers(): void
    {
        $this->getJson('/palette')->assertUnauthorized();
        $this->postJson('/palette/recents', [])->assertUnauthorized();
    }

    public function test_opened_records_become_recent_items_newest_first(): void
    {
        $this->open($this->owner, 'client', $this->acme->public_id);
        $this->open($this->owner, 'project', $this->project->public_id);

        $recents = $this->recents($this->owner);

        $this->assertSame(['project', 'client'], array_column($recents, 'kind'));
        $this->assertSame('Palette Project', $recents[0]['title']);
        $this->assertSame("/workspaces/{$this->workspace->public_id}/clients/{$this->acme->public_id}/projects/{$this->project->public_id}", $recents[0]['href']);

        // Opening one again moves it to the front instead of adding a second row.
        $this->open($this->owner, 'client', $this->acme->public_id);
        $this->assertSame(['client', 'project'], array_column($this->recents($this->owner), 'kind'));
        $this->assertSame(2, PaletteRecent::query()->count());
    }

    public function test_only_the_last_eight_are_kept(): void
    {
        $ids = [];
        foreach (range(1, 11) as $i) {
            $company = ClientCompany::query()->create(['workspace_id' => $this->workspace->id, 'name' => "Recent Client {$i}", 'slug' => "recent-client-{$i}"]);
            $ids[$i] = $company->public_id;
            $this->open($this->owner, 'client', $company->public_id);
        }

        $this->assertSame(PaletteRecents::KEEP, PaletteRecent::query()->where('user_id', $this->owner->id)->count());
        $titles = array_column($this->recents($this->owner), 'title');
        $this->assertSame('Recent Client 11', $titles[0]);
        $this->assertSame('Recent Client 4', $titles[7]);
        $this->assertCount(8, $titles);
    }

    public function test_recents_belong_to_the_person_who_opened_them(): void
    {
        $this->open($this->owner, 'client', $this->acme->public_id);

        $this->assertSame([], $this->recents($this->admin));
    }

    public function test_a_record_the_viewer_cannot_open_is_never_recorded(): void
    {
        // A scoped member cannot open Globex, which they hold no project in.
        $this->postRecent($this->member, 'client', $this->globex->public_id, $this->workspace->public_id)->assertOk()->assertExactJson(['recorded' => false]);
        // Nor a record from a workspace they are not in.
        $elsewhere = Workspace::query()->create(['name' => 'Elsewhere Works', 'slug' => 'elsewhere-works']);
        $foreign = ClientCompany::query()->create(['workspace_id' => $elsewhere->id, 'name' => 'Foreign Recent', 'slug' => 'foreign-recent']);
        $this->postRecent($this->owner, 'client', $foreign->public_id, $elsewhere->public_id)->assertOk()->assertExactJson(['recorded' => false]);
        // Nor a real record filed under a workspace it does not belong to.
        $this->postRecent($this->owner, 'client', $foreign->public_id, $this->workspace->public_id)->assertOk()->assertExactJson(['recorded' => false]);

        $this->assertSame(0, PaletteRecent::query()->count());
    }

    public function test_only_openable_kinds_can_be_recorded(): void
    {
        $this->postRecent($this->owner, 'time', $this->acme->public_id, $this->workspace->public_id)->assertUnprocessable();
        $this->postRecent($this->owner, 'client', 'not-a-uuid', $this->workspace->public_id)->assertUnprocessable();
        $this->postRecent($this->owner, 'client', $this->acme->public_id, 'not-a-uuid')->assertUnprocessable();
    }

    public function test_a_recent_the_viewer_can_no_longer_open_drops_out(): void
    {
        $this->open($this->member, 'project', $this->project->public_id);
        $this->assertCount(1, $this->recents($this->member));

        $this->workspace->projectMemberships()->where('user_id', $this->member->id)->delete();
        $this->assertSame([], $this->recents($this->member), 'Removed from the project');

        $this->workspace->projectMemberships()->create(['client_project_id' => $this->project->id, 'user_id' => $this->member->id, 'role' => ProjectRole::Contributor->value]);
        $this->assertCount(1, $this->recents($this->member), 'Control: back on it, back in the list');

        $this->workspace->memberships()->where('user_id', $this->member->id)->delete();
        $this->assertSame([], $this->recents($this->member), 'Removed from the workspace');
    }

    public function test_a_renamed_record_shows_its_new_name_because_only_the_id_is_stored(): void
    {
        $this->open($this->owner, 'client', $this->acme->public_id);

        $this->acme->forceFill(['name' => 'Acme Renamed'])->save();

        $this->assertSame('Acme Renamed', $this->recents($this->owner)[0]['title']);
        $this->assertSame(['kind', 'record_public_id'], array_values(array_intersect(['kind', 'record_public_id', 'title'], array_keys(PaletteRecent::query()->firstOrFail()->getAttributes()))));
    }

    public function test_recents_are_filed_by_workspace(): void
    {
        $second = Workspace::query()->create(['name' => 'Second Palette', 'slug' => 'second-palette']);
        $second->memberships()->create(['user_id' => $this->owner->id, 'role' => 'owner']);
        $other = ClientCompany::query()->create(['workspace_id' => $second->id, 'name' => 'Second Client', 'slug' => 'second-client']);

        $this->open($this->owner, 'client', $this->acme->public_id);
        $this->postRecent($this->owner, 'client', $other->public_id, $second->public_id)->assertOk()->assertExactJson(['recorded' => true]);

        $here = $this->actingAs($this->owner)->getJson('/palette?path='.urlencode($this->dashboardPath()))->json('recents');
        $there = $this->actingAs($this->owner)->getJson('/palette?path='.urlencode("/workspaces/{$second->public_id}/dashboard"))->json('recents');
        $anywhere = $this->actingAs($this->owner)->getJson('/palette?path=%2Fapp')->json('recents');

        $this->assertSame(['Acme Palette'], array_column($here, 'title'));
        $this->assertSame(['Second Client'], array_column($there, 'title'));
        $this->assertEqualsCanonicalizing(['Acme Palette', 'Second Client'], array_column($anywhere, 'title'));
    }

    public function test_a_client_contact_keeps_recents_of_what_the_portal_shows_them(): void
    {
        $invoice = ClientInvoice::query()->create([
            'workspace_id' => $this->workspace->id, 'client_company_id' => $this->acme->id, 'invoice_number' => 'PAL-RECENT',
            'status' => 'issued', 'currency' => 'USD', 'issue_date' => '2026-03-01', 'is_visible_to_client' => true,
        ]);
        $hidden = ClientInvoice::query()->create([
            'workspace_id' => $this->workspace->id, 'client_company_id' => $this->acme->id, 'invoice_number' => 'PAL-DRAFT',
            'status' => 'draft', 'currency' => 'USD', 'issue_date' => '2026-03-01', 'is_visible_to_client' => false,
        ]);

        $this->open($this->client, 'invoice', $invoice->public_id);
        $this->postRecent($this->client, 'invoice', $hidden->public_id, $this->workspace->public_id)->assertExactJson(['recorded' => false]);

        $recents = $this->recents($this->client, "/portal/{$this->acme->public_id}");
        $this->assertSame(['PAL-RECENT'], array_column($recents, 'title'));
        $this->assertSame("/portal/{$this->acme->public_id}/invoices/{$invoice->public_id}", $recents[0]['href']);
    }

    private function contact(string $first, string $last, ?ClientCompany $company = null, ?Workspace $workspace = null): ClientContact
    {
        $workspace ??= $this->workspace;

        return ClientContact::query()->create([
            'workspace_id' => $workspace->id, 'client_company_id' => ($company ?? $this->acme)->id,
            'first_name' => $first, 'last_name' => $last, 'email' => strtolower($first.'.'.$last).'@recents.example.test',
        ]);
    }

    private function portalMember(string $name, ?ClientCompany $company = null, ?Workspace $workspace = null): ClientCompanyMembership
    {
        $user = User::factory()->create(['name' => $name]);

        return ClientCompanyMembership::query()->create([
            'workspace_id' => ($workspace ?? $this->workspace)->id, 'client_company_id' => ($company ?? $this->acme)->id,
            'user_id' => $user->id, 'role' => 'client', 'access_scope' => 'company',
        ]);
    }

    public function test_a_later_contact_is_recorded_and_shown_as_itself_not_the_first(): void
    {
        $first = $this->contact('Aaron', 'Alpha');
        $second = $this->contact('Zed', 'Zulu');

        $this->open($this->owner, 'contact', $second->public_id);

        $recents = $this->recents($this->owner);
        $this->assertSame([$second->public_id], array_column($recents, 'id'));
        $this->assertSame(['contact'], array_column($recents, 'kind'));
        $this->assertSame('Zed Zulu', $recents[0]['title']);

        $this->open($this->owner, 'contact', $first->public_id);
        $this->assertSame([$first->public_id, $second->public_id], array_column($this->recents($this->owner), 'id'));
    }

    public function test_an_older_portal_membership_is_recorded_and_shown_as_itself_when_a_newer_exists(): void
    {
        $older = $this->portalMember('Older Portal Person');
        $newer = $this->portalMember('Newer Portal Person');

        $this->open($this->owner, 'portal_user', $older->public_id);

        $recents = $this->recents($this->owner);
        $this->assertSame([$older->public_id], array_column($recents, 'id'));
        $this->assertSame('Older Portal Person', $recents[0]['title']);
        $this->assertNotSame($newer->public_id, $recents[0]['id']);
    }

    public function test_an_unknown_contact_or_membership_id_is_ignored_even_when_others_exist(): void
    {
        $this->contact('Real', 'Contact');
        $this->portalMember('Real Portal Person');

        $this->postRecent($this->owner, 'contact', (string) Str::uuid(), $this->workspace->public_id)->assertOk()->assertExactJson(['recorded' => false]);
        $this->postRecent($this->owner, 'portal_user', (string) Str::uuid(), $this->workspace->public_id)->assertOk()->assertExactJson(['recorded' => false]);

        $this->assertSame(0, PaletteRecent::query()->count());
    }

    public function test_a_contact_or_membership_of_another_workspace_is_never_recorded(): void
    {
        $elsewhere = Workspace::query()->create(['name' => 'Elsewhere Contacts', 'slug' => 'elsewhere-contacts']);
        $elsewhere->memberships()->create(['user_id' => $this->owner->id, 'role' => 'owner']);
        $foreignCompany = ClientCompany::query()->create(['workspace_id' => $elsewhere->id, 'name' => 'Foreign Co', 'slug' => 'foreign-co']);
        $foreignContact = $this->contact('Foreign', 'Contact', $foreignCompany, $elsewhere);
        $foreignMember = $this->portalMember('Foreign Portal Person', $foreignCompany, $elsewhere);
        // Local rows exist, so "some result" would exist if the id were ignored.
        $this->contact('Local', 'Contact');
        $this->portalMember('Local Portal Person');

        // Real rows, filed under a workspace they do not belong to.
        $this->postRecent($this->owner, 'contact', $foreignContact->public_id, $this->workspace->public_id)->assertExactJson(['recorded' => false]);
        $this->postRecent($this->owner, 'portal_user', $foreignMember->public_id, $this->workspace->public_id)->assertExactJson(['recorded' => false]);
        $this->assertSame(0, PaletteRecent::query()->count());

        // Control: the same rows are recordable under their own workspace.
        $this->postRecent($this->owner, 'contact', $foreignContact->public_id, $elsewhere->public_id)->assertExactJson(['recorded' => true]);
        $this->postRecent($this->owner, 'portal_user', $foreignMember->public_id, $elsewhere->public_id)->assertExactJson(['recorded' => true]);
    }

    public function test_an_archived_or_deleted_contact_and_a_revoked_membership_drop_out_of_recents(): void
    {
        $keptContact = $this->contact('Kept', 'Contact');
        $archived = $this->contact('Archived', 'Contact');
        $deleted = $this->contact('Deleted', 'Contact');
        $keptMember = $this->portalMember('Kept Portal Person');
        $revoked = $this->portalMember('Revoked Portal Person');

        foreach ([[$keptContact, 'contact'], [$archived, 'contact'], [$deleted, 'contact'], [$keptMember, 'portal_user'], [$revoked, 'portal_user']] as [$record, $kind]) {
            $this->open($this->owner, $kind, $record->public_id);
        }
        $this->assertCount(5, $this->recents($this->owner));

        $archived->forceFill(['is_archived' => true])->save();
        $deleted->delete();
        $revoked->delete();

        $this->assertEqualsCanonicalizing([$keptContact->public_id, $keptMember->public_id], array_column($this->recents($this->owner), 'id'));
        // An archived contact cannot be newly recorded either.
        $this->postRecent($this->owner, 'contact', $archived->public_id, $this->workspace->public_id)->assertExactJson(['recorded' => false]);
    }

    public function test_a_non_manager_cannot_record_or_see_contacts_and_portal_users(): void
    {
        $contact = $this->contact('Managed', 'Contact');
        $membership = $this->portalMember('Managed Portal Person');
        $this->open($this->owner, 'contact', $contact->public_id);
        $this->open($this->owner, 'portal_user', $membership->public_id);

        foreach ([$this->member, $this->viewer, $this->client] as $viewer) {
            $this->postRecent($viewer, 'contact', $contact->public_id, $this->workspace->public_id)->assertExactJson(['recorded' => false]);
            $this->postRecent($viewer, 'portal_user', $membership->public_id, $this->workspace->public_id)->assertExactJson(['recorded' => false]);
            $this->assertSame(0, PaletteRecent::query()->where('user_id', $viewer->id)->count());
        }
        $this->assertCount(2, $this->recents($this->owner), 'Control: the manager still has them');

        // Even rows stored for someone who is not a manager are never shown to them.
        PaletteRecent::query()->where('user_id', $this->owner->id)->update(['user_id' => $this->member->id]);
        $this->assertSame([], $this->recents($this->member));
    }

    public function test_resolving_recents_costs_the_same_queries_however_many_contacts_and_memberships(): void
    {
        $count = function (): int {
            $this->actingAs($this->owner);
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson('/palette?path='.urlencode($this->dashboardPath()))->assertOk();
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };
        $this->open($this->owner, 'contact', $this->contact('One', 'Contact')->public_id);
        $this->open($this->owner, 'portal_user', $this->portalMember('One Portal Person')->public_id);
        $small = $count();

        foreach (range(2, 4) as $i) {
            $this->open($this->owner, 'contact', $this->contact('Many'.$i, 'Contact')->public_id);
            $this->open($this->owner, 'portal_user', $this->portalMember('Many Portal Person '.$i)->public_id);
        }

        $this->assertCount(8, $this->recents($this->owner));
        $this->assertSame($small, $count());
    }

    private function open(User $user, string $kind, string $id): void
    {
        $this->postRecent($user, $kind, $id, $this->workspace->public_id)->assertOk()->assertExactJson(['recorded' => true]);
    }

    private function postRecent(User $user, string $kind, string $id, string $workspaceId): TestResponse
    {
        return $this->actingAs($user)->postJson('/palette/recents', ['workspace_id' => $workspaceId, 'kind' => $kind, 'id' => $id]);
    }

    /** @return list<array<string, mixed>> */
    private function recents(User $user, ?string $path = null): array
    {
        return $this->actingAs($user)->getJson('/palette?path='.urlencode($path ?? $this->dashboardPath()))->assertOk()->json('recents');
    }
}
