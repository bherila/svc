<?php

namespace App\Services\Accounting;

use App\Models\AccountingCashRow;
use App\Models\AccountingInvoiceEntry;
use App\Models\Workspace;
use App\Support\Accounting\AccountingQuery;
use App\Support\Accounting\YearlyIncome;
use App\Support\Finance\FinanceCaller;
use Illuminate\Support\Facades\Gate;

final readonly class AccountingReadService
{
    public function __construct(private AccountingReadRepository $invoices, private CashIncomeRepository $cash) {}

    /** @return array<string, mixed> */
    public function lines(FinanceCaller $caller, Workspace $workspace, AccountingQuery $query): array
    {
        Gate::forUser($caller->user)->authorize('manage', $workspace);
        $this->invoices->importExisting($workspace);

        return AccountingPage::run($workspace, 'finance.invoice_lines.list', $this->invoices->lines($workspace, $query), $query,
            static fn (AccountingInvoiceEntry $entry): array => ['id' => $entry->public_id, ...$entry->payload, 'updated_at' => $entry->updated_at?->toIso8601String()]);
    }

    /** @return array<string, mixed> */
    public function income(FinanceCaller $caller, Workspace $workspace, AccountingQuery $query): array
    {
        return AccountingPage::run($workspace, 'finance.cash_income.list', $this->cash->query($caller, $workspace, $query), $query, $this->cash->present(...));
    }

    public function yearly(FinanceCaller $caller, Workspace $workspace, int $year): YearlyIncome
    {
        Gate::forUser($caller->user)->authorize('manage', $workspace);
        $this->invoices->importExisting($workspace, $year);
        $query = AccountingQuery::from(['from' => $year.'-01-01', 'to' => $year.'-12-31']);
        $lines = $this->invoices->lines($workspace, $query)->lazyById(100)->map(static fn (AccountingInvoiceEntry $entry): array => $entry->payload);
        $cash = $this->cash->query($caller, $workspace, $query)->lazyById(100)->map(static fn (AccountingCashRow $row): array => [
            'effective_on' => $row->effective_on, 'kind' => $row->kind, 'amount' => $row->amount,
            'fee_amount' => $row->fee_amount, 'unattributed_refund_amount' => $row->unattributed_refund_amount]);

        return YearlyIncome::calculate($year, $lines, $cash);
    }
}
