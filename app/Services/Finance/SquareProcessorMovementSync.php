<?php

namespace App\Services\Finance;

use App\Models\ClientInvoicePayment;
use App\Models\Workspace;
use App\Services\Square\SquareClient;
use App\Services\Square\SquareConfiguration;
use App\Services\Square\SquareRequestFailed;
use App\Support\Finance\ProcessorMovementFacts;
use App\Support\Finance\ProcessorReadBudget;
use App\Support\Square\SquareProcessorMovementFacts;
use App\Support\WorkspaceClock;
use Carbon\CarbonImmutable;
use DomainException;
use SensitiveParameter;

/**
 * Bounded actual payout-entry reads, then tenant-owned movement writes.
 * Every provider read finishes before any write. Unknown entries remain explicit
 * deferred counts; they are not inferred from statuses or stored as raw payloads.
 */
final class SquareProcessorMovementSync
{
    private const int MAX_LOCATIONS = 100;

    private const int MAX_ENTRY_PAGES = 100;

    private const int MAX_ENTRY_ROWS = 10000;

    private const int MAX_DISCOVERY_PAGES = 100;

    private const int MAX_DISCOVERY_ROWS = 10000;

    public function __construct(
        private readonly SquareClient $client,
        private readonly ProcessorMovementRecorder $recorder,
        private readonly WorkspaceClock $clock,
    ) {}

