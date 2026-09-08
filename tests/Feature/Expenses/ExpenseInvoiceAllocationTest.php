<?php

namespace Tests\Feature\Expenses;

use App\Console\Commands\Billing\ReplayInvoicesCommand;
use App\Exceptions\ExpenseTransitionRefused;
use App\Models\ClientAgreement;
use App\Models\ClientBillingSchedule;
use App\Models\ClientCompany;
use App\Models\ClientExpense;
use App\Models\ClientInvoice;
use App\Models\ClientInvoiceLine;
use App\Models\ClientProjectMembership;
use App\Models\Workspace;
use App\Queries\Expenses\WorkspaceExpenses;
use App\Services\Billing\BillingScheduleService;
use App\Services\Billing\ClientInvoicingService;
use App\Services\Billing\ExpenseInvoiceAllocations;
use App\Services\Billing\InvoiceLifecycleService;
use App\Support\AgentApi\AgentApiVersion;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\BuildsSyntheticExpenses;
use Tests\TestCase;

final class ExpenseInvoiceAllocationTest extends TestCase
{
    use BuildsSyntheticExpenses;
    use RefreshDatabase;

    public function test_live_schedule_bills_approved_expenses_at_cost_and_retries_do_not_bill_again(): void
    {
        [$workspace, $company, $expense] = $this->approved();
        $agreement = ClientAgreement::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'title' => 'Synthetic scheduled agreement', 'status' => 'active', 'starts_on' => '2026-01-01', 'currency' => 'USD', 'billing_cadence' => 'monthly']);
        $schedule = ClientBillingSchedule::query()->create([
            'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'client_agreement_id' => $agreement->id,
            'cadence' => 'monthly', 'anchor_day' => 1, 'next_run_on' => '2026-08-01', 'due_days' => 30,
            'currency' => 'USD', 'is_active' => true,
            'line_template' => [['type' => 'service', 'description' => 'Synthetic scheduled service', 'quantity' => '1', 'unit_amount' => 100]],
        ]);
        $version = AgentApiVersion::for($expense);
        $invoices = app(BillingScheduleService::class)->generateDue($schedule, CarbonImmutable::parse('2026-08-01'));
        $this->assertCount(1, $invoices);
        $this->assertSame(12600, $invoices[0]->total_amount);
        $this->assertSame('issued', $invoices[0]->status);
        $expense->refresh();
        $this->assertSame('invoiced', $expense->status);
        $this->assertNotNull($expense->client_invoice_line_id);
        $this->assertNotSame($version, AgentApiVersion::for($expense));
        $this->assertCount(0, app(BillingScheduleService::class)->generateDue($schedule->refresh(), CarbonImmutable::parse('2026-08-01')));
        $this->assertSame(1, ClientInvoiceLine::query()->where('workspace_id', $workspace->id)->where('type', 'expense')->count());
    }

    public function test_regeneration_releases_and_reclaims_preserving_approval_and_manual_lines(): void
    {
        [$workspace, $company, $expense] = $this->approved();
        $invoice = $this->draft($workspace, $company);
        $allocations = app(ExpenseInvoiceAllocations::class);
        DB::transaction(fn () => $allocations->rebuild($invoice, '2026-08-31'));
        $expense->refresh();
        $firstLine = $expense->client_invoice_line_id;
        $approvedAt = $expense->approved_at;
        DB::transaction(fn () => $allocations->rebuild($invoice, '2026-08-31'));
        $expense->refresh();
        $this->assertNotSame($firstLine, $expense->client_invoice_line_id);
        $this->assertEquals($approvedAt, $expense->approved_at);
        $this->assertSame('invoiced', $expense->status);
        $this->assertSame(1, ClientInvoiceLine::query()->where('workspace_id', $workspace->id)->where('type', 'expense')->count());
        $this->assertSame(1, ClientInvoiceLine::query()->where('workspace_id', $workspace->id)->where('type', 'adjustment')->count());
        $this->expectException(DomainException::class);
        app(InvoiceLifecycleService::class)->updateDraft($invoice, $workspace, [], [['description' => 'Replacement', 'type' => 'service', 'quantity' => '1', 'unit_amount' => 5]]);
    }

    public function test_discard_releases_the_claim_and_a_later_invoice_can_bill_it(): void
    {
        [$workspace, $company, $expense] = $this->approved();
        $invoice = $this->draft($workspace, $company);
        DB::transaction(fn () => app(ExpenseInvoiceAllocations::class)->rebuild($invoice, '2026-08-31'));
        app(InvoiceLifecycleService::class)->discardDraft($invoice, $workspace, 'Synthetic replacement');
        $expense->refresh();
        $this->assertSame('approved', $expense->status);
        $this->assertNull($expense->client_invoice_line_id);
        $replacement = $this->draft($workspace, $company, 'SYN-REPLACEMENT');
        DB::transaction(fn () => app(ExpenseInvoiceAllocations::class)->rebuild($replacement, '2026-09-30'));
        $this->assertSame('invoiced', $expense->refresh()->status);
    }

    public function test_scope_currency_date_and_approval_are_checked_and_an_expense_has_one_claim(): void
    {
        [$workspace, $company, $expense] = $this->approved();
        $otherWorkspace = $this->syntheticWorkspace('Other');
        $otherCompany = $this->syntheticCompany($otherWorkspace, 'Other');
        $foreign = $this->recordSyntheticExpense($otherWorkspace, $otherCompany);
        $foreign->forceFill(['status' => 'approved'])->save();
        $draftExpense = $this->recordSyntheticExpense($workspace, $company);
        $eur = $this->recordSyntheticExpense($workspace, $company, facts: $this->syntheticExpenseFacts(currency: 'EUR'));
        $future = $this->recordSyntheticExpense($workspace, $company, facts: $this->syntheticExpenseFacts(spentOn: '2026-09-15'));
        foreach ([$eur, $future] as $row) {
            $row->forceFill(['status' => 'approved'])->save();
        }
        $invoice = $this->draft($workspace, $company);
        DB::transaction(fn () => app(ExpenseInvoiceAllocations::class)->rebuild($invoice, '2026-08-31'));
        $other = $this->draft($workspace, $company, 'SYN-OTHER');
        DB::transaction(fn () => app(ExpenseInvoiceAllocations::class)->rebuild($other, '2026-08-31'));
        $this->assertSame(1, ClientInvoiceLine::query()->where('workspace_id', $workspace->id)->where('type', 'expense')->count());
        foreach ([$foreign, $draftExpense, $eur, $future] as $row) {
            $this->assertNull($row->refresh()->client_invoice_line_id);
        }
        $this->assertNotNull($expense->refresh()->client_invoice_line_id);
    }

    public function test_project_scoped_agreements_claim_only_their_project_and_claimed_rows_are_immutable(): void
    {
        [$workspace, $company, $companyExpense] = $this->approved();
        $project = $this->syntheticProject($company, 'Allowed');
        $otherProject = $this->syntheticProject($company, 'Other');
        $allowed = $this->recordSyntheticExpense($workspace, $company, $project);
        $other = $this->recordSyntheticExpense($workspace, $company, $otherProject);
        $actor = $this->syntheticMember($workspace, 'Project approver');
        foreach ([$allowed, $other] as $row) {
            (new WorkspaceExpenses($workspace))->approve($row, $actor);
        }
        $agreement = $this->agreement($workspace, $company, 'monthly');
        $agreement->forceFill(['client_project_id' => $project->id])->save();
        $invoice = $this->draft($workspace, $company);
        $invoice->forceFill(['client_agreement_id' => $agreement->id])->save();
        DB::transaction(fn () => app(ExpenseInvoiceAllocations::class)->rebuild($invoice, '2026-08-31'));
        $this->assertSame('invoiced', $allowed->refresh()->status);
        $this->assertSame('approved', $other->refresh()->status);
        $this->assertSame('approved', $companyExpense->refresh()->status);
        $this->expectException(ExpenseTransitionRefused::class);
        (new WorkspaceExpenses($workspace))->unapprove($allowed);
    }

    public function test_invoice_association_is_manager_only_and_server_produced(): void
    {
        [$workspace, $company, $expense] = $this->approved();
        $project = $this->syntheticProject($company, 'Visible');
        $expense->forceFill(['client_project_id' => $project->id])->save();
        $invoice = $this->draft($workspace, $company);
        DB::transaction(fn () => app(ExpenseInvoiceAllocations::class)->rebuild($invoice, '2026-08-31'));
        $manager = $this->syntheticMember($workspace, 'Manager');
        $url = route('clients.expenses', [$workspace, $company], absolute: false);
        $this->actingAs($manager)->get($url)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('expenses.0.billing.invoice.number', 'SYN-DRAFT')
            ->where('expenses.0.billing.invoice.url', route('svc.billing.invoices.show', [$workspace, $invoice], absolute: false))
            ->where('expenses.0.can_unapprove', false));
        $member = $this->syntheticMember($workspace, 'Member', 'member');
        ClientProjectMembership::query()->create(['workspace_id' => $workspace->id, 'client_project_id' => $project->id, 'user_id' => $member->id, 'role' => 'contributor']);
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });
        $this->actingAs($member)->get($url)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('expenses.0.billing', null));
        $this->assertSame([], array_values(array_filter($queries, fn (string $sql): bool => str_contains($sql, 'client_invoice_lines') || str_contains($sql, 'client_invoices'))));
    }

    public function test_database_refuses_cross_workspace_claims_and_deleting_claimed_lines(): void
    {
        [$workspace, $company, $expense] = $this->approved();
        $invoice = $this->draft($workspace, $company);
        DB::transaction(fn () => app(ExpenseInvoiceAllocations::class)->rebuild($invoice, '2026-08-31'));
        $lineId = $expense->refresh()->client_invoice_line_id;
        $foreignWorkspace = $this->syntheticWorkspace('Foreign claim');
        $foreign = $this->recordSyntheticExpense($foreignWorkspace, $this->syntheticCompany($foreignWorkspace, 'Foreign claim'));
        foreach ([
            fn () => DB::table('client_expenses')->where('workspace_id', $foreignWorkspace->id)->where('id', $foreign->id)->update(['client_invoice_line_id' => $lineId]),
            fn () => DB::table('client_invoice_lines')->where('workspace_id', $workspace->id)->where('id', $lineId)->delete(),
        ] as $mutation) {
            try {
                DB::transaction($mutation);
                $this->fail('The database must protect the expense claim');
            } catch (QueryException) {
                $this->assertSame($lineId, $expense->refresh()->client_invoice_line_id);
            }
        }
    }

    public function test_ad_hoc_and_interim_invoices_do_not_automatically_claim_expenses(): void
    {
        [$workspace, $company, $expense] = $this->approved();
        foreach (['ad_hoc', 'interim_overage', 'synthetic_unknown'] as $kind) {
            $invoice = $this->draft($workspace, $company, 'SYN-'.$kind);
            $invoice->forceFill(['invoice_kind' => $kind])->save();
            try {
                DB::transaction(fn () => app(ExpenseInvoiceAllocations::class)->rebuild($invoice, '2026-08-31'));
                $this->fail('Non-cadence invoice cannot claim expenses');
            } catch (DomainException) {
                $this->assertNull($expense->refresh()->client_invoice_line_id);
            }
        }
    }

    public function test_replay_clear_releases_expenses_and_outer_rollback_restores_the_original_invoice(): void
    {
        [$workspace, $company, $expense] = $this->approved();
        $invoice = $this->draft($workspace, $company);
        DB::transaction(fn () => app(ExpenseInvoiceAllocations::class)->rebuild($invoice, '2026-08-31'));
        $originalLine = $expense->refresh()->client_invoice_line_id;
        $command = app(ReplayInvoicesCommand::class);
        $clear = new \ReflectionMethod($command, 'clear');
        DB::beginTransaction();
        try {
            $clear->invoke($command, $workspace, collect([$company]));
            $this->assertSame('approved', $expense->refresh()->status);
            $this->assertNull($expense->client_invoice_line_id);
            $this->assertSame(0, ClientInvoiceLine::query()->where('workspace_id', $workspace->id)->where('client_invoice_id', $invoice->id)->count());
        } finally {
            DB::rollBack();
        }
        $this->assertSame('invoiced', $expense->refresh()->status);
        $this->assertSame($originalLine, $expense->client_invoice_line_id);
        $this->assertTrue(ClientInvoice::query()->where('workspace_id', $workspace->id)->whereKey($invoice->id)->exists());
    }

    /** @return array<string, array{string, string, string}> */
    public static function cadences(): array
    {
        return ['monthly' => ['monthly', '2026-08-01', '2026-08-31'], 'quarterly' => ['quarterly', '2026-07-01', '2026-09-30']];
    }

    #[DataProvider('cadences')]
    public function test_legacy_generators_regenerate_expense_only_drafts(string $cadence, string $start, string $end): void
    {
        [$workspace, $company, $expense] = $this->approved();
        $agreement = $this->agreement($workspace, $company, $cadence);
        $service = app(ClientInvoicingService::class);
        $first = $service->generateInvoice($company, Carbon::parse($start), Carbon::parse($end), $agreement);
        $this->assertSame(12500, $first->total_amount);
        $firstLine = $expense->refresh()->client_invoice_line_id;
        $second = $service->generateInvoice($company, Carbon::parse($start), Carbon::parse($end), $agreement);
        $this->assertSame($first->id, $second->id);
        $this->assertSame(12500, $second->total_amount);
        $this->assertNotSame($firstLine, $expense->refresh()->client_invoice_line_id);
        $this->assertSame(1, ClientInvoiceLine::query()->where('workspace_id', $workspace->id)->where('type', 'expense')->count());
    }

    public function test_expense_only_backlog_is_not_skipped_and_late_approval_carries_forward_after_termination(): void
    {
        $this->travelTo(Carbon::parse('2026-10-01'));
        [$workspace, $company, $expense] = $this->approved();
        $agreement = $this->agreement($workspace, $company, 'monthly');
        $agreement->forceFill(['ends_on' => '2026-08-31', 'status' => 'terminated'])->save();
        app(ClientInvoicingService::class)->generateAllInvoices($company);
        $this->assertSame('invoiced', $expense->refresh()->status);
        $invoice = ClientInvoice::query()->where('workspace_id', $workspace->id)->whereHas('lines', fn ($q) => $q->where('workspace_id', $workspace->id)->where('type', 'expense'))->firstOrFail();
        app(InvoiceLifecycleService::class)->issue($invoice, $workspace);
        $late = $this->recordSyntheticExpense($workspace, $company, facts: $this->syntheticExpenseFacts(label: 'late approval', spentOn: '2026-08-20'));
        (new WorkspaceExpenses($workspace))->approve($late, $this->syntheticMember($workspace, 'Late approver'));
        app(ClientInvoicingService::class)->generateAllInvoices($company);
        $this->assertSame('invoiced', $late->refresh()->status);
        $this->assertNotSame($expense->client_invoice_line_id, $late->client_invoice_line_id);
    }

    public function test_failed_regeneration_rolls_back_the_original_claim_and_line(): void
    {
        [$workspace, $company, $expense] = $this->approved();
        $invoice = $this->draft($workspace, $company);
        DB::transaction(fn () => app(ExpenseInvoiceAllocations::class)->rebuild($invoice, '2026-08-31'));
        $original = $expense->refresh()->client_invoice_line_id;
        try {
            DB::transaction(function () use ($invoice): void {
                app(ExpenseInvoiceAllocations::class)->rebuild($invoice, '2026-08-31');
                throw new RuntimeException('Synthetic later composition failure');
            });
            $this->fail('Expected synthetic rollback');
        } catch (RuntimeException $exception) {
            $this->assertSame('Synthetic later composition failure', $exception->getMessage());
        }
        $this->assertSame($original, $expense->refresh()->client_invoice_line_id);
        $this->assertSame('invoiced', $expense->status);
        $this->assertTrue(ClientInvoiceLine::query()->where('workspace_id', $workspace->id)->whereKey($original)->exists());
    }

    public function test_void_releases_issued_expenses_but_paid_invoice_refusal_preserves_claim(): void
    {
        [$workspace, $company, $expense] = $this->approved();
        $invoice = $this->draft($workspace, $company);
        DB::transaction(fn () => app(ExpenseInvoiceAllocations::class)->rebuild($invoice, '2026-08-31'));
        $invoice->forceFill(['status' => 'paid', 'paid_amount' => 12500])->save();
        try {
            app(InvoiceLifecycleService::class)->void($invoice, $workspace);
            $this->fail('Paid invoice must refuse void');
        } catch (DomainException) {
            $this->assertSame('invoiced', $expense->refresh()->status);
        }
        $invoice->forceFill(['status' => 'issued', 'paid_amount' => 0])->save();
        app(InvoiceLifecycleService::class)->void($invoice, $workspace);
        $this->assertSame('approved', $expense->refresh()->status);
        $this->assertNull($expense->client_invoice_line_id);
    }

    private function agreement(Workspace $workspace, ClientCompany $company, string $cadence): ClientAgreement
    {
        return ClientAgreement::query()->create([
            'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'title' => 'Synthetic expense agreement',
            'status' => 'active', 'starts_on' => '2026-07-01', 'currency' => 'USD', 'billing_cadence' => $cadence,
            'retainer_minutes' => 0, 'retainer_amount' => 0, 'hourly_rate_amount' => 10000, 'rollover_months' => 0,
        ]);
    }

    /** @return array{Workspace, ClientCompany, ClientExpense} */
    private function approved(): array
    {
        $workspace = $this->syntheticWorkspace('Allocation');
        $company = $this->syntheticCompany($workspace, 'Allocation');
        $expense = $this->recordSyntheticExpense($workspace, $company);
        $expense = (new WorkspaceExpenses($workspace))->approve($expense, $this->syntheticMember($workspace, 'Approver'));

        return [$workspace, $company, $expense];
    }

    private function draft(Workspace $workspace, ClientCompany $company, string $number = 'SYN-DRAFT'): ClientInvoice
    {
        return app(InvoiceLifecycleService::class)->createDraft($workspace, $company,
            ['invoice_number' => $number, 'invoice_kind' => 'cadence_period', 'currency' => 'USD', 'service_period_start' => '2026-08-01', 'service_period_end' => '2026-08-31'],
            [['description' => 'Synthetic manual line', 'type' => 'adjustment', 'quantity' => '1', 'unit_amount' => 100]]);
    }
}
