<?php

namespace Tests\Unit\Revenue;

use App\Services\Billing\Balances\ClosingBalance;
use App\Services\Billing\Balances\MonthSummary;
use App\Services\Billing\Balances\OpeningBalance;
use App\Services\Revenue\AgreementHealthCalculator;
use App\Services\Revenue\RecurringRevenueProjector;
use App\Services\Revenue\RevenueSummaries;
use App\Support\Revenue\ActualAmount;
use App\Support\Revenue\AgreementHealth;
use App\Support\Revenue\OpenInvoice;
use App\Support\Revenue\ProjectedAgreement;
use App\Support\Revenue\ProjectedRecurringItem;
use App\Support\Revenue\ProjectionInputs;
use App\Support\Revenue\RevenueActualsInputs;
use App\Support\Revenue\RevenueAssumptions;
use App\Support\Revenue\RevenueBand;
use App\Support\Revenue\RevenueBasis;
use App\Support\Revenue\RevenueClient;
use App\Support\Revenue\RevenueSummary;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/** The summaries over in-memory inputs, as of 2026-11-06: no database. */
final class RevenueSummariesTest extends TestCase
{
    private const string AS_OF = '2026-11-06';

    public function test_ytd_runs_from_january_first_to_today_against_the_same_days_last_year(): void
    {
        $summary = $this->summary([
            $this->amount('2026-01-01', 1000),
            $this->amount('2026-11-06', 200),
            $this->amount('2026-11-07', 99999),
            $this->amount('2025-12-31', 50000),
            $this->amount('2025-01-01', 300),
            $this->amount('2025-11-06', 40),
            $this->amount('2025-11-07', 77777),
        ])->toArray();

        $this->assertSame(['amount' => 1200, 'from' => '2026-01-01', 'to' => '2026-11-06', 'prior_amount' => 340, 'prior_from' => '2025-01-01', 'prior_to' => '2025-11-06'], $summary['ytd']);
    }

    public function test_ttm_is_twelve_full_months_plus_the_month_to_date(): void
    {
        $summary = $this->summary([
            $this->amount('2025-11-01', 10),
            $this->amount('2025-10-31', 20000),
            $this->amount('2026-11-06', 5),
            $this->amount('2024-11-01', 7),
            $this->amount('2025-11-06', 3),
        ])->toArray();

        $this->assertSame(['2025-11-01', '2026-11-06', 18, '2024-11-01', '2025-11-06'], [$summary['ttm']['from'], $summary['ttm']['to'], $summary['ttm']['amount'], $summary['ttm']['prior_from'], $summary['ttm']['prior_to']]);
        // The month a year ago opens both windows: 7 + 20000 + 10 + 3.
        $this->assertSame(20020, $summary['ttm']['prior_amount']);
    }

    public function test_expenses_can_be_left_out(): void
    {
        $amounts = [$this->amount('2026-03-01', 1000), $this->amount('2026-03-01', 250, RevenueBand::Expenses)];

        $this->assertSame(1250, $this->summary($amounts)->toArray()['ytd']['amount']);
        $this->assertSame(1000, $this->summary($amounts, includeExpenses: false)->toArray()['ytd']['amount']);
    }

    public function test_the_collected_basis_reads_collected_amounts_and_the_projection_stays_invoiced(): void
    {
        $actuals = new RevenueActualsInputs(CarbonImmutable::parse(self::AS_OF), $this->clients(), [$this->amount('2026-02-01', 1000)], [$this->amount('2026-03-01', 600)], []);
        $inputs = new ProjectionInputs(CarbonImmutable::parse(self::AS_OF), [$this->agreement(retainerAmount: 10000)]);
        $assumptions = new RevenueAssumptions(CarbonImmutable::parse(self::AS_OF), RevenueBasis::Collected);
        $summary = (new RevenueSummaries)->summary($actuals, $inputs, (new RecurringRevenueProjector)->project($inputs), $assumptions)->toArray();

        $this->assertSame(600, $summary['ytd']['amount']);
        $this->assertSame(120000, $summary['next_12_months']['contracted']);
        $this->assertSame(['collected', 'invoiced'], [$summary['assumptions']['basis'], $summary['assumptions']['projection_basis']]);
    }

