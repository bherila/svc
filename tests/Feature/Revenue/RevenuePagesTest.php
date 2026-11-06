<?php

namespace Tests\Feature\Revenue;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientProject;
use App\Models\ClientProjectMembership;
use App\Models\User;
use App\Services\Revenue\RevenueReadService;
use App\Support\AgentApi\ProjectRole;
use App\Support\Revenue\RevenueQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\AssertsSurfaceIsolation;
use Tests\Concerns\BuildsRevenueFixtures;
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

    public function test_a_client_invoice_tab_filters_to_a_month(): void
    {
        $mine = $this->revenueWorkspace('mine');
        $path = "/workspaces/{$mine['workspace']->public_id}/clients/{$mine['alpha']->public_id}/invoices";

        $props = $this->actingAs($mine['owner'])->get($path.'?month=2026-10')->assertOk()->viewData('page')['props'];

        $this->assertSame('2026-10', $props['month']);
        $this->assertSame($path, $props['clear_month_href']);
        $this->assertContains($mine['october']->public_id, array_column($props['invoices'], 'id'));
        $this->assertLessThan(count($this->actingAs($mine['owner'])->get($path)->viewData('page')['props']['invoices']), count($props['invoices']));
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
}
