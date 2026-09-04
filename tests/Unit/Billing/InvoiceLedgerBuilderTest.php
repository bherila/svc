<?php

namespace Tests\Unit\Billing;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientProject;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\Balances\ClosingBalance;
use App\Services\Billing\Balances\MonthSummary;
use App\Services\Billing\Balances\OpeningBalance;
use App\Services\Billing\BillingCycleResolver;
use App\Services\Billing\InvoiceLedgerBuilder;
use App\Services\Billing\ReplayHistoryBasis;
use App\Support\Billing\BillingCadence;
use Carbon\Carbon;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\WritesLegacyCrossTenantRows;
use Tests\TestCase;

class InvoiceLedgerBuilderTest extends TestCase
{
    use RefreshDatabase;
    use WritesLegacyCrossTenantRows;

    public function test_build_agreement_ledger_through_summarizes_monthly_entries(): void
    {
        $company = $this->company();
        $project = $this->project($company);
        $agreement = $this->agreement($company, [
            'active_date' => '2026-01-01',
            'termination_date' => null,
            'monthly_retainer_hours' => 10,
            'rollover_months' => 0,
            'initial_rollover_hours' => 0,
            'retainer_hours' => null,
        ]);

        $this->entry($company, $project, [
            'date_worked' => '2026-01-15',
            'minutes_worked' => 120,
            'is_billable' => true,
        ]);
        $this->entry($company, $project, [
            'date_worked' => '2026-01-20',
            'minutes_worked' => 60,
            'is_billable' => false,
        ]);

        $ledger = (new InvoiceLedgerBuilder)->buildAgreementLedgerThrough(
            $company,
            $agreement,
            Carbon::parse('2026-01-31'),
        );

        $this->assertCount(1, $ledger);
        $this->assertSame('2026-01', $ledger[0]->yearMonth);
        $this->assertSame(2.0, $ledger[0]->hoursWorked);
        $this->assertSame(10.0, $ledger[0]->retainerHours);
        $this->assertSame(8.0, $ledger[0]->closing->unusedHours);
    }

    public function test_a_charged_pre_active_service_month_carries_into_the_first_active_month(): void
    {
        $company = $this->company();
        $agreement = $this->agreement($company, [
            'active_date' => '2026-02-01',
            'billing_cadence' => 'monthly',
            'monthly_retainer_hours' => 10,
            'rollover_months' => 1,
            'retainer_hours' => null,
        ]);
        ClientInvoice::query()->create([
            'workspace_id' => $company->workspace_id,
            'client_company_id' => $company->id,
            'client_agreement_id' => $agreement->id,
            'invoice_number' => 'PRE-ACTIVE-'.uniqid(),
            'status' => 'issued',
            'service_period_end' => '2026-01-31',
            'hours_billed_at_rate' => '5.0000',
            'currency' => 'USD',
        ]);

        $ledger = (new InvoiceLedgerBuilder)->buildAgreementLedgerThrough(
            $company,
            $agreement,
            Carbon::parse('2026-02-28'),
        );

        $this->assertSame(['2026-01', '2026-02'], array_column($ledger, 'yearMonth'));
        $this->assertSame(0.0, $ledger[0]->retainerHours, 'The service month predates the agreement grant');
        $this->assertSame(5.0, $ledger[0]->closing->unusedHours);
        $this->assertSame(5.0, $ledger[1]->opening->rolloverHours);
        $this->assertSame(15.0, $ledger[1]->opening->totalAvailable);
    }

    public function test_a_monthly_period_retainer_ledger_applies_charged_overage_history(): void
    {
        $company = $this->company();
        $project = $this->project($company);
        $agreement = $this->agreement($company, [
            'active_date' => '2026-01-01',
            'billing_cadence' => 'monthly',
            'monthly_retainer_hours' => 10,
            'retainer_hours' => 10,
        ]);
        $this->entry($company, $project, [
            'date_worked' => '2026-01-15',
            'minutes_worked' => 900,
        ]);
        ClientInvoice::query()->create([
            'workspace_id' => $company->workspace_id,
            'client_company_id' => $company->id,
            'client_agreement_id' => $agreement->id,
            'invoice_number' => 'PERIOD-HISTORY-'.uniqid(),
            'status' => 'paid',
            'service_period_end' => '2026-01-31',
            'hours_billed_at_rate' => '6.1234',
            'currency' => 'USD',
        ]);

        $ledger = (new InvoiceLedgerBuilder)->buildAgreementLedgerThrough(
            $company,
            $agreement,
            Carbon::parse('2026-01-31'),
        );

        $this->assertCount(1, $ledger);
        $this->assertSame(1.1234, $ledger[0]->closing->unusedHours, 'The charge settles five debt hours and restores its exact surplus');
        $this->assertSame(0.0, $ledger[0]->closing->negativeBalance);
    }

