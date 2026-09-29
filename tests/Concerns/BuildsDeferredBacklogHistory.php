<?php

namespace Tests\Concerns;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientInvoiceLine;
use App\Models\ClientProject;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\MoneyService;
use App\Support\Billing\InvoiceKind;
use App\Support\Billing\InvoiceLineType;
use Carbon\Carbon;

/**
 * A synthetic monthly retainer with nine paid months and a deferred backlog.
 *
 * The shape mirrors an imported history that exposed several generator
 * defects at once, with every identifier invented:
 *
 * - 10 retainer hours a month at $375/h, one month of rollover, from January;
 * - a paid cadence invoice for every retainer month January-September, each
 *   numbered `ATLA-YYYYMM-001` by its issue month;
 * - one catch-up charge (505 minutes of April work plus the one-hour minimum
 *   availability buffer, 9.4167 hours) on the May invoice;
 * - from June on, every paid invoice carries a zero-value "Deferred work items
 *   applied to retainer (10:00)" line linking 600 minutes of deferred work
 *   performed in earlier months;
 * - 2,950 minutes of deferred work (April-September) and 690 minutes of
 *   ordinary September work on no invoice at all.
 *
 * Minutes per worked month, ordinary + already-applied deferred, are chosen so
 * the capacity ledger reads exactly what the imported history did:
 * 14.25, 12, 11.58, 27.83, 14.25, 16.08, 2.67, 0 and 11.5 hours.
 */
trait BuildsDeferredBacklogHistory
{
    protected Workspace $backlogWorkspace;

    protected ClientCompany $backlogCompany;

    protected ClientProject $backlogProject;

    protected ClientAgreement $backlogAgreement;

    protected User $backlogUser;

    /** @var array<string, ClientInvoice> paid invoices keyed by retainer month `Y-m` */
    protected array $backlogInvoices = [];

    protected function buildDeferredBacklogHistory(string $slug = 'atlas-imaging'): void
    {
        $this->backlogWorkspace = Workspace::query()->create(['name' => 'Backlog '.$slug, 'slug' => 'backlog-'.$slug]);
        $this->backlogCompany = ClientCompany::query()->create([
            'workspace_id' => $this->backlogWorkspace->id,
            'name' => 'Atlas Imaging',
            'slug' => $slug,
        ]);
        $this->backlogProject = ClientProject::query()->create([
            'workspace_id' => $this->backlogWorkspace->id,
            'client_company_id' => $this->backlogCompany->id,
            'name' => 'Imaging platform',
        ]);
        $this->backlogUser = User::factory()->create();
        $this->backlogAgreement = ClientAgreement::query()->create([
            'workspace_id' => $this->backlogWorkspace->id,
            'client_company_id' => $this->backlogCompany->id,
            'title' => 'Monthly retainer',
            'status' => 'active',
            'currency' => 'USD',
            'starts_on' => '2026-01-01',
            'retainer_minutes' => 600,
            'retainer_amount' => 375000,
            // Unset on purpose: the default one-hour minimum availability.
            'catch_up_threshold_minutes' => null,
            'hourly_rate_amount' => 37500,
            'billing_cadence' => 'monthly',
            'rollover_months' => 1,
        ]);

        // Ordinary work, billed on the invoice issued the month after it.
        $ordinary = [
            '2026-01' => [855], '2026-02' => [720], '2026-03' => [695],
            '2026-05' => [15],
        ];
        foreach (['2026-02' => '2026-01', '2026-03' => '2026-02', '2026-04' => '2026-03', '2026-06' => '2026-05'] as $retainerMonth => $workMonth) {
            $invoice = $this->paidCadenceInvoice($retainerMonth, 0.0);
            $entries = array_map(fn (int $minutes): ClientTimeEntry => $this->backlogEntry($workMonth.'-10', $minutes), $ordinary[$workMonth]);
            $this->workLine($invoice, 'Work items applied to retainer', $workMonth, $entries);
        }

        // April: 730 minutes absorbed, 505 billed as catch-up with the buffer.
        $may = $this->paidCadenceInvoice('2026-05', 9.4167);
        $this->workLine($may, 'Work items applied to retainer', '2026-04', [$this->backlogEntry('2026-04-10', 730)]);
        $catchUpEntry = $this->backlogEntry('2026-04-20', 505);
        $catchUp = $this->line($may, InvoiceLineType::AdditionalHours, 'Catch-up hours for prior month overage and minimum availability', 9.4167, '2026-04-01', MoneyService::hourlyAmount(565, 37500), 37500);
        $catchUp->timeEntries()->attach($catchUpEntry->id, ['workspace_id' => $this->backlogWorkspace->id]);

        foreach (['2026-01', '2026-07', '2026-08', '2026-09'] as $retainerMonth) {
            $this->paidCadenceInvoice($retainerMonth, 0.0);
        }

        // Deferred work the paid June-September invoices already applied.
        $applied = [
            '2026-06' => [['2026-04-15', 435], ['2026-05-12', 165]],
            '2026-07' => [['2026-05-14', 300], ['2026-05-18', 300]],
            '2026-08' => [['2026-05-22', 75], ['2026-06-11', 525]],
            '2026-09' => [['2026-06-16', 440], ['2026-07-08', 160]],
        ];
        foreach ($applied as $retainerMonth => $rows) {
            $invoice = $this->backlogInvoices[$retainerMonth];
            $line = $this->line(
                $invoice,
                InvoiceLineType::PriorMonthRetainer,
                'Deferred work items applied to retainer (10:00)',
                10.0,
                $invoice->service_period_end?->toDateString() ?? '',
                0,
                0,
            );
            foreach ($rows as [$workedOn, $minutes]) {
                $line->timeEntries()->attach(
                    $this->backlogEntry($workedOn, $minutes, deferred: true)->id,
                    ['workspace_id' => $this->backlogWorkspace->id],
                );
            }
        }

        // Nothing below is on any invoice.
        $unbilledDeferred = [
            '2026-04-24' => [120, 120, 60], '2026-05-26' => [120, 120, 60], '2026-06-23' => [120, 120, 120, 40],
            '2026-07-14' => [120, 120, 120, 120, 120, 120, 120, 60], '2026-08-12' => [120, 120, 120, 120, 120, 84],
            '2026-09-09' => [120, 120, 120, 6],
        ];
        foreach ($unbilledDeferred as $workedOn => $minutesList) {
            foreach ($minutesList as $minutes) {
                $this->backlogEntry($workedOn, $minutes, deferred: true);
            }
        }
        $this->backlogEntry('2026-09-15', 690);
    }

