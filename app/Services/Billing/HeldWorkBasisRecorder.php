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

    /** @return list<array{int, bool, string|null, bool}> */
    private function rows(ClientInvoice $invoice, ?int $projectId, string $through, bool $locking): array
    {
        $query = ClientTimeEntry::query()
            ->withTrashed()
            ->where('workspace_id', $invoice->workspace_id)
            ->where('client_company_id', $invoice->client_company_id)
            ->when($projectId !== null, fn ($scoped) => $scoped->where('client_project_id', $projectId))
            ->whereDate('worked_on', '<=', $through)
            ->where(fn ($touched) => $touched
                ->whereNotNull('client_disputed_at')
                ->orWhereNotNull('client_dispute_settlement'))
            ->orderBy('id')
            ->select(['id', 'client_disputed_at', 'client_dispute_settlement', 'deleted_at']);
        if ($locking) {
            $query->tap(Locks::forUpdate());
        }

        return array_values($query->get()->map(fn (ClientTimeEntry $entry): array => [
            $entry->id,
            $entry->getAttributes()['client_disputed_at'] !== null,
            is_string($settlement = $entry->getAttributes()['client_dispute_settlement']) ? $settlement : null,
            $entry->getAttributes()['deleted_at'] !== null,
        ])->all());
    }
}
