<?php

namespace App\Services\Billing;

use App\Models\ClientAgreement;
use App\Models\ClientInvoice;
use App\Support\Billing\CarriedDeferredLine;
use App\Support\Billing\InvoiceKind;
use App\Support\Billing\InvoiceStatus;
use App\Support\Billing\PricingBasis;
use App\Support\Billing\PricingBasisChanged;
use App\Support\Billing\PricingBasisMissing;
use App\Support\Billing\SubcontractorBillingMode;
use App\Support\Concurrency\Locks;
use Carbon\Carbon;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Records what a generated cadence draft was priced from, and tells the issue
 * whether any of it has moved since (#718, review finding B4).
 *
 * A cadence draft's figures come from its agreement's ledger: the scope's time
 * through the last day the ledger reads, where that time was placed (the line
 * and invoice that absorbed deferred work), the agreement's other live
 * invoices (the overage they charged, the periods they cover) and their
 * re-carried deferred lines. The held-work basis covers disputed rows only;
 * this covers every input, so a draft whose ledger has moved since it was
 * priced - an hour approved, deleted or redated, an allocation released, an
 * earlier invoice voided - is refused at issue and regenerated, rather than
 * charging the ledger as it was. The draft's own lines are guarded separately
 * (`assertSnapshotHoldsTheLockedLines`, and every attach checks the row it
 * links), so they are left out: of the agreement's invoices, its carried
 * lines, and the allocations - time added to the draft, or a buy-down
 * attached to it, does not move its basis.
 *
 * Columns that cannot change money - descriptions, who approved, versions -
 * are left out, so an edit to them does not refuse the draft; nor does an
 * invoice's status within the live set (being paid, or an earlier draft being
 * issued, which its catch-up basis already accounts for).
 *
 * At issue every input is read with share locks at the time phase. The
 * agreement's invoices and their lines rank before time, but every writer of
 * them - generation, issue, void, discard - holds the agreement's lock, which
 * the issue of a draft with a basis takes first; so nothing that could hold
 * one of those rows is waiting on this transaction.
 */
final class PricingBasisRecorder
{
    /** At generation, after the draft's lines are composed: the transaction's own view, its writes included. */
    public function record(ClientInvoice $invoice, ClientAgreement $agreement): void
    {
        $through = self::through($invoice);
        $upTo = $through === null ? 0 : self::int(DB::table('client_time_entries')
            ->tap(self::scope($invoice, $agreement->client_project_id, $through, PHP_INT_MAX))
            ->max('client_time_entries.id')) ?? 0;
        $invoicesUpTo = self::int(DB::table('client_invoices')
            ->where('workspace_id', $invoice->workspace_id)
            ->where('client_company_id', $invoice->client_company_id)
            ->where('client_agreement_id', $agreement->id)
            ->max('id')) ?? 0;
        $basis = $through === null ? null : $this->basis($invoice, $agreement, $through, $upTo, $invoicesUpTo, false);
        $invoice->forceFill(['pricing_basis' => $basis?->toStored()])->save();
    }

    /**
     * Is this a draft whose figures come from the ledger? The cadence engine
     * records a basis on every draft it prices - this one, and the held-work
     * basis before it - so a draft with neither was not priced from the
     * ledger: a billing schedule's template, or one written by hand. A
     * reissue is governed by its own charge checks.
     */
    public function appliesTo(ClientInvoice $locked): bool
    {
        return $locked->status === InvoiceStatus::Draft->value
            && $locked->reopened_at === null
            && $locked->client_agreement_id !== null
            && in_array($locked->invoiceKindValue(), [InvoiceKind::CadencePeriod->value, InvoiceKind::Terminal->value], true)
            && ($locked->pricing_basis !== null || $locked->held_work_basis !== null);
    }

