<?php

namespace App\Services\Billing;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientTimeEntry;
use App\Support\Approval\ConsultantTimeRelease;
use App\Support\WorkspaceClock;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Pulls the consultant's unbilled time onto the draft being generated
 * (docs/ux-overhaul.md section 7b, "Invoiced").
 *
 * Nobody approves their own time, so the draft cannot wait for an approval
 * that no one is allowed to give. Generation instead takes every unbilled,
 * billable entry the consultant logged up to the workspace's today, and moves
 * it to `approved` - the status every ledger, allocator and generator already
 * reads - with no approver and no approval time. An `approved` entry with
 * nobody named is therefore the system's, not a person's, and the client
 * approves afterwards in the portal or by paying.
 *
 * The rate is stamped as approval always stamped it, from the agreement in
 * force on the day worked. It is only informational for cadence invoices,
 * which price from the agreement, and an entry the agreement cannot price
 * keeps no rate rather than failing the draft. A rate the person stated when
 * they logged the time is kept.
 *
 * Which entries qualify is {@see ConsultantTimeRelease}; this class only finds
 * the candidates under the workspace and company, locks them, and writes.
 * Call it inside the generating transaction, before anything reads the ledger.
 * It takes no row locks (see the write below), so it is safe to call before
 * the numbering rows are locked.
 */
final class ConsultantTimeReleaser
{
    public function __construct(
        private readonly AgreementBillingRateResolver $rates = new AgreementBillingRateResolver,
        private readonly WorkspaceClock $clock = new WorkspaceClock,
    ) {}

    /** @return int how many entries were released */
    public function release(ClientCompany $company, ClientAgreement $agreement): int
    {
        $workspace = $company->workspace ?? throw new DomainException('The client company has no workspace.');
        $today = $this->clock->today($workspace)->toDateString();

        $candidates = ClientTimeEntry::query()
            ->where('workspace_id', $company->workspace_id)
            ->where('client_company_id', $company->id)
            ->where('status', 'draft')
            ->forAgreementScope($agreement)
            ->where('worked_on', '<=', $this->clock->today($workspace)->toDateString())
            ->withExists('invoiceLines as on_an_invoice_line')
            ->orderBy('id')
            ->get();

        $released = 0;
        foreach ($candidates as $entry) {
            if (! ConsultantTimeRelease::releases(
                $entry->status,
                $entry->is_billable,
                $entry->getRawOriginal('subcontractor_billing_mode'),
                $entry->subcontractor_cost_amount !== null,
                $entry->worked_on->toDateString(),
                $today,
                (bool) $entry->getAttribute('on_an_invoice_line'),
            )) {
                continue;
            }

            // A compare-and-set rather than a read-modify-write under a lock:
            // generation takes the numbering rows before it touches time, and
            // locking entries here, ahead of them, would walk that order
            // backwards. The status in the WHERE is what makes it safe - an
            // entry someone else moved meanwhile is simply not written.
            $released += ClientTimeEntry::query()
                ->where('workspace_id', $company->workspace_id)
                ->whereKey($entry->id)
                ->where('status', 'draft')
                ->update(['status' => 'approved', 'lock_version' => DB::raw('lock_version + 1')] + $this->rateFor($entry));
        }

        return $released;
    }

    /** @return array<string, mixed> */
    private function rateFor(ClientTimeEntry $entry): array
    {
        if ($entry->billing_rate_source === 'explicit' && $entry->billing_rate_amount !== null) {
            return [];
        }

        try {
            $rate = $this->rates->resolve($entry);
        } catch (DomainException) {
            return [];
        }

        return [
            'billing_rate_amount' => $rate['amount'],
            'currency' => $rate['currency'],
            'billing_rate_source' => 'agreement',
        ];
    }
}
