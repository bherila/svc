<?php

namespace Tests\Unit\Revenue;

use App\Support\Revenue\RevenueDashboard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RevenueDashboardTest extends TestCase
{
    /** @return iterable<string, array{int, int, float|null}> */
    public static function changes(): iterable
    {
        yield 'no prior figure' => [500, 0, null];
        yield 'nothing either year' => [0, 0, null];
        yield 'unchanged' => [500, 500, 0.0];
        yield 'doubled' => [200, 100, 100.0];
        yield 'halved' => [50, 100, -50.0];
        yield 'rounded to one decimal' => [1001, 3000, -66.6];
        yield 'rounded up at the half' => [1005, 1000, 0.5];
        yield 'a credit prior' => [100, -100, 200.0];
        yield 'down to nothing' => [0, 100, -100.0];
    }

    #[DataProvider('changes')]
    public function test_a_change_is_a_percentage_of_the_prior_figure_or_nothing(int $current, int $prior, ?float $expected): void
    {
        $this->assertSame($expected, RevenueDashboard::percentChange($current, $prior));
    }

    public function test_the_key_figures_carry_their_windows_and_their_change(): void
    {
        $window = fn (int $amount, int $prior): array => ['amount' => $amount, 'prior_amount' => $prior, 'from' => '2026-01-01', 'to' => '2026-11-06', 'prior_from' => '2025-01-01', 'prior_to' => '2025-11-06'];
        $next = ['from' => '2026-12-01', 'to' => '2027-11-30', 'contracted' => 900, 'projected_overage' => null];
        $open = ['outstanding' => 70, 'invoice_count' => 2, 'overdue_amount' => 30, 'overdue_count' => 1];

        $kpis = RevenueDashboard::kpis(['ytd' => $window(300, 200), 'ttm' => $window(500, 0), 'next_12_months' => $next, 'open_receivables' => $open, 'changes' => ['ending' => []]]);

        $this->assertSame(['ytd', 'ttm', 'next_12_months', 'open_receivables'], array_keys($kpis));
        $this->assertSame(50.0, $kpis['ytd']['change_percent']);
        $this->assertSame(300, $kpis['ytd']['amount']);
        $this->assertSame(200, $kpis['ytd']['prior_amount']);
        $this->assertSame('2025-11-06', $kpis['ytd']['prior_to']);
        $this->assertNull($kpis['ttm']['change_percent']);
        $this->assertSame($next, $kpis['next_12_months']);
        $this->assertSame($open, $kpis['open_receivables']);
    }

    /** @return array<string, mixed> */
    private function month(string $month, array $clients, string $kind = 'actual'): array
    {
        return ['month' => $month, 'kind' => $kind, 'recurring' => 10, 'overage_and_other' => 20, 'expenses' => null, 'contracted' => null, 'projected_overage' => 5, 'total' => 35, 'clients' => $clients];
    }

    /** @return array{client_id: string, client_name: string, total: int} */
    private function client(int $n, int $total): array
    {
        return ['client_id' => "c{$n}", 'client_name' => "Client {$n}", 'total' => $total];
    }

    public function test_a_month_names_its_top_five_clients_and_folds_the_rest_into_others(): void
    {
        $clients = [];
        foreach ([70, 60, 50, 40, 30, 20, 10] as $i => $total) {
            $clients[] = $this->client($i + 1, $total);
        }

        $rows = RevenueDashboard::months([$this->month('2026-10', $clients)], fn (string $m): string => "/invoices?month={$m}", fn (string $id): string => "/clients/{$id}");

        $this->assertCount(1, $rows);
        $row = $rows[0];
        $this->assertSame('/invoices?month=2026-10', $row['href']);
        $this->assertSame(['c1', 'c2', 'c3', 'c4', 'c5'], array_column($row['clients'], 'client_id'));
        $this->assertSame('Client 1', $row['clients'][0]['name']);
        $this->assertSame(70, $row['clients'][0]['total']);
        $this->assertSame('/clients/c1', $row['clients'][0]['href']);
        $this->assertSame(['count' => 2, 'total' => 30], $row['others']);
        $this->assertSame(35, $row['total']);
        $this->assertNull($row['expenses']);
        $this->assertSame(5, $row['projected_overage']);
        $this->assertSame('actual', $row['kind']);
    }

    public function test_five_clients_leave_nothing_in_others(): void
    {
        $five = array_map(fn (int $n): array => $this->client($n, 10 * $n), [1, 2, 3, 4, 5]);

        $row = RevenueDashboard::months([$this->month('2026-10', $five)], fn (): string => '', fn (): string => '')[0];

        $this->assertCount(5, $row['clients']);
        $this->assertNull($row['others']);
    }

    public function test_six_clients_put_exactly_one_in_others(): void
    {
        $six = array_map(fn (int $n): array => $this->client($n, 10), [1, 2, 3, 4, 5, 6]);

        $row = RevenueDashboard::months([$this->month('2026-10', $six)], fn (): string => '', fn (): string => '')[0];

        $this->assertSame(['count' => 1, 'total' => 10], $row['others']);
    }

    public function test_a_month_without_clients_has_none(): void
    {
        $row = RevenueDashboard::months([$this->month('2026-10', [])], fn (): string => '', fn (): string => '')[0];

        $this->assertSame([], $row['clients']);
        $this->assertNull($row['others']);
    }

    public function test_agreements_at_risk_keep_the_models_order_and_total_the_monthly_amount(): void
    {
        $ending = [
            ['agreement_id' => 'a1', 'client_id' => 'c1', 'client_name' => 'One', 'title' => 'First', 'date' => '2026-12-01', 'monthly_amount' => 500],
            ['agreement_id' => 'a2', 'client_id' => 'c2', 'client_name' => 'Two', 'title' => 'Second', 'date' => '2027-03-01', 'monthly_amount' => 250],
        ];

        $risk = RevenueDashboard::atRisk($ending, fn (string $c): string => "/c/{$c}", fn (string $c, string $a): string => "/c/{$c}/a/{$a}");

        $this->assertSame(750, $risk['monthly_total']);
        $this->assertSame(['a1', 'a2'], array_column($risk['agreements'], 'agreement_id'));
        $this->assertSame('2026-12-01', $risk['agreements'][0]['ends_on']);
        $this->assertSame('/c/c1', $risk['agreements'][0]['client_href']);
        $this->assertSame('/c/c2/a/a2', $risk['agreements'][1]['href']);
        $this->assertSame(['agreements' => [], 'monthly_total' => 0], RevenueDashboard::atRisk([], fn (): string => '', fn (): string => ''));
    }

    public function test_this_period_prints_hours_as_whole_minutes_and_keeps_withheld_hours_null(): void
    {
        $row = fn (string $id, ?float $used, ?float $included, string $status): array => [
            'agreement_id' => $id, 'title' => "T{$id}", 'period_start' => '2026-11-01', 'period_end' => '2026-11-30', 'hours_used' => $used,
            'hours_included' => $included, 'over_retainer' => $used === null ? null : false, 'ends_within_60_days' => true, 'ends_on' => '2026-12-15', 'ledger_status' => $status,
        ];

        $period = RevenueDashboard::thisPeriod([$row('a1', 2.5, 10.0, 'ok'), $row('a2', null, null, 'ledger_unreadable'), $row('a3', 0.0125, 1.0, 'ok')], fn (string $id): string => "/a/{$id}");

        $this->assertSame(150, $period[0]['minutes_used']);
        $this->assertSame(600, $period[0]['minutes_included']);
        $this->assertSame('/a/a1', $period[0]['href']);
        $this->assertNull($period[1]['minutes_used']);
        $this->assertNull($period[1]['minutes_included']);
        $this->assertSame('ledger_unreadable', $period[1]['ledger_status']);
        $this->assertSame(1, $period[2]['minutes_used']);
        $this->assertSame(0, RevenueDashboard::thisPeriod([$row('a4', 0.0, 4.0, 'ok')], fn (): string => '')[0]['minutes_used']);
        $this->assertTrue($period[0]['ends_within_60_days']);
    }

    public function test_only_agreements_in_force_become_cards_without_their_schedules(): void
    {
        $agreement = fn (string $id, string $status): array => [
            'agreement_id' => $id, 'title' => "T{$id}", 'status' => $status, 'billing_cadence' => 'monthly', 'starts_on' => '2026-01-01', 'ends_on' => null, 'signed' => true,
            'retainer' => ['period_fee' => 100, 'monthly_equivalent' => 100, 'first_cycle_proration' => 'none'], 'monthly_recurring' => 130,
            'recurring_items' => [['item_id' => 'i1', 'description' => 'Hosting', 'cadence' => 'quarterly', 'amount' => 90, 'monthly_equivalent' => 30, 'is_taxable' => true, 'is_active' => true, 'effective_on' => null, 'expires_on' => null, 'next_charge_on' => '2027-01-01']],
            'overage' => ['method' => 'x'], 'months' => [['month' => '2026-12']], 'totals' => [],
        ];

        $cards = RevenueDashboard::agreementCards([$agreement('a1', 'active'), $agreement('a2', 'terminated'), $agreement('a3', 'expired'), $agreement('a4', 'paused')], fn (string $id): string => "/a/{$id}");

        $this->assertSame(['a1'], array_column($cards, 'agreement_id'));
        $this->assertSame('/a/a1', $cards[0]['href']);
        $this->assertSame(130, $cards[0]['monthly_recurring']);
        $this->assertSame('Hosting', $cards[0]['recurring_items'][0]['description']);
        $this->assertSame('2027-01-01', $cards[0]['recurring_items'][0]['next_charge_on']);
        $this->assertArrayNotHasKey('months', $cards[0]);
        $this->assertArrayNotHasKey('overage', $cards[0]);
        $this->assertArrayNotHasKey('is_taxable', $cards[0]['recurring_items'][0]);
    }
}
