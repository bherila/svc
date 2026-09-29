<?php

namespace Tests\Feature\Billing;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientProject;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Queries\Expenses\WorkspaceExpenses;
use App\Services\Billing\ClientInvoicingService;
use App\Support\Billing\InvoiceLineType;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsSyntheticExpenses;
use Tests\TestCase;

/**
 * Catch-up bills ordinary (non-deferred) time, and approved expenses are billed
 * beside it; deferred work never reaches a catch-up line.
 */
final class CatchUpBillsOrdinaryWorkAndExpensesTest extends TestCase
{
    use BuildsSyntheticExpenses;
    use RefreshDatabase;

    private Workspace $workspace;

    private ClientCompany $company;

    private ClientProject $project;

    private ClientAgreement $agreement;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workspace = $this->syntheticWorkspace('Catch-up');
        $this->company = $this->syntheticCompany($this->workspace, 'Catch-up');
        $this->project = $this->syntheticProject($this->company, 'Catch-up');
        $this->manager = $this->syntheticMember($this->workspace, 'Catch-up approver');
        $this->agreement = ClientAgreement::query()->create([
            'workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id, 'title' => 'Retainer',
            'status' => 'active', 'currency' => 'USD', 'starts_on' => '2026-01-01',
            'retainer_minutes' => 600, 'retainer_amount' => 150000, 'catch_up_threshold_minutes' => 60,
            'hourly_rate_amount' => 15000, 'billing_cadence' => 'monthly', 'rollover_months' => 1,
        ]);
    }

    public function test_a_retainer_invoice_bills_catch_up_for_ordinary_work_and_the_months_expenses(): void
    {
        // 22 hours against 10 of January and 10 of February: 2 over, plus the
        // one-hour minimum availability.
        $this->entry('2026-01-12', 1320);
        $expense = $this->recordSyntheticExpense($this->workspace, $this->company, $this->project, $this->syntheticExpenseFacts(amount: 4_200, spentOn: '2026-01-20'));
        (new WorkspaceExpenses($this->workspace))->approve($expense, $this->manager);

        $february = $this->generate('2026-01');

        $catchUp = $february->lines->firstWhere('type', InvoiceLineType::AdditionalHours->value);
        $this->assertSame(3.0, (float) $catchUp?->hours);
        $this->assertSame(45000, (int) $catchUp?->total_amount);
        $expenseLine = $february->lines->firstWhere('type', InvoiceLineType::Expense->value);
        $this->assertSame(4200, (int) $expenseLine?->total_amount);
        $this->assertSame($expenseLine?->id, $expense->refresh()->client_invoice_line_id);
        $this->assertSame(150000 + 45000 + 4200, (int) $february->total_amount);
    }

    /**
     * Months of deferred work, absorbed only where a month left room, never
     * produce a catch-up charge - not directly, and not by leaving the next
     * month's ordinary work short of its pool.
     */
    public function test_deferred_work_never_reaches_a_catch_up_charge(): void
    {
        $service = app(ClientInvoicingService::class);
        // 9:45 of ordinary work a month against 10 hours: a quarter-hour spare.
        // A 10-hour deferred job waits; quarter-hour ones fit as room appears.
        $this->entry('2026-01-20', 600, deferred: true);
        foreach (['2026-01', '2026-02', '2026-03', '2026-04'] as $month) {
            $this->entry($month.'-05', 585);
            $this->entry($month.'-15', 15, deferred: true);
        }

        $billed = 0.0;
        foreach (['2026-01', '2026-02', '2026-03', '2026-04'] as $month) {
            $invoice = $this->generate($month);
            $billed += (float) $invoice->hours_billed_at_rate;
            $invoice->forceFill(['status' => 'issued'])->save();
            foreach ($invoice->lines->where('type', InvoiceLineType::AdditionalHours->value) as $line) {
                $this->assertSame(0, $line->timeEntries()->where('is_deferred', true)->count());
            }
        }

        $this->assertSame(0.0, $billed, 'Ordinary work fits the retainer every month, so nothing is owed');
        $this->assertGreaterThan(0, ClientTimeEntry::query()->where('is_deferred', true)->whereHas('invoiceLines')->count(), 'Spare hours absorbed some of the backlog');
        $this->assertGreaterThan(0, ClientTimeEntry::query()->where('is_deferred', true)->whereDoesntHave('invoiceLines')->count(), 'The rest is carried, not charged');
    }

    private function generate(string $workMonth): ClientInvoice
    {
        $start = Carbon::parse($workMonth.'-01');

        return app(ClientInvoicingService::class)->generateInvoice(
            $this->company, $start, $start->copy()->endOfMonth()->startOfDay(), $this->agreement,
        );
    }

    private function entry(string $workedOn, int $minutes, bool $deferred = false): ClientTimeEntry
    {
        return ClientTimeEntry::query()->create([
            'workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id,
            'client_project_id' => $this->project->id, 'user_id' => $this->manager->id,
            'worked_on' => $workedOn, 'minutes' => $minutes, 'description' => 'Work',
            'is_billable' => true, 'is_deferred' => $deferred, 'status' => 'approved',
            'billing_rate_amount' => 15000, 'currency' => 'USD',
        ]);
    }
}