    public function test_next_twelve_months_and_the_current_month_keep_contracted_and_overage_apart(): void
    {
        $agreement = $this->agreement(retainerAmount: 10000);
        $inputs = new ProjectionInputs(CarbonImmutable::parse(self::AS_OF), [$agreement], [$agreement->id => array_fill_keys(['2026-05', '2026-06', '2026-07', '2026-08', '2026-09', '2026-10'], 3000)]);
        $actuals = new RevenueActualsInputs(CarbonImmutable::parse(self::AS_OF), $this->clients(), [$this->amount('2026-11-01', 444)], [], []);
        $projection = (new RecurringRevenueProjector)->project($inputs);

        $off = (new RevenueSummaries)->summary($actuals, $inputs, $projection, new RevenueAssumptions(CarbonImmutable::parse(self::AS_OF)))->toArray();
        $on = (new RevenueSummaries)->summary($actuals, $inputs, $projection, new RevenueAssumptions(CarbonImmutable::parse(self::AS_OF), includeProjectedOverage: true))->toArray();

        $this->assertSame(['from' => '2026-12-01', 'to' => '2027-11-30', 'contracted' => 120000, 'projected_overage' => null], $off['next_12_months']);
        $this->assertSame(36000, $on['next_12_months']['projected_overage']);
        $this->assertSame(['month' => '2026-11', 'actual' => 444, 'remaining_contracted' => 10000, 'projected_overage' => 3000], $on['current_month']);
    }

    public function test_open_receivables_count_overdue_invoices_from_the_day_after_they_fall_due(): void
    {
        $actuals = new RevenueActualsInputs(CarbonImmutable::parse(self::AS_OF), $this->clients(), [], [], [
            new OpenInvoice(10, 1000, CarbonImmutable::parse('2026-11-05')),
            new OpenInvoice(10, 500, CarbonImmutable::parse('2026-11-06')),
            new OpenInvoice(20, 300, null),
            new OpenInvoice(20, 200, CarbonImmutable::parse('2026-01-01')),
        ]);
        $inputs = new ProjectionInputs(CarbonImmutable::parse(self::AS_OF), []);
        $summaries = new RevenueSummaries;
        $assumptions = new RevenueAssumptions(CarbonImmutable::parse(self::AS_OF));

        $this->assertSame(['outstanding' => 2000, 'invoice_count' => 4, 'overdue_amount' => 1200, 'overdue_count' => 2], $summaries->summary($actuals, $inputs, (new RecurringRevenueProjector)->project($inputs), $assumptions)->toArray()['open_receivables']);
        $this->assertSame(['outstanding' => 1500, 'invoice_count' => 2, 'overdue_amount' => 1000, 'overdue_count' => 1], $summaries->summary($actuals, $inputs, (new RecurringRevenueProjector)->project($inputs), $assumptions, 10)->toArray()['open_receivables']);
    }

    public function test_changes_list_endings_with_the_monthly_amount_that_drops_and_starts(): void
    {
        $inputs = new ProjectionInputs(CarbonImmutable::parse(self::AS_OF), [
            $this->agreement(id: 1, retainerAmount: 10000, endsOn: '2027-02-28', items: [$this->item('annual', 12000)]),
            $this->agreement(id: 2, retainerAmount: 5000, endsOn: '2026-11-05'),
            $this->agreement(id: 3, retainerAmount: 7000, endsOn: '2028-01-31'),
            $this->agreement(id: 4, startsOn: '2027-01-01', retainerAmount: 8000),
            $this->agreement(id: 5, startsOn: '2026-12-01', cadence: 'one_time', retainerAmount: 9000),
        ]);
        $changes = (new RevenueSummaries)->changes($inputs);

        $this->assertSame([['agreement-1', '2027-02-28', 11000]], array_map(static fn ($c): array => [$c->agreement->publicId, $c->date->toDateString(), $c->monthlyAmount], $changes['ending']));
        $this->assertSame([['agreement-4', '2027-01-01', 8000]], array_map(static fn ($c): array => [$c->agreement->publicId, $c->date->toDateString(), $c->monthlyAmount], $changes['starting']));
    }

