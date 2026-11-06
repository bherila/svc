<?php

namespace Tests\Feature\Revenue;

use App\Models\ClientAgreementRecurringItem;
use App\Models\ClientInvoiceLine;
use App\Services\Revenue\RevenueActuals;
use App\Services\Revenue\RevenueActualsRepository;
use App\Support\Revenue\ActualAmount;
use App\Support\Revenue\BandAmounts;
use App\Support\Revenue\RevenueClient;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsRevenueFixtures;
use Tests\Concerns\WritesLegacyCrossTenantRows;
use Tests\TestCase;

/**
 * The tenant-scoped read behind the actuals: invoiced and collected money by
 * client, placement date and band, and open receivables, for the workspace
 * alone and in queries that do not grow with its invoices.
 */
final class RevenueActualsRepositoryTest extends TestCase
{
    use BuildsRevenueFixtures;
    use RefreshDatabase;
    use WritesLegacyCrossTenantRows;

    private const string AS_OF = '2026-11-06';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-06 12:00:00');
    }

    public function test_actuals_place_bands_by_service_month_before_tax_and_ignore_draft_and_void(): void
    {
        $mine = $this->revenueWorkspace('mine');
        $this->revenueWorkspace('theirs', 7);

        $actuals = app(RevenueActualsRepository::class)->load($mine['workspace'], CarbonImmutable::parse(self::AS_OF));
        $monthly = RevenueActuals::monthly($actuals->invoiced);
        $alpha = $mine['alpha']->id;

        // July: the retainer and the hosting item sold on 1 July, and July's overage.
        $this->assertSame([130000, 20000, 0], $this->bands($monthly[$alpha]['2026-07']));
        // October: the October retainer and hosting, October's overage less the credit, and the expense.
        $this->assertSame([130000, 58000, 5000], $this->bands($monthly[$alpha]['2026-10']));
        // November: the retainer October's invoice sold in advance.
        $this->assertSame([100000, 0, 0], $this->bands($monthly[$alpha]['2026-11']));
        $this->assertArrayNotHasKey('2026-05', $monthly[$alpha]);
        $this->assertSame(['Synthetic Alpha mine', 'Synthetic Beta mine', 'Synthetic Delta mine', 'Synthetic Gamma mine'], $this->names($actuals->clients));

        // Collected: September's payment of October's retainer and hosting, before tax.
        $collected = RevenueActuals::monthly($actuals->collected);
        $this->assertSame([130000, 0, 0], $this->bands($collected[$alpha]['2026-10']));
        // August's invoice, paid on 2 September and a tenth refunded, spread over its bands before tax.
        $this->assertSame([90000, 36000, 0], $this->bands($collected[$alpha]['2026-09']));

        $this->assertCount(1, $actuals->openInvoices);
        $this->assertSame([169000, '2026-11-01'], [$actuals->openInvoices[0]->balance, $actuals->openInvoices[0]->dueDate?->toDateString()]);
    }

    public function test_the_number_of_queries_does_not_grow_with_the_invoices_read(): void
    {
        $small = $this->revenueWorkspace('small');
        $large = $this->revenueWorkspace('large');
        foreach (range(1, 4) as $copy) {
            foreach (ClientInvoiceLine::query()->where('workspace_id', $large['workspace']->id)->get() as $line) {
                ClientInvoiceLine::query()->create([...$line->only(['workspace_id', 'client_invoice_id', 'type', 'description', 'quantity', 'unit_amount', 'tax_amount', 'total_amount', 'line_date', 'sort_order']),
                    'client_agreement_id' => $line->getAttribute('client_agreement_id'), 'client_agreement_recurring_item_id' => $line->getAttribute('client_agreement_recurring_item_id')]);
            }
        }

        $count = function (array $fixture): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $actuals = app(RevenueActualsRepository::class)->load($fixture['workspace'], CarbonImmutable::parse(self::AS_OF));
            $this->assertNotSame([], $actuals->invoiced);
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        $this->assertSame($count($small), $count($large));
        $this->assertLessThanOrEqual(5, $count($small));
    }

    public function test_rows_of_another_workspace_carrying_this_workspaces_keys_are_never_read(): void
    {
        $mine = $this->revenueWorkspace('mine');
        $theirs = $this->revenueWorkspace('theirs', 7);
        $this->writingLegacyCrossTenantRows(function () use ($mine, $theirs): void {
            ClientAgreementRecurringItem::query()->create([
                'workspace_id' => $theirs['workspace']->id, 'client_agreement_id' => $mine['agreement']->id, 'description' => 'Foreign item', 'cadence' => 'monthly',
                'anchor_day' => 1, 'quantity' => '1.000', 'amount' => 777777, 'currency' => 'USD', 'is_taxable' => false, 'is_active' => true, 'sort_order' => 9,
            ]);
            ClientInvoiceLine::query()->create([
                'workspace_id' => $theirs['workspace']->id, 'client_invoice_id' => $mine['october']->id, 'client_agreement_id' => $mine['agreement']->id,
                'type' => 'additional_hours', 'description' => 'Foreign line', 'quantity' => '1', 'unit_amount' => 555555, 'tax_amount' => 0, 'total_amount' => 555555, 'line_date' => '2026-10-31', 'sort_order' => 9,
            ]);
        });

        $actuals = app(RevenueActualsRepository::class)->load($mine['workspace'], CarbonImmutable::parse(self::AS_OF));

        $this->assertSame(58000, RevenueActuals::monthly($actuals->invoiced)[$mine['alpha']->id]['2026-10']->overageAndOther);
        $this->assertSame([], array_values(array_filter($actuals->invoiced, static fn (ActualAmount $amount): bool => $amount->amount === 555555)));
    }

    /** @return array{int, int, int} */
    private function bands(BandAmounts $bands): array
    {
        return [$bands->recurring, $bands->overageAndOther, $bands->expenses];
    }

    /**
     * @param  array<int, RevenueClient>  $clients
     * @return list<string>
     */
    private function names(array $clients): array
    {
        $names = array_values(array_map(static fn ($client): string => $client->name, $clients));
        sort($names);

        return $names;
    }
}
