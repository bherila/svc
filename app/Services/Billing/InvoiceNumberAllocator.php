<?php

namespace App\Services\Billing;

use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\Workspace;
use App\Models\WorkspaceInvoiceCounter;
use App\Support\Concurrency\Locks;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use LogicException;

final class InvoiceNumberAllocator
{
    public function next(Workspace $workspace): string
    {
        $counter = $this->lockNumbering($workspace->id);

        if ($counter === null) {
            $highest = 0;
            foreach (ClientInvoice::query()->where('workspace_id', $workspace->id)->pluck('invoice_number') as $invoiceNumber) {
                if (is_string($invoiceNumber) && preg_match('/^SVC-(\d+)$/', $invoiceNumber, $matches) === 1) {
                    $highest = max($highest, (int) $matches[1]);
                }
            }
            $counter = WorkspaceInvoiceCounter::query()->create([
                'workspace_id' => $workspace->id,
                'next_number' => $highest + 1,
            ]);
        }
        $number = $counter->next_number;
        $counter->forceFill(['next_number' => $number + 1])->save();

        return 'SVC-'.str_pad((string) $number, 5, '0', STR_PAD_LEFT);
    }

    /**
     * `PREFIX-YYYYMM-NNN` for a cadence invoice, keyed to the month it sells.
     *
     * docs/client-management/cadence-billing.md#invoice-period states one rule
     * for every cadence: the number names the first month of the retainer
     * billed in advance, which is also the month the invoice is issued in. The
     * cadence generators used to take the workspace counter instead, so an
     * October invoice for a client whose every earlier invoice read
     * `XXXX-2026MM-001` arrived as `SVC-00001`.
     *
     * The prefix is the one this client's invoices already carry, so a renamed
     * company keeps its series; a client with none yet gets the first four
     * letters and digits of its name. The sequence is counted across the whole
     * workspace, because that is the scope of the `(workspace_id,
     * invoice_number)` unique index a duplicate would hit - two clients whose
     * names share a prefix take 001 and 002 of the same month.
     *
     * Taken under the same two locks as {@see next()}, so a concurrent cadence
     * run cannot read the same highest sequence.
     */
    public function forIssueMonth(ClientCompany $company, CarbonInterface $issueMonth): string
    {
        $this->lockNumbering((int) $company->workspace_id);

        $prefix = $this->prefixFor($company);
        $stem = ($prefix === '' ? '' : $prefix.'-').$issueMonth->format('Ym').'-';

        $highest = 0;
        $numbers = ClientInvoice::query()
            ->where('workspace_id', $company->workspace_id)
            ->where('invoice_number', 'like', $stem.'%')
            ->pluck('invoice_number');
        foreach ($numbers as $invoiceNumber) {
            if (is_string($invoiceNumber) && preg_match('/^'.preg_quote($stem, '/').'(\d+)$/', $invoiceNumber, $matches) === 1) {
                $highest = max($highest, (int) $matches[1]);
            }
        }

        return $stem.str_pad((string) ($highest + 1), 3, '0', STR_PAD_LEFT);
    }

    /**
     * The client's own series prefix, or one derived from its name.
     *
     * Read from this client's most recent issue-month number rather than
     * re-derived every time, so the series survives a rename.
     */
    private function prefixFor(ClientCompany $company): string
    {
        $existing = ClientInvoice::query()
            ->where('workspace_id', $company->workspace_id)
            ->where('client_company_id', $company->id)
            ->orderByDesc('id')
            ->pluck('invoice_number');
        foreach ($existing as $invoiceNumber) {
            if (is_string($invoiceNumber) && preg_match('/^([A-Z0-9]+)-\d{6}-\d{3,}$/', $invoiceNumber, $matches) === 1) {
                return $matches[1];
            }
        }

        $alphanumeric = preg_replace('/[^A-Za-z0-9]/', '', (string) $company->name) ?? '';

        return strtoupper(substr($alphanumeric, 0, 4));
    }

    /**
     * Take the rows a number is allocated from, without allocating one.
     *
     * `next()` is the only caller that needs the counter back; this exists
     * separately because a generator sometimes has to take these two rows
     * earlier than it needs a number. It takes the workspace *key* rather than
     * the model because that is all a lock needs, and because the callers that
     * have to reach it early hold a tenant-owned row whose `workspace_id` is a
     * plain non-null column, not a relation that may or may not be loaded.
     * Numbering sits at positions 7 and 8 of
     * the lock-order registry and `client_time_entries` at position 9, so a
     * path that touches time before it creates its invoice - the interim
     * overage generator recombines fragments first - has to reach the counter
     * before it reaches the time, or it walks the registry backwards against
     * every cadence path.
     *
     * The rows are held to the end of the transaction either way, so taking
     * them early costs the caller nothing it was not going to pay; what it
     * costs a *concurrent* caller is that numbering for the workspace is
     * serialised from this point rather than from the create, including on the
     * paths that then decide there is nothing to bill and allocate no number.
     * That is the price of one order for everybody, and it is a lock held for
     * the rest of one short transaction, not a number consumed.
     *
     * @return WorkspaceInvoiceCounter|null The locked counter row, or null
     *                                      when this workspace has never had a
     *                                      number allocated and the row does
     *                                      not exist yet. The workspace row is
     *                                      locked in both cases, which is what
     *                                      serialises the create below.
     */
    public function lockNumbering(int $workspaceId): ?WorkspaceInvoiceCounter
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Invoice numbers must be allocated inside the invoice creation transaction.');
        }

        Workspace::query()->whereKey($workspaceId)->tap(Locks::forUpdate())->firstOrFail();

        return WorkspaceInvoiceCounter::query()->whereKey($workspaceId)->tap(Locks::forUpdate())->first();
    }
}
