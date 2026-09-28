<?php

namespace Tests\Feature\Billing;

use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientInvoiceLine;
use App\Models\Workspace;
use App\Services\Billing\OverpaymentCreditAuditor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The read-only comparison of credit funded with credit consumed.
 *
 * What it must never do is let one number stand for another: a surplus in one
 * pool offsetting another's deficit, one currency netting against another, or
 * a pool it cannot read reported as a balance of zero.
 */
class AuditOverpaymentCreditCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_pool_that_spent_its_credit_twice_is_reported_as_a_deficit(): void
    {
        $company = $this->company('deficit');
        $this->overpaid($company, 10000);
        $this->spent($company, 10000, 'SYNTH-SPEND-1');
        $this->spent($company, 10000, 'SYNTH-SPEND-2');

        $report = $this->report(['--list' => true]);

        $this->assertSame(1, $report['summary']['in_deficit']);
        $this->assertSame(['USD' => 10000], $report['summary']['deficit_minor_by_currency']);
        $this->assertSame(
            ['funded_minor' => 10000, 'consumed_minor' => 20000, 'difference_minor' => -10000, 'deficit_minor' => 10000],
            array_intersect_key($report['pools'][0], array_flip(['funded_minor', 'consumed_minor', 'difference_minor', 'deficit_minor'])),
        );
    }

    public function test_a_surplus_in_one_pool_never_offsets_a_deficit_in_another(): void
    {
        $short = $this->company('short');
        $this->overpaid($short, 5000);
        $this->spent($short, 10000, 'SYNTH-SHORT');
        $flush = $this->company('flush');
        $this->overpaid($flush, 50000);

        // Nor across currencies within one company.
        $this->overpaid($short, 50000, 'EUR');

        $summary = $this->report()['summary'];

        $this->assertSame(3, $summary['pools']);
        $this->assertSame(1, $summary['in_deficit']);
        $this->assertSame(['USD' => 5000], $summary['deficit_minor_by_currency']);
    }

    public function test_draft_and_void_credit_lines_are_not_consumption(): void
    {
        $company = $this->company('uncharged');
        $this->overpaid($company, 10000);
        $this->spent($company, 10000, 'SYNTH-DRAFT', 'draft');
        $this->spent($company, 10000, 'SYNTH-VOID', 'void');

        $pool = $this->report(['--list' => true])['pools'][0];

        $this->assertSame(0, $pool['consumed_minor']);
        $this->assertSame(10000, $pool['difference_minor']);
        $this->assertSame(0, $pool['deficit_minor']);
    }

    public function test_only_settled_money_net_of_refunds_funds_credit(): void
    {
        $company = $this->company('settled');
        $source = $this->overpaid($company, 10000);
        DB::table('client_invoice_payments')->where('client_invoice_id', $source->id)->update(['refunded_amount' => 4000]);
        DB::table('client_invoice_payments')->insert([
            'public_id' => (string) str()->uuid(), 'workspace_id' => $company->workspace_id,
            'client_invoice_id' => $source->id, 'amount' => 90000, 'refunded_amount' => 0,
            'currency' => 'USD', 'status' => 'failed', 'method' => 'manual', 'received_on' => '2024-02-01',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertSame(6000, $this->report(['--list' => true])['pools'][0]['funded_minor']);
    }

    public function test_a_pool_with_unreadable_data_is_unevaluable_and_not_zero(): void
    {
        $company = $this->company('unreadable');
        $source = $this->overpaid($company, 10000);
        DB::table('client_invoice_payments')->where('client_invoice_id', $source->id)->update(['status' => 'Succeeded']);
        $this->spent($company, 10000, 'SYNTH-UNREADABLE');

        $report = $this->report(['--list' => true]);

        $this->assertSame(1, $report['summary']['unevaluable']);
        $this->assertSame(0, $report['summary']['in_deficit']);
        $this->assertSame(['unreadable_payment_status' => 1], $report['summary']['unevaluable_reasons']);
        $this->assertNull($report['pools'][0]['funded_minor']);
        $this->assertNull($report['pools'][0]['difference_minor']);
        $this->assertNull($report['pools'][0]['deficit_minor']);
    }

    /**
     * A credit line that adds to the invoice is not spending credit, and is
     * not zero spending either. (Payments and lines in another workspace are
     * refused by the composite tenant keys before they can exist; the auditor
     * checks for them anyway, as the ledger does.)
     */
    public function test_an_inverted_credit_line_is_a_reason_not_a_value(): void
    {
        $company = $this->company('inverted');
        $this->overpaid($company, 10000);
        $spend = $this->spent($company, 10000, 'SYNTH-INVERTED');
        DB::table('client_invoice_lines')->where('client_invoice_id', $spend->id)->where('type', 'credit')->update(['total_amount' => 10000]);

        $summary = $this->report()['summary'];

        $this->assertSame(['credit_line_with_positive_amount' => 1], $summary['unevaluable_reasons']);
        $this->assertSame(1, $summary['unevaluable']);
        $this->assertSame([], $summary['deficit_minor_by_currency']);
    }

    public function test_the_default_output_names_no_workspace_company_or_invoice(): void
    {
        $company = $this->company('private');
        $this->overpaid($company, 10000);
        $this->spent($company, 20000, 'SYNTH-PRIVATE-NUMBER');

        $this->assertSame(0, Artisan::call('svc:billing:audit-overpayment-credit'));
        $text = Artisan::output();
        $this->assertSame(0, Artisan::call('svc:billing:audit-overpayment-credit', ['--format' => 'json']));
        $json = Artisan::output();

        foreach ([$text, $json] as $output) {
            foreach ([$company->public_id, $company->name, $company->workspace->public_id, 'SYNTH-PRIVATE-NUMBER'] as $secret) {
                $this->assertStringNotContainsString((string) $secret, $output);
            }
        }
        $this->assertStringContainsString('not proof of how it arose', $text);
        $this->assertArrayNotHasKey('pools', json_decode($json, true, flags: JSON_THROW_ON_ERROR));
    }

    /**
     * Ordinary billing is not a credit pool. An invoice paid exactly, or with
     * only failed or pending attempts, funds nothing and spends nothing, and
     * must not be counted among the pools the report says have activity.
     */
    public function test_invoices_with_no_credit_activity_are_not_pools(): void
    {
        $company = $this->company('ordinary');
        $paid = $this->overpaid($company, 0);
        DB::table('client_invoice_payments')->insert([
            'public_id' => (string) str()->uuid(), 'workspace_id' => $company->workspace_id,
            'client_invoice_id' => $paid->id, 'amount' => 5000, 'refunded_amount' => 0,
            'currency' => 'USD', 'status' => 'failed', 'method' => 'manual', 'received_on' => '2024-02-01',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $report = $this->report(['--list' => true]);

        $this->assertSame(0, $report['summary']['pools']);
        $this->assertSame([], $report['pools']);
    }

    /**
     * The audit is meant for whole production histories, so its query count
     * must not grow with the number of invoices: rows are loaded in bounded
     * batches and the arithmetic is done in memory.
     */
    public function test_its_query_count_does_not_grow_with_the_number_of_invoices(): void
    {
        $company = $this->company('batched');
        for ($i = 0; $i < 30; $i++) {
            $this->overpaid($company, 100);
        }
        $this->spent($company, 1000, 'SYNTH-BATCH-SPEND');

        DB::flushQueryLog();
        DB::enableQueryLog();
        $pools = app(OverpaymentCreditAuditor::class)->partitions();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertCount(1, $pools);
        $this->assertSame(3000, $pools[0]->funded());
        $this->assertSame(1000, $pools[0]->consumed());
        $this->assertLessThanOrEqual(6, $queries, 'One batch of 31 invoices should take a handful of queries, not two per invoice');
    }

    public function test_it_writes_nothing(): void
    {
        $company = $this->company('readonly');
        $this->overpaid($company, 10000);
        $this->spent($company, 20000, 'SYNTH-READONLY');
        $before = $this->fingerprint();

        $this->report(['--list' => true]);

        $this->assertSame($before, $this->fingerprint());
    }

    /** @param  array<string, mixed>  $options */
    private function report(array $options = []): array
    {
        $this->assertSame(0, Artisan::call('svc:billing:audit-overpayment-credit', ['--format' => 'json', ...$options]));

        return json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function company(string $slug): ClientCompany
    {
        $workspace = Workspace::query()->create(['name' => 'Synthetic '.$slug, 'slug' => 'synthetic-'.$slug]);

        return ClientCompany::query()->create([
            'workspace_id' => $workspace->id, 'name' => 'Synthetic '.$slug.' client', 'slug' => 'synthetic-'.$slug.'-client',
        ]);
    }

    /** An invoice paid `$by` over its 100.00 total - imported history, as rows. */
    private function overpaid(ClientCompany $company, int $by, string $currency = 'USD'): ClientInvoice
    {
        $invoice = ClientInvoice::query()->create([
            'workspace_id' => $company->workspace_id, 'client_company_id' => $company->id,
            'invoice_number' => 'SYNTH-SRC-'.str()->upper(str()->random(6)), 'currency' => $currency,
            'status' => 'paid', 'invoice_kind' => 'ad_hoc',
            'subtotal_amount' => 10000, 'tax_amount' => 0, 'total_amount' => 10000, 'paid_amount' => 10000 + $by,
        ]);
        $invoice->payments()->create([
            'workspace_id' => $company->workspace_id, 'amount' => 10000 + $by, 'refunded_amount' => 0,
            'currency' => $currency, 'status' => 'succeeded', 'method' => 'manual', 'received_on' => '2024-01-31',
        ]);

        return $invoice;
    }

    private function spent(ClientCompany $company, int $credit, string $number, string $status = 'issued'): ClientInvoice
    {
        $invoice = ClientInvoice::query()->create([
            'workspace_id' => $company->workspace_id, 'client_company_id' => $company->id,
            'invoice_number' => $number, 'currency' => 'USD', 'status' => $status, 'invoice_kind' => 'ad_hoc',
            'subtotal_amount' => 50000 - $credit, 'tax_amount' => 0, 'total_amount' => 50000 - $credit,
        ]);
        foreach ([['additional_hours', 50000], ['credit', -$credit]] as $i => [$type, $amount]) {
            ClientInvoiceLine::query()->create([
                'workspace_id' => $company->workspace_id, 'client_invoice_id' => $invoice->id, 'type' => $type,
                'description' => 'Synthetic '.$type, 'quantity' => '1', 'unit_amount' => $amount,
                'tax_amount' => 0, 'total_amount' => $amount, 'sort_order' => $i,
            ]);
        }

        return $invoice;
    }

    /** @return array<string, string> */
    private function fingerprint(): array
    {
        $tables = ['client_invoices', 'client_invoice_lines', 'client_invoice_payments', 'client_companies'];

        return array_combine($tables, array_map(
            fn (string $table): string => md5((string) json_encode(DB::table($table)->orderBy('id')->get())),
            $tables,
        ));
    }
}