    /**
     * At issue, after the draft and before the company: refuse a draft whose
     * recorded inputs have moved, or which recorded none. Reads with share
     * locks, so the inputs compared are the current rows whatever snapshot the
     * caller's transaction holds, and they stay as compared until the issue
     * commits. The invoice and line reads come first; call it where invoice
     * rows may still be locked (see `issue()`).
     *
     * @param  Collection<int, ClientAgreement>  $segments  the client's agreements, which the issue locked first
     */
    public function assertCurrent(ClientInvoice $locked, ClientAgreement $agreement, Collection $segments): void
    {
        $stored = PricingBasis::fromStored($locked->pricing_basis);
        if (! $stored instanceof PricingBasis) {
            throw new PricingBasisMissing;
        }
        if (! $stored->matches($this->basis($locked, $agreement, $stored->through, $stored->upTo, $stored->invoicesUpTo, true, $segments))) {
            throw new PricingBasisChanged;
        }
    }

    /** @param  Collection<int, ClientAgreement>|null  $segments */
    private function basis(ClientInvoice $invoice, ClientAgreement $agreement, string $through, int $upTo, int $invoicesUpTo, bool $locking, ?Collection $segments = null): PricingBasis
    {
        $invoices = $this->invoices($invoice, $agreement, $through, $invoicesUpTo, $locking);

        return PricingBasis::of($agreement->client_project_id, $through, $upTo, $invoicesUpTo, [
            'invoices' => array_values(array_filter($invoices)),
            'carried' => $this->carried($invoice, $agreement, array_keys($invoices), $locking),
            'time' => $this->time($invoice, $agreement, self::workThrough($agreement, $through, $segments), $upTo, $locking),
        ]);
    }

    /**
     * The last day of work this agreement bills: a successor in the same
     * scope takes over from the day it starts, so later work is the
     * successor's to bill, as generation hands it over, and the successor's
     * invoices placing it move nothing here.
     *
     * @param  Collection<int, ClientAgreement>|null  $segments  the client's agreements, locked, at issue
     */
    private static function workThrough(ClientAgreement $agreement, string $through, ?Collection $segments): string
    {
        $handover = app(DeferredBacklogReader::class)->handedOverAfter((int) $agreement->workspace_id, $agreement, $segments)?->toDateString();

        return $handover !== null && $handover < $through ? $handover : $through;
    }

    /**
     * The work period's end: what a cadence draft reconciles. The cycle it
     * sells is a fixed retainer, so time logged in that cycle prices the next
     * draft, not this one. A draft with no work period reads through its cycle.
     */
    private static function through(ClientInvoice $invoice): ?string
    {
        $end = $invoice->service_period_end ?? $invoice->cycle_end;

        return $end === null ? null : Carbon::instance($end)->toDateString();
    }

    /**
     * The agreement's other live invoices that recorded overage: what the
     * billed-overage ledger reads - the hours and the month they settle. An
     * invoice that charged no overage (a setup fee, an ad-hoc charge) moves
     * nothing, nor does a payment or an earlier draft's issue; a void does.
     * Every live invoice's id is returned too, keyed, for the carried lines.
     *
     * @return array<int, list<scalar|null>|null>
     */
    private function invoices(ClientInvoice $invoice, ClientAgreement $agreement, string $through, int $upTo, bool $locking): array
    {
        // Only the agreement's own invoices are named, so the scan takes the
        // agreement's index and its locks reach no other invoice: one whose
        // writer holds no agreement lock - a hand-edited ad-hoc draft - and
        // inserts lines that check its row (IssueTimeLockOrderTest). A range
        // on the id would scan the primary key instead, so the draft and the
        // later invoices are left out here.
        $query = DB::table('client_invoices')
            ->where('workspace_id', $invoice->workspace_id)
            ->where('client_agreement_id', $agreement->id)
            ->orderBy('id')
            ->select(['id', 'client_company_id', 'status', 'service_period_end', 'hours_billed_at_rate']);
        $this->lock($query, $locking);

        $live = [];
        foreach ($query->get() as $row) {
            $id = (int) $row->id;
            // An invoice created after this draft was priced is later than it.
            if ($id === $invoice->id || $id > $upTo || (int) $row->client_company_id !== $invoice->client_company_id
                || ! in_array(self::text($row->status), InvoiceStatus::live(), true)) {
                continue;
            }
            $hours = self::hours($row->hours_billed_at_rate);
            $end = self::day($row->service_period_end);
            // The ledger reads overage settled through its own end; a later
            // invoice's is the next draft's.
            $live[$id] = $hours === null || (float) $hours === 0.0 || ($end !== null && $end > $through)
                ? null
                : [$id, self::day($row->service_period_end), $hours];
        }

        return $live;
    }

