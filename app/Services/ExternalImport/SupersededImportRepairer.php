<?php

namespace App\Services\ExternalImport;

use App\Models\Workspace;
use App\Support\ExternalImport\SupersededImportCounts;
use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use UnexpectedValueException;

/**
 * Retire the rows an earlier import took from source rows the predecessor had
 * already deleted.
 *
 * ## What went wrong
 *
 * The predecessor soft-deletes. The importer, before #87, read every source row
 * regardless of `deleted_at` - so a destination that looked complete by row
 * count was carrying the predecessor's whole history of superseded drafts as
 * live records. In the production import of 2026-08-16 that is 49 of 78
 * invoices and 764 of 822 invoice lines.
 *
 * The visible symptom is an invoice whose header total is right and whose lines
 * are nonsense: every superseded revision of every line is present, so the lines
 * sum to a multiple of the invoice. The header is right because it was imported
 * from the source's own total, which is why this survived a byte-for-byte
 * money comparison - that comparison asked whether the amounts matched, and
 * they did. It never asked whether the rows should exist.
 *
 * ## Why this is a repair and not a re-import
 *
 * The importer is fixed. Re-importing would also be correct, and it is the
 * wider blast radius: it rewrites rows that are right today, and every row it
 * rewrites is a row whose correctness has to be re-established. This deletes
 * exactly the rows that should never have been created and touches nothing
 * else, which is a claim that can be checked by counting.
 *
 * ## What it refuses to touch
 *
 * A superseded invoice carrying a payment, because a payment against a
 * superseded draft means either the payment is real - so the invoice is not
 * superseded - or the import went wrong a second way. Both need a person.
 *
 * It does *not* claim to detect a row an operator has edited since the import.
 * The ledger backfill already rewrote `updated_at` on every imported invoice and
 * line, so no such signal survives to read, and a check that cannot fire is
 * worse than none - it reads as protection. The real protection is the snapshot
 * the command takes before writing.
 *
 * ## The check that makes it verifiable
 *
 * After the repair every surviving invoice's lines must sum to its own
 * subtotal. That is reported before and after, and it is the number that says
 * whether the superseded set was the whole story.
 */
final class SupersededImportRepairer
{
    public function __construct(private readonly SourceGuard $guard) {}

    /**
     * The source is an allowlisted one, already resolved by
     * {@see SourceGuard::resolve()} - which is where the read-only declaration
     * is enforced. Taken rather than resolved here so this service cannot be
     * handed a connection the guard would have refused.
     *
     * @param  array{key: string, connection: string, config: array<string, mixed>, identity: array<string, string>, identity_hash: string, declared_restore_of: string|null}  $source
     */
    public function repair(Workspace $workspace, array $source, bool $apply = false): SupersededImportCounts
    {
        $connection = $this->guard->connection($source);

        $supersededInvoices = $this->supersededTargets($connection, 'client_invoices', 'client_invoice_id');
        $supersededLines = $this->supersededTargets($connection, 'client_invoice_lines', 'client_invoice_line_id');

        // Money first, and in SQL. A superseded invoice carrying a payment
        // leaves this repair entirely - and its lines leave with it, because
        // retiring the lines of an invoice we decline to retire would corrupt
        // the very invoice we spared.
        $spared = $this->ids($this->supersededInvoices($workspace, $supersededInvoices)
            ->whereExists($this->aPayment()));
        $invoiceIds = $this->ids($this->supersededInvoices($workspace, $supersededInvoices)
            ->whereNotExists($this->aPayment()));

        // A line goes if its own source row was deleted, or if the invoice it
        // hangs off is going - and stays if it belongs to an invoice we spared,
        // whatever the source says about the line itself.
        $lineIds = $this->ids(DB::table('client_invoice_lines')
            ->where('workspace_id', $workspace->id)
            ->where(fn (Builder $line): Builder => $line
                ->whereIn('public_id', $supersededLines)
                ->orWhereIn('client_invoice_id', $invoiceIds))
            ->whereNotIn('client_invoice_id', $spared));

        $retiredInvoices = 0;
        $retiredLines = 0;

        if ($apply) {
            [$retiredInvoices, $retiredLines] = $this->retire($workspace, $invoiceIds, $lineIds);
        }

        return new SupersededImportCounts(
            eligibleInvoices: count($invoiceIds),
            eligibleLines: count($lineIds),
            retiredInvoices: $retiredInvoices,
            retiredLines: $retiredLines,
            skippedWithAPayment: count($spared),
            survivorsNotReconciling: $this->survivorsNotReconciling(
                $workspace,
                $apply ? [] : $invoiceIds,
                $apply ? [] : $lineIds,
            ),
            applied: $apply,
        );
    }

    /**
     * This workspace's invoices that came from a deleted source row.
     *
     * @param  list<string>  $supersededPublicIds
     */
    private function supersededInvoices(Workspace $workspace, array $supersededPublicIds): Builder
    {
        // Workspace-scoped even though the public ids came from this
        // workspace's own import ledger: a repair that deletes rows is the last
        // place to take a tenant boundary on trust.
        return DB::table('client_invoices')
            ->where('workspace_id', $workspace->id)
            ->whereIn('public_id', $supersededPublicIds);
    }

