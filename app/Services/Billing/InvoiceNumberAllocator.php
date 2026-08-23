<?php

namespace App\Services\Billing;

use App\Models\ClientInvoice;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

final class InvoiceNumberAllocator
{
    public function next(Workspace $workspace): string
    {
        return DB::transaction(function () use ($workspace): string {
            $last = ClientInvoice::query()->where('workspace_id', $workspace->id)->lockForUpdate()->latest('id')->value('invoice_number');
            $number = is_string($last) && preg_match('/^SVC-(\d+)$/', $last, $matches) === 1 ? ((int) $matches[1]) + 1 : 1;

            return 'SVC-'.str_pad((string) $number, 5, '0', STR_PAD_LEFT);
        });
    }
}