    /**
     * Re-carried deferred work the agreement's other live invoices applied or
     * billed: the ledger books these hours where their lines are dated.
     *
     * @param  list<int>  $liveInvoiceIds
     * @return list<list<scalar|null>>
     */
    private function carried(ClientInvoice $invoice, ClientAgreement $agreement, array $liveInvoiceIds, bool $locking): array
    {
        $query = DB::table('client_invoice_lines')
            ->where('workspace_id', $invoice->workspace_id)
            ->where('client_agreement_id', $agreement->id)
            ->whereIn('type', [CarriedDeferredLine::Applied->lineType()->value, CarriedDeferredLine::BilledOnTermination->lineType()->value])
            ->where('client_invoice_id', '!=', $invoice->id)
            ->orderBy('id')
            ->select(['id', 'client_invoice_id', 'type', 'hours', 'line_date']);
        $this->lock($query, $locking);

        $rows = [];
        foreach ($query->get() as $row) {
            if (in_array((int) $row->client_invoice_id, $liveInvoiceIds, true)) {
                $rows[] = [(int) $row->id, (int) $row->client_invoice_id, self::text($row->type), self::hours($row->hours), self::day($row->line_date)];
            }
        }

        return $rows;
    }

    /**
     * The scope's time through the day, deleted rows included, with every
     * column its pricing reads and the type and placement of each line it is
     * allocated to. Direct subcontractor time is never billed here. The
     * allocations are read first, starting from the lines, which rank before
     * time entries; then the rows.
     *
     * @return list<list<scalar|null>>
     */
    private function time(ClientInvoice $invoice, ClientAgreement $agreement, string $through, int $upTo, bool $locking): array
    {
        $workspaceId = $invoice->workspace_id;
        if ($locking) {
            BillingMaintenance::share($workspaceId);
        }
        $scope = self::scope($invoice, $agreement->client_project_id, $through, $upTo);
        $allocations = DB::table('client_invoice_lines')
            ->join('client_invoice_line_time_entries as allocation', function ($join) use ($workspaceId): void {
                $join->on('allocation.client_invoice_line_id', '=', 'client_invoice_lines.id')
                    ->where('allocation.workspace_id', '=', $workspaceId);
            })
            ->join('client_time_entries', 'client_time_entries.id', '=', 'allocation.client_time_entry_id')
            ->where('client_invoice_lines.workspace_id', $workspaceId)
            // The draft's own allocations are its charges, which its line
            // checks and every attach guard; leaving them out lets time added
            // to the draft, or a buy-down attached to it, stand.
            ->where('client_invoice_lines.client_invoice_id', '!=', $invoice->id)
            ->tap($scope)
            ->orderBy('client_invoice_lines.id')
            ->select(['client_time_entries.id as entry_id', 'client_invoice_lines.type', 'client_invoice_lines.line_date', 'client_invoice_lines.client_invoice_id']);
        // Exclusive, though only read: the join reaches the time rows the
        // read below locks, and a share lock here would have to be upgraded
        // there - which two issues holding it each wait on for ever.
        if ($locking) {
            $allocations->tap(Locks::forUpdate());
        }
        $held = [];
        foreach ($allocations->get() as $line) {
            $day = self::day($line->line_date);
            // Placed after the ledger's end, an allocation draws on a later
            // pool: the ledger leaves it out, as it leaves out work not yet
            // allocated, so a later invoice taking it moves nothing here.
            if ($day !== null && $day > $through) {
                continue;
            }
            $held[(int) $line->entry_id][] = self::text($line->type).'@'.($day ?? 'invoice:'.(int) $line->client_invoice_id);
        }

        $entries = DB::table('client_time_entries')
            ->tap($scope)
            ->orderBy('client_time_entries.id')
            ->select([
                'client_time_entries.id', 'client_time_entries.worked_on', 'client_time_entries.minutes', 'client_time_entries.status',
                'client_time_entries.is_billable', 'client_time_entries.is_deferred', 'client_time_entries.split_from_time_entry_id',
                'client_time_entries.subcontractor_billing_mode', 'client_time_entries.billing_rate_amount', 'client_time_entries.currency',
                'client_time_entries.client_project_id', 'client_time_entries.client_task_id', 'client_time_entries.deleted_at',
                'client_time_entries.client_disputed_at', 'client_time_entries.client_dispute_settlement',
                'client_time_entries.subcontractor_cost_amount',
            ]);
        if ($locking) {
            $entries->tap(Locks::forUpdate());
        }
        $rows = [];
        foreach ($entries->get() as $row) {
            $id = (int) $row->id;
            $lines = $held[$id] ?? [];
            sort($lines);
            $rows[] = [
                // The ledger counts approved and invoiced work alike: an issue
                // that marks its time invoiced moves no other draft's figures.
                $id, self::day($row->worked_on), self::int($row->minutes), self::countedStatus($row->status), (bool) $row->is_billable,
                (bool) $row->is_deferred, self::int($row->split_from_time_entry_id), self::text($row->subcontractor_billing_mode),
                self::int($row->billing_rate_amount), self::text($row->currency), self::int($row->client_project_id), self::int($row->client_task_id),
                $row->deleted_at !== null, $row->client_disputed_at !== null, self::text($row->client_dispute_settlement),
                $row->subcontractor_cost_amount !== null, implode(',', $lines),
            ];
        }

        return $rows;
    }

