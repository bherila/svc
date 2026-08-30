<?php

namespace Tests\Concerns;

use App\Services\ExternalImport\Fingerprint;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Replays every permutation of a scenario's input events against a
 * system-under-test and checks the invariants a well-behaved event-ordering
 * state machine must uphold no matter what order its inputs arrive in, or how
 * many times one of them is redelivered:
 *
 *  - the terminal state after any ordering is one of the states the design
 *    explicitly allows ($assertLegalState);
 *  - a delivery the scenario marks as a required no-op - an out-of-order
 *    event, or one that must always be refused - leaves the declared domain
 *    tables byte-for-byte unchanged ($mustNotWrite);
 *  - redelivering an event that has already been fully applied, adjacent to
 *    its first delivery and again separated from it, is a no-op too;
 *  - where the scenario claims delivery order does not matter, every
 *    ordering reaches the identical terminal state ($stateSignature).
 *
 * A misconfigured scenario fails loudly rather than passing vacuously: an
 * empty event list, an empty fingerprint-table list, zero orderings run, or
 * zero fingerprints captured are all hard failures.
 *
 * Usage:
 *
 *   $signature = $this->assertEventOrderingInvariance(
 *       events: [$processingEvent, $poisonEvent, $failedEvent],
 *       reset: fn () => $this->seedScenario(),
 *       deliver: fn ($event) => $this->deliverToWebhook($event),
 *       assertLegalState: fn () => $this->assertContains($this->paymentStatus(), self::LEGAL_STATUSES),
 *       fingerprintTables: ['client_invoice_payments', 'client_invoices', 'client_company_activity'],
 *       mustNotWrite: fn ($event, array $deliveredSoFar) => $event['poison']
 *           || $event['ts'] <= $this->maxTs($deliveredSoFar),
 *       stateSignature: fn () => $this->paymentStatus().'|'.$this->paymentProviderTimestamp(),
 *   );
 *   $this->assertSame('failed|200', $signature);
 *
 * Each event is an opaque value understood only by the callbacks above - the
 * harness never inspects it. Permutations are enumerated exactly while n! <=
 * $permutationCap (n <= 6 stays under the default 720); beyond the cap,
 * orderings are sampled deterministically from $seed, which is recorded so a
 * sampled failure reproduces.
 */
trait AssertsEventOrderingInvariance
{
    private int $eventOrderingOrderingsRun = 0;

    private int $eventOrderingFingerprintsCaptured = 0;

