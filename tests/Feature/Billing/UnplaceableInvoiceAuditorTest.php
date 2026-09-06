<?php

namespace Tests\Feature\Billing;

use App\Models\ClientAgreement;
use App\Models\ClientBillingSchedule;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\Workspace;
use App\Services\Billing\UnplaceableInvoiceAuditor;
use App\Support\Billing\InvoiceStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The audit's scope, which the console command never exercises.
 *
 * `AuditUnplaceableInvoicesCommandTest` covers what counts as affected; this
 * covers *whose*. The console runs unscoped on purpose - an operator sizing a
 * migration needs every workspace at once - so nothing there would notice if
 * the workspace parameter silently did nothing, and a tenant-facing surface
 * built on top would report one client's data-quality problem to another.
 *
 * That makes this the tenancy boundary for the audit, and it is asserted the
 * way the isolation harness asserts every other read surface: with a second
 * workspace that must not appear, rather than a single-tenant happy path.
 */
final class UnplaceableInvoiceAuditorTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_unscoped_audit_counts_every_workspace(): void
    {
        $this->affectedInvoiceIn('first');
        $this->affectedInvoiceIn('second');

        $counts = app(UnplaceableInvoiceAuditor::class)->count();

        $this->assertSame(2, $counts->invoices);
        $this->assertSame(2, $counts->affected);
        $this->assertSame(11.0, $counts->overageHoursAtStake);
    }

    public function test_a_scoped_audit_sees_only_its_own_workspace(): void
    {
        $mine = $this->affectedInvoiceIn('first');
        $this->affectedInvoiceIn('second');

        $counts = app(UnplaceableInvoiceAuditor::class)->count($mine);

        $this->assertSame(1, $counts->invoices);
        $this->assertSame(1, $counts->affected);
        $this->assertSame(5.5, $counts->overageHoursAtStake);
    }

    /**
     * A neighbour's broken rows must not raise this workspace's count, even
     * when this workspace is clean. Asserted separately because a scope that
     * leaked would still pass the test above by coincidence: both workspaces
     * are affected there, so a wrong number is a plausible number.
     */
    public function test_a_clean_workspace_reports_nothing_when_its_neighbour_is_broken(): void
    {
        $clean = $this->workspace('clean');
        $this->affectedInvoiceIn('broken');

        $counts = app(UnplaceableInvoiceAuditor::class)->count($clean);

        $this->assertSame(0, $counts->invoices);
        $this->assertSame(0, $counts->affected);
        $this->assertSame(0.0, $counts->overageHoursAtStake);
        $this->assertSame(0, $counts->liveWithoutACycle);
    }

    /**
     * Every stage of the funnel a different number, and the boundaries pinned.
     *
     * The scoping tests above prove *whose* rows are counted; this proves the
     * arithmetic. It is built so that each narrowing actually narrows - 7 rows,
     * then 6, 5, 4, 3 - because when consecutive stages happen to agree, a
     * funnel that skipped one reports the same totals and nothing notices.
     *
     * The hour values are chosen against the filter rather than for realism:
     * exactly 1 and exactly -1 sit either side of the `!= 0` test, so a filter
     * that drifted to `!= 1` or `!= -1` would drop a row it must keep. The
     * 1.2345 is there because the column is `decimal:4`, so it is the finest
     * value that survives storage and the one that notices if the rounding
     * loses a place.
     */
    public function test_each_stage_of_the_funnel_narrows_and_the_boundaries_hold(): void
    {
        $workspace = $this->workspace('funnel');
        $company = $this->company($workspace, 'funnel');
        $agreement = $this->agreement($workspace, $company);

        // Counted: nonzero overage, charged, on an agreement, no period.
        $this->invoice($workspace, $company, $agreement, ['status' => 'issued', 'hours_billed_at_rate' => '1.0000']);
        $this->invoice($workspace, $company, $agreement, ['status' => 'issued', 'hours_billed_at_rate' => '-1.0000']);
        $this->invoice($workspace, $company, $agreement, ['status' => 'issued', 'hours_billed_at_rate' => '1.2345']);

        // Dropped, one per stage.
        $this->invoice($workspace, $company, $agreement, ['status' => 'draft', 'hours_billed_at_rate' => '5.0000']);
        $this->invoice($workspace, $company, null, ['status' => 'issued', 'hours_billed_at_rate' => '7.0000']);
        $this->invoice($workspace, $company, $agreement, ['status' => 'issued', 'hours_billed_at_rate' => '0.0000']);
        $this->invoice($workspace, $company, $agreement, [
            'status' => 'issued',
            'hours_billed_at_rate' => '9.0000',
            'service_period_end' => '2026-07-31',
        ]);

        $counts = app(UnplaceableInvoiceAuditor::class)->count($workspace);

        $this->assertSame(7, $counts->invoices);
        $this->assertSame(6, $counts->withoutAServicePeriod);
        $this->assertSame(5, $counts->chargedOfThose);
        $this->assertSame(4, $counts->onAnAgreementOfThose);
        $this->assertSame(3, $counts->affected);

        // Magnitudes, so the negative row adds rather than cancels, and to four
        // places, so a rounding that lost one would show here.
        $this->assertSame(3.2345, $counts->overageHoursAtStake);
    }

    /**
     * The same discipline on the cycle half, which narrows on different keys.
     *
     * Kind is applied before the cycle dates are read at all, and the live and
     * charged counts answer two different questions - one about duplicate
     * guards, one about money - so the fixture makes all five stages differ:
     * 6, 5, 4, 3, 2.
     *
     * Again the hour values sit on the filter's boundary rather than anywhere
     * realistic, so a drift from `!= 0` in either direction drops a row that
     * must be kept.
     */
    public function test_the_cycle_funnel_narrows_and_the_boundaries_hold(): void
    {
        $workspace = $this->workspace('cycle');
        $company = $this->company($workspace, 'cycle');
        $agreement = $this->agreement($workspace, $company);

        // Counted all the way through.
        $this->invoice($workspace, $company, $agreement, ['status' => 'issued', 'hours_billed_at_rate' => '1.0000']);
        $this->invoice($workspace, $company, $agreement, ['status' => 'issued', 'hours_billed_at_rate' => '-1.0000']);

        // Live but not charged - visible to the duplicate guards, absent from
        // the money question.
        $this->invoice($workspace, $company, $agreement, ['status' => 'draft', 'hours_billed_at_rate' => '3.0000']);

        // Charged, but nothing at stake.
        $this->invoice($workspace, $company, $agreement, ['status' => 'issued', 'hours_billed_at_rate' => '0.0000']);

        // Dropped by kind, before its cycle columns are looked at.
        $this->invoice($workspace, $company, $agreement, [
            'status' => 'issued',
            'invoice_kind' => 'ad_hoc',
            'hours_billed_at_rate' => '4.0000',
        ]);

        // Dropped for naming no agreement.
        $this->invoice($workspace, $company, null, ['status' => 'issued', 'hours_billed_at_rate' => '6.0000']);

        // Names both cycle dates, so it is placeable and out of scope entirely.
        $this->invoice($workspace, $company, $agreement, [
            'status' => 'issued',
            'hours_billed_at_rate' => '5.0000',
            'cycle_start' => '2026-07-01',
            'cycle_end' => '2026-07-31',
        ]);

        $counts = app(UnplaceableInvoiceAuditor::class)->count($workspace);

        $this->assertSame(6, $counts->withoutACycle);
        $this->assertSame(5, $counts->ofAKindReadByCycle);
        $this->assertSame(4, $counts->liveWithoutACycle);
        $this->assertSame(2, $counts->cycleAffected);
        $this->assertSame(2.0, $counts->cycleOverageHoursAtStake);
    }

    /**
     * The period-guard count mirrors the guard, not a tidier version of it.
     *
     * Three separate things, each of which a plausible simplification gets
     * wrong, and each of which was wrong in a shipped draft of this count.
     *
     * **Both boundaries.** `generateDue()` and `assertNoOverlappingInvoice()`
     * compare a period at both ends, so a row stating a start and no end is
     * exactly as invisible to them as one stating neither. Counting only the
     * start printed an all-clear that was false for half the population it
     * claimed to cover - a zero read as evidence about a column it never
     * examined, which is the very mistake this count was added to correct.
     *
     * **Kind narrows only unlinked rows.** `cycleGuardExclusions()` keeps an
     * interim or ad-hoc invoice from blocking a cadence one, but `generateDue()`
     * applies that only where the invoice names no schedule; a row naming a
     * schedule is that schedule's whatever kind it carries. Applying the
     * exclusion to everything hid the malformed combination this audit exists
     * to surface.
     *
     * **Status is not applied at all**, unlike every other funnel in this
     * auditor. `generateDue()` has no status filter, so a voided invoice blocks
     * its period - and a voided invoice missing a boundary defeats that guard
     * exactly as a live one does, letting the schedule bill a waived period
     * again with no constraint to reject the write. A first draft filtered to
     * `live()` by analogy with the cycle counts and reported that row as no
     * exposure at all.
     */
    public function test_the_period_guard_count_reads_both_boundaries_and_narrows_by_kind_only_when_unlinked(): void
    {
        $workspace = $this->workspace('period-start');
        $company = $this->company($workspace, 'period-start');
        $agreement = $this->agreement($workspace, $company);
        $schedule = ClientBillingSchedule::query()->create([
            'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'client_agreement_id' => $agreement->id,
            'cadence' => 'monthly', 'next_run_on' => '2026-08-01', 'due_days' => 14, 'currency' => 'USD',
            'line_template' => [],
        ]);
        $placed = ['service_period_start' => '2026-07-01', 'service_period_end' => '2026-07-31'];
        $startless = ['service_period_end' => '2026-07-31'];
        $endless = ['service_period_start' => '2026-07-01', 'service_period_end' => null];

        // Fully placed: out of every count, and the only row here that is.
        $this->invoice($workspace, $company, $agreement, ['status' => 'issued'] + $placed);

        // No start, of a kind a period guard reads.
        $this->invoice($workspace, $company, $agreement, ['status' => 'issued', 'invoice_kind' => 'cadence_period'] + $startless);
        $this->invoice($workspace, $company, $agreement, ['status' => 'draft', 'invoice_kind' => 'cadence_period'] + $startless);
        // A migrated row carries no kind and those guards still read it.
        $this->invoice($workspace, $company, $agreement, ['status' => 'issued', 'invoice_kind' => null] + $startless);

        // Voided, and still exposure. `generateDue()` never looks at status, so
        // this row blocks its period while it is placeable and stops blocking
        // the moment a boundary is missing - the schedule then bills a period
        // that was deliberately waived, and the unique index cannot reject the
        // replacement because one of its three columns is the null that caused
        // the problem.
        $this->invoice($workspace, $company, $agreement, ['status' => InvoiceStatus::Void->value, 'invoice_kind' => 'cadence_period'] + $startless);

        // Excluded by kind, because it names no schedule: an ad-hoc invoice is
        // out of the cadence guards before its dates matter.
        $this->invoice($workspace, $company, $agreement, ['status' => 'issued', 'invoice_kind' => 'ad_hoc'] + $startless);

        // The same kind, linked to a schedule, is *not* excluded: that arm of
        // `generateDue()` reads the row whatever kind it carries.
        $this->invoice($workspace, $company, $agreement, [
            'status' => 'issued', 'invoice_kind' => 'ad_hoc', 'client_billing_schedule_id' => $schedule->id,
        ] + $startless);

        // States a start and no end. Invisible to the same comparisons, and
        // counted by the guard metric while the start-only figure never sees
        // it.
        $this->invoice($workspace, $company, $agreement, ['status' => 'issued', 'invoice_kind' => 'cadence_period'] + $endless);

        $counts = app(UnplaceableInvoiceAuditor::class)->count($workspace);

        $this->assertSame(6, $counts->withoutAServicePeriodStart);
        $this->assertSame(
            6,
            $counts->unplaceableByAPeriodGuard,
            'both boundaries, the voided row, and the schedule-linked ad-hoc row - but not the unlinked ad-hoc one',
        );

        // The end-boundary funnel is untouched by the start-only rows: only the
        // last invoice above lacks an end. Asserted here because folding these
        // together is the obvious simplification, and it would drag five rows
        // into a funnel that reports money at stake.
        $this->assertSame(1, $counts->withoutAServicePeriod);
        $this->assertSame(0, $counts->affected);
    }

    private function workspace(string $slug): Workspace
    {
        return Workspace::query()->create([
            'name' => ucfirst($slug).' Workspace',
            'slug' => $slug.'-workspace',
        ]);
    }

    private function company(Workspace $workspace, string $slug): ClientCompany
    {
        return ClientCompany::query()->create([
            'workspace_id' => $workspace->id,
            'name' => ucfirst($slug).' Client',
            'slug' => $slug.'-client',
        ]);
    }

    private function agreement(Workspace $workspace, ClientCompany $company): ClientAgreement
    {
        return ClientAgreement::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'title' => 'Retainer',
            'status' => 'active',
            'currency' => 'USD',
            'starts_on' => '2026-01-01',
            'retainer_minutes' => 600,
        ]);
    }

    /**
     * An invoice with no service period unless the overrides give it one.
     *
     * `forceFill` for the overrides because the audit's whole subject is
     * columns a normal create would not let a fixture set - a null period, a
     * charged status, a zero or negative overage.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function invoice(
        Workspace $workspace,
        ClientCompany $company,
        ?ClientAgreement $agreement,
        array $overrides = [],
    ): ClientInvoice {
        $invoice = ClientInvoice::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'client_agreement_id' => $agreement?->id,
            'invoice_number' => strtoupper($workspace->slug).'-'.uniqid(),
            'status' => 'draft',
            'currency' => 'USD',
            'subtotal_amount' => 0,
            'tax_amount' => 0,
            'total_amount' => 0,
        ]);

        $invoice->forceFill(['service_period_end' => null, ...$overrides])->save();

        return $invoice;
    }

    /**
     * One charged, agreement-bound invoice with overage and no service period -
     * the shape the audit is meant to count - in a workspace of its own.
     */
    private function affectedInvoiceIn(string $slug): Workspace
    {
        $workspace = $this->workspace($slug);
        $company = $this->company($workspace, $slug);

        $this->invoice($workspace, $company, $this->agreement($workspace, $company), [
            'status' => 'issued',
            'hours_billed_at_rate' => '5.5',
        ]);

        return $workspace;
    }
}
