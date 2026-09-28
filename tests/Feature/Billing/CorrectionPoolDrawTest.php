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
use App\Support\Billing\InvoiceHoursStatement;
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

    /** @return array{ClientInvoice, InvoiceHoursStatement} */
    private function correction(): array
    {
        $correction = app(ClientInvoicingService::class)->generateInvoice(
            $this->company, Carbon::parse('2026-02-01'), Carbon::parse('2026-02-15'), $this->agreement,
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
