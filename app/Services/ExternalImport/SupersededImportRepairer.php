<?php

namespace App\Services\ExternalImport;

use App\Models\Workspace;
use App\Support\ExternalImport\SupersededImportCounts;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

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

        $invoiceIds = $this->idsFor($workspace, 'client_invoices', $supersededInvoices);
        $lineIds = $this->idsFor($workspace, 'client_invoice_lines', $supersededLines);

        // Money first. An invoice with a payment leaves this repair entirely,
        // and its lines leave with it - retiring the lines of an invoice we
        // decline to retire would corrupt the very invoice we spared.
        $paidInvoiceIds = DB::table('client_invoice_payments')
            ->where('workspace_id', $workspace->id)
            ->whereIn('client_invoice_id', $invoiceIds ?: [0])
            ->pluck('client_invoice_id')->unique()->map(fn (mixed $id): int => (int) $id)->all();

        $invoiceIds = array_values(array_diff($invoiceIds, $paidInvoiceIds));
        $sparedLineIds = $paidInvoiceIds === [] ? [] : DB::table('client_invoice_lines')
            ->where('workspace_id', $workspace->id)
            ->whereIn('client_invoice_id', $paidInvoiceIds)
            ->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
        $lineIds = array_values(array_diff($lineIds, $sparedLineIds));

        // The lines of a retiring invoice go with it whether or not the source
        // marked them deleted: the invoice is not supposed to exist, so neither
        // is anything hanging off it.
        $lineIds = array_values(array_unique(array_merge($lineIds, $invoiceIds === [] ? [] : DB::table('client_invoice_lines')
            ->where('workspace_id', $workspace->id)
            ->whereIn('client_invoice_id', $invoiceIds)
            ->pluck('id')->map(fn (mixed $id): int => (int) $id)->all())));

        $counts = [
            'eligibleInvoices' => count($invoiceIds),
            'eligibleLines' => count($lineIds),
            'skippedWithAPayment' => count($paidInvoiceIds),
        ];

        $retiredInvoices = 0;
        $retiredLines = 0;

        if ($apply && ($invoiceIds !== [] || $lineIds !== [])) {
            [$retiredInvoices, $retiredLines] = $this->retire($workspace, $invoiceIds, $lineIds);
        }

        return new SupersededImportCounts(
            eligibleInvoices: $counts['eligibleInvoices'],
            eligibleLines: $counts['eligibleLines'],
            retiredInvoices: $retiredInvoices,
            retiredLines: $retiredLines,
            skippedWithAPayment: $counts['skippedWithAPayment'],
            survivorsNotReconciling: $this->survivorsNotReconciling(
                $workspace,
                $apply ? [] : $invoiceIds,
                $apply ? [] : $lineIds,
            ),
            applied: $apply,
        );
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
        $deleted = $connection->table($table)->whereNotNull('deleted_at')->pluck($key)
            ->map(fn (mixed $id): string => (string) $id)->all();

        if ($deleted === []) {
            return [];
        }

        return array_values(DB::table('external_import_items')
            ->where('source_table', $table)
            ->whereIn('source_key', $deleted)
            ->whereNotNull('target_public_id')
            ->pluck('target_public_id')
            ->map(fn (mixed $id): string => (string) $id)
            ->all());
    }

    /**
     * @param  list<string>  $publicIds
     * @return list<int>
     */
    private function idsFor(Workspace $workspace, string $table, array $publicIds): array
    {
        if ($publicIds === []) {
            return [];
        }

        // Workspace-scoped even though the public ids came from this
        // workspace's own import ledger: a repair that deletes rows is the last
        // place to take a tenant boundary on trust.
        return array_values(DB::table($table)
            ->where('workspace_id', $workspace->id)
            ->whereIn('public_id', $publicIds)
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all());
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
