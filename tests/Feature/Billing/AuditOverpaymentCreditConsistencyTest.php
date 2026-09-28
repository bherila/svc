<?php

namespace Tests\Feature\Billing;

use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientInvoiceLine;
use App\Models\Workspace;
use App\Services\Billing\OverpaymentCreditAuditor;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\UsesAProbeDatabase;
use Tests\TestCase;

/**
 * The credit audit reads invoices, then their payments, then their credit
 * lines. Read as separate autocommit statements, a write committing between
 * them is seen by the later reads and not the earlier one, and the audit can
 * report a balance that never existed at any moment.
 *
 * Here a pool is balanced before and after: 100.00 of overpayment funds a
 * 100.00 credit spent on an issued invoice, and one transaction voids that
 * invoice and refunds the overpayment. Committed after the invoices are read
 * and before the payments are, it would show the spend still charged and the
 * funding already gone - a 100.00 deficit nobody ever had.
 */
final class AuditOverpaymentCreditConsistencyTest extends TestCase
{
    use UsesAProbeDatabase;

    public function test_the_audit_reads_one_consistent_snapshot(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('A second connection committing mid-audit is exercised in the MariaDB lane.');
        }

        $this->bootProbeDatabase('audit_probe');
        Artisan::call('migrate', ['--database' => 'audit_probe', '--force' => true]);
        config(['database.connections.audit_writer' => config('database.connections.audit_probe')]);
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection('audit_probe');
        Schema::clearResolvedInstance('db.schema');
        try {
            $workspace = Workspace::query()->create(['name' => 'Synthetic audit snapshot', 'slug' => 'synthetic-audit-snapshot']);
            $company = ClientCompany::query()->create([
                'workspace_id' => $workspace->id, 'name' => 'Synthetic audit client', 'slug' => 'synthetic-audit-client',
            ]);
            $source = ClientInvoice::query()->create([
                'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'invoice_number' => 'SYNTH-AUDIT-SRC',
                'currency' => 'USD', 'status' => 'paid', 'invoice_kind' => 'ad_hoc',
                'subtotal_amount' => 10000, 'tax_amount' => 0, 'total_amount' => 10000, 'paid_amount' => 20000,
            ]);
            $payment = $source->payments()->create([
                'workspace_id' => $workspace->id, 'amount' => 20000, 'refunded_amount' => 0, 'currency' => 'USD',
                'status' => 'succeeded', 'method' => 'manual', 'received_on' => '2024-01-31',
            ]);
            $spend = ClientInvoice::query()->create([
                'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'invoice_number' => 'SYNTH-AUDIT-SPEND',
                'currency' => 'USD', 'status' => 'issued', 'invoice_kind' => 'ad_hoc',
                'subtotal_amount' => 40000, 'tax_amount' => 0, 'total_amount' => 40000,
            ]);
            ClientInvoiceLine::query()->create([
                'workspace_id' => $workspace->id, 'client_invoice_id' => $spend->id, 'type' => 'credit',
                'description' => 'Credit', 'quantity' => '1', 'unit_amount' => -10000, 'tax_amount' => 0,
                'total_amount' => -10000, 'sort_order' => 0,
            ]);

            $armed = true;
            DB::listen(static function (QueryExecuted $query) use (&$armed, $spend, $payment): void {
                if (! $armed || $query->connectionName !== 'audit_probe' || ! str_contains($query->sql, 'from `client_invoices`')) {
                    return;
                }
                $armed = false;
                DB::connection('audit_writer')->transaction(function () use ($spend, $payment): void {
                    DB::connection('audit_writer')->table('client_invoices')->where('id', $spend->id)->update(['status' => 'void']);
                    DB::connection('audit_writer')->table('client_invoice_payments')->where('id', $payment->id)->update(['refunded_amount' => 10000]);
                });
            });

            $pools = app(OverpaymentCreditAuditor::class)->partitions();

            $this->assertFalse($armed, 'The concurrent write ran during the audit');
            $this->assertCount(1, $pools);
            $this->assertSame(0, $pools[0]->deficit(), 'A deficit that existed at no moment: funded '.$pools[0]->funded().', consumed '.$pools[0]->consumed());
            $this->assertSame([10000, 10000], [$pools[0]->funded(), $pools[0]->consumed()], 'The state before the write, read whole');
        } finally {
            DB::purge('audit_writer');
            DB::setDefaultConnection($original);
            Schema::clearResolvedInstance('db.schema');
        }
    }
}
