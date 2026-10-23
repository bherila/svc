<?php

namespace App\Services\Square\Mirror;

use App\Models\SquareSyncRun;
use App\Models\Workspace;
use App\Support\Concurrency\Locks;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The mirror's only writer (#438): one workspace's snapshot rows.
 *
 * Every write is safe to repeat and to race. A Square object is one row,
 * because each table is unique on (workspace, Square id): a new object is
 * inserted-or-ignored, and an existing one is updated only when its
 * fingerprint differs and the stored Square version is not newer than the one
 * fetched - so a slower sync can never put back an older copy a faster one
 * already replaced. Short database writes take the workspace lock to fence
 * completed generations against late inserts; no transaction spans a Square
 * call. Missing records remain as tombstones, hidden by snapshot model scopes.
 */
final class SquareMirrorRepository
{
    /**
     * @param  list<array<string, mixed>>  $rows  from {@see SquareSnapshotMapper::invoice()}
     * @return int rows inserted or changed
     */
    public function storeInvoices(Workspace $workspace, array $rows, CarbonImmutable $now, ?int $generation = null): int
    {
        return $this->store('square_invoice_snapshots', 'square_invoice_id', $workspace, $rows, $now, true, $generation);
    }

    /**
     * @param  list<array<string, mixed>>  $rows  from {@see SquareSnapshotMapper::customer()}
     * @return int rows inserted or changed
     */
    public function storeCustomers(Workspace $workspace, array $rows, CarbonImmutable $now, ?int $generation = null): int
    {
        return $this->store('square_customer_snapshots', 'square_customer_id', $workspace, $rows, $now, true, $generation);
    }

    /**
     * @param  list<array<string, mixed>>  $rows  from {@see SquareSnapshotMapper::location()}
     * @return int rows inserted or changed
     */
    public function storeLocations(Workspace $workspace, array $rows, CarbonImmutable $now, ?int $generation = null): int
    {
        return $this->store('square_location_snapshots', 'square_location_id', $workspace, $rows, $now, false, $generation);
    }

    /**
     * Invoices whose payment ids have not been read for their current status,
     * paid amount and Square version, oldest change first.
     *
     * `read` is what the row held when it was queued; writes for that lookup
     * apply only while it still does ({@see SquarePaymentIdsRead}).
     *
     * @return list<array{square_invoice_id: string, square_order_id: string, square_location_id: string|null, basis: string, read: SquarePaymentIdsRead}>
     */
    public function invoicesNeedingPaymentIds(Workspace $workspace, int $limit): array
    {
        if ($limit < 1) {
            return [];
        }
        $rows = DB::table('square_invoice_snapshots')
            ->where('workspace_id', $workspace->id)
            ->whereNull('deleted_at')
            ->whereNotNull('square_order_id')
            ->where(static fn (Builder $paid) => $paid->where('paid_amount', '>', 0)
                ->orWhere(static fn (Builder $unknown) => $unknown->whereNull('paid_amount')->whereIn('status', SquareSnapshotMapper::PAYMENT_STATUSES)))
            // Current and unresolvable rows are left out by the database, so
            // the read is bounded by the limit, not by the paid history.
            ->where(static fn (Builder $due) => $due->whereNull('payment_ids_basis')
                ->orWhereRaw('payment_ids_basis not in ('.self::basisSql().', '.self::basisSql("'|x'").')'))
            // Orders awaiting an authorization go after everything else.
            ->orderByRaw('case when payment_ids_basis = '.self::basisSql("'|p'").' or payment_ids_basis = '.self::basisSql("'|r'").' then 1 else 0 end')
            ->orderBy('synced_at')
            ->orderBy('id')
            ->limit(min($limit, 100))
            ->get(['square_invoice_id', 'square_order_id', 'square_location_id', 'status', 'paid_amount', 'square_version', 'payment_ids_basis', 'payment_ids', 'synced_at']);
        $due = [];
        foreach ($rows as $row) {
            $paid = $row->paid_amount === null ? null : (int) $row->paid_amount;
            $version = $row->square_version === null ? null : (int) $row->square_version;
            $stamped = $row->payment_ids_basis === null ? null : (string) $row->payment_ids_basis;
            // A read with no known amount is current but read again in turn.
            if ((SquareSnapshotMapper::paymentIdsCurrent((string) $row->status, $paid, $version, (string) $row->square_order_id, $stamped) && $stamped !== SquareSnapshotMapper::rereadBasis((string) $row->status, $version))
                || $stamped === SquareSnapshotMapper::unresolvableBasis((string) $row->status, $paid, $version)) {
                continue;
            }
            $basis = SquareSnapshotMapper::paymentIdsBasis((string) $row->status, $paid, $version);
            $due[] = ['square_invoice_id' => (string) $row->square_invoice_id, 'square_order_id' => (string) $row->square_order_id, 'square_location_id' => $row->square_location_id === null ? null : (string) $row->square_location_id, 'basis' => $basis,
                'read' => new SquarePaymentIdsRead((string) $row->synced_at, $stamped, $row->payment_ids === null ? null : (string) $row->payment_ids)];
            if (count($due) >= $limit) {
                break;
            }
        }

        return $due;
    }

