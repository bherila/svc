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
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * The worked examples the retainer engine was specified against.
 *
 * These carry exact figures rather than shapes, which is the point: they are
 * the check that the ported arithmetic still produces the same invoice, to the
 * cent, as the system whose data was migrated here. A refactor that keeps every
 * structural test green and moves one of these numbers has changed what clients
 * are charged.
 *
 * The predecessor's fixtures are written in decimal hours and dollars; this
 * schema stores minutes and minor units. Only the construction is translated -
 * every assertion is the original one.
 */
final class InvoicingExamplesTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private ClientCompany $company;

    private ClientProject $project;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workspace = Workspace::query()->create(['name' => 'Examples', 'slug' => 'examples']);
        $this->company = ClientCompany::query()->create([
            'workspace_id' => $this->workspace->id, 'name' => 'Test Company', 'slug' => 'test-company',
        ]);
        $this->project = ClientProject::query()->create([
            'workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id, 'name' => 'Test Project',
        ]);
        $this->user = User::factory()->create();
    }

    /**
     * Example 1 - one large entry against a small retainer.
     *
     * 10h worked in January against a 2h retainer that begins in February. Two
     * hours are covered retroactively; the remaining eight, plus the one-hour
     * buffer the next cycle needs, are billed.
     */
    public function test_a_single_large_entry_splits_across_retainer_and_catch_up(): void
    {
        $this->agreement(retainerMinutes: 120, thresholdMinutes: 60, hourlyRate: 15000, retainerAmount: 30000);
        $this->entry('2024-01-15', 600);

        $invoice = $this->generateJanuary();
        $lines = $invoice->lines()->get();

        $priorMonth = $lines->where('type', 'prior_month_retainer');
        $this->assertSame(2.0, (float) $priorMonth->sum('hours'), 'Should have 2h prior month retainer coverage');
        $this->assertSame(0, (int) $priorMonth->sum('total_amount'), 'Prior month retainer lines should be free');

        $additional = $lines->firstWhere('type', 'additional_hours');
        $this->assertNotNull($additional, 'Should have an additional_hours line');
        $this->assertSame(9.0, (float) $additional->hours, 'Should bill 9h (8h overage + 1h buffer)');
        $this->assertSame(135000, (int) $additional->total_amount, '9h at 150.00 is 1350.00');

        $retainer = $lines->firstWhere('type', 'retainer');
        $this->assertNotNull($retainer);
        $this->assertSame(30000, (int) $retainer->total_amount);

        $this->assertSame(165000, (int) $invoice->refresh()->total_amount, '300.00 retainer + 1350.00 additional');
    }

    /**
     * Example 2 - work that exactly consumes the retainer.
     *
     * The entry fits in one fragment and is never split. Catch-up still appears,
     * because consuming the whole pool leaves nothing for the buffer.
     */
    public function test_work_that_exactly_fills_the_retainer_still_bills_the_buffer(): void
    {
        $this->agreement(retainerMinutes: 120, thresholdMinutes: 60, hourlyRate: 15000, retainerAmount: 30000);
        $this->entry('2024-01-15', 120);

        $invoice = $this->generateJanuary();
        $lines = $invoice->lines()->get();

        $priorMonth = $lines->firstWhere('type', 'prior_month_retainer');
        $this->assertNotNull($priorMonth);
        $this->assertSame(2.0, (float) $priorMonth->hours);
        $this->assertSame(0, (int) $priorMonth->total_amount);

        $additional = $lines->firstWhere('type', 'additional_hours');
        $this->assertNotNull($additional, 'Should bill catch-up to restore minimum availability');
        $this->assertSame(1.0, (float) $additional->hours, 'Should bill 1h catch-up to restore the buffer');

        $this->assertSame(45000, (int) $invoice->refresh()->total_amount, '300.00 retainer + 150.00 catch-up');

        // The entry fit whole, so nothing was split.
        $this->assertSame(1, ClientTimeEntry::query()->where('client_company_id', $this->company->id)->count());
    }

    /**
     * Example 3 - a zero threshold bills the overage and nothing more.
     */
    public function test_a_zero_threshold_bills_only_the_actual_overage(): void
    {
        $this->agreement(retainerMinutes: 300, thresholdMinutes: 0, hourlyRate: 15000, retainerAmount: 75000);
        $this->entry('2024-01-15', 480);

        $invoice = $this->generateJanuary();
        $lines = $invoice->lines()->get();

        $priorMonth = $lines->firstWhere('type', 'prior_month_retainer');
        $this->assertNotNull($priorMonth);
        $this->assertSame(5.0, (float) $priorMonth->hours);

        $additional = $lines->firstWhere('type', 'additional_hours');
        $this->assertNotNull($additional);
        $this->assertSame(3.0, (float) $additional->hours, 'Should bill the 3h overage and no buffer');
        $this->assertSame(45000, (int) $additional->total_amount, '3h at 150.00 is 450.00');

        $this->assertSame(120000, (int) $invoice->refresh()->total_amount, '750.00 + 450.00');
    }

    /**
     * Example 4 - unused hours roll forward and are drawn before the retainer.
     *
     * January leaves 4h unused. February works 14h against a 10h retainer, and
     * the rollover absorbs the difference, so nothing is billed at rate.
     */
    public function test_unused_hours_roll_forward_and_absorb_the_next_overage(): void
    {
        $this->agreement(
            retainerMinutes: 600,
            thresholdMinutes: 60,
            hourlyRate: 15000,
            retainerAmount: 150000,
            startsOn: '2024-01-01',
            rolloverMonths: 2,
        );
        $this->entry('2024-01-15', 360);

        $januaryInvoice = $this->generateJanuary();
        $this->assertSame(4.0, (float) $januaryInvoice->unused_hours_balance, 'January should leave 4h unused');

        $this->entry('2024-02-15', 840);

        $februaryInvoice = app(ClientInvoicingService::class)->generateInvoice(
            $this->company,
            Carbon::parse('2024-02-01'),
            Carbon::parse('2024-02-29'),
        )->refresh();

        $this->assertSame(4.0, (float) $februaryInvoice->rollover_hours_used, 'February should draw the 4h rollover');
        $this->assertNull(
            $februaryInvoice->lines()->where('type', 'additional_hours')->first(),
            'Rollover covering the work means nothing is billed at rate',
        );
        $this->assertSame(0.0, (float) $februaryInvoice->unused_hours_balance);
    }

    /**
     * A threshold larger than the retainer can never be satisfied, so it is
     * rejected rather than quietly billing catch-up on every cycle forever.
     */
    public function test_a_threshold_larger_than_the_retainer_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('catch_up_threshold_hours must be between 0 and period retainer hours');

        $this->agreement(retainerMinutes: 300, thresholdMinutes: 600, hourlyRate: 15000, retainerAmount: 75000);
    }

    public function test_an_unset_threshold_defaults_to_one_hour(): void
    {
        $agreement = $this->agreement(
            retainerMinutes: 600,
            thresholdMinutes: null,
            hourlyRate: 15000,
            retainerAmount: 150000,
        );

        $this->assertSame(1.0, $agreement->catch_up_threshold_hours);
    }

    /**
     * An agreement with no retainer has no capacity to hold a buffer, so the
     * default must not invent one.
     */
    public function test_an_agreement_with_no_retainer_defaults_to_no_threshold(): void
    {
        $agreement = $this->agreement(
            retainerMinutes: 0,
            thresholdMinutes: null,
            hourlyRate: 15000,
            retainerAmount: 0,
        );

        $this->assertSame(0.0, $agreement->catch_up_threshold_hours);
    }

    private function generateJanuary(): ClientInvoice
    {
        return app(ClientInvoicingService::class)->generateInvoice(
            $this->company,
            Carbon::parse('2024-01-01'),
            Carbon::parse('2024-01-31'),
        )->refresh();
    }

    private function agreement(
        int $retainerMinutes,
        ?int $thresholdMinutes,
        int $hourlyRate,
        int $retainerAmount,
        string $startsOn = '2024-02-01',
        int $rolloverMonths = 3,
    ): ClientAgreement {
        return ClientAgreement::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'title' => 'Retainer',
            'status' => 'active',
            'currency' => 'USD',
            'starts_on' => $startsOn,
            'retainer_minutes' => $retainerMinutes,
            'retainer_amount' => $retainerAmount,
            'catch_up_threshold_minutes' => $thresholdMinutes,
            'hourly_rate_amount' => $hourlyRate,
            'rollover_months' => $rolloverMonths,
        ]);
    }

    private function entry(string $workedOn, int $minutes): ClientTimeEntry
    {
        return ClientTimeEntry::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'client_project_id' => $this->project->id,
            'user_id' => $this->user->id,
            'worked_on' => $workedOn,
            'minutes' => $minutes,
            'description' => 'Work',
            'is_billable' => true,
            'is_deferred' => false,
            'status' => 'approved',
            'currency' => 'USD',
        ]);
    }
}
