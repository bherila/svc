<?php

namespace Tests\Feature\Billing;

use App\Models\ClientInvoice;
use App\Models\ClientInvoiceLine;
use App\Support\Billing\InvoiceKind;
use App\Support\Billing\InvoiceStatus;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsServicePackages;
use Tests\Concerns\SignsUpThroughPackages;
use Tests\Concerns\WalksBillingHistories;
use Tests\Support\Requirement;
use Tests\TestCase;

/**
 * The sign-up property of a package (#685), one of the billing-history
 * invariants ({@see BillingHistoryInvariantTest}) beside the moved-and-never-
 * moved twins ({@see BillingHistoryMoveTwinTest}), in a class of its own: its
 * 72 worlds each walk a service through three of its billing periods, a few
 * years for an annual one, and ParaTest schedules by class. Components on
 * another cadence than the service's are {@see BillingHistoryOffCadenceSignupTest}'s.
 */
final class BillingHistorySignupTest extends TestCase
{
    use BuildsServicePackages;
    use RefreshDatabase;
    use SignsUpThroughPackages;
    use WalksBillingHistories;

    /** What the base price of every world sells for one period, in cents: the package's retainer. */
    private const int PRICE = 30000;

    private const int PERIODS = 3;

    /** @return iterable<string, array{string, int, string}> */
    public static function signupWorlds(): iterable
    {
        foreach (['monthly', 'quarterly', 'semi_annual', 'annual'] as $cadence) {
            foreach ([1, 9, 31] as $day) {
                foreach (['no component', 'one component', 'two components', 'a setup fee with the first cycle', 'a setup fee at sign-up', 'the first period at sign-up'] as $components) {
                    yield "{$cadence}, started on day {$day}, {$components}" => [$cadence, $day, $components];
                }
            }
        }
    }

    /**
     * Property (docs/service-packages.md, "A recurring component charges once
     * per billing period"): a new sign-up's billing periods - the engine's own
     * grid, anchored on the start - are each invoiced exactly once, at the
     * terms' price: the base price and each recurring component once, and a
     * setup fee once, on the first invoice when it is billed with the first
     * cycle and on its own invoice when it is charged at sign-up. On every
     * cadence, whatever day of the month the service started, from the first
     * invoice on (Issue #685: quarterly from 9 January was charged $240 for a
     * $120 component, 9 January and 1 April both inside the first quarter).
     */
    #[DataProvider('signupWorlds')]
    #[Requirement('ACC-3', 'INV-1', 'INV-2')]
    public function test_a_sign_up_bills_every_period_once_at_the_terms_price(string $cadence, int $day, string $components): void
    {
        $amounts = ['monthly' => 4500, 'quarterly' => 12000, 'semi_annual' => 24000, 'annual' => 48000];
        $recurring = match ($components) {
            'no component' => [],
            'two components' => [$amounts[$cadence], 700],
            'one component', 'a setup fee with the first cycle', 'a setup fee at sign-up', 'the first period at sign-up' => [$amounts[$cadence]],
        };
        $setupFee = in_array($components, ['a setup fee with the first cycle', 'a setup fee at sign-up'], true) ? 15000 : 0;
        $terms = [
            'billing_cadence' => $cadence, 'invoice_issuing' => 'draft', 'activation_mode' => 'automatic', 'notice_days' => 0,
            // A whole period's price from the first, so the price of the terms is the same for every period.
            'retainer_amount' => self::PRICE, 'retainer_minutes' => 600, 'first_cycle_proration' => 'full_period',
            'charge_setup_fee_at_signup' => $components === 'a setup fee at sign-up',
            'charge_first_period' => $components === 'the first period at sign-up',
            'components' => [
                ...array_map(static fn (int $amount, int $index): array => ['kind' => 'recurring', 'description' => 'Synthetic component '.($index + 1), 'cadence' => $cadence, 'amount' => $amount], $recurring, array_keys($recurring)),
                ...($setupFee > 0 ? [['kind' => 'setup_fee', 'description' => 'Synthetic setup', 'amount' => $setupFee]] : []),
            ],
        ];
        [$workspace, $company, $agreement, $start] = $this->signUpOn($day, $terms);
        // The base price's own periods, as the engine lays them out.
        $periods = $this->walkPeriods($workspace, $company, $agreement, $start, self::PERIODS);

        $invoices = ClientInvoice::query()->where('workspace_id', $workspace->id)->where('client_company_id', $company->id)
            ->where('status', '!=', InvoiceStatus::Void->value)->orderBy('id')->get();
        $cadenceInvoices = $this->cadenceInvoices($workspace, $company);
        // A monthly invoice is dated, and names its cycle from, the 1st of the month it sells.
        $sold = array_map(static fn (array $period): array => [$cadence === 'monthly' ? Carbon::parse($period[0])->startOfMonth()->toDateString() : $period[0], $period[1]], $periods);
        $issued = $cadenceInvoices->reject(fn (ClientInvoice $invoice): bool => $invoice->status === InvoiceStatus::Draft->value)->values();
        $this->assertSame($sold, $issued->map(fn (ClientInvoice $invoice): array => [(string) $invoice->cycle_start?->toDateString(), (string) $invoice->cycle_end?->toDateString()])->all(), 'every period invoiced and issued exactly once');
        // The next period's draft is written ahead, and is not dated yet.
        $this->assertTrue($cadenceInvoices->diff($issued)->every(fn (ClientInvoice $invoice): bool => (string) $invoice->cycle_start?->toDateString() > $periods[self::PERIODS - 1][1]), 'only periods after the walk are left in draft');

        foreach ($issued as $index => $invoice) {
            [$periodStart, $periodEnd] = $periods[$index];
            $withFirstCycle = $index === 0 && $components === 'a setup fee with the first cycle';
            $lines = ClientInvoiceLine::query()->where('client_invoice_id', $invoice->id)->where('type', 'recurring_item')->orderBy('line_date')->orderBy('id')->get();
            $this->assertSame(
                array_fill(0, count($recurring) + ($withFirstCycle ? 1 : 0), $periodStart),
                $lines->map(fn (ClientInvoiceLine $line): string => (string) $line->line_date?->toDateString())->all(),
                "{$periodStart}: each component charged once, on the day its period starts",
            );
            $this->assertSame(
                self::PRICE + array_sum($recurring) + ($withFirstCycle ? $setupFee : 0),
                (int) $invoice->total_amount,
                "{$periodStart} to {$periodEnd}: billed at the terms' price",
            );
        }
        $others = $invoices->reject(fn (ClientInvoice $invoice): bool => $invoice->invoice_kind === InvoiceKind::CadencePeriod->value)->values();
        $this->assertSame($components === 'a setup fee at sign-up' ? [$setupFee] : [], $others->map(fn (ClientInvoice $invoice): int => (int) $invoice->total_amount)->all(), 'a setup fee charged at sign-up is its own invoice');
    }
}
