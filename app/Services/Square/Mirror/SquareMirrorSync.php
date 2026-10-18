<?php

namespace App\Services\Square\Mirror;

use App\Models\SquareSyncRun;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Square\SquareClient;
use App\Services\Square\SquareConfiguration;
use App\Services\Square\SquareReadFailureReason;
use App\Services\Square\SquareRequestFailed;
use App\Support\WorkspaceClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Refreshes svc's mirror of the connected Square account (#438).
 *
 * Read-only against Square: it goes through {@see SquareClient}, whose
 * allow-list holds reads only. One pass lists the locations, every customer
 * and every location's invoices, then reads the orders of a bounded number of
 * paid invoices for their payment ids. Square offers no "changed since" filter
 * on its invoice or customer lists, so each pass pages through them whole and
 * the fingerprints decide what is written: an unchanged account costs reads
 * and no writes, and the run records the newest Square `updated_at` it saw as
 * its high-water mark.
 *
 * Safe to repeat and to run twice at once. A run is claimed atomically before
 * it starts, so one requested run is performed once; two different runs may
 * overlap, and the mirror still holds one row per Square object
 * ({@see SquareMirrorRepository}). No transaction is held across a Square
 * call. The Square-Version sent is recorded on the run and logged with every
 * page.
 */
final class SquareMirrorSync
{
    public const int CUSTOMER_PAGE_SIZE = 100;

    public const int INVOICE_PAGE_SIZE = 200;

    /** Pages per listing in one run: 10,000 customers, 20,000 invoices per location. */
    public const int MAX_PAGES = 100;

    /** Orders a scheduled run reads to resolve paid invoices' payment ids; the rest wait for the next run. */
    public const int PAYMENT_LOOKUPS_PER_RUN = 50;

    /** Fewer for a run someone is waiting on in a request. */
    public const int PAYMENT_LOOKUPS_PER_REQUESTED_RUN = 10;

    /** A run still requested or running after this long died with its process. */
    public const int ABANDONED_AFTER_MINUTES = 30;

    public function __construct(
        private readonly SquareClient $client,
        private readonly SquareSnapshotMapper $mapper,
        private readonly SquareMirrorRepository $mirror,
        private readonly WorkspaceClock $clock,
    ) {}

    /** Records a run that has not started yet. */
    public function request(Workspace $workspace, string $trigger, ?User $requestedBy = null): SquareSyncRun
    {
        return SquareSyncRun::query()->create([
            'workspace_id' => $workspace->id,
            'trigger' => $trigger,
            'status' => SquareSyncRun::REQUESTED,
            'requested_by_user_id' => $requestedBy?->id,
            'api_version' => mb_substr(SquareConfiguration::fromConfig()->version, 0, 16),
        ]);
    }

    /**
     * Performs a requested run. A run that is no longer requested - another
     * process claimed it, or it already ended - is returned as it stands.
     */
    public function perform(Workspace $workspace, SquareSyncRun $run): SquareSyncRun
    {
        $configuration = SquareConfiguration::fromConfig();
        $claimed = SquareSyncRun::query()
            ->where('workspace_id', $workspace->id)
            ->whereKey($run->getKey())
            ->where('status', SquareSyncRun::REQUESTED)
            ->update([
                'status' => SquareSyncRun::RUNNING,
                'started_at' => $this->now(),
                'api_version' => mb_substr($configuration->version, 0, 16),
                'updated_at' => $this->now(),
            ]);
        $run = $this->reload($workspace, $run);
        if ($claimed !== 1) {
            return $run;
        }
        if (! $configuration->servesWorkspace($workspace)) {
            return $this->finish($workspace, $run, new SquareSyncTally, 'NOT_CONNECTED');
        }

        $tally = new SquareSyncTally;
        $stage = 'locations';
        try {
            $locations = $this->locations($workspace, $run, $configuration, $tally);
            $stage = 'customers';
            $this->customers($workspace, $run, $configuration, $tally);
            $stage = 'invoices';
            foreach ($locations as $locationId) {
                $this->invoices($workspace, $run, $configuration, $tally, $locationId);
            }
            $stage = 'payment_ids';
            $this->paymentIds($workspace, $tally, $run->trigger === SquareSyncRun::TRIGGER_SCHEDULE ? self::PAYMENT_LOOKUPS_PER_RUN : self::PAYMENT_LOOKUPS_PER_REQUESTED_RUN);
        } catch (SquareRequestFailed $failure) {
            Log::warning('Square mirror sync failed', [
                'stage' => $stage,
                'reason' => $failure->reason->value ?? self::errorCode($failure),
            ]);

            return $this->finish($workspace, $run, $tally, self::errorCode($failure));
        } catch (Throwable $exception) {
            $this->finish($workspace, $run, $tally, 'INTERNAL');

            throw $exception;
        }

        return DB::transaction(function () use ($workspace, $run, $tally): SquareSyncRun {
            if (! $tally->truncated) {
                $this->mirror->retireMissing($workspace, $run->id, $this->now());
            }

            return $this->finish($workspace, $run, $tally, null);
        }, 3);
    }

