<?php

declare(strict_types=1);

namespace Tests\Unit\Revenue;

use App\Services\Billing\Balances\ClosingBalance;
use App\Services\Billing\Balances\MonthSummary;
use App\Services\Billing\Balances\OpeningBalance;
use App\Services\Revenue\AgreementHealthCalculator;
use App\Services\Revenue\RecurringRevenueProjector;
use App\Services\Revenue\RevenueSummaries;
use App\Support\Revenue\ActualAmount;
use App\Support\Revenue\AgreementChange;
use App\Support\Revenue\AgreementHealth;
use App\Support\Revenue\ClientRevenue;
use App\Support\Revenue\CollectedPayment;
use App\Support\Revenue\OpenInvoice;
use App\Support\Revenue\ProjectedAgreement;
use App\Support\Revenue\ProjectedOverage;
use App\Support\Revenue\ProjectionInputs;
use App\Support\Revenue\RevenueActualsInputs;
use App\Support\Revenue\RevenueAssumptions;
use App\Support\Revenue\RevenueBand;
use App\Support\Revenue\RevenueBasis;
use App\Support\Revenue\RevenueClient;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Every field the revenue DTOs expose, and the edges of the summaries: sort
 * orders, which clients a roster or a month lists, switched-off figures,
 * rounding, and how a payment is spread. As of 2026-11-06, with no database.
 */
final class RevenueReportingTest extends TestCase
{
    private const string AS_OF = '2026-11-06';

    public function test_the_assumptions_block_states_every_rule(): void
    {
        $this->assertSame([
            'as_of' => '2026-11-06', 'basis' => 'collected', 'projection_basis' => 'invoiced', 'currency' => 'EUR', 'tax' => 'excluded',
            'fiscal_year_start' => '01-01', 'include_expenses' => false, 'include_projected_overage' => true, 'overage_window_months' => 6,
            'minimum_history_months' => 3, 'projection_months' => 12, 'renewals_assumed' => false, 'method_version' => 'revenue-2026-11-v1',
        ], (new RevenueAssumptions(CarbonImmutable::parse('2026-11-06 15:00'), RevenueBasis::Collected, false, true, 'EUR'))->toArray());
        $defaults = new RevenueAssumptions(CarbonImmutable::parse(self::AS_OF));
        $this->assertSame([RevenueBasis::Invoiced, true, false, null], [$defaults->basis, $defaults->includeExpenses, $defaults->includeProjectedOverage, $defaults->currency]);
    }