    /**
     * A deferred/conflict count means coverage is incomplete. Retrying reads the
     * payout again, including entries whose payment did not exist on the first run.
     * The optional shared budget bounds calls across an entire command run.
     *
     * @return array{entries_read: int, created: int, unchanged: int, enriched: int, conflict: int, deferred: int, ignored: int}
     */
    public function payout(#[SensitiveParameter] Workspace $workspace, #[SensitiveParameter] string $payoutId, bool $apply = false, ?ProcessorReadBudget $budget = null): array
    {
        $budget ??= new ProcessorReadBudget;
        $configuration = SquareConfiguration::fromConfig();
        if (! $configuration->servesWorkspace($workspace)) {
            throw SquareRequestFailed::notConnected();
        }
        if (preg_match(SquareClient::ID_PATTERN, $payoutId) !== 1) {
            throw SquareRequestFailed::unreadable();
        }
        [$accountKey, $locations] = $this->account($configuration, $budget);

        return $this->payoutInAccount($workspace, $payoutId, $apply, $budget, $accountKey, $locations);
    }

    /**
     * Payout creation dates bound discovery only; each movement retains its own
     * effective_at. Bounds stay identical on every page (the API otherwise
     * defaults begin_time to one year ago). Earlier completed payouts remain
     * idempotently recorded if a later read fails or exhausts the shared budget.
     * https://developer.squareup.com/reference/square/payouts-api/list-payouts
     *
     * @return iterable<array{entries_read: int, created: int, unchanged: int, enriched: int, conflict: int, deferred: int, ignored: int}>
     */
    public function payouts(#[SensitiveParameter] Workspace $workspace, CarbonImmutable $since, bool $apply = false, ?ProcessorReadBudget $budget = null, ?CarbonImmutable $until = null): iterable
    {
        $budget ??= new ProcessorReadBudget;
        $until ??= $this->clock->now('UTC');
        if ($since->year < 1000 || $since->year > 9999 || $until->year < 1000 || $until->year > 9999 || $since->greaterThan($until)) {
            throw new DomainException('The payout discovery time range is invalid.');
        }
        $configuration = SquareConfiguration::fromConfig();
        if (! $configuration->servesWorkspace($workspace)) {
            throw SquareRequestFailed::notConnected();
        }
        [$accountKey, $locations] = $this->account($configuration, $budget);
        $beginTime = $since->utc()->toIso8601ZuluString();
        $endTime = $until->utc()->toIso8601ZuluString();
        $seenIds = [];
        foreach ($locations as $location) {
            $cursor = null;
            $seenCursors = [];
            for ($pageNumber = 1; ; $pageNumber++) {
                if ($pageNumber > self::MAX_DISCOVERY_PAGES) {
                    throw SquareRequestFailed::unreadable();
                }
                $budget->consume();
                $page = $this->client->listPayouts($location, $beginTime, $cursor, 100, $endTime);
                $rows = array_key_exists('payouts', $page) ? $page['payouts'] : [];
                if (! is_array($rows) || ! array_is_list($rows) || count($rows) > 100 || ! empty($page['errors'])) {
                    throw SquareRequestFailed::unreadable();
                }
                $ids = [];
                foreach ($rows as $row) {
                    $id = is_array($row) ? ($row['id'] ?? null) : null;
                    if (! is_array($row) || ($row['location_id'] ?? null) !== $location
                        || ! is_string($id) || preg_match(SquareClient::ID_PATTERN, $id) !== 1
                        || isset($seenIds[$id]) || count($seenIds) >= self::MAX_DISCOVERY_ROWS) {
                        throw SquareRequestFailed::unreadable();
                    }
                    $seenIds[$id] = true;
                    $ids[] = $id;
                }
                $next = $page['cursor'] ?? null;
                if ($next !== null && $next !== '' && (! is_string($next)
                    || preg_match(SquareClient::CURSOR_PATTERN, $next) !== 1 || isset($seenCursors[$next]))) {
                    throw SquareRequestFailed::unreadable();
                }
                foreach ($ids as $id) {
                    try {
                        $result = $this->payoutInAccount($workspace, $id, $apply, $budget, $accountKey, $locations, $location);
                    } catch (SquareRequestFailed $failure) {
                        if (! $failure->isRecordSpecific()) {
                            throw $failure;
                        }
                        // All provider reads precede this payout's first write.
                        // A refused old record is incomplete, not a reason to
                        // starve later payouts in the same bounded window.
                        $result = ['entries_read' => 0, 'created' => 0, 'unchanged' => 0, 'enriched' => 0, 'conflict' => 0, 'deferred' => 1, 'ignored' => 0];
                    }
                    yield $result;
                }
                if ($next === null || $next === '') {
                    break;
                }
                $seenCursors[$next] = true;
                $cursor = $next;
            }
        }
    }

    /**
     * @param  list<string>  $locations
     * @return array{entries_read: int, created: int, unchanged: int, enriched: int, conflict: int, deferred: int, ignored: int}
     */
    private function payoutInAccount(#[SensitiveParameter] Workspace $workspace, #[SensitiveParameter] string $payoutId, bool $apply, ProcessorReadBudget $budget, #[SensitiveParameter] string $accountKey, array $locations, ?string $expectedLocation = null): array
    {
        $budget->consume();
        $payout = $this->client->getPayout($payoutId)['payout'] ?? null;
        if (! is_array($payout) || ($payout['id'] ?? null) !== $payoutId
            || ! is_string($payout['location_id'] ?? null) || ! in_array($payout['location_id'], $locations, true)
            || ($expectedLocation !== null && $payout['location_id'] !== $expectedLocation)) {
            throw SquareRequestFailed::unreadable();
        }
        $entries = $this->entries($payoutId, $budget);
        $counts = ['entries_read' => count($entries), 'created' => 0, 'unchanged' => 0, 'enriched' => 0, 'conflict' => 0, 'deferred' => 0, 'ignored' => 0];
        /** @var list<ProcessorMovementFacts> $prepared */
        $prepared = [];
        $sources = [];
        foreach ($entries as $entry) {
            $source = null;
            $request = $this->sourceRequest($entry);
            if ($request !== null) {
                [$type, $id] = $request;
                $key = $type.':'.$id;
                if (! isset($sources[$key])) {
                    // Budget failure must escape; it is not an unreadable record.
                    $budget->consume();
                    $response = $type === 'refund' ? $this->client->getPaymentRefund($id) : $this->client->getDispute($id);
                    $value = $response[$type] ?? null;
                    if (! is_array($value)) {
                        throw SquareRequestFailed::unreadable();
                    }
                    $sources[$key] = $value;
                }
                $source = $sources[$key];
            }
            try {
                $facts = SquareProcessorMovementFacts::from($entry, $source);
            } catch (DomainException) {
                $counts['deferred']++;

                continue;
            }
            if ($facts === null) {
                $counts['ignored']++;
            } else {
                $prepared[] = $facts;
            }
        }
        // The provider has now been fully read; every following write is scoped
        // and revalidates its payment under the recorder's existing row lock.
        foreach ($prepared as $facts) {
            $payment = ClientInvoicePayment::query()->where('workspace_id', $workspace->id)->where('provider', 'square')
                ->whereIn('provider_payment_identifier', $facts->paymentIdentifiers)->first(['id', 'workspace_id', 'provider', 'provider_payment_identifier']);
            if ($payment === null || $payment->provider !== 'square') {
                $counts['deferred']++;

                continue;
            }
            try {
                $outcome = $this->recorder->record($workspace, $payment->id, $facts, $accountKey, $apply);
            } catch (DomainException) {
                $counts['deferred']++;

                continue;
            }
            $counts[$outcome->value]++;
        }

        return $counts;
    }

    /**
     * Current API merchant identity and environment, never a token/rotation hash.
     *
     * @return array{string, list<string>}
     */
    private function account(#[SensitiveParameter] SquareConfiguration $configuration, ?ProcessorReadBudget $budget): array
    {
        $budget?->consume();
        $response = $this->client->listLocations();
        $locations = $response['locations'] ?? null;
        if (! is_array($locations) || ! array_is_list($locations) || $locations === [] || count($locations) > self::MAX_LOCATIONS
            || ! empty($response['cursor']) || ! empty($response['errors'])) {
            throw SquareRequestFailed::unreadable();
        }
        $ids = [];
        $merchant = null;
        foreach ($locations as $location) {
            $id = is_array($location) ? ($location['id'] ?? null) : null;
            $owner = is_array($location) ? ($location['merchant_id'] ?? null) : null;
            if (! is_string($id) || strlen($id) > 32 || preg_match(SquareClient::ID_PATTERN, $id) !== 1
                || ! is_string($owner) || strlen($owner) > 32 || preg_match(SquareClient::ID_PATTERN, $owner) !== 1
                || ($merchant !== null && $merchant !== $owner) || in_array($id, $ids, true)) {
                throw SquareRequestFailed::unreadable();
            }
            $merchant = $owner;
            $ids[] = $id;
        }

        return ['square:'.$configuration->environment.':'.$merchant, $ids];
    }

    /** @return list<array<string, mixed>> */
    private function entries(#[SensitiveParameter] string $payoutId, ?ProcessorReadBudget $budget): array
    {
        $entries = [];
        $cursor = null;
        $seenCursors = [];
        $seenIds = [];
        for ($pageNumber = 1; ; $pageNumber++) {
            if ($pageNumber > self::MAX_ENTRY_PAGES) {
                throw SquareRequestFailed::unreadable();
            }
            $budget?->consume();
            $page = $this->client->listPayoutEntries($payoutId, $cursor, 100);
            $rows = array_key_exists('payout_entries', $page) ? $page['payout_entries'] : [];
            if (! is_array($rows) || ! array_is_list($rows) || count($rows) > 100 || ! empty($page['errors'])) {
                throw SquareRequestFailed::unreadable();
            }
            foreach ($rows as $row) {
                $id = is_array($row) ? ($row['id'] ?? null) : null;
                if (! is_array($row) || ($row['payout_id'] ?? null) !== $payoutId
                    || ! is_string($id) || preg_match('/^[A-Za-z0-9][A-Za-z0-9:_-]{0,254}$/D', $id) !== 1
                    || isset($seenIds[$id]) || count($entries) >= self::MAX_ENTRY_ROWS) {
                    throw SquareRequestFailed::unreadable();
                }
                $seenIds[$id] = true;
                $entries[] = $row;
            }
            $next = $page['cursor'] ?? null;
            if ($next === null || $next === '') {
                break;
            }
            if (! is_string($next) || preg_match(SquareClient::CURSOR_PATTERN, $next) !== 1 || isset($seenCursors[$next])) {
                throw SquareRequestFailed::unreadable();
            }
            $seenCursors[$next] = true;
            $cursor = $next;
        }

        return $entries;
    }

    /**
     * Resolve only an actual entry reference when its payment ID is absent.
     * Invalid references are left for the adapter's fixed unreadable hold.
     *
     * @param  array<string, mixed>  $entry
     * @return array{string, string}|null
     */
    private function sourceRequest(#[SensitiveParameter] array $entry): ?array
    {
        $shape = match ($entry['type'] ?? null) {
            'REFUND' => ['refund', 'type_refund_details', 'refund_id'],
            'OPEN_DISPUTE' => ['dispute', 'type_open_dispute_details', 'dispute_id'],
            'DISPUTE' => ['dispute', 'type_dispute_details', 'dispute_id'],
            default => null,
        };
        if ($shape === null) {
            return null;
        }
        [$type, $detailsKey, $referenceKey] = $shape;
        $details = $entry[$detailsKey] ?? null;
        if (! is_array($details) || isset($details['payment_id'])) {
            return null;
        }
        $id = $details[$referenceKey] ?? null;

        return is_string($id) && preg_match(SquareClient::ID_PATTERN, $id) === 1 ? [$type, $id] : null;
    }
}