    /** Correlated to the invoice row being considered, so it reads per invoice. */
    private function aPayment(): Closure
    {
        return fn (Builder $payment): Builder => $payment
            ->select(DB::raw('1'))
            ->from('client_invoice_payments')
            ->whereColumn('client_invoice_payments.client_invoice_id', 'client_invoices.id')
            ->whereColumn('client_invoice_payments.workspace_id', 'client_invoices.workspace_id');
    }

    /**
     * The source keys this import took from rows the predecessor had deleted.
     *
     * Read from the source rather than inferred from the destination, because
     * "should this row exist" is a question only the source can answer. The
     * destination cannot tell a superseded revision from a current one - that
     * is precisely the information the broken import discarded.
     *
     * @return list<string>
     */
    private function supersededTargets(ConnectionInterface $connection, string $table, string $key): array
    {
        $deleted = [];

        foreach ($connection->table($table)->whereNotNull('deleted_at')->pluck($key) as $id) {
            $deleted[] = $this->asKey($id);
        }

        if ($deleted === []) {
            return [];
        }

        $targets = [];

        foreach (DB::table('external_import_items')
            ->where('source_table', $table)
            ->whereIn('source_key', $deleted)
            ->whereNotNull('target_public_id')
            ->pluck('target_public_id') as $publicId) {
            $targets[] = $this->asKey($publicId);
        }

        return $targets;
    }

    /**
     * Row ids, as ids rather than as `mixed`.
     *
     * One place converts, so a row id that is not a number is refused here
     * rather than becoming a silent zero inside a `whereIn` - which on a delete
     * means matching nothing, or matching something else.
     *
     * The numeric conversion is defensive against a driver handing back
     * numeric strings; no driver in the suite does, so no test can distinguish
     * it from the raw value and its mutants are equivalent here.
     *
     * @infection-ignore-all
     *
     * @return list<int>
     */
    private function ids(Builder $query): array
    {
        $ids = [];

        foreach ($query->pluck('id') as $id) {
            if (! is_numeric($id)) {
                throw new UnexpectedValueException('A row id that is not a number cannot identify a row to delete.');
            }

            $ids[] = (int) $id;
        }

        return $ids;
    }

    /**
     * A source or public key, refused rather than coerced when it is neither.
     *
     * Same reasoning as {@see self::ids()}: the string conversion exists for a
     * driver that returns integer keys, and the suite's drivers make the two
     * branches indistinguishable.
     *
     * @infection-ignore-all
     */
    private function asKey(mixed $value): string
    {
        if (! is_string($value) && ! is_int($value)) {
            throw new UnexpectedValueException('A source key that is neither a string nor an integer cannot be matched.');
        }

        return (string) $value;
    }

    /**
     * @param  list<int>  $invoiceIds
     * @param  list<int>  $lineIds
     * @return array{int, int}
     */
    private function retire(Workspace $workspace, array $invoiceIds, array $lineIds): array
    {
        return DB::transaction(function () use ($workspace, $invoiceIds, $lineIds): array {
            // Links before the rows they point at. The time entries themselves
            // stay - they are real work, and unlinking them returns them to
            // uninvoiced, which is what they were before an invoice that should
            // not exist claimed them.
            if ($lineIds !== []) {
                DB::table('client_invoice_line_time_entries')
                    ->where('workspace_id', $workspace->id)
                    ->whereIn('client_invoice_line_id', $lineIds)
                    ->delete();
            }

            $lines = $lineIds === [] ? 0 : DB::table('client_invoice_lines')
                ->where('workspace_id', $workspace->id)
                ->whereIn('id', $lineIds)
                ->delete();

            $invoices = $invoiceIds === [] ? 0 : DB::table('client_invoices')
                ->where('workspace_id', $workspace->id)
                ->whereIn('id', $invoiceIds)
                ->delete();

            return [$invoices, $lines];
        });
    }

    /**
     * Invoices whose lines do not sum to their own subtotal.
     *
     * The measure of whether this worked. Before an apply the caller passes the
     * rows that *would* go, so the preview reports the state the write would
     * leave rather than a second implementation that agrees today.
     *
     * @param  list<int>  $pendingInvoiceIds
     * @param  list<int>  $pendingLineIds
     */
    private function survivorsNotReconciling(Workspace $workspace, array $pendingInvoiceIds, array $pendingLineIds): int
    {
        $off = 0;

        foreach (DB::table('client_invoices')->where('workspace_id', $workspace->id)
            ->whereNotIn('id', $pendingInvoiceIds ?: [0])->get(['id', 'subtotal_amount']) as $invoice) {
            $sum = (int) DB::table('client_invoice_lines')
                ->where('workspace_id', $workspace->id)
                ->where('client_invoice_id', $invoice->id)
                ->whereNotIn('id', $pendingLineIds ?: [0])
                ->sum('total_amount');

            if ($sum !== (int) $invoice->subtotal_amount) {
                $off++;
            }
        }

        return $off;
    }
}
