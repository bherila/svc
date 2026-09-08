<?php

namespace Tests\Feature\Billing;

use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientInvoicePayment;
use App\Models\Workspace;
use App\Services\Billing\InvoiceLifecycleService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\WritesLegacyCrossTenantRows;
use Tests\TestCase;

final class PaymentTenantScopingTest extends TestCase
{
    use RefreshDatabase, WritesLegacyCrossTenantRows;

    public static function paymentPaths(): iterable
    {
        foreach (['apply', 'status', 'refund'] as $path) {
            yield $path.' with workspace' => [$path, true];
            yield $path.' without workspace' => [$path, false];
        }
    }

    #[DataProvider('paymentPaths')]
    public function test_every_tenant_statement_in_payment_paths_names_its_workspace(string $path, bool $withWorkspace): void
    {
        [$workspace, $invoice, $payment] = $this->fixture();
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $this->runPath($path, $invoice, $payment, $withWorkspace ? $workspace : null);
        $this->assertNotEmpty($queries);
        foreach ($queries as $sql) {
            $normal = str_replace(['`', '"', '[', ']'], '', $sql);
            preg_match_all('/\b(?:from|into|update|join)\s+([a-z0-9_]+)/i', $normal, $matches);
            foreach ($matches[1] as $table) {
                // The tenant itself and global user identities have no workspace_id.
                if (in_array($table, ['workspaces', 'users'], true)) {
                    continue;
                }
                if (preg_match('/^insert/i', $normal)) {
                    $this->assertStringContainsString('workspace_id', $normal, $sql);
                } else {
                    $this->assertMatchesRegularExpression('/\bwhere\b.*\bworkspace_id\s*=\s*\?/i', $normal, $sql);
                }
            }
        }
    }

    #[DataProvider('paymentPaths')]
    public function test_foreign_payment_never_contributes_to_invoice_arithmetic(string $path, bool $withWorkspace): void
    {
        [$workspace, $invoice, $payment] = $this->fixture();
        [$foreignWorkspace] = $this->fixture();
        $this->writingLegacyCrossTenantRows(fn () => ClientInvoicePayment::query()->create([
            'workspace_id' => $foreignWorkspace->id,
            'client_invoice_id' => $invoice->id,
            'status' => 'succeeded', 'amount' => 9000, 'refunded_amount' => 0,
            'currency' => 'USD', 'method' => 'wire',
        ]));
        $this->runPath($path, $invoice, $payment, $withWorkspace ? $workspace : null);
        $expected = match ($path) {
            'apply', 'status' => 2000, 'refund' => 500
        };
        $this->assertSame($expected, $invoice->fresh()->paid_amount);
        $this->assertSame(10000 - $expected, $invoice->fresh()->balance_amount);
    }

    public static function siblingPaths(): iterable
    {
        yield 'status' => ['status'];
        yield 'refund' => ['refund'];
    }

    #[DataProvider('siblingPaths')]
    public function test_a_payment_cannot_reach_an_invoice_in_another_workspace(string $path): void
    {
        [$workspace, $invoice, $payment] = $this->fixture();
        [, $foreignInvoice] = $this->fixture();
        $this->writingLegacyCrossTenantRows(fn () => $payment->forceFill(['client_invoice_id' => $foreignInvoice->id])->save());
        try {
            $this->runPath($path, $invoice, $payment, null);
            $this->fail('A foreign invoice was accepted.');
        } catch (ModelNotFoundException) {
            $this->assertSame('succeeded', $payment->fresh()->status);
            $this->assertSame(0, $payment->fresh()->refunded_amount);
            $this->assertSame(1000, $foreignInvoice->fresh()->paid_amount);
        }
    }

    public function test_enforced_composite_key_refuses_a_foreign_payment(): void
    {
        [, $invoice, $payment] = $this->fixture();
        [, $foreignInvoice] = $this->fixture();
        $this->expectException(QueryException::class);
        $payment->forceFill(['client_invoice_id' => $foreignInvoice->id])->save();
    }

    #[DataProvider('siblingPaths')]
    public function test_a_callers_workspace_cannot_override_the_payments_owner(string $path): void
    {
        [, $invoice, $payment] = $this->fixture();
        [$foreignWorkspace] = $this->fixture();
        try {
            $this->runPath($path, $invoice, $payment, $foreignWorkspace);
            $this->fail('A caller from another workspace was accepted.');
        } catch (ModelNotFoundException) {
            $this->assertSame('succeeded', $payment->fresh()->status);
            $this->assertSame(0, $payment->fresh()->refunded_amount);
        }
    }

    public function test_migration_preflight_refuses_existing_cross_tenant_payments(): void
    {
        [, , $payment] = $this->fixture();
        [, $foreignInvoice] = $this->fixture();
        $this->writingLegacyCrossTenantRows(fn () => $payment->forceFill(['client_invoice_id' => $foreignInvoice->id])->save());
        $migration = require database_path('migrations/2026_08_31_000200_add_composite_tenant_foreign_keys.php');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('client_invoice_payments.client_invoice_id -> client_invoices: 1');
        // up() must refuse at preflight, before trying any DDL on this already migrated schema.
        $migration->up();
    }

    private function runPath(string $path, ClientInvoice $invoice, ClientInvoicePayment $payment, ?Workspace $workspace): void
    {
        $service = app(InvoiceLifecycleService::class);
        if ($path === 'status') {
            $service->setPaymentStatus($payment, 'failed', $workspace);
            $service->setPaymentStatus($payment, 'succeeded', $workspace);
            $service->applyPayment(ClientInvoice::query()->where('workspace_id', $invoice->workspace_id)->whereKey($invoice->id)->firstOrFail(), ['amount' => 1000, 'currency' => 'USD', 'method' => 'wire'], $workspace);
        } elseif ($path === 'refund') {
            $service->setRefundedAmount($payment, 500, $workspace);
        } else {
            $service->applyPayment($invoice, ['amount' => 1000, 'currency' => 'USD', 'method' => 'wire'], $workspace);
        }
    }

    /** @return array{Workspace, ClientInvoice, ClientInvoicePayment} */
    private function fixture(): array
    {
        $workspace = Workspace::query()->create(['name' => 'Synthetic workspace', 'slug' => 'synthetic-'.str()->uuid()]);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic client', 'slug' => 'synthetic-client']);
        $service = app(InvoiceLifecycleService::class);
        $invoice = $service->issue($service->createDraft($workspace, $company, ['currency' => 'USD', 'invoice_number' => 'SYNTHETIC-'.str()->uuid()], [
            ['type' => 'adjustment', 'description' => 'Synthetic work', 'quantity' => 1, 'unit_amount' => 10000],
        ]), $workspace);
        $payment = $service->applyPayment($invoice, ['amount' => 1000, 'currency' => 'USD', 'method' => 'wire'], $workspace);

        return [$workspace, $invoice->fresh(), $payment];
    }
}
