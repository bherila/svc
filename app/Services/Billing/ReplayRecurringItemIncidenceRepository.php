<?php

namespace App\Services\Billing;

use App\Models\ClientAgreement;
use App\Models\Workspace;
use App\Support\Billing\InvoiceKind;
use App\Support\Billing\ReplayRecurringItemIncidence;
use App\Support\Billing\ReplaySnapshotValue;
use Carbon\Carbon;

/**
 * Loads recurring-item replay contracts through one tenant-scoped boundary.
 *
 * The returned DTOs let the classifier prove every invoice without issuing a
 * query of its own. Agreement and item counts therefore do not change the
 * replay's query count.
 */
final readonly class ReplayRecurringItemIncidenceRepository
{
    public function __construct(private RecurringItemBiller $biller) {}

    /**
     * @param  array<string, array<string, mixed>>  $snapshots
     * @return array<string, list<ReplayRecurringItemIncidence>>
     */
    public function forSnapshots(Workspace $workspace, array $snapshots): array
    {
        $candidates = [];
        foreach ($snapshots as $key => $snapshot) {
            [$companyId, $agreementId, $kind] = array_pad(explode('|', $key, 4), 4, '');
            $cycleStart = ReplaySnapshotValue::text($snapshot['cycle_start'] ?? null);
            $cycleEnd = ReplaySnapshotValue::text($snapshot['cycle_end'] ?? null);
            if (! ctype_digit($companyId)
                || ! ctype_digit($agreementId)
                || $kind !== InvoiceKind::CadencePeriod->value
                || $cycleStart === ''
                || $cycleEnd === ''
                || $cycleStart === '?'
                || $cycleEnd === '?') {
                continue;
            }

            $candidates[$key] = [
                'company_id' => (int) $companyId,
                'agreement_id' => (int) $agreementId,
                'cycle_start' => $cycleStart,
                'cycle_end' => $cycleEnd,
            ];
        }

        if ($candidates === []) {
            return [];
        }

        $agreements = ClientAgreement::query()
            ->where('workspace_id', $workspace->id)
            ->whereIn('id', array_values(array_unique(array_column($candidates, 'agreement_id'))))
            ->with([
                'recurringItems' => fn ($items) => $items->where('workspace_id', $workspace->id),
            ])
            ->get()
            ->keyBy('id');

        $contexts = [];
        foreach ($candidates as $key => $candidate) {
            $agreement = $agreements->get($candidate['agreement_id']);
            if (! $agreement instanceof ClientAgreement
                || (int) $agreement->client_company_id !== $candidate['company_id']) {
                continue;
            }

            $cycleStart = Carbon::parse($candidate['cycle_start'])->startOfDay();
            $cycleEnd = Carbon::parse($candidate['cycle_end'])->startOfDay();
            if ($cycleEnd->lt($cycleStart)) {
                continue;
            }

            foreach ($this->biller->linesForCycle($agreement, $cycleStart, $cycleEnd) as $lineData) {
                $line = $this->biller->buildLine($lineData);
                $item = $lineData['item'];
                $itemStart = Carbon::instance($item->start_date ?? $agreement->starts_on ?? $cycleStart)
                    ->startOfDay();
                $contexts[$key][] = new ReplayRecurringItemIncidence(
                    companyId: $candidate['company_id'],
                    agreementId: (int) $agreement->id,
                    itemId: (int) $item->id,
                    currency: (string) $item->currency,
                    taxable: (bool) $item->is_taxable,
                    opensItem: $lineData['line_date']->isSameDay($itemStart),
                    lineDate: $lineData['line_date']->toDateString(),
                    unitAmount: (int) $line->unit_amount,
                    quantity: (string) $line->quantity,
                    taxAmount: (int) $line->tax_amount,
                    totalAmount: (int) $line->total_amount,
                );
            }
        }

        return $contexts;
    }
}