    /**
     * An agreement with no rollover term carries nothing forward.
     *
     * `rollover_months` is cast straight to an int where the ledger reads it,
     * so a null is indistinguishable from a deliberate zero - and zero is the
     * safe reading: an unstated policy must not silently grant the client last
     * month's leftover hours on top of this month's retainer. The contrast
     * against a one-month policy is what makes the null the reason.
     */
    public function test_an_agreement_with_no_rollover_term_carries_nothing_forward(): void
    {
        $company = $this->company();
        $project = $this->project($company);
        $agreement = ClientAgreement::query()->create([
            'workspace_id' => $company->workspace_id,
            'client_company_id' => $company->id,
            'title' => 'Unstated rollover',
            'status' => 'active',
            'currency' => 'USD',
            'starts_on' => '2026-01-01',
            'billing_cadence' => 'monthly',
            'retainer_minutes' => 600,
            'rollover_months' => null,
        ]);

        $this->entry($company, $project, [
            'date_worked' => '2026-01-15',
            'minutes_worked' => 120,
            'is_billable' => true,
        ]);

        $through = Carbon::parse('2026-02-28');
        $ledger = (new InvoiceLedgerBuilder)->buildAgreementLedgerThrough($company, $agreement, $through);

        $this->assertSame('2026-02', $ledger[1]->yearMonth);
        $this->assertSame(8.0, $ledger[0]->closing->unusedHours, 'January leaves eight hours unused');
        $this->assertSame(0.0, $ledger[1]->opening->rolloverHours, 'None of them reach February');
        $this->assertSame(10.0, $ledger[1]->opening->totalAvailable, 'February opens on its own retainer alone');

        // The same ledger with a stated one-month policy, so the assertion above
        // is pinned to the null rather than to the arithmetic.
        $agreement->forceFill(['rollover_months' => 1])->save();
        $rolled = (new InvoiceLedgerBuilder)->buildAgreementLedgerThrough($company, $agreement->fresh(), $through);

        $this->assertSame(8.0, $rolled[1]->opening->rolloverHours);
        $this->assertSame(18.0, $rolled[1]->opening->totalAvailable);
    }

    public function test_replay_history_basis_opens_a_native_period_ledger_without_changing_the_agreement(): void
    {
        $company = $this->company();
        $project = $this->project($company);
        $agreement = $this->agreement($company, [
            'active_date' => '2026-01-01',
            'billing_cadence' => 'quarterly',
            'monthly_retainer_hours' => 10,
            'retainer_hours' => 30,
        ]);
        $this->entry($company, $project, [
            'date_worked' => '2025-12-15',
            'minutes_worked' => 600,
        ]);

        $basis = new ReplayHistoryBasis;
        $basis->seed($agreement, Carbon::parse('2025-12-01'));
        $ledger = (new InvoiceLedgerBuilder(replayHistoryBasis: $basis))->buildAgreementLedgerThrough(
            $company,
            $agreement,
            Carbon::parse('2026-01-31'),
        );

        $this->assertSame('2025-12', $ledger[0]->yearMonth);
        $this->assertSame(10.0, $ledger[0]->hoursWorked);
        $this->assertSame('2026-01-01', $agreement->fresh()->starts_on?->toDateString());
    }

    public function test_replay_history_basis_grants_capacity_in_a_legacy_monthly_ledger_without_changing_the_agreement(): void
    {
        $company = $this->company();
        $project = $this->project($company);
        $agreement = $this->agreement($company, [
            'active_date' => '2026-01-01',
            'billing_cadence' => 'monthly',
            'monthly_retainer_hours' => 10,
            'retainer_hours' => null,
        ]);
        $this->entry($company, $project, [
            'date_worked' => '2025-12-15',
            'minutes_worked' => 600,
        ]);

        $basis = new ReplayHistoryBasis;
        $basis->seed($agreement, Carbon::parse('2025-12-01'));
        $ledger = (new InvoiceLedgerBuilder(replayHistoryBasis: $basis))->buildAgreementLedgerThrough(
            $company,
            $agreement,
            Carbon::parse('2026-01-31'),
        );

        $this->assertSame('2025-12', $ledger[0]->yearMonth);
        $this->assertSame(10.0, $ledger[0]->retainerHours);
        $this->assertSame(10.0, $ledger[0]->hoursWorked);
        $this->assertSame(0.0, $ledger[0]->closing->unusedHours);
        $this->assertSame('2026-01-01', $agreement->fresh()->starts_on?->toDateString());
    }

