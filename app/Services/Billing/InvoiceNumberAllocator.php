<?php

namespace App\Services\Billing;

use App\Models\ClientInvoice;
use App\Models\Workspace;
use App\Models\WorkspaceInvoiceCounter;
use App\Support\Concurrency\Locks;
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
