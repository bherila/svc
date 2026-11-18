<?php

namespace App\Services\Billing;

use App\Models\ClientTimeEntry;
use App\Support\Billing\BillingEligibilityPosition;
use App\Support\Concurrency\Locks;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use LogicException;
use PDO;
use Throwable;
use WeakMap;

/** Explicit eligibility events, ordered at the final physical-commit fence. */
final class BillingEligibilityOrder
{
    /** @var WeakMap<PDO, array<int, array{workspace: int, company: int, depth: int}>> */
    private WeakMap $pending;

    public function __construct()
    {
        $this->pending = new WeakMap;
    }

    public function record(int $workspaceId, int $companyId): int
    {
        $connection = DB::connection();
        if ($connection->transactionLevel() < 1) {
            throw new LogicException('Billing eligibility requires its domain transaction.');
        }
        $id = (int) $connection->table('billing_eligibility_event_reservations')->insertGetId([]);
        $pdo = $connection->getPdo();
        $registered = $this->pending[$pdo] ?? [];
        $registered[$id] = [
            'workspace' => $workspaceId, 'company' => $companyId, 'depth' => $connection->transactionLevel(),
        ];
        $this->pending[$pdo] = $registered;

        return $id;
    }

    /**
     * @param  array<int, int>  $ids
     * @return array<int, BillingEligibilityPosition>
     */
    public function positions(int $workspaceId, int $companyId, array $ids): array
    {
        $connection = DB::connection();
        $pdo = $connection->getRawPdo();
        $registered = $pdo instanceof PDO ? ($this->pending[$pdo] ?? []) : [];
        $positions = [];
        $stored = [];
        foreach ($ids as $id) {
            $pending = $registered[$id] ?? null;
            if ($pending !== null && $pending['workspace'] === $workspaceId && $pending['company'] === $companyId) {
                $positions[$id] = new BillingEligibilityPosition($id, null);
            } else {
                $stored[] = $id;
            }
        }
        foreach (array_chunk($stored, 200) as $chunk) {
            foreach ($connection->table('client_billing_eligibility_events')
                ->where('workspace_id', $workspaceId)->where('client_company_id', $companyId)
                ->whereIn('id', $chunk)->get(['id', 'sequence']) as $event) {
                if ($event->sequence === null) {
                    throw new LogicException('A published billing eligibility event has no commit order.');
                }
                $positions[(int) $event->id] = new BillingEligibilityPosition((int) $event->id, (int) $event->sequence);
            }
        }

        return $positions;
    }

    /** The caller already created/updated and owns this entry in its transaction. */
    public function recordEntry(ClientTimeEntry $entry): void
    {
        $id = $this->record((int) $entry->workspace_id, (int) $entry->client_company_id);
        $updated = ClientTimeEntry::query()->where('workspace_id', $entry->workspace_id)
            ->where('client_company_id', $entry->client_company_id)->whereKey($entry->id)
            ->update(['billing_eligibility_event_id' => $id]);
        if ($updated !== 1) {
            throw new LogicException('The entry no longer belongs to the eligibility event client.');
        }
        $entry->setAttribute('billing_eligibility_event_id', $id);
        $entry->syncOriginalAttribute('billing_eligibility_event_id');
    }