    /**
     * Records payment ids against the status, paid amount and version they
     * were read for. An invoice that changed again in between keeps waiting
     * for the next sync rather than taking ids from an older state.
     *
     * @param  list<string>  $paymentIds
     */
    public function storePaymentIds(Workspace $workspace, string $invoiceId, string $basis, array $paymentIds, ?CarbonImmutable $rereadAfter = null, ?SquarePaymentIdsRead $read = null): bool
    {
        [$status, $paid, $version] = explode('|', $basis, 4);

        return self::inState(DB::table('square_invoice_snapshots')
            ->where('workspace_id', $workspace->id)
            ->where('square_invoice_id', $invoiceId)
            ->whereNull('deleted_at'), $status, $paid, $version)
            ->when($read !== null, static fn (Builder $query) => $read?->guard($query))
            ->update([
                'payment_ids' => json_encode($paymentIds, JSON_THROW_ON_ERROR),
                // An unknown amount is read again in turn (rereadBasis()).
                'payment_ids_basis' => $paid === '' ? SquareSnapshotMapper::rereadBasis($status, $version === '' ? null : (int) $version) : $basis,
                ...($paid === '' && $rereadAfter !== null ? ['synced_at' => $rereadAfter->utc()->format('Y-m-d H:i:s')] : []),
            ]) > 0;
    }

    /**
     * The row still in the state a basis names: its status, paid amount and
     * Square version, an empty one being unknown.
     */
    private static function inState(Builder $query, string $status, string $paid, string $version): Builder
    {
        return $query->where('status', $status)
            ->when($paid === '', static fn (Builder $unknown) => $unknown->whereNull('paid_amount'), static fn (Builder $known) => $known->where('paid_amount', (int) $paid))
            ->when($version === '', static fn (Builder $unknown) => $unknown->whereNull('square_version'), static fn (Builder $known) => $known->where('square_version', (int) $version));
    }

    /**
     * {@see SquareSnapshotMapper::paymentIdsBasis()} in SQL, for the row's own
     * status, paid amount and Square version, followed by `$suffix`.
     *
     * @param  literal-string  $suffix
     * @return literal-string
     */
    private static function basisSql(string $suffix = "''"): string
    {
        return DB::connection()->getDriverName() === 'sqlite'
            ? "(status || '|' || coalesce(cast(paid_amount as char), '') || '|' || coalesce(cast(square_version as char), '') || '|".SquareSnapshotMapper::TENDER_RULES."' || ".$suffix.')'
            : "concat(status, '|', coalesce(cast(paid_amount as char), ''), '|', coalesce(cast(square_version as char), ''), '|".SquareSnapshotMapper::TENDER_RULES."', ".$suffix.')';
    }