    /**
     * The opening rollover reaches the agreement's first recorded month.
     *
     * #134: this assertion did not exist, and its absence is the whole reason
     * the defect survived. `InvoiceLedgerBuilder` read `initial_rollover_hours`
     * where the column is `initial_rollover_minutes`, so the read was null on
     * every agreement and the seed month was never built - and a missing month
     * of zero worked hours leaves every total in this suite unchanged. The
     * assertion has to be that the month is *there*.
     */
    public function test_the_opening_rollover_grants_capacity_that_reaches_the_first_month(): void
    {
        $company = $this->company();
        $agreement = $this->agreement($company, [
            'active_date' => '2026-01-01',
            'monthly_retainer_hours' => 10,
            'rollover_months' => 1,
            'initial_rollover_hours' => 5,
            'retainer_hours' => null,
        ]);

        $ledger = (new InvoiceLedgerBuilder)->buildAgreementLedgerThrough(
            $company,
            $agreement,
            Carbon::parse('2026-01-31'),
        );

        $this->assertSame('2025-12', $ledger[0]->yearMonth, 'The carrier month is built');
        $this->assertSame(5.0, $ledger[0]->retainerHours);
        $this->assertSame(0.0, $ledger[0]->hoursWorked, 'It carries capacity, never work');
        $this->assertSame('2026-01', $ledger[1]->yearMonth);
        $this->assertSame(5.0, $ledger[1]->opening->rolloverHours, 'And it reaches January');
        $this->assertSame(15.0, $ledger[1]->opening->totalAvailable);

        // The same ledger with no opening rollover, so the assertions above are
        // pinned to the grant rather than to the retainer arithmetic.
        $agreement->forceFill(['initial_rollover_minutes' => 0])->save();
        $without = (new InvoiceLedgerBuilder)->buildAgreementLedgerThrough(
            $company,
            $agreement->fresh(),
            Carbon::parse('2026-01-31'),
        );

        $this->assertSame('2026-01', $without[0]->yearMonth, 'No carrier month at all');
        $this->assertSame(0.0, $without[0]->opening->rolloverHours);
        $this->assertSame(10.0, $without[0]->opening->totalAvailable);
    }

    /**
     * A null initial rollover means no opening capacity, not an unknown amount.
     *
     * Registered for #115: until #134 the column had no reachable reader at
     * all, so its null could not mean anything and the registry marked it
     * pending. It has one now, which makes the meaning a decision rather than
     * an accident - and the decision has to be pinned against a stated value in
     * the same test, or it would pass on the surrounding retainer arithmetic
     * exactly as the original defect did.
     *
     * Null and zero agree here deliberately. An agreement that never recorded
     * an opening balance is not carrying an unknown one, and inventing capacity
     * for it would grant hours nobody agreed to sell.
     */
    public function test_an_agreement_with_no_recorded_opening_rollover_grants_none(): void
    {
        $company = $this->company();
        $agreement = $this->agreement($company, [
            'active_date' => '2026-01-01',
            'monthly_retainer_hours' => 10,
            'rollover_months' => 1,
            'initial_rollover_hours' => 5,
            'retainer_hours' => null,
        ]);

        $agreement->forceFill(['initial_rollover_minutes' => null])->save();

        $ledger = (new InvoiceLedgerBuilder)->buildAgreementLedgerThrough(
            $company,
            $agreement->fresh(),
            Carbon::parse('2026-01-31'),
        );

        $this->assertSame('2026-01', $ledger[0]->yearMonth, 'No carrier month is built');
        $this->assertSame(0.0, $ledger[0]->opening->rolloverHours);
        $this->assertSame(10.0, $ledger[0]->opening->totalAvailable, 'The retainer alone');

        // The same agreement with the value stated, so the assertions above are
        // pinned to the null rather than to the retainer arithmetic.
        $agreement->forceFill(['initial_rollover_minutes' => 300])->save();
        $stated = (new InvoiceLedgerBuilder)->buildAgreementLedgerThrough(
            $company,
            $agreement->fresh(),
            Carbon::parse('2026-01-31'),
        );

        $this->assertSame('2025-12', $stated[0]->yearMonth, 'A stated opening does build one');
        $this->assertSame(15.0, $stated[1]->opening->totalAvailable);
    }

