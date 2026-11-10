<?php

namespace Tests\Feature\Billing;

use App\Models\ClientInvoice;
use App\Models\ClientInvoiceLine;
use App\Support\Billing\BillingCadence;
use App\Support\Billing\InvoiceStatus;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsServicePackages;
use Tests\Concerns\SignsUpThroughPackages;
use Tests\Concerns\WalksBillingHistories;
use Tests\Support\Requirement;
use Tests\TestCase;
use Tests\Unit\Packages\EnrollmentRulesTest;

/**
 * The sign-up property for a component on another cadence than its service's
 * (#695), beside {@see BillingHistorySignupTest} in a class of its own so
 * ParaTest runs the two side by side: a monthly component in a quarterly,
 * semi-annual or annual service, and a longer one - an annual licence in a
 * quarterly service, a semi-annual charge in a quarterly one - each started on
 * the 1st, the 9th and the 31st.
 */
final class BillingHistoryOffCadenceSignupTest extends TestCase
{
    use BuildsServicePackages;
    use RefreshDatabase;
    use SignsUpThroughPackages;
    use WalksBillingHistories;

    /** What the base price of every world sells for one period, in cents: the package's retainer. */
    private const int PRICE = 30000;

    /** The component on the service's own cadence. */
    private const int ON_CADENCE = 12000;

    /** The component on another cadence. */
    private const int OFF_CADENCE = 700;

    /** @return iterable<string, array{string, string, int}> */
    public static function offCadenceWorlds(): iterable
    {
        foreach ([['quarterly', 'monthly'], ['semi_annual', 'monthly'], ['annual', 'monthly'], ['annual', 'quarterly'], ['quarterly', 'annual'], ['quarterly', 'semi_annual']] as [$service, $component]) {
            foreach ([1, 9, 31] as $day) {
                yield str_replace('_', '-', "{$component} component, {$service} service, started on day {$day}") => [$service, $component, $day];
            }
        }
    }

