<?php

namespace App\Services\Billing;

use App\Exceptions\DisputedWorkChanged;
use App\Models\ClientAgreement;
use App\Models\ClientInvoice;
use App\Support\Billing\HeldWorkBasis;
use App\Support\Billing\SubcontractorBillingMode;
use App\Support\Concurrency\Locks;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Records what a generated cadence draft was priced from, as far as disputes
 * go, and tells the issue whether that has moved since (docs/ux-overhaul.md
 * 7b″); the issue refuses with {@see DisputedWorkChanged}.
 *
 * The rows read are the company's time in the agreement's project scope that
 * the retainer ledger reads, from the beginning through the last day the
 * draft's ledger read, that a client has disputed or the provider has settled
 * - deleted rows included, so a deletion shows too - with what holds each.
 * Every other row's place in the ledger is not a dispute's to move.
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

    /**
     * Renew a locked draft's basis after a change of its own allocations, as a
     * buy-down attached to it makes, from a current, locking read: the state
     * {@see isCurrent()} just validated, with those rows still locked, plus the
     * change. An ordinary read would return the transaction's snapshot, which
     * can predate that state - a dispute since answered - and write it back as
     * the basis, so that a later dispute matches it again.
     *
     * A draft with no basis this version can read is left without one.
     */
    public function renewCurrent(ClientInvoice $locked): void
    {
        $stored = HeldWorkBasis::fromStored($locked->held_work_basis);
        if (! $stored instanceof HeldWorkBasis) {
            return;
        }
        $current = HeldWorkBasis::of($stored->projectId, $stored->through, $this->rows($locked, $stored->projectId, $stored->through, true));
        $locked->forceFill(['held_work_basis' => $current->toStored()])->save();
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
     * Each row's dispute state and what holds it: the type and placement of
     * every invoice line it is allocated to. The allocation is what makes a
     * disputed row charged rather than held, and its type and placement are
     * what it does to capacity - a retainer draw in a month, a buy-down that
     * draws none, a termination charge - so a void that releases it, and a
     * later allocation of another kind, each change the basis, and the
     * second does not change it back. Placement is the line's date, or its
     * invoice when the line has none; both survive a regeneration, which
     * recreates the line on the same invoice for the same day.
     *
     * And whether it waits as deferred work, which takes no capacity until a
     * line takes it: a late answer moves only that.
     *
     * Only work the retainer ledger reads: flat-hourly and direct time is
     * priced alone, and its allocation moves no pool.
     *
     * The allocations are read first, starting from the lines, which rank
     * before time entries; then the rows themselves. Both locking when asked.
     *
     * @return list<array{int, bool, string|null, bool, string, bool}>
     */
    private function rows(ClientInvoice $invoice, ?int $projectId, string $through, bool $locking): array
    {
        $allocations = $this->allocations($invoice, $projectId, $through, $locking);
        $query = DB::table('client_time_entries')
            ->tap(self::scope($invoice, $projectId, $through))
            ->orderBy('client_time_entries.id')
            ->select([
                'client_time_entries.id', 'client_time_entries.client_disputed_at',
                'client_time_entries.client_dispute_settlement', 'client_time_entries.deleted_at',
                'client_time_entries.is_deferred',
            ]);
        if ($locking) {
            $query->tap(Locks::forUpdate());
        }

        $rows = [];
        foreach ($query->get() as $row) {
            $id = (int) $row->id;
            $held = $allocations[$id] ?? [];
            sort($held);
            $rows[] = [
                $id,
                $row->client_disputed_at !== null,
                is_string($row->client_dispute_settlement) ? $row->client_dispute_settlement : null,
                $row->deleted_at !== null,
                implode(',', $held),
                (bool) $row->is_deferred,
            ];
        }

        return $rows;
    }

    /** @return array<int, list<string>> each scoped row's allocations, as `type@placement` */
    private function allocations(ClientInvoice $invoice, ?int $projectId, string $through, bool $locking): array
    {
        $workspaceId = $invoice->workspace_id;
        $query = DB::table('client_invoice_lines')
            ->join('client_invoice_line_time_entries as allocation', function ($join) use ($workspaceId): void {
                $join->on('allocation.client_invoice_line_id', '=', 'client_invoice_lines.id')
                    ->where('allocation.workspace_id', '=', $workspaceId);
            })
            ->join('client_time_entries', 'client_time_entries.id', '=', 'allocation.client_time_entry_id')
            ->where('client_invoice_lines.workspace_id', $workspaceId)
            ->tap(self::scope($invoice, $projectId, $through))
            ->orderBy('client_invoice_lines.id')
            ->select([
                'client_time_entries.id as entry_id', 'client_invoice_lines.type',
                'client_invoice_lines.line_date', 'client_invoice_lines.client_invoice_id',
            ]);
        if ($locking) {
            $query->tap(Locks::forUpdate());
        }

        $allocations = [];
        foreach ($query->get() as $line) {
            $placement = is_string($line->line_date) && $line->line_date !== ''
                ? substr($line->line_date, 0, 10)
                : 'invoice:'.(int) $line->client_invoice_id;
            $allocations[(int) $line->entry_id][] = (is_string($line->type) ? $line->type : '').'@'.$placement;
        }

        return $allocations;
    }

    /**
     * The scope's disputed or settled time the retainer ledger reads, through
     * the day, deleted rows included.
     *
     * @return \Closure(Builder): void
     */
    private static function scope(ClientInvoice $invoice, ?int $projectId, string $through): \Closure
    {
        return static function ($query) use ($invoice, $projectId, $through): void {
            $query->where('client_time_entries.workspace_id', $invoice->workspace_id)
                ->where('client_time_entries.client_company_id', $invoice->client_company_id)
                ->when($projectId !== null, fn ($scoped) => $scoped->where('client_time_entries.client_project_id', $projectId))
                ->whereDate('client_time_entries.worked_on', '<=', $through)
                ->where(fn ($touched) => $touched
                    ->whereNotNull('client_time_entries.client_disputed_at')
                    ->orWhereNotNull('client_time_entries.client_dispute_settlement'))
                ->where(fn ($ledger) => $ledger
                    ->whereNull('client_time_entries.subcontractor_billing_mode')
                    ->orWhere('client_time_entries.subcontractor_billing_mode', SubcontractorBillingMode::Retainer->value));
        };
    }
}