    public function test_the_roster_reports_mrr_ytd_ttm_series_open_and_health_per_client(): void
    {
        $actuals = new RevenueActualsInputs(CarbonImmutable::parse(self::AS_OF), $this->clients(), [
            $this->amount('2026-10-01', 1000),
            $this->amount('2026-11-02', 300),
            $this->amount('2025-12-15', 700),
            $this->amount('2026-05-01', 50, RevenueBand::Expenses, client: 20),
        ], [], [new OpenInvoice(10, 900, CarbonImmutable::parse('2026-10-01'))]);
        $agreement = $this->agreement(retainerAmount: 10000, retainerMinutes: 600, endsOn: '2026-12-31', items: [$this->item('quarterly', 3000)]);
        $inputs = new ProjectionInputs(CarbonImmutable::parse(self::AS_OF), [$agreement]);
        $ledger = [$this->ledgerRow('2026-10', 12.0, 10.0, 0.0), $this->ledgerRow('2026-11', 4.5, 10.0, 0.0)];

        $roster = (new RevenueSummaries)->clients($actuals, $inputs, new RevenueAssumptions(CarbonImmutable::parse(self::AS_OF)), [$agreement->id => $ledger]);
        $first = $roster['clients'][0]->toArray();

        $this->assertSame('client-10', $first['client_id']);
        $this->assertSame(11000, $first['mrr']);
        $this->assertSame([1300, 2000, 300], [$first['ytd'], $first['ttm']['amount'], $first['ttm']['month_to_date']]);
        $this->assertCount(12, $first['ttm']['series']);
        $this->assertSame(['month' => '2025-11', 'amount' => 0], $first['ttm']['series'][0]);
        $this->assertSame(['month' => '2025-12', 'amount' => 700], $first['ttm']['series'][1]);
        $this->assertSame(['month' => '2026-10', 'amount' => 1000], $first['ttm']['series'][11]);
        $this->assertSame([900, 900, 1], [$first['open_amount'], $first['overdue_amount'], $first['overdue_count']]);
        $this->assertTrue($first['ending_soon']);
        $this->assertFalse($first['over_retainer']);
        $this->assertSame(['2026-11-01', '2026-11-30', 4.5, 10.0, AgreementHealth::OK], [$first['agreements'][0]['period_start'], $first['agreements'][0]['period_end'], $first['agreements'][0]['hours_used'], $first['agreements'][0]['hours_included'], $first['agreements'][0]['ledger_status']]);
        $this->assertSame(['mrr' => 11000, 'ytd' => 1350, 'ttm' => 2050, 'open_amount' => 900, 'overdue_amount' => 900, 'overdue_count' => 1], $roster['totals']);
    }

    public function test_an_inactive_client_with_no_figures_is_left_off_the_roster(): void
    {
        $clients = [10 => new RevenueClient(10, 'client-10', 'Synthetic Alpha', true), 30 => new RevenueClient(30, 'client-30', 'Synthetic Gone', false)];
        $actuals = new RevenueActualsInputs(CarbonImmutable::parse(self::AS_OF), $clients, [], [], []);
        $roster = (new RevenueSummaries)->clients($actuals, new ProjectionInputs(CarbonImmutable::parse(self::AS_OF), []), new RevenueAssumptions(CarbonImmutable::parse(self::AS_OF)), []);

        $this->assertSame(['client-10'], array_map(static fn ($row): string => $row->client->publicId, $roster['clients']));
    }

    public function test_health_flags_over_retainer_and_withholds_an_unreadable_ledger(): void
    {
        $calculator = new AgreementHealthCalculator;
        $agreement = $this->agreement(retainerAmount: 10000, retainerMinutes: 600);
        $asOf = CarbonImmutable::parse(self::AS_OF);

        $over = $calculator->health($agreement, [$this->ledgerRow('2026-11', 14.0, 10.0, 4.0)], $asOf);
        $this->assertSame([14.0, 10.0, true, false], [$over->hoursUsed, $over->hoursIncluded, $over->overRetainer, $over->endsWithin60Days]);

        $unreadable = $calculator->health($agreement, null, $asOf);
        $this->assertSame([null, null, null, AgreementHealth::LEDGER_UNREADABLE], [$unreadable->hoursUsed, $unreadable->hoursIncluded, $unreadable->overRetainer, $unreadable->ledgerStatus]);

        $hourly = $calculator->health($this->agreement(), [], $asOf);
        $this->assertSame(AgreementHealth::NO_RETAINER, $hourly->ledgerStatus);
    }

