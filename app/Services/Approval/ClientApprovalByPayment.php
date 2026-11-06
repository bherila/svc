<?php

namespace App\Services\Approval;

use App\Models\ClientExpense;
use App\Models\ClientInvoice;
use App\Models\ClientTimeEntry;
use App\Support\Approval\ClientApprovalSource;
use App\Support\Approval\ClientApprovalVerdict;
use App\Support\Billing\InvoiceStatus;
use App\Support\Concurrency\Locks;
use App\Support\WorkspaceClock;
use Illuminate\Support\Facades\DB;

/**
 * Paying an invoice in full approves the hours and expenses on it
 * (docs/ux-overhaul.md sections 7b and 7b').
 *
 * Which records: the time entries linked to the invoice's lines through the
 * workspace's own pivot rows, and the expenses whose line is one of them.
 * Nothing else the client owes, and nothing from another invoice.
 *
 * When: once the invoice is paid in full, read again under its lock after the
 * payment has committed - never from the payment's own transaction, which may
 * still roll back, and never from a queue, which nothing here runs. Callers
 * hand it to `DB::afterCommit()`.
 *
 * What it writes is the source `payment` and a time, and no person: the
 * payment is the client's act, nobody is named as having approved, so there is
 * no author who could be their own approver. It also leaves two kinds of row
 * alone: one a person has already decided on in the portal (their word stands,
 * approved or disputed), and one authored by the person who recorded the
 * payment, who is not allowed to approve their own work by any route.
 *
 * Whether the approval still holds is read later, from the invoice's status
 * ({@see ClientApprovalVerdict}): a refund or a reopen
 * ends it without anything writing.
 */
final class ClientApprovalByPayment
{
    public function __construct(private readonly WorkspaceClock $clock = new WorkspaceClock) {}

    /**
     * @return array{time: int, expenses: int}
     */
    public function approve(int $workspaceId, int $invoiceId, ?int $recordedByUserId = null): array
    {
        return DB::transaction(function () use ($workspaceId, $invoiceId, $recordedByUserId): array {
            $invoice = ClientInvoice::query()
                ->where('workspace_id', $workspaceId)
                ->whereKey($invoiceId)
                ->tap(Locks::forUpdate())
                ->first();
            // Read again, after commit and under the lock: this runs on what
            // is true now, not on what the payment expected.
            if (! $invoice instanceof ClientInvoice || $invoice->status !== InvoiceStatus::Paid->value) {
                return ['time' => 0, 'expenses' => 0];
            }

            $now = $this->clock->now($invoice->workspace);
            $lineIds = $invoice->lines()->where('workspace_id', $workspaceId)->pluck('id');
            if ($lineIds->isEmpty()) {
                return ['time' => 0, 'expenses' => 0];
            }

            $entryIds = DB::table('client_invoice_line_time_entries')
                ->where('workspace_id', $workspaceId)
                ->whereIn('client_invoice_line_id', $lineIds)
                ->pluck('client_time_entry_id');

            $stamp = [
                'client_approved_at' => $now,
                'client_approval_source' => ClientApprovalSource::Payment->value,
                'client_approved_by_user_id' => null,
                'lock_version' => DB::raw('lock_version + 1'),
            ];

            $time = ClientTimeEntry::query()
                ->where('workspace_id', $workspaceId)
                ->whereIn('id', $entryIds)
                ->whereNull('client_disputed_at')
                ->where(fn ($open) => $open->whereNull('client_approved_at')
                    ->orWhere('client_approval_source', ClientApprovalSource::Payment->value))
                ->when($recordedByUserId !== null, fn ($rows) => $rows->where('user_id', '!=', $recordedByUserId))
                ->update($stamp);

            $expenses = ClientExpense::query()
                ->where('workspace_id', $workspaceId)
                ->whereIn('client_invoice_line_id', $lineIds)
                ->whereNull('client_disputed_at')
                ->where(fn ($open) => $open->whereNull('client_approved_at')
                    ->orWhere('client_approval_source', ClientApprovalSource::Payment->value))
                // An expense whose creator is unknown has no author to protect.
                ->when($recordedByUserId !== null, fn ($rows) => $rows->where(fn ($by) => $by
                    ->whereNull('created_by_user_id')->orWhere('created_by_user_id', '!=', $recordedByUserId)))
                ->update($stamp);

            return ['time' => $time, 'expenses' => $expenses];
        });
    }
}