    /**
     * Stamps an invoice whose order could not be read into payment ids for
     * the state named by `$basis`. Its payment ids stay unresolved. An
     * oversized order is skipped until the invoice changes; with `$retryAfter`
     * (an authorization that may yet settle) it is retried after the
     * invoices never read, behind the other pending ones.
     */
    public function markPaymentIdsUnresolvable(Workspace $workspace, string $invoiceId, string $basis, ?CarbonImmutable $retryAfter = null, ?SquarePaymentIdsRead $read = null): bool
    {
        [$status, $paid, $version] = explode('|', $basis, 4);

        return self::inState(DB::table('square_invoice_snapshots')
            ->where('workspace_id', $workspace->id)
            ->where('square_invoice_id', $invoiceId)
            ->whereNull('deleted_at'), $status, $paid, $version)
            ->when($read !== null, static fn (Builder $query) => $read?->guard($query))
            // Never over ids another sync has just resolved for this state.
            // Without the lookup's own read to compare, never over ids already
            // resolved for this state - a reread stamp included, for the
            // unknown amount it was read under. With it, the read decides.
            ->when($read === null, static fn (Builder $unguarded) => $unguarded->where(static fn (Builder $query) => $query->whereNull('payment_ids_basis')
                ->orWhereNotIn('payment_ids_basis', $paid === '' ? [$basis, SquareSnapshotMapper::rereadBasis($status, $version === '' ? null : (int) $version)] : [$basis])))
            ->update([
                'payment_ids' => null,
                'payment_ids_basis' => $basis.($retryAfter !== null ? '|p' : '|x'),
                // A retried order goes behind the others it waits with.
                ...($retryAfter !== null ? ['synced_at' => $retryAfter->utc()->format('Y-m-d H:i:s')] : []),
            ]) > 0;
    }

    /** Retain missing records as tombstones after a complete account listing. */
    public function retireMissing(Workspace $workspace, int $generation, CarbonImmutable $now): void
    {
        DB::transaction(function () use ($workspace, $generation, $now): void {
            $this->lockWrites($workspace);
            $stamp = $now->utc()->format('Y-m-d H:i:s');
            foreach (['square_location_snapshots', 'square_customer_snapshots', 'square_invoice_snapshots'] as $table) {
                DB::table($table)->where('workspace_id', $workspace->id)->whereNull('deleted_at')
                    ->where(static fn (Builder $query) => $query->whereNull('last_sync_run_id')->orWhere('last_sync_run_id', '<', $generation))
                    ->update(['deleted_at' => $stamp, 'last_sync_run_id' => $generation]);
            }
        }, 3);
    }

    private function lockWrites(Workspace $workspace): void
    {
        Workspace::query()->whereKey($workspace->id)->tap(Locks::forUpdate())->firstOrFail();
    }

    /** Older overlapping runs cannot undo a newer observation or tombstone. */
    private static function admitGeneration(Builder $query, ?int $generation): void
    {
        if ($generation === null) {
            $query->whereNull('deleted_at');

            return;
        }
        $query->where(static fn (Builder $current) => $current->whereNull('last_sync_run_id')->orWhere('last_sync_run_id', '<=', $generation))
            ->where(static fn (Builder $live) => $live->whereNull('deleted_at')->orWhere('last_sync_run_id', '<', $generation));
    }