    /**
     * Ends runs whose process died: still requested or running long after
     * they began. Their partial writes stand; the mirror is only ever as fresh
     * as its last completed run says.
     */
    public function abandonStale(Workspace $workspace): int
    {
        $cutoff = $this->now()->subMinutes(self::ABANDONED_AFTER_MINUTES);

        return SquareSyncRun::query()
            ->where('workspace_id', $workspace->id)
            ->whereIn('status', [SquareSyncRun::REQUESTED, SquareSyncRun::RUNNING])
            ->where('created_at', '<', $cutoff)
            ->update([
                'status' => SquareSyncRun::FAILED,
                'error_code' => 'ABANDONED',
                'finished_at' => $this->now(),
                'updated_at' => $this->now(),
            ]);
    }

    /** @return list<string> the location ids whose invoices to read */
    private function locations(Workspace $workspace, SquareSyncRun $run, SquareConfiguration $configuration, SquareSyncTally $tally): array
    {
        $response = $this->client->listLocations();
        $records = self::records($response, 'locations', 300);
        $this->logBatch($workspace, $run, $configuration, 'locations', 1, count($records));
        $rows = array_values(array_filter(array_map($this->mapper->location(...), $records)));
        if (count($rows) !== count($records)) {
            throw SquareRequestFailed::unreadable(SquareReadFailureReason::MappingRejected);
        }
        $this->mirror->storeLocations($workspace, $rows, $this->now(), $run->id);
        $tally->locationsSeen += count($rows);

        return array_column($rows, 'square_location_id');
    }

    private function customers(Workspace $workspace, SquareSyncRun $run, SquareConfiguration $configuration, SquareSyncTally $tally): void
    {
        $this->paginate(
            fn (?string $cursor): array => $this->client->listCustomers($cursor, self::CUSTOMER_PAGE_SIZE),
            'customers',
            self::CUSTOMER_PAGE_SIZE,
            $tally,
            function (array $records, int $page) use ($workspace, $run, $configuration, $tally): void {
                $this->logBatch($workspace, $run, $configuration, 'customers', $page, count($records));
                $rows = array_values(array_filter(array_map($this->mapper->customer(...), $records)));
                if (count($rows) !== count($records)) {
                    throw SquareRequestFailed::unreadable(SquareReadFailureReason::MappingRejected);
                }
                $tally->customersSeen += count($rows);
                $tally->customersChanged += $this->mirror->storeCustomers($workspace, $rows, $this->now(), $run->id);
                $tally->observe(array_column($rows, 'square_updated_at'));
            },
        );
    }

    private function invoices(Workspace $workspace, SquareSyncRun $run, SquareConfiguration $configuration, SquareSyncTally $tally, string $locationId): void
    {
        $this->paginate(
            fn (?string $cursor): array => $this->client->listInvoices($locationId, $cursor, self::INVOICE_PAGE_SIZE),
            'invoices',
            self::INVOICE_PAGE_SIZE,
            $tally,
            function (array $records, int $page) use ($workspace, $run, $configuration, $tally): void {
                $this->logBatch($workspace, $run, $configuration, 'invoices', $page, count($records));
                $rows = array_values(array_filter(array_map($this->mapper->invoice(...), $records)));
                if (count($rows) !== count($records)) {
                    throw SquareRequestFailed::unreadable(SquareReadFailureReason::MappingRejected);
                }
                $tally->invoicesSeen += count($rows);
                $tally->invoicesChanged += $this->mirror->storeInvoices($workspace, $rows, $this->now(), $run->id);
                $tally->observe(array_column($rows, 'square_updated_at'));
            },
        );
    }