    /**
     * Property (docs/client-management/billing.md, "Recurring items on another
     * cadence"): every billing period is invoiced and issued exactly once, and
     * charges the base price, the component on the service's cadence once on
     * its first day, and the other component once per period of its own
     * cadence laid on the service's periods - a shorter one on the period's
     * first day and each of its months after it, never past a month's end; a
     * longer one on the first day of the first of each run of periods it spans
     * - so every charge falls inside the period it is sold with (Issue #695:
     * a monthly component in a quarter from 9 January charged 9 January,
     * 1 February, 1 March and 1 April, four times in the first quarter).
     */
    #[DataProvider('offCadenceWorlds')]
    #[Requirement('ACC-3', 'INV-1', 'INV-2')]
    public function test_an_off_cadence_component_charges_once_per_period_of_its_own(string $service, string $component, int $day): void
    {
        $periodMonths = BillingCadence::from($service)->monthsInCycle();
        $componentMonths = BillingCadence::from($component)->monthsInCycle();
        [$workspace, $company, $agreement, $start] = $this->signUpOn($day, [
            'billing_cadence' => $service, 'invoice_issuing' => 'draft', 'activation_mode' => 'automatic', 'notice_days' => 0,
            'retainer_amount' => self::PRICE, 'retainer_minutes' => 600, 'first_cycle_proration' => 'full_period',
            'components' => [
                ['kind' => 'recurring', 'description' => 'Synthetic on-cadence', 'cadence' => $service, 'amount' => self::ON_CADENCE],
                ['kind' => 'recurring', 'description' => 'Synthetic off-cadence', 'cadence' => $component, 'amount' => self::OFF_CADENCE],
            ],
        ]);
        // A longer component is walked into its second charge.
        $count = max(3, intdiv($componentMonths, $periodMonths) + 1);
        $periods = $this->walkPeriods($workspace, $company, $agreement, $start, $count);

        $issued = $this->cadenceInvoices($workspace, $company)->reject(fn (ClientInvoice $invoice): bool => $invoice->status === InvoiceStatus::Draft->value)->values();
        $this->assertSame($periods, $issued->map(fn (ClientInvoice $invoice): array => [(string) $invoice->cycle_start?->toDateString(), (string) $invoice->cycle_end?->toDateString()])->all(), 'every period invoiced and issued exactly once');

        $charges = 0;
        foreach ($issued as $index => $invoice) {
            [$periodStart, $periodEnd] = $periods[$index];
            $expected = [];
            if ($componentMonths < $periodMonths) {
                for ($part = 0; $part < intdiv($periodMonths, $componentMonths); $part++) {
                    $expected[] = Carbon::parse($periodStart)->addMonthsNoOverflow($part * $componentMonths)->toDateString();
                }
            } elseif ($index % intdiv($componentMonths, $periodMonths) === 0) {
                $expected[] = $periodStart;
            }
            $lines = ClientInvoiceLine::query()->where('workspace_id', $workspace->id)->where('client_invoice_id', $invoice->id)->where('type', 'recurring_item')->orderBy('line_date')->orderBy('id')->get();
            $on = $lines->where('description', 'Synthetic on-cadence')->map(fn (ClientInvoiceLine $line): string => (string) $line->line_date?->toDateString())->values()->all();
            $off = $lines->where('description', 'Synthetic off-cadence')->map(fn (ClientInvoiceLine $line): string => (string) $line->line_date?->toDateString())->values()->all();
            $this->assertSame([$periodStart], $on, "{$periodStart}: the on-cadence component once, on the day its period starts");
            $this->assertSame($expected, $off, "{$periodStart}: the off-cadence component once per period of its own");
            foreach ($off as $date) {
                $this->assertTrue($date >= $periodStart && $date <= $periodEnd, "{$date}: inside the period {$periodStart} to {$periodEnd}");
            }
            $this->assertSame(self::PRICE + self::ON_CADENCE + self::OFF_CADENCE * count($expected), (int) $invoice->total_amount, "{$periodStart} to {$periodEnd}: billed at the terms' price");
            $charges += count($off);
        }
        // Once per period of its own cadence over the walk, not one more.
        $this->assertSame(intdiv($count * $periodMonths, $componentMonths) + ($componentMonths > $periodMonths ? 1 : 0), $charges);
    }

    /**
     * Issue #695's example as a sign-up: a $45 monthly component in a
     * quarterly service started on 9 January charges 9 January, 9 February and
     * 9 March in the first quarter, $135 ({@see EnrollmentRulesTest}
     * holds the sign-up page's estimate of that period to the same).
     */
    #[Requirement('ACC-3', 'INV-1')]
    public function test_a_monthly_component_in_a_quarter_from_the_9th_charges_three_months(): void
    {
        [$workspace, $company, $agreement, $start] = $this->signUpOn(9, [
            'billing_cadence' => 'quarterly', 'invoice_issuing' => 'draft', 'activation_mode' => 'automatic', 'notice_days' => 0,
            'retainer_amount' => self::PRICE, 'retainer_minutes' => 600, 'first_cycle_proration' => 'full_period',
            'components' => [['kind' => 'recurring', 'description' => 'Synthetic monthly hosting', 'cadence' => 'monthly', 'amount' => 4500]],
        ]);
        $this->walkPeriods($workspace, $company, $agreement, $start, 1);

        $first = $this->cadenceInvoices($workspace, $company)->firstOrFail();
        $this->assertSame(['2026-01-09', '2026-04-08'], [(string) $first->cycle_start?->toDateString(), (string) $first->cycle_end?->toDateString()]);
        $this->assertSame(
            ['2026-01-09', '2026-02-09', '2026-03-09'],
            ClientInvoiceLine::query()->where('workspace_id', $workspace->id)->where('client_invoice_id', $first->id)->where('type', 'recurring_item')->orderBy('line_date')->get()->map(fn (ClientInvoiceLine $line): string => (string) $line->line_date?->toDateString())->all(),
        );
        $this->assertSame(self::PRICE + 3 * 4500, (int) $first->total_amount);
    }
}
