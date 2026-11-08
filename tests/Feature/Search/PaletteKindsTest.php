<?php

namespace Tests\Feature\Search;

use App\Models\ClientAgreement;
use App\Models\ClientAttachment;
use App\Models\ClientCompany;
use App\Models\ClientCompanyMembership;
use App\Models\ClientContact;
use App\Models\ClientExpense;
use App\Models\ClientProject;
use App\Models\ClientProposal;
use App\Models\ClientTask;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Support\AgentApi\ProjectRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The palette's record kinds beyond clients, projects, invoices and tasks.
 *
 * Each kind opens a page that already decides who may read it, and this file
 * holds the palette to the same decision for every audience that reaches the
 * search: an owner, a member scoped to one project, a client contact (whole
 * company, and narrowed to one project), someone whose membership was revoked,
 * and a stranger from another workspace. A control row sits in every case so an
 * endpoint that returned nothing at all could not pass.
 *
 * Everything is synthetic. The shared word is "zeppelin" so one query reaches
 * every row.
 */
final class PaletteKindsTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private Workspace $elsewhere;

    private User $manager;

    private User $member;

    private User $client;

    private User $narrowClient;

    private User $otherClient;

    private ClientCompany $acme;

    private ClientCompany $globex;

    private ClientProject $mine;

    private ClientProject $notMine;

    private ClientProject $globexProject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = Workspace::query()->create(['name' => 'Zeppelin Works', 'slug' => 'zeppelin-works']);
        $this->elsewhere = Workspace::query()->create(['name' => 'Zeppelin Elsewhere', 'slug' => 'zeppelin-elsewhere']);

        $this->manager = User::factory()->create(['name' => 'Manager Synthetic']);
        $this->member = User::factory()->create(['name' => 'Member Synthetic']);
        $this->client = User::factory()->create(['name' => 'Zeppelin Companywide']);
        $this->narrowClient = User::factory()->create(['name' => 'Zeppelin Narrowed']);
        $this->otherClient = User::factory()->create(['name' => 'Other Company Contact']);
        $this->workspace->memberships()->create(['user_id' => $this->manager->id, 'role' => 'owner']);
        $this->workspace->memberships()->create(['user_id' => $this->member->id, 'role' => 'member']);

        $this->acme = $this->company($this->workspace, 'Acme Synthetic', 'acme-synthetic');
        $this->globex = $this->company($this->workspace, 'Globex Synthetic', 'globex-synthetic');
        $this->mine = $this->project($this->workspace, $this->acme, 'Acme Mine');
        $this->notMine = $this->project($this->workspace, $this->acme, 'Acme Not Mine');
        $this->globexProject = $this->project($this->workspace, $this->globex, 'Globex Only');
        $this->workspace->projectMemberships()->create([
            'client_project_id' => $this->mine->id, 'user_id' => $this->member->id, 'role' => ProjectRole::Contributor->value,
        ]);

        ClientCompanyMembership::query()->create(['workspace_id' => $this->workspace->id, 'client_company_id' => $this->acme->id, 'user_id' => $this->client->id, 'role' => 'client', 'access_scope' => 'company']);
        $narrow = ClientCompanyMembership::query()->create(['workspace_id' => $this->workspace->id, 'client_company_id' => $this->acme->id, 'user_id' => $this->narrowClient->id, 'role' => 'client', 'access_scope' => 'projects']);
        DB::table('client_portal_project_access')->insert([
            'workspace_id' => $this->workspace->id, 'client_company_membership_id' => $narrow->id,
            'client_project_id' => $this->mine->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        ClientCompanyMembership::query()->create(['workspace_id' => $this->workspace->id, 'client_company_id' => $this->globex->id, 'user_id' => $this->otherClient->id, 'role' => 'client', 'access_scope' => 'company']);

        $this->seedWorkspaceRows();
        $this->seedForeignRows();
    }

    private function seedWorkspaceRows(): void
    {
        $w = $this->workspace;

        // Expenses: mine, a project the member does not hold, none at all, another client's.
        $this->expense($w, $this->acme, $this->mine, 'Zeppelin expense on my project');
        $this->expense($w, $this->acme, $this->notMine, 'Zeppelin expense on their project');
        $this->expense($w, $this->acme, null, 'Zeppelin expense with no project');
        $this->expense($w, $this->globex, $this->globexProject, 'Zeppelin expense at globex');

        // Agreements.
        $this->agreement($w, $this->acme, $this->mine, 'Zeppelin agreement mine', 'active', true);
        $this->agreement($w, $this->acme, $this->notMine, 'Zeppelin agreement theirs', 'active', true);
        $this->agreement($w, $this->acme, null, 'Zeppelin agreement companywide', 'active', true);
        $this->agreement($w, $this->acme, $this->mine, 'Zeppelin agreement draft', 'draft', true);
        $this->agreement($w, $this->acme, $this->mine, 'Zeppelin agreement hidden', 'active', false);

        // Proposals.
        $this->proposal($w, $this->acme, $this->mine, 'Zeppelin proposal mine', 'sent', true);
        $this->proposal($w, $this->acme, $this->notMine, 'Zeppelin proposal theirs', 'sent', true);
        $this->proposal($w, $this->acme, null, 'Zeppelin proposal companywide draft', 'draft', false);
        $this->proposal($w, $this->acme, $this->mine, 'Zeppelin proposal unsent', 'draft', true);

        // Files (a project file shared with the client, one kept private, and a client-level file).
        $this->file($w, 'project', $this->mine->public_id, 'zeppelin-mine-shared.pdf', true);
        $this->file($w, 'project', $this->mine->public_id, 'zeppelin-mine-private.pdf', false);
        $this->file($w, 'project', $this->notMine->public_id, 'zeppelin-theirs-shared.pdf', true);
        $this->file($w, 'company', $this->acme->public_id, 'zeppelin-company-file.pdf', true);

        // Time: the member's own on their project, the owner's on the project the member does not hold,
        // and approved work with a client-safe description beside an internal note.
        $this->time($w, $this->acme, $this->mine, $this->member, 'Zeppelin member own entry', 'draft', null, false);
        $this->time($w, $this->acme, $this->notMine, $this->manager, 'Zeppelin manager entry theirs', 'approved', 'Zeppelin shared theirs', true);
        $this->time($w, $this->acme, $this->mine, $this->manager, 'Zeppelin internal note only', 'approved', 'Client safe wording mine', true);
        $this->time($w, $this->acme, $this->mine, $this->manager, 'Zeppelin hidden approved', 'approved', 'Zeppelin withheld wording', false);

        ClientContact::query()->create(['workspace_id' => $w->id, 'client_company_id' => $this->acme->id, 'first_name' => 'Zeppelin', 'last_name' => 'Contactperson', 'email' => 'contact@acme.example.test']);
        ClientContact::query()->create(['workspace_id' => $w->id, 'client_company_id' => $this->acme->id, 'first_name' => 'Zeppelin', 'last_name' => 'Archived', 'is_archived' => true]);
    }

    private function seedForeignRows(): void
    {
        $other = $this->elsewhere;
        $company = $this->company($other, 'Foreign Synthetic', 'foreign-synthetic');
        $project = $this->project($other, $company, 'Foreign Project');
        $this->expense($other, $company, $project, 'Zeppelin foreign expense');
        $this->agreement($other, $company, $project, 'Zeppelin foreign agreement', 'active', true);
        $this->proposal($other, $company, $project, 'Zeppelin foreign proposal', 'sent', true);
        $this->file($other, 'project', $project->public_id, 'zeppelin-foreign.pdf', true);
        $this->time($other, $company, $project, User::factory()->create(), 'Zeppelin foreign time', 'approved', 'Zeppelin foreign client wording', true);
        ClientContact::query()->create(['workspace_id' => $other->id, 'client_company_id' => $company->id, 'first_name' => 'Zeppelin', 'last_name' => 'Foreigner']);
    }

    /** @return array<string, list<string>> the titles each kind returned, by kind */
    private function titles(User $viewer, string $term = 'zeppelin'): array
    {
        $rows = $this->actingAs($viewer)->getJson('/search?q='.urlencode($term))->assertOk()->json('results');
        $byKind = [];
        foreach ($rows as $row) {
            $byKind[$row['kind']][] = $row['title'];
        }
        foreach ($byKind as &$titles) {
            sort($titles);
        }

        return $byKind;
    }

    public function test_an_owner_finds_every_kind_in_their_workspace_and_nothing_from_another(): void
    {
        $found = $this->titles($this->manager);

        $this->assertSame([
            'Zeppelin expense at globex', 'Zeppelin expense on my project', 'Zeppelin expense on their project', 'Zeppelin expense with no project',
        ], $found['expense']);
        $this->assertSame([
            'Zeppelin agreement companywide', 'Zeppelin agreement draft', 'Zeppelin agreement hidden', 'Zeppelin agreement mine', 'Zeppelin agreement theirs',
        ], $found['agreement']);
        $this->assertSame([
            'Zeppelin proposal companywide draft', 'Zeppelin proposal mine', 'Zeppelin proposal theirs', 'Zeppelin proposal unsent',
        ], $found['proposal']);
        $this->assertSame([
            'zeppelin-company-file.pdf', 'zeppelin-mine-private.pdf', 'zeppelin-mine-shared.pdf', 'zeppelin-theirs-shared.pdf',
        ], $found['file']);
        $this->assertSame([
            'Zeppelin hidden approved', 'Zeppelin internal note only', 'Zeppelin manager entry theirs', 'Zeppelin member own entry',
        ], $found['time']);
        $this->assertSame(['Zeppelin Contactperson'], $found['contact']);
        $this->assertSame(['Zeppelin Companywide', 'Zeppelin Narrowed'], $found['portal_user']);

        $body = json_encode($found, JSON_THROW_ON_ERROR);
        foreach (['foreign', 'Foreigner'] as $leak) {
            $this->assertStringNotContainsStringIgnoringCase($leak, $body, 'Another workspace contributed a row');
        }
    }

    public function test_a_scoped_member_finds_only_what_the_pages_would_show_them(): void
    {
        $found = $this->titles($this->member);

        $this->assertSame(['Zeppelin expense on my project'], $found['expense'], 'Their project only: not another project, not an unattributed expense, not another client');
        $this->assertSame(['Zeppelin agreement draft', 'Zeppelin agreement hidden', 'Zeppelin agreement mine'], $found['agreement'], 'A project agreement for a project they hold; company-wide terms are a manager\'s');
        $this->assertSame(['Zeppelin proposal companywide draft', 'Zeppelin proposal mine', 'Zeppelin proposal unsent'], $found['proposal'], 'Their project, and the client-wide ones of a client they reach');
        $this->assertSame(['zeppelin-mine-private.pdf', 'zeppelin-mine-shared.pdf'], $found['file'], 'Their project\'s files; never the client-level or another project\'s');
        $this->assertSame(['Zeppelin member own entry'], $found['time'], 'Their own time on their project; not the owner\'s entries');
        $this->assertArrayNotHasKey('contact', $found, 'Contacts are managers only');
        $this->assertArrayNotHasKey('portal_user', $found, 'Portal users are managers only');
    }

    public function test_a_member_scoped_to_nothing_finds_nothing(): void
    {
        $nobody = User::factory()->create();
        $this->workspace->memberships()->create(['user_id' => $nobody->id, 'role' => 'member']);

        $this->assertSame([], $this->titles($nobody));
    }

    public function test_a_client_contact_finds_only_what_the_portal_shows_them(): void
    {
        $found = $this->titles($this->client);

        // The portal serves no expense page and no contact list.
        $this->assertArrayNotHasKey('expense', $found);
        $this->assertArrayNotHasKey('contact', $found);
        $this->assertArrayNotHasKey('portal_user', $found);
        $this->assertSame(['Zeppelin agreement companywide', 'Zeppelin agreement mine', 'Zeppelin agreement theirs'], $found['agreement'], 'Visible and not a draft: neither the draft nor the withheld one');
        $this->assertSame(['Zeppelin proposal mine', 'Zeppelin proposal theirs'], $found['proposal'], 'Sent and visible only');
    }

    public function test_a_client_contact_sees_shared_files_only(): void
    {
        $found = $this->titles($this->client);

        $this->assertSame(['zeppelin-mine-shared.pdf', 'zeppelin-theirs-shared.pdf'], $found['file'], 'Shared with the client; the private file and the company-level file stay with the team');
    }

    public function test_a_clients_time_matches_the_wording_they_may_read_and_never_the_internal_note(): void
    {
        $found = $this->titles($this->client);

        $this->assertSame(['Zeppelin shared theirs'], $found['time'], 'The client-safe description is matched and shown');

        $internal = $this->titles($this->client, 'internal note only');
        $this->assertArrayNotHasKey('time', $internal, 'A search must not confirm text the client may not read');

        $wording = $this->titles($this->client, 'Client safe wording');
        $this->assertSame(['Client safe wording mine'], $wording['time']);
    }

    public function test_a_narrowed_client_contact_loses_the_records_of_projects_they_were_not_given(): void
    {
        $found = $this->titles($this->narrowClient, 'zeppelin');

        $this->assertSame(['Zeppelin agreement companywide', 'Zeppelin agreement mine'], $found['agreement']);
        $this->assertSame(['Zeppelin proposal mine'], $found['proposal']);
        $this->assertSame(['zeppelin-mine-shared.pdf'], $found['file']);
        $this->assertArrayNotHasKey('time', $found, 'Their one project has no entry that matches here');
        $this->assertSame(['Client safe wording mine'], $this->titles($this->narrowClient, 'Client safe wording')['time']);
    }

    public function test_a_client_of_another_company_finds_nothing_of_this_companys(): void
    {
        $found = $this->titles($this->otherClient);

        $this->assertArrayNotHasKey('agreement', $found);
        $this->assertArrayNotHasKey('proposal', $found);
        $this->assertArrayNotHasKey('file', $found);
        $this->assertArrayNotHasKey('time', $found);
        $this->assertArrayNotHasKey('expense', $found);
    }

    public function test_a_revoked_workspace_membership_stops_every_kind(): void
    {
        $this->assertNotSame([], $this->titles($this->member), 'Control: they could search before');

        $this->workspace->memberships()->where('user_id', $this->member->id)->delete();

        $this->assertSame([], $this->titles($this->member));
    }

    public function test_a_revoked_portal_membership_stops_every_kind(): void
    {
        $this->assertNotSame([], $this->titles($this->client), 'Control: they could search before');

        ClientCompanyMembership::query()->where('user_id', $this->client->id)->delete();

        $this->assertSame([], $this->titles($this->client));
    }

    public function test_a_stranger_from_another_workspace_finds_only_their_own(): void
    {
        $stranger = User::factory()->create();
        $this->elsewhere->memberships()->create(['user_id' => $stranger->id, 'role' => 'owner']);

        $found = $this->titles($stranger);

        $this->assertSame(['Zeppelin foreign expense'], $found['expense'], 'Control');
        $this->assertStringNotContainsString('Acme', json_encode($found, JSON_THROW_ON_ERROR));
        $this->assertSame(['Zeppelin foreign agreement'], $found['agreement']);
        $this->assertSame(['Zeppelin foreign proposal'], $found['proposal']);
        $this->assertSame(['zeppelin-foreign.pdf'], $found['file']);
    }

    public function test_the_rows_link_to_the_pages_that_exist(): void
    {
        $rows = collect($this->actingAs($this->manager)->getJson('/search?q=zeppelin')->assertOk()->json('results'))->groupBy('kind');
        $base = "/workspaces/{$this->workspace->public_id}/clients/{$this->acme->public_id}";

        $expense = $rows['expense']->firstWhere('title', 'Zeppelin expense on my project');
        $this->assertSame("{$base}/expenses", $expense['href']);
        $agreement = $rows['agreement']->firstWhere('title', 'Zeppelin agreement mine');
        $this->assertSame("{$base}/agreements/{$agreement['id']}", $agreement['href']);
        $proposal = $rows['proposal']->firstWhere('title', 'Zeppelin proposal mine');
        $this->assertSame("{$base}/proposals/{$proposal['id']}", $proposal['href']);
        $this->assertSame("{$base}/time", $rows['time']->first()['href']);
        $this->assertSame("{$base}/settings", $rows['contact']->first()['href']);
        $file = $rows['file']->firstWhere('title', 'zeppelin-mine-shared.pdf');
        $this->assertSame("/workspaces/{$this->workspace->public_id}/attachment-records/project/{$this->mine->public_id}/files", $file['href']);

        // And the portal's own: a client never receives an operator path.
        $portal = collect($this->actingAs($this->client)->getJson('/search?q=zeppelin')->assertOk()->json('results'))->groupBy('kind');
        $this->assertStringStartsWith("/portal/{$this->acme->public_id}/agreements/", $portal['agreement']->first()['href']);
        $this->assertStringStartsWith("/portal/{$this->acme->public_id}/proposals/", $portal['proposal']->first()['href']);
        $this->assertSame("/portal/{$this->acme->public_id}/time", $portal['time']->first()['href']);
        $this->assertSame("/portal/{$this->acme->public_id}/attachments/project/{$this->mine->public_id}/files", $portal['file']->firstWhere('title', 'zeppelin-mine-shared.pdf')['href']);
        foreach ($portal->flatten(1) as $row) {
            $this->assertStringNotContainsString('/workspaces/', $row['href']);
        }
    }

    public function test_every_link_resolves_for_the_person_who_was_shown_it(): void
    {
        foreach ([$this->manager, $this->member, $this->client, $this->narrowClient] as $viewer) {
            foreach ($this->actingAs($viewer)->getJson('/search?q=zeppelin')->assertOk()->json('results') as $row) {
                $this->actingAs($viewer)->get($row['href'])->assertStatus(200, "{$row['kind']} {$row['title']} for {$viewer->name} linked to {$row['href']}");
            }
        }
    }

    public function test_wildcards_in_the_term_match_literally_for_the_new_kinds(): void
    {
        $this->expense($this->workspace, $this->acme, $this->mine, 'Wild 100% expense');
        $this->expense($this->workspace, $this->acme, $this->mine, 'Wild 100 expense');
        $this->file($this->workspace, 'project', $this->mine->public_id, 'wild_name.pdf', true);
        $this->file($this->workspace, 'project', $this->mine->public_id, 'wildXname.pdf', true);

        $percent = $this->titles($this->manager, '100%');
        $this->assertSame(['Wild 100% expense'], $percent['expense']);

        $underscore = $this->titles($this->manager, 'wild_name');
        $this->assertSame(['wild_name.pdf'], $underscore['file']);

        $bang = $this->titles($this->manager, '!');
        $this->assertArrayNotHasKey('file', $bang);
    }

    public function test_a_contact_is_found_by_first_and_last_name_together(): void
    {
        $this->assertSame(['Zeppelin Contactperson'], $this->titles($this->manager, 'zeppelin contact')['contact']);
        $this->assertSame(['Zeppelin Contactperson'], $this->titles($this->manager, 'contact@acme.example.test')['contact']);
        $this->assertArrayNotHasKey('contact', $this->titles($this->manager, 'zeppelin archived'), 'An archived contact is gone');
    }

    public function test_time_entries_come_back_most_recent_first_and_bounded(): void
    {
        foreach (range(1, 9) as $day) {
            $this->time($this->workspace, $this->acme, $this->mine, $this->manager, 'Zeppelin ordered '.$day, 'approved', null, false, sprintf('2026-03-%02d', $day));
        }

        $rows = collect($this->actingAs($this->manager)->getJson('/search?q='.urlencode('zeppelin ordered'))->assertOk()->json('results'))->where('kind', 'time')->values();

        $this->assertCount(5, $rows, 'Bounded per kind');
        $this->assertSame(['Zeppelin ordered 9', 'Zeppelin ordered 8', 'Zeppelin ordered 7', 'Zeppelin ordered 6', 'Zeppelin ordered 5'], $rows->pluck('title')->all());
    }

    public function test_the_number_of_queries_does_not_grow_with_the_data(): void
    {
        // One file under every kind of parent a search can reach, so the
        // baseline already pays for each parent lookup (a fixed handful, one
        // per parent type present - never one per row).
        $task = ClientTask::query()->create(['workspace_id' => $this->workspace->id, 'client_project_id' => $this->mine->id, 'title' => 'Baseline task', 'status' => 'open']);
        $this->file($this->workspace, 'task', $task->public_id, 'zeppelin-baseline-task.pdf', true);
        // And one match among the original kinds, whose parent-client lookup is
        // skipped when nothing matched.
        $baselineProject = $this->project($this->workspace, $this->acme, 'Zeppelin baseline project');
        $this->workspace->projectMemberships()->create(['client_project_id' => $baselineProject->id, 'user_id' => $this->member->id, 'role' => ProjectRole::Contributor->value]);
        $baselineExpense = $this->expense($this->workspace, $this->acme, $this->mine, 'Zeppelin baseline expense');
        $this->file($this->workspace, 'expense', $baselineExpense->public_id, 'zeppelin-baseline-receipt.pdf', false);

        $small = $this->queriesFor($this->manager);
        $smallMember = $this->queriesFor($this->member);
        $smallClient = $this->queriesFor($this->client);

        foreach (range(1, 12) as $i) {
            $project = $this->project($this->workspace, $this->acme, 'Zeppelin extra project '.$i);
            $this->workspace->projectMemberships()->create(['client_project_id' => $project->id, 'user_id' => $this->member->id, 'role' => ProjectRole::Contributor->value]);
            $this->expense($this->workspace, $this->acme, $project, 'Zeppelin extra expense '.$i);
            $this->agreement($this->workspace, $this->acme, $project, 'Zeppelin extra agreement '.$i, 'active', true);
            $this->proposal($this->workspace, $this->acme, $project, 'Zeppelin extra proposal '.$i, 'sent', true);
            foreach (['project' => $project->public_id, 'company' => $this->acme->public_id, 'task' => $task->public_id] as $type => $id) {
                $this->file($this->workspace, $type, $id, "zeppelin-extra-{$type}-{$i}.pdf", true);
            }
            $this->time($this->workspace, $this->acme, $project, $this->manager, 'Zeppelin extra time '.$i, 'approved', 'Zeppelin extra wording '.$i, true);
            ClientContact::query()->create(['workspace_id' => $this->workspace->id, 'client_company_id' => $this->acme->id, 'first_name' => 'Zeppelin', 'last_name' => 'Extra'.$i]);
        }

        $this->assertSame($small, $this->queriesFor($this->manager), 'Owner: more rows, same number of queries');
        $this->assertSame($smallMember, $this->queriesFor($this->member), 'Scoped member');
        $this->assertSame($smallClient, $this->queriesFor($this->client), 'Client contact');
    }

    private function queriesFor(User $viewer): int
    {
        $this->actingAs($viewer);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson('/search?q=zeppelin')->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    private function company(Workspace $workspace, string $name, string $slug): ClientCompany
    {
        return ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => $name, 'slug' => $slug]);
    }

    private function project(Workspace $workspace, ClientCompany $company, string $name): ClientProject
    {
        return ClientProject::query()->create([
            'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => $name, 'status' => 'active', 'is_visible_to_client' => true,
        ]);
    }

    private function expense(Workspace $workspace, ClientCompany $company, ?ClientProject $project, string $description): ClientExpense
    {
        return ClientExpense::query()->create([
            'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'client_project_id' => $project?->id,
            'spent_on' => '2026-03-01', 'amount' => 1200, 'currency' => 'USD', 'description' => $description, 'status' => 'draft',
        ]);
    }

    private function agreement(Workspace $workspace, ClientCompany $company, ?ClientProject $project, string $title, string $status, bool $visible): ClientAgreement
    {
        return ClientAgreement::query()->create([
            'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'client_project_id' => $project?->id, 'title' => $title,
            'status' => $status, 'currency' => 'USD', 'starts_on' => '2026-01-01', 'billing_cadence' => 'monthly', 'is_visible_to_client' => $visible,
        ]);
    }

    private function proposal(Workspace $workspace, ClientCompany $company, ?ClientProject $project, string $title, string $status, bool $visible): ClientProposal
    {
        return ClientProposal::query()->create([
            'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'client_project_id' => $project?->id, 'title' => $title,
            'status' => $status, 'currency' => 'USD', 'is_visible_to_client' => $visible,
        ]);
    }

    private function file(Workspace $workspace, string $type, string $recordPublicId, string $filename, bool $shared): ClientAttachment
    {
        $file = ClientAttachment::query()->create([
            'workspace_id' => $workspace->id, 'record_type' => $type, 'record_public_id' => $recordPublicId,
            'object_key' => 'synthetic/'.$filename, 'original_filename' => $filename, 'media_type' => 'application/pdf', 'bytes' => 1,
            'sha256' => str_repeat('a', 64), 'uploader_id' => $this->manager->id, 'lifecycle_state' => ClientAttachment::STATE_AVAILABLE,
        ]);
        DB::table('client_attachments')->where('id', $file->id)->update(['is_visible_to_client' => $shared]);

        return $file;
    }

    private function time(Workspace $workspace, ClientCompany $company, ClientProject $project, User $worker, string $description, string $status, ?string $clientDescription, bool $visible, string $day = '2026-03-02'): ClientTimeEntry
    {
        return ClientTimeEntry::query()->create([
            'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'client_project_id' => $project->id, 'user_id' => $worker->id,
            'worked_on' => $day, 'minutes' => 60, 'description' => $description, 'client_visible_description' => $clientDescription,
            'is_visible_to_client' => $visible, 'status' => $status, 'is_billable' => true, 'currency' => 'USD',
        ]);
    }
}
