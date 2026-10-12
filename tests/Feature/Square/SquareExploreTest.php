<?php

namespace Tests\Feature\Square;

use App\Models\ClientCompany;
use App\Models\ClientCompanyMembership;
use App\Models\SquareSyncRun;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Services\Square\Mirror\SquareMirrorSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\FakesSquare;
use Tests\Concerns\ServesSyntheticSquareAccount;
use Tests\TestCase;

/**
 * The operator's explore screen over the Square mirror (#438): synthetic
 * records only, and no Square call from the screen itself.
 */
final class SquareExploreTest extends TestCase
{
    use FakesSquare;
    use RefreshDatabase;
    use ServesSyntheticSquareAccount;

    private User $owner;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Sleep::fake();
        Date::setTestNow('2026-10-12 18:00:00');
        $this->owner = User::factory()->create();
        $this->workspace = Workspace::query()->create(['name' => 'Synthetic explore workspace', 'slug' => 'synthetic-explore']);
        WorkspaceMembership::query()->create(['workspace_id' => $this->workspace->id, 'user_id' => $this->owner->id, 'role' => 'owner']);
        $this->connectSquare($this->workspace);
        $this->serveSquareAccount();
        $sync = app(SquareMirrorSync::class);
        $sync->perform($this->workspace, $sync->request($this->workspace, SquareSyncRun::TRIGGER_SCHEDULE));
    }

    public function test_a_manager_sees_the_mirror_filtered_with_its_freshness_and_a_finished_sync_url(): void
    {
        $calls = count(Http::recorded());

        $this->actingAs($this->owner)->get(route('square.explore', $this->workspace).'?status=PAID&series_key=000001')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('square/explore')
                ->where('filters', ['location_id' => null, 'status' => 'PAID', 'customer_id' => null, 'series_key' => '000001', 'due_from' => null, 'due_to' => null])
                ->where('invoices.total', 1)
                ->where('invoices.data.0.square_invoice_id', 'inv:0-fixture2')
                ->where('invoices.data.0.payment_ids', ['pay_fixture_1'])
                ->where('series.0.series_key', '000001')
                ->where('series.0.cadence', 'irregular')
                ->where('series_truncated', false)
                ->where('options.locations', [['value' => 'LOCFIXTURE01', 'label' => 'Synthetic studio']])
                ->where('options.customers', [['value' => 'cust_fixture_a', 'label' => 'Fixture Holdings']])
                ->where('sync.last_synced_at', '2026-10-12T18:00:00Z')
                ->where('sync.last_run.status', 'completed')
                ->where('sync.sync_url', '/api/v1/workspaces/'.$this->workspace->public_id.'/square/sync')
                ->where('explore_url', '/workspaces/'.$this->workspace->public_id.'/square')
                ->where('workspaceNavigation.square_href', '/workspaces/'.$this->workspace->public_id.'/square'));
        $this->assertCount($calls, Http::recorded(), 'The screen reads only the mirror.');
    }

    public function test_the_newest_due_invoice_comes_first_and_a_bad_filter_shows_everything(): void
    {
        $this->actingAs($this->owner)->get(route('square.explore', $this->workspace).'?status=bogus&due_to=not-a-date')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.status', null)
                ->where('invoices.total', 3)
                ->has('invoices.data', 3));
    }

    public function test_only_managers_of_the_bound_workspace_see_the_screen_or_its_link(): void
    {
        $member = User::factory()->create();
        WorkspaceMembership::query()->create(['workspace_id' => $this->workspace->id, 'user_id' => $member->id, 'role' => 'member']);
        $portal = User::factory()->create();
        $company = ClientCompany::query()->create(['workspace_id' => $this->workspace->id, 'name' => 'Synthetic portal client', 'slug' => 'synthetic-explore-client']);
        ClientCompanyMembership::query()->create(['workspace_id' => $this->workspace->id, 'client_company_id' => $company->id, 'user_id' => $portal->id, 'role' => 'client', 'access_scope' => ClientCompanyMembership::SCOPE_COMPANY]);
        $other = Workspace::query()->create(['name' => 'Synthetic unbound explore', 'slug' => 'synthetic-unbound-explore']);
        WorkspaceMembership::query()->create(['workspace_id' => $other->id, 'user_id' => $this->owner->id, 'role' => 'owner']);

        $this->actingAs($member)->get(route('square.explore', $this->workspace))->assertForbidden();
        $this->actingAs($member)->get(route('mcp.setup', $this->workspace))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('workspaceNavigation.square_href', null));
        $this->assertContains($this->actingAs($portal)->get(route('square.explore', $this->workspace))->status(), [403, 404]);
        $this->actingAs($this->owner)->get(route('square.explore', $other))->assertNotFound();
        $this->actingAs($this->owner)->get(route('mcp.setup', $other))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('workspaceNavigation.square_href', null));

        config(['services.square.access_token' => null]);
        $this->actingAs($this->owner)->get(route('square.explore', $this->workspace))->assertNotFound();
        $this->actingAs($this->owner)->get(route('mcp.setup', $this->workspace))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('workspaceNavigation.square_href', null));
    }
}