    public function test_health_reads_the_whole_cadence_period(): void
    {
        $agreement = $this->agreement(cadence: 'quarterly', startsOn: '2026-01-01', retainerAmount: 10000, retainerMinutes: 600);
        $health = (new AgreementHealthCalculator)->health($agreement, [
            $this->ledgerRow('2026-09', 99.0, 10.0, 0.0),
            $this->ledgerRow('2026-10', 8.0, 10.0, 0.0),
            $this->ledgerRow('2026-11', 3.0, 10.0, 0.0),
        ], CarbonImmutable::parse(self::AS_OF));

        $this->assertSame(['2026-10-01', '2026-12-31', 11.0, 20.0], [$health->periodStart->toDateString(), $health->periodEnd->toDateString(), $health->hoursUsed, $health->hoursIncluded]);
    }

    public function test_the_period_ledger_rows_are_matched_by_their_cycle(): void
    {
        $agreement = $this->agreement(cadence: 'quarterly', startsOn: '2026-01-15', retainerAmount: 10000, retainerMinutes: 600);
        $health = (new AgreementHealthCalculator)->health($agreement, [
            $this->ledgerRow('2026-10', 5.0, 30.0, 0.0, '2026-07-15'),
            $this->ledgerRow('2026-11', 6.0, 30.0, 1.5, '2026-10-15'),
        ], CarbonImmutable::parse(self::AS_OF));

        $this->assertSame(['2026-10-15', 6.0, 30.0, true], [$health->periodStart->toDateString(), $health->hoursUsed, $health->hoursIncluded, $health->overRetainer]);
    }

    public function test_ending_soon_is_within_sixty_days_inclusive(): void
    {
        $asOf = CarbonImmutable::parse(self::AS_OF);

        $this->assertTrue(AgreementHealthCalculator::endsWithin($this->agreement(endsOn: '2027-01-05'), $asOf, 60));
        $this->assertFalse(AgreementHealthCalculator::endsWithin($this->agreement(endsOn: '2027-01-06'), $asOf, 60));
        $this->assertTrue(AgreementHealthCalculator::endsWithin($this->agreement(endsOn: self::AS_OF), $asOf, 60));
        $this->assertFalse(AgreementHealthCalculator::endsWithin($this->agreement(), $asOf, 60));
    }

    public function test_monthly_rows_span_a_year_back_and_a_year_ahead_with_their_kind(): void
    {
        $agreement = $this->agreement(retainerAmount: 10000);
        $inputs = new ProjectionInputs(CarbonImmutable::parse(self::AS_OF), [$agreement], [$agreement->id => array_fill_keys(['2026-05', '2026-06', '2026-07', '2026-08', '2026-09', '2026-10'], 600)]);
        $actuals = new RevenueActualsInputs(CarbonImmutable::parse(self::AS_OF), $this->clients(), [
            $this->amount('2026-10-03', 900),
            $this->amount('2026-10-04', 100, RevenueBand::OverageAndOther),
            $this->amount('2026-10-05', 40, RevenueBand::Expenses),
            $this->amount('2027-01-01', 5000),
        ], [], []);
        $projection = (new RecurringRevenueProjector)->project($inputs);
        $rows = (new RevenueSummaries)->monthly($actuals, $projection, new RevenueAssumptions(CarbonImmutable::parse(self::AS_OF), includeProjectedOverage: true));

        $this->assertCount(25, $rows);
        $this->assertSame(['2025-11', 'actual'], [$rows[0]['month'], $rows[0]['kind']]);
        $this->assertSame(['2026-11', 'current'], [$rows[12]['month'], $rows[12]['kind']]);
        $this->assertSame(['2027-11', 'projected'], [$rows[24]['month'], $rows[24]['kind']]);
        $this->assertSame(['recurring' => 900, 'overage_and_other' => 100, 'expenses' => 40, 'contracted' => 0, 'projected_overage' => 0, 'total' => 1040], array_intersect_key($rows[11], array_flip(['recurring', 'overage_and_other', 'expenses', 'contracted', 'projected_overage', 'total'])));
        $this->assertSame(['client-10', 1040], [$rows[11]['clients'][0]['client_id'], $rows[11]['clients'][0]['total']]);
        $this->assertSame(['contracted' => 10000, 'projected_overage' => 600, 'total' => 10600], array_intersect_key($rows[12], array_flip(['contracted', 'projected_overage', 'total'])));
        // A charge already invoiced in advance is actual in its own month, beside the projection.
        $this->assertSame([5000, 10000], [$rows[14]['recurring'], $rows[14]['contracted']]);

        $recurringOnly = (new RevenueSummaries)->monthly($actuals, $projection, new RevenueAssumptions(CarbonImmutable::parse(self::AS_OF), includeExpenses: false), 'recurring');
        $this->assertSame(['recurring' => 900, 'overage_and_other' => null, 'expenses' => null, 'contracted' => 0, 'projected_overage' => null, 'total' => 900], array_intersect_key($recurringOnly[11], array_flip(['recurring', 'overage_and_other', 'expenses', 'contracted', 'projected_overage', 'total'])));
    }

