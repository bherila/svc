<?php

namespace Tests\Feature\Revenue;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientCompanyActivity;
use App\Models\ClientProject;
use App\Models\ClientProjectMembership;
use App\Models\ClientProposal;
use App\Models\User;
use App\Services\Revenue\RevenueReadService;
use App\Support\AgentApi\ProjectRole;
use App\Support\Revenue\RevenueQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\AssertsSurfaceIsolation;
use Tests\Concerns\BuildsRevenueFixtures;
use Tests\Concerns\WritesLegacyCrossTenantRows;
use Tests\TestCase;

/**
 * The Dashboard's revenue overview, the client roster and the client overview
 * (docs/ux-overhaul.md sections 2 to 4): the figures are the revenue read
 * model's, nothing is shown to anyone but owners and admins, and nothing of
 * another workspace appears. The synthetic workspace is as of 2026-11-06.
 */
final class RevenuePagesTest extends TestCase
{
    use AssertsSurfaceIsolation;
    use BuildsRevenueFixtures;
    use RefreshDatabase;
    use WritesLegacyCrossTenantRows;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-06 12:00:00');
    }

    /** @return array<string, string> */
    private function partial(string $component, string $data): array
    {
        return [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(Request::create('/')) ?? '',
            'X-Inertia-Partial-Component' => $component,
            'X-Inertia-Partial-Data' => $data,
        ];
    }

    private function member(array $fixture): User
    {
        $member = User::factory()->create();
        $fixture['workspace']->memberships()->create(['user_id' => $member->id, 'role' => 'member']);

        return $member;
    }

    // ---- Dashboard ----

    public function test_an_owner_gets_the_key_figures_the_chart_and_the_agreements_at_risk(): void
    {
        $mine = $this->revenueWorkspace('mine');
        $path = "/workspaces/{$mine['workspace']->public_id}/dashboard";

        $this->actingAs($mine['owner'])->get($path)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('workspaces/dashboard')
            ->where('revenue.toggle_href', $path)
            ->where('revenue.filters', ['basis' => 'invoiced', 'expenses' => true, 'overage' => false])
            ->where('revenue.assumptions.as_of', '2026-11-06')
            ->where('revenue.assumptions.projection_basis', 'invoiced')
            ->has('revenue.kpis.ytd.amount')
            ->where('revenue.kpis.ytd.change_percent', null)
            ->has('revenue.months', 25)
            ->where('revenue.months.0.month', '2025-11')
            ->where('revenue.months.12.kind', 'current')
            ->where('revenue.months.12.href', route('workspaces.invoices', [$mine['workspace'], 'month' => '2026-11'], absolute: false))
            ->where('revenue.at_risk.agreements.0.client_name', 'Synthetic Gamma mine')
            ->where('revenue.at_risk.agreements.0.ends_on', '2026-12-15')
            ->where('revenue.at_risk.agreements.0.monthly_amount', 50000)
            ->where('revenue.at_risk.monthly_total', 50000));
    }

    public function test_the_dashboard_reads_the_same_figures_as_the_planning_reads(): void
    {
        $mine = $this->revenueWorkspace('mine');
        $read = app(RevenueReadService::class)->summary($mine['owner'], $mine['workspace'], new RevenueQuery);
        $props = $this->actingAs($mine['owner'])->get("/workspaces/{$mine['workspace']->public_id}/dashboard")->assertOk()->viewData('page')['props']['revenue'];

        $this->assertSame($read['ytd']['amount'], $props['kpis']['ytd']['amount']);
        $this->assertSame($read['ttm']['prior_amount'], $props['kpis']['ttm']['prior_amount']);
        $this->assertSame($read['next_12_months']['contracted'], $props['kpis']['next_12_months']['contracted']);
        $this->assertSame($read['open_receivables'], $props['kpis']['open_receivables']);
        $this->assertGreaterThan(0, $props['kpis']['ytd']['amount']);
    }

    public function test_the_toggles_are_asked_of_the_server_and_change_the_figures(): void
    {
        $mine = $this->revenueWorkspace('mine');
        $path = "/workspaces/{$mine['workspace']->public_id}/dashboard";
        $figures = fn (string $query): array => $this->actingAs($mine['owner'])->get($path.$query)->assertOk()->viewData('page')['props']['revenue'];

        $default = $figures('');
        $collected = $figures('?basis=collected');
        $noExpenses = $figures('?expenses=0');
        $overage = $figures('?overage=1');

        $this->assertSame('collected', $collected['filters']['basis']);
        $this->assertNotSame($default['kpis']['ytd']['amount'], $collected['kpis']['ytd']['amount']);
        $this->assertFalse($noExpenses['filters']['expenses']);
        $this->assertSame($default['kpis']['ytd']['amount'] - 5000, $noExpenses['kpis']['ytd']['amount']);
        $this->assertNull($default['kpis']['next_12_months']['projected_overage']);
        $this->assertNotNull($overage['kpis']['next_12_months']['projected_overage']);
        $this->assertSame('invoiced', $collected['assumptions']['projection_basis'], 'The forecast stays on the invoiced basis.');
        $this->assertSame($default['filters'], $figures('?basis=bogus&expenses=maybe&overage[]=1')['filters'], 'Unreadable toggles take the defaults.');
    }

    public function test_a_toggle_reload_asks_for_the_revenue_alone(): void
    {
        $mine = $this->revenueWorkspace('mine');

        $props = $this->actingAs($mine['owner'])
            ->withHeaders($this->partial('workspaces/dashboard', 'revenue'))
            ->get("/workspaces/{$mine['workspace']->public_id}/dashboard?basis=collected")
            ->assertOk()->json('props');

        $this->assertArrayHasKey('revenue', $props);
        $this->assertArrayNotHasKey('queue', $props);
        $this->assertSame('collected', $props['revenue']['filters']['basis']);
    }

    public function test_a_member_who_is_not_an_owner_or_admin_gets_no_revenue_even_when_asking_for_it(): void
    {
        $mine = $this->revenueWorkspace('mine');
        $member = $this->member($mine);
        $path = "/workspaces/{$mine['workspace']->public_id}/dashboard";

        $this->actingAs($member)->get($path)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->missing('revenue'));

        $response = $this->actingAs($member)->withHeaders($this->partial('workspaces/dashboard', 'revenue'))->get($path.'?basis=collected')->assertOk();
        $this->assertArrayNotHasKey('revenue', $response->json('props'));
        foreach (['Synthetic Alpha mine', 'Synthetic Gamma mine', '"ytd"', 'at_risk'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, (string) $response->getContent());
        }
    }

    public function test_another_workspaces_revenue_never_reaches_the_dashboard(): void
    {
        $mine = $this->revenueWorkspace('mine');
        $theirs = $this->revenueWorkspace('theirs', 7);

        $response = $this->actingAs($mine['owner'])->get("/workspaces/{$mine['workspace']->public_id}/dashboard")->assertOk();

        $this->assertInertiaPayloadOmits($response, ['theirs', $theirs['workspace']->public_id, $theirs['alpha']->public_id, '700000'], 'Synthetic Alpha mine');
        $this->actingAs($mine['owner'])->get("/workspaces/{$theirs['workspace']->public_id}/dashboard")->assertForbidden();
    }

    public function test_the_dashboard_does_not_query_once_per_row(): void
    {
        $mine = $this->revenueWorkspace('mine');
        $n = 0;

        $this->assertQueryCountIndependentOfRows(
            fn () => $this->actingAs($mine['owner'])->get("/workspaces/{$mine['workspace']->public_id}/dashboard")->assertOk(),
            function () use ($mine, &$n): void {
                for ($i = 0; $i < 6; $i++) {
                    $n++;
                    $company = ClientCompany::query()->create(['workspace_id' => $mine['workspace']->id, 'name' => "Bulk Client {$n}", 'slug' => "bulk-client-{$n}"]);
                    ClientAgreement::query()->create([
                        'workspace_id' => $mine['workspace']->id, 'client_company_id' => $company->id, 'title' => "Bulk {$n}", 'status' => 'active', 'currency' => 'USD',
                        'starts_on' => '2026-01-01', 'ends_on' => '2027-03-01', 'billing_cadence' => 'monthly', 'retainer_amount' => 10000, 'signed_at' => '2026-01-01 09:00:00',
                    ]);
                }
            },
        );
    }

    public function test_a_chart_bar_filters_the_workspace_invoices_to_its_month(): void
    {
        $mine = $this->revenueWorkspace('mine');
        $theirs = $this->revenueWorkspace('theirs');
        $path = "/workspaces/{$mine['workspace']->public_id}/all/invoices";

        $october = $this->actingAs($mine['owner'])->get($path.'?month=2026-10')->assertOk()->viewData('page')['props'];
        $this->assertSame('2026-10', $october['month']);
        $this->assertSame($path, $october['clear_month_href']);
        $this->assertContains($mine['october']->public_id, array_column($october['invoices'], 'id'));
        $this->assertNotContains('2026-09-01', array_column($october['invoices'], 'issue_date'), 'The void invoice of September is not an October invoice.');

        $all = $this->actingAs($mine['owner'])->get($path)->assertOk()->viewData('page')['props'];
        $this->assertNull($all['month']);
        $this->assertGreaterThan(count($october['invoices']), count($all['invoices']));

        $mangled = $this->actingAs($mine['owner'])->get($path.'?month=2026-13')->assertOk()->viewData('page')['props'];
        $this->assertNull($mangled['month']);
        $this->assertSame(count($all['invoices']), count($mangled['invoices']), 'A month that is not one filters nothing.');
        $this->assertStringNotContainsString($theirs['october']->invoice_number, (string) json_encode($october));
    }

    // ---- Client roster ----

    public function test_an_owner_gets_the_revenue_roster_with_the_server_filtered_totals(): void
    {
        $mine = $this->revenueWorkspace('mine');
        $path = "/workspaces/{$mine['workspace']->public_id}/clients";

        $this->actingAs($mine['owner'])->get($path)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('clients/index')
            ->where('roster.href', $path)
            ->where('roster.filters', ['q' => '', 'filter' => 'all', 'sort' => 'name', 'dir' => 'asc'])
            ->where('roster.total_count', 3)
            ->has('roster.clients', 3)
            ->where('roster.clients.0.name', 'Synthetic Alpha mine')
            ->where('roster.clients.0.href', "{$path}/{$mine['alpha']->public_id}")
            ->has('roster.clients.0.ttm.series', 12)
            ->where('roster.clients.2.ending_soon', true)
            ->has('roster.totals.mrr')
            ->missing('health'));

        $filtered = $this->actingAs($mine['owner'])->get($path.'?filter=ending_soon&q=gamma&sort=mrr&dir=desc')->assertOk()->viewData('page')['props']['roster'];
        $this->assertSame(['Synthetic Gamma mine'], array_column($filtered['clients'], 'name'));
        $this->assertSame($filtered['clients'][0]['mrr'], $filtered['totals']['mrr']);
        $this->assertSame(3, $filtered['total_count']);
    }

    public function test_the_retainer_meters_follow_the_first_paint_and_come_from_the_ledgers(): void
    {
        $mine = $this->revenueWorkspace('mine');
        $path = "/workspaces/{$mine['workspace']->public_id}/clients";

        $page = $this->actingAs($mine['owner'])->get($path)->assertOk()->viewData('page');
        $this->assertSame(['health'], $page['deferredProps']['default']);

        $health = $this->actingAs($mine['owner'])->withHeaders($this->partial('clients/index', 'health'))->get($path)->assertOk()->json('props.health');
        $this->assertSame('ok', $health[$mine['alpha']->public_id]['status']);
        $this->assertSame(600, $health[$mine['alpha']->public_id]['minutes_included']);
        $this->assertSame('none', $health[$mine['beta']->public_id]['status'], 'An agreement that has not started has no ledger period yet.');
        $this->assertArrayHasKey('over_retainer', $health[$mine['alpha']->public_id]);
    }

    public function test_filtering_to_over_retainer_reads_the_meters_up_front(): void
    {
        $mine = $this->revenueWorkspace('mine');

        $props = $this->actingAs($mine['owner'])->get("/workspaces/{$mine['workspace']->public_id}/clients?filter=over_retainer")->assertOk()->viewData('page');

        $this->assertArrayHasKey('health', $props['props']);
        $this->assertArrayNotHasKey('deferredProps', $props);
    }

    public function test_a_member_keeps_the_plain_list_and_sees_no_revenue_roster(): void
    {
        $mine = $this->revenueWorkspace('mine');
        $member = $this->member($mine);
        $project = ClientProject::query()->create(['workspace_id' => $mine['workspace']->id, 'client_company_id' => $mine['alpha']->id, 'name' => 'Synthetic Alpha project', 'status' => 'active']);
        ClientProjectMembership::query()->create(['workspace_id' => $mine['workspace']->id, 'client_project_id' => $project->id, 'user_id' => $member->id, 'role' => ProjectRole::Contributor->value]);

        $response = $this->actingAs($member)->get("/workspaces/{$mine['workspace']->public_id}/clients?filter=all")->assertOk();

        $response->assertInertia(fn (Assert $page) => $page->has('companies', 1)->missing('roster')->missing('health'));
        foreach (['"mrr"', '"ytd"', 'Synthetic Gamma mine', 'overdue_amount'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, (string) $response->getContent());
        }
    }

    public function test_another_workspaces_clients_never_reach_the_roster(): void
    {
        $mine = $this->revenueWorkspace('mine');
        $theirs = $this->revenueWorkspace('theirs', 7);

        $response = $this->actingAs($mine['owner'])->get("/workspaces/{$mine['workspace']->public_id}/clients")->assertOk();

        $this->assertInertiaPayloadOmits($response, ['theirs', $theirs['alpha']->public_id, '700000'], 'Synthetic Alpha mine');
    }

    public function test_the_roster_does_not_query_once_per_client_or_agreement(): void
    {
        $mine = $this->revenueWorkspace('mine');
        $n = 0;

        $this->assertQueryCountIndependentOfRows(
            fn () => $this->actingAs($mine['owner'])->get("/workspaces/{$mine['workspace']->public_id}/clients")->assertOk(),
            function () use ($mine, &$n): void {
                for ($i = 0; $i < 6; $i++) {
                    $n++;
                    $company = ClientCompany::query()->create(['workspace_id' => $mine['workspace']->id, 'name' => "Bulk Client {$n}", 'slug' => "bulk-client-{$n}"]);
                    ClientAgreement::query()->create([
                        'workspace_id' => $mine['workspace']->id, 'client_company_id' => $company->id, 'title' => "Bulk {$n}", 'status' => 'active', 'currency' => 'USD',
                        'starts_on' => '2026-01-01', 'billing_cadence' => 'monthly', 'retainer_amount' => 10000, 'retainer_minutes' => 600,
                    ]);
                }
            },
        );
    }

    // ---- Client overview ----

    private function overviewPath(array $fixture, ?ClientCompany $company = null): string
    {
        return "/workspaces/{$fixture['workspace']->public_id}/clients/".($company ?? $fixture['alpha'])->public_id;
    }

    public function test_an_owner_gets_the_client_overview(): void
    {
        $mine = $this->revenueWorkspace('mine');
        ClientProposal::query()->create(['workspace_id' => $mine['workspace']->id, 'client_company_id' => $mine['alpha']->id, 'title' => 'Synthetic proposal', 'currency' => 'USD', 'status' => 'draft']);
        ClientProject::query()->create(['workspace_id' => $mine['workspace']->id, 'client_company_id' => $mine['alpha']->id, 'name' => 'Synthetic Alpha project', 'status' => 'active']);
        $path = $this->overviewPath($mine);

        $this->actingAs($mine['owner'])->get($path)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('clients/home')
            ->where('overview.toggle_href', $path)
            ->where('overview.filters.basis', 'invoiced')
            ->where('overview.kpis.mrr', 100000 + 10000)
            ->has('overview.months', 25)
            ->where('overview.agreements.0.title', 'Synthetic Alpha retainer')
            ->where('overview.agreements.0.retainer.monthly_equivalent', 100000)
            ->where('overview.agreements.0.recurring_items.0.description', 'Synthetic hosting')
            ->where('overview.agreements.0.href', "{$path}/agreements/{$mine['agreement']->public_id}")
            ->has('overview.recent_invoices', 5)
            ->where('overview.projects.0.name', 'Synthetic Alpha project')
            ->where('overview.projects.0.log_time_href', fn (string $href): bool => str_starts_with($href, "{$path}/time?log_project="))
            ->where('overview.proposals.0.title', 'Synthetic proposal')
            ->where('overview.months.12.href', "{$path}/invoices?month=2026-11")
            ->missing('overview.this_period'));
    }

    /**
     * The client's activity feed lived on the retired Operations screen. It is
     * now the overview's, newest first and bounded at 100, with the actor's
     * name and the event's own subject and payload.
     */
    public function test_the_overview_carries_the_latest_hundred_events_newest_first(): void
    {
        $mine = $this->revenueWorkspace('mine');
        $workspace = $mine['workspace'];
        $company = $mine['alpha'];
        $owner = $mine['owner'];

        foreach (range(1, 101) as $eventNumber) {
            $activity = ClientCompanyActivity::query()->create([
                'workspace_id' => $workspace->id,
                'client_company_id' => $company->id,
                'actor_user_id' => $owner->id,
                'action' => $eventNumber === 101 ? 'agreement.transitioned' : 'invoice.generated',
                'subject_type' => $eventNumber === 101 ? 'client_agreement' : null,
                'subject_public_id' => $eventNumber === 101 ? '11111111-1111-4111-8111-111111111111' : null,
                'payload' => ['invoice_kind' => 'cadence_period'],
            ]);
            $activity->timestamps = false;
            $activity->forceFill([
                'created_at' => now()->subMinutes(101 - $eventNumber),
                'updated_at' => now()->subMinutes(101 - $eventNumber),
            ])->save();
        }

        // Another client's event in the same workspace is not this client's.
        ClientCompanyActivity::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $mine['beta']->id,
            'action' => 'beta.only',
            'payload' => ['note' => 'Synthetic beta only'],
        ]);

        $this->actingAs($owner)->get($this->overviewPath($mine))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('overview.activities', 100)
            ->where('overview.activities.0.action', 'agreement.transitioned')
            ->where('overview.activities.0.actor_name', $owner->name)
            ->where('overview.activities.0.subject_type', 'client_agreement')
            ->where('overview.activities.0.subject_id', '11111111-1111-4111-8111-111111111111')
            ->where('overview.activities.0.payload.invoice_kind', 'cadence_period')
            ->where('overview.activities', fn (Collection $activities): bool => $activities->pluck('action')->doesntContain('beta.only')));
    }

    /**
     * A correction link is offered to whoever holds the owner or admin role in
     * THIS workspace, and the overview is only built for them: a plain member
     * gets no feed at all, and losing the membership loses the page.
     */
    public function test_the_correction_link_follows_the_acting_membership_in_this_workspace(): void
    {
        $mine = $this->revenueWorkspace('mine');
        $person = User::factory()->create();
        $membership = $mine['workspace']->memberships()->create(['user_id' => $person->id, 'role' => 'member']);
        $project = ClientProject::query()->create(['workspace_id' => $mine['workspace']->id, 'client_company_id' => $mine['alpha']->id, 'name' => 'Synthetic Alpha project', 'status' => 'active']);
        ClientProjectMembership::query()->create(['workspace_id' => $mine['workspace']->id, 'client_project_id' => $project->id, 'user_id' => $person->id, 'role' => ProjectRole::Contributor->value]);
        $foreign = $this->revenueWorkspace('foreign-membership', 3);
        $foreign['workspace']->memberships()->create(['user_id' => $person->id, 'role' => 'owner']);
        $href = '/workspaces/'.$mine['workspace']->public_id.'/invoices/'.Str::uuid().'/corrections/'.Str::uuid();
        ClientCompanyActivity::query()->create([
            'workspace_id' => $mine['workspace']->id, 'client_company_id' => $mine['alpha']->id,
            'action' => 'invoice.corrected', 'payload' => ['correction_url' => $href],
        ]);
        $path = $this->overviewPath($mine);

        $this->actingAs($person)->get($path)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->missing('overview'));
        $this->assertStringNotContainsString($href, (string) $this->get($path)->getContent());
        $membership->forceFill(['role' => 'admin'])->save();
        $this->get($path)->assertOk()->assertInertia(fn (Assert $page) => $page->where('overview.activities.0.correction_url', $href));
        $membership->delete();
        $this->get($path)->assertForbidden();
    }

    /**
     * Nothing of another workspace reaches the feed, including a row written
     * under the other workspace that names THIS company (the pre-#113 shape):
     * only the workspace predicate keeps it out.
     */
    public function test_no_activity_of_another_workspace_reaches_the_overview(): void
    {
        $mine = $this->revenueWorkspace('mine');
        $theirs = $this->revenueWorkspace('theirs', 7);
        $foreignWorker = User::factory()->create(['name' => 'Foreign Worker Name']);
        ClientCompanyActivity::query()->create([
            'workspace_id' => $mine['workspace']->id, 'client_company_id' => $mine['alpha']->id,
            'action' => 'invoice.generated', 'payload' => ['note' => 'Visible Activity Payload'],
        ]);
        ClientCompanyActivity::query()->create([
            'workspace_id' => $theirs['workspace']->id, 'client_company_id' => $theirs['alpha']->id,
            'actor_user_id' => $foreignWorker->id, 'action' => 'invoice.generated', 'payload' => ['note' => 'Foreign Activity Payload'],
        ]);
        $this->writingLegacyCrossTenantRows(function () use ($mine, $theirs, $foreignWorker): void {
            ClientCompanyActivity::query()->create([
                'workspace_id' => $theirs['workspace']->id, 'client_company_id' => $mine['alpha']->id,
                'actor_user_id' => $foreignWorker->id, 'action' => 'invoice.generated', 'payload' => ['note' => 'Mixed Tenant Activity Payload'],
            ]);
        });

        $response = $this->actingAs($mine['owner'])->get($this->overviewPath($mine))->assertOk();

        $this->assertInertiaPayloadOmits($response, ['Foreign Activity Payload', 'Mixed Tenant Activity Payload', 'Foreign Worker Name'], 'Visible Activity Payload');
    }

    public function test_the_overview_names_only_columns_that_exist(): void
    {
        $mine = $this->revenueWorkspace('mine');
        ClientCompanyActivity::query()->create([
            'workspace_id' => $mine['workspace']->id, 'client_company_id' => $mine['alpha']->id,
            'actor_user_id' => $mine['owner']->id, 'action' => 'invoice.generated', 'payload' => ['invoice_kind' => 'cadence_period'],
        ]);

        $this->assertQueriesNameOnlyRealIdentifiers(
            fn () => $this->actingAs($mine['owner'])->get($this->overviewPath($mine))->assertOk(),
        );
    }

    public function test_the_overview_figures_are_this_clients_alone(): void
    {
        $mine = $this->revenueWorkspace('mine');
        $read = app(RevenueReadService::class)->summary($mine['owner'], $mine['workspace'], new RevenueQuery(clientId: $mine['alpha']->public_id));
        $gamma = $this->actingAs($mine['owner'])->get($this->overviewPath($mine, $mine['gamma']))->assertOk()->viewData('page')['props']['overview'];
        $alpha = $this->actingAs($mine['owner'])->get($this->overviewPath($mine))->assertOk()->viewData('page')['props']['overview'];

        $this->assertSame($read['ytd']['amount'], $alpha['kpis']['ytd']['amount']);
        $this->assertSame(0, $gamma['kpis']['ytd']['amount']);
        $this->assertSame(50000, $gamma['kpis']['mrr']);
        $this->assertSame([], $gamma['recent_invoices']);
        $this->assertSame('Synthetic Gamma retainer', $gamma['agreements'][0]['title']);
        $this->assertStringNotContainsString('Alpha', (string) json_encode($gamma));
    }

    public function test_this_periods_retainer_use_follows_the_first_paint(): void
    {
        $mine = $this->revenueWorkspace('mine');
        $path = $this->overviewPath($mine);

        $page = $this->actingAs($mine['owner'])->get($path)->assertOk()->viewData('page');
        $this->assertSame(['this_period'], $page['deferredProps']['default']);

        $period = $this->actingAs($mine['owner'])->withHeaders($this->partial('clients/home', 'this_period'))->get($path)->assertOk()->json('props.this_period');
        $this->assertCount(1, $period);
        $this->assertSame('ok', $period[0]['ledger_status']);
        $this->assertSame(600, $period[0]['minutes_included']);
        $this->assertSame("{$path}/agreements/{$mine['agreement']->public_id}", $period[0]['href']);
    }

    public function test_a_member_gets_the_plain_client_home_and_no_revenue(): void
    {
        $mine = $this->revenueWorkspace('mine');
        $member = $this->member($mine);
        $project = ClientProject::query()->create(['workspace_id' => $mine['workspace']->id, 'client_company_id' => $mine['alpha']->id, 'name' => 'Synthetic Alpha project', 'status' => 'active']);
        ClientProjectMembership::query()->create(['workspace_id' => $mine['workspace']->id, 'client_project_id' => $project->id, 'user_id' => $member->id, 'role' => ProjectRole::Contributor->value]);

        $response = $this->actingAs($member)->get($this->overviewPath($mine))->assertOk();

        $response->assertInertia(fn (Assert $page) => $page->component('clients/home')->missing('overview')->missing('this_period'));
        $this->assertArrayNotHasKey('deferredProps', $response->viewData('page'));
        $this->assertStringNotContainsString('monthly_equivalent', (string) $response->getContent());
    }

    public function test_a_client_of_another_workspace_has_no_overview(): void
    {
        $mine = $this->revenueWorkspace('mine');
        $theirs = $this->revenueWorkspace('theirs', 7);

        $this->actingAs($mine['owner'])->get("/workspaces/{$mine['workspace']->public_id}/clients/{$theirs['alpha']->public_id}")->assertNotFound();
        $this->actingAs($mine['owner'])->get("/workspaces/{$theirs['workspace']->public_id}/clients/{$theirs['alpha']->public_id}")->assertForbidden();
    }

    public function test_the_overview_does_not_query_once_per_row(): void
    {
        $mine = $this->revenueWorkspace('mine');
        // A client with no project skips the project reads altogether; start from one that has it.
        ClientProject::query()->create(['workspace_id' => $mine['workspace']->id, 'client_company_id' => $mine['alpha']->id, 'name' => 'Synthetic first project', 'status' => 'active']);
        ClientCompanyActivity::query()->create([
            'workspace_id' => $mine['workspace']->id, 'client_company_id' => $mine['alpha']->id,
            'actor_user_id' => $mine['owner']->id, 'action' => 'invoice.generated', 'payload' => ['invoice_kind' => 'cadence_period'],
        ]);
        $n = 0;

        $this->assertQueryCountIndependentOfRows(
            fn () => $this->actingAs($mine['owner'])->get($this->overviewPath($mine))->assertOk(),
            function () use ($mine, &$n): void {
                for ($i = 0; $i < 6; $i++) {
                    $n++;
                    ClientProject::query()->create(['workspace_id' => $mine['workspace']->id, 'client_company_id' => $mine['alpha']->id, 'name' => "Bulk project {$n}", 'status' => 'active']);
                    ClientCompanyActivity::query()->create([
                        'workspace_id' => $mine['workspace']->id, 'client_company_id' => $mine['alpha']->id,
                        'actor_user_id' => $mine['owner']->id, 'action' => 'invoice.generated', 'payload' => ['invoice_kind' => 'cadence_period'],
                    ]);
                    ClientAgreement::query()->create([
                        'workspace_id' => $mine['workspace']->id, 'client_company_id' => $mine['alpha']->id, 'title' => "Bulk {$n}", 'status' => 'active', 'currency' => 'USD',
                        'starts_on' => '2026-01-01', 'billing_cadence' => 'monthly', 'retainer_amount' => 10000, 'retainer_minutes' => 600,
                    ]);
                }
            },
        );
    }

    public function test_a_client_invoice_tab_filters_to_a_month(): void
    {
        $mine = $this->revenueWorkspace('mine');
        $path = $this->overviewPath($mine).'/invoices';

        $props = $this->actingAs($mine['owner'])->get($path.'?month=2026-10')->assertOk()->viewData('page')['props'];

        $this->assertSame('2026-10', $props['month']);
        $this->assertSame($path, $props['clear_month_href']);
        $this->assertContains($mine['october']->public_id, array_column($props['invoices'], 'id'));
        $this->assertLessThan(count($this->actingAs($mine['owner'])->get($path)->viewData('page')['props']['invoices']), count($props['invoices']));
    }
}
