<?php

namespace Tests\Feature\Square;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\SquareInvoiceSnapshot;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Existing billed-in-Square markers take their series' one location (#488),
 * from the workspace's own mirror, and are left naming none when the mirror
 * holds the series in no location, in more than one, or with a member naming
 * none.
 */
final class SquareSeriesLocationBackfillTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_marker_takes_the_one_location_its_series_is_billed_from(): void
    {
        $workspace = Workspace::query()->create(['name' => 'Synthetic backfill', 'slug' => 'synthetic-backfill']);
        $other = Workspace::query()->create(['name' => 'Synthetic backfill other', 'slug' => 'synthetic-backfill-other']);
        $marked = [];
        foreach (['000001' => 'one', '000002' => 'two', '000003' => 'none', '000004' => 'unnamed', '000005' => 'retired'] as $series => $case) {
            $marked[$case] = $this->agreement($workspace, $series);
        }
        $this->snapshot($workspace, 'inv_one_a', '000001', 'LOCFIXTURE01');
        $this->snapshot($workspace, 'inv_one_b', '000001', 'LOCFIXTURE01');
        // Another workspace's mirror holds the same prefix elsewhere: not this marker's.
        $this->snapshot($other, 'inv_one_x', '000001', 'LOCFIXTURE09');
        $this->snapshot($workspace, 'inv_two_a', '000002', 'LOCFIXTURE01');
        $this->snapshot($workspace, 'inv_two_b', '000002', 'LOCFIXTURE02');
        $this->snapshot($workspace, 'inv_unnamed_a', '000004', 'LOCFIXTURE01');
        $this->snapshot($workspace, 'inv_unnamed_b', '000004', null);
        $this->snapshot($workspace, 'inv_retired_a', '000005', 'LOCFIXTURE01');
        $this->snapshot($workspace, 'inv_retired_b', '000005', 'LOCFIXTURE02', deleted: true);

        (require database_path('migrations/2026_10_21_000100_bind_square_series_marker_to_a_location.php'))->backfill();

        $located = static fn (ClientAgreement $agreement): ?string => DB::table('client_agreements')->where('id', $agreement->id)->value('square_location_id');
        $this->assertSame(
            ['one' => 'LOCFIXTURE01', 'two' => null, 'none' => null, 'unnamed' => null, 'retired' => 'LOCFIXTURE01'],
            array_map($located, $marked),
        );
    }

    private function agreement(Workspace $workspace, string $series): ClientAgreement
    {
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic backfill '.$series, 'slug' => 'synthetic-backfill-'.$series]);
        $agreement = ClientAgreement::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'title' => 'Synthetic '.$series, 'status' => 'active', 'starts_on' => '2030-01-01', 'billing_cadence' => 'monthly', 'currency' => 'USD', 'retainer_amount' => 100]);
        $agreement->forceFill(['square_series_key' => $series])->save();

        return $agreement;
    }

    private function snapshot(Workspace $workspace, string $id, string $series, ?string $location, bool $deleted = false): void
    {
        $snapshot = SquareInvoiceSnapshot::query()->create([
            'workspace_id' => $workspace->id, 'square_invoice_id' => $id, 'invoice_number' => $series.'-R-0001', 'series_key' => $series, 'series_occurrence' => 1,
            'square_location_id' => $location, 'status' => 'UNPAID', 'currency' => 'USD', 'total_amount' => 100, 'paid_amount' => 0, 'autopay' => false,
            'payment_requests' => [], 'fingerprint' => str_repeat('a', 64), 'synced_at' => now(), 'last_seen_at' => now(),
        ]);
        if ($deleted) {
            $snapshot->delete();
        }
    }
}