    /** TransactionCommitting: no business or parent locks may follow this phase. */
    public function committing(Connection $connection): void
    {
        if ($connection->transactionLevel() !== 1) {
            return;
        }
        $pdo = $connection->getPdo();
        $pending = $this->pending[$pdo] ?? [];
        if ($pending === []) {
            return;
        }
        try {
            /** @var array<int, array<int, list<int>>> $groups */
            $groups = [];
            foreach ($pending as $id => $event) {
                $groups[$event['workspace']][$event['company']][] = $id;
            }
            ksort($groups, SORT_NUMERIC);
            // Publish all FK-owned events before the first counter is locked.
            // ID reservation at the domain write acquired no company share lock.
            foreach ($groups as $workspaceId => &$companies) {
                ksort($companies, SORT_NUMERIC);
                foreach ($companies as $companyId => &$ids) {
                    sort($ids, SORT_NUMERIC);
                    $reserved = [];
                    foreach (array_chunk($ids, 200) as $chunk) {
                        foreach ($connection->table('billing_eligibility_event_reservations')
                            ->whereIn('id', $chunk)->orderBy('id')->pluck('id') as $id) {
                            if (! is_numeric($id)) {
                                throw new LogicException('A billing eligibility reservation has an invalid ID.');
                            }
                            $reserved[] = (int) $id;
                        }
                    }
                    $ids = $reserved;
                    foreach (array_chunk($ids, 200) as $chunk) {
                        $connection->table('client_billing_eligibility_events')->insert(array_map(
                            static fn (int $id): array => ['id' => $id, 'workspace_id' => $workspaceId, 'client_company_id' => $companyId, 'sequence' => null],
                            $chunk,
                        ));
                    }
                }
                unset($ids);
            }
            unset($companies);
            foreach ($groups as $workspaceId => $companies) {
                foreach ($companies as $companyId => $ids) {
                    if ($ids === []) {
                        continue;
                    }
                    // Preseeded: acquiring the fence adds no FK parent lock.
                    $counter = $connection->table('client_billing_eligibility_counters')
                        ->where('workspace_id', $workspaceId)->where('client_company_id', $companyId)
                        ->tap(Locks::forUpdate())->first();
                    if ($counter === null || ! is_numeric($counter->sequence)
                        || (int) $counter->sequence > PHP_INT_MAX - count($ids)) {
                        throw new LogicException('The billing eligibility counter is missing or exhausted.');
                    }
                    $sequence = (int) $counter->sequence;
                    foreach (array_chunk($ids, 200) as $chunk) {
                        $cases = [];
                        $bindings = [];
                        foreach ($chunk as $id) {
                            $cases[] = 'WHEN ? THEN ?';
                            $bindings[] = $id;
                            $bindings[] = ++$sequence;
                        }
                        $connection->update(
                            'update client_billing_eligibility_events set sequence = CASE id '.implode(' ', $cases)
                                .' END where workspace_id = ? and client_company_id = ? and id in ('.implode(', ', array_fill(0, count($chunk), '?')).')',
                            [...$bindings, $workspaceId, $companyId, ...$chunk],
                        );
                    }
                    $connection->table('client_billing_eligibility_counters')
                        ->where('workspace_id', $workspaceId)->where('client_company_id', $companyId)
                        ->update(['sequence' => $sequence]);
                    foreach (array_chunk($ids, 200) as $chunk) {
                        // Exact own IDs, with no foreign keys or parent locks.
                        $connection->table('billing_eligibility_event_reservations')->whereIn('id', $chunk)->delete();
                    }
                }
            }
            // Do not retain completed registrations while afterCommit callbacks
            // run (one may fail before Laravel emits TransactionCommitted).
            unset($this->pending[$pdo]);
        } catch (Throwable $exception) {
            // Laravel's non-retryable committing-listener failures do not
            // reliably roll PDO back. Never leave business writes committable.
            $connection->rollBack();

            throw $exception;
        }
    }

    public function committed(Connection $connection): void
    {
        $pdo = $connection->getRawPdo();
        if (! $pdo instanceof PDO) {
            return;
        }
        $depth = $connection->transactionLevel();
        if ($depth === 0) {
            unset($this->pending[$pdo]);

            return;
        }
        $registered = $this->pending[$pdo] ?? [];
        foreach ($registered as $id => $event) {
            $registered[$id] = [...$event, 'depth' => min($event['depth'], $depth)];
        }
        $this->pending[$pdo] = $registered;
    }

    public function rolledBack(Connection $connection): void
    {
        $pdo = $connection->getRawPdo();
        if (! $pdo instanceof PDO) {
            return;
        }
        $depth = $connection->transactionLevel();
        $registered = $this->pending[$pdo] ?? [];
        foreach ($registered as $id => $event) {
            if ($event['depth'] > $depth) {
                unset($registered[$id]);
            }
        }
        if ($registered === []) {
            unset($this->pending[$pdo]);
        } else {
            $this->pending[$pdo] = $registered;
        }
    }
}