    public function test_agreement_health_and_changes_print_every_field(): void
    {
        $ending = $this->agreement(endsOn: '2026-12-15');
        $health = new AgreementHealth($ending, CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-11-30'), 4.5, 10.0, false, true, AgreementHealth::OK);
        $this->assertSame([
            'agreement_id' => 'agreement-1', 'title' => 'Synthetic retainer 1', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-15',
            'period_start' => '2026-11-01', 'period_end' => '2026-11-30', 'hours_used' => 4.5, 'hours_included' => 10.0,
            'over_retainer' => false, 'ends_within_60_days' => true, 'ledger_status' => 'ok',
        ], $health->toArray());
        $open = new AgreementHealth($this->agreement(), CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-11-30'), null, null, null, false, AgreementHealth::LEDGER_UNREADABLE);
        $this->assertSame([null, null, null, false, 'ledger_unreadable'], array_values(array_intersect_key($open->toArray(), array_flip(['ends_on', 'hours_used', 'over_retainer', 'ends_within_60_days', 'ledger_status']))));

        $this->assertSame(['agreement_id' => 'agreement-1', 'client_id' => 'client-10', 'client_name' => 'Synthetic Alpha', 'title' => 'Synthetic retainer 1', 'date' => '2026-12-15', 'monthly_amount' => 500],
            (new AgreementChange(AgreementChange::ENDING, $ending, CarbonImmutable::parse('2026-12-15'), 500))->toArray());
    }

    public function test_a_roster_row_prints_every_field_and_rolls_up_its_badges(): void
    {
        $over = new AgreementHealth($this->agreement(), CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-11-30'), 12.0, 10.0, true, false, AgreementHealth::OK);
        $fine = new AgreementHealth($this->agreement(id: 2), CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-11-30'), 2.0, 10.0, false, false, AgreementHealth::OK);
        $row = new ClientRevenue(new RevenueClient(10, 'client-10', 'Synthetic Alpha', false), 11000, 1300, 2000, 300, [['month' => '2025-11', 'amount' => 0]], 900, 600, 2, [$fine, $over]);

        $this->assertSame([
            'client_id' => 'client-10', 'name' => 'Synthetic Alpha', 'is_active' => false, 'mrr' => 11000, 'ytd' => 1300,
            'ttm' => ['amount' => 2000, 'month_to_date' => 300, 'series' => [['month' => '2025-11', 'amount' => 0]]],
            'open_amount' => 900, 'overdue_amount' => 600, 'overdue_count' => 2, 'ending_soon' => false, 'over_retainer' => true,
            'agreements' => [$fine->toArray(), $over->toArray()],
        ], $row->toArray());
        $none = new ClientRevenue(new RevenueClient(10, 'client-10', 'Synthetic Alpha', true), 0, 0, 0, 0, [], 0, 0, 0, [$fine]);
        $this->assertSame([false, false], [$none->toArray()['ending_soon'], $none->toArray()['over_retainer']]);
    }

    public function test_the_summary_prints_its_changes(): void
    {
        $inputs = new ProjectionInputs(CarbonImmutable::parse(self::AS_OF), [
            $this->agreement(id: 1, retainerAmount: 10000, endsOn: '2027-03-31'),
            $this->agreement(id: 2, startsOn: '2027-02-01', retainerAmount: 5000),
        ]);
        $summary = (new RevenueSummaries)->summary($this->actuals([]), $inputs, (new RecurringRevenueProjector)->project($inputs), new RevenueAssumptions(CarbonImmutable::parse(self::AS_OF)))->toArray();

        $this->assertSame([['agreement-1', '2027-03-31', 10000]], array_map(static fn (array $change): array => [$change['agreement_id'], $change['date'], $change['monthly_amount']], $summary['changes']['ending']));
        $this->assertSame([['agreement-2', '2027-02-01', 5000]], array_map(static fn (array $change): array => [$change['agreement_id'], $change['date'], $change['monthly_amount']], $summary['changes']['starting']));
        $this->assertSame(['from' => '2026-12-01', 'to' => '2027-11-30'], array_intersect_key($summary['next_12_months'], ['from' => 1, 'to' => 1]));
    }

    public function test_changes_are_soonest_first_then_by_agreement_and_skip_one_time_agreements(): void
    {
        $inputs = new ProjectionInputs(CarbonImmutable::parse(self::AS_OF), [
            $this->agreement(id: 9, cadence: 'one_time', endsOn: '2026-12-01', startsOn: '2026-01-01'),
            $this->agreement(id: 5, endsOn: '2027-03-01'),
            $this->agreement(id: 4, endsOn: '2027-01-01'),
            $this->agreement(id: 3, endsOn: '2027-03-01'),
            $this->agreement(id: 8, cadence: 'one_time', startsOn: '2027-01-01'),
            $this->agreement(id: 7, startsOn: '2027-04-01'),
            $this->agreement(id: 6, startsOn: '2027-02-01'),
            $this->agreement(id: 2, startsOn: '2027-04-01'),
        ]);
        $changes = (new RevenueSummaries)->changes($inputs);

        $this->assertSame(['agreement-4', 'agreement-3', 'agreement-5'], array_map(static fn (AgreementChange $change): string => $change->agreement->publicId, $changes['ending']));
        $this->assertSame(['agreement-6', 'agreement-2', 'agreement-7'], array_map(static fn (AgreementChange $change): string => $change->agreement->publicId, $changes['starting']));
    }

    public function test_endings_are_ordered_by_date_before_agreement(): void
    {
        $inputs = new ProjectionInputs(CarbonImmutable::parse(self::AS_OF), [
            $this->agreement(id: 5, endsOn: '2027-03-01'),
            $this->agreement(id: 4, endsOn: '2027-01-01'),
            $this->agreement(id: 3, endsOn: '2027-03-01'),
            $this->agreement(id: 2, endsOn: '2027-02-01'),
            $this->agreement(id: 1, endsOn: '2026-12-01'),
        ]);

        $this->assertSame(['agreement-1', 'agreement-4', 'agreement-2', 'agreement-3', 'agreement-5'], array_map(static fn (AgreementChange $change): string => $change->agreement->publicId, (new RevenueSummaries)->changes($inputs)['ending']));
    }

    public function test_an_inactive_client_is_listed_for_any_one_figure(): void
    {
        $inactive = static fn (int $id): RevenueClient => new RevenueClient($id, 'client-'.$id, 'Synthetic '.$id, false);
        $clients = [11 => $inactive(11), 12 => $inactive(12), 13 => $inactive(13), 14 => $inactive(14), 15 => $inactive(15)];
        $actuals = new RevenueActualsInputs(CarbonImmutable::parse(self::AS_OF), $clients, [new ActualAmount(12, CarbonImmutable::parse('2026-02-01'), RevenueBand::Recurring, 100)], [], [new OpenInvoice(13, 50, null)]);
        // 11: monthly recurring; 12: revenue in the trailing year; 13: an open balance; 14: an agreement's health; 15: nothing.
        $inputs = new ProjectionInputs(CarbonImmutable::parse(self::AS_OF), [
            $this->agreement(id: 1, clientId: 11, retainerAmount: 100),
            $this->agreement(id: 2, clientId: 14, startsOn: '2027-01-01'),
        ]);
        $roster = (new RevenueSummaries)->clients($actuals, $inputs, new RevenueAssumptions(CarbonImmutable::parse(self::AS_OF)), [2 => []]);

        $this->assertSame(['client-11', 'client-12', 'client-13', 'client-14'], array_map(static fn (ClientRevenue $row): string => $row->client->publicId, $roster['clients']));
        $this->assertSame(['mrr' => 100, 'ytd' => 100, 'ttm' => 100, 'open_amount' => 50, 'overdue_amount' => 0, 'overdue_count' => 0], $roster['totals']);
    }

    public function test_the_roster_sorts_by_name_without_regard_to_case_then_by_id(): void
    {
        $clients = [];
        foreach ([1 => 'beta', 2 => 'Alpha', 3 => 'Éb', 4 => 'éa', 5 => 'alpha', 6 => 'Émile', 7 => 'éclair'] as $id => $name) {
            $clients[$id] = new RevenueClient($id, 'client-'.$id, $name, true);
        }
        $roster = (new RevenueSummaries)->clients(new RevenueActualsInputs(CarbonImmutable::parse(self::AS_OF), $clients, [], [], []), new ProjectionInputs(CarbonImmutable::parse(self::AS_OF), []), new RevenueAssumptions(CarbonImmutable::parse(self::AS_OF)), []);

        $this->assertSame(['client-2', 'client-5', 'client-1', 'client-4', 'client-3', 'client-7', 'client-6'], array_map(static fn (ClientRevenue $row): string => $row->client->publicId, $roster['clients']));
    }

    public function test_a_month_lists_only_clients_with_a_figure_largest_first(): void
    {
        $clients = [];
        foreach ([1, 2, 3, 4] as $id) {
            $clients[$id] = new RevenueClient($id, 'client-'.$id, 'Synthetic '.$id, true);
        }
        $actuals = new RevenueActualsInputs(CarbonImmutable::parse(self::AS_OF), $clients, [
            $this->amount('2026-10-01', 100, client: 1),
            $this->amount('2026-10-02', 300, client: 2),
            $this->amount('2026-10-03', 100, client: 3),
            $this->amount('2026-10-04', 50, RevenueBand::OverageAndOther, client: 4),
            $this->amount('2026-10-04', -50, client: 4),
        ], [], []);
        // A client known only to the projection is listed too.
        $inputs = new ProjectionInputs(CarbonImmutable::parse(self::AS_OF), [$this->agreement(id: 1, clientId: 30, retainerAmount: 700)]);
        $summaries = new RevenueSummaries;
        $rows = $summaries->monthly($actuals, (new RecurringRevenueProjector)->project($inputs), new RevenueAssumptions(CarbonImmutable::parse(self::AS_OF)));

        $october = $rows[11];
        $this->assertSame(['client-2', 'client-1', 'client-3', 'client-4'], array_column($october['clients'], 'client_id'));
        $this->assertSame(['Synthetic 2', 300], [$october['clients'][0]['client_name'], $october['clients'][0]['total']]);
        $this->assertSame([0, 50, -50], [$october['clients'][3]['total'], $october['clients'][3]['overage_and_other'], $october['clients'][3]['recurring']]);
        $this->assertSame([['client_id' => 'client-30', 'client_name' => 'Synthetic Alpha', 'recurring' => 0, 'overage_and_other' => 0, 'expenses' => 0, 'contracted' => 700, 'projected_overage' => null, 'total' => 700]], $rows[13]['clients']);

        $narrowed = $summaries->monthly($actuals, (new RecurringRevenueProjector)->project($inputs), new RevenueAssumptions(CarbonImmutable::parse(self::AS_OF)), clientId: 2);
        $this->assertSame(['client-2'], array_column($narrowed[11]['clients'], 'client_id'));
        $this->assertSame(300, $narrowed[11]['total']);
        $this->assertSame([], $narrowed[13]['clients']);
    }

    public function test_figures_outside_the_band_or_switched_off_are_null(): void
    {
        $actuals = new RevenueActualsInputs(CarbonImmutable::parse(self::AS_OF), [10 => new RevenueClient(10, 'client-10', 'Synthetic', true)], [
            $this->amount('2026-11-01', 100),
            $this->amount('2026-11-02', 20, RevenueBand::OverageAndOther),
            $this->amount('2026-11-03', 3, RevenueBand::Expenses),
        ], [], []);
        $agreement = $this->agreement(retainerAmount: 1000);
        $projection = (new RecurringRevenueProjector)->project(new ProjectionInputs(CarbonImmutable::parse(self::AS_OF), [$agreement], [$agreement->id => array_fill_keys(['2026-05', '2026-06', '2026-07', '2026-08', '2026-09', '2026-10'], 40)]));
        $keys = array_flip(['recurring', 'overage_and_other', 'expenses', 'contracted', 'projected_overage', 'total']);
        $month = static fn (array $rows): array => array_intersect_key($rows[12], $keys);
        $summaries = new RevenueSummaries;
        $everything = new RevenueAssumptions(CarbonImmutable::parse(self::AS_OF), includeExpenses: true, includeProjectedOverage: true);

        $this->assertSame(['recurring' => 100, 'overage_and_other' => 20, 'expenses' => 3, 'contracted' => 1000, 'projected_overage' => 40, 'total' => 1163], $month($summaries->monthly($actuals, $projection, $everything)));
        $this->assertSame(['recurring' => 100, 'overage_and_other' => null, 'expenses' => null, 'contracted' => 1000, 'projected_overage' => null, 'total' => 1100], $month($summaries->monthly($actuals, $projection, $everything, 'recurring')));
        $this->assertSame(['recurring' => null, 'overage_and_other' => 20, 'expenses' => null, 'contracted' => null, 'projected_overage' => 40, 'total' => 60], $month($summaries->monthly($actuals, $projection, $everything, 'overage_and_other')));
        $this->assertSame(['recurring' => null, 'overage_and_other' => null, 'expenses' => 3, 'contracted' => null, 'projected_overage' => null, 'total' => 3], $month($summaries->monthly($actuals, $projection, $everything, 'expenses')));
        $this->assertSame(['recurring' => 100, 'overage_and_other' => 20, 'expenses' => null, 'contracted' => 1000, 'projected_overage' => null, 'total' => 1120], $month($summaries->monthly($actuals, $projection, new RevenueAssumptions(CarbonImmutable::parse(self::AS_OF), includeExpenses: false))));
    }

    public function test_the_health_figures_are_rounded_to_four_places(): void
    {
        $agreement = $this->agreement(retainerAmount: 10000, retainerMinutes: 600);
        $health = (new AgreementHealthCalculator)->health($agreement, [$this->ledgerRow('2026-11', 1.234567, 9.876543)], CarbonImmutable::parse(self::AS_OF));

        $this->assertSame([1.2346, 9.8765], [$health->hoursUsed, $health->hoursIncluded]);
    }

    public function test_the_average_overage_rounds_to_the_nearest_minor_unit(): void
    {
        $this->assertSame(33334, ProjectedOverage::fromHistory(3, 100001)->monthlyAverage);
        $this->assertSame(33333, ProjectedOverage::fromHistory(3, 100000)->monthlyAverage);
        $this->assertSame(['method' => 'trailing_average', 'window_months' => 6, 'minimum_history_months' => 3, 'history_months' => 2, 'billed_in_window' => 9, 'monthly_average' => 0, 'status' => 'insufficient_history'], ProjectedOverage::fromHistory(2, 9)->toArray());
    }

    public function test_a_payment_skips_zero_payments_and_keeps_reading_the_rest(): void
    {
        $amounts = RevenueActualsInputs::collectedAmounts([
            new CollectedPayment(10, 7, CarbonImmutable::parse('2026-04-01'), 0),
            new CollectedPayment(10, 9, CarbonImmutable::parse('2026-04-02'), 40),
            new CollectedPayment(10, 7, CarbonImmutable::parse('2026-04-03'), 60),
        ], [7 => ['gross' => 100, 'bands' => ['recurring' => 100]]]);

        $this->assertSame([[RevenueBand::OverageAndOther, 40], [RevenueBand::Recurring, 60]], array_map(static fn (ActualAmount $amount): array => [$amount->band, $amount->amount], $amounts));
    }

    public function test_an_invoice_without_priced_bands_or_gross_counts_a_payment_as_other(): void
    {
        $payments = [new CollectedPayment(10, 7, CarbonImmutable::parse('2026-04-01'), 500)];

        $zeroBands = RevenueActualsInputs::collectedAmounts($payments, [7 => ['gross' => 500, 'bands' => ['recurring' => 0]]]);
        $this->assertSame([[RevenueBand::OverageAndOther, 500]], array_map(static fn (ActualAmount $amount): array => [$amount->band, $amount->amount], $zeroBands));
        $zeroGross = RevenueActualsInputs::collectedAmounts($payments, [7 => ['gross' => 0, 'bands' => ['recurring' => 300, 'overage_and_other' => -300]]]);
        $this->assertSame([[RevenueBand::OverageAndOther, 500]], array_map(static fn (ActualAmount $amount): array => [$amount->band, $amount->amount], $zeroGross));
    }

    public function test_a_spread_payment_rounds_each_band_and_leaves_out_a_zero_share(): void
    {
        $spread = static fn (int $payment, int $gross, array $bands): array => array_map(
            static fn (ActualAmount $amount): array => [$amount->band->value, $amount->amount],
            RevenueActualsInputs::collectedAmounts([new CollectedPayment(10, 7, CarbonImmutable::parse('2026-04-01'), $payment)], [7 => ['gross' => $gross, 'bands' => $bands]]),
        );

        // 50, 33.33 and 16.67: each band rounds to its nearest unit.
        $this->assertSame([['recurring', 50], ['overage_and_other', 33], ['expenses', 17]], $spread(100, 600, ['recurring' => 300, 'overage_and_other' => 200, 'expenses' => 100]));
        // Pre-tax share 83.33 rounds down and 91.67 up.
        $this->assertSame([['recurring', 83]], $spread(100, 600, ['recurring' => 500]));
        $this->assertSame([['recurring', 92]], $spread(100, 600, ['recurring' => 550]));
        // 16.67 + 16.83 + 16.5 round to 51 against a share of 50: the largest band, wherever it is listed, gives back the unit.
        $this->assertSame([['recurring', 17], ['overage_and_other', 16], ['expenses', 17]], $spread(50, 300, ['recurring' => 100, 'overage_and_other' => 101, 'expenses' => 99]));
        // A band whose share rounds to nothing is not listed.
        $this->assertSame([['recurring', 100]], $spread(100, 1001, ['recurring' => 1000, 'overage_and_other' => 1]));
    }

    /** @param list<ActualAmount> $invoiced */
    private function actuals(array $invoiced): RevenueActualsInputs
    {
        return new RevenueActualsInputs(CarbonImmutable::parse(self::AS_OF), [10 => new RevenueClient(10, 'client-10', 'Synthetic Alpha', true)], $invoiced, [], []);
    }

    private function amount(string $date, int $amount, RevenueBand $band = RevenueBand::Recurring, int $client = 10): ActualAmount
    {
        return new ActualAmount($client, CarbonImmutable::parse($date), $band, $amount);
    }

    private function ledgerRow(string $month, float $worked, float $retainer): MonthSummary
    {
        return new MonthSummary(
            new OpeningBalance($retainer, 0.0, 0.0, $retainer, 0.0, 0.0, $retainer, 0.0),
            new ClosingBalance(min($worked, $retainer), 0.0, max(0.0, $retainer - $worked), max(0.0, $worked - $retainer), 0.0, 0.0),
            $worked,
            $month,
            $retainer,
        );
    }

    private function agreement(int $id = 1, int $clientId = 10, string $cadence = 'monthly', string $startsOn = '2026-01-01', ?string $endsOn = null, ?int $retainerAmount = null, ?int $retainerMinutes = null): ProjectedAgreement
    {
        return new ProjectedAgreement(
            id: $id, publicId: 'agreement-'.$id, workspaceId: 1, clientId: $clientId, clientPublicId: 'client-'.$clientId, clientName: 'Synthetic Alpha',
            title: 'Synthetic retainer '.$id, status: 'active', currency: 'USD', billingCadence: $cadence, firstCycleProration: null,
            startsOn: CarbonImmutable::parse($startsOn), endsOn: $endsOn === null ? null : CarbonImmutable::parse($endsOn), terminatedAt: null, signedAt: null,
            retainerMinutes: $retainerMinutes, retainerAmount: $retainerAmount, periodRetainerMinutes: null, periodRetainerAmount: null,
        );
    }
}
