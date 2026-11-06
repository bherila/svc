<?php

namespace Tests\Feature\Revenue;

use App\Models\ClientAgreementRecurringItem;
use App\Models\ClientInvoiceLine;
use App\Services\Revenue\ProjectionInputsRepository;
use App\Support\Revenue\ProjectedAgreement;
use App\Support\Revenue\ProjectionInputs;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsRevenueFixtures;
use Tests\Concerns\WritesLegacyCrossTenantRows;
use Tests\TestCase;

/**
 * The tenant-scoped read behind the projection: what it returns for a synthetic
 * workspace, that nothing of another workspace reaches it, and that its
 * queries do not grow with the invoices it reads.
 */
final class ProjectionInputsRepositoryTest extends TestCase
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

    public function test_projection_inputs_read_the_workspaces_agreements_items_overage_and_billed_charges(): void
    {
        $mine = $this->revenueWorkspace('mine');
        $this->revenueWorkspace('theirs', 7);

        $inputs = app(ProjectionInputsRepository::class)->load($mine['workspace'], CarbonImmutable::parse(self::AS_OF));

        $this->assertSame(['Synthetic Alpha retainer', 'Synthetic Gamma retainer', 'Synthetic Beta quarterly'], array_map(static fn (ProjectedAgreement $agreement): string => $agreement->title, $inputs->agreements));
        $alpha = $inputs->agreements[0];
        $this->assertSame([$mine['alpha']->public_id, 'Synthetic Alpha mine'], [$alpha->clientPublicId, $alpha->clientName]);
        $this->assertSame([$mine['item']->public_id], array_map(static fn ($item): string => $item->publicId, $alpha->recurringItems));
        // Billed overage before tax, by service month; draft and void invoices count nothing.
        $this->assertSame([$alpha->id => ['2026-07' => 20000, '2026-08' => 40000, '2026-10' => 60000]], $inputs->overageHistory);
        // October's invoice sold November's retainer in advance.
        $this->assertSame([ProjectionInputs::retainerKey($alpha->id, '2026-11-01') => true], $inputs->billedCharges);

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
            $inputs = app(ProjectionInputsRepository::class)->load($fixture['workspace'], CarbonImmutable::parse(self::AS_OF));
            $this->assertNotSame([], $inputs->agreements);
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

        $inputs = app(ProjectionInputsRepository::class)->load($mine['workspace'], CarbonImmutable::parse(self::AS_OF));

        $this->assertCount(1, $inputs->agreements[0]->recurringItems);
        $this->assertSame(60000, $inputs->overageHistory[$inputs->agreements[0]->id]['2026-10']);
    }
}