    /**
     * One page, in one short transaction that holds no Square call. A
     * deadlock between two syncs' inserts is retried: both are writing the
     * same facts, so whichever lands first is as good as the other.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function store(string $table, string $key, Workspace $workspace, array $rows, CarbonImmutable $now, bool $versioned, ?int $generation): int
    {
        if ($rows === []) {
            return 0;
        }

        return DB::transaction(function () use ($table, $key, $workspace, $rows, $now, $versioned, $generation): int {
            $this->lockWrites($workspace);
            if ($generation !== null) {
                $completed = SquareSyncRun::query()->where('workspace_id', $workspace->id)
                    ->where('status', SquareSyncRun::COMPLETED)->where('truncated', false)->max('id');
                if ($completed !== null && $generation < (int) $completed) {
                    return 0;
                }
            }

            return $this->storePage($table, $key, $workspace, $rows, $now, $versioned, $generation);
        }, 3);
    }

    /**
     * @param  non-empty-list<array<string, mixed>>  $rows
     */
    private function storePage(string $table, string $key, Workspace $workspace, array $rows, CarbonImmutable $now, bool $versioned, ?int $generation): int
    {
        $stamp = $now->utc()->format('Y-m-d H:i:s');
        // One row per Square id even if Square repeats one within a page.
        $byId = [];
        foreach ($rows as $row) {
            $byId[(string) $row[$key]] = $row;
        }
        $ids = array_map(strval(...), array_keys($byId));
        $stored = [];
        foreach (DB::table($table)->where('workspace_id', $workspace->id)->whereIn($key, $ids)->get([$key, 'fingerprint']) as $existing) {
            $stored[(string) $existing->{$key}] = (string) $existing->fingerprint;
        }

        $changed = 0;
        $inserts = [];
        foreach ($byId as $id => $row) {
            $id = (string) $id;
            if (! array_key_exists($id, $stored)) {
                $inserts[$id] = $row + [
                    'workspace_id' => $workspace->id,
                    'synced_at' => $stamp,
                    'last_seen_at' => $stamp,
                    'last_sync_run_id' => $generation,
                    'created_at' => $stamp,
                    'updated_at' => $stamp,
                ];

                continue;
            }
            if (! hash_equals($stored[$id], (string) $row['fingerprint'])) {
                $changed += $this->replaceUnlessNewer($table, $key, $workspace, $id, $row, $stamp, $versioned, $generation);
            }
        }
        if ($inserts !== []) {
            $inserted = DB::table($table)->insertOrIgnore(array_values($inserts));
            $changed += $inserted;
            if ($inserted < count($inserts)) {
                // Another sync inserted some of these first. Its copy stands
                // only if it is the same or newer; a row this call inserted
                // already carries this fingerprint and is left alone.
                foreach ($inserts as $id => $row) {
                    $changed += $this->replaceUnlessNewer($table, $key, $workspace, $id, $row, $stamp, $versioned, $generation);
                }
            }
        }
        $seen = DB::table($table)->where('workspace_id', $workspace->id)->whereIn($key, $ids);
        self::admitGeneration($seen, $generation);
        $seen->update(['last_seen_at' => $stamp] + ($generation === null ? [] : ['last_sync_run_id' => $generation, 'deleted_at' => null]));

        return $changed;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function replaceUnlessNewer(string $table, string $key, Workspace $workspace, string $id, array $row, string $stamp, bool $versioned, ?int $generation): int
    {
        // Payment ids stay as they are: they record the state they were read
        // for, and the next sync reads them again if that state moved.
        $values = ['synced_at' => $stamp, 'last_seen_at' => $stamp, 'updated_at' => $stamp]
            + array_diff_key($row, array_flip(['workspace_id', 'created_at', 'synced_at', 'last_seen_at', 'updated_at', $key]));
        $version = $versioned ? ($row['square_version'] ?? null) : null;

        $query = DB::table($table)
            ->where('workspace_id', $workspace->id)
            ->where($key, $id)
            ->where('fingerprint', '!=', $row['fingerprint'])
            ->when($version !== null, static fn (Builder $query) => $query->where(
                static fn (Builder $older) => $older->whereNull('square_version')->orWhere('square_version', '<=', $version),
            ));
        self::admitGeneration($query, $generation);

        return $query->update($values);
    }
}