    /**
     * The grant expires on the agreement's own rollover policy.
     *
     * This is why it is a carrier month rather than hours added to the start
     * month: an agreement that carries nothing forward carries this forward
     * neither. Added to January directly, the remainder would outlive every
     * other unused hour on the same agreement.
     */
    public function test_the_opening_rollover_expires_on_the_agreements_own_rollover_policy(): void
    {
        $company = $this->company();
        $agreement = $this->agreement($company, [
            'active_date' => '2026-01-01',
            'monthly_retainer_hours' => 10,
            'rollover_months' => 0,
            'initial_rollover_hours' => 5,
            'retainer_hours' => null,
        ]);

        $ledger = (new InvoiceLedgerBuilder)->buildAgreementLedgerThrough(
            $company,
            $agreement,
            Carbon::parse('2026-01-31'),
        );

        $january = $ledger[count($ledger) - 1];
        $this->assertSame('2026-01', $january->yearMonth);
        $this->assertSame(0.0, $january->opening->rolloverHours, 'Nothing carries with no policy');
        $this->assertSame(10.0, $january->opening->totalAvailable);
    }

    /**
     * Under a replay basis the grant lands against the recorded start.
     *
     * The basis moves the ledger's opening back a period; the carry-in belongs
     * where the predecessor recorded it, not a period earlier, which would
     * grant capacity before the history the basis exists to reproduce. The
     * carrier month is already occupied in that case, so the hours are added to
     * it rather than duplicating its key.
     */
    public function test_the_opening_rollover_attaches_to_the_recorded_start_under_a_replay_basis(): void
    {
        $company = $this->company();
        $project = $this->project($company);
        $agreement = $this->agreement($company, [
            'active_date' => '2026-01-01',
            'billing_cadence' => 'monthly',
            'monthly_retainer_hours' => 10,
            'rollover_months' => 1,
            'initial_rollover_hours' => 5,
            'retainer_hours' => null,
        ]);
        $this->entry($company, $project, [
            'date_worked' => '2025-12-15',
            'minutes_worked' => 600,
        ]);

        $basis = new ReplayHistoryBasis;
        $basis->seed($agreement, Carbon::parse('2025-12-01'));
        $ledger = (new InvoiceLedgerBuilder(replayHistoryBasis: $basis))->buildAgreementLedgerThrough(
            $company,
            $agreement,
            Carbon::parse('2026-01-31'),
        );

        $keys = array_map(static fn (MonthSummary $month): string => $month->yearMonth, $ledger);
        $this->assertSame(['2025-12', '2026-01'], $keys, 'No month is added ahead of the basis');
        $this->assertSame(15.0, $ledger[0]->retainerHours, 'The grant joins the basis month');
        $this->assertSame(10.0, $ledger[0]->hoursWorked);
        $this->assertSame(5.0, $ledger[1]->opening->rolloverHours, 'And reaches the recorded start');
        $this->assertSame('2026-01-01', $agreement->fresh()->starts_on?->toDateString());
    }

    /**
     * The carrier month carries capacity, never work, so the grant arrives whole.
     *
     * What this pins is the arrival: the full grant is available in the first
     * recorded month and is still whole at its close, drawn from that month's
     * own retainer first.
     *
     * What it deliberately does not claim is that the entry window is what
     * keeps the carrier month empty. It is not: widening the window a month
     * back changes nothing observable here, because the ledger emits months
     * only from its opening forward and work grouped into a month it never
     * emits is dropped without trace. The carrier reports zero worked because
     * it is constructed that way. Anyone tempted to make it read entries
     * instead should note that the grant would then be consumable by work done
     * before the agreement existed.
     */
    public function test_work_before_the_recorded_start_does_not_consume_the_opening_rollover(): void
    {
        $company = $this->company();
        $project = $this->project($company);
        $agreement = $this->agreement($company, [
            'active_date' => '2026-01-01',
            'monthly_retainer_hours' => 10,
            'rollover_months' => 1,
            'initial_rollover_hours' => 5,
            'retainer_hours' => null,
        ]);

        // Eight hours in the carrier month itself, before the agreement began.
        $this->entry($company, $project, [
            'date_worked' => '2025-12-10',
            'minutes_worked' => 480,
        ]);

        $ledger = (new InvoiceLedgerBuilder)->buildAgreementLedgerThrough(
            $company,
            $agreement,
            Carbon::parse('2026-01-31'),
        );

        $this->assertSame('2025-12', $ledger[0]->yearMonth);
        $this->assertSame(0.0, $ledger[0]->hoursWorked, 'The pre-agreement work is not in the ledger');
        $this->assertSame(5.0, $ledger[1]->opening->rolloverHours, 'So the whole grant reaches January');

        // The same eight hours inside the agreement's own first month, which is
        // where they would have to be to consume it.
        $this->entry($company, $project, [
            'date_worked' => '2026-01-10',
            'minutes_worked' => 480,
        ]);

        $consumed = (new InvoiceLedgerBuilder)->buildAgreementLedgerThrough(
            $company,
            $agreement->fresh(),
            Carbon::parse('2026-01-31'),
        );

        $this->assertSame(8.0, $consumed[1]->hoursWorked);
        $this->assertSame(15.0, $consumed[1]->opening->totalAvailable);

        // Drawn from January's own retainer first, so the grant is still whole
        // at the close. `unusedHours` is the month's own remainder and does not
        // fold the carried hours in - which is why asserting on it alone would
        // not have distinguished a grant that survived from one that was never
        // made.
        $this->assertSame(8.0, $consumed[1]->closing->hoursUsedFromRetainer);
        $this->assertSame(0.0, $consumed[1]->closing->hoursUsedFromRollover);
        $this->assertSame(2.0, $consumed[1]->closing->unusedHours);
        $this->assertSame(5.0, $consumed[1]->closing->remainingRollover);
    }

