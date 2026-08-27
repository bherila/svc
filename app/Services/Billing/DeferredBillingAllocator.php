<?php

namespace App\Services\Billing;

use App\Models\ClientCompany;
use App\Models\ClientTimeEntry;
use App\Services\Billing\Balances\DeferredAllocationResult;
use App\Services\Billing\Balances\DeferredEntryCandidate;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Allocates deferred-billing time entries onto an invoice.
 *
 * Semantics (see docs/client-management/deferred-billing.md):
 * - Entries are never split.
 * - Only entries that fit wholly in the remaining retainer capacity are billed.
 * - Entries that don't fit stay unlinked and roll to a future invoice.
 * - On agreement termination, all outstanding deferred entries are force-billed
 *   at the hourly rate via {@see collectForTermination()}.
 */
class DeferredBillingAllocator
{
    /**
     * Select deferred entries that fit in the remaining retainer capacity.
     *
     * @param  ClientCompany  $company  The client company being invoiced.
     * @param  Carbon  $upTo  Only consider entries dated on or before this.
     * @param  float  $remainingCapacityHours  Remaining retainer hours after
     *                                         the regular {@see TimeEntrySplitter}
     *                                         has run. May be 0 or negative; in
     *                                         that case nothing is billed.
     */
    public function allocate(
        ClientCompany $company,
        Carbon $upTo,
        float $remainingCapacityHours,
    ): DeferredAllocationResult {
        $candidates = $this->loadCandidates($company, $upTo);
        if ($candidates->isEmpty()) {
            return DeferredAllocationResult::empty();
        }

        $billed = [];
        $skipped = [];
        $hoursBilled = 0.0;
        $remaining = max(0.0, $remainingCapacityHours);

        foreach ($candidates as $candidate) {
            if ($candidate->hours <= $remaining + 0.00001) {
                $billed[] = $candidate;
                $hoursBilled += $candidate->hours;
                $remaining -= $candidate->hours;
            } else {
                $skipped[] = $this->summarise($candidate);
            }
        }

        return new DeferredAllocationResult(
            billed: $billed,
            skipped: $skipped,
            hoursBilled: round($hoursBilled, 4),
        );
    }

    /**
     * All outstanding unbilled deferred entries for a company. Used when
     * generating the final (post-termination) invoice so the client is
     * never left with unbilled deferred work.
     *
     * @return Collection<int, ClientTimeEntry>
     */
    public function collectForTermination(ClientCompany $company, ?Carbon $upTo = null): Collection
    {
        $query = ClientTimeEntry::query()
            ->where('workspace_id', $company->workspace_id)
            ->where('client_company_id', $company->id)
            ->where('is_billable', true)
            ->where('is_deferred', true)
            ->retainerBillable()
            ->whereDoesntHave('invoiceLines')
            ->orderBy('worked_on', 'asc')
            ->orderBy('id', 'asc');

        if ($upTo !== null) {
            $query->where('worked_on', '<=', $upTo);
        }

        return $query->get();
    }

    /**
     * Ordered candidates (FIFO by date_worked, id) for this period.
     *
     * @return Collection<int, DeferredEntryCandidate>
     */
    protected function loadCandidates(ClientCompany $company, Carbon $upTo): Collection
    {
        return ClientTimeEntry::query()
            ->where('workspace_id', $company->workspace_id)
            ->where('client_company_id', $company->id)
            ->where('is_billable', true)
            ->where('is_deferred', true)
            ->retainerBillable()
            ->whereDoesntHave('invoiceLines')
            ->where('worked_on', '<=', $upTo)
            ->orderBy('worked_on', 'asc')
            ->orderBy('id', 'asc')
            ->get()
            ->map(fn (ClientTimeEntry $entry) => DeferredEntryCandidate::fromEntry($entry));
    }

    /**
     * Minimal serializable summary for UI "deferred to future invoice" lists.
     *
     * @return array{id: int, hours: float, date_worked: string, name: string|null}
     */
    protected function summarise(DeferredEntryCandidate $candidate): array
    {
        $entry = $candidate->entry;

        return [
            'id' => (int) $entry->id,
            'hours' => $candidate->hours,
            'date_worked' => $entry->date_worked->format('Y-m-d'),
            'name' => $entry->name,
        ];
    }
}
