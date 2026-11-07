<?php

namespace Tests\Feature\Finance;

use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientInvoicePayment;
use App\Models\ClientInvoicePaymentRefund;
use App\Models\ProcessorMovement;
use App\Models\User;
use App\Models\Workspace;
use App\Support\AgentApi\AgentApiScopes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CallsMcp;
use Tests\TestCase;

/** The cash-income read loads a page's facts in a fixed number of workspace-scoped queries. */
final class CashIncomeLoadTest extends TestCase
{
    use CallsMcp;
    use RefreshDatabase;

    private User $owner;

    private Workspace $workspace;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2027-01-05 12:00:00 UTC'));
        $this->owner = User::factory()->create(['email' => 'cash-load@example.test']);
        $this->workspace = $this->workspace('Cash Load', 'cash-load');
        $this->actingAsMcp($this->owner, [AgentApiScopes::MCP_USE, AgentApiScopes::FINANCE_READ]);
    }

    public function test_a_page_is_loaded_in_the_same_number_of_queries_however_many_rows_it_holds(): void
    {
        $this->seedRows($this->workspace, 2);
        $small = $this->queriesFor('cash-income?limit=100', 6);

        $this->seedRows($this->workspace, 12);
        $large = $this->queriesFor('cash-income?limit=100', 6 + 12 * 3);

        $this->assertSame($small, $large, 'Eager loading must not grow with the number of rows.');
        $this->assertLessThanOrEqual(11, $large, 'A page loads its facts in a bounded number of queries.');
    }

    public function test_the_yearly_export_loads_in_chunks_not_per_row(): void
    {
        $this->seedRows($this->workspace, 3);
        $small = $this->queriesFor('income?year=2026', 9);

        $this->seedRows($this->workspace, 12);
        $large = $this->queriesFor('income?year=2026', 9 + 12 * 3);

        // 45 rows fit in one chunk of 100 plus the closing empty lookup: no per-row queries.
        $this->assertSame($small, $large);
    }

    public function test_period_edges_are_inclusive_by_date_for_payments_refunds_and_movements(): void
    {
        $payments = [];
        foreach (['2025-12-31', '2026-01-01', '2026-12-31', '2027-01-01'] as $day) {
            $payments[$day] = $this->payment($this->workspace, 1000, $day);
        }
        $this->refund($this->workspace, $payments['2026-01-01'], 'refund', 100, '2025-12-31 23:59:59');
        $this->refund($this->workspace, $payments['2026-01-01'], 'refund', 200, '2026-12-31 23:59:59');
        $this->refund($this->workspace, $payments['2026-01-01'], 'refund', 300, '2027-01-01 00:00:00');
        $this->movement($this->workspace, $payments['2026-01-01'], 'fee', 0, 10, '2025-12-31');
        $this->movement($this->workspace, $payments['2026-01-01'], 'fee', 0, 20, '2026-01-01');
        $this->movement($this->workspace, $payments['2026-01-01'], 'fee', 0, 30, '2026-12-31');
        $this->movement($this->workspace, $payments['2026-01-01'], 'fee', 0, 40, '2027-01-01');
        $this->travelTo(Carbon::parse('2027-01-05 12:10:00 UTC'));

        $year = $this->getJson($this->url('cash-income').'?from=2026-01-01&to=2026-12-31&limit=100')->assertOk()->json('data');
        $this->assertEqualsCanonicalizing(
            ['2026-01-01', '2026-12-31', '2026-12-31', '2026-01-01', '2026-12-31'],
            array_column($year, 'effective_on'),
        );
        $this->assertSame(1000 + 1000 - 200, array_sum(array_column($year, 'amount')));
        $this->assertSame(20 + 30, array_sum(array_column($year, 'fee_amount')));

        $single = $this->getJson($this->url('cash-income').'?from=2026-12-31&to=2026-12-31')->assertOk()->json('data');
        $this->assertCount(3, $single);
        $first = $this->getJson($this->url('cash-income').'?from=2025-12-31&to=2025-12-31')->assertOk()->json('data');
        $this->assertCount(3, $first);

        $months = $this->getJson($this->url('income').'?year=2026')->assertOk()->json('data.months');
        $this->assertSame(1000, $months[0]['cash_received']);
        $this->assertSame(1000, $months[11]['cash_received']);
        $this->assertSame(200, $months[11]['refunds']);
        $this->assertSame(20, $months[0]['fees']);
        $this->assertSame(30, $months[11]['fees']);
    }

    public function test_partial_payments_refunds_and_reversals_keep_exact_integer_amounts(): void
    {
        $payment = $this->payment($this->workspace, 11000, '2026-03-10', refunded: 5000, fee: 150);
        $first = $this->refund($this->workspace, $payment, 'refund', 4000, '2026-03-11 10:00:00');
        $this->refund($this->workspace, $payment, 'refund', 1000, '2026-03-12 10:00:00');
        $this->refund($this->workspace, $payment, 'reversal', 4000, '2026-03-13 10:00:00', $first->id);
        $this->travelTo(Carbon::parse('2027-01-05 12:10:00 UTC'));

        /** @var list<array<string, mixed>> $data */
        $data = $this->getJson($this->url('cash-income').'?limit=100')->assertOk()->json('data');
        $rows = array_combine(array_map(static fn (array $row): string => $row['kind'].':'.$row['effective_on'], $data), $data);
        $this->assertSame(11000, $rows['payment:2026-03-10']['amount']);
        $this->assertSame(150, $rows['payment:2026-03-10']['fee_amount']);
        $this->assertSame(10850, $rows['payment:2026-03-10']['net_amount']);
        $this->assertSame(5000, $rows['payment:2026-03-10']['refunded_amount']);
        $this->assertSame(-4000, $rows['refund:2026-03-11']['amount']);
        $this->assertSame(-1000, $rows['refund:2026-03-12']['amount']);
        $this->assertSame(4000, $rows['refund_reversal:2026-03-13']['amount']);
        $this->assertSame($rows['refund:2026-03-11']['source_id'], $rows['refund_reversal:2026-03-13']['reverses_id']);
        $this->assertSame($payment->public_id, $rows['refund_reversal:2026-03-13']['payment_id']);

        $month = $this->getJson($this->url('income').'?year=2026')->assertOk()->json('data.months.2');
        $this->assertSame(5000, $month['refunds']);
        $this->assertSame(4000, $month['refund_reversals']);
        $this->assertSame(11000 - 5000 + 4000 - 150, $month['net_cash']);
        // Refunded 5000, evidenced 4000 + 1000 - 4000: 4000 has no dated evidence.
        $this->assertSame(4000, $month['unattributed_refunds']);
    }

    public function test_each_row_carries_its_own_currency_and_unpaid_payments_count_no_money(): void
    {
        $this->payment($this->workspace, 5000, '2026-05-01', currency: 'EUR');
        $this->payment($this->workspace, 7000, '2026-05-02', currency: 'USD');
        $pending = $this->payment($this->workspace, 9000, '2026-05-03', status: 'pending');
        $this->movement($this->workspace, $pending, 'dispute_debit', -300, 5, '2026-05-04', currency: 'GBP');
        $this->travelTo(Carbon::parse('2027-01-05 12:10:00 UTC'));

        /** @var list<array<string, mixed>> $rows */
        $rows = $this->getJson($this->url('cash-income').'?limit=100')->assertOk()->json('data');
        $byDay = array_column($rows, null, 'effective_on');
        $this->assertEqualsCanonicalizing(['EUR', 'USD', 'USD', 'GBP'], array_column($rows, 'currency'));
        $this->assertSame(0, $byDay['2026-05-03']['amount']);
        $this->assertSame(-300, $byDay['2026-05-04']['amount']);
        $this->assertSame('GBP', $byDay['2026-05-04']['currency']);
    }

    public function test_pages_follow_one_order_across_every_source_kind(): void
    {
        $this->seedRows($this->workspace, 4);
        $this->travelTo(Carbon::parse('2027-01-05 12:10:00 UTC'));
        $all = $this->getJson($this->url('cash-income').'?limit=100')->assertOk()->json('data');
        $this->assertCount(12, $all);

        $paged = [];
        $cursor = null;
        do {
            $page = $this->getJson($this->url('cash-income').'?limit=5'.($cursor === null ? '' : '&cursor='.urlencode($cursor)))->assertOk()->json();
            array_push($paged, ...$page['data']);
            $cursor = $page['meta']['next_cursor'];
        } while ($cursor !== null);

        $this->assertSame(array_column($all, 'id'), array_column($paged, 'id'));
        $this->assertCount(12, array_unique(array_column($paged, 'id')));
    }

    public function test_another_workspaces_payments_refunds_and_movements_are_never_loaded_or_reported(): void
    {
        $other = $this->workspace('Other Cash Load', 'other-cash-load');
        $mine = $this->payment($this->workspace, 1000, '2026-06-01');
        $theirs = $this->payment($other, 2000, '2026-06-01');
        $this->refund($other, $theirs, 'refund', 500, '2026-06-02 00:00:00');
        $this->movement($other, $theirs, 'fee', 0, 40, '2026-06-03');
        // The schema's composite keys refuse a refund or movement that names another workspace's payment.
        $this->travelTo(Carbon::parse('2027-01-05 12:10:00 UTC'));

        $own = $this->getJson($this->url('cash-income').'?limit=100')->assertOk()->json('data');
        $this->assertCount(1, $own);
        $this->assertSame($mine->public_id, $own[0]['source_id']);
        $this->assertSame(1000, $own[0]['amount']);

        $foreign = $this->getJson('/api/v1/workspaces/'.$other->public_id.'/accounting/cash-income?limit=100')->assertOk()->json('data');
        $this->assertCount(3, $foreign);
        $this->assertEqualsCanonicalizing([-500, 0, 2000], array_column($foreign, 'amount'));

        $months = $this->getJson($this->url('income').'?year=2026')->assertOk()->json('data.months');
        $this->assertSame(1000, $months[5]['cash_received']);
        $this->assertSame(0, $months[5]['refunds']);
        $this->assertSame(0, $months[5]['fees']);
    }

    private function queriesFor(string $path, int $expectedRows): int
    {
        // Past the feed's watermark lag, so every row seeded so far is readable.
        $this->travelTo(now()->addMinutes(10));
        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = $this->getJson($this->url($path))->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();
        if (str_starts_with($path, 'cash-income')) {
            $this->assertCount($expectedRows, $response->json('data'));
        }

        return $count;
    }

    private function seedRows(Workspace $workspace, int $payments): void
    {
        for ($i = 0; $i < $payments; $i++) {
            $payment = $this->payment($workspace, 1000 + $i, '2026-07-'.sprintf('%02d', 1 + $i % 28), refunded: 100, fee: 5);
            $this->refund($workspace, $payment, 'refund', 100, '2026-07-'.sprintf('%02d', 1 + $i % 28).' 08:00:00');
            $this->movement($workspace, $payment, 'fee', 0, 3, '2026-07-'.sprintf('%02d', 1 + $i % 28));
        }
    }

    private function workspace(string $name, string $slug): Workspace
    {
        $workspace = Workspace::query()->create(['name' => $name, 'slug' => $slug]);
        $workspace->memberships()->firstOrCreate(['user_id' => $this->owner->id], ['role' => 'owner']);

        return $workspace;
    }

    private function payment(Workspace $workspace, int $amount, string $receivedOn, int $refunded = 0, ?int $fee = null, string $currency = 'USD', string $status = 'succeeded'): ClientInvoicePayment
    {
        $payment = ClientInvoicePayment::query()->create(['workspace_id' => $workspace->id, 'client_invoice_id' => $this->invoice($workspace)->id,
            'status' => $status, 'amount' => $amount, 'refunded_amount' => $refunded, 'currency' => $currency, 'method' => 'bank_transfer', 'received_on' => $receivedOn]);
        if ($fee !== null) {
            $payment->forceFill(['provider_fee_amount' => $fee])->save();
        }

        return $payment;
    }

    private function invoice(Workspace $workspace): ClientInvoice
    {
        $client = ClientCompany::query()->firstOrCreate(['workspace_id' => $workspace->id, 'slug' => 'synthetic-client'], ['name' => 'Synthetic Client']);

        return ClientInvoice::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $client->id,
            'invoice_number' => 'SYN-LOAD-'.++$this->sequence, 'status' => 'draft', 'issue_date' => '2026-01-05',
            'currency' => 'USD', 'subtotal_amount' => 1000, 'tax_amount' => 0, 'total_amount' => 1000, 'balance_amount' => 1000]);
    }

    private function refund(Workspace $workspace, ClientInvoicePayment $payment, string $kind, int $amount, string $createdAt, ?int $reverses = null): ClientInvoicePaymentRefund
    {
        $refund = ClientInvoicePaymentRefund::query()->create(['workspace_id' => $workspace->id, 'client_invoice_payment_id' => $payment->id,
            'kind' => $kind, 'amount' => $amount, 'reason' => 'Synthetic refund evidence', 'reverses_refund_id' => $reverses]);
        DB::table('client_invoice_payment_refunds')->where('id', $refund->id)->update(['created_at' => $createdAt]);

        return $refund->refresh();
    }

    private function movement(Workspace $workspace, ClientInvoicePayment $payment, string $kind, int $principal, int $fee, string $on, string $currency = 'USD'): ProcessorMovement
    {
        return ProcessorMovement::query()->forceCreate(['workspace_id' => $workspace->id, 'client_invoice_payment_id' => $payment->id,
            'provider' => 'stripe', 'account_key' => 'synthetic-account', 'provider_entry_identifier' => 'synthetic-entry-'.++$this->sequence,
            'kind' => $kind, 'principal_minor' => $principal, 'fee_minor' => $fee, 'net_minor' => $principal - $fee, 'currency' => $currency,
            'provider_occurred_at' => now(), 'occurred_on' => $on, 'occurred_timezone' => 'UTC', 'date_basis' => 'stripe_balance_created']);
    }

    private function url(string $path): string
    {
        return '/api/v1/workspaces/'.$this->workspace->public_id.'/accounting/'.$path;
    }
}