    /**
     * A ledger that stops before the carrier month grants nothing.
     *
     * Reachable only under a replay basis, which is the one thing that can put
     * the ledger's opening earlier than the agreement's recorded start. Without
     * the guard the carrier is prepended anyway and the rollover calculator -
     * which reads the array in order - is handed a later month at the front of
     * an earlier sequence.
     */
    public function test_a_ledger_ending_before_the_carrier_month_grants_no_opening_capacity(): void
    {
        $company = $this->company();
        $agreement = $this->agreement($company, [
            'active_date' => '2026-01-01',
            'monthly_retainer_hours' => 10,
            'rollover_months' => 1,
            'initial_rollover_hours' => 5,
            'retainer_hours' => null,
        ]);

        $basis = new ReplayHistoryBasis;
        $basis->seed($agreement, Carbon::parse('2025-09-01'));
        $ledger = (new InvoiceLedgerBuilder(replayHistoryBasis: $basis))->buildAgreementLedgerThrough(
            $company,
            $agreement,
            // Stops two months before the carrier month, 2025-12.
            Carbon::parse('2025-10-31'),
        );

        $keys = array_map(static fn (MonthSummary $month): string => $month->yearMonth, $ledger);
        $this->assertSame(['2025-09', '2025-10'], $keys, 'In order, and no carrier among them');
        $this->assertSame(10.0, $ledger[0]->opening->totalAvailable, 'Opening on its own retainer alone');
    }

    /**
     * An agreement with period retainer terms never receives the grant.
     *
     * `buildPeriodRetainerLedgerThrough` returns before the grant is applied,
     * so the opening rollover reaches the monthly ledger only. This is the
     * branch boundary the audit in #137 counts on when it excludes cadence
     * agreements from the affected population; if the grant ever moved above
     * that return, the audit would be undercounting and nothing else would say
     * so.
     */
    public function test_a_period_retainer_agreement_never_receives_an_opening_rollover(): void
    {
        $company = $this->company();
        $agreement = $this->agreement($company, [
            'active_date' => '2026-01-01',
            'billing_cadence' => 'quarterly',
            'monthly_retainer_hours' => 10,
            'rollover_months' => 1,
            'initial_rollover_hours' => 5,
            'retainer_hours' => 30,
        ]);

        $ledger = (new InvoiceLedgerBuilder)->buildAgreementLedgerThrough(
            $company,
            $agreement,
            Carbon::parse('2026-01-31'),
        );

        $this->assertSame('2026-01', $ledger[0]->yearMonth, 'The cycle opens at the agreement start');
        $this->assertSame(0.0, $ledger[0]->opening->rolloverHours);

        // The same terms without the period override, so the assertion is
        // pinned to the branch rather than to the cadence arithmetic.
        $agreement->forceFill(['period_retainer_minutes' => null])->save();
        $monthly = (new InvoiceLedgerBuilder)->buildAgreementLedgerThrough(
            $company,
            $agreement->fresh(),
            Carbon::parse('2026-01-31'),
        );

        $this->assertSame('2025-12', $monthly[0]->yearMonth, 'Which does receive one');
        $this->assertSame(5.0, $monthly[1]->opening->rolloverHours);
    }

