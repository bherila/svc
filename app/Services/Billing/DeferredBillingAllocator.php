<?php

namespace App\Services\Billing;

use App\Models\ClientAgreement;
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
 * - On agreement termination, all outstanding invoiceable deferred entries are
 *   collected via {@see collectForTermination()}; the composer preserves their
 *   ordinary or flat-hourly pricing and excludes direct work.
 */
class DeferredBillingAllocator
{
    public function __construct(
        private readonly TimeEntryProjectChainGuard $projectChainGuard = new TimeEntryProjectChainGuard,
    ) {}

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
        ?ClientAgreement $agreement = null,
    ): DeferredAllocationResult {
        $candidates = $this->loadCandidates($company, $upTo, $agreement);
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
     * All outstanding unbilled deferred entries this agreement may invoice.
     * Used when generating the final (post-termination) invoice so invoiceable
     * work is not left behind. Direct work remains tracked but is the
     * subcontractor's responsibility to bill.
     *
     * @return Collection<int, ClientTimeEntry>
     */
    public function collectForTermination(
        ClientCompany $company,
        ?Carbon $upTo = null,
        ?ClientAgreement $agreement = null,
    ): Collection {
        $companyEntries = ClientTimeEntry::query()
            ->where('workspace_id', $company->workspace_id)
            ->where('client_company_id', $company->id)
            ->where('is_billable', true)
            ->where('is_deferred', true)
            ->whereDoesntHave('invoiceLines')
            ->when($upTo !== null, fn ($query) => $query->where('worked_on', '<=', $upTo));

        // Validate the complete deferred chain before applying an agreement's
        // project filter, so a malformed row cannot disappear from the check.
        $this->projectChainGuard->assertProjectChainsAgree($company, $companyEntries);

        $query = (clone $companyEntries)
            // Direct-mode work is tracked but is never ours to invoice. Flat
            // hourly and retainer-mode work are both collected, then composed
            // through their own rates by InvoiceLineComposer.
            ->billableForInvoicing()
            ->when(
                $agreement instanceof ClientAgreement,
                fn ($scoped) => $scoped->forAgreementScope($agreement),
            )
            ->orderBy('worked_on', 'asc')
            ->orderBy('id', 'asc');

        return $query->get();
    }

    /**
     * Ordered candidates (FIFO by date_worked, id) for this period.
     *
     * @return Collection<int, DeferredEntryCandidate>
     */
    protected function loadCandidates(
        ClientCompany $company,
        Carbon $upTo,
        ?ClientAgreement $agreement = null,
    ): Collection {
        $companyEntries = ClientTimeEntry::query()
            ->where('workspace_id', $company->workspace_id)
            ->where('client_company_id', $company->id)
            ->where('is_billable', true)
            ->where('is_deferred', true)
            ->whereDoesntHave('invoiceLines')
            ->where('worked_on', '<=', $upTo);

        // Validate before narrowing to this agreement, so a malformed project
        // chain cannot disappear behind either the mode or project predicate.
        $this->projectChainGuard->assertProjectChainsAgree($company, $companyEntries);

        $query = (clone $companyEntries)
            ->retainerBillable()
            ->when(
                $agreement instanceof ClientAgreement,
                fn ($scoped) => $scoped->forAgreementScope($agreement),
            )
            ->orderBy('worked_on', 'asc')
            ->orderBy('id', 'asc');

        return $query->get()
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