    /**
     * The scope's billable-here time through the day, deleted rows included,
     * as far as the draft was priced from: rows logged since are later work,
     * which a later invoice bills, and do not make this draft's charges wrong.
     *
     * @return Closure(Builder): void
     */
    private static function scope(ClientInvoice $invoice, ?int $projectId, string $through, int $upTo): Closure
    {
        return static function ($query) use ($invoice, $projectId, $through, $upTo): void {
            $query->where('client_time_entries.workspace_id', $invoice->workspace_id)
                ->where('client_time_entries.id', '<=', $upTo)
                ->where('client_time_entries.client_company_id', $invoice->client_company_id)
                ->when($projectId !== null, fn ($scoped) => $scoped->where('client_time_entries.client_project_id', $projectId))
                ->whereDate('client_time_entries.worked_on', '<=', $through)
                ->where(fn ($billed) => $billed
                    ->whereNull('client_time_entries.subcontractor_billing_mode')
                    ->orWhere('client_time_entries.subcontractor_billing_mode', '!=', SubcontractorBillingMode::Direct->value));
        };
    }

    private function lock(Builder $query, bool $locking): void
    {
        if ($locking) {
            $query->tap(Locks::forShare());
        }
    }

    private static function countedStatus(mixed $value): ?string
    {
        $status = self::text($value);

        return in_array($status, ['approved', 'invoiced'], true) ? 'approved' : $status;
    }

    private static function text(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }

    private static function int(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private static function day(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? substr($value, 0, 10) : null;
    }

    private static function hours(mixed $value): ?string
    {
        return is_numeric($value) ? number_format((float) $value, 4, '.', '') : null;
    }
}