    /**
     * @template TEvent
     *
     * @param  list<TEvent>  $events  the events to permute; keep n <= 6 per scenario and prefer several small scenarios over one large one
     * @param  callable(): void  $reset  returns the system under test to its baseline; called before every ordering, including duplicate-delivery variants
     * @param  callable(TEvent): mixed  $deliver  delivers a single event to the system under test
     * @param  callable(): void  $assertLegalState  called once every event (and any duplicate) in an ordering has been delivered; must fail the test if the resulting state is not one of the explicitly legal states
     * @param  list<string>  $fingerprintTables  domain tables snapshotted around a delivery this scenario has marked as a required no-op
     * @param  (callable(TEvent, list<TEvent>): bool)|null  $mustNotWrite  given the next event and the events already delivered in this ordering, returns true when delivering it must leave $fingerprintTables unchanged (an out-of-order or otherwise-refused delivery). Omit when the scenario makes no such claim.
     * @param  (callable(): string)|null  $stateSignature  when given, the design claims order independence: this must return an identical value after every ordering and after every duplicate-delivery variant. Omit when order is allowed to matter.
     * @param  int  $seed  recorded so a sampled-order failure reproduces
     * @param  int  $permutationCap  orderings are enumerated exactly up to this many (n! for the given events); sampled deterministically beyond it
     * @return string|null the single terminal-state signature every ordering agreed on, or null when $stateSignature was not given
     */
    protected function assertEventOrderingInvariance(
        array $events,
        callable $reset,
        callable $deliver,
        callable $assertLegalState,
        array $fingerprintTables,
        ?callable $mustNotWrite = null,
        ?callable $stateSignature = null,
        int $seed = 20260829,
        int $permutationCap = 720,
    ): ?string {
        if ($events === []) {
            throw new InvalidArgumentException('assertEventOrderingInvariance() needs at least one event; an empty scenario proves nothing.');
        }
        if ($fingerprintTables === []) {
            throw new InvalidArgumentException('assertEventOrderingInvariance() needs at least one table to fingerprint; an empty list proves nothing.');
        }

        $signatures = [];
        foreach ($this->eventOrderings($events, $seed, $permutationCap) as $label => $ordering) {
            $reset();
            $this->deliverOrdering($ordering, $deliver, $mustNotWrite, $fingerprintTables);
            $assertLegalState();
            $this->eventOrderingOrderingsRun++;

            if ($stateSignature !== null) {
                $signatures[$label] = $stateSignature();
            }
        }

        if ($stateSignature !== null) {
            $distinct = array_unique($signatures);
            $this->assertCount(
                1,
                $distinct,
                "The scenario claims order independence, but these orderings of the same events disagree on the terminal state:\n"
                .json_encode($signatures, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            );
        }

        // Duplicate-delivery variants: redeliver one event from the canonical
        // (as-given) ordering a second time - once adjacent to its first
        // delivery, once separated from it - and confirm the repeat writes
        // nothing. This is the idempotency invariant: replaying an
        // already-applied event must change nothing.
        $canonical = $events;
        foreach (array_keys($canonical) as $index) {
            foreach (['adjacent', 'separated'] as $mode) {
                [$sequence, $duplicatePosition] = $this->withDuplicateDelivery($canonical, $index, $mode);
                $reset();
                $this->deliverSequenceWithDuplicateCheck($sequence, $duplicatePosition, $mode, $deliver, $fingerprintTables);
                $assertLegalState();
                $this->eventOrderingOrderingsRun++;
            }
        }

        $this->assertGreaterThan(
            0,
            $this->eventOrderingOrderingsRun,
            'The permutation harness ran zero orderings; the scenario is vacuous.',
        );
        $this->assertGreaterThan(
            0,
            $this->eventOrderingFingerprintsCaptured,
            'The permutation harness captured zero table fingerprints; the scenario is vacuous.',
        );

        if ($stateSignature === null) {
            return null;
        }

        return array_values($signatures)[0] ?? null;
    }

    /**
     * A whole-table fingerprint for every table given, in the same spirit as
     * the billing replay harness's whole-database fingerprint: sorted,
     * normalised row hashes, so the comparison cannot pass by accident of row
     * order and cannot be fooled by a column the caller forgot to look at.
     *
     * @param  list<string>  $tables
     * @return array<string, string>
     */
    protected function fingerprintTables(array $tables): array
    {
        $fingerprint = [];
        foreach ($tables as $table) {
            $rows = DB::table($table)->get()->map(static fn (object $row): array => (array) $row)->all();
            $fingerprint[$table] = Fingerprint::rows($rows);
        }
        $this->eventOrderingFingerprintsCaptured++;

        return $fingerprint;
    }

    /**
     * @template TEvent
     *
     * @param  list<TEvent>  $ordering
     * @param  callable(TEvent): mixed  $deliver
     * @param  (callable(TEvent, list<TEvent>): bool)|null  $mustNotWrite
     * @param  list<string>  $fingerprintTables
     */
    private function deliverOrdering(array $ordering, callable $deliver, ?callable $mustNotWrite, array $fingerprintTables): void
    {
        $delivered = [];
        foreach ($ordering as $event) {
            if ($mustNotWrite !== null && $mustNotWrite($event, $delivered)) {
                $before = $this->fingerprintTables($fingerprintTables);
                $deliver($event);
                $after = $this->fingerprintTables($fingerprintTables);
                $this->assertSame(
                    $before,
                    $after,
                    'A delivery the scenario marked as a required no-op (out-of-order or refused) wrote to the fingerprinted tables.',
                );
            } else {
                $deliver($event);
            }
            $delivered[] = $event;
        }
    }

    /**
     * @template TEvent
     *
     * @param  list<TEvent>  $sequence
     * @param  callable(TEvent): mixed  $deliver
     * @param  list<string>  $fingerprintTables
     */
    private function deliverSequenceWithDuplicateCheck(
        array $sequence,
        int $duplicatePosition,
        string $mode,
        callable $deliver,
        array $fingerprintTables,
    ): void {
        foreach ($sequence as $position => $event) {
            if ($position !== $duplicatePosition) {
                $deliver($event);

                continue;
            }
            $before = $this->fingerprintTables($fingerprintTables);
            $deliver($event);
            $after = $this->fingerprintTables($fingerprintTables);
            $this->assertSame(
                $before,
                $after,
                "Redelivering an already-applied event ({$mode} duplicate) wrote to the fingerprinted tables; replay must be a no-op.",
            );
        }
    }

    /**
     * Builds the duplicate-delivery sequence for one event: an adjacent copy
     * lands immediately after the original's own position; a separated copy
     * always lands at the very end of the sequence, after every other event
     * has already been delivered once (which is also "adjacent" in the one
     * degenerate case where the original is already the last event - there
     * is nothing after it to separate the copy with, so the two variants
     * coincide for that index rather than one of them redelivering before
     * the original has even been applied once).
     *
     * @template TEvent
     *
     * @param  list<TEvent>  $events
     * @return array{0: list<TEvent>, 1: int}
     */
    private function withDuplicateDelivery(array $events, int $index, string $mode): array
    {
        $event = $events[$index];
        $sequence = $events;

        if ($mode === 'adjacent') {
            array_splice($sequence, $index + 1, 0, [$event]);

            return [$sequence, $index + 1];
        }

        $sequence[] = $event;

        return [$sequence, count($sequence) - 1];
    }

    /**
     * @template TEvent
     *
     * @param  list<TEvent>  $events
     * @return array<string, list<TEvent>>
     */
    private function eventOrderings(array $events, int $seed, int $permutationCap): array
    {
        $n = count($events);
        $indices = range(0, $n - 1);

        if ($this->cappedFactorial($n, $permutationCap) <= $permutationCap) {
            $orderings = [];
            $i = 0;
            foreach ($this->indexPermutations($indices) as $permutation) {
                $orderings['permutation #'.$i] = array_map(static fn (int $index): mixed => $events[$index], $permutation);
                $i++;
            }

            return $orderings;
        }

        // More orderings exist than the cap allows: sample deterministically
        // from the recorded seed so a failure reproduces exactly.
        mt_srand($seed);
        $orderings = [];

        $orderings['sample #0 identity (seed='.$seed.')'] = $events;
        $reversed = array_reverse($indices);
        $orderings['sample #1 reverse (seed='.$seed.')'] = array_map(static fn (int $index): mixed => $events[$index], $reversed);
        $seen = [implode(',', $indices) => true, implode(',', $reversed) => true];

        $attempts = 0;
        $maxAttempts = $permutationCap * 20;
        while (count($orderings) < $permutationCap && $attempts < $maxAttempts) {
            $attempts++;
            $shuffled = $indices;
            shuffle($shuffled);
            $key = implode(',', $shuffled);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $orderings['sample #'.count($orderings).' (seed='.$seed.')'] = array_map(static fn (int $index): mixed => $events[$index], $shuffled);
        }

        return $orderings;
    }

    /**
     * @param  list<int>  $items
     * @return \Generator<int, list<int>>
     */
    private function indexPermutations(array $items): \Generator
    {
        if ($items === []) {
            yield [];

            return;
        }

        foreach ($items as $key => $item) {
            $remaining = $items;
            unset($remaining[$key]);
            foreach ($this->indexPermutations(array_values($remaining)) as $permutation) {
                yield array_merge([$item], $permutation);
            }
        }
    }

    /**
     * n! capped at a ceiling comfortably above any sane $permutationCap, so
     * this never overflows for a scenario that should have been sampled.
     */
    private function cappedFactorial(int $n, int $ceiling): int
    {
        $result = 1;
        for ($i = 2; $i <= $n; $i++) {
            $result *= $i;
            if ($result > $ceiling) {
                return $result;
            }
        }

        return $result;
    }
}
