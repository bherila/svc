<?php

namespace App\Services\Billing;

use App\Exceptions\DisputedWorkChanged;
use App\Models\ClientAgreement;
use App\Models\ClientInvoice;
use App\Models\ClientTimeEntry;
use App\Support\Billing\HeldWorkBasis;
use App\Support\Concurrency\Locks;
use Illuminate\Support\Carbon;

/**
 * Records what a generated cadence draft was priced from, as far as disputes
 * go, and tells the issue whether that has moved since (docs/ux-overhaul.md
 * 7b″); the issue refuses with {@see DisputedWorkChanged}.
 *
 * The rows read are the company's time in the agreement's project scope, from
 * the beginning through the last day the draft's ledger read, that a client has
 * disputed or the provider has settled - deleted rows included, so a deletion
 * shows too. Every other row's place in the ledger is not a dispute's to move.
 */
final class HeldWorkBasisRecorder
{
    /** At generation, after the draft's lines are composed. */
    public function record(ClientInvoice $invoice, ClientAgreement $agreement): void
    {
        $through = self::through($invoice);
        $basis = $through === null ? null : HeldWorkBasis::of(
            $agreement->client_project_id,
            $through,
            $this->rows($invoice, $agreement->client_project_id, $through, false),
        );
        $invoice->forceFill(['held_work_basis' => $basis?->toStored()])->save();
    }

    /**
     * At issue, on the locked draft: a locking read, so the state compared is
     * the current one whatever snapshot the caller's transaction holds, and
     * the rows stay locked until the issue commits. Time entries rank after
     * the invoice's lines and before the company.
     *
     * A draft with no basis this version can read - generated before it
     * existed, or not a cadence draft - is issued as before.
     */
    public function isCurrent(ClientInvoice $locked): bool
    {
        $stored = HeldWorkBasis::fromStored($locked->held_work_basis);
        if (! $stored instanceof HeldWorkBasis) {
            return true;
        }

        return $stored->matches(HeldWorkBasis::of($stored->projectId, $stored->through, $this->rows($locked, $stored->projectId, $stored->through, true)));
    }

    /** The later of the work period's end and the sold cycle's: the ledger reads through both. */
    private static function through(ClientInvoice $invoice): ?string
    {
        $ends = array_values(array_filter([$invoice->service_period_end, $invoice->cycle_end]));
        if ($ends === []) {
            return null;
        }

        return Carbon::instance(max($ends))->toDateString();
    }

    /**
     * Each row's dispute state and whether an invoice line holds it. The
     * allocation is what makes a disputed row charged rather than held: a
     * void releases it, and the row leaves the ledger without its dispute
     * columns moving. Joined rather than read through a subquery, so a locking
     * read locks, and reads current, the allocation rows too; they rank after
     * the time entries.
     *
     * @return list<array{int, bool, string|null, bool, bool}>
     */
    private function rows(ClientInvoice $invoice, ?int $projectId, string $through, bool $locking): array
    {
        $workspaceId = $invoice->workspace_id;
        $query = ClientTimeEntry::query()
            ->withTrashed()
            ->leftJoin('client_invoice_line_time_entries as allocation', function ($join) use ($workspaceId): void {
                $join->on('allocation.client_time_entry_id', '=', 'client_time_entries.id')
                    ->where('allocation.workspace_id', '=', $workspaceId);
            })
            ->where('client_time_entries.workspace_id', $workspaceId)
            ->where('client_time_entries.client_company_id', $invoice->client_company_id)
            ->when($projectId !== null, fn ($scoped) => $scoped->where('client_time_entries.client_project_id', $projectId))
            ->whereDate('client_time_entries.worked_on', '<=', $through)
            ->where(fn ($touched) => $touched
                ->whereNotNull('client_time_entries.client_disputed_at')
                ->orWhereNotNull('client_time_entries.client_dispute_settlement'))
            ->orderBy('client_time_entries.id')
            ->select([
                'client_time_entries.id', 'client_time_entries.client_disputed_at', 'client_time_entries.client_dispute_settlement',
                'client_time_entries.deleted_at', 'allocation.client_time_entry_id as allocated_entry_id',
            ]);
        if ($locking) {
            $query->tap(Locks::forUpdate());
        }

        $rows = [];
        foreach ($query->get() as $entry) {
            $attributes = $entry->getAttributes();
            $id = $entry->id;
            $allocated = ($attributes['allocated_entry_id'] ?? null) !== null;
            if (isset($rows[$id])) {
                $rows[$id][4] = $rows[$id][4] || $allocated;

                continue;
            }
            $rows[$id] = [
                $id,
                $attributes['client_disputed_at'] !== null,
                is_string($settlement = $attributes['client_dispute_settlement']) ? $settlement : null,
                $attributes['deleted_at'] !== null,
                $allocated,
            ];
        }

        return array_values($rows);
    }
}