    /**
     * The grant lives exactly as long as any other unused hour.
     *
     * `rollover_months = 2` keeps the carrier month's remainder spendable
     * through the second month after it and expires it at the end of that one.
     * Asserting the expiry as well as the survival is what distinguishes a
     * grant that ages on the agreement's policy from one that never ages.
     */
    public function test_the_opening_rollover_ages_on_the_agreements_window_like_any_other_hour(): void
    {
        $company = $this->company();
        $agreement = $this->agreement($company, [
            'active_date' => '2026-01-01',
            'monthly_retainer_hours' => 0,
            'rollover_months' => 2,
            'initial_rollover_hours' => 6,
            'retainer_hours' => null,
        ]);

        $ledger = (new InvoiceLedgerBuilder)->buildAgreementLedgerThrough(
            $company,
            $agreement,
            Carbon::parse('2026-03-31'),
        );

        $byMonth = [];
        foreach ($ledger as $month) {
            $byMonth[$month->yearMonth] = $month;
        }

        $this->assertSame(6.0, $byMonth['2026-01']->opening->rolloverHours, 'Spendable in the first month');
        $this->assertSame(6.0, $byMonth['2026-02']->opening->rolloverHours, 'And the second');
        $this->assertSame(0.0, $byMonth['2026-03']->opening->rolloverHours, 'Gone in the third');
        $this->assertSame(6.0, $byMonth['2026-03']->opening->expiredHours, 'Reported as expired once');
    }

    /**
     * The grant is rounded to the ledger's own four-decimal precision.
     *
     * Both paths round, and neither had a value that could show it: every
     * fixture above uses whole hours, where rounding and its precision are
     * invisible. A hundred minutes is one and two thirds of an hour, so it
     * distinguishes `round(..., 4)` from no rounding at all and from a
     * neighbouring precision - which is what the ledger's own arithmetic needs,
     * since these hours are added to retainer figures carried at four places.
     */
    public function test_the_grant_is_rounded_to_the_ledgers_precision(): void
    {
        $company = $this->company();
        $agreement = $this->agreement($company, [
            'active_date' => '2026-01-01',
            'monthly_retainer_hours' => 10,
            'rollover_months' => 1,
            'retainer_hours' => null,
        ]);
        // 100 minutes: 1.6666... hours, which four places render as 1.6667.
        $agreement->forceFill(['initial_rollover_minutes' => 100])->save();

        $prepended = (new InvoiceLedgerBuilder)->buildAgreementLedgerThrough(
            $company,
            $agreement->fresh(),
            Carbon::parse('2026-01-31'),
        );

        $this->assertSame('2025-12', $prepended[0]->yearMonth);
        $this->assertSame(1.6667, $prepended[0]->retainerHours, 'The carrier month it creates');

        // And the same on the path that adds to a month already there.
        $basis = new ReplayHistoryBasis;
        $basis->seed($agreement, Carbon::parse('2025-12-01'));
        $merged = (new InvoiceLedgerBuilder(replayHistoryBasis: $basis))->buildAgreementLedgerThrough(
            $company,
            $agreement->fresh(),
            Carbon::parse('2026-01-31'),
        );

        $this->assertSame('2025-12', $merged[0]->yearMonth);
        $this->assertSame(11.6667, $merged[0]->retainerHours, 'The month it joins');
    }

