<?php

namespace Tests\Feature\Billing;

use App\Models\ClientAgreement;
use App\Models\ClientInvoice;
use App\Models\ClientInvoiceLine;
use App\Models\ClientTimeEntry;
use App\Queries\Expenses\WorkspaceExpenses;
use App\Services\Billing\ClientInvoicingService;
use App\Services\Billing\ExpenseInvoiceAllocations;
use App\Services\Billing\InvoiceLifecycleService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsSyntheticExpenses;
use Tests\TestCase;

final class ReplayExpenseClaimsTest extends TestCase
{
    use BuildsSyntheticExpenses, RefreshDatabase;

    public static function histories(): iterable
    {
        yield 'observed late claim' => ['valid'];
        yield 'expense-only observed invoice' => ['expense-only'];
        yield 'superseded invoice claim' => ['superseded'];
        yield 'malformed claim status' => ['malformed'];
    }

    #[DataProvider('histories')]
    public function test_real_replay_preserves_observed_claims_or_refuses_before_clearing_and_rolls_back_every_row(string $shape): void
    {
        $workspace = $this->syntheticWorkspace('Replay expense');
        $company = $this->syntheticCompany($workspace, 'Replay expense');
        $manager = $this->syntheticMember($workspace, 'Replay approver');
        ClientAgreement::query()->create([
            'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'title' => 'Synthetic monthly retainer',
            'status' => 'active', 'currency' => 'USD', 'starts_on' => '2026-07-01', 'billing_cadence' => 'monthly',
            'retainer_amount' => $shape === 'expense-only' ? 0 : 10000, 'retainer_minutes' => $shape === 'expense-only' ? 0 : 60, 'hourly_rate_amount' => 10000, 'rollover_months' => 0,
        ]);
        if ($shape === 'expense-only') {
            $project = $this->syntheticProject($company, 'Historical work');
            ClientTimeEntry::query()->create([
                'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'client_project_id' => $project->id,
                'user_id' => $manager->id, 'worked_on' => '2026-07-10', 'minutes' => 60, 'description' => 'Synthetic historical work',
                'status' => 'approved', 'is_billable' => true, 'is_deferred' => false, 'currency' => 'USD',
            ]);
        }
        Carbon::setTestNow(Carbon::parse('2026-08-15'));
        try {
            app(ClientInvoicingService::class)->generateAllInvoices($company);
            foreach (ClientInvoice::query()->where('workspace_id', $workspace->id)->get() as $invoice) {
                app(InvoiceLifecycleService::class)->issue($invoice, $workspace);
            }
            Carbon::setTestNow(Carbon::parse('2026-09-15'));
            $expense = $this->recordSyntheticExpense($workspace, $company, facts: $this->syntheticExpenseFacts(spentOn: '2026-07-15'));
            (new WorkspaceExpenses($workspace))->approve($expense, $manager);
            app(ClientInvoicingService::class)->generateAllInvoices($company);
            $line = ClientInvoiceLine::query()->where('workspace_id', $workspace->id)->whereKey($expense->refresh()->client_invoice_line_id)->firstOrFail();
            $target = ClientInvoice::query()->where('workspace_id', $workspace->id)->whereKey($line->client_invoice_id)->firstOrFail();
            $this->assertGreaterThan('2026-07-15', $target->service_period_start->toDateString());
            if ($shape === 'expense-only') {
                $this->assertSame(12500, $target->total_amount);
            }
            app(InvoiceLifecycleService::class)->issue($target, $workspace);
            if ($shape === 'superseded') {
                $duplicate = $target->replicate();
                $duplicate->forceFill(['public_id' => (string) Str::uuid(), 'invoice_number' => 'SYN-SUPERSEDED', 'status' => 'draft', 'issued_at' => null, 'is_visible_to_client' => false])->save();
                $duplicateLine = $line->replicate();
                $duplicateLine->forceFill(['public_id' => (string) Str::uuid(), 'client_invoice_id' => $duplicate->id])->save();
                $expense->forceFill(['client_invoice_line_id' => $duplicateLine->id])->save();
            } elseif ($shape === 'malformed') {
                $expense->forceFill(['status' => 'approved'])->save();
            }
            // A second tenant's historical claim is outside the selected replay.
            $foreignWorkspace = $this->syntheticWorkspace('Foreign replay');
            $foreignCompany = $this->syntheticCompany($foreignWorkspace, 'Foreign replay');
            $foreignExpense = $this->recordSyntheticExpense($foreignWorkspace, $foreignCompany);
            $foreignExpense->forceFill(['status' => 'approved'])->save();
            $foreignInvoice = app(InvoiceLifecycleService::class)->createDraft($foreignWorkspace, $foreignCompany,
                ['invoice_number' => 'SYN-FOREIGN-CLAIM', 'invoice_kind' => 'cadence_period', 'currency' => 'USD', 'service_period_start' => '2026-08-01', 'service_period_end' => '2026-08-31'],
                [['description' => 'Synthetic foreign charge', 'type' => 'adjustment', 'quantity' => '1', 'unit_amount' => 100]]);
            DB::transaction(fn () => app(ExpenseInvoiceAllocations::class)->rebuild($foreignInvoice, '2026-08-31'));
            $before = $this->fingerprint();
            $clearWrites = [];
            DB::listen(function ($event) use (&$clearWrites): void {
                $sql = str_replace(['`', '"'], '', strtolower($event->sql));
                if (preg_match('/^(?:update|delete from) (?:client_invoices|client_invoice_lines|client_expenses|client_tasks|client_time_entries)\b/', $sql)) {
                    $clearWrites[] = $sql;
                }
            });
            if (in_array($shape, ['valid', 'expense-only'], true)) {
                for ($attempt = 0; $attempt < 2; $attempt++) {
                    $this->artisan('svc:billing:replay', ['--workspace' => $workspace->public_id, '--as-of' => '2026-09-15'])->assertSuccessful();
                    $this->assertSame($before, $this->fingerprint());
                    $this->assertSame($line->id, $expense->refresh()->client_invoice_line_id);
                }
                Carbon::setTestNow(Carbon::parse('2026-09-15'));
                app(ClientInvoicingService::class)->generateAllInvoices($company);
            } else {
                try {
                    Artisan::call('svc:billing:replay', ['--workspace' => $workspace->public_id, '--as-of' => '2026-09-15']);
                    $this->fail('Unsafe historical claim must refuse replay');
                } catch (\RuntimeException $exception) {
                    $this->assertStringContainsString($shape === 'superseded' ? 'superseded invoice carries an expense claim' : 'inconsistent historical invoice claim', $exception->getMessage());
                    $this->assertSame([], $clearWrites);
                }
            }
            $this->assertSame($before, $this->fingerprint());
        } finally {
            Carbon::setTestNow();
        }
    }

    /** @return array<string, string> */
    private function fingerprint(): array
    {
        $snapshot = [];
        foreach (Schema::getTableListing() as $table) {
            if (str_starts_with($table, 'sqlite_')) {
                continue;
            }
            $rows = array_map(static fn ($row): string => json_encode($row, JSON_THROW_ON_ERROR), DB::table($table)->get()->all());
            sort($rows, SORT_STRING);
            $snapshot[$table] = hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
        }
        ksort($snapshot);

        return $snapshot;
    }
}