    private function paymentIds(Workspace $workspace, SquareSyncTally $tally, int $limit): void
    {
        foreach ($this->mirror->invoicesNeedingPaymentIds($workspace, $limit) as $invoice) {
            $order = $this->client->retrieveOrder($invoice['square_order_id'])['order'] ?? null;
            if (! is_array($order)) {
                throw SquareRequestFailed::unreadable();
            }
            if ($this->mirror->storePaymentIds($workspace, $invoice['square_invoice_id'], $invoice['basis'], $this->mapper->paymentIds($order))) {
                $tally->paymentsResolved++;
            }
        }
    }

    /**
     * Pages through one Square listing. A cursor Square repeats would loop
     * forever, so it ends the run as unreadable; a listing longer than
     * {@see MAX_PAGES} stops there and the run says it was truncated.
     *
     * @param  callable(string|null): array<string, mixed>  $fetch
     * @param  callable(list<array<mixed>>, int): void  $store
     */
    private function paginate(callable $fetch, string $key, int $pageSize, SquareSyncTally $tally, callable $store): void
    {
        $cursor = null;
        $seen = [];
        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $response = $fetch($cursor);
            $store(self::records($response, $key, $pageSize), $page);
            $next = $response['cursor'] ?? null;
            if ($next === null || $next === '') {
                return;
            }
            if (! is_string($next) || preg_match(SquareClient::CURSOR_PATTERN, $next) !== 1) {
                throw SquareRequestFailed::unreadable(SquareReadFailureReason::InvalidCursor);
            }
            if (isset($seen[$next])) {
                throw SquareRequestFailed::unreadable(SquareReadFailureReason::RepeatedCursor);
            }
            $seen[$next] = true;
            $cursor = $next;
        }
        $tally->truncated = true;
    }

    /**
     * @param  array<string, mixed>  $response
     * @return list<array<mixed>>
     */
    private static function records(array $response, string $key, int $max): array
    {
        // Square omits an empty list rather than sending [].
        $records = $response[$key] ?? [];
        if (! is_array($records) || ! array_is_list($records) || count($records) > $max) {
            throw SquareRequestFailed::unreadable(SquareReadFailureReason::MalformedRecords);
        }

        if (count(array_filter($records, 'is_array')) !== count($records)) {
            throw SquareRequestFailed::unreadable(SquareReadFailureReason::MalformedRecords);
        }

        return $records;
    }

    private function finish(Workspace $workspace, SquareSyncRun $run, SquareSyncTally $tally, ?string $errorCode): SquareSyncRun
    {
        $run->forceFill([
            'status' => $errorCode === null ? SquareSyncRun::COMPLETED : SquareSyncRun::FAILED,
            'finished_at' => $this->now(),
            'locations_seen' => $tally->locationsSeen,
            'customers_seen' => $tally->customersSeen,
            'customers_changed' => $tally->customersChanged,
            'invoices_seen' => $tally->invoicesSeen,
            'invoices_changed' => $tally->invoicesChanged,
            'payments_resolved' => $tally->paymentsResolved,
            'high_water_mark' => $tally->highWaterMark,
            'truncated' => $tally->truncated,
            'error_code' => $errorCode,
        ])->save();

        return $this->reload($workspace, $run);
    }

    private function reload(Workspace $workspace, SquareSyncRun $run): SquareSyncRun
    {
        return SquareSyncRun::query()->where('workspace_id', $workspace->id)->whereKey($run->getKey())->firstOrFail();
    }

    private function logBatch(Workspace $workspace, SquareSyncRun $run, SquareConfiguration $configuration, string $resource, int $page, int $records): void
    {
        Log::info('Square mirror sync batch', [
            'workspace' => $workspace->public_id,
            'run' => $run->public_id,
            'resource' => $resource,
            'page' => $page,
            'records' => $records,
            'square_version' => $configuration->version,
        ]);
    }

    /** A stable, safe code for why a run failed; Square's own code when it gave one. */
    private static function errorCode(SquareRequestFailed $failure): string
    {
        if ($failure->squareCode !== null && $failure->squareCode !== 'UNKNOWN') {
            return $failure->squareCode;
        }

        return match ($failure->getStatusCode()) {
            404 => 'NOT_CONNECTED',
            429 => 'RATE_LIMITED',
            502 => 'UNREADABLE',
            503 => 'SERVICE_UNAVAILABLE',
            default => 'REQUEST_FAILED',
        };
    }

    private function now(): CarbonImmutable
    {
        return $this->clock->now()->utc();
    }
}
