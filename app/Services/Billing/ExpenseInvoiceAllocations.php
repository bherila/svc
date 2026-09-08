<?php

namespace App\Services\Billing;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientExpense;
use App\Models\ClientInvoice;
use App\Models\ClientInvoiceLine;
use App\Models\ClientProject;
use App\Support\Billing\InvoiceKind;
use App\Support\Billing\InvoiceLineType;
use App\Support\Concurrency\Locks;
use App\Support\Expenses\ExpenseInvoiceTerms;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/** Tenant-scoped claim/release boundary, called inside invoice transactions. */
final class ExpenseInvoiceAllocations
{
    /** @return Builder<ClientExpense> */
    public function eligible(ClientCompany $company, ?ClientAgreement $agreement, string $through, string $currency): Builder
    {
        if ($agreement !== null && ($agreement->workspace_id !== $company->workspace_id || $agreement->client_company_id !== $company->id)) {
            throw new DomainException('The expense invoice agreement has inconsistent ownership.');
        }

        $projectId = $agreement?->client_project_id;

        return ClientExpense::query()->where('workspace_id', $company->workspace_id)
            ->where('client_company_id', $company->id)->where('status', 'approved')
            ->whereNull('client_invoice_line_id')->where('currency', $currency)
            ->whereDate('spent_on', '<=', $through)
            ->when($projectId !== null, fn (Builder $query): Builder => $query->where('client_project_id', $projectId))
            ->where(fn (Builder $query): Builder => $query->whereNull('client_project_id')
                ->orWhereIn('client_project_id', ClientProject::query()->where('workspace_id', $company->workspace_id)->where('client_company_id', $company->id)->select('id')));
    }

    /**
     * Run after time/task composition, before credit and totals. The caller
     * already owns a locked or newly inserted invoice in this transaction.
     * Retaining old expense lines until here avoids expense→time lock order.
     */
    public function rebuild(ClientInvoice $invoice, string $through): void
    {
        $this->requireTransaction();
        if ($invoice->status !== 'draft' || $invoice->invoice_kind !== InvoiceKind::CadencePeriod->value) {
            throw new DomainException('Expenses are automatically claimed only by a cadence draft.');
        }
        $company = ClientCompany::query()->where('workspace_id', $invoice->workspace_id)->whereKey($invoice->client_company_id)->firstOrFail();
        $agreement = null;
        if ($invoice->client_agreement_id !== null) {
            $agreement = ClientAgreement::query()->where('workspace_id', $invoice->workspace_id)
                ->where('client_company_id', $company->id)->whereKey($invoice->client_agreement_id)->firstOrFail();
        }
        $lines = ClientInvoiceLine::query()->where('workspace_id', $invoice->workspace_id)->where('client_invoice_id', $invoice->id)->get()->keyBy('id');
        $candidates = ClientExpense::withTrashed()->where('workspace_id', $invoice->workspace_id)
            ->where(fn (Builder $query): Builder => $query->whereIn('client_invoice_line_id', $lines->modelKeys())
                ->orWhereIn('id', $this->eligible($company, $agreement, $through, $invoice->currency)->select('id')));
        if (! (clone $candidates)->exists()) {
            return;
        }
        // Old claims and new candidates share one ascending acquisition order.
        // Releasing a high-id claim before locking a lower-id new candidate
        // would otherwise invert two regenerations of overlapping agreements.
        $locked = $candidates->orderBy('id')->tap(Locks::forUpdate())->get();
        $releasedLines = $this->releaseClaims($invoice, $lines, $locked->filter(fn (ClientExpense $expense): bool => $expense->client_invoice_line_id !== null));
        ClientInvoiceLine::query()->where('workspace_id', $invoice->workspace_id)
            ->where('client_invoice_id', $invoice->id)->whereKey($releasedLines)->delete();
        $sort = 0;
        foreach ($lines as $line) {
            if (! in_array($line->id, $releasedLines, true)) {
                $sort = max($sort, $line->sort_order + 1);
            }
        }
        // All these rows are already locked; re-read eligibility after release.
        $expenses = $this->eligible($company, $agreement, $through, $invoice->currency)->whereKey($locked->modelKeys())->orderBy('id')->get();
        foreach ($expenses as $expense) {
            $terms = ExpenseInvoiceTerms::from($expense);
            $line = ClientInvoiceLine::query()->create([
                'workspace_id' => $invoice->workspace_id, 'client_invoice_id' => $invoice->id,
                'client_agreement_id' => $invoice->client_agreement_id, 'client_project_id' => $expense->client_project_id,
                'description' => $expense->description, 'quantity' => '1', 'unit_amount' => $terms->amount,
                'total_amount' => $terms->amount, 'tax_amount' => 0, 'type' => InvoiceLineType::Expense->value,
                'line_date' => $expense->spent_on, 'sort_order' => $sort++,
            ]);
            $claimed = ClientExpense::query()->where('workspace_id', $invoice->workspace_id)->whereKey($expense->id)
                ->where('status', 'approved')->whereNull('client_invoice_line_id')
                ->update(['client_invoice_line_id' => $line->id, 'status' => 'invoiced', 'lock_version' => DB::raw('lock_version + 1')]);
            if ($claimed !== 1) {
                throw new DomainException('The expense changed while its invoice was being prepared.');
            }
        }
    }