    /** @param list<ActualAmount> $invoiced */
    private function summary(array $invoiced, bool $includeExpenses = true): RevenueSummary
    {
        $actuals = new RevenueActualsInputs(CarbonImmutable::parse(self::AS_OF), $this->clients(), $invoiced, [], []);
        $inputs = new ProjectionInputs(CarbonImmutable::parse(self::AS_OF), []);

        return (new RevenueSummaries)->summary($actuals, $inputs, (new RecurringRevenueProjector)->project($inputs), new RevenueAssumptions(CarbonImmutable::parse(self::AS_OF), includeExpenses: $includeExpenses));
    }

    /** @return array<int, RevenueClient> */
    private function clients(): array
    {
        return [10 => new RevenueClient(10, 'client-10', 'Synthetic Alpha', true), 20 => new RevenueClient(20, 'client-20', 'Synthetic Beta', true)];
    }

    private function amount(string $date, int $amount, RevenueBand $band = RevenueBand::Recurring, int $client = 10): ActualAmount
    {
        return new ActualAmount($client, CarbonImmutable::parse($date), $band, $amount);
    }

    private function ledgerRow(string $month, float $worked, float $retainer, float $negative, ?string $cycleStart = null): MonthSummary
    {
        return new MonthSummary(
            new OpeningBalance($retainer, 0.0, 0.0, $retainer, 0.0, 0.0, $retainer, 0.0),
            new ClosingBalance(min($worked, $retainer), 0.0, max(0.0, $retainer - $worked), max(0.0, $worked - $retainer), $negative, 0.0),
            $worked,
            $month,
            $retainer,
            cycleStart: $cycleStart,
        );
    }

    private function item(string $cadence, int $amount): ProjectedRecurringItem
    {
        return new ProjectedRecurringItem(1, 'item-1', 'Synthetic hosting', $cadence, null, 1, null, null, $amount, '1.000', false, true);
    }

    /** @param list<ProjectedRecurringItem> $items */
    private function agreement(int $id = 1, string $cadence = 'monthly', string $startsOn = '2026-01-01', ?string $endsOn = null, ?int $retainerAmount = null, ?int $retainerMinutes = null, array $items = []): ProjectedAgreement
    {
        return new ProjectedAgreement(
            id: $id, publicId: 'agreement-'.$id, workspaceId: 1, clientId: 10, clientPublicId: 'client-10', clientName: 'Synthetic Alpha',
            title: 'Synthetic retainer '.$id, status: 'active', currency: 'USD', billingCadence: $cadence, firstCycleProration: null,
            startsOn: CarbonImmutable::parse($startsOn), endsOn: $endsOn === null ? null : CarbonImmutable::parse($endsOn), terminatedAt: null, signedAt: null,
            retainerMinutes: $retainerMinutes, retainerAmount: $retainerAmount, periodRetainerMinutes: null, periodRetainerAmount: null, recurringItems: $items,
        );
    }
}
