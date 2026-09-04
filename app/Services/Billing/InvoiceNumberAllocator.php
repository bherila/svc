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
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Invoice numbers must be allocated inside the invoice creation transaction.');
        }

        Workspace::query()->whereKey($workspace->id)->tap(Locks::forUpdate())->firstOrFail();
        $counter = WorkspaceInvoiceCounter::query()->whereKey($workspace->id)->tap(Locks::forUpdate())->first();
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
}