    private function paidCadenceInvoice(string $retainerMonth, float $billedOverageHours): ClientInvoice
    {
        $cycleStart = Carbon::parse($retainerMonth.'-01');
        $workStart = $cycleStart->copy()->subMonth();
        $invoice = ClientInvoice::query()->create([
            'workspace_id' => $this->backlogWorkspace->id,
            'client_company_id' => $this->backlogCompany->id,
            'client_agreement_id' => $this->backlogAgreement->id,
            'invoice_number' => 'ATLA-'.$cycleStart->format('Ym').'-001',
            'status' => 'paid',
            'invoice_kind' => InvoiceKind::CadencePeriod->value,
            'issue_date' => $cycleStart->toDateString(),
            'due_date' => $cycleStart->toDateString(),
            'service_period_start' => $workStart->toDateString(),
            'service_period_end' => $workStart->copy()->endOfMonth()->toDateString(),
            'cycle_start' => $cycleStart->toDateString(),
            'cycle_end' => $cycleStart->copy()->endOfMonth()->toDateString(),
            'currency' => 'USD',
            'subtotal_amount' => 0,
            'tax_amount' => 0,
            'total_amount' => 0,
            'paid_amount' => 0,
            'balance_amount' => 0,
            'hours_billed_at_rate' => $billedOverageHours,
        ]);
        $this->line($invoice, InvoiceLineType::Retainer, 'Monthly Retainer (10:00 hours)', 10.0, $cycleStart->toDateString(), 375000, 375000);
        $total = (int) ClientInvoiceLine::query()->where('client_invoice_id', $invoice->id)->sum('total_amount');
        $invoice->forceFill(['subtotal_amount' => $total, 'total_amount' => $total, 'paid_amount' => $total])->save();

        return $this->backlogInvoices[$retainerMonth] = $invoice;
    }

    /**
     * @param  list<ClientTimeEntry>  $entries
     */
    private function workLine(ClientInvoice $invoice, string $description, string $workMonth, array $entries): void
    {
        $minutes = array_sum(array_map(fn (ClientTimeEntry $entry): int => (int) $entry->minutes, $entries));
        $line = $this->line(
            $invoice,
            InvoiceLineType::PriorMonthRetainer,
            $description,
            round($minutes / 60, 4),
            Carbon::parse($workMonth.'-01')->endOfMonth()->toDateString(),
            0,
            0,
        );
        foreach ($entries as $entry) {
            $line->timeEntries()->attach($entry->id, ['workspace_id' => $this->backlogWorkspace->id]);
        }
    }

    private function line(
        ClientInvoice $invoice,
        InvoiceLineType $type,
        string $description,
        float $hours,
        string $lineDate,
        int $total,
        int $unit,
    ): ClientInvoiceLine {
        $line = ClientInvoiceLine::query()->create([
            'workspace_id' => $invoice->workspace_id,
            'client_invoice_id' => $invoice->id,
            'client_agreement_id' => $invoice->client_agreement_id,
            'type' => $type->value,
            'description' => $description,
            'quantity' => $type === InvoiceLineType::AdditionalHours ? (string) $hours : '1',
            'unit_amount' => $unit,
            'tax_amount' => 0,
            'total_amount' => $total,
            'hours' => $hours,
            'line_date' => $lineDate,
            'sort_order' => 1 + ClientInvoiceLine::query()->where('client_invoice_id', $invoice->id)->count(),
        ]);
        if ($total !== 0) {
            $sum = (int) ClientInvoiceLine::query()->where('client_invoice_id', $invoice->id)->sum('total_amount');
            $invoice->forceFill(['subtotal_amount' => $sum, 'total_amount' => $sum, 'paid_amount' => $sum])->save();
        }

        return $line;
    }

    private function backlogEntry(string $workedOn, int $minutes, bool $deferred = false): ClientTimeEntry
    {
        return ClientTimeEntry::query()->create([
            'workspace_id' => $this->backlogWorkspace->id,
            'client_company_id' => $this->backlogCompany->id,
            'client_project_id' => $this->backlogProject->id,
            'user_id' => $this->backlogUser->id,
            'worked_on' => $workedOn,
            'minutes' => $minutes,
            'description' => 'Synthetic work',
            'is_billable' => true,
            'is_deferred' => $deferred,
            'status' => 'approved',
            'billing_rate_amount' => 37500,
            'currency' => 'USD',
        ]);
    }
}
