<?php

namespace Tests\Feature;

use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientProject;
use App\Models\ClientTask;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Search\WorkspaceSearch;
use App\Support\AgentApi\ProjectRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * What the command palette can find.
 *
 * The palette is reachable from every screen and answers a free-text query, so
 * it is the widest read surface in the application: one workspace-blind clause
 * here publishes every tenant's client names to everyone, with no page to
 * review the leak on. That is what most of this file asserts.
 *
 * The scoping rule is the one from #157 - reachability runs through projects -
 * and the point of testing it again here rather than trusting
 * `ProjectAccess` is that this surface reaches four tables, and each `where`
 * is its own chance to forget.
 *
 * Fixtures are synthetic: reserved-looking names, no real client data.
 */
class SearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_finds_clients_projects_invoices_and_tasks_by_name(): void
    {
        $manager = User::factory()->create();
        $workspace = $this->workspace('Synthetic Search', 'synthetic-search', $manager, 'admin');
        $company = $this->company($workspace, 'Quixotic Synthetic Client', 'quixotic-client');
        $project = $this->project($workspace, $company, 'Quixotic Synthetic Project');
        $this->task($workspace, $project, 'Quixotic Synthetic Task');
        $this->invoice($workspace, $company, 'QUIXOTIC-202601-001');

        $response = $this->actingAs($manager)->getJson('/search?q=quixotic')->assertOk();

        $kinds = array_column($response->json('results'), 'kind');

        $this->assertContains('client', $kinds);
        $this->assertContains('project', $kinds);
        $this->assertContains('invoice', $kinds);
        $this->assertContains('task', $kinds);
    }

    /**
     * Each row has to lead to the screen that actually exists.
     *
     * Asserted whole rather than by prefix: a task has no page of its own and
     * resolves to its client's Tasks tab, and the way that goes wrong is a
     * plausible-looking path nobody has a route for.
     */
    public function test_each_result_links_to_the_screen_that_exists(): void
    {
        $manager = User::factory()->create();
        $workspace = $this->workspace('Synthetic Links', 'synthetic-links', $manager, 'admin');
        $company = $this->company($workspace, 'Zephyr Synthetic Client', 'zephyr-client');
        $project = $this->project($workspace, $company, 'Zephyr Synthetic Project');
        $this->task($workspace, $project, 'Zephyr Synthetic Task');
        $invoice = $this->invoice($workspace, $company, 'ZEPHYR-202601-001');

        $base = "/workspaces/{$workspace->public_id}/clients/{$company->public_id}";
        $results = collect($this->actingAs($manager)->getJson('/search?q=zephyr')->assertOk()->json('results'))
            ->keyBy('kind');

        $this->assertSame($base, $results['client']['href']);
        $this->assertSame("{$base}/projects/{$project->public_id}", $results['project']['href']);
        $this->assertSame("{$base}/invoices/{$invoice->public_id}", $results['invoice']['href']);
        // Filtered to the project the task belongs to, so the reader lands on
        // the row they searched for rather than on the client's whole list.
        $this->assertSame(
            "{$base}/tasks?project={$project->public_id}",
            $results['task']['href'],
        );
    }

    /**
     * The reason this surface exists at all is speed, and the reason it is
     * dangerous is that it is a free-text read over four tables. A workspace
     * this person is not in must contribute nothing - not a client name, not a
     * project name, not an invoice number.
     *
     * The control row matters: an endpoint that returned nothing at all would
     * omit the foreign names too and pass while proving nothing.
     */
    public function test_no_other_tenants_row_reaches_the_results(): void
    {
        $manager = User::factory()->create();
        $mine = $this->workspace('Synthetic Mine Search', 'synthetic-mine-search', $manager, 'admin');
        $mineCompany = $this->company($mine, 'Shared Word Synthetic Mine', 'shared-mine');
        $this->project($mine, $mineCompany, 'Shared Word Synthetic Project');

        $foreign = Workspace::query()->create(['name' => 'Foreign Search Tenant', 'slug' => 'foreign-search']);
        $foreignCompany = $this->company($foreign, 'Shared Word Foreign Client', 'shared-foreign');
        $foreignProject = $this->project($foreign, $foreignCompany, 'Shared Word Foreign Project');
        $this->task($foreign, $foreignProject, 'Shared Word Foreign Task');
        $this->invoice($foreign, $foreignCompany, 'SHARED-WORD-FOREIGN-001');

        $body = $this->actingAs($manager)->getJson('/search?q=shared word')->assertOk()->getContent();

        $this->assertStringContainsString('Shared Word Synthetic Mine', (string) $body, 'The control row, so an empty response cannot pass');
        $this->assertStringNotContainsString('Shared Word Foreign Client', (string) $body);
        $this->assertStringNotContainsString('Shared Word Foreign Project', (string) $body);
        $this->assertStringNotContainsString('Shared Word Foreign Task', (string) $body);
        $this->assertStringNotContainsString('SHARED-WORD-FOREIGN-001', (string) $body);
    }

    /**
     * Membership of a workspace is not managership of it.
     *
     * A plain member reaches the projects they were added to, so the palette
     * has to scope down exactly as the directory does. Reading "is a member"
     * as "sees everything" is the defect #157 fixed on two other surfaces, and
     * this one has four queries to forget it in.
     */
    public function test_a_scoped_member_finds_only_what_they_were_added_to(): void
    {
        $member = User::factory()->create();
        $workspace = $this->workspace('Synthetic Scoped', 'synthetic-scoped', $member, 'member');

        $reachable = $this->company($workspace, 'Included Synthetic Client', 'included-client');
        $reachableProject = $this->project($workspace, $reachable, 'Included Synthetic Project');
        $workspace->projectMemberships()->create([
            'client_project_id' => $reachableProject->id,
            'user_id' => $member->id,
            'role' => ProjectRole::Contributor->value,
        ]);

        $hidden = $this->company($workspace, 'Excluded Synthetic Client', 'excluded-client');
        $hiddenProject = $this->project($workspace, $hidden, 'Excluded Synthetic Project');
        $this->task($workspace, $hiddenProject, 'Excluded Synthetic Task');
        $this->invoice($workspace, $hidden, 'EXCLUDED-202601-001');

        $body = (string) $this->actingAs($member)->getJson('/search?q=synthetic')->assertOk()->getContent();

        $this->assertStringContainsString('Included Synthetic Client', $body, 'The control row');
        $this->assertStringNotContainsString('Excluded Synthetic Client', $body);
        $this->assertStringNotContainsString('Excluded Synthetic Project', $body);
        $this->assertStringNotContainsString('Excluded Synthetic Task', $body);
        $this->assertStringNotContainsString('EXCLUDED-202601-001', $body);
    }

    /**
     * Reaching a client is not reaching all of its invoices.
     *
     * `BillingRecordAccess::canViewInvoice()` is narrower than company
     * reachability: an invoice needs lineage inside what the viewer holds, and
     * one with no project lineage at all is refused outright. Scoping the
     * search on company reachability alone therefore surfaced invoice numbers
     * for records the reader is refused on the screen the row links to - which
     * discloses that the record exists and what it is called.
     */
    public function test_a_scoped_member_does_not_find_invoices_they_cannot_open(): void
    {
        $member = User::factory()->create();
        $workspace = $this->workspace('Synthetic Billing', 'synthetic-billing', $member, 'member');
        $company = $this->company($workspace, 'Ledger Synthetic Client', 'ledger-client');

        $mine = $this->project($workspace, $company, 'Ledger Synthetic Mine');
        $workspace->projectMemberships()->create([
            'client_project_id' => $mine->id,
            'user_id' => $member->id,
            'role' => ProjectRole::Contributor->value,
        ]);
        $other = $this->project($workspace, $company, 'Ledger Synthetic Other');

        // Same client, so company reachability lets all three through; only the
        // invoice rule tells them apart.
        $reachable = $this->invoice($workspace, $company, 'LEDGER-MINE-001');
        $this->line($workspace, $reachable, $mine);
        $foreign = $this->invoice($workspace, $company, 'LEDGER-OTHER-001');
        $this->line($workspace, $foreign, $other);
        $this->invoice($workspace, $company, 'LEDGER-NOLINEAGE-001');

        $body = (string) $this->actingAs($member)->getJson('/search?q=LEDGER-')->assertOk()->getContent();

        $this->assertStringContainsString('LEDGER-MINE-001', $body, 'The control row');
        $this->assertStringNotContainsString('LEDGER-OTHER-001', $body, 'Attributed to a project this member does not hold');
        $this->assertStringNotContainsString('LEDGER-NOLINEAGE-001', $body, 'No lineage at all is refused, not permitted');
    }

    /**
     * Cross-tenant task lineage is not asserted here, and that is deliberate.
     *
     * The defect Codex found was real: a clause of the form "in a workspace I
     * manage OR naming a project I belong to" never constrains the row's own
     * `workspace_id`, so another tenant's task naming this viewer's project
     * would have matched. The fix was structural - every query in
     * {@see WorkspaceSearch} is now scoped to one
     * workspace, which makes the case unrepresentable rather than guarded.
     *
     * It has no test because the row cannot be built: #113's composite foreign
     * key refuses it, and `RefreshDatabase` runs inside a transaction where
     * SQLite ignores the pragma that would disable the check. A test that
     * cannot construct its own fixture is not a guard, and writing one that
     * quietly asserts something easier is how a suite comes to look complete
     * while proving less than it claims.
     */

    /**
     * A portal user has client company memberships and no workspace
     * membership. They reach the palette's endpoint like any signed-in person
     * and must get nothing from it - the palette is operator navigation.
     */
    public function test_a_viewer_with_no_workspace_membership_gets_nothing(): void
    {
        $outsider = User::factory()->create();
        $workspace = Workspace::query()->create(['name' => 'Unrelated Tenant', 'slug' => 'unrelated-tenant']);
        $this->company($workspace, 'Unreachable Synthetic Client', 'unreachable-client');

        $this->actingAs($outsider)
            ->getJson('/search?q=synthetic')
            ->assertOk()
            ->assertExactJson(['results' => []]);
    }

    /**
     * `%` and `_` are LIKE wildcards. Unescaped, a search for a single `%`
     * returns the whole table - which is both the slowest query the endpoint
     * can run and a way to enumerate a workspace without knowing any names.
     */
    public function test_like_wildcards_in_the_term_are_matched_literally(): void
    {
        $manager = User::factory()->create();
        $workspace = $this->workspace('Synthetic Wildcards', 'synthetic-wildcards', $manager, 'admin');
        $this->company($workspace, 'Ordinary Synthetic Client', 'ordinary-client');
        $this->company($workspace, 'Fifty % Synthetic Client', 'fifty-percent-client');

        $percent = (string) $this->actingAs($manager)
            ->getJson('/search?q='.urlencode('%'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Fifty % Synthetic Client', $percent, 'The literal match');
        $this->assertStringNotContainsString('Ordinary Synthetic Client', $percent, 'A bare % must not mean "everything"');
    }

    /**
     * A blank search is the palette at rest, not a request for the workspace.
     *
     * Refused rather than answered emptily, and refused before any query runs.
     * `required` sees the trimmed value, so whitespace is blank here too - the
     * distinction matters because "   " reaching a LIKE would match every row
     * with a space in it, which on this surface is most of them.
     */
    public function test_a_blank_term_is_refused_rather_than_run(): void
    {
        $manager = User::factory()->create();
        $workspace = $this->workspace('Synthetic Blank', 'synthetic-blank', $manager, 'admin');
        $this->company($workspace, 'Synthetic Blank Client', 'blank-client');

        $this->actingAs($manager)
            ->getJson('/search?q=%20%20')
            ->assertStatus(422);

        $this->actingAs($manager)
            ->getJson('/search')
            ->assertStatus(422);
    }

    /**
     * `!` is the LIKE escape character this query declares, so a caller typing
     * one must still get a literal match rather than an escape that swallows
     * the character after it.
     */
    public function test_the_escape_character_itself_is_matched_literally(): void
    {
        $manager = User::factory()->create();
        $workspace = $this->workspace('Synthetic Bang', 'synthetic-bang', $manager, 'admin');
        $this->company($workspace, 'Bang! Synthetic Client', 'bang-client');
        $this->company($workspace, 'Bangless Synthetic Client', 'bangless-client');

        $body = (string) $this->actingAs($manager)
            ->getJson('/search?q='.urlencode('Bang!'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Bang! Synthetic Client', $body);
        $this->assertStringNotContainsString('Bangless Synthetic Client', $body);
    }

    /** The endpoint is behind `auth`; an anonymous caller is redirected to sign in. */
    public function test_it_is_closed_to_anonymous_callers(): void
    {
        $this->getJson('/search?q=synthetic')->assertUnauthorized();
    }

    private function workspace(string $name, string $slug, User $member, string $role): Workspace
    {
        $workspace = Workspace::query()->create(['name' => $name, 'slug' => $slug]);
        $workspace->memberships()->create(['user_id' => $member->id, 'role' => $role]);

        return $workspace;
    }

    private function company(Workspace $workspace, string $name, string $slug): ClientCompany
    {
        return ClientCompany::query()->create([
            'workspace_id' => $workspace->id,
            'name' => $name,
            'slug' => $slug,
        ]);
    }

    private function project(Workspace $workspace, ClientCompany $company, string $name): ClientProject
    {
        return ClientProject::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'name' => $name,
            'status' => 'active',
        ]);
    }

    private function task(Workspace $workspace, ClientProject $project, string $title): ClientTask
    {
        return ClientTask::query()->create([
            'workspace_id' => $workspace->id,
            'client_project_id' => $project->id,
            'title' => $title,
            'status' => 'open',
        ]);
    }

    private function line(Workspace $workspace, ClientInvoice $invoice, ClientProject $project): void
    {
        DB::table('client_invoice_lines')->insert([
            'public_id' => (string) Str::uuid(),
            'workspace_id' => $workspace->id,
            'client_invoice_id' => $invoice->id,
            'client_project_id' => $project->id,
            'type' => 'retainer',
            'description' => 'Synthetic line',
            'quantity' => 1,
            'unit_amount' => 0,
            'total_amount' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function invoice(Workspace $workspace, ClientCompany $company, string $number): ClientInvoice
    {
        return ClientInvoice::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'invoice_number' => $number,
            'status' => 'draft',
            'currency' => 'USD',
            'issue_date' => '2026-01-01',
        ]);
    }
}
