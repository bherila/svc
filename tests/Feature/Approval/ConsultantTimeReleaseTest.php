<?php

namespace Tests\Feature\Approval;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientProject;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\ClientInvoicingService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Generating a cadence draft pulls the consultant's unbilled time onto it
 * without anyone approving it (docs/ux-overhaul.md section 7b). Today is
 * 2026-10-08; the draft sells October and bills September's work.
 */
final class ConsultantTimeReleaseTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private ClientCompany $company;

    private ClientProject $project;

    private ClientAgreement $agreement;

    private User $consultant;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-08 12:00:00');
        [$this->workspace, $this->company, $this->project, $this->agreement, $this->consultant] = $this->world('release');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /** @return array{Workspace, ClientCompany, ClientProject, ClientAgreement, User} */
    private function world(string $name): array
    {
        $workspace = Workspace::query()->create(['name' => 'Synthetic '.$name, 'slug' => 'synthetic-'.$name]);
        $consultant = User::factory()->create();
        $workspace->memberships()->create(['user_id' => $consultant->id, 'role' => 'owner']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic '.$name.' client', 'slug' => 'synthetic-'.$name.'-client']);
        $project = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Synthetic '.$name.' project']);
        $agreement = ClientAgreement::query()->create([
            'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'title' => 'Synthetic retainer', 'status' => 'active',
            'starts_on' => '2026-01-01', 'billing_cadence' => 'monthly', 'currency' => 'USD', 'hourly_rate_amount' => 10000,
            'retainer_minutes' => 600, 'retainer_amount' => 100000,
        ]);

        return [$workspace, $company, $project, $agreement, $consultant];
    }

    /** @param array<string, mixed> $overrides */
    private function draft(string $day, int $minutes = 60, array $overrides = [], ?Workspace $workspace = null, ?ClientCompany $company = null, ?ClientProject $project = null, ?User $author = null): ClientTimeEntry
    {
        $workspace ??= $this->workspace;

        return ClientTimeEntry::query()->create($overrides + [
            'workspace_id' => $workspace->id, 'client_company_id' => ($company ?? $this->company)->id, 'client_project_id' => ($project ?? $this->project)->id,
            'user_id' => ($author ?? $this->consultant)->id, 'worked_on' => $day, 'minutes' => $minutes, 'description' => 'Synthetic work',
            'status' => 'draft', 'is_billable' => true, 'is_deferred' => false,
        ])->refresh();
    }

    private function generate(?ClientCompany $company = null, ?ClientAgreement $agreement = null): void
    {
        app(ClientInvoicingService::class)->generateCadencePeriod($company ?? $this->company, $agreement ?? $this->agreement, Carbon::parse('2026-10-01'));
    }

    public function test_generating_the_draft_moves_unbilled_consultant_time_to_ready_with_no_approver_and_the_agreement_rate(): void
    {
        $entry = $this->draft('2026-09-10', 700);

        $this->generate();

        $fresh = $entry->fresh();
        $this->assertSame('approved', $fresh->status);
        $this->assertNull($fresh->approved_by_user_id, 'No person approved it.');
        $this->assertNull($fresh->approved_at);
        $this->assertSame(10000, $fresh->billing_rate_amount);
        $this->assertSame('agreement', $fresh->billing_rate_source);
        $this->assertSame('USD', $fresh->currency);
        // And the draft bills it: 700 minutes against 600 included.
        $this->assertTrue(DB::table('client_invoice_line_time_entries')->where('client_time_entry_id', $entry->id)->exists());
    }

    public function test_the_hours_are_billed_exactly_as_if_they_had_been_approved(): void
    {
        $this->draft('2026-09-10', 700);
        [$otherWorkspace, $otherCompany, $otherProject, $otherAgreement, $otherConsultant] = $this->world('control');
        $this->draft('2026-09-10', 700, ['status' => 'approved', 'billing_rate_amount' => 10000, 'currency' => 'USD'], $otherWorkspace, $otherCompany, $otherProject, $otherConsultant);

        $this->generate();
        $this->generate($otherCompany, $otherAgreement);

        $total = fn (Workspace $workspace): int => (int) DB::table('client_invoices')->where('workspace_id', $workspace->id)->value('total_amount');
        $linked = fn (Workspace $workspace): int => DB::table('client_invoice_line_time_entries')->where('workspace_id', $workspace->id)->count();
        $this->assertGreaterThan(0, $linked($otherWorkspace), 'The control bills its hours.');
        $this->assertSame($linked($otherWorkspace), $linked($this->workspace));
        $this->assertSame($total($otherWorkspace), $total($this->workspace));
    }

    public function test_only_billable_consultant_time_up_to_today_is_released(): void
    {
        $billable = $this->draft('2026-09-10');
        $today = $this->draft('2026-10-08');
        $future = $this->draft('2026-10-09');
        $nonBillable = $this->draft('2026-09-11', 60, ['is_billable' => false]);
        $flat = $this->draft('2026-09-12', 60, ['subcontractor_billing_mode' => 'flat_hourly', 'subcontractor_cost_amount' => 5000, 'subcontractor_cost_currency' => 'USD']);
        $direct = $this->draft('2026-09-13', 60, ['subcontractor_billing_mode' => 'direct']);
        $retainerSub = $this->draft('2026-09-14', 60, ['subcontractor_billing_mode' => 'retainer']);

        $this->generate();

        $this->assertSame('approved', $billable->fresh()->status);
        $this->assertSame('approved', $today->fresh()->status, 'Today is included.');
        foreach ([$future, $nonBillable, $flat, $direct, $retainerSub] as $left) {
            $this->assertSame('draft', $left->fresh()->status);
        }
    }

    public function test_a_rate_the_person_stated_is_kept(): void
    {
        $entry = $this->draft('2026-09-10', 60, ['billing_rate_amount' => 22500, 'billing_rate_source' => 'explicit', 'currency' => 'USD']);

        $this->generate();

        $this->assertSame(22500, $entry->fresh()->billing_rate_amount);
        $this->assertSame('explicit', $entry->fresh()->billing_rate_source);
    }

    public function test_time_the_agreement_cannot_price_is_still_released_without_a_rate(): void
    {
        $this->agreement->forceFill(['hourly_rate_amount' => null])->save();
        $entry = $this->draft('2026-09-10');

        $this->generate();

        $this->assertSame('approved', $entry->fresh()->status);
        $this->assertNull($entry->fresh()->billing_rate_amount);
    }

    public function test_another_workspaces_drafts_are_never_touched(): void
    {
        [$otherWorkspace, $otherCompany, $otherProject, , $otherConsultant] = $this->world('bystander');
        $theirs = $this->draft('2026-09-10', 60, [], $otherWorkspace, $otherCompany, $otherProject, $otherConsultant);
        $this->draft('2026-09-10');

        $this->generate();

        $this->assertSame('draft', $theirs->fresh()->status);
    }

    public function test_another_clients_drafts_in_the_same_workspace_are_never_touched(): void
    {
        $other = ClientCompany::query()->create(['workspace_id' => $this->workspace->id, 'name' => 'Synthetic other client', 'slug' => 'synthetic-release-other']);
        $otherProject = ClientProject::query()->create(['workspace_id' => $this->workspace->id, 'client_company_id' => $other->id, 'name' => 'Other project']);
        $theirs = $this->draft('2026-09-10', 60, [], null, $other, $otherProject);
        $this->draft('2026-09-10');

        $this->generate();

        $this->assertSame('draft', $theirs->fresh()->status);
    }

    public function test_an_agreement_scoped_to_one_project_releases_only_that_projects_time(): void
    {
        $second = ClientProject::query()->create(['workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id, 'name' => 'Second project']);
        $this->agreement->forceFill(['client_project_id' => $this->project->id])->save();
        $inside = $this->draft('2026-09-10');
        $outside = $this->draft('2026-09-10', 60, [], null, null, $second);

        $this->generate();

        $this->assertSame('approved', $inside->fresh()->status);
        $this->assertSame('draft', $outside->fresh()->status);
    }

    public function test_regenerating_releases_nothing_twice_and_picks_up_late_time(): void
    {
        $first = $this->draft('2026-09-10');
        $this->generate();
        $version = $first->fresh()->lock_version;
        $late = $this->draft('2026-09-20');

        $this->generate();

        $this->assertSame($version, $first->fresh()->lock_version, 'Already released: not written again.');
        $this->assertSame('approved', $late->fresh()->status);
    }
}
