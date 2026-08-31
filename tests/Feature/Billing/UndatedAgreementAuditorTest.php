<?php

namespace Tests\Feature\Billing;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientProject;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\AgreementBillingRateResolver;
use App\Services\Billing\UndatedAgreementAuditor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What the audit counts, and whose.
 *
 * The candidate bounds and exact selected count are computed differently. The
 * bounds make a cheap cross-check; the exact count asks the real resolver so
 * project specificity and every later tiebreak stay authoritative.
 */
final class UndatedAgreementAuditorTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A draft is not this defect.
     *
     * The resolver refuses a draft outright, so an undated draft prices nothing
     * and counting it would inflate the population with rows nobody has
     * finished writing. Asserted because "agreements with a null start date" is
     * the obvious query and it is the wrong one.
     */
    public function test_an_undated_draft_is_not_counted(): void
    {
        $workspace = $this->workspace('draft');
        $company = $this->company($workspace, 'draft');

        $this->agreement($workspace, $company, ['status' => 'draft', 'starts_on' => null]);
        $this->agreement($workspace, $company, ['status' => 'active', 'starts_on' => null]);

        $counts = app(UndatedAgreementAuditor::class)->count($workspace);

        $this->assertSame(2, $counts->agreements);
        $this->assertSame(1, $counts->undated);
        $this->assertSame(['active' => 1], $counts->byStatus);
    }

    /**
     * A terminated or expired agreement still counts.
     *
     * The resolver prices by the effective date range rather than by the
     * current lifecycle status - work done last year under an agreement that
     * has since ended was priced by it - so narrowing to `active` would
     * understate the population. This is the direction of error that matters:
     * it would report a small number and be believed.
     */
    public function test_an_ended_agreement_still_prices_and_is_still_counted(): void
    {
        $workspace = $this->workspace('ended');
        $company = $this->company($workspace, 'ended');

        foreach (['active', 'active', 'paused', 'terminated', 'expired'] as $status) {
            $this->agreement($workspace, $company, ['status' => $status, 'starts_on' => null]);
        }

        $counts = app(UndatedAgreementAuditor::class)->count($workspace);

        // Two actives, not one. Every bucket reading 1 is what a tally that
        // overwrote rather than accumulated would also produce, so one bucket
        // has to hold more than a single row for the count to mean anything.
        $this->assertSame(5, $counts->undated);
        $this->assertSame(
            ['active' => 2, 'expired' => 1, 'paused' => 1, 'terminated' => 1],
            $counts->byStatus,
        );
    }

    /**
     * Hourly-only and retainer-bearing are counted apart.
     *
     * #147 asks for the split because the two have different blast radii: an
     * hourly-only agreement reaches the rate resolver and nothing else, while
     * one carrying retainer terms also reaches capacity and cycle generation,
     * where an undated agreement throws rather than being quietly excluded.
     */
    public function test_terms_and_cadence_are_reported_separately(): void
    {
        $workspace = $this->workspace('terms');
        $company = $this->company($workspace, 'terms');

        $this->agreement($workspace, $company, [
            'starts_on' => null,
            'hourly_rate_amount' => 15000,
            'billing_cadence' => 'one_time',
        ]);
        $this->agreement($workspace, $company, [
            'starts_on' => null,
            'hourly_rate_amount' => 15000,
            'retainer_minutes' => 600,
            'billing_cadence' => 'monthly',
        ]);
        // Period-level terms only. Counted as retainer-bearing, because those
        // are the columns cycle generation reads - a check that looked only at
        // `retainer_minutes` would call this hourly-only and understate the
        // group with the larger blast radius.
        $this->agreement($workspace, $company, [
            'starts_on' => null,
            'period_retainer_amount' => 250000,
            'billing_cadence' => 'monthly',
        ]);

        $counts = app(UndatedAgreementAuditor::class)->count($workspace);

        $this->assertSame(3, $counts->undated);
        $this->assertSame(1, $counts->hourlyOnly);
        $this->assertSame(2, $counts->withRetainerTerms);
        // Two monthly, for the same reason the status tally has two actives.
        $this->assertSame(['monthly' => 2, 'one_time' => 1], $counts->byCadence);
    }

    /**
     * The bracket, with the two bounds forced apart.
     *
     * One entry has only the undated agreement to price it; the other also has
     * a dated one that the resolver's ordering may prefer. So the upper bound is
     * 2 and the lower is 1, and a query that computed either bound twice would
     * report them equal.
     */
    public function test_the_upper_and_lower_bounds_bracket_the_affected_entries(): void
    {
        $workspace = $this->workspace('bracket');
        $company = $this->company($workspace, 'bracket');
        $onlyUndated = $this->project($workspace, $company, 'Only Undated');
        $alsoDated = $this->project($workspace, $company, 'Also Dated');

        $this->agreement($workspace, $company, ['starts_on' => null, 'hourly_rate_amount' => 15000]);
        $this->agreement($workspace, $company, [
            'starts_on' => '2020-01-01',
            'hourly_rate_amount' => 20000,
            'client_project_id' => $alsoDated->id,
        ]);

        $this->entry($workspace, $company, $onlyUndated);
        $this->entry($workspace, $company, $alsoDated);

        $counts = app(UndatedAgreementAuditor::class)->count($workspace);

        $this->assertSame(2, $counts->entriesWithAnUndatedCandidate);
        $this->assertSame(1, $counts->entriesWithNoOtherCandidate);
        $this->assertSame(1, $counts->entriesSelectedByAnUndatedAgreement);
        $this->assertTrue($counts->isLive());
    }

    /**
     * A dated candidate does not prove the undated agreement loses.
     *
     * The undated agreement is project-specific and the dated agreement is
     * company-wide, so the resolver selects the undated one before comparing
     * start dates. This is the reachable case a lower bound cannot identify
     * and the reason the audit must ask the resolver for an exact count.
     */
    public function test_the_exact_count_includes_an_undated_winner_with_a_dated_candidate(): void
    {
        $workspace = $this->workspace('specific-winner');
        $company = $this->company($workspace, 'specific-winner');
        $project = $this->project($workspace, $company, 'Specific Winner');

        $this->agreement($workspace, $company, [
            'starts_on' => null,
            'hourly_rate_amount' => 15000,
            'client_project_id' => $project->id,
        ]);
        $this->agreement($workspace, $company, [
            'starts_on' => '2020-01-01',
            'hourly_rate_amount' => 20000,
        ]);
        $this->entry($workspace, $company, $project);

        $counts = app(UndatedAgreementAuditor::class)->count($workspace);

        $this->assertSame(1, $counts->entriesWithAnUndatedCandidate);
        $this->assertSame(0, $counts->entriesWithNoOtherCandidate);
        $this->assertSame(1, $counts->entriesSelectedByAnUndatedAgreement);
        $this->assertTrue($counts->isLive());
    }

    /**
     * Selection alone is not pricing when the winning agreement has no rate.
     */
    public function test_an_undated_winner_without_an_hourly_rate_is_not_counted_as_priced(): void
    {
        $workspace = $this->workspace('rateless');
        $company = $this->company($workspace, 'rateless');
        $project = $this->project($workspace, $company, 'Rateless');

        $this->agreement($workspace, $company, [
            'starts_on' => null,
            'hourly_rate_amount' => null,
        ]);
        $this->entry($workspace, $company, $project);

        $counts = app(UndatedAgreementAuditor::class)->count($workspace);

        $this->assertSame(1, $counts->entriesWithNoOtherCandidate);
        $this->assertSame(0, $counts->entriesSelectedByAnUndatedAgreement);
        $this->assertFalse($counts->isLive());
    }

    /**
     * The lower bound agrees with the resolver, not merely with my reading of it.
     *
     * The audit restates the resolver's eligibility in SQL, and a restatement
     * can drift from the thing it restates - which is the whole reason #147
     * exists. So this drives the real resolver over the entry the audit calls
     * certainly-affected and asserts it does select the undated agreement's
     * rate. If the two ever disagree, the audit is reporting a number about a
     * rule the code no longer follows.
     */
    public function test_the_certain_bound_matches_what_the_resolver_actually_selects(): void
    {
        $workspace = $this->workspace('agrees');
        $company = $this->company($workspace, 'agrees');
        $project = $this->project($workspace, $company, 'Agreeing');

        $this->agreement($workspace, $company, ['starts_on' => null, 'hourly_rate_amount' => 15000]);
        $entry = $this->entry($workspace, $company, $project);

        $counts = app(UndatedAgreementAuditor::class)->count($workspace);
        $this->assertSame(1, $counts->entriesWithNoOtherCandidate);
        $this->assertSame(1, $counts->entriesSelectedByAnUndatedAgreement);

        $resolved = app(AgreementBillingRateResolver::class)->resolve($entry);

        // `amount`, which is what the resolver returns - reading a key it does
        // not emit would make this pass for the wrong reason if the assertion
        // ever became a null-tolerant one.
        $this->assertSame(15000, $resolved['amount']);
    }

    /**
     * An entry outside the agreement's end date is not priced by it.
     *
     * The eligibility restated in the audit includes the `ends_on` clause, and
     * dropping it is the easy mistake: an undated agreement that ended years ago
     * prices nothing today, and counting today's work against it would inflate
     * both bounds with entries nothing is wrong with.
     */
    public function test_work_after_the_agreement_ended_is_not_counted(): void
    {
        $workspace = $this->workspace('ends');
        $company = $this->company($workspace, 'ends');
        $project = $this->project($workspace, $company, 'Ended');

        $this->agreement($workspace, $company, [
            'starts_on' => null,
            'ends_on' => '2020-12-31',
            'hourly_rate_amount' => 15000,
        ]);
        $this->entry($workspace, $company, $project, ['worked_on' => '2026-08-01']);

        $counts = app(UndatedAgreementAuditor::class)->count($workspace);

        $this->assertSame(1, $counts->undated);
        $this->assertSame(0, $counts->entriesWithAnUndatedCandidate);
        $this->assertSame(0, $counts->entriesSelectedByAnUndatedAgreement);
        $this->assertFalse($counts->isLive());
    }

    /**
     * An agreement scoped to another project does not price this project's work.
     *
     * The resolver accepts a company-wide agreement or one naming the entry's
     * own project. An audit that dropped that clause would count every entry in
     * the company against a project-specific agreement.
     */
    public function test_an_agreement_scoped_to_another_project_is_not_a_candidate(): void
    {
        $workspace = $this->workspace('scoped');
        $company = $this->company($workspace, 'scoped');
        $mine = $this->project($workspace, $company, 'Mine');
        $theirs = $this->project($workspace, $company, 'Theirs');

        $this->agreement($workspace, $company, [
            'starts_on' => null,
            'hourly_rate_amount' => 15000,
            'client_project_id' => $theirs->id,
        ]);
        $this->entry($workspace, $company, $mine);

        $counts = app(UndatedAgreementAuditor::class)->count($workspace);

        $this->assertSame(0, $counts->entriesWithAnUndatedCandidate);
    }

    public function test_a_scoped_audit_sees_only_its_own_workspace(): void
    {
        $mine = $this->exposedWorkspace('first');
        $this->exposedWorkspace('second');

        $counts = app(UndatedAgreementAuditor::class)->count($mine);

        $this->assertSame(1, $counts->undated);
        $this->assertSame(1, $counts->entriesWithNoOtherCandidate);
        $this->assertSame(1, $counts->entriesSelectedByAnUndatedAgreement);

        $unscoped = app(UndatedAgreementAuditor::class)->count();
        $this->assertSame(2, $unscoped->undated);
        $this->assertSame(2, $unscoped->entriesWithNoOtherCandidate);
        $this->assertSame(2, $unscoped->entriesSelectedByAnUndatedAgreement);
    }

    /**
     * A clean workspace reports nothing while its neighbour is broken.
     *
     * Asserted separately because a scope that leaked would still pass the test
     * above by coincidence: both workspaces are affected there, so a wrong
     * number is a plausible number.
     */
    public function test_a_clean_workspace_reports_nothing_when_its_neighbour_is_broken(): void
    {
        $clean = $this->workspace('clean');
        $this->exposedWorkspace('broken');

        $counts = app(UndatedAgreementAuditor::class)->count($clean);

        $this->assertSame(0, $counts->agreements);
        $this->assertSame(0, $counts->undated);
        $this->assertSame(0, $counts->entriesWithAnUndatedCandidate);
        $this->assertSame(0, $counts->entriesSelectedByAnUndatedAgreement);
        $this->assertSame([], $counts->byStatus);
        $this->assertFalse($counts->isLive());
    }

    private function workspace(string $slug): Workspace
    {
        return Workspace::query()->create([
            'name' => ucfirst($slug).' Workspace',
            'slug' => $slug.'-undated-workspace',
        ]);
    }

    private function company(Workspace $workspace, string $slug): ClientCompany
    {
        return ClientCompany::query()->create([
            'workspace_id' => $workspace->id,
            'name' => ucfirst($slug).' Client',
            'slug' => $slug.'-undated-client',
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

    /** @param array<string, mixed> $overrides */
    private function agreement(Workspace $workspace, ClientCompany $company, array $overrides = []): ClientAgreement
    {
        $agreement = ClientAgreement::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'title' => 'Agreement',
            'status' => 'active',
            'currency' => 'USD',
            'starts_on' => '2026-01-01',
        ]);

        // `forceFill`, because the audit's whole subject is a column a normal
        // create path never leaves null - `ProposalWorkflow::accept()` always
        // sets it, so the undated population exists only through import.
        $agreement->forceFill($overrides)->save();

        return $agreement;
    }

    /** @param array<string, mixed> $overrides */
    private function entry(
        Workspace $workspace,
        ClientCompany $company,
        ClientProject $project,
        array $overrides = [],
    ): ClientTimeEntry {
        return ClientTimeEntry::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'client_project_id' => $project->id,
            'user_id' => User::factory()->create()->id,
            'worked_on' => '2026-08-01',
            'minutes' => 60,
            'description' => 'Synthetic work',
            'status' => 'draft',
            'currency' => 'USD',
            ...$overrides,
        ]);
    }

    /**
     * One workspace whose only agreement is undated and whose only time entry
     * has nothing else to price it - the certainly-affected shape.
     */
    private function exposedWorkspace(string $slug): Workspace
    {
        $workspace = $this->workspace($slug);
        $company = $this->company($workspace, $slug);
        $project = $this->project($workspace, $company, ucfirst($slug).' Project');

        $this->agreement($workspace, $company, ['starts_on' => null, 'hourly_rate_amount' => 15000]);
        $this->entry($workspace, $company, $project);

        return $workspace;
    }
}
