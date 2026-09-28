<?php

namespace Tests\Feature\Billing;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientProject;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\ClientInvoicingService;
use App\Services\Billing\InvoiceLifecycleService;
use App\Support\Billing\InvoiceHoursStatement;
use App\Support\Billing\InvoiceHoursStatementRows;
use App\Support\Billing\InvoiceLineType;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsSyntheticExpenses;
use Tests\TestCase;

/**
 * A month's pool is drawn at most once for any piece of work.
 *
 * A monthly invoice offers its work two pools: the month the work was done in,
 * then the retainer the invoice sells for the month after. A correction range
 * ending mid-month derives its "month after" from the day after that range -
 * which is still inside the same month - so both offers were the same pool and
 * work beyond it was absorbed a second time instead of being billed.
 *
 * January leaves 4 hours unused, so February opens with 14 available (10 of
 * its own, 4 rolled in); the minimum availability is one hour.
 */
final class CorrectionPoolDrawTest extends TestCase
{
    use BuildsSyntheticExpenses;
    use RefreshDatabase;

    private Workspace $workspace;

    private ClientCompany $company;

    private ClientProject $project;

    private ClientAgreement $agreement;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-02-20 12:00:00'));
        $this->workspace = $this->syntheticWorkspace('Pool draw');
        $this->company = $this->syntheticCompany($this->workspace, 'Pool draw');
        $this->project = $this->syntheticProject($this->company, 'Pool draw');
        $this->member = $this->syntheticMember($this->workspace, 'Pool draw worker');
        $this->agreement = ClientAgreement::query()->create([
            'workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id, 'title' => 'Retainer',
            'status' => 'active', 'currency' => 'USD', 'starts_on' => '2026-01-01',
            'retainer_minutes' => 600, 'retainer_amount' => 150000, 'catch_up_threshold_minutes' => 60,
            'hourly_rate_amount' => 15000, 'billing_cadence' => 'monthly', 'rollover_months' => 1,
        ]);
        $this->entry('2026-01-12', 360);
        app(ClientInvoicingService::class)->generateInvoice(
            $this->company, Carbon::parse('2026-01-01'), Carbon::parse('2026-01-31'), $this->agreement,
        );
    }

    /** (a) Work that fits, with the minimum still left: drawn once, nothing billed. */
    public function test_a_correction_that_fits_draws_its_work_once(): void
    {
        $this->entry('2026-02-05', 600);

        [$correction, $statement] = $this->correction();

        $this->assertSame(10.0, $statement->ordinaryAppliedToWorkPool);
        $this->assertSame(0.0, $statement->ordinaryAppliedToNextRetainer);
        $this->assertSame(0.0, $statement->catchUpBilledHours);
        $this->assertSame(4.0, $statement->poolRemainingHours, '14 available less 10 of work');
        $this->assertSame(0, $correction->lines->where('type', InvoiceLineType::AdditionalHours->value)->count());
    }

    /**
     * (a') Work that fits but leaves less than the minimum. Counting the same
     * month twice made the pool look ten hours larger than it is, so the
     * minimum-availability top-up was never billed.
     */
    public function test_a_correction_that_fits_still_restores_the_minimum_from_the_real_pool(): void
    {
        $this->entry('2026-02-05', 810);

        [, $statement] = $this->correction();

        $this->assertSame(13.5, $statement->ordinaryAppliedToWorkPool);
        $this->assertSame(0.0, $statement->ordinaryAppliedToNextRetainer);
        $this->assertSame(0.0, $statement->ordinaryBilledAtRate);
        $this->assertSame(0.5, $statement->minimumAvailabilityHours);
        $this->assertSame(0.5, $statement->catchUpBilledHours);
        $this->assertSame(1.0, $statement->poolRemainingHours);
    }

    /** (b) Work beyond the pool is billed once, not absorbed by the same pool again. */
    public function test_a_correction_beyond_the_pool_bills_the_overflow_once(): void
    {
        $this->entry('2026-02-05', 960);

        [$correction, $statement] = $this->correction();

        $this->assertSame(16.0, $statement->ordinaryHours);
        $this->assertSame(14.0, $statement->ordinaryAppliedToWorkPool);
        $this->assertSame(0.0, $statement->ordinaryAppliedToNextRetainer);
        $this->assertSame(2.0, $statement->ordinaryBilledAtRate);
        $this->assertSame(3.0, $statement->catchUpBilledHours, 'Two hours over the pool, one to restore the minimum');
        $line = $correction->lines->firstWhere('type', InvoiceLineType::AdditionalHours->value);
        $this->assertSame(3.0, (float) $line?->hours);
        $this->assertSame(1.0, $statement->poolRemainingHours);
    }

    /**
     * (c) The independent constraint: an ordinary month whose work spills
     * past its own pool still draws on the next month's retainer exactly as
     * before - the fix is about the same month, not about lending.
     */
    public function test_an_ordinary_month_still_draws_on_the_next_months_retainer(): void
    {
        $this->entry('2026-02-05', 1440);
        $this->travelTo(Carbon::parse('2026-03-02 12:00:00'));

        $february = app(ClientInvoicingService::class)->generateInvoice(
            $this->company, Carbon::parse('2026-02-01'), Carbon::parse('2026-02-28'), $this->agreement,
        );
        $statement = $february->hoursStatement();

        $this->assertInstanceOf(InvoiceHoursStatement::class, $statement);
        $this->assertNull($statement->retainerSoldBy);
        $this->assertSame(14.0, $statement->ordinaryAppliedToWorkPool);
        $this->assertSame(10.0, $statement->ordinaryAppliedToNextRetainer);
        $this->assertSame(0.0, $statement->ordinaryBilledAtRate);
        $this->assertSame(1.0, $statement->catchUpBilledHours);
    }

    /**
     * Two corrections in one sold month. The second one's pool is what the
     * first left, not the month's opening again - otherwise its statement
     * shows 14 available, 2 applied and 10 remaining, which nothing on it
     * explains.
     */
    public function test_a_second_correction_in_the_month_opens_where_the_first_left_the_pool(): void
    {
        $this->entry('2026-02-05', 120);
        $this->entry('2026-02-12', 180);

        $first = $this->correction('2026-02-01', '2026-02-10')[1];
        $second = $this->correction('2026-02-11', '2026-02-15')[1];

        foreach ([$first, $second] as $statement) {
            $available = (float) InvoiceHoursStatementRows::for($statement)[0]['rows'][
                array_search('Available before this correction\'s work', array_column(InvoiceHoursStatementRows::for($statement)[0]['rows'], 'label'), true)
            ]['hours'];
            $this->assertSame($statement->poolRemainingHours, round($available - $statement->ordinaryAppliedToWorkPool, 4));
        }
        $this->assertSame(14.0, $first->availableBeforeHours);
        $this->assertSame(12.0, $first->poolRemainingHours);
        $this->assertSame($first->poolRemainingHours, $second->availableBeforeHours);
        $this->assertSame(9.0, $second->poolRemainingHours);
        $this->assertSame(
            ['label' => 'Already drawn on the February 2026 pool before this correction\'s range', 'hours' => '-2.00', 'kind' => 'row'],
            InvoiceHoursStatementRows::for($second)[0]['rows'][3],
        );
    }

    /**
     * The second of two corrections overflows only because the first already
     * drew on the month's pool. It bills that overflow itself; the next
     * ordinary invoice does not bill it again; and across all three invoices
     * the hours billed at the rate are the true overflow, exactly once.
     *
     * No minimum availability here, so the only hours at the rate are overflow.
     */
    public function test_a_later_correction_bills_the_overflow_the_earlier_one_left_it(): void
    {
        $this->agreement->forceFill(['catch_up_threshold_minutes' => 0])->save();
        $this->issue(ClientInvoice::query()->where('workspace_id', $this->workspace->id)->sole());
        $this->entry('2026-02-05', 600);
        $this->entry('2026-02-12', 360);

        [$first, $firstStatement] = $this->correction('2026-02-01', '2026-02-10');
        $this->issue($first);
        [$second, $secondStatement] = $this->correction('2026-02-11', '2026-02-15');
        $this->issue($second);

        // February: 14 available, 16 worked across the two corrections.
        foreach ([$firstStatement, $secondStatement] as $statement) {
            // The statement and the allocation agree: what was applied is what
            // the pool had, what was billed is the rest of the work, and the
            // pool is left at what it had less what was drawn on it.
            $available = (float) $statement->availableBeforeHours;
            $this->assertSame(round(min($statement->ordinaryHours, max(0.0, $available)), 4), $statement->ordinaryAppliedToWorkPool);
            $this->assertSame(round($statement->ordinaryHours - $statement->ordinaryAppliedToWorkPool, 4), $statement->catchUpBilledHours);
            $this->assertSame(round($available - $statement->ordinaryAppliedToWorkPool, 4), $statement->poolRemainingHours);
        }
        $this->assertSame(14.0, $firstStatement->availableBeforeHours);
        $this->assertSame(0.0, $firstStatement->catchUpBilledHours);
        $this->assertSame(4.0, $secondStatement->availableBeforeHours);
        $this->assertSame(4.0, $secondStatement->ordinaryAppliedToWorkPool);
        $this->assertSame(2.0, $secondStatement->catchUpBilledHours, 'The overflow is billed where it happened');

        // March: a full month of work against March's own pool.
        $this->entry('2026-03-10', 600);
        $this->travelTo(Carbon::parse('2026-04-02 12:00:00'));
        $march = app(ClientInvoicingService::class)->generateInvoice(
            $this->company, Carbon::parse('2026-03-01'), Carbon::parse('2026-03-31'), $this->agreement,
        );

        $this->assertSame(0.0, (float) $march->hours_billed_at_rate, 'February\'s overflow is not billed a second time');
        $this->assertSame(
            2.0,
            (float) $first->fresh()?->hours_billed_at_rate + (float) $second->fresh()?->hours_billed_at_rate + (float) $march->hours_billed_at_rate,
        );
    }

    private function issue(ClientInvoice $invoice): void
    {
        app(InvoiceLifecycleService::class)->issue($invoice, $this->workspace);
    }

    /** @return array{ClientInvoice, InvoiceHoursStatement} */
    private function correction(string $from = '2026-02-01', string $to = '2026-02-15'): array
    {
        $correction = app(ClientInvoicingService::class)->generateInvoice(
            $this->company, Carbon::parse($from), Carbon::parse($to), $this->agreement,
        );
        $statement = $correction->hoursStatement();
        $this->assertInstanceOf(InvoiceHoursStatement::class, $statement);
        $this->assertNotNull($statement->retainerSoldBy, 'The fixture is a correction');

        return [$correction, $statement];
    }

    private function entry(string $workedOn, int $minutes): void
    {
        ClientTimeEntry::query()->create([
            'workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id,
            'client_project_id' => $this->project->id, 'user_id' => $this->member->id,
            'worked_on' => $workedOn, 'minutes' => $minutes, 'description' => 'Synthetic pool work',
            'is_billable' => true, 'status' => 'approved', 'billing_rate_amount' => 15000, 'currency' => 'USD',
        ]);
    }
}