    public function test_the_ledger_refuses_time_whose_project_belongs_to_another_company(): void
    {
        $company = $this->company();
        $otherCompany = ClientCompany::query()->create([
            'workspace_id' => $company->workspace_id,
            'name' => 'Other Ledger Client',
            'slug' => 'other-ledger-client-'.uniqid(),
        ]);
        $otherProject = $this->project($otherCompany);
        $agreement = $this->agreement($company, [
            'active_date' => '2026-01-01',
            'monthly_retainer_hours' => 10,
        ]);

        $this->entry($company, $otherProject, [
            'date_worked' => '2026-01-15',
            'minutes_worked' => 120,
            'is_billable' => true,
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('project outside this client company');

        (new InvoiceLedgerBuilder)->buildAgreementLedgerThrough(
            $company,
            $agreement,
            Carbon::parse('2026-01-31'),
        );
    }

    public function test_the_ledger_refuses_time_whose_project_belongs_to_another_workspace(): void
    {
        $company = $this->company();
        $otherCompany = $this->company();
        $otherProject = $this->project($otherCompany);
        $agreement = $this->agreement($company, [
            'active_date' => '2026-01-01',
            'monthly_retainer_hours' => 10,
        ]);

        // The composite tenant keys refuse this row now. It is written with
        // enforcement suspended because the ledger's own refusal is what is under
        // test, and a database migrated from before those keys can still hold one.
        $this->writingLegacyCrossTenantRows(fn () => $this->entry($company, $otherProject, [
            'date_worked' => '2026-01-15',
            'minutes_worked' => 120,
            'is_billable' => true,
        ]));

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('project outside this client company');

        (new InvoiceLedgerBuilder)->buildAgreementLedgerThrough(
            $company,
            $agreement,
            Carbon::parse('2026-01-31'),
        );
    }

    public function test_summarize_legacy_monthly_ledger_counts_mid_month_boundary_once(): void
    {
        $company = $this->company();
        $agreement = $this->agreement($company, [
            'active_date' => '2024-02-15',
            'termination_date' => null,
            'monthly_retainer_hours' => 10,
            'rollover_months' => 0,
            'initial_rollover_hours' => 0,
            'retainer_hours' => null,
            'billing_cadence' => BillingCadence::Quarterly->value,
        ]);

        $cycles = iterator_to_array((new BillingCycleResolver)->cyclesForAgreement(
            $agreement,
            Carbon::parse('2024-08-14'),
        ));
        $ledger = [
            $this->summary('2024-02', hoursWorked: 1.0, retainerHours: 10.0),
            $this->summary('2024-03', hoursWorked: 2.0, retainerHours: 10.0),
            $this->summary('2024-04', hoursWorked: 3.0, retainerHours: 10.0),
            $this->summary('2024-05', hoursWorked: 4.0, retainerHours: 10.0),
            $this->summary('2024-06', hoursWorked: 5.0, retainerHours: 10.0),
            $this->summary('2024-07', hoursWorked: 6.0, retainerHours: 10.0),
            $this->summary('2024-08', hoursWorked: 7.0, retainerHours: 10.0),
        ];

        $builder = new InvoiceLedgerBuilder;
        $firstCycle = $builder->summarizeLedgerForCycle($agreement, $ledger, $cycles[0]);
        $secondCycle = $builder->summarizeLedgerForCycle($agreement, $ledger, $cycles[1]);

        $this->assertSame(70.0, $firstCycle['retainer_hours'] + $secondCycle['retainer_hours']);
        $this->assertSame(28.0, $firstCycle['hours_worked'] + $secondCycle['hours_worked']);
        $this->assertSame(30.0, $secondCycle['retainer_hours']);
        $this->assertSame(18.0, $secondCycle['hours_worked']);
    }

    public function test_summarize_legacy_monthly_ledger_moves_boundary_month_to_truncated_final_cycle(): void
    {
        $company = $this->company();
        $agreement = $this->agreement($company, [
            'active_date' => '2024-02-15',
            'termination_date' => '2024-05-20',
            'monthly_retainer_hours' => 10,
            'rollover_months' => 0,
            'initial_rollover_hours' => 0,
            'retainer_hours' => null,
            'billing_cadence' => BillingCadence::Quarterly->value,
        ]);

        $cycles = iterator_to_array((new BillingCycleResolver)->cyclesForAgreement(
            $agreement,
            Carbon::parse('2024-08-14'),
        ));
        $ledger = [
            $this->summary('2024-02', hoursWorked: 1.0, retainerHours: 10.0),
            $this->summary('2024-03', hoursWorked: 2.0, retainerHours: 10.0),
            $this->summary('2024-04', hoursWorked: 3.0, retainerHours: 10.0),
            $this->summary('2024-05', hoursWorked: 4.0, retainerHours: 10.0),
        ];

        $builder = new InvoiceLedgerBuilder;
        $firstCycle = $builder->summarizeLedgerForCycle($agreement, $ledger, $cycles[0]);
        $finalCycle = $builder->summarizeLedgerForCycle($agreement, $ledger, $cycles[1]);

        $this->assertSame('2024-05-15', $cycles[1]->start->toDateString());
        $this->assertSame('2024-05-20', $cycles[1]->end->toDateString());
        $this->assertSame(30.0, $firstCycle['retainer_hours']);
        $this->assertSame(6.0, $firstCycle['hours_worked']);
        $this->assertSame(10.0, $finalCycle['retainer_hours']);
        $this->assertSame(4.0, $finalCycle['hours_worked']);
        $this->assertSame(40.0, $firstCycle['retainer_hours'] + $finalCycle['retainer_hours']);
        $this->assertSame(10.0, $firstCycle['hours_worked'] + $finalCycle['hours_worked']);
    }

    public function test_ledger_row_belongs_to_cycle_through_respects_cycle_owner_and_period_end(): void
    {
        $builder = new InvoiceLedgerBuilder;
        $cycleMonthStart = Carbon::parse('2026-02-01');
        $periodMonthEnd = Carbon::parse('2026-03-01');

        $this->assertTrue($builder->ledgerRowBelongsToCycleThrough(
            $this->summary('2026-03', '2026-02-01'),
            '2026-02-01',
            $cycleMonthStart,
            $periodMonthEnd,
        ));
        $this->assertFalse($builder->ledgerRowBelongsToCycleThrough(
            $this->summary('2026-04', '2026-02-01'),
            '2026-02-01',
            $cycleMonthStart,
            $periodMonthEnd,
        ));
        $this->assertTrue($builder->ledgerRowBelongsToCycleThrough(
            $this->summary('2026-03'),
            '2026-02-01',
            $cycleMonthStart,
            $periodMonthEnd,
        ));
    }

    public function test_find_ledger_month_prefers_matching_cycle_start(): void
    {
        $first = $this->summary('2026-03', '2026-02-01');
        $second = $this->summary('2026-03', '2026-03-01');

        $builder = new InvoiceLedgerBuilder;

        $this->assertSame($second, $builder->findLedgerMonth([$first, $second], '2026-03', '2026-03-01'));
        $this->assertSame($first, $builder->findLedgerMonth([$first, $second], '2026-03'));
        $this->assertNull($builder->findLedgerMonth([$first, $second], '2026-04'));
    }

    private function summary(
        string $yearMonth,
        ?string $cycleStart = null,
        float $hoursWorked = 0.0,
        float $retainerHours = 0.0,
    ): MonthSummary {
        return new MonthSummary(
            opening: new OpeningBalance(
                retainerHours: 0.0,
                rolloverHours: 0.0,
                expiredHours: 0.0,
                totalAvailable: 0.0,
                negativeOffset: 0.0,
                invoicedNegativeBalance: 0.0,
                effectiveRetainerHours: 0.0,
                remainingNegativeBalance: 0.0,
            ),
            closing: new ClosingBalance(
                hoursUsedFromRetainer: 0.0,
                hoursUsedFromRollover: 0.0,
                unusedHours: 0.0,
                excessHours: 0.0,
                negativeBalance: 0.0,
                remainingRollover: 0.0,
            ),
            hoursWorked: $hoursWorked,
            yearMonth: $yearMonth,
            retainerHours: $retainerHours,
            cycleStart: $cycleStart,
        );
    }

    // ── Construction only ────────────────────────────────────────────────────
    // These translate the engine's vocabulary to this schema's columns and
    // units. Every assertion above is the one the predecessor shipped.

    private function company(): ClientCompany
    {
        $workspace = Workspace::query()->create([
            'name' => 'Ledger', 'slug' => 'ledger-'.uniqid(),
        ]);

        return ClientCompany::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Ledger Client',
            'slug' => 'ledger-client-'.uniqid(),
        ]);
    }

