<?php

namespace App\Services\Accounting;

use App\Models\AccountingInvoiceEntry;
use App\Models\ClientInvoice;
use App\Models\Workspace;
use App\Support\Accounting\AccountingQuery;
use App\Support\Concurrency\Locks;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final readonly class AccountingReadRepository
{
    public function __construct(private InvoiceAccountingJournal $journal) {}

    /**
     * Existing issued documents are projected synchronously, one locked invoice
     * at a time. Future issue/correction/void operations append in their own transaction.
     */
    public function importExisting(Workspace $workspace, ?int $year = null): void
    {
        ClientInvoice::query()->where('workspace_id', $workspace->id)
            ->where(fn (Builder $rows) => $rows->whereIn('status', ['issued', 'paid', 'partially_paid'])->orWhereNotNull('issued_at')->orWhere('document_revision', '>', 0))
            ->when($year !== null, fn (Builder $rows) => $rows->whereBetween('issue_date', [CarbonImmutable::create($year, 1, 1)->toDateString(), CarbonImmutable::create($year, 12, 31)->toDateString()]))
            ->whereNotIn('id', AccountingInvoiceEntry::query()->where('workspace_id', $workspace->id)->select('client_invoice_id'))
            ->select('id')->orderBy('id')->chunkById(100, function ($invoices) use ($workspace): void {
                foreach ($invoices as $invoice) {
                    DB::transaction(function () use ($invoice, $workspace): void {
                        $locked = ClientInvoice::query()->where('workspace_id', $workspace->id)->whereKey($invoice->id)->tap(Locks::forUpdate())->firstOrFail();
                        $this->journal->ensure($locked);
                    });
                }
            });
    }

    /** @return Builder<AccountingInvoiceEntry> */
    public function lines(Workspace $workspace, AccountingQuery $query): Builder
    {
        return AccountingInvoiceEntry::query()->where('workspace_id', $workspace->id)
            ->when($query->from !== null, fn (Builder $rows) => $rows->where('effective_on', '>=', $query->from))
            ->when($query->to !== null, fn (Builder $rows) => $rows->where('effective_on', '<=', $query->to));
    }
}
