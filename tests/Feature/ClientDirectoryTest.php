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
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $this->agreement($workspace, $company, [
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
            ->get("/workspaces/{$workspace->public_id}/clients/{$company->public_id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('agreements.0.is_recurring', false)
                ->where('agreements.0.retainer_minutes_per_period', null));
    }

    public function test_the_detail_screen_shows_the_company_its_projects_agreements_and_invoices(): void
    {
        $manager = User::factory()->create();
        $workspace = $this->workspace('Synthetic Detail', 'synthetic-detail', $manager);
        $company = $this->company($workspace, 'Synthetic Client', 'synthetic-client');
        $project = $this->project($workspace, $company, 'Synthetic Project');
        $this->agreement($workspace, $company, [
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

        $this->actingAs($manager)
            ->get("/workspaces/{$workspace->public_id}/clients/{$company->public_id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('clients/show')
                ->where('company.id', $company->public_id)
                ->where('company.name', 'Synthetic Client')
                ->has('projects', 1)
                ->where('projects.0.name', 'Synthetic Project')
                ->has('agreements', 1)
                ->where('agreements.0.billing_cadence', 'quarterly')
                ->where('agreements.0.is_recurring', true)
                ->where('agreements.0.starts_on', '2026-01-01')
                ->where('agreements.0.ends_on', '2026-12-31')
                // Quarterly: three months of the monthly retainer.
                ->where('agreements.0.retainer_minutes_per_period', 1800)
                ->where('agreements.0.retainer_amount_per_period', 1500000)
                ->where('agreements.0.project', 'Synthetic Project')
                ->has('invoices', 1)
                ->where('invoices.0.invoice_number', 'SYN-DETAIL-1'));
    }

    public function test_the_detail_screen_bounds_the_invoice_list(): void
    {
        $manager = User::factory()->create();
        $workspace = $this->workspace('Synthetic Bounded', 'synthetic-bounded', $manager);
        $company = $this->company($workspace, 'Synthetic Client', 'synthetic-client');

        foreach (range(1, 25) as $number) {
            $this->invoice($workspace, $company, 'SYN-BOUND-'.$number, 'issued');
        }

        $this->actingAs($manager)
            ->get("/workspaces/{$workspace->public_id}/clients/{$company->public_id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('invoice_limit', 20)
                ->has('invoices', 20));
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
            ->has('projects', 1)
            ->has('agreements', 2)
            ->has('invoices', 1)
            ->where('agreements.0.title', 'Synthetic Cross Scoped Agreement')
            ->where('agreements.0.project', null));

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
        unset($props['clientContext']);

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

        // The detail screen narrows the same way, so reaching one project of a
        // client does not disclose the rest of that client's portfolio.
        $detail = $this->actingAs($member)
            ->get("/workspaces/{$workspace->public_id}/clients/{$mine->public_id}")
            ->assertOk();

        $detail->assertInertia(fn (Assert $page) => $page->has('projects', 1));
        $this->assertInertiaPayloadOmits(
            $detail,
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
            ->assertInertia(fn (Assert $page) => $page->where('clientContext.can_manage', false));

        $this->actingAs($member)
            ->get("/workspaces/{$workspace->public_id}/clients/{$company->public_id}/manage")
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
                'status' => 'archived',
                'is_visible_to_client' => false,
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
                'status' => 'active',
                'is_visible_to_client' => true,
            ])
            ->assertNotFound();

        $this->assertSame('Owned Edit Project', $project->fresh()?->name);
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