    private function project(ClientCompany $company): ClientProject
    {
        return ClientProject::query()->create([
            'workspace_id' => $company->workspace_id,
            'client_company_id' => $company->id,
            'name' => 'Ledger Project',
        ]);
    }

    /** @param array<string, mixed> $terms */
    private function agreement(ClientCompany $company, array $terms): ClientAgreement
    {
        return ClientAgreement::query()->create([
            'workspace_id' => $company->workspace_id,
            'client_company_id' => $company->id,
            'title' => 'Retainer',
            'status' => 'active',
            'currency' => 'USD',
            'starts_on' => $terms['active_date'] ?? null,
            'ends_on' => $terms['termination_date'] ?? null,
            'billing_cadence' => $terms['billing_cadence'] ?? 'monthly',
            'rollover_months' => $terms['rollover_months'] ?? 0,
            'retainer_minutes' => (int) round((float) ($terms['monthly_retainer_hours'] ?? 0) * 60),
            'initial_rollover_minutes' => (int) round((float) ($terms['initial_rollover_hours'] ?? 0) * 60),
            'period_retainer_minutes' => ($terms['retainer_hours'] ?? null) === null
                ? null
                : (int) round((float) $terms['retainer_hours'] * 60),
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function entry(ClientCompany $company, ClientProject $project, array $attributes): ClientTimeEntry
    {
        return ClientTimeEntry::query()->create([
            'workspace_id' => $company->workspace_id,
            'client_company_id' => $company->id,
            'client_project_id' => $project->id,
            'user_id' => User::factory()->create()->id,
            'worked_on' => $attributes['date_worked'],
            'minutes' => $attributes['minutes_worked'],
            'description' => 'Ledger work',
            'is_billable' => $attributes['is_billable'] ?? true,
            'is_deferred' => false,
            'status' => 'approved',
            'currency' => 'USD',
        ]);
    }
}