    /**
     * Release only this invoice's claims; callers decide whether lines remain.
     *
     * @return list<int>
     */
    public function release(ClientInvoice $invoice): array
    {
        $this->requireTransaction();
        $lines = ClientInvoiceLine::query()->where('workspace_id', $invoice->workspace_id)->where('client_invoice_id', $invoice->id)->get()->keyBy('id');
        $claims = ClientExpense::withTrashed()->where('workspace_id', $invoice->workspace_id)->whereIn('client_invoice_line_id', $lines->modelKeys());
        if (! (clone $claims)->exists()) {
            return [];
        }

        return $this->releaseClaims($invoice, $lines, $claims->orderBy('id')->tap(Locks::forUpdate())->get());
    }

    /** @param Collection<int, ClientInvoiceLine> $lines
     * @param Collection<int, ClientExpense> $expenses
     * @return list<int> */
    private function releaseClaims(ClientInvoice $invoice, Collection $lines, Collection $expenses): array
    {
        $released = [];
        foreach ($expenses as $expense) {
            $line = $lines->get($expense->client_invoice_line_id);
            if ($expense->client_company_id !== $invoice->client_company_id || $expense->status !== 'invoiced'
                || $expense->trashed() || ! $line instanceof ClientInvoiceLine || $line->type !== InvoiceLineType::Expense->value) {
                throw new DomainException('The invoice has an inconsistent expense allocation.');
            }
            $changed = ClientExpense::query()->where('workspace_id', $invoice->workspace_id)->whereKey($expense->id)
                ->where('client_invoice_line_id', $line->id)->where('status', 'invoiced')
                ->update(['client_invoice_line_id' => null, 'status' => 'approved', 'lock_version' => DB::raw('lock_version + 1')]);
            if ($changed !== 1) {
                throw new DomainException('The expense allocation changed while it was being released.');
            }
            $released[] = $line->id;
        }

        return $released;
    }

    public function assertReplaceable(ClientInvoice $invoice): void
    {
        if (ClientExpense::withTrashed()->where('workspace_id', $invoice->workspace_id)
            ->whereIn('client_invoice_line_id', ClientInvoiceLine::query()->where('workspace_id', $invoice->workspace_id)->where('client_invoice_id', $invoice->id)->select('id'))->exists()) {
            throw new DomainException('This draft contains claimed expenses. Regenerate or discard it before replacing its lines.');
        }
    }

    private function requireTransaction(): void
    {
        if (DB::transactionLevel() < 1) {
            throw new DomainException('Expense allocations require an invoice transaction.');
        }
    }
}
