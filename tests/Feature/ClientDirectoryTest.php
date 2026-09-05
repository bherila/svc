<?php

namespace Tests\Feature;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientProject;
use App\Models\ClientProjectMembership;
use App\Models\ClientTask;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Support\ClientHome\ClientHomeViewModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\AssertsSurfaceIsolation;
use Tests\Concerns\WritesLegacyCrossTenantRows;
use Tests\TestCase;

/**
 * The client directory's two read-only screens.
 *
 * Every fixture here is synthetic: reserved-looking names, obviously fake
 * invoice numbers, and no real client, rate or contact data.
 */
class ClientDirectoryTest extends TestCase
{
    use AssertsSurfaceIsolation;
    use RefreshDatabase;
    use WritesLegacyCrossTenantRows;

    public function test_the_list_reports_projects_invoices_and_this_periods_retainer(): void
    {
        $manager = User::factory()->create();
        $workspace = $this->workspace('Synthetic Directory', 'synthetic-directory', $manager);
        $company = $this->company($workspace, 'Synthetic Client', 'synthetic-client');
        $project = $this->project($workspace, $company, 'Synthetic Project');
        $this->project($workspace, $company, 'Second Synthetic Project');

        $this->agreement($workspace, $company, [
            'title' => 'Synthetic Retainer',
            'status' => 'active',
            'billing_cadence' => 'monthly',
            'starts_on' => '2026-01-01',
            'retainer_minutes' => 600,
            'retainer_amount' => 500000,
        ]);

        $this->invoice($workspace, $company, 'SYN-DIR-DRAFT', 'draft');
        $this->invoice($workspace, $company, 'SYN-DIR-OPEN', 'issued');
        $this->invoice($workspace, $company, 'SYN-DIR-PARTIAL', 'partially_paid');
        $this->invoice($workspace, $company, 'SYN-DIR-PAID', 'paid');
        $this->invoice($workspace, $company, 'SYN-DIR-VOID', 'void');

        // Two hours approved inside the current cycle, and an hour outside it.
        $this->timeEntry($workspace, $company, $project, [
            'worked_on' => now()->startOfMonth()->toDateString(),
            'minutes' => 120,
            'status' => 'approved',
        ]);
        $this->timeEntry($workspace, $company, $project, [
            'worked_on' => now()->startOfMonth()->subMonth()->toDateString(),
            'minutes' => 60,
            'status' => 'approved',
        ]);
        // Draft work is not a draw on anything yet, and the ledger does not
        // count it either.
        $this->timeEntry($workspace, $company, $project, [
            'worked_on' => now()->startOfMonth()->toDateString(),
            'minutes' => 300,
            'status' => 'draft',
        ]);

        $this->actingAs($manager)
            ->get("/workspaces/{$workspace->public_id}/clients")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('clients/index')
                ->where('workspace.id', $workspace->public_id)
                ->has('companies', 1)
                ->where('companies.0.id', $company->public_id)
                ->where('companies.0.project_count', 2)
                ->where('companies.0.draft_invoice_count', 1)
                ->where('companies.0.open_invoice_count', 2)
                ->where('companies.0.retainer.agreement', 'Synthetic Retainer')
                ->where('companies.0.retainer.capacity_minutes', 600)
                ->where('companies.0.retainer.used_minutes', 120)
                ->where('companies.0.retainer.remaining_minutes', 480)
                ->where('companies.0.retainer.over_minutes', 0));
    }

    public function test_retainer_usage_counts_only_work_on_this_companys_own_projects(): void
    {
        // The chain, not the key: a time entry names its workspace, its company
        // and its project through three independent columns, and the schema does
        // not require them to agree. An entry filed under this company while
        // pointing at another company's project would otherwise add its hours
        // to a total published beside this company's name.
        $manager = User::factory()->create();
        $workspace = $this->workspace('Synthetic Chain', 'synthetic-chain', $manager);
        $company = $this->company($workspace, 'Aa Synthetic Client', 'aa-synthetic-client');
        $ownProject = $this->project($workspace, $company, 'Own Synthetic Project');
        $sibling = $this->company($workspace, 'Bb Sibling Client', 'bb-sibling-client');
        $siblingProject = $this->project($workspace, $sibling, 'Sibling Synthetic Project');

        $this->agreement($workspace, $company, [
            'title' => 'Synthetic Chain Retainer',
            'status' => 'active',
            'billing_cadence' => 'monthly',
            'starts_on' => '2026-01-01',
            'retainer_minutes' => 600,
        ]);

        $worked = now()->startOfMonth()->toDateString();
        $this->timeEntry($workspace, $company, $ownProject, [
            'worked_on' => $worked,
            'minutes' => 120,
            'status' => 'approved',
        ]);
        // This company, this workspace - but another company's project.
        $this->timeEntry($workspace, $company, $siblingProject, [
            'worked_on' => $worked,
            'minutes' => 300,
            'status' => 'approved',
        ]);
        // This company, its own project - but another workspace's row.
        //
        // Refused by the composite tenant keys since #113, so it is seeded with
        // enforcement suspended: the subject here is the *query's* project
        // scoping, and a database migrated from before those keys can still
        // hold rows shaped like this.
        $foreign = Workspace::query()->create(['name' => 'Foreign Chain', 'slug' => 'foreign-chain']);
        $this->writingLegacyCrossTenantRows(fn () => $this->timeEntry($foreign, $company, $ownProject, [
            'worked_on' => $worked,
            'minutes' => 480,
            'status' => 'approved',
        ]));
        // And the chain broken one link further out: a project owned by another
        // workspace that names this company, with a row of this workspace's own
        // pointing at it. The entry passes every filter keyed on the workspace
        // and the company; only a project set built inside this workspace
        // excludes it.
        // Both writes are refused now: the project because it names this
        // company from another workspace, and the entry because it points at
        // that project. They are seeded together, since either alone leaves the
        // broken chain this test exists to describe half-built.
        $this->writingLegacyCrossTenantRows(function () use ($foreign, $workspace, $company, $worked) {
            $foreignProject = $this->project($foreign, $company, 'Foreign Chain Project');
            $this->timeEntry($workspace, $company, $foreignProject, [
                'worked_on' => $worked,
                'minutes' => 240,
                'status' => 'approved',
            ]);
        });

        $this->actingAs($manager)
            ->get("/workspaces/{$workspace->public_id}/clients")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('companies.0.name', 'Aa Synthetic Client')
                ->where('companies.0.retainer.used_minutes', 120)
                ->where('companies.0.retainer.remaining_minutes', 480));
    }

    public function test_a_project_scoped_retainer_counts_only_that_projects_work(): void
    {
        $manager = User::factory()->create();
        $workspace = $this->workspace('Synthetic Scoped', 'synthetic-scoped', $manager);
        $company = $this->company($workspace, 'Synthetic Client', 'synthetic-client');
        $covered = $this->project($workspace, $company, 'Covered Synthetic Project');
        $other = $this->project($workspace, $company, 'Other Synthetic Project');

        $this->agreement($workspace, $company, [
            'title' => 'Synthetic Project Retainer',
            'status' => 'active',
            'billing_cadence' => 'monthly',
            'starts_on' => '2026-01-01',
            'client_project_id' => $covered->id,
            'retainer_minutes' => 600,
        ]);

        $worked = now()->startOfMonth()->toDateString();
        $this->timeEntry($workspace, $company, $covered, [
            'worked_on' => $worked,
            'minutes' => 60,
            'status' => 'approved',
        ]);
        // The company's other project pays for its own hours; a retainer sold
        // for one project is never drawn on by work logged against another.
        $this->timeEntry($workspace, $company, $other, [
            'worked_on' => $worked,
            'minutes' => 90,
            'status' => 'approved',
        ]);

        $this->actingAs($manager)
            ->get("/workspaces/{$workspace->public_id}/clients")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('companies.0.retainer.used_minutes', 60)
                ->where('companies.0.retainer.remaining_minutes', 540));
    }

    public function test_a_retainer_scoped_to_a_project_of_another_company_draws_on_nothing(): void
    {
        // `client_project_id` on an agreement is an independent key too. Taking
        // it at face value would scope the company's retainer to a project the
        // company does not own, and then count that project's hours against it.
        $manager = User::factory()->create();
        $workspace = $this->workspace('Synthetic Cross Scope', 'synthetic-cross-scope', $manager);
        $company = $this->company($workspace, 'Aa Scoped Client', 'aa-scoped-client');
        $other = $this->company($workspace, 'Bb Other Client', 'bb-other-client');
        $otherProject = $this->project($workspace, $other, 'Other Company Project');
        $this->project($workspace, $company, 'Own Synthetic Project');

        $this->agreement($workspace, $company, [
            'title' => 'Synthetic Cross Scoped Retainer',
            'status' => 'active',
            'billing_cadence' => 'monthly',
            'starts_on' => '2026-01-01',
            'client_project_id' => $otherProject->id,
            'retainer_minutes' => 600,
        ]);

        $this->timeEntry($workspace, $company, $otherProject, [
            'worked_on' => now()->startOfMonth()->toDateString(),
            'minutes' => 300,
            'status' => 'approved',
        ]);

        $this->actingAs($manager)
            ->get("/workspaces/{$workspace->public_id}/clients")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('companies.0.name', 'Aa Scoped Client')
                ->where('companies.0.retainer.used_minutes', 0)
                ->where('companies.0.retainer.remaining_minutes', 600));
    }

    public function test_the_list_reports_no_retainer_without_an_agreement_in_force(): void
    {
        $manager = User::factory()->create();
        $workspace = $this->workspace('Synthetic Lapsed', 'synthetic-lapsed', $manager);
        $expired = $this->company($workspace, 'Synthetic Expired', 'synthetic-expired');
        $hourly = $this->company($workspace, 'Synthetic Hourly', 'synthetic-hourly');

        $this->agreement($workspace, $expired, [
            'title' => 'Synthetic Expired Retainer',
            'status' => 'active',
            'billing_cadence' => 'monthly',
            'starts_on' => '2020-01-01',
            'ends_on' => '2020-12-31',
            'retainer_minutes' => 600,
        ]);
        // Hourly-only: no retainer minutes anywhere, so there is no capacity to
        // report and an empty meter would be a claim about nothing.
        $this->agreement($workspace, $hourly, [
            'title' => 'Synthetic Hourly Agreement',
            'status' => 'active',
            'billing_cadence' => 'monthly',
            'starts_on' => '2026-01-01',
            'hourly_rate_amount' => 20000,
        ]);

        $this->actingAs($manager)
            ->get("/workspaces/{$workspace->public_id}/clients")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('companies', 2)
                ->where('companies.0.retainer', null)
                ->where('companies.1.retainer', null));
    }

    public function test_a_one_time_agreement_never_grants_a_repeating_retainer(): void
    {
        $manager = User::factory()->create();
        $workspace = $this->workspace('Synthetic One Time', 'synthetic-one-time', $manager);
        $company = $this->company($workspace, 'Synthetic Client', 'synthetic-client');
        $agreement = $this->agreement($workspace, $company, [
            'title' => 'Synthetic One Time Package',
            'status' => 'active',
            'billing_cadence' => 'one_time',
            'starts_on' => '2026-01-01',
            'retainer_minutes' => 600,
        ]);

        $this->actingAs($manager)
            ->get("/workspaces/{$workspace->public_id}/clients")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('companies.0.retainer', null));

        $this->actingAs($manager)
            ->get("/workspaces/{$workspace->public_id}/clients/{$company->public_id}/agreements/{$agreement->public_id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('agreement.is_recurring', false)
                ->where('agreement.effective_billing_cadence', null)
                ->where('agreement.effective_first_cycle_proration', null)
                ->where('agreement.retainer_minutes_per_period', null)
                ->where('agreement.retainer_minutes_per_month', null));
    }

    /**
     * Client Home is a glance, not a record dump.
     *
     * What it replaced sent every project, every agreement and twenty invoices,
     * so it grew with the engagement. This asserts the shape that keeps it from
     * doing so again: one latest invoice, capped previews, and a link per
     * section to the module holding the rest.
     */
    public function test_client_home_shows_the_latest_invoice_and_links_to_each_module(): void
    {
        $manager = User::factory()->create();
        $workspace = $this->workspace('Synthetic Detail', 'synthetic-detail', $manager);
        $company = $this->company($workspace, 'Synthetic Client', 'synthetic-client');
        $project = $this->project($workspace, $company, 'Synthetic Project');
        $agreement = $this->agreement($workspace, $company, [
            'title' => 'Synthetic Quarterly Retainer',
            'status' => 'active',
            'billing_cadence' => 'quarterly',
            'starts_on' => '2026-01-01',
            'ends_on' => '2026-12-31',
            'client_project_id' => $project->id,
            'retainer_minutes' => 600,
            'retainer_amount' => 500000,
            'hourly_rate_amount' => 20000,
        ]);
        $this->invoice($workspace, $company, 'SYN-DETAIL-1', 'issued');

        $base = "/workspaces/{$workspace->public_id}/clients/{$company->public_id}";

        $this->actingAs($manager)
            ->get($base)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('clients/home')
                ->where('company.id', $company->public_id)
                ->where('company.name', 'Synthetic Client')
                ->where('latest_invoice.invoice_number', 'SYN-DETAIL-1')
                ->where('engagement.agreement_title', 'Synthetic Quarterly Retainer')
                ->where('engagement.agreement_href', "{$base}/agreements/{$agreement->public_id}")
                ->where('links.invoices', "{$base}/invoices")
                ->where('links.time', "{$base}/time")
                ->where('links.tasks', "{$base}/tasks")
                // The unbounded collections the old screen carried are gone
                // rather than merely shorter. Their absence is the property:
                // a section that can grow is a section that will.
                ->missing('projects')
                ->missing('agreements')
                ->missing('invoices'));
    }

    /**
     * Every preview is capped, and by the view model rather than by each
     * adapter - two adapters choosing their own limits is two screens that
     * disagree about what "recent" means.
     */
    public function test_client_home_caps_every_preview(): void
    {
        $manager = User::factory()->create();
        $workspace = $this->workspace('Synthetic Bounded', 'synthetic-bounded', $manager);
        $company = $this->company($workspace, 'Synthetic Client', 'synthetic-client');
        $project = $this->project($workspace, $company, 'Synthetic Project');

        foreach (range(1, 25) as $number) {
            $this->invoice($workspace, $company, 'SYN-BOUND-'.$number, 'issued');
            ClientTimeEntry::query()->create([
                'workspace_id' => $workspace->id,
                'client_company_id' => $company->id,
                'client_project_id' => $project->id,
                'user_id' => $manager->id,
                'worked_on' => '2026-03-0'.($number % 9 + 1),
                'minutes' => 30,
                'description' => 'Synthetic bounded work '.$number,
                'status' => 'draft',
            ]);
            ClientTask::query()->create([
                'workspace_id' => $workspace->id,
                'client_project_id' => $project->id,
                'title' => 'Synthetic bounded task '.$number,
                'status' => 'open',
            ]);
        }

        $this->actingAs($manager)
            ->get("/workspaces/{$workspace->public_id}/clients/{$company->public_id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                // Exactly one, not "the most recent few": a glance at money has
                // one answer.
                ->has('latest_invoice')
                ->has('recent_time', ClientHomeViewModel::RECENT_TIME)
                ->has('open_tasks', ClientHomeViewModel::OPEN_TASKS));
    }

    public function test_a_workspace_outsider_cannot_list_its_clients(): void
    {
        $manager = User::factory()->create();
        $outsider = User::factory()->create();
        $workspace = $this->workspace('Synthetic Guarded List', 'synthetic-guarded-list', $manager);
        $this->company($workspace, 'Synthetic Client', 'synthetic-client');

        $this->actingAs($outsider)
            ->get("/workspaces/{$workspace->public_id}/clients")
            ->assertForbidden();
    }

    public function test_a_workspace_outsider_cannot_open_one_of_its_clients(): void
    {
        // Separate from the list, so removing either gate names the screen it
        // guards rather than turning one shared test red for both.
        $manager = User::factory()->create();
        $outsider = User::factory()->create();
        $workspace = $this->workspace('Synthetic Guarded Detail', 'synthetic-guarded-detail', $manager);
        $company = $this->company($workspace, 'Synthetic Client', 'synthetic-client');

        $this->actingAs($outsider)
            ->get("/workspaces/{$workspace->public_id}/clients/{$company->public_id}")
            ->assertForbidden();
    }

    public function test_a_company_cannot_be_opened_through_another_workspace_the_reader_belongs_to(): void
    {
        // The failure this guards is not "an outsider gets in" - it is a member
        // of workspace A pasting workspace B's company id after A's own
        // workspace segment. The gate passes, because the workspace really is
        // theirs; only the ownership check refuses.
        $member = User::factory()->create();
        $mine = $this->workspace('Synthetic Mine', 'synthetic-mine', $member);
        $this->company($mine, 'Synthetic Own Client', 'synthetic-own-client');

        $foreign = Workspace::query()->create(['name' => 'Foreign Tenant', 'slug' => 'foreign-tenant']);
        $foreignCompany = $this->company($foreign, 'Foreign Client Name', 'foreign-client-name');

        $this->actingAs($member)
            ->get("/workspaces/{$mine->public_id}/clients/{$foreignCompany->public_id}")
            ->assertNotFound();
    }

    public function test_nothing_from_another_workspace_reaches_the_list_payload(): void
    {
        $manager = User::factory()->create();
        $workspace = $this->workspace('Synthetic Visible', 'synthetic-visible', $manager);
        $company = $this->company($workspace, 'Synthetic Visible Client', 'synthetic-visible-client');
        $project = $this->project($workspace, $company, 'Synthetic Visible Project');
        $this->agreement($workspace, $company, [
            'title' => 'Synthetic Visible Retainer',
            'status' => 'active',
            'billing_cadence' => 'monthly',
            'starts_on' => '2026-01-01',
            'retainer_minutes' => 600,
        ]);
        $this->timeEntry($workspace, $company, $project, [
            'worked_on' => now()->startOfMonth()->toDateString(),
            'minutes' => 60,
            'status' => 'approved',
        ]);

        $foreign = Workspace::query()->create(['name' => 'Foreign Tenant Name', 'slug' => 'foreign-tenant']);
        $foreignCompany = $this->company($foreign, 'Foreign Client Name', 'foreign-client-name');
        $foreignProject = $this->project($foreign, $foreignCompany, 'Foreign Project Name');
        $this->agreement($foreign, $foreignCompany, [
            'title' => 'Foreign Agreement Title',
            'status' => 'active',
            'billing_cadence' => 'monthly',
            'starts_on' => '2026-01-01',
            'retainer_minutes' => 600,
        ]);
        $this->invoice($foreign, $foreignCompany, 'FOREIGN-INV-7777', 'issued');
        $this->timeEntry($foreign, $foreignCompany, $foreignProject, [
            'worked_on' => now()->startOfMonth()->toDateString(),
            'minutes' => 90,
            'status' => 'approved',
        ]);

        // The defect class this repo repeats: a row owned by another workspace
        // that names a company visible here. Keyed on the company alone, each
        // of these is counted or serialized on its parent's authority.
        $strayProject = $this->writingLegacyCrossTenantRows(function () use ($foreign, $company) {
            $project = $this->project($foreign, $company, 'Stray Foreign Project Name');
            $this->invoice($foreign, $company, 'STRAY-INV-9999', 'issued');
            $this->invoice($foreign, $company, 'STRAY-INV-DRAFT', 'draft');
            $this->agreement($foreign, $company, [
                'title' => 'Stray Agreement Title',
                'status' => 'active',
                'billing_cadence' => 'monthly',
                'starts_on' => '2026-01-01',
                'retainer_minutes' => 9999,
            ]);
            $this->timeEntry($foreign, $company, $project, [
                'worked_on' => now()->startOfMonth()->toDateString(),
                'minutes' => 999,
                'status' => 'approved',
            ]);

            return $project;
        });

        $response = $this->actingAs($manager)
            ->get("/workspaces/{$workspace->public_id}/clients")
            ->assertOk();

        $this->assertInertiaPayloadOmits($response, [
            'Foreign Tenant Name',
            'Foreign Client Name',
            'Foreign Project Name',
            'Foreign Agreement Title',
            'FOREIGN-INV-7777',
            'Stray Foreign Project Name',
            'Stray Agreement Title',
        ], 'Synthetic Visible Client');

        // The counts and the retainer figures are the other half: a leak here
        // is an aggregate rather than a string, so the scan above cannot see it.
        $response->assertInertia(fn (Assert $page) => $page
            ->has('companies', 1)
            ->where('companies.0.project_count', 1)
            ->where('companies.0.draft_invoice_count', 0)
            ->where('companies.0.open_invoice_count', 0)
            ->where('companies.0.retainer.agreement', 'Synthetic Visible Retainer')
            ->where('companies.0.retainer.capacity_minutes', 600)
            ->where('companies.0.retainer.used_minutes', 60));
    }

    public function test_nothing_invisible_reaches_the_detail_payload(): void
    {
        $manager = User::factory()->create();
        $workspace = $this->workspace('Synthetic Detail Scope', 'synthetic-detail-scope', $manager);
        $company = $this->company($workspace, 'Synthetic Detail Client', 'synthetic-detail-client');
        $this->project($workspace, $company, 'Synthetic Detail Project');
        $this->agreement($workspace, $company, [
            'title' => 'Synthetic Detail Agreement',
            'status' => 'active',
            'billing_cadence' => 'monthly',
            'starts_on' => '2026-01-01',
            'retainer_minutes' => 600,
        ]);
        $this->invoice($workspace, $company, 'SYN-SCOPE-1', 'issued');

        $sibling = $this->company($workspace, 'Sibling Client Name', 'sibling-client-name');
        $siblingProject = $this->project($workspace, $sibling, 'Sibling Project Name');
        $this->agreement($workspace, $sibling, [
            'title' => 'Sibling Agreement Title',
            'status' => 'active',
            'billing_cadence' => 'monthly',
            'starts_on' => '2026-01-01',
        ]);
        $this->invoice($workspace, $sibling, 'SIBLING-INV-4242', 'issued');

        $foreign = Workspace::query()->create(['name' => 'Foreign Detail Tenant', 'slug' => 'foreign-detail']);
        $this->writingLegacyCrossTenantRows(function () use ($foreign, $company) {
            $this->project($foreign, $company, 'Stray Detail Project Name');
            $this->invoice($foreign, $company, 'STRAY-DETAIL-8888', 'issued');
            $this->agreement($foreign, $company, [
                'title' => 'Stray Detail Agreement Title',
                'status' => 'active',
                'billing_cadence' => 'monthly',
                'starts_on' => '2026-01-01',
            ]);
        });

        // An agreement scoped to a project of another company still names it by
        // an independent key. The name must not be resolved from that key.
        $this->agreement($workspace, $company, [
            'title' => 'Synthetic Cross Scoped Agreement',
            'status' => 'draft',
            'billing_cadence' => 'monthly',
            'starts_on' => '2026-02-01',
            'client_project_id' => $siblingProject->id,
        ]);

        $response = $this->actingAs($manager)
            ->get("/workspaces/{$workspace->public_id}/clients/{$company->public_id}")
            ->assertOk();

        $this->assertInertiaPayloadOmits($response, [
            'Foreign Detail Tenant',
            'Sibling Project Name',
            'Sibling Agreement Title',
            'SIBLING-INV-4242',
            'Stray Detail Project Name',
            'Stray Detail Agreement Title',
            'STRAY-DETAIL-8888',
        ], 'Synthetic Detail Client');

        $response->assertInertia(fn (Assert $page) => $page
            ->where('latest_invoice.invoice_number', 'SYN-SCOPE-1')
            ->where('engagement.agreement_title', 'Synthetic Detail Agreement'));

        // The cross-scoped agreement names a project of another company by an
        // independent key, and its detail screen is where a name would be
        // resolved from that key. Asserted on that screen rather than on Home,
        // which names no project at all - a guarantee is only tested where the
        // thing it guards is rendered.
        $crossScoped = ClientAgreement::query()
            ->where('title', 'Synthetic Cross Scoped Agreement')
            ->sole();

        $this->actingAs($manager)
            ->get("/workspaces/{$workspace->public_id}/clients/{$company->public_id}/agreements/{$crossScoped->public_id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('agreement.project', null));

        // The sibling *company's* name is deliberately absent from the omit
        // list above, because the company switcher lists every company in this
        // workspace on purpose - the operator can already see them all in the
        // directory, and a switcher that hid them would be useless.
        //
        // It is asserted here instead, against this screen's own props with the
        // shared chrome removed. Dropping the name from the scan without this
        // would have quietly retired a real guarantee: that company A's detail
        // screen never renders company B's name in its projects, agreements or
        // invoices.
        $props = $response->viewData('page')['props'];
        unset($props['workspaceNavigation']);

        $this->assertStringNotContainsString(
            'Sibling Client Name',
            (string) json_encode($props, JSON_THROW_ON_ERROR),
            "A sibling company's name reached this company's own payload, outside the switcher.",
        );
    }

    public function test_the_list_does_not_query_once_per_row(): void
    {
        $manager = User::factory()->create();
        $workspace = $this->workspace('Synthetic Row Scope', 'synthetic-row-scope', $manager);

        $addCompanies = function (int $count) use ($workspace, $manager): void {
            $existing = ClientCompany::query()->where('workspace_id', $workspace->id)->count();

            for ($i = 1; $i <= $count; $i++) {
                $number = $existing + $i;
                $company = $this->company($workspace, "Synthetic Client {$number}", "synthetic-client-{$number}");
                $project = $this->project($workspace, $company, "Synthetic Project {$number}");
                $this->agreement($workspace, $company, [
                    'title' => "Synthetic Retainer {$number}",
                    'status' => 'active',
                    'billing_cadence' => 'monthly',
                    'starts_on' => '2026-01-01',
                    'retainer_minutes' => 600,
                ]);
                $this->invoice($workspace, $company, "SYN-ROW-{$number}", 'issued');
                $this->timeEntry($workspace, $company, $project, [
                    'worked_on' => now()->startOfMonth()->toDateString(),
                    'minutes' => 30,
                    'status' => 'approved',
                ], $manager);
            }
        };

        $addCompanies(2);

        $this->assertQueryCountIndependentOfRows(
            fn () => $this->actingAs($manager)
                ->get("/workspaces/{$workspace->public_id}/clients")
                ->assertOk(),
            fn () => $addCompanies(20),
        );
    }

    public function test_the_detail_screen_does_not_query_once_per_row(): void
    {
        $manager = User::factory()->create();
        $workspace = $this->workspace('Synthetic Detail Rows', 'synthetic-detail-rows', $manager);
        $company = $this->company($workspace, 'Synthetic Client', 'synthetic-client');

        $addRows = function (int $count) use ($workspace, $company): void {
            $existing = ClientProject::query()->where('client_company_id', $company->id)->count();

            for ($i = 1; $i <= $count; $i++) {
                $number = $existing + $i;
                $this->project($workspace, $company, "Synthetic Project {$number}");
                $this->agreement($workspace, $company, [
                    'title' => "Synthetic Agreement {$number}",
                    'status' => 'active',
                    'billing_cadence' => 'monthly',
                    'starts_on' => '2026-01-01',
                ]);
                $this->invoice($workspace, $company, "SYN-DROW-{$number}", 'issued');
            }
        };

        $addRows(2);

        $this->assertQueryCountIndependentOfRows(
            fn () => $this->actingAs($manager)
                ->get("/workspaces/{$workspace->public_id}/clients/{$company->public_id}")
                ->assertOk(),
            fn () => $addRows(20),
        );
    }

    public function test_the_directory_screens_name_only_columns_that_exist(): void
    {
        $manager = User::factory()->create();
        $workspace = $this->workspace('Synthetic Identifiers', 'synthetic-identifiers', $manager);
        $company = $this->company($workspace, 'Synthetic Client', 'synthetic-client');
        $project = $this->project($workspace, $company, 'Synthetic Project');
        $this->agreement($workspace, $company, [
            'title' => 'Synthetic Retainer',
            'status' => 'active',
            'billing_cadence' => 'monthly',
            'starts_on' => '2026-01-01',
            'retainer_minutes' => 600,
            'client_project_id' => $project->id,
        ]);
        $this->invoice($workspace, $company, 'SYN-IDENT-1', 'issued');
        $this->timeEntry($workspace, $company, $project, [
            'worked_on' => now()->startOfMonth()->toDateString(),
            'minutes' => 60,
            'status' => 'approved',
        ]);

        $this->assertQueriesNameOnlyRealIdentifiers(
            fn () => $this->actingAs($manager)
                ->get("/workspaces/{$workspace->public_id}/clients")
                ->assertOk(),
        );
        $this->assertQueriesNameOnlyRealIdentifiers(
            fn () => $this->actingAs($manager)
                ->get("/workspaces/{$workspace->public_id}/clients/{$company->public_id}")
                ->assertOk(),
        );
    }

    /**
     * The Invoices tab lists this client's invoices and only this client's.
     *
     * The same two-key rule the detail screen follows: `client_company_id`
     * alone would serialize another workspace's invoice on the strength of the
     * company it names, and the tab is a new query that has to obey it too
     * rather than inheriting the discipline by proximity.
     */
    public function test_the_invoices_tab_lists_only_this_companys_invoices(): void
    {
        $manager = User::factory()->create();
        $workspace = $this->workspace('Synthetic Tab Scope', 'synthetic-tab-scope', $manager);
        $company = $this->company($workspace, 'Synthetic Tab Client', 'synthetic-tab-client');
        $sibling = $this->company($workspace, 'Sibling Tab Client', 'sibling-tab-client');

        $this->invoice($workspace, $company, 'SYN-TAB-1', 'issued');
        $this->invoice($workspace, $company, 'SYN-TAB-2', 'draft');
        $this->invoice($workspace, $sibling, 'SIBLING-TAB-9999', 'issued');

        $foreign = Workspace::query()->create(['name' => 'Foreign Tab Tenant', 'slug' => 'foreign-tab']);
        // Another workspace's invoice naming a company visible here - refused
        // by the composite keys since #113, so seeded with enforcement
        // suspended; the query's second key is what must exclude it.
        $this->writingLegacyCrossTenantRows(
            fn () => $this->invoice($foreign, $company, 'STRAY-TAB-8888', 'issued'),
        );

        $response = $this->actingAs($manager)
            ->get("/workspaces/{$workspace->public_id}/clients/{$company->public_id}/invoices")
            ->assertOk();

        $response->assertInertia(fn (Assert $page) => $page
            ->component('clients/invoices')
            ->has('invoices', 2)
            ->where('company.name', 'Synthetic Tab Client'));

        $this->assertInertiaPayloadOmits($response, [
            'SIBLING-TAB-9999',
            'STRAY-TAB-8888',
            'Foreign Tab Tenant',
        ], 'SYN-TAB-1');
    }

    /**
     * A member who reaches one project sees that project's tasks and no others.
     *
     * This screen applies per-project access where its siblings on this
     * controller do not, and that difference is the assertion: a task carries a
     * title written for whoever is doing the work, so a member scoped to one
     * project must not read another's through a company they can otherwise see.
     */
    public function test_tasks_are_limited_to_the_projects_the_viewer_can_reach(): void
    {
        $manager = User::factory()->create();
        $workspace = $this->workspace('Synthetic Task Scope', 'synthetic-task-scope', $manager);
        $company = $this->company($workspace, 'Synthetic Task Client', 'synthetic-task-client');
        $reachable = $this->project($workspace, $company, 'Reachable Project');
        $hidden = $this->project($workspace, $company, 'Hidden Project');

        $this->task($workspace, $reachable, 'Reachable Task Title');
        $this->task($workspace, $hidden, 'Hidden Task Title');

        $member = User::factory()->create();
        $workspace->memberships()->create(['user_id' => $member->id, 'role' => 'member']);
        ClientProjectMembership::query()->create([
            'workspace_id' => $workspace->id,
            'client_project_id' => $reachable->id,
            'user_id' => $member->id,
            'role' => 'contributor',
        ]);

        $response = $this->actingAs($member)
            ->get("/workspaces/{$workspace->public_id}/clients/{$company->public_id}/tasks")
            ->assertOk();

        $response->assertInertia(fn (Assert $page) => $page
            ->component('clients/tasks')
            ->has('projects', 1)
            ->has('tasks', 1)
            ->where('tasks.0.title', 'Reachable Task Title'));

        $this->assertInertiaPayloadOmits($response, [
            'Hidden Task Title',
            'Hidden Project',
        ], 'Reachable Task Title');
    }

    /**
     * A task owned by another workspace that names a project visible here.
     *
     * Tasks have no company key, so the company is reached through its
     * projects - and keying on the project alone would serialize this row on
     * the strength of a project the viewer can legitimately see.
     */
    public function test_a_foreign_workspaces_task_on_a_visible_project_is_excluded(): void
    {
        $manager = User::factory()->create();
        $workspace = $this->workspace('Synthetic Task Chain', 'synthetic-task-chain', $manager);
        $company = $this->company($workspace, 'Synthetic Chain Client', 'synthetic-chain-client');
        $project = $this->project($workspace, $company, 'Shared Name Project');
        $this->task($workspace, $project, 'Own Task Title');

        $foreign = Workspace::query()->create(['name' => 'Foreign Task Tenant', 'slug' => 'foreign-task']);
        $this->writingLegacyCrossTenantRows(
            fn () => $this->task($foreign, $project, 'Stray Task Title'),
        );

        $response = $this->actingAs($manager)
            ->get("/workspaces/{$workspace->public_id}/clients/{$company->public_id}/tasks")
            ->assertOk();

        $response->assertInertia(fn (Assert $page) => $page->has('tasks', 1));
        $this->assertInertiaPayloadOmits($response, ['Stray Task Title'], 'Own Task Title');
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

    /**
     * An invoice detail reached through the wrong client is not found.
     *
     * The invoice binds by a public id unique across every workspace, so
     * passing the workspace gate is not passing a check on the invoice in the
     * URL - and the company segment is checked too, or the chrome would label
     * one client's invoice with the name of the client the operator came from.
     */
    public function test_an_invoice_cannot_be_opened_under_another_client(): void
    {
        $manager = User::factory()->create();
        $workspace = $this->workspace('Synthetic Detail Route', 'synthetic-detail-route', $manager);
        $owner = $this->company($workspace, 'Owning Detail Client', 'owning-detail-client');
        $other = $this->company($workspace, 'Other Detail Client', 'other-detail-client');
        $invoice = $this->invoice($workspace, $owner, 'SYN-DETAIL-1', 'issued');

        $this->actingAs($manager)
            ->get("/workspaces/{$workspace->public_id}/clients/{$owner->public_id}/invoices/{$invoice->public_id}")
            ->assertOk();

        // Same invoice, a sibling company in the same workspace.
        $this->actingAs($manager)
            ->get("/workspaces/{$workspace->public_id}/clients/{$other->public_id}/invoices/{$invoice->public_id}")
            ->assertNotFound();
    }

    /**
     * And not from another workspace either, even by an operator who passes
     * that workspace's own gate.
     */
    public function test_an_invoice_from_another_workspace_is_not_found(): void
    {
        $manager = User::factory()->create();
        $mine = $this->workspace('Synthetic Mine Route', 'synthetic-mine-route', $manager);
        $company = $this->company($mine, 'Synthetic Mine Route Client', 'mine-route-client');

        $foreign = Workspace::query()->create(['name' => 'Foreign Route Tenant', 'slug' => 'foreign-route']);
        $foreignCompany = $this->company($foreign, 'Foreign Route Client', 'foreign-route-client');
        $foreignInvoice = $this->invoice($foreign, $foreignCompany, 'FOREIGN-ROUTE-1', 'issued');

        $this->actingAs($manager)
            ->get("/workspaces/{$mine->public_id}/clients/{$company->public_id}/invoices/{$foreignInvoice->public_id}")
            ->assertNotFound();
    }

    /**
     * A scoped member sees the clients they work for, and no others (#157).
     *
     * The directory used to answer this differently from the time sheet: it
     * showed every company and every project name to anyone who passed the
     * workspace gate. Both now answer it the strict way, so a contributor on
     * one project of one client cannot read the rest of the workspace's client
     * list.
     */
    public function test_a_scoped_member_sees_only_the_clients_they_can_reach(): void
    {
        $manager = User::factory()->create();
        $workspace = $this->workspace('Synthetic Reach', 'synthetic-reach', $manager);
        $mine = $this->company($workspace, 'Reachable Client Name', 'reachable-client');
        $reachable = $this->project($workspace, $mine, 'Reachable Scope Project');
        $this->project($workspace, $mine, 'Unreachable Sibling Project');

        $theirs = $this->company($workspace, 'Unreachable Client Name', 'unreachable-client');
        $this->project($workspace, $theirs, 'Someone Elses Project');

        $member = User::factory()->create();
        $workspace->memberships()->create(['user_id' => $member->id, 'role' => 'member']);
        ClientProjectMembership::query()->create([
            'workspace_id' => $workspace->id,
            'client_project_id' => $reachable->id,
            'user_id' => $member->id,
            'role' => 'contributor',
        ]);

        $response = $this->actingAs($member)
            ->get("/workspaces/{$workspace->public_id}/clients")
            ->assertOk();

        $response->assertInertia(fn (Assert $page) => $page->has('companies', 1));
        $this->assertInertiaPayloadOmits($response, [
            'Unreachable Client Name',
            'Someone Elses Project',
        ], 'Reachable Client Name');

        // The client's own modules narrow the same way, so reaching one
        // project of a client does not disclose the rest of that client's
        // portfolio. Asserted on Tasks, which is where the project list now
        // lives - Client Home names a project only where one appears on a row.
        $tasks = $this->actingAs($member)
            ->get("/workspaces/{$workspace->public_id}/clients/{$mine->public_id}/tasks")
            ->assertOk();

        $tasks->assertInertia(fn (Assert $page) => $page->has('projects', 1));
        $this->assertInertiaPayloadOmits(
            $tasks,
            ['Unreachable Sibling Project'],
            'Reachable Scope Project',
        );
    }

    /**
     * And a direct URL agrees with the list.
     *
     * Omitting a company from the index while still serving it by id would
     * make the scoping decorative - the id would be the only thing in the way.
     */
    public function test_a_client_the_member_cannot_reach_is_not_found_by_url(): void
    {
        $manager = User::factory()->create();
        $workspace = $this->workspace('Synthetic Direct', 'synthetic-direct', $manager);
        $theirs = $this->company($workspace, 'Out Of Reach Client', 'out-of-reach-client');
        $this->project($workspace, $theirs, 'Out Of Reach Project');
        $this->invoice($workspace, $theirs, 'OUTOFREACH-1', 'issued');

        $member = User::factory()->create();
        $workspace->memberships()->create(['user_id' => $member->id, 'role' => 'member']);

        foreach (['', '/invoices', '/tasks'] as $suffix) {
            $this->actingAs($member)
                ->get("/workspaces/{$workspace->public_id}/clients/{$theirs->public_id}{$suffix}")
                ->assertNotFound();
        }
    }

    /**
     * An owner or admin still reaches a client with no projects at all.
     *
     * Reachability is defined through projects, so a brand-new client would
     * otherwise be invisible to the person who just created it.
     */
    public function test_an_admin_still_sees_a_client_with_no_projects(): void
    {
        $manager = User::factory()->create();
        $workspace = $this->workspace('Synthetic Fresh', 'synthetic-fresh', $manager);
        $this->company($workspace, 'Brand New Client', 'brand-new-client');

        $this->actingAs($manager)
            ->get("/workspaces/{$workspace->public_id}/clients")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('companies', 1)
                ->where('companies.0.name', 'Brand New Client'));
    }

    /**
     * Manage is offered only to a manager, and refuses regardless.
     *
     * The tab is hidden without the ability, but hiding a link is not a check -
     * so the action is asserted directly against someone who can see the client
     * and cannot manage it.
     */
    public function test_manage_is_refused_to_a_member_who_cannot_manage(): void
    {
        $manager = User::factory()->create();
        $workspace = $this->workspace('Synthetic Manage', 'synthetic-manage', $manager);
        $company = $this->company($workspace, 'Synthetic Manage Client', 'synthetic-manage-client');
        $project = $this->project($workspace, $company, 'Synthetic Manage Project');

        $member = User::factory()->create();
        $workspace->memberships()->create(['user_id' => $member->id, 'role' => 'member']);
        ClientProjectMembership::query()->create([
            'workspace_id' => $workspace->id,
            'client_project_id' => $project->id,
            'user_id' => $member->id,
            'role' => 'contributor',
        ]);

        // They can reach the client - the Overview tab is fine.
        $this->actingAs($member)
            ->get("/workspaces/{$workspace->public_id}/clients/{$company->public_id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('workspaceNavigation.permissions.manage_current_client', false));

        $this->actingAs($member)
            ->get("/workspaces/{$workspace->public_id}/clients/{$company->public_id}/settings")
            ->assertForbidden();

        $this->actingAs($member)
            ->patch("/workspaces/{$workspace->public_id}/clients/{$company->public_id}", [
                'name' => 'Renamed By Someone Who May Not',
                'billing_email' => null,
                'is_active' => true,
            ])
            ->assertForbidden();

        $this->assertSame('Synthetic Manage Client', $company->fresh()?->name);
    }

    public function test_a_manager_can_edit_the_client_and_its_projects(): void
    {
        $manager = User::factory()->create();
        $workspace = $this->workspace('Synthetic Edit', 'synthetic-edit', $manager);
        $company = $this->company($workspace, 'Before Rename Client', 'before-rename-client');
        $project = $this->project($workspace, $company, 'Before Rename Project');

        $this->actingAs($manager)
            ->patch("/workspaces/{$workspace->public_id}/clients/{$company->public_id}", [
                'name' => 'After Rename Client',
                'billing_email' => 'billing@synthetic.test',
                'is_active' => false,
            ])
            ->assertRedirect();

        $fresh = $company->fresh();
        $this->assertSame('After Rename Client', $fresh?->name);
        $this->assertSame('billing@synthetic.test', $fresh?->billing_email);
        $this->assertFalse((bool) $fresh?->is_active);

        $this->actingAs($manager)
            ->patch("/workspaces/{$workspace->public_id}/clients/{$company->public_id}/projects/{$project->public_id}", [
                'name' => 'After Rename Project',
                'description' => 'Synthetic description',
                'repository' => null,
                'status' => 'archived',
                'is_visible_to_client' => false,
                'lock_version' => (int) $project->fresh()?->lock_version,
            ])
            ->assertRedirect();

        $freshProject = $project->fresh();
        $this->assertSame('After Rename Project', $freshProject?->name);
        $this->assertSame('archived', $freshProject?->status);
        $this->assertFalse((bool) $freshProject?->is_visible_to_client);
    }

    /**
     * A project reached through the wrong client is not editable.
     *
     * Projects bind by a public id unique across every workspace, so without
     * the company check a manager edits another client's project through their
     * own client's Manage tab - and the redirect would look like it worked.
     */
    public function test_a_project_cannot_be_edited_through_another_client(): void
    {
        $manager = User::factory()->create();
        $workspace = $this->workspace('Synthetic Cross Edit', 'synthetic-cross-edit', $manager);
        $owner = $this->company($workspace, 'Owning Edit Client', 'owning-edit-client');
        $other = $this->company($workspace, 'Other Edit Client', 'other-edit-client');
        $project = $this->project($workspace, $owner, 'Owned Edit Project');

        $this->actingAs($manager)
            ->patch("/workspaces/{$workspace->public_id}/clients/{$other->public_id}/projects/{$project->public_id}", [
                'name' => 'Renamed Through The Wrong Client',
                'description' => null,
                'repository' => null,
                'status' => 'active',
                'is_visible_to_client' => true,
                // A payload that validates, so the refusal below is the
                // company check and not the version rule arriving first.
                'lock_version' => (int) $project->fresh()?->lock_version,
            ])
            ->assertNotFound();

        $this->assertSame('Owned Edit Project', $project->fresh()?->name);
    }

    /**
     * A repository is stored canonically, whatever spelling was pasted in.
     *
     * The operator pastes what their checkout printed, and a checkout prints
     * one of several spellings of the same remote. Storing it as typed would
     * make the mapping match only for whoever happened to paste the same shape,
     * which fails silently - the project saves, and an agent in that repository
     * still cannot find it. See #243.
     */
    public function test_a_pasted_remote_is_stored_canonically(): void
    {
        $manager = User::factory()->create();
        $workspace = $this->workspace('Synthetic Repo', 'synthetic-repo', $manager);
        $company = $this->company($workspace, 'Repo Client', 'repo-client');
        $project = $this->project($workspace, $company, 'Repo Project');

        $this->actingAs($manager)
            ->patch("/workspaces/{$workspace->public_id}/clients/{$company->public_id}/projects/{$project->public_id}", [
                'name' => 'Repo Project',
                'description' => null,
                'repository' => 'git@github.com:Synthetic/Example.git',
                'status' => 'active',
                'is_visible_to_client' => true,
                'lock_version' => (int) $project->fresh()?->lock_version,
            ])
            ->assertRedirect();

        $this->assertSame('github.com/synthetic/example', $project->fresh()?->repository);
    }

    /**
     * Clearing the field is a real act and is not confused with a bad value.
     *
     * Both arrive at the normalizer as null, so only the pairing of `nullable`
     * with the format rule can tell them apart. If they collapsed, an operator
     * who typed a sentence would be told the project saved and the mapping
     * would quietly be "nobody has said" - the exact failure the field exists
     * to remove.
     */
    public function test_a_blank_unmaps_but_an_unusable_value_is_refused(): void
    {
        $manager = User::factory()->create();
        $workspace = $this->workspace('Synthetic Unmap', 'synthetic-unmap', $manager);
        $company = $this->company($workspace, 'Unmap Client', 'unmap-client');
        $project = $this->project($workspace, $company, 'Unmap Project');
        $project->forceFill(['repository' => 'github.com/synthetic/mapped'])->save();

        $payload = [
            'name' => 'Unmap Project',
            'description' => null,
            'status' => 'active',
            'is_visible_to_client' => true,
        ];

        $this->actingAs($manager)
            ->patch("/workspaces/{$workspace->public_id}/clients/{$company->public_id}/projects/{$project->public_id}", [
                ...$payload,
                'repository' => 'the synthetic repo on github',
                'lock_version' => (int) $project->fresh()?->lock_version,
            ])
            ->assertSessionHasErrors('repository');

        $this->assertSame('github.com/synthetic/mapped', $project->fresh()?->repository);

        $this->actingAs($manager)
            ->patch("/workspaces/{$workspace->public_id}/clients/{$company->public_id}/projects/{$project->public_id}", [
                ...$payload,
                'repository' => '',
                'lock_version' => (int) $project->fresh()?->lock_version,
            ])
            ->assertRedirect();

        $this->assertNull($project->fresh()?->repository);
    }

    /**
     * A save about something else does not unmap the project.
     *
     * `present` rather than bare `nullable`, for the same reason as the
     * description beside it: without it a PATCH that only meant to rename would
     * drop the mapping, and nobody would notice until the next time an agent
     * asked which project this checkout was.
     */
    public function test_a_payload_omitting_the_repository_is_refused(): void
    {
        $manager = User::factory()->create();
        $workspace = $this->workspace('Synthetic Keep', 'synthetic-keep', $manager);
        $company = $this->company($workspace, 'Keep Client', 'keep-client');
        $project = $this->project($workspace, $company, 'Keep Project');
        $project->forceFill(['repository' => 'github.com/synthetic/keep'])->save();

        $this->actingAs($manager)
            ->patch("/workspaces/{$workspace->public_id}/clients/{$company->public_id}/projects/{$project->public_id}", [
                'name' => 'Renamed Keep Project',
                'description' => null,
                'status' => 'active',
                'is_visible_to_client' => true,
                'lock_version' => (int) $project->fresh()?->lock_version,
            ])
            ->assertSessionHasErrors('repository');

        $fresh = $project->fresh();
        $this->assertSame('github.com/synthetic/keep', $fresh?->repository);
        $this->assertSame('Keep Project', $fresh?->name);
    }

    /**
     * A pasted token does not outlive the request that refused it.
     *
     * A remote is the one ordinary field here that can carry a secret - a
     * machine with stored credentials prints
     * `https://user:token@host/owner/name.git` - and the normalizer strips it
     * before saving. But a refused save never reaches the normalizer: Laravel
     * flashes the raw input on the redirect back, and sessions are stored in
     * the database, so without `dontFlash` the token is written to a table.
     *
     * The version check is what refuses it here, deliberately: it is the
     * failure an operator hits by accident, with a payload that is otherwise
     * entirely well-formed.
     */
    public function test_a_credential_in_a_refused_remote_is_not_flashed(): void
    {
        $manager = User::factory()->create();
        $workspace = $this->workspace('Synthetic Secret', 'synthetic-secret', $manager);
        $company = $this->company($workspace, 'Secret Client', 'secret-client');
        $project = $this->project($workspace, $company, 'Secret Project');

        $this->actingAs($manager)
            ->patch("/workspaces/{$workspace->public_id}/clients/{$company->public_id}/projects/{$project->public_id}", [
                'name' => 'Secret Project',
                'description' => null,
                'repository' => 'https://someone:a-secret@example.com/owner/name.git',
                'status' => 'active',
                'is_visible_to_client' => true,
                'lock_version' => (int) $project->fresh()?->lock_version + 99,
            ])
            ->assertSessionHasErrors('lock_version');

        $this->assertNull(session()->getOldInput('repository'));
        $this->assertSame(
            'Secret Project',
            session()->getOldInput('name'),
            'the rest of the form is still flashed, or this proves nothing',
        );
    }

    /** The Manage screen renders the stored mapping so the form can round-trip it. */
    public function test_the_manage_screen_sends_the_repository(): void
    {
        $manager = User::factory()->create();
        $workspace = $this->workspace('Synthetic Send', 'synthetic-send', $manager);
        $company = $this->company($workspace, 'Send Client', 'send-client');
        $project = $this->project($workspace, $company, 'Send Project');
        $project->forceFill(['repository' => 'github.com/synthetic/send'])->save();

        $this->actingAs($manager)
            ->get("/workspaces/{$workspace->public_id}/clients/{$company->public_id}/settings")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('projects.0.repository', 'github.com/synthetic/send')
                ->etc());
    }

    /**
     * An agreement's full terms, and only through the client that owns it.
     *
     * Agreements bind by a public id unique across every workspace, so without
     * the company check one client's terms - rates, retainer, signatories -
     * render under another client's name and chrome.
     */
    public function test_an_agreement_is_only_reachable_through_its_own_client(): void
    {
        $manager = User::factory()->create();
        $workspace = $this->workspace('Synthetic Terms', 'synthetic-terms', $manager);
        $owner = $this->company($workspace, 'Owning Terms Client', 'owning-terms-client');
        $other = $this->company($workspace, 'Other Terms Client', 'other-terms-client');

        $agreement = $this->agreement($workspace, $owner, [
            'title' => 'Synthetic Terms Agreement',
            'status' => 'active',
            'billing_cadence' => 'monthly',
            'starts_on' => '2026-01-01',
            'retainer_minutes' => 600,
        ]);

        $this->actingAs($manager)
            ->get("/workspaces/{$workspace->public_id}/clients/{$owner->public_id}/agreements/{$agreement->public_id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('clients/agreement')
                ->where('agreement.title', 'Synthetic Terms Agreement')
                ->where('company.name', 'Owning Terms Client'));

        $this->actingAs($manager)
            ->get("/workspaces/{$workspace->public_id}/clients/{$other->public_id}/agreements/{$agreement->public_id}")
            ->assertNotFound();
    }

    /**
     * An unstated term is sent as null, not as zero.
     *
     * The screen distinguishes them - a null rate means the lookup refuses to
     * price rather than pricing at nothing, and a null threshold means the
     * engine defaults - so the payload has to preserve the difference the
     * whole null-semantics registry exists to protect.
     */
    public function test_unstated_agreement_terms_are_sent_as_null(): void
    {
        $manager = User::factory()->create();
        $workspace = $this->workspace('Synthetic Unstated', 'synthetic-unstated', $manager);
        $company = $this->company($workspace, 'Synthetic Unstated Client', 'unstated-client');

        $agreement = $this->agreement($workspace, $company, [
            'title' => 'Synthetic Unstated Agreement',
            'status' => 'active',
            'billing_cadence' => 'monthly',
            'starts_on' => '2026-01-01',
        ]);

        $this->actingAs($manager)
            ->get("/workspaces/{$workspace->public_id}/clients/{$company->public_id}/agreements/{$agreement->public_id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('agreement.hourly_rate_amount', null)
                ->where('agreement.catch_up_threshold_minutes', null)
                ->where('agreement.rollover_policy', null)
                ->where('agreement.terminated_at', null));
    }

    /**
     * Reaching a client is not reaching every project of it.
     *
     * The one-level-in version of #157: a contributor scoped to one project can
     * open that client, so without a check here they read a sibling project's
     * description and task list by pasting its id.
     */
    public function test_a_project_the_member_cannot_view_is_not_found(): void
    {
        $manager = User::factory()->create();
        $workspace = $this->workspace('Synthetic Project Scope', 'synthetic-project-scope', $manager);
        $company = $this->company($workspace, 'Synthetic Project Client', 'synthetic-project-client');
        $reachable = $this->project($workspace, $company, 'Reachable Detail Project');
        $hidden = $this->project($workspace, $company, 'Hidden Detail Project');

        $member = User::factory()->create();
        $workspace->memberships()->create(['user_id' => $member->id, 'role' => 'member']);
        ClientProjectMembership::query()->create([
            'workspace_id' => $workspace->id,
            'client_project_id' => $reachable->id,
            'user_id' => $member->id,
            'role' => 'contributor',
        ]);

        $this->actingAs($member)
            ->get("/workspaces/{$workspace->public_id}/clients/{$company->public_id}/projects/{$reachable->public_id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('clients/project')
                ->where('project.name', 'Reachable Detail Project'));

        $this->actingAs($member)
            ->get("/workspaces/{$workspace->public_id}/clients/{$company->public_id}/projects/{$hidden->public_id}")
            ->assertNotFound();
    }

    /**
     * Time is totalled per status rather than summed into one figure.
     *
     * Approved and draft hours mean different things - only one has been
     * agreed to be billable - so a single total would state something the
     * ledger does not.
     */
    public function test_project_time_is_totalled_separately_by_status(): void
    {
        $manager = User::factory()->create();
        $workspace = $this->workspace('Synthetic Totals', 'synthetic-totals', $manager);
        $company = $this->company($workspace, 'Synthetic Totals Client', 'totals-client');
        $project = $this->project($workspace, $company, 'Synthetic Totals Project');
        $other = $this->project($workspace, $company, 'Other Totals Project');

        $worked = '2026-08-01';
        $this->timeEntry($workspace, $company, $project, ['worked_on' => $worked, 'minutes' => 120, 'status' => 'approved']);
        $this->timeEntry($workspace, $company, $project, ['worked_on' => $worked, 'minutes' => 60, 'status' => 'approved']);
        $this->timeEntry($workspace, $company, $project, ['worked_on' => $worked, 'minutes' => 30, 'status' => 'draft']);
        // Another project's time must not be counted here.
        $this->timeEntry($workspace, $company, $other, ['worked_on' => $worked, 'minutes' => 999, 'status' => 'approved']);

        $this->actingAs($manager)
            ->get("/workspaces/{$workspace->public_id}/clients/{$company->public_id}/projects/{$project->public_id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('time', 2)
                ->where('time.0.status', 'approved')
                ->where('time.0.minutes', 180)
                ->where('time.0.entries', 2)
                ->where('time.1.status', 'draft')
                ->where('time.1.minutes', 30));
    }

    /**
     * Granting project access is what makes #157's scoping administrable.
     *
     * Until this endpoint existed the reachability rule could be tightened but
     * not managed, so the grant is asserted end to end: the member cannot see
     * the client, is granted access, can, is removed, and cannot again.
     */
    public function test_granting_and_removing_project_access_changes_what_a_member_sees(): void
    {
        $manager = User::factory()->create();
        $workspace = $this->workspace('Synthetic Access', 'synthetic-access', $manager);
        $company = $this->company($workspace, 'Synthetic Access Client', 'synthetic-access-client');
        $project = $this->project($workspace, $company, 'Synthetic Access Project');

        $member = User::factory()->create();
        $workspace->memberships()->create(['user_id' => $member->id, 'role' => 'member']);

        $path = "/workspaces/{$workspace->public_id}/clients/{$company->public_id}";

        $this->actingAs($member)->get($path)->assertNotFound();

        $this->actingAs($manager)
            ->put("{$path}/projects/{$project->public_id}/access", [
                'user' => $member->public_id,
                'role' => 'contributor',
            ])
            ->assertRedirect();

        $this->actingAs($member)->get($path)->assertOk();

        $this->actingAs($manager)
            ->put("{$path}/projects/{$project->public_id}/access", [
                'user' => $member->public_id,
                'role' => 'none',
            ])
            ->assertRedirect();

        $this->actingAs($member)->get($path)->assertNotFound();
    }

    /**
     * Re-granting moves the role rather than adding a second row.
     *
     * Two membership rows would both match every reachability query, and the
     * role that won would depend on which came back first.
     */
    public function test_regranting_access_replaces_the_role(): void
    {
        $manager = User::factory()->create();
        $workspace = $this->workspace('Synthetic Regrant', 'synthetic-regrant', $manager);
        $company = $this->company($workspace, 'Synthetic Regrant Client', 'regrant-client');
        $project = $this->project($workspace, $company, 'Synthetic Regrant Project');

        $member = User::factory()->create();
        $workspace->memberships()->create(['user_id' => $member->id, 'role' => 'member']);

        $path = "/workspaces/{$workspace->public_id}/clients/{$company->public_id}/projects/{$project->public_id}/access";

        foreach (['viewer', 'contributor'] as $role) {
            $this->actingAs($manager)
                ->put($path, ['user' => $member->public_id, 'role' => $role])
                ->assertRedirect();
        }

        $rows = ClientProjectMembership::query()
            ->where('client_project_id', $project->id)
            ->where('user_id', $member->id)
            ->get();

        $this->assertCount(1, $rows);
        $this->assertSame('contributor', $rows->first()?->role->value);
    }

    /**
     * Access cannot be granted to someone outside the workspace.
     *
     * The membership row would be honoured by every reachability query while
     * no workspace membership backed it - access through a side door.
     */
    public function test_access_cannot_be_granted_to_a_non_member(): void
    {
        $manager = User::factory()->create();
        $workspace = $this->workspace('Synthetic Outsider', 'synthetic-outsider', $manager);
        $company = $this->company($workspace, 'Synthetic Outsider Client', 'outsider-client');
        $project = $this->project($workspace, $company, 'Synthetic Outsider Project');

        $outsider = User::factory()->create();

        $this->actingAs($manager)
            ->put("/workspaces/{$workspace->public_id}/clients/{$company->public_id}/projects/{$project->public_id}/access", [
                'user' => $outsider->public_id,
                'role' => 'contributor',
            ])
            ->assertNotFound();

        $this->assertSame(0, ClientProjectMembership::query()->where('user_id', $outsider->id)->count());
    }

    /**
     * The cross-workspace isolation test this endpoint was missing.
     *
     * Three guards protect it - the company against the workspace, the project
     * against the workspace, and the project's company against the company in
     * the URL - and none of them had a test that used another workspace's rows
     * and then checked that no membership was written. Asserting the refusal
     * without asserting the absence would pass on a 404 that still wrote.
     */
    public function test_no_membership_is_written_across_a_workspace_boundary(): void
    {
        $manager = User::factory()->create();
        $mine = $this->workspace('Synthetic Boundary', 'synthetic-boundary', $manager);
        $myCompany = $this->company($mine, 'My Boundary Client', 'my-boundary-client');
        $myProject = $this->project($mine, $myCompany, 'My Boundary Project');

        $foreign = Workspace::query()->create(['name' => 'Foreign Boundary Tenant', 'slug' => 'foreign-boundary']);
        $foreignCompany = $this->company($foreign, 'Foreign Boundary Client', 'foreign-boundary-client');
        $foreignProject = $this->project($foreign, $foreignCompany, 'Foreign Boundary Project');

        $foreignMember = User::factory()->create();
        $foreign->memberships()->create(['user_id' => $foreignMember->id, 'role' => 'member']);

        $myMember = User::factory()->create();
        $mine->memberships()->create(['user_id' => $myMember->id, 'role' => 'member']);

        $attempts = [
            // Their company and project, through my workspace.
            ["/workspaces/{$mine->public_id}/clients/{$foreignCompany->public_id}/projects/{$foreignProject->public_id}/access", $myMember],
            // My company, their project.
            ["/workspaces/{$mine->public_id}/clients/{$myCompany->public_id}/projects/{$foreignProject->public_id}/access", $myMember],
            // My company and project, their member.
            ["/workspaces/{$mine->public_id}/clients/{$myCompany->public_id}/projects/{$myProject->public_id}/access", $foreignMember],
        ];

        foreach ($attempts as [$path, $target]) {
            $this->actingAs($manager)
                ->put($path, ['user' => $target->public_id, 'role' => 'contributor'])
                ->assertNotFound();
        }

        // The refusal is not the property - the absence is.
        $this->assertSame(0, ClientProjectMembership::query()
            ->whereIn('client_project_id', [$foreignProject->id, $myProject->id])
            ->whereIn('user_id', [$foreignMember->id, $myMember->id])
            ->count());
    }

    /**
     * An admin cannot pre-seed a project role that outlives their own.
     *
     * A membership row for an owner or admin does nothing while they hold
     * workspace-wide access, because the role lookup short-circuits before
     * reading it - but it survives a demotion to `member`, and the rule then
     * honours it. That is privilege that persists precisely because nothing
     * looks at it on the way down.
     */
    public function test_an_admin_cannot_be_granted_a_project_role(): void
    {
        $manager = User::factory()->create();
        $workspace = $this->workspace('Synthetic Seed', 'synthetic-seed', $manager);
        $company = $this->company($workspace, 'Synthetic Seed Client', 'seed-client');
        $project = $this->project($workspace, $company, 'Synthetic Seed Project');

        $admin = User::factory()->create();
        $workspace->memberships()->create(['user_id' => $admin->id, 'role' => 'admin']);

        $this->actingAs($manager)
            ->put("/workspaces/{$workspace->public_id}/clients/{$company->public_id}/projects/{$project->public_id}/access", [
                'user' => $admin->public_id,
                'role' => 'owner',
            ])
            ->assertStatus(422);

        $this->assertSame(0, ClientProjectMembership::query()
            ->where('user_id', $admin->id)
            ->count());

        // And the Manage payload does not carry them either way.
        $this->actingAs($manager)
            ->get("/workspaces/{$workspace->public_id}/clients/{$company->public_id}/settings")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('assignable', 0)
                ->has('projects.0.members', 0));
    }

    /**
     * Clearing a billing address is a thing an operator does.
     *
     * The null comes from Laravel's `ConvertEmptyStringsToNull`, not from the
     * controller - a mutation check proved that, by removing the controller's
     * own empty-string guard and watching this test still pass. The guard was
     * dead code and is gone.
     *
     * The test stays, and is the more valuable half: it pins the behaviour
     * end to end rather than one line's implementation of it, so it still
     * fails if that middleware is ever removed from the stack. What matters is
     * that "no billing email" is null rather than a blank string every
     * `!== null` check downstream would accept.
     */
    public function test_clearing_a_billing_email_stores_null_rather_than_an_empty_string(): void
    {
        $manager = User::factory()->create();
        $workspace = $this->workspace('Synthetic Clear', 'synthetic-clear', $manager);
        $company = $this->company($workspace, 'Synthetic Clear Client', 'synthetic-clear-client');
        $company->forceFill(['billing_email' => 'billing@synthetic.test'])->save();

        $this->actingAs($manager)
            ->patch("/workspaces/{$workspace->public_id}/clients/{$company->public_id}", [
                'name' => 'Synthetic Clear Client',
                'billing_email' => '',
                'is_active' => true,
            ])
            ->assertRedirect();

        $this->assertNull($company->fresh()?->billing_email);
    }

    /**
     * Hiding a project from a client must not be undone by omission.
     *
     * `is_visible_to_client` is `required` rather than `sometimes` precisely so
     * a form that forgets the checkbox cannot silently re-expose a project
     * someone hid. That is a disclosure decision, so the refusal is asserted
     * rather than trusted to the rule string.
     */
    public function test_a_project_update_that_omits_visibility_is_refused(): void
    {
        $manager = User::factory()->create();
        $workspace = $this->workspace('Synthetic Omit', 'synthetic-omit', $manager);
        $company = $this->company($workspace, 'Synthetic Omit Client', 'omit-client');
        $project = $this->project($workspace, $company, 'Synthetic Omit Project');
        $project->forceFill(['is_visible_to_client' => false])->save();

        $this->actingAs($manager)
            ->patch("/workspaces/{$workspace->public_id}/clients/{$company->public_id}/projects/{$project->public_id}", [
                'name' => 'Synthetic Omit Project',
                'description' => null,
                'repository' => null,
                'status' => 'active',
            ])
            ->assertSessionHasErrors('is_visible_to_client');

        $this->assertFalse((bool) $project->fresh()?->is_visible_to_client);
    }

    /**
     * A client of another workspace cannot be edited through one you manage.
     *
     * Companies bind by a public id unique across every workspace, so passing
     * the `manage` gate on your own workspace is not passing a check on the
     * company named in the URL. The read paths assert this; the write path did
     * not.
     */
    public function test_a_company_from_another_workspace_cannot_be_edited(): void
    {
        $manager = User::factory()->create();
        $mine = $this->workspace('Synthetic Mine Write', 'synthetic-mine-write', $manager);

        $foreign = Workspace::query()->create(['name' => 'Foreign Write Tenant', 'slug' => 'foreign-write']);
        $foreignCompany = $this->company($foreign, 'Foreign Write Client', 'foreign-write-client');

        $this->actingAs($manager)
            ->patch("/workspaces/{$mine->public_id}/clients/{$foreignCompany->public_id}", [
                'name' => 'Renamed Across Tenants',
                'billing_email' => null,
                'is_active' => true,
            ])
            ->assertNotFound();

        $this->assertSame('Foreign Write Client', $foreignCompany->fresh()?->name);
    }

    /**
     * The update writes the named columns and nothing else.
     *
     * Both controllers pass an explicit array rather than `$request->all()`,
     * so a payload naming `workspace_id` should be ignored. Asserted because
     * the consequence - a client moving tenant on a rename - is exactly the
     * kind of thing the composite keys exist to make unrepresentable and the
     * application should never attempt.
     */
    public function test_an_update_cannot_move_a_client_to_another_workspace(): void
    {
        $manager = User::factory()->create();
        $mine = $this->workspace('Synthetic Anchor', 'synthetic-anchor', $manager);
        $company = $this->company($mine, 'Synthetic Anchor Client', 'anchor-client');

        $foreign = Workspace::query()->create(['name' => 'Foreign Anchor Tenant', 'slug' => 'foreign-anchor']);

        $this->actingAs($manager)
            ->patch("/workspaces/{$mine->public_id}/clients/{$company->public_id}", [
                'name' => 'Synthetic Anchor Client',
                'billing_email' => null,
                'is_active' => true,
                'workspace_id' => $foreign->id,
            ])
            ->assertRedirect();

        $this->assertSame((int) $mine->id, (int) $company->fresh()?->workspace_id);
    }

    /**
     * An omitted billing address is not an instruction to erase one.
     *
     * `nullable` says an empty value is legal. It says nothing about an absent
     * key, so a PATCH that supplied only `name` and `is_active` validated, and
     * `validated('billing_email')` returned the same null an explicit clear
     * produces - the controller could not tell the two apart and wrote over a
     * real address. `present` separates them, and this asserts the separation
     * rather than the rule, because the rule reads correct either way.
     */
    public function test_a_billing_address_survives_a_payload_that_omits_it(): void
    {
        $manager = User::factory()->create();
        $workspace = $this->workspace('Synthetic Keep', 'synthetic-keep', $manager);
        $company = $this->company($workspace, 'Synthetic Keep Client', 'synthetic-keep-client');
        $company->forceFill(['billing_email' => 'billing@synthetic.test'])->save();

        $this->actingAs($manager)
            ->patch("/workspaces/{$workspace->public_id}/clients/{$company->public_id}", [
                'name' => 'Synthetic Keep Client',
                'is_active' => true,
            ])
            ->assertSessionHasErrors('billing_email');

        $this->assertSame('billing@synthetic.test', $company->fresh()?->billing_email);
    }

    /**
     * The same distinction on a project's description.
     *
     * Asserted separately rather than trusted to the shared reasoning: the two
     * requests are different classes, and the rule was wrong in both of them
     * for the same reason, which is exactly the pattern a single test lets
     * survive on one side.
     */
    public function test_a_description_survives_a_payload_that_omits_it(): void
    {
        $manager = User::factory()->create();
        $workspace = $this->workspace('Synthetic Desc', 'synthetic-desc', $manager);
        $company = $this->company($workspace, 'Synthetic Desc Client', 'desc-client');
        $project = $this->project($workspace, $company, 'Synthetic Desc Project');
        $project->forceFill(['description' => 'Original scope of work.'])->save();

        $this->actingAs($manager)
            ->patch("/workspaces/{$workspace->public_id}/clients/{$company->public_id}/projects/{$project->public_id}", [
                'name' => 'Renamed Project',
                'repository' => null,
                'status' => 'active',
                'is_visible_to_client' => true,
                'lock_version' => (int) $project->fresh()?->lock_version,
            ])
            ->assertSessionHasErrors('description');

        $this->assertSame('Original scope of work.', $project->fresh()?->description);
    }

    /**
     * A form composed before someone hid a project cannot un-hide it.
     *
     * Two managers with the Manage page open. One hides a project; the other
     * submits a form loaded earlier, meaning only to rename it. Every field in
     * that payload validates - it is not malformed, it is out of date - and its
     * stale `is_visible_to_client: true` silently re-exposed the project to the
     * client. The version the form was rendered from is the only thing that
     * distinguishes the two, so the save is refused whole rather than merged
     * field by field.
     */
    public function test_a_stale_project_form_cannot_re_expose_a_hidden_project(): void
    {
        $manager = User::factory()->create();
        $workspace = $this->workspace('Synthetic Stale', 'synthetic-stale', $manager);
        $company = $this->company($workspace, 'Synthetic Stale Client', 'stale-client');
        $project = $this->project($workspace, $company, 'Synthetic Stale Project');

        // What the second manager's browser is holding.
        $staleVersion = (int) $project->fresh()?->lock_version;

        // The first manager hides it, which advances the version.
        $this->actingAs($manager)
            ->patch("/workspaces/{$workspace->public_id}/clients/{$company->public_id}/projects/{$project->public_id}", [
                'name' => 'Synthetic Stale Project',
                'description' => null,
                'repository' => null,
                'status' => 'active',
                'is_visible_to_client' => false,
                'lock_version' => $staleVersion,
            ])
            ->assertRedirect();

        $this->assertFalse((bool) $project->fresh()?->is_visible_to_client);
        $this->assertNotSame($staleVersion, (int) $project->fresh()?->lock_version);

        // The second manager saves a rename from the older form.
        $this->actingAs($manager)
            ->patch("/workspaces/{$workspace->public_id}/clients/{$company->public_id}/projects/{$project->public_id}", [
                'name' => 'Renamed From A Stale Form',
                'description' => null,
                'repository' => null,
                'status' => 'active',
                'is_visible_to_client' => true,
                'lock_version' => $staleVersion,
            ])
            ->assertSessionHasErrors('lock_version');

        // Neither the disclosure nor the rename lands: the save is refused as a
        // whole, so the operator reloads and decides again with current values.
        $fresh = $project->fresh();
        $this->assertFalse((bool) $fresh?->is_visible_to_client);
        $this->assertSame('Synthetic Stale Project', $fresh?->name);
    }

    /**
     * A company reparented between the authorization and the write is not
     * written.
     *
     * `assertOwnedBy` describes the instance the router bound, and the router
     * binds by public id alone. The write that followed named only the primary
     * key, so the two were separate statements about a row nothing held still
     * in between - reparent it after the check and a request authorized against
     * one tenant edits a row now owned by another.
     *
     * The race is made deterministic with `beforeExecuting`, which lands the
     * reparent in the same gap a concurrent request would occupy: after the
     * controller has authorized, before its first statement runs. The reparent
     * is fired once and on its own connection callback guard, because it issues
     * queries itself and would otherwise recurse.
     */
    public function test_a_company_reparented_after_authorization_is_not_edited(): void
    {
        $manager = User::factory()->create();
        $mine = $this->workspace('Synthetic Race', 'synthetic-race', $manager);
        $company = $this->company($mine, 'Synthetic Race Client', 'race-client');
        $foreign = Workspace::query()->create(['name' => 'Foreign Race Tenant', 'slug' => 'foreign-race']);

        $reparented = false;
        DB::connection()->beforeExecuting(function () use (&$reparented, $company, $foreign): void {
            if ($reparented) {
                return;
            }

            $reparented = true;
            DB::table('client_companies')
                ->where('id', $company->id)
                ->update(['workspace_id' => $foreign->id]);
        });

        $this->actingAs($manager)
            ->patch("/workspaces/{$mine->public_id}/clients/{$company->public_id}", [
                'name' => 'Renamed Mid Race',
                'billing_email' => null,
                'is_active' => true,
            ])
            ->assertNotFound();

        // The row now belongs to the other tenant and still carries its own
        // name: the request was refused rather than applied to a record it was
        // never authorized for.
        $fresh = $company->fresh();
        $this->assertSame((int) $foreign->id, (int) $fresh?->workspace_id);
        $this->assertSame('Synthetic Race Client', $fresh?->name);
    }

    private function workspace(string $name, string $slug, User $member): Workspace
    {
        $workspace = Workspace::query()->create(['name' => $name, 'slug' => $slug]);
        $workspace->memberships()->create(['user_id' => $member->id, 'role' => 'admin']);

        return $workspace;
    }

    private function company(Workspace $workspace, string $name, string $slug): ClientCompany
    {
        return ClientCompany::query()->create([
            'workspace_id' => $workspace->id,
            'name' => $name,
            'slug' => $slug.'-'.$workspace->id,
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

    /** @param array<string, mixed> $attributes */
    private function agreement(Workspace $workspace, ClientCompany $company, array $attributes): ClientAgreement
    {
        return ClientAgreement::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'currency' => 'USD',
            // Before the spread, so a caller that cares still names its own.
            // `starts_on` is NOT NULL since #147 and most of these fixtures are
            // about listing and visibility rather than about a term.
            'starts_on' => '2024-01-01',
            ...$attributes,
        ]);
    }

    private function invoice(Workspace $workspace, ClientCompany $company, string $number, string $status): ClientInvoice
    {
        return ClientInvoice::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'invoice_number' => $number,
            'status' => $status,
            'currency' => 'USD',
            'issue_date' => '2026-08-01',
            'subtotal_amount' => 10000,
            'tax_amount' => 0,
            'total_amount' => 10000,
            'paid_amount' => 0,
            'balance_amount' => 10000,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function timeEntry(
        Workspace $workspace,
        ClientCompany $company,
        ClientProject $project,
        array $attributes,
        ?User $user = null,
    ): ClientTimeEntry {
        $user ??= User::factory()->create();

        return ClientTimeEntry::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'client_project_id' => $project->id,
            'user_id' => $user->id,
            'description' => 'Synthetic directory work',
            'is_billable' => true,
            'is_deferred' => false,
            ...$attributes,
        ]);
    }
}
